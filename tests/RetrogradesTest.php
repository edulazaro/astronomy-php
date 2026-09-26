<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Retrogrades;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Planetary stations, against the JPL and against their own definition.
 *
 * A station is where the speed in longitude equals zero, so there are two ways to check it and
 * both are here. The inside one: at the returned instant, that speed has to be zero. And the
 * outside one, which is the one that counts: **a planet's longitude has a maximum or a minimum
 * there**, and anyone can confirm that with the JPL's own ephemerides without knowing anything
 * about our code.
 *
 * Measured by asking Horizons for Mercury's apparent ecliptic longitude every minute around its
 * February 2026 station: **the peak falls on the same minute as the station we compute**, and
 * the longitude matches to 0.12 arcseconds, which is the engine's known floor against the JPL.
 * The figures are copied by hand so the suite runs with no network, the same way `EphemerisTest`
 * does.
 */
class RetrogradesTest extends TestCase
{
    private function jd(string $date): float
    {
        return Time::tt(Time::julianDay(new DateTimeImmutable($date, new DateTimeZone('UTC'))));
    }

    /**
     * The longitude peak JPL Horizons publishes for Mercury's 2026 station, requested in
     * Terrestrial Time and at a resolution of one minute.
     */
    public function test_mercurys_station_falls_where_the_jpl_puts_the_peak(): void
    {
        $stations = Retrogrades::stations(
            Body::Mercury,
            $this->jd('2026-02-20 00:00:00'),
            $this->jd('2026-03-04 00:00:00')
        );

        $this->assertCount(1, $stations);
        $this->assertTrue($stations[0]['retrograde']);

        // Horizons, QUANTITIES='31', TIME_TYPE='TT', one-minute step: the apparent longitude's
        // maximum falls on 2026-Feb-26 at 06:49 TT, at 352.5653127 degrees.
        $expected = $this->jd('2026-02-26 06:49:00');

        $this->assertEqualsWithDelta($expected, $stations[0]['jd'], 1.0 / 1440.0);
        $this->assertEqualsWithDelta(352.5653127, $stations[0]['longitude'], 0.0001);
    }

    /**
     * @return list<array{0: Body}>
     */
    public static function bodiesThatRetrograde(): array
    {
        return [
            'Mercury' => [Body::Mercury],
            'Venus' => [Body::Venus],
            'Mars' => [Body::Mars],
            'Jupiter' => [Body::Jupiter],
            'Saturn' => [Body::Saturn],
            'Uranus' => [Body::Uranus],
            'Neptune' => [Body::Neptune],
            'Pluto' => [Body::Pluto],
        ];
    }

    /**
     * The check against the definition: if that is a station, the planet stands still there.
     *     */
    #[DataProvider('bodiesThatRetrograde')]
    public function test_at_the_station_the_speed_is_zero(Body $body): void
    {
        $stations = Retrogrades::stations(
            $body,
            $this->jd('2019-01-01 00:00:00'),
            $this->jd('2021-01-01 00:00:00')
        );

        $this->assertNotEmpty($stations, $body->value.' has no station at all in two years');

        foreach ($stations as $station) {
            $this->assertEqualsWithDelta(
                0.0,
                Ephemeris::position($body, $station['jd'])->speed,
                1e-7,
                $body->value
            );
        }
    }

    /**
     * What the definition cannot see: that none of them get missed.
     *
     * A Mercury retrogradation lasts three weeks. With too long a sweep step, its two stations
     * fit inside a single step, the speed has the same sign at both ends and **the whole period
     * vanishes without giving any error**. Here it sweeps with a step twenty times finer and
     * demands the same count.
     */
    public function test_the_sweep_does_not_skip_any_station(): void
    {
        foreach ([Body::Mercury, Body::Venus, Body::Mars, Body::Jupiter] as $body) {
            $from = $this->jd('2019-01-01 00:00:00');
            $days = 730.0;
            $step = 0.1;

            $reference = 0;
            $previous = Ephemeris::position($body, $from)->speed;

            for ($i = 1; $i * $step <= $days; $i++) {
                $value = Ephemeris::position($body, $from + $i * $step)->speed;

                if (($previous < 0.0) !== ($value < 0.0)) {
                    $reference++;
                }

                $previous = $value;
            }

            $this->assertCount(
                $reference,
                Retrogrades::stations($body, $from, $from + $days),
                $body->value
            );
        }
    }

    /**
     * Mercury retrogrades three times a year, which is the fact everyone repeats. Here it is
     * not taken for granted: it is counted over several years running.
     */
    public function test_mercury_retrogrades_three_times_a_year(): void
    {
        foreach ([2023, 2024, 2025, 2026] as $year) {
            $periods = Retrogrades::periods(
                Body::Mercury,
                $this->jd("{$year}-01-01 00:00:00"),
                $this->jd(($year + 1).'-01-01 00:00:00')
            );

            // Three or four: a period straddling the turn of the year counts in both years, and
            // that is why some calendar years get four.
            $this->assertGreaterThanOrEqual(3, count($periods), "in {$year}");
            $this->assertLessThanOrEqual(4, count($periods), "in {$year}");
        }
    }

    /**
     * A period that starts in December and ends in January belongs to both years, and both
     * have to see it WHOLE. If the search stuck to the requested year, January would show an
     * end with no beginning, which is broken data with every appearance of being fine.
     */
    public function test_a_period_straddling_the_year_comes_out_whole_in_both(): void
    {
        // Mercury retrogrades from 2023 December 13 to 2024 January 2.
        $from2023 = Retrogrades::periods(
            Body::Mercury,
            $this->jd('2023-01-01 00:00:00'),
            $this->jd('2024-01-01 00:00:00')
        );
        $from2024 = Retrogrades::periods(
            Body::Mercury,
            $this->jd('2024-01-01 00:00:00'),
            $this->jd('2025-01-01 00:00:00')
        );

        $last = end($from2023);
        $first = $from2024[0];

        $this->assertNotNull($last['start']);
        $this->assertNotNull($last['end']);
        $this->assertNotNull($first['start']);
        $this->assertNotNull($first['end']);

        // It is the same period seen from both years, so its two ends match.
        $this->assertEqualsWithDelta($last['start'], $first['start'], 1e-6);
        $this->assertEqualsWithDelta($last['end'], $first['end'], 1e-6);
    }

    /**
     * Between the two stations the planet goes backwards, and outside it goes forwards. It
     * sounds obvious and it is exactly what breaks if the stations get paired up wrong.
     */
    public function test_inside_the_period_it_goes_backwards_and_outside_forwards(): void
    {
        $periods = Retrogrades::periods(
            Body::Mars,
            $this->jd('2022-01-01 00:00:00'),
            $this->jd('2024-01-01 00:00:00')
        );

        $this->assertNotEmpty($periods);

        foreach ($periods as $period) {
            if ($period['start'] === null || $period['end'] === null) {
                continue;
            }

            $midpoint = ($period['start'] + $period['end']) / 2.0;

            $this->assertTrue(Retrogrades::retrogradeAt(Body::Mars, $midpoint));
            $this->assertFalse(Retrogrades::retrogradeAt(Body::Mars, $period['start'] - 5.0));
            $this->assertFalse(Retrogrades::retrogradeAt(Body::Mars, $period['end'] + 5.0));
        }
    }

    /**
     * The Sun and the Moon never retrograde as seen from here. Returning an empty list would
     * read as "this year has not retrograded", which is a different thing.
     */
    public function test_asking_about_the_sun_or_the_moon_is_refused(): void
    {
        foreach ([Body::Sun, Body::Moon] as $body) {
            try {
                Retrogrades::stations($body, $this->jd('2026-01-01 00:00:00'), $this->jd('2027-01-01 00:00:00'));
                $this->fail($body->value.' should have thrown');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('do not go retrograde', $e->getMessage());
            }
        }
    }

    public function test_around_an_instant_gives_the_ongoing_period_or_the_next_one(): void
    {
        // Halfway through the March 2026 retrogradation.
        $inside = Retrogrades::around(Body::Mercury, $this->jd('2026-03-05 00:00:00'));

        $this->assertNotNull($inside);
        $this->assertTrue($inside['underWay']);
        $this->assertLessThan($this->jd('2026-03-05 00:00:00'), $inside['start']);
        $this->assertGreaterThan($this->jd('2026-03-05 00:00:00'), $inside['end']);

        // And between two periods, the next one.
        $outside = Retrogrades::around(Body::Mercury, $this->jd('2026-05-01 00:00:00'));

        $this->assertNotNull($outside);
        $this->assertFalse($outside['underWay']);
        $this->assertGreaterThan($this->jd('2026-05-01 00:00:00'), $outside['start']);
    }
}
