<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Calibration;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Calibration\HitRatioAdjustment;

/**
 * Algorithm-level unit tests for the convergent hit_ratio adjustment used by
 * CalibrateCommand. Integration tests live in CalibrateCommandTest; here we
 * verify the mathematical properties in isolation:
 *
 *   1. No-op when hit_ratio is null (no signal)
 *   2. No-op inside the hysteresis deadband
 *   3. Proportional adjustment within the bounded clamp
 *   4. Hard clamp at ±max_step for extreme deviations
 *   5. Fixed point: hit_ratio == target → adjustment == 1 → TTL unchanged
 *   6. Bounded contraction: repeated runs converge geometrically (no oscillation)
 */
class HitRatioAdjustmentTest extends TestCase
{
    public function test_returns_raw_when_hit_ratio_is_null(): void
    {
        // No signal → no adjustment, idletime-only path.
        $this->assertSame(
            1000,
            HitRatioAdjustment::apply(1000, null, 0.80, 0.05, 0.30, 0.20),
        );
    }

    public function test_returns_raw_when_deviation_within_deadband(): void
    {
        // hit_ratio=0.78, target=0.80 → deviation=0.02 < deadband=0.05 → no-op.
        $this->assertSame(
            1000,
            HitRatioAdjustment::apply(1000, 0.78, 0.80, 0.05, 0.30, 0.20),
        );

        // Symmetric: hit_ratio=0.82 → deviation=-0.02 → still no-op.
        $this->assertSame(
            1000,
            HitRatioAdjustment::apply(1000, 0.82, 0.80, 0.05, 0.30, 0.20),
        );
    }

    public function test_grows_ttl_when_hit_ratio_below_target_outside_deadband(): void
    {
        // hit_ratio=0.50, target=0.80 → deviation=0.30
        // adj = 1 + 0.30 × 0.30 = 1.09 (clamp irrelevant, |0.09| < 0.20)
        // raw=1000 → ceil(1000 × 1.09) = 1090.
        $this->assertSame(
            1090,
            HitRatioAdjustment::apply(1000, 0.50, 0.80, 0.05, 0.30, 0.20),
        );
    }

    public function test_shrinks_ttl_when_hit_ratio_above_target_outside_deadband(): void
    {
        // hit_ratio=0.95, target=0.80 → deviation=-0.15
        // adj = 1 + 0.30 × (-0.15) = 0.955 (float: 0.95500000000001)
        // raw=1000 → ceil(955.0000000001) = 956 — rounding up by one second is the
        // safe direction here (cache stays slightly longer); rounding down would
        // collide with the survivor-bias guard.
        $this->assertSame(
            956,
            HitRatioAdjustment::apply(1000, 0.95, 0.80, 0.05, 0.30, 0.20),
        );
    }

    public function test_caps_growth_at_max_step_for_extreme_low_hit_ratio(): void
    {
        // hit_ratio=0.10, target=0.80 → deviation=0.70
        // raw_adj = 1 + 0.30 × 0.70 = 1.21 → clamped to 1.20.
        // raw=1000 → ceil(1000 × 1.20) = 1200.
        $this->assertSame(
            1200,
            HitRatioAdjustment::apply(1000, 0.10, 0.80, 0.05, 0.30, 0.20),
        );

        // hit_ratio=0.00 (pathological worst case) → deviation=0.80
        // raw_adj = 1.24 → clamped to 1.20 (same).
        $this->assertSame(
            1200,
            HitRatioAdjustment::apply(1000, 0.00, 0.80, 0.05, 0.30, 0.20),
        );
    }

    public function test_caps_shrink_at_max_step_for_extreme_high_hit_ratio(): void
    {
        // Pathological: hit_ratio=1.00, very high learning_rate=2.0 to force clamp.
        // deviation = 0.80 - 1.00 = -0.20
        // raw_adj = 1 + 2.0 × (-0.20) = 0.60 → clamped to 0.80.
        // raw=1000 → ceil(1000 × 0.80) = 800.
        $this->assertSame(
            800,
            HitRatioAdjustment::apply(1000, 1.00, 0.80, 0.05, 2.0, 0.20),
        );
    }

    public function test_fixed_point_when_hit_ratio_equals_target(): void
    {
        // deviation=0 < deadband → no-op. Stable: future runs at this hit_ratio
        // produce the same TTL. (Convergence pre-condition.)
        $this->assertSame(
            1000,
            HitRatioAdjustment::apply(1000, 0.80, 0.80, 0.05, 0.30, 0.20),
        );
    }

    public function test_iterative_application_converges_into_deadband(): void
    {
        // Simulate cron repeating: each run uses previous TTL as input. With
        // hit_ratio held BELOW target, TTL should grow toward stability.
        // We don't model the actual hit_ratio response curve here — just verify
        // that monotonic input produces bounded change per step (no overshoot).
        $ttl = 1000;
        $maxChangePerStep = 0.20; // matches max_step

        for ($i = 0; $i < 5; $i++) {
            $newTtl = HitRatioAdjustment::apply($ttl, 0.40, 0.80, 0.05, 0.30, 0.20);
            $ratio = $newTtl / $ttl;

            // Per-step change must stay within ±max_step (bounded contraction).
            $this->assertGreaterThanOrEqual(1.0 - $maxChangePerStep, $ratio, "step {$i}");
            $this->assertLessThanOrEqual(1.0 + $maxChangePerStep, $ratio, "step {$i}");

            $ttl = $newTtl;
        }

        // After 5 runs at 12% growth (clamp not binding): 1000 × 1.12^5 ≈ 1762.
        $this->assertGreaterThan(1500, $ttl);
        $this->assertLessThan(2000, $ttl);
    }

    public function test_negative_config_values_are_clamped_defensively(): void
    {
        // Negative deadband / learning_rate / max_step would otherwise produce
        // nonsensical results (inverted direction, ever-shrinking TTL). The
        // function clamps them to 0 internally → safe degradation.

        // Negative deadband → treated as 0 → adjustment still applies.
        $this->assertSame(
            1090,
            HitRatioAdjustment::apply(1000, 0.50, 0.80, -0.05, 0.30, 0.20),
        );

        // Negative learning_rate → treated as 0 → no movement at all.
        $this->assertSame(
            1000,
            HitRatioAdjustment::apply(1000, 0.50, 0.80, 0.05, -0.30, 0.20),
        );

        // Negative max_step → treated as 0 → clamp to exactly [1, 1] → no change.
        $this->assertSame(
            1000,
            HitRatioAdjustment::apply(1000, 0.50, 0.80, 0.05, 0.30, -0.20),
        );
    }

    public function test_max_step_above_one_is_capped_to_protect_lower_bound(): void
    {
        // Without a ceiling, max_step=2.0 + high learning_rate + hit_ratio above
        // target would drive `1 - max_step = -1`, allowing adjustment <= 0 →
        // ceil(raw × adj) ≤ 0. The MAX_STEP_CEILING (0.95) keeps the lower
        // clamp at 0.05:
        //   raw_adj = 1 + 5.0 × (-0.5) = -1.5 → clamp(-1.5, 0.05, 1.95) = 0.05
        //   raw=1000 → ceil(1000 × 0.05) = 50 (well above zero).
        $this->assertGreaterThanOrEqual(
            50,
            HitRatioAdjustment::apply(1000, 1.00, 0.50, 0.05, 5.0, 2.0),
        );
    }

    public function test_result_is_never_zero_or_negative(): void
    {
        // Defensive last-resort floor. TTL=0 in Lada means "persist forever"
        // (Cache::set drops the EX argument), so a misconfigured input that
        // produces TTL ≤ 0 would silently leak memory when invalidation misses.
        // max(1, ...) is the final guarantee.
        //
        // Worst-case combination: tiny raw + max_step above ceiling +
        // extreme learning rate + maximum deviation.
        $result = HitRatioAdjustment::apply(1, 1.00, 0.00, 0.0, 100.0, 999.0);

        $this->assertGreaterThanOrEqual(1, $result, 'TTL must never be zero or negative under any input.');
    }
}
