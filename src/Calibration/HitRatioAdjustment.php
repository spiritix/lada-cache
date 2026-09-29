<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Calibration;

/**
 * Convergent proportional control: pulls the hit_ratio signal toward a target
 * by bounded steps. Used on the read-heavy calibrate path.
 *
 * Algorithm:
 *   deviation = target - hitRatio
 *   if |deviation| < deadband: return raw                       // hysteresis
 *   raw_adj = 1 + learning_rate × deviation
 *   adj     = clamp(raw_adj, 1 - max_step, 1 + max_step)        // bounded step
 *   return max(1, ceil(raw × adj))
 *
 * Stability invariants:
 *   - Per-run TTL change adj ∈ [1 - max_step, 1 + max_step] → bounded growth
 *     and bounded decay symmetrically.
 *   - |deviation| < deadband → no-op (oscillation guard around target).
 *   - hit_ratio is monotone in TTL (longer TTL → more hits, until invalidations
 *     dominate), so fixed point: hit_ratio ≈ target ⇒ adj → 1, TTL steady.
 *   - With the survivor-bias floor applied in the caller (previousTtl / 2),
 *     the combined update map is a geometric bounded contraction → guaranteed
 *     to converge.
 *
 * Safety bounds:
 *   - `maxStep` is capped at MAX_STEP_CEILING (0.95). If `1 - max_step` were
 *     allowed to reach 0 or below, an extreme `learning_rate × deviation` could
 *     drive `adj ≤ 0`, and ceil(raw × adj) could be 0 or negative. The caller
 *     `max($adjusted, $floor)` would still preserve floor=0 (newly tracked
 *     model with no prior TTL) → TTL=0 → `Cache::set` drops the EX argument
 *     → "persist forever" semantics → memory leak. Capping at 0.95 keeps the
 *     lower clamp ≥ 0.05 (at least 5% of the raw value).
 *   - The final `max(1, ceil(raw × adj))` is the last-resort floor; even a
 *     misconfigured input can never produce a zero or negative TTL.
 *
 * Static + dependency-free → trivial to unit-test, zero runtime overhead.
 */
final class HitRatioAdjustment
{
    /**
     * Upper bound for `maxStep`. Setting `maxStep = 1` would push the lower
     * clamp `1 - max_step` to 0, allowing the multiplier to collapse the TTL
     * to zero. 0.95 guarantees at least 5% of the raw value survives the
     * most aggressive single-step contraction.
     */
    private const float MAX_STEP_CEILING = 0.95;

    /**
     * @param  float|null  $hitRatio  null → adjustment skipped (no signal)
     */
    public static function apply(
        int $raw,
        ?float $hitRatio,
        float $target,
        float $deadband,
        float $learningRate,
        float $maxStep,
    ): int {
        if ($hitRatio === null) {
            return $raw;
        }

        $deadband = max(0.0, $deadband);
        $learningRate = max(0.0, $learningRate);
        $maxStep = max(0.0, min(self::MAX_STEP_CEILING, $maxStep));

        $deviation = $target - $hitRatio;

        if (abs($deviation) < $deadband) {
            return $raw;
        }

        $adjustment = 1.0 + ($learningRate * $deviation);
        $adjustment = max(1.0 - $maxStep, min(1.0 + $maxStep, $adjustment));

        return max(1, (int) ceil($raw * $adjustment));
    }
}
