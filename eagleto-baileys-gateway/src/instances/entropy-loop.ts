import { env } from '../config/env.js'
import { contextLogger } from '../config/logger.js'
import type { BaileysSocketHandle } from '../baileys/adapter/types.js'
import { normaliseJid } from '../baileys/jid-utils.js'
import { randomBetween } from '../util/timing.js'

/**
 * Background entropy loop.
 *
 * A real WhatsApp account is not "listen-only": the phone periodically comes
 * online, avatars load, the owner glances at chats. An account that only ever
 * emits outbound messages and shows no other activity carries a machine
 * signature. This performs ONE harmless "I'm a real phone" action per cycle — a
 * brief presence blip, or a fetch of the account's own profile picture — at a
 * randomised interval, and NEVER sends a message or touches a group.
 *
 * One self-rescheduling timer per instance, started when a number becomes READY
 * and cleared when it stops or drops. Best-effort background noise: any error is
 * logged at debug and the next tick is still scheduled.
 */

export type EntropyAction = 'presence' | 'profile'

/** A randomised interval in ms for the next tick — uniform across the hour band. */
export function nextIntervalMs(minHours: number, maxHours: number): number {
  const min = Math.max(0, minHours) * 3_600_000
  const max = Math.max(min, maxHours * 3_600_000)

  return randomBetween(min, max)
}

/** Which harmless action to perform this cycle. `rand` is injectable for tests. */
export function chooseAction(rand: number = Math.random()): EntropyAction {
  return rand < 0.5 ? 'presence' : 'profile'
}

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms))

interface ActiveLoop {
  timer: NodeJS.Timeout
  socket: BaileysSocketHandle
}

export class EntropyLoop {
  private readonly active = new Map<string, ActiveLoop>()

  /** Begin the loop for a now-READY number. No-op unless entropy is enabled. */
  start(instanceId: string, socket: BaileysSocketHandle): void {
    if (!env().ENTROPY_ENABLED) {
      return
    }

    this.stop(instanceId)
    this.schedule(instanceId, socket)
  }

  stop(instanceId: string): void {
    const loop = this.active.get(instanceId)

    if (loop) {
      clearTimeout(loop.timer)
      this.active.delete(instanceId)
    }
  }

  private schedule(instanceId: string, socket: BaileysSocketHandle): void {
    const timer = setTimeout(() => {
      void this.runAction(instanceId, socket).finally(() => {
        // Reschedule only if this exact socket is still the active loop — a stop
        // or a reconnect (which starts a fresh loop) must not be resurrected.
        if (this.active.get(instanceId)?.socket === socket) {
          this.schedule(instanceId, socket)
        }
      })
    }, nextIntervalMs(env().ENTROPY_MIN_HOURS, env().ENTROPY_MAX_HOURS))

    // Never hold the process open for a background blip.
    timer.unref()
    this.active.set(instanceId, { timer, socket })
  }

  private async runAction(instanceId: string, socket: BaileysSocketHandle): Promise<void> {
    const log = contextLogger({ instanceId })
    const action = chooseAction()

    try {
      if (action === 'presence') {
        // Come online briefly, then go quiet again — no message, no group.
        await socket.sendPresenceUpdate('available')
        await sleep(randomBetween(1500, 4000))
        await socket.sendPresenceUpdate('unavailable')
      } else {
        const self = socket.user()?.id
        if (self) {
          await socket.fetchProfilePicture(normaliseJid(self))
        }
      }

      log.debug({ action }, 'Entropy action performed')
    } catch (error) {
      // Background noise must never surface as an error — a failed blip is
      // immaterial, and the loop keeps going.
      log.debug({ err: error, action }, 'Entropy action failed (ignored)')
    }
  }
}

export const entropyLoop = new EntropyLoop()
