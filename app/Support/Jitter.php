<?php

namespace App\Support;

/**
 * Human-like inter-message timing.
 *
 * The campaign dispatcher spaces messages by a random gap drawn from the
 * operator's [min, max] band. A *uniform* draw (random_int) spreads sends evenly
 * across the whole band, and that flatness is itself a machine signature: real
 * people cluster around a typical gap and only occasionally pause much longer or
 * fire back quickly. A Gaussian (normal) draw reproduces that shape — most gaps
 * land near the centre, the tails are rare — so the timing reads as organic.
 *
 * This is a pure timing helper: no state, no side effects, testable in isolation.
 */
class Jitter
{
    /**
     * A pause in whole seconds, drawn from a normal distribution centred on the
     * midpoint of [$min, $max] and clamped to that band.
     *
     * The standard deviation is a sixth of the range, so the band spans ~±3σ and
     * almost every draw falls inside it before clamping; clamping then guarantees
     * the caller's bounds are never violated, whatever the tail produces.
     */
    public static function seconds(int $min, int $max): int
    {
        $min = max(0, $min);
        $max = max($min, $max);

        if ($min === $max) {
            return $min;
        }

        $mean = ($min + $max) / 2;
        $stdDev = ($max - $min) / 6; // ±3σ spans the whole band

        $value = (int) round(self::gaussian($mean, $stdDev));

        return max($min, min($max, $value));
    }

    /**
     * One draw from a normal distribution via the Box–Muller transform.
     *
     * random_int gives cryptographic-quality uniform integers; scaling two of
     * them into (0, 1] and combining them yields a standard normal deviate, which
     * is then shifted and scaled to the requested mean and spread. u1 is kept
     * strictly above zero so log() can never reach -INF.
     */
    private static function gaussian(float $mean, float $stdDev): float
    {
        $u1 = (random_int(0, PHP_INT_MAX - 1) + 1) / PHP_INT_MAX; // (0, 1]
        $u2 = random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;       // [0, 1)

        $standardNormal = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        return $mean + $standardNormal * $stdDev;
    }
}
