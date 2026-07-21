/**
 * Shared timing maths for humanised behaviour.
 *
 * Pure functions, no clock, no I/O — so the jitter shapes can be unit-tested in
 * isolation. Randomness here is timing entropy, never a security decision, so
 * Math.random is the right tool.
 */

/** Inclusive integer in [min, max]; collapses to `min` when the range is empty. */
export function randomBetween(min: number, max: number): number {
  if (max <= min) {
    return Math.max(0, Math.round(min))
  }

  return Math.round(min) + Math.floor(Math.random() * (Math.round(max) - Math.round(min) + 1))
}

/**
 * An integer drawn from a normal (Gaussian) distribution centred on the midpoint
 * of [min, max] and clamped to that band.
 *
 * A uniform delay spreads evenly across the whole band, and that flatness is
 * itself a machine signature. A Gaussian draw clusters around the centre with
 * rare tails — the shape human reaction times actually take. The standard
 * deviation is a sixth of the range, so the band spans ~±3σ and almost every
 * draw lands inside it before clamping; clamping then guarantees the bounds.
 */
export function gaussianBetween(min: number, max: number): number {
  if (max <= min) {
    return Math.max(0, Math.round(min))
  }

  const mean = (min + max) / 2
  const stdDev = (max - min) / 6

  // Box–Muller: two uniforms in (0, 1] combine into a standard normal deviate.
  const u1 = Math.random() || Number.EPSILON // never 0, so log() is finite
  const u2 = Math.random()
  const standardNormal = Math.sqrt(-2 * Math.log(u1)) * Math.cos(2 * Math.PI * u2)

  const value = Math.round(mean + standardNormal * stdDev)

  return Math.max(Math.round(min), Math.min(Math.round(max), value))
}
