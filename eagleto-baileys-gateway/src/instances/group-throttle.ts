/**
 * Group-action brake.
 *
 * WhatsApp treats a number that fires at many groups in quick succession as
 * spam. This enforces a minimum gap between actions targeting a group chat on a
 * single instance, so group sends can never go back-to-back however fast the
 * serial queue delivers them. One-to-one sends are never affected — only group
 * JIDs pass through here.
 *
 * In-memory and per instance; `now` is injectable so the cooldown maths can be
 * tested without real time.
 */
export class GroupActionThrottle {
  private readonly lastActionAt = new Map<string, number>()

  /**
   * Milliseconds the caller must wait before the next group action on this
   * instance — 0 when the cooldown has already elapsed (or none has run yet).
   */
  waitMs(instanceId: string, cooldownMs: number, now: number = Date.now()): number {
    const last = this.lastActionAt.get(instanceId)

    if (last === undefined) {
      return 0
    }

    const elapsed = now - last

    return elapsed >= cooldownMs ? 0 : cooldownMs - elapsed
  }

  /** Record that a group action has just happened, starting the cooldown. */
  mark(instanceId: string, now: number = Date.now()): void {
    this.lastActionAt.set(instanceId, now)
  }

  clear(instanceId: string): void {
    this.lastActionAt.delete(instanceId)
  }
}

export const groupActionThrottle = new GroupActionThrottle()
