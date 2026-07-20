import type { Logger } from 'pino'

import { contextLogger, logger } from '../config/logger.js'
import { prisma } from '../database/client.js'
import { CLAIMABLE_STATUSES, deliverWebhook } from '../webhooks/webhook-dispatcher.js'

/**
 * Drains the webhook_events queue.
 *
 * Every gateway node runs one of these against the same table, so the design
 * question is not "how fast" but "how do two nodes avoid delivering the same
 * event twice". The answer is a conditional update: a row is only claimed if it
 * is still in a claimable status, and the database decides the winner.
 */

export interface WebhookWorkerOptions {
  /** Deliveries in flight at once. */
  concurrency?: number
  /** Rows fetched per pass. Larger than concurrency so a backlog drains without a sleep between batches. */
  batchSize?: number
  /** Pause between passes when the queue is empty. */
  idleDelayMs?: number
  /**
   * A row is considered abandoned this long after being claimed. Guards against
   * a node that crashed mid-delivery: without it those rows would sit in
   * DELIVERING forever and the event would be silently lost — the exact
   * outcome persist-before-send exists to prevent.
   */
  stalledAfterMs?: number
  /** How often to sweep for abandoned rows. */
  reclaimIntervalMs?: number
}

const DEFAULTS = {
  concurrency: 5,
  batchSize: 25,
  idleDelayMs: 1_000,
  stalledAfterMs: 120_000,
  reclaimIntervalMs: 30_000,
} as const

export class WebhookWorker {
  private readonly options: Required<WebhookWorkerOptions>
  private readonly log: Logger

  private running = false
  private loop: Promise<void> | null = null

  /** Set while sleeping, so stop() can cut the sleep short instead of waiting it out. */
  private wake: (() => void) | null = null

  private lastReclaimAt = 0

  constructor(options: WebhookWorkerOptions = {}) {
    this.options = { ...DEFAULTS, ...options }
    this.log = logger().child({ component: 'webhook-worker' })
  }

  start(): void {
    if (this.running) {
      return
    }

    this.running = true
    this.loop = this.run()

    this.log.info(
      { concurrency: this.options.concurrency, batchSize: this.options.batchSize },
      'Webhook worker started.',
    )
  }

  /**
   * Resolves once the current pass has finished. Deliberately awaits the loop
   * rather than abandoning it: shutting down mid-delivery would leave rows in
   * DELIVERING that only the stall sweep could recover.
   */
  async stop(): Promise<void> {
    if (!this.running) {
      return
    }

    this.running = false
    this.wake?.()

    await this.loop
    this.loop = null

    this.log.info('Webhook worker stopped.')
  }

  private async run(): Promise<void> {
    while (this.running) {
      let handled = 0

      try {
        await this.reclaimStalled()
        handled = await this.pass()
      } catch (error) {
        // One bad pass (a dropped database connection, say) must not kill the
        // worker — otherwise every later event stops being delivered and
        // nothing says why.
        this.log.error({ err: error }, 'Webhook worker pass failed; continuing.')
      }

      // A full batch means there is probably more waiting; go straight round
      // again rather than sleeping through a backlog.
      if (this.running && handled < this.options.batchSize) {
        await this.sleep(this.options.idleDelayMs)
      }
    }
  }

  /** @returns how many events were claimed and attempted. */
  private async pass(): Promise<number> {
    const due = await prisma.webhookEvent.findMany({
      where: {
        status: { in: [...CLAIMABLE_STATUSES] },
        nextAttemptAt: { lte: new Date() },
      },
      // Oldest first, so Laravel sees an instance's events in roughly the order
      // they happened rather than the order they were retried.
      orderBy: [{ occurredAt: 'asc' }, { id: 'asc' }],
      take: this.options.batchSize,
      select: { id: true },
    })

    if (due.length === 0) {
      return 0
    }

    const queue = due.map((row) => row.id)
    const lanes = Math.min(this.options.concurrency, queue.length)

    let attempted = 0

    await Promise.all(
      Array.from({ length: lanes }, async () => {
        while (this.running) {
          const eventId = queue.shift()

          if (eventId === undefined) {
            return
          }

          if (await this.claim(eventId)) {
            attempted += 1
            await this.deliver(eventId)
          }
        }
      }),
    )

    return attempted
  }

  /**
   * Compare-and-swap the row into DELIVERING.
   *
   * updateMany returns the number of rows it matched, so a count of 0 means
   * another node got there first. Reading the row and then updating it would
   * leave a window in which both nodes saw it as PENDING.
   */
  private async claim(eventId: string): Promise<boolean> {
    const claimed = await prisma.webhookEvent.updateMany({
      where: { id: eventId, status: { in: [...CLAIMABLE_STATUSES] } },
      data: { status: 'DELIVERING' },
    })

    return claimed.count > 0
  }

  private async deliver(eventId: string): Promise<void> {
    try {
      await deliverWebhook(eventId)
    } catch (error) {
      // deliverWebhook absorbs transport failures itself, so reaching here
      // means its own bookkeeping failed and the row is still DELIVERING. The
      // stall sweep will pick it back up.
      contextLogger({ eventId }).error({ err: error }, 'Webhook delivery threw; row left for stall recovery.')
    }
  }

  /** Return rows abandoned by a crashed node to the queue. */
  private async reclaimStalled(): Promise<void> {
    const now = Date.now()

    if (now - this.lastReclaimAt < this.options.reclaimIntervalMs) {
      return
    }

    this.lastReclaimAt = now

    const reclaimed = await prisma.webhookEvent.updateMany({
      where: {
        status: 'DELIVERING',
        updatedAt: { lt: new Date(now - this.options.stalledAfterMs) },
      },
      data: { status: 'RETRY_WAIT', nextAttemptAt: new Date() },
    })

    if (reclaimed.count > 0) {
      this.log.warn({ count: reclaimed.count }, 'Reclaimed webhook events abandoned mid-delivery.')
    }
  }

  private sleep(ms: number): Promise<void> {
    return new Promise((resolve) => {
      const timer = setTimeout(() => {
        this.wake = null
        resolve()
      }, ms)

      this.wake = () => {
        clearTimeout(timer)
        this.wake = null
        resolve()
      }
    })
  }
}
