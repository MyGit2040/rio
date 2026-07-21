import { randomBetween } from '../util/timing.js'

/**
 * Human-like typing choreography for outgoing text.
 *
 * Pure timing maths — no socket, no Baileys import — so the "how long would a
 * person take to type this?" model can be unit-tested in isolation. The socket
 * calls (sendPresenceUpdate) live in the sender; this module only decides the
 * durations.
 *
 * A real person pauses to read/think, then types for a while before a message
 * appears. Sending instantly, every time, with no "typing…" indicator, is one
 * of the clearest automation tells there is. The plan below reproduces the two
 * beats: a think pause (500–1500 ms by default), then a compose window sized
 * from the message length at ~msPerChar per character, jittered so two identical
 * messages never take exactly the same time.
 */

export interface TypingModel {
  /** Milliseconds of typing per character of the message (~30 ms ≈ real speed). */
  msPerChar: number
  /** Floor on the compose window, so even a one-word reply shows a beat. */
  minTypeMs: number
  /** Ceiling on the compose window, so a long message can't stall the queue. */
  maxTypeMs: number
  /** Think-pause range, applied before "typing…" appears. */
  thinkMinMs: number
  thinkMaxMs: number
}

export interface TypingPlan {
  /** Pause before the "typing…" indicator appears — reading/thinking time. */
  thinkMs: number
  /** How long "composing" is shown before the message is sent. */
  typeMs: number
}

/**
 * Plan the think + typing durations for a message of `textLength` characters.
 *
 * Compose time is `textLength * msPerChar` (so a 40-character line at 30 ms/char
 * ≈ 1.2 s of typing), jittered ±20% so no two sends match to the millisecond,
 * then clamped to [minTypeMs, maxTypeMs]. The clamp keeps the behaviour safe at
 * both extremes: a one-character reply still shows a real beat, and a
 * 4 000-character message does not freeze the instance's serial queue.
 */
export function planTyping(textLength: number, model: TypingModel): TypingPlan {
  const thinkMs = randomBetween(model.thinkMinMs, model.thinkMaxMs)

  const baseMs = Math.max(0, textLength) * model.msPerChar
  const jittered = baseMs * (0.8 + Math.random() * 0.4) // ±20%
  const typeMs = Math.min(model.maxTypeMs, Math.max(model.minTypeMs, Math.round(jittered)))

  return { thinkMs, typeMs }
}
