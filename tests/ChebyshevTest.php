<?php

namespace Astronomy\Tests;

use Astronomy\Chebyshev;
use Astronomy\CorrectionTable;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * The machinery that stores the correction towards the JPL: the Chebyshev fit and the
 * binary file that carries it.
 *
 * It is pure arithmetic, so it is checked against functions known in advance and not against
 * any ephemeris. The real tables are checked by `MoonCorrectionTest` and
 * `PlanetCorrectionTest`, each against Horizons.
 */
class ChebyshevTest extends TestCase
{
    private const PATH = 'correction/test-chebyshev.bin';

    protected function tearDown(): void
    {
        $path = \Astronomy\DataFolder::path(self::PATH);

        if (is_file($path)) {
            unlink($path);
        }

        CorrectionTable::forget();

        parent::tearDown();
    }

    /**
     * Chebyshev polynomials are worth what their definition says: T0 = 1, T1 = x, and each one
     * is twice the previous one times x minus the one before that. Evaluating coefficient j
     * only has to return T_j.
     */
    public function test_evaluation_returns_the_chebyshev_polynomials(): void
    {
        $expected = [
            fn (float $x) => 1.0,
            fn (float $x) => $x,
            fn (float $x) => 2 * $x ** 2 - 1,
            fn (float $x) => 4 * $x ** 3 - 3 * $x,
            fn (float $x) => 8 * $x ** 4 - 8 * $x ** 2 + 1,
        ];

        foreach ($expected as $j => $polynomial) {
            $coefficients = array_fill(0, $j + 1, 0.0);
            $coefficients[$j] = 1.0;

            foreach ([-1.0, -0.7, -0.25, 0.0, 0.3, 0.85, 1.0] as $x) {
                $this->assertEqualsWithDelta($polynomial($x), Chebyshev::evaluate($coefficients, $x), 1e-13, "T{$j} at {$x}");
            }
        }

        $this->assertSame(0.0, Chebyshev::evaluate([], 0.5));
    }

    /**
     * The error of a Chebyshev fit to a smooth function falls GEOMETRICALLY as the degree goes
     * up, and that is the whole reason to store the correction this way and not as a table of
     * points. If it ever stopped falling, it would mean the fit is solved wrong.
     */
    public function test_the_error_falls_geometrically_with_the_degree(): void
    {
        $function = fn (float $x) => sin(3 * $x) * exp($x / 2);

        $x = [];
        $y = [];

        for ($i = 0; $i < 200; $i++) {
            $x[] = -1 + 2 * $i / 199;
            $y[] = $function(end($x));
        }

        $previous = null;

        foreach ([4, 8, 12, 16] as $degree) {
            $coefficients = Chebyshev::fit($x, $y, $degree);
            $this->assertCount($degree + 1, $coefficients);

            $worst = 0.0;

            for ($i = 0; $i < 500; $i++) {
                $point = -1 + 2 * $i / 499;
                $worst = max($worst, abs(Chebyshev::evaluate($coefficients, $point) - $function($point)));
            }

            if ($previous !== null) {
                $this->assertLessThan($previous / 100, $worst, "degree {$degree} had to improve by two orders over the previous one");
            }

            $previous = $worst;
        }

        // At degree 16 the test function is pinned to machine precision.
        $this->assertLessThan(1e-10, $previous);
    }

    /**
     * A polynomial of degree n fits EXACTLY with degree n, and no better with more: it is the
     * check that the normal system is set up right and not just close.
     */
    public function test_a_polynomial_fits_exactly(): void
    {
        $function = fn (float $x) => 2 - 3 * $x + 0.5 * $x ** 2 + 4 * $x ** 3;

        $x = [];
        $y = [];

        for ($i = 0; $i < 50; $i++) {
            $x[] = -1 + 2 * $i / 49;
            $y[] = $function(end($x));
        }

        $coefficients = Chebyshev::fit($x, $y, 3);

        for ($i = 0; $i < 200; $i++) {
            $point = -1 + 2 * $i / 199;
            $this->assertEqualsWithDelta($function($point), Chebyshev::evaluate($coefficients, $point), 1e-12);
        }
    }

    /**
     * With fewer points than coefficients the fit would pass through all of them and would say
     * nothing about what is in between, which is exactly what is meant to be measured. It warns
     * instead of returning it.
     */
    public function test_it_does_not_fit_with_fewer_points_than_coefficients(): void
    {
        $this->expectException(RuntimeException::class);

        Chebyshev::fit([-1.0, 0.0, 1.0], [1.0, 2.0, 3.0], 5);
    }

    /**
     * The binary file: it is written, it is read, and it returns the same thing that went in,
     * within the precision of a four-byte float over values the size of a correction.
     */
    public function test_the_table_makes_the_round_trip_through_the_file(): void
    {
        $function = fn (float $jd, int $axis) => 1e-6 * sin(($jd - 2451545.0) / 300.0 + $axis);

        $startJd = 2451545.0;
        $days = 64.0;
        $degree = 10;
        $blocks = [];

        for ($b = 0; $b < 30; $b++) {
            $x = [];
            $y = [[], [], []];

            for ($i = 0; $i < 60; $i++) {
                $point = -1 + 2 * $i / 59;
                $x[] = $point;
                $jd = $startJd + ($b + ($point + 1) / 2) * $days;

                foreach ([0, 1, 2] as $axis) {
                    $y[$axis][] = $function($jd, $axis);
                }
            }

            $blocks[] = [
                Chebyshev::fit($x, $y[0], $degree),
                Chebyshev::fit($x, $y[1], $degree),
                Chebyshev::fit($x, $y[2], $degree),
            ];
        }

        $bytes = CorrectionTable::write(\Astronomy\DataFolder::path(self::PATH), $startJd, $days, $degree, $blocks);
        CorrectionTable::forget();

        // A 32-byte header plus three coordinates of eleven four-byte coefficients.
        $this->assertSame(32 + 30 * 3 * 11 * 4, $bytes);
        $this->assertTrue(CorrectionTable::exists('test-chebyshev'));
        $this->assertEqualsWithDelta([$startJd, $startJd + 30 * $days], CorrectionTable::range('test-chebyshev'), 1e-9);

        $worst = 0.0;

        for ($i = 0; $i <= 2000; $i++) {
            $jd = $startJd + 30 * $days * $i / 2000;
            $vector = CorrectionTable::vector('test-chebyshev', $jd);

            foreach ([0, 1, 2] as $axis) {
                $worst = max($worst, abs($vector[$axis] - $function($jd, $axis)));
            }
        }

        $this->assertLessThan(1e-12, $worst, 'the round trip through the file cannot lose anything appreciable');
    }

    /**
     * Outside the range it returns null and not a zero or an extrapolation, because the caller
     * has to be able to tell "there is no correction here" apart from "the correction is zero".
     * A body with no file is the same thing: the engine carries on with its analytic series.
     */
    public function test_outside_the_range_and_with_no_file_there_is_no_correction(): void
    {
        $blocks = [[array_fill(0, 4, 1e-7), array_fill(0, 4, 1e-7), array_fill(0, 4, 1e-7)]];

        CorrectionTable::write(\Astronomy\DataFolder::path(self::PATH), 2451545.0, 100.0, 3, $blocks);
        CorrectionTable::forget();

        $this->assertNotNull(CorrectionTable::vector('test-chebyshev', 2451545.0));
        $this->assertNotNull(CorrectionTable::vector('test-chebyshev', 2451645.0));
        $this->assertNull(CorrectionTable::vector('test-chebyshev', 2451544.9));
        $this->assertNull(CorrectionTable::vector('test-chebyshev', 2451645.1));

        $this->assertFalse(CorrectionTable::exists('body-that-does-not-exist'));
        $this->assertNull(CorrectionTable::vector('body-that-does-not-exist', 2451545.0));
        $this->assertNull(CorrectionTable::range('body-that-does-not-exist'));
    }

    /**
     * A file cut off halfway through does not fail to read: it would give a zero correction in
     * the part that is missing, that is, a worse position with every appearance of being fine.
     * That is why the header states how much it has to measure, and it is checked on load.
     */
    public function test_a_truncated_file_is_caught_on_load(): void
    {
        $path = \Astronomy\DataFolder::path(self::PATH);
        $blocks = [[array_fill(0, 4, 1e-7), array_fill(0, 4, 1e-7), array_fill(0, 4, 1e-7)]];

        CorrectionTable::write($path, 2451545.0, 100.0, 3, $blocks);
        file_put_contents($path, substr((string) file_get_contents($path), 0, -8));
        CorrectionTable::forget();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cut short|is \d+ bytes/');

        CorrectionTable::vector('test-chebyshev', 2451545.0);
    }

    /**
     * And a file that is not the right format does not pass as good either.
     */
    public function test_a_file_that_is_not_the_right_format_is_caught(): void
    {
        file_put_contents(\Astronomy\DataFolder::path(self::PATH), str_repeat('x', 64));
        CorrectionTable::forget();

        $this->expectException(RuntimeException::class);

        CorrectionTable::vector('test-chebyshev', 2451545.0);
    }
}
