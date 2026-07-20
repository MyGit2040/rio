import { contextLogger } from '../config/logger.js'

/**
 * Per-instance serial execution.
 *
 * Exactly one send may be inside Baileys' sendMessage for a given WhatsApp
 * account at a time. Concurrent sends through one socket interleave Signal
 * ratchet operations and can corrupt session state or produce out-of-order
 * delivery.
 *
 * Instances are independent: instance A's queue never blocks instance B, so a
 * slow or wedged number cannot stall the rest of the fleet. That is why this is
 * a map of small queues rather than one global queue.
 *
 * This is a technical concurrency limit only. Pacing, daily caps and campaign
 * throttling are Laravel's responsibility and are not reimplemented here.
 */

interface QueueEntry<T> {
  run: () => Promise<T>
  resolve: (value: T) => void
  reject: (error: unknown) => void
}

class InstanceQueue {
  private readonly pending: Array<QueueEntry<any>> = []
  private draining = false
  private closed = false

  get depth(): number {
    return this.pending.length + (this.draining ? 1 : 0)
  }

  enqueue<T>(run: () => Promise<T>): Promise<T> {
    if (this.closed) {
      return Promise.reject(new Error('This instance queue is closed; the socket is shutting down.'))
    }

    return new Promise<T>((resolve, reject) => {
      this.pending.push({ run, resolve, reject })
      void this.drain()
    })
  }

  private async drain(): Promise<void> {
    if (this.draining) {
      return
    }

    this.draining = true

    try {
      while (this.pending.length > 0) {
        const entry = this.pending.shift()

        if (!entry) {
          continue
        }

        try {
          entry.resolve(await entry.run())
        } catch (error) {
          // A failed send must not poison the queue — the next message still
          // gets its turn.
          entry.reject(error)
        }
      }
    } finally {
      this.draining = false
    }
  }

  /** Reject everything still waiting. Used when a socket dies or is closed. */
  close(reason: string): void {
    this.closed = true

    while (this.pending.length > 0) {
      this.pending.shift()?.reject(new Error(reason))
    }
  }
}

export class SerialQueueRegistry {
  private readonly queues = new Map<string, InstanceQueue>()

  run<T>(instanceId: string, task: () => Promise<T>): Promise<T> {
    let queue = this.queues.get(instanceId)

    if (!queue) {
      queue = new InstanceQueue()
      this.queues.set(instanceId, queue)
    }

    return queue.enqueue(task)
  }

  depth(instanceId: string): number {
    return this.queues.get(instanceId)?.depth ?? 0
  }

  totalDepth(): number {
    let total = 0

    for (const queue of this.queues.values()) {
      total += queue.depth
    }

    return total
  }

  close(instanceId: string, reason = 'Instance socket closed.'): void {
    const queue = this.queues.get(instanceId)

    if (queue) {
      queue.close(reason)
      this.queues.delete(instanceId)
      contextLogger({ instanceId }).debug('Closed serial send queue')
    }
  }

  closeAll(reason = 'Gateway is shutting down.'): void {
    for (const instanceId of [...this.queues.keys()]) {
      this.close(instanceId, reason)
    }
  }
}

export const serialQueues = new SerialQueueRegistry()
