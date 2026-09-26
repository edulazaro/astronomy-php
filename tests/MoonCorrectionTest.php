<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Moon;
use Astronomy\CorrectionTable;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The layer that brings our Moon closer to the JPL's.
 *
 * `Moon` is ELP 2000-82B, an analytical theory fitted to DE200 forty years ago. Against
 * DE440 it departs by eight tenths of an arcsecond in 1900 and **fifteen seconds in
 * 1600**, almost all of it from tidal acceleration: how much the Moon slows down raising
 * tides on Earth enters its mean longitude as a term in the square of time, and the two
 * ephemerides were fitted with different values for it. That is why the error grows like
 * a parabola on both sides of 2000.
 *
 * `resources/astro/correction/moon.bin` stores that difference in Chebyshev polynomials
 * and `Ephemeris::geometricMoon` adds it in. It is written by `astronomy moon-correction`.
 *
 * The figures here do NOT come from our own code: they are what JPL Horizons returns,
 * asked for in Terrestrial Time and geocentric (`QUANTITIES='31'`, apparent ecliptic
 * longitude and latitude of the date), and are copied by hand so the test runs with no
 * network. It is the same thing `EphemerisTest` does with its own.
 */
class MoonCorrectionTest extends TestCase
{
    /**
     * Maximum longitude difference allowed against the JPL, in arcseconds.
     *
     * MEASURED, not chosen: over the 42 dates below, the worst comes out to 0.125" and the
     * average to 0.085". And it does not go lower than that because of the correction, but
     * because **the floor is the frame's**: the Sun, which has nothing to do with the Moon
     * or with this table, departs from Horizons by 0.145" in 1600 and 0.127" in 2399
     * because of the precession model. The corrected Moon cannot come out better than the
     * coordinate system it is measured in.
     */
    private const TOLERANCE = 0.15;

    /**
     * And in latitude, which comes out much better: 0.015" worst case over the same 42
     * dates.
     *
     * It is no accident that latitude beats longitude by an order of magnitude. What is
     * left after correcting is a rotation of the origin of longitudes between our frame
     * and the JPL's, and a rotation around the ecliptic axis does not touch latitude.
     */
    private const LATITUDE_TOLERANCE = 0.05;

    /** One arcsecond at the Moon's distance, in AU. */
    private const AU_PER_ARCSECOND = 1.2467e-8;

    /** Days each block of the table covers. Fixed by `astronomy moon-correction`. */
    private const DAYS_PER_BLOCK = 16.0;

    /** Degree of the polynomial for each coordinate. */
    private const DEGREE = 14;

    /** Days light takes to cross one astronomical unit. */
    private const LIGHT_PER_AU = 0.005775518331;

    /**
     * Apparent geocentric ecliptic longitude and latitude of the Moon according to JPL
     * Horizons, with the date in Terrestrial Time.
     *
     * Two crossed grids, of 40 and of 38 years, so that not everything falls on the same
     * day of the year: a single regular grid always samples the same time of year and can
     * leave out exactly the error being looked for.
     *
     * @return list<array{string, float, float}>
     */
    public static function jplPositions(): array
    {
        return [
            ['1600-01-01 00:00:00', 104.7710513, 1.4171802],
            ['1617-08-23 00:00:00', 54.5469619, 5.2726248],
            ['1640-01-01 00:00:00', 7.8333816, 4.5749753],
            ['1655-08-23 00:00:00', 37.5022828, 5.2432581],
            ['1680-01-01 00:00:00', 269.2367705, 4.8294336],
            ['1693-08-23 00:00:00', 50.3195670, 4.5446104],
            ['1720-01-01 00:00:00', 164.0053542, 2.1915912],
            ['1731-08-23 00:00:00', 41.8879940, 4.2036188],
            ['1760-01-01 00:00:00', 77.2811911, -0.9423558],
            ['1769-08-23 00:00:00', 40.2304089, 3.3314003],
            ['1800-01-01 00:00:00', 348.4646729, -3.6400960],
            ['1807-08-23 00:00:00', 21.2441953, 3.5151483],
            ['1840-01-01 00:00:00', 236.5571715, -4.9556678],
            ['1845-08-23 00:00:00', 39.5560209, 0.9285366],
            ['1880-01-01 00:00:00', 137.7037282, -2.8228028],
            ['1883-08-23 00:00:00', 30.0040307, 0.3963679],
            ['1920-01-01 00:00:00', 32.0779376, 1.9248136],
            ['1921-08-23 00:00:00', 17.5186616, 0.1331776],
            ['1959-08-23 00:00:00', 22.4119824, -1.6144104],
            ['1960-01-01 00:00:00', 310.2593426, 3.7221147],
            ['1997-08-23 00:00:00', 32.9828807, -3.6097639],
            ['2000-01-01 00:00:00', 217.2843298, 5.2313466],
            ['2035-08-23 00:00:00', 16.7161377, -3.4911156],
            ['2040-01-01 00:00:00', 116.1423081, 3.5882797],
            ['2073-08-23 00:00:00', 28.7854498, -4.8734011],
            ['2080-01-01 00:00:00', 18.2523939, 0.0116481],
            ['2111-08-23 00:00:00', 19.4773635, -5.0121823],
            ['2120-01-01 00:00:00', 279.6466088, -3.5094125],
            ['2149-08-23 00:00:00', 19.1751735, -5.1629749],
            ['2160-01-01 00:00:00', 194.7128461, -5.1474745],
            ['2187-08-23 00:00:00', 12.1486139, -5.0776404],
            ['2200-01-01 00:00:00', 96.4968714, -4.2931389],
            ['2225-08-23 00:00:00', 17.4078234, -4.5691883],
            ['2240-01-01 00:00:00', 343.6674405, -0.1653854],
            ['2263-08-23 00:00:00', 8.2449341, -4.2584923],
            ['2280-01-01 00:00:00', 249.6602772, 3.3749418],
            ['2301-08-23 00:00:00', 356.8696346, -4.0378727],
            ['2320-01-01 00:00:00', 152.2747488, 5.1364527],
            ['2339-08-23 00:00:00', 0.7144644, -2.8543210],
            ['2360-01-01 00:00:00', 61.9379632, 4.3290155],
            ['2377-08-23 00:00:00', 10.7693286, -0.7913917],
            ['2400-01-01 00:00:00', 324.6245594, 1.5520808],
        ];
    }

    /**
     * @param string $date
     * @return float
     */
    private function jd(string $date): float
    {
        return Time::julianDay(new DateTimeImmutable($date, new DateTimeZone('UTC')));
    }

    public function test_the_moon_in_the_chart_matches_the_jpl_across_the_whole_range(): void
    {
        $worst = 0.0;
        $worstDate = '';

        foreach (self::jplPositions() as [$date, $longitude, $latitude]) {
            $position = Ephemeris::position(Body::Moon, $this->jd($date));
            $difference = abs($this->difference($position->longitude, $longitude)) * 3600;

            if ($difference > $worst) {
                $worst = $difference;
                $worstDate = $date;
            }
        }

        $this->assertLessThan(
            self::TOLERANCE,
            $worst,
            sprintf('The Moon departs %.4f" from the JPL on %s.', $worst, $worstDate)
        );
    }

    public function test_the_latitude_also_matches(): void
    {
        foreach (self::jplPositions() as [$date, $longitude, $latitude]) {
            $position = Ephemeris::position(Body::Moon, $this->jd($date));

            $this->assertEqualsWithDelta(
                $latitude,
                $position->latitude,
                self::LATITUDE_TOLERANCE / 3600,
                "The Moon's latitude does not match on {$date}."
            );
        }
    }

    /**
     * That the correction REACHES the Moon that gets drawn in a chart.
     *
     * This test exists because there are two roads to the Moon and only one of them
     * passes through the correction. `Ephemeris::geometricHeliocentric` asks for it
     * through `geometricMoon`, which adds the table in; the geocentric one, which is the
     * one in the chart, can be resolved by calling `Moon::geocentricRectangular` directly,
     * which is pure ELP and knows nothing about the table. If that happens, the chart
     * comes out with the usual error, the table is on disk, the suite above fails by
     * fifteen arcseconds in 1600, and there is nothing pointing at where.
     *
     * The check does not look at code: it looks at the result. The apparent Moon is
     * computed by the raw road (ELP and nothing else) and the engine is required NOT to
     * give that same value. With the correction applied it carries several arcseconds in
     * 1600; without it, it is exactly zero.
     */
    public function test_the_correction_reaches_the_geocentric_moon(): void
    {
        $jd = $this->jd('1600-06-15 00:00:00');

        $correction = CorrectionTable::vector('moon', $jd);
        $this->assertNotNull($correction, 'The table does not cover 1600, so this test proves nothing.');

        $raw = $this->rawLongitude($jd);
        $engine = Ephemeris::position(Body::Moon, $jd)->longitude;
        $difference = abs($this->difference($engine, $raw)) * 3600;

        $this->assertGreaterThan(
            1.0,
            $difference,
            'The engine gives exactly the uncorrected ELP Moon: the table exists but the geocentric one does not go through it.'
        );
    }

    /**
     * That the blocks do not jump where they meet.
     *
     * A piecewise fit is NOT continuous by construction: each block is fitted by least
     * squares on its own and nothing forces the last value of one to be the first of the
     * next. The jump does not show in the position, which is where one usually looks, but
     * it does show in the VELOCITY, which comes from centred differences: a step between
     * two evaluations a few hours apart is a huge derivative during that stretch, and
     * invented retrogradations come out of that.
     *
     * The number is measured and fixed here.
     */
    public function test_the_blocks_do_not_jump_at_the_seams(): void
    {
        [$start] = CorrectionTable::range('moon');
        $days = self::DAYS_PER_BLOCK;
        $worst = 0.0;

        // Seams spread across the whole range, not just the first ones: a fit breaks
        // where what it fits is biggest, and that is at the extremes.
        foreach ([1, 500, 2000, 4560, 9130, 13700, 18000, 18262] as $block) {
            $seam = $start + $block * $days;

            $before = CorrectionTable::vector('moon', $seam - 1e-5);
            $after = CorrectionTable::vector('moon', $seam + 1e-5);

            $this->assertNotNull($before);
            $this->assertNotNull($after);

            $jump = sqrt(
                ($after[0] - $before[0]) ** 2
                + ($after[1] - $before[1]) ** 2
                + ($after[2] - $before[2]) ** 2
            );

            $worst = max($worst, $jump);
        }

        $this->assertLessThan(
            0.01 * self::AU_PER_ARCSECOND,
            $worst,
            sprintf('The seam jumps %.4f arcseconds.', $worst / self::AU_PER_ARCSECOND)
        );
    }

    /**
     * Outside the table there is no error: there is pure ELP.
     *
     * This is the property that separates a correction layer from a change of
     * ephemerides. A date in 1400 has no correction and still returns a position, and
     * that position is exactly ELP's, with no special case and no exception to catch.
     */
    public function test_outside_the_range_there_is_no_correction_and_still_a_moon(): void
    {
        $outside = $this->jd('1400-03-21 00:00:00');

        $this->assertNull(CorrectionTable::vector('moon', $outside));

        $position = Ephemeris::position(Body::Moon, $outside);

        $this->assertGreaterThanOrEqual(0.0, $position->longitude);
        $this->assertLessThan(360.0, $position->longitude);

        // And it is ELP's and nothing more: with no correction, the engine and the raw
        // series match to machine precision.
        $this->assertEqualsWithDelta(
            $this->rawLongitude($outside),
            $position->longitude,
            1e-9,
            'Outside the table the Moon would have to be pure ELP.'
        );
    }

    /**
     * The file, on disk and covering the range it claims to.
     *
     * `CorrectionTable` already throws if the size does not match the header, so reading
     * it is already half the check: a truncated file does not give zero correction for
     * the missing part, it throws.
     */
    public function test_the_file_is_on_disk_and_covers_1600_to_2400(): void
    {
        $path = \Astronomy\DataFolder::path('correction/moon.bin');

        $this->assertFileExists($path);
        $this->assertTrue(CorrectionTable::exists('moon'));

        [$start, $end] = CorrectionTable::range('moon');

        // One block of margin ahead: light-time delay and the velocity derivative look
        // backwards, and at the very first instant that would fall outside.
        $this->assertLessThanOrEqual($this->jd('1600-01-01 00:00:00'), $start);
        $this->assertGreaterThanOrEqual($this->jd('2400-01-01 00:00:00'), $end);

        $this->assertNotNull(CorrectionTable::vector('moon', $this->jd('1600-01-01 00:00:00')));
        $this->assertNotNull(CorrectionTable::vector('moon', $this->jd('2400-01-01 00:00:00')));

        // And the size is what the header announces: blocks times three coordinates
        // times coefficients times four bytes, plus thirty two of header.
        $blocks = (int) round(($end - $start) / self::DAYS_PER_BLOCK);
        $this->assertSame(
            32 + $blocks * 3 * (self::DEGREE + 1) * 4,
            filesize($path)
        );
    }

    /**
     * The apparent Moon by the raw road: ELP and nothing else.
     *
     * Repeats what `Ephemeris` does with the Moon (light-time delay, no aberration, no
     * FK5, plus nutation in longitude) but without going through the table. It serves two
     * purposes: seeing whether the correction is getting through, and checking that
     * nothing gets through outside the range.
     *
     * @param float $jdTT
     * @return float
     */
    private function rawLongitude(float $jdTT): float
    {
        $vector = function (float $jd): array {
            [$longitude, $latitude, $distance] = Moon::spherical($jd);

            return [
                $distance * cos($latitude) * cos($longitude),
                $distance * cos($latitude) * sin($longitude),
                $distance * sin($latitude),
            ];
        };

        [, , $distance] = Moon::spherical($jdTT);
        $v = $vector($jdTT - self::LIGHT_PER_AU * $distance);

        $longitude = rad2deg(atan2($v[1], $v[0])) + rad2deg(Time::nutation(Time::centuries($jdTT))[0]);

        return fmod(fmod($longitude, 360) + 360, 360);
    }

    /**
     * @param float $a
     * @param float $b
     * @return float Difference in degrees, folded to the smaller arc.
     */
    private function difference(float $a, float $b): float
    {
        $difference = fmod($a - $b + 540, 360) - 180;

        return $difference;
    }
}
