<?php

namespace Tests\Unit;

use App\Support\Jitter;
use PHPUnit\Framework\TestCase;

class JitterTest extends TestCase
{
    public function test_every_draw_stays_within_the_band(): void
    {
        for ($i = 0; $i < 2000; $i++) {
            $value = Jitter::seconds(40, 90);
            $this->assertGreaterThanOrEqual(40, $value);
            $this->assertLessThanOrEqual(90, $value);
        }
    }

    public function test_a_zero_width_band_returns_the_single_value(): void
    {
        $this->assertSame(30, Jitter::seconds(30, 30));
    }

    public function test_it_tolerates_an_inverted_band(): void
    {
        // max < min must never throw or escape the (clamped) bounds.
        $value = Jitter::seconds(90, 40);
        $this->assertSame(90, $value);
    }

    public function test_it_clamps_a_negative_floor_to_zero(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $this->assertGreaterThanOrEqual(0, Jitter::seconds(-5, 5));
        }
    }

    public function test_the_distribution_clusters_around_the_midpoint(): void
    {
        // A normal draw must pile up near the centre, unlike a uniform one: at
        // least half of a large sample should land in the middle third of the
        // band. (A uniform distribution would put only ~a third there.)
        $min = 0;
        $max = 90;
        $lowerThird = $min + ($max - $min) / 3; // 30
        $upperThird = $max - ($max - $min) / 3; // 60

        $sample = 4000;
        $inMiddle = 0;
        $sum = 0;

        for ($i = 0; $i < $sample; $i++) {
            $value = Jitter::seconds($min, $max);
            $sum += $value;
            if ($value >= $lowerThird && $value <= $upperThird) {
                $inMiddle++;
            }
        }

        $this->assertGreaterThan($sample * 0.5, $inMiddle, 'Gaussian jitter should concentrate around the midpoint.');

        // The mean should sit near the centre (45), give or take sampling noise.
        $mean = $sum / $sample;
        $this->assertGreaterThan(40, $mean);
        $this->assertLessThan(50, $mean);
    }
}
