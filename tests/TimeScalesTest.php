<?php

namespace Astronomy\Tests;

use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Delta T and the calendar.
 *
 * Delta T cannot be calculated, only measured, so what is checked is that the table
 * `astro:deltat` writes is complete and correctly parsed, that the splices between the
 * historical spline, the observations and the extrapolation do not jump, that the values are
 * the ones the sources publish, and that everything matches Swiss Ephemeris as far as Swiss
 * has observations and parts from it exactly where we have newer data.
 *
 * The calendar can be checked completely, against historical anchors and against Swiss.
 */
class TimeScalesTest extends TestCase
{
    /**
     * The table `astro:deltat` writes has to be the whole Stephenson, Morrison and Hohenkerk
     * Table S15 and the observation series without a month missing. A stretch lost in parsing
     * or a missing month raise no error: they give a slightly wrong delta T that nobody sees
     * looking at a wheel, and that is why the count is fixed here as well as in the command.
     */
    public function test_the_delta_t_table_is_complete(): void
    {
        $table = require \Astronomy\DataFolder::path('deltat.php');

        // The spline: 54 stretches from -720 to 2016, chained and continuous at every knot save
        // for the rounding to thousandths of the published coefficients.
        $this->assertCount(54, $table['spline']);
        $this->assertSame([-720.0, 400.0, 20550.593, -21268.478, 11863.418, -4541.129], $table['spline'][0]);
        $this->assertSame(2016.0, $table['spline'][53][1]);

        foreach ($table['spline'] as $i => $stretch) {
            $this->assertCount(6, $stretch, "stretch {$i} does not have two years and four coefficients");

            if ($i > 0) {
                $previous = $table['spline'][$i - 1];
                $this->assertSame($previous[1], $stretch[0], "stretch {$i} does not start where the previous one ends");
                $this->assertEqualsWithDelta(array_sum(array_slice($previous, 2)), $stretch[2], 0.0015, "the spline jumps in the year {$stretch[0]}");
            }
        }

        // The observations: from 1 January 1955 with 31.07, in order, and one value a month
        // from February 1973 with no gaps.
        $points = $table['table'];

        $this->assertSame([2435108.5, 31.07], $points[0]);
        $this->assertGreaterThan(690, count($points));

        $monthlyFrom = array_search(2441714.5, array_column($points, 0), true);
        $this->assertNotFalse($monthlyFrom, 'missing 1 February 1973, where the IERS series starts');

        for ($i = 1; $i < count($points); $i++) {
            $step = $points[$i][0] - $points[$i - 1][0];

            $this->assertGreaterThan(0, $step, "the table is not in order at {$points[$i][0]}");
            $this->assertGreaterThan(-5, $points[$i][1]);
            $this->assertLessThan(100, $points[$i][1]);

            if ($i > $monthlyFrom) {
                $this->assertGreaterThanOrEqual(28, $step, "a month is missing before {$points[$i][0]}");
                $this->assertLessThanOrEqual(31, $step, "a month is missing before {$points[$i][0]}");
            }
        }

        // What is measured reaches into the table, and the IERS prediction that follows does
        // not run past a year and a bit.
        $last = $points[count($points) - 1][0];

        $this->assertSame($table['observedUntil'], Time::deltaTObservedUntil());
        $this->assertContains($table['observedUntil'], array_column($points, 0));
        $this->assertLessThan(450, $last - $table['observedUntil']);
    }

    /**
     * Every splice, measured at a fine step and pinned down. There are four and none of them
     * can jump: the parabola with the spline at -720 (it shifts on load), the spline with the
     * first observation in 1955 (the 0.66 second step is spread over the previous thousand
     * days), the table with the extrapolation at its last point (the step is spread over a
     * hundred years) and the cubic with the parabola in 2500. A jump in any of them is a UT
     * instant that changes suddenly when crossing a date.
     */
    public function test_the_delta_t_splices_do_not_jump(): void
    {
        $table = require \Astronomy\DataFolder::path('deltat.php');
        $tableEnd = $table['table'][count($table['table']) - 1][0];

        $splices = [
            '-720' => Time::civilJulianDay(-720, 1, 1.0),
            'start of the ramp' => 2434108.5,
            '1955' => 2435108.5,
            'February 1973' => 2441714.5,
            'end of the table' => $tableEnd,
            'a hundred years after the table' => $tableEnd + 36525,
            '2500' => Time::civilJulianDay(2500, 1, 1.0),
        ];

        foreach ($splices as $name => $jd) {
            $jump = Time::deltaT($jd + 1e-4) - Time::deltaT($jd - 1e-4);

            $this->assertEqualsWithDelta(0, $jump, 0.001, "delta T jumps at {$name}");
        }

        // And in 1955 the ramp ends exactly at the first atomic observation.
        $this->assertEqualsWithDelta(31.07, Time::deltaT(2435108.5), 1e-9);
    }

    /**
     * The published anchors: at every knot of the spline, delta T is Table S15's a0
     * coefficient plus the tidal adjustment, which carries the spline's own tidal
     * acceleration (-25.85 arcseconds per century squared) over to DE431's (-25.80):
     * -0.000091·(n' - n'0)·(year - 1955)². It is half a second in 1600 and 32 in -720, and it
     * is written here with the formula so it is clear what is being checked.
     */
    public function test_delta_t_gives_the_published_anchors(): void
    {
        $knots = [
            // [year, Table S15's a0]
            [-720, 20550.593], [400, 6604.404], [1000, 1467.654], [1500, 292.635], [1600, 89.380],
            [1650, 43.736], [1720, 10.730], [1800, 18.714], [1900, -1.977], [1950, 28.932],
        ];

        foreach ($knots as [$year, $a0]) {
            $jd = Time::civilJulianDay($year, 1, 1.0);
            $gregorianYear = 2000 + ($jd - Time::J2000) / 365.2425;
            $adjustment = -0.000091 * (Time::TIDAL_ACCELERATION + 25.85) * ($gregorianYear - 1955) ** 2;

            $this->assertEqualsWithDelta($a0 + $adjustment, Time::deltaT($jd), 0.0015, "knot for the year {$year}");
        }

        // And the observations, exactly as published: 1955 and 1960 from the Astronomical
        // Almanac, the rest computed by the USNO from the same IERS data we use, copied from
        // its `deltat.data`. It is the check that the reconstruction
        // 32.184 + (TAI-UTC) - (UT1-UTC) is done right, column by column and leap second by
        // leap second.
        $observations = [
            [2435108.5, 31.07],  // 1955-01
            [2436934.5, 33.150],  // 1960-01
            [2441714.5, 43.4724],  // 1973-02
            [2444239.5, 50.5387],  // 1980-01
            [2447496.5, 56.2583],  // 1988-12
            [2447892.5, 56.8553],  // 1990-01
            [2451544.5, 63.8285],  // 2000-01
            [2455197.5, 66.0699],  // 2010-01
            [2457388.5, 68.1024],  // 2016-01
            [2458849.5, 69.3612],  // 2020-01
            [2460310.5, 69.1752],  // 2024-01
            [2460676.5, 69.1377],  // 2025-01
            [2460949.5, 69.0909],  // 2025-10
        ];

        foreach ($observations as [$jd, $expected]) {
            $this->assertEqualsWithDelta($expected, Time::deltaT($jd), 0.0001, "observation at JD {$jd}");
        }
    }

    /**
     * Against Swiss Ephemeris 2.10.03's `swe_deltat`, copied by hand.
     *
     * Before 1955 it is the same spline with the same tidal adjustment and the same ramp, so
     * it matches to a ten thousandth of a second across the whole range, from -720 to 1954.
     * From 1955 to 2023 the difference is the interpolation: Swiss carries one value a year
     * and interpolates with Bessel, here there are two a year until 1973 and twelve after that
     * and it interpolates linearly; measured month by month, the worst difference is 0.036
     * seconds until 1973 and 0.065 after.
     *
     * And from 2024 on we part ways, because that version of Swiss already extrapolates
     * (69.10 in 2024, 68.80 in 2028) and here there are IERS measurements up to 2026: 69.18,
     * 69.14, 69.11. They run up to 0.57 seconds apart in 2028 and the difference dissolves
     * towards 2130, when the two extrapolations already run over the same curve. That is not
     * pinned against Swiss: what is pinned is that in 2026 the value is the observed one.
     */
    public function test_delta_t_matches_swiss_where_swiss_has_observations(): void
    {
        $swiss = [
            // [Julian day, Swiss's delta T in seconds, margin]
            [1465390.5, 20142.4746, 0.001],  // -700
            [1538438.5, 16768.7659, 0.001],  // -500
            [1721059.5, 10556.9107, 0.001],  // 0
            [1903681.5, 5590.0933, 0.001],  // 500
            [2086302.5, 1463.5043, 0.001],  // 1000
            [2195875.5, 624.6298, 0.001],  // 1300
            [2268923.5, 291.6930, 0.001],  // 1500
            [2305447.5, 88.8066, 0.001],  // 1600
            [2312752.5, 66.5536, 0.001],  // 1620
            [2323710.5, 43.3127, 0.001],  // 1650
            [2341972.5, 14.2867, 0.001],  // 1700
            [2360234.5, 16.0056, 0.001],  // 1750
            [2378496.5, 18.6047, 0.001],  // 1800
            [2396758.5, 9.2668, 0.001],  // 1850
            [2415020.5, -1.9908, 0.001],  // 1900
            [2433282.5, 28.9319, 0.001],  // 1950
            [2434378.5, 30.1805, 0.001],  // 1953, in the middle of the ramp
            [2435108.5, 31.0702, 0.001],  // 1955
            [2436385.5, 32.4323, 0.05],  // 1958-07
            [2436934.5, 33.1500, 0.02],  // 1960
            [2438577.5, 35.3622, 0.05],  // 1964-07, the worst of the semestral table
            [2438761.5, 35.7316, 0.02],  // 1965
            [2440587.5, 40.1813, 0.02],  // 1970
            [2441683.5, 43.3724, 0.02],  // 1973
            [2442594.5, 45.9553, 0.1],  // 1975-07
            [2444239.5, 50.5387, 0.1],  // 1980
            [2447892.5, 56.8562, 0.1],  // 1990
            [2450934.5, 63.1508, 0.1],  // 1998-05
            [2451544.5, 63.8285, 0.1],  // 2000
            [2455197.5, 66.0703, 0.1],  // 2010
            [2457448.5, 68.1850, 0.1],  // 2016-03
            [2458849.5, 69.3612, 0.1],  // 2020
            [2459001.5, 69.3737, 0.1],  // 2020-06
            [2459884.5, 69.2015, 0.1],  // 2022-11
            [2459945.5, 69.1832, 0.1],  // 2023
        ];

        foreach ($swiss as [$jd, $expected, $margin]) {
            $this->assertEqualsWithDelta($expected, Time::deltaT($jd), $margin, "delta T at JD {$jd}");
        }

        // Where Swiss 2.10.03 already extrapolates, here the measurements decide: 1 January
        // 2026 is 69.11 seconds according to the USNO's `deltat.data`, and Swiss gives 68.90.
        $this->assertEqualsWithDelta(69.1099, Time::deltaT(2461041.5), 0.001);
    }

    /**
     * Past the table, Swiss's extrapolation for this model is followed: the long term cubic
     * until 2500 and the parabola after, with the step against the last point spread over a
     * hundred years. Since our table ends a year and a bit after theirs, between 2028 and 2130
     * we run up to 0.57 seconds apart; past the transition the two curves are the same one and
     * match to the thousandth. That in 2200 it gives 163 seconds and not the 442 of Espenak and
     * Meeus's parabola, or the 427 of Stephenson's 1997 formula that Swiss's documentation
     * describes, is deliberate and is written up in `Time::deltaT()`.
     */
    public function test_past_the_table_delta_t_follows_swisss_extrapolation(): void
    {
        $swiss = [
            [2464328.5, 70.508, 0.6],  // 2035, inside the transition
            [2488069.5, 93.182, 0.6],  // 2100
            [2506331.5, 121.662, 0.001],  // 2150
            [2524593.5, 162.997, 0.001],  // 2200
            [2561117.5, 296.990, 0.001],  // 2300
            [2634166.5, 854.967, 0.001],  // 2500
            [2816787.5, 3292.375, 0.001],  // 3000
        ];

        foreach ($swiss as [$jd, $expected, $margin]) {
            $this->assertEqualsWithDelta($expected, Time::deltaT($jd), $margin, "delta T at JD {$jd}");
        }

        // And it grows: the Earth's rotation slows down, and an extrapolation that dropped
        // would be a flipped sign.
        $previous = Time::deltaT(2464328.5);

        for ($year = 2040; $year <= 3000; $year += 20) {
            $value = Time::deltaT(Time::civilJulianDay($year, 1, 1.0));

            $this->assertGreaterThan($previous, $value, "delta T drops towards the year {$year}");
            $previous = $value;
        }
    }

    /**
     * The calendar's classic anchors, and that the round trip undoes itself in both.
     */
    public function test_the_civil_julian_day_round_trips_in_both_calendars(): void
    {
        // The reform: Thursday 4 October 1582 Julian was followed by Friday 15 October
        // Gregorian. They are consecutive Julian days.
        $this->assertSame(2299159.5, Time::civilJulianDay(1582, 10, 4, false));
        $this->assertSame(2299160.5, Time::civilJulianDay(1582, 10, 15, true));

        // The origin: 1 January -4712 at noon, in the Julian calendar.
        $this->assertSame(0.0, Time::civilJulianDay(-4712, 1, 1.5, false));
        $this->assertSame([-4712, 1, 1.5], Time::civilDate(0.0, false));

        // J2000, and that the version with PHP's date is the same computation.
        $this->assertSame(Time::J2000, Time::civilJulianDay(2000, 1, 1.5, true));
        $this->assertEqualsWithDelta(
            Time::civilJulianDay(1500, 1, 1, true),
            Time::julianDay(new DateTimeImmutable('1500-01-01 00:00', new DateTimeZone('UTC'))),
            1e-9
        );

        // In 1500 the two calendars are nine days apart (ten from March 1500, when the Julian
        // one puts in a leap day the Gregorian one does not).
        $this->assertSame(9.0, Time::civilJulianDay(1500, 1, 1, false) - Time::civilJulianDay(1500, 1, 1, true));
        $this->assertSame(10.0, Time::civilJulianDay(1500, 3, 1, false) - Time::civilJulianDay(1500, 3, 1, true));

        // Round trip, year by year, in both calendars and with a fraction of a day.
        foreach ([true, false] as $gregorian) {
            for ($year = -4000; $year <= 3000; $year += 37) {
                foreach ([[1, 1, 1.0], [2, 28, 0.25], [7, 15, 0.5], [12, 31, 0.999]] as [$month, $day, $fraction]) {
                    $jd = Time::civilJulianDay($year, $month, $day + $fraction, $gregorian);
                    [$y, $m, $d] = Time::civilDate($jd, $gregorian);

                    $this->assertSame($year, $y, "year on the way back ({$year}-{$month}-{$day})");
                    $this->assertSame($month, $m, "month on the way back ({$year}-{$month}-{$day})");
                    $this->assertEqualsWithDelta($day + $fraction, $d, 1e-6, "day on the way back ({$year}-{$month}-{$day})");
                }
            }
        }
    }

    /**
     * Against `swe_julday` and `swe_revjul`: over eight hundred random dates between -4712 and
     * 3000, in both calendars, the difference was exactly zero in all of them. Here a few are
     * copied, including negative years and the fractional day.
     */
    public function test_the_calendar_matches_swiss(): void
    {
        $swiss = [
            // [year, month, day with fraction, gregorian, Swiss's Julian day]
            [1582, 10, 4.0, false, 2299159.5],
            [1582, 10, 15.0, true, 2299160.5],
            [-4712, 1, 1.5, false, 0.0],
            [0, 1, 1.0, true, 1721059.5],
            [0, 1, 1.0, false, 1721057.5],
            [-1, 12, 31.0, true, 1721058.5],
            [1000, 7, 1.0, false, 2086489.5],
            [1650, 7, 1.0, true, 2323891.5],
            [2000, 7, 1.0, true, 2451726.5],
        ];

        foreach ($swiss as [$year, $month, $day, $gregorian, $expected]) {
            $this->assertEqualsWithDelta(
                $expected,
                Time::civilJulianDay($year, $month, $day, $gregorian),
                1e-9,
                sprintf('%d-%d-%.2f %s', $year, $month, $day, $gregorian ? 'Gregorian' : 'Julian')
            );
        }
    }

    /**
     * The table `astro:nutacion` writes has to be the whole IAU 2000B series. A lost term in
     * the parsing raises no error: it gives a slightly wrong nutation that nobody sees looking
     * at a wheel, and that is why the count is fixed here as well as in the command.
     */
    /**
     * What `swe_date_conversion` returns with the Gregorian calendar. Each row: what is asked
     * for (year, month, day and hour) and what comes out (whether it is valid, the Julian day
     * and the corrected date).
     *
     * These are the fifteen cases that break in different ways: 31 February, 29 February of a
     * non leap year, 1900's (divisible by four and by a hundred and not by four hundred), month
     * 0 and 13, day 0 and 32, hour 24 and hour minus one, and a negative year.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: float, 4: bool, 5: float, 6: int, 7: int, 8: int, 9: float}>
     */
    public static function swissDates(): array
    {
        return [
            [2024, 2, 31, 0.0, false, 2460371.500000000, 2024, 3, 2, 0.0],
            [2024, 2, 29, 12.0, true, 2460370.000000000, 2024, 2, 29, 12.0],
            [2023, 2, 29, 12.0, false, 2460005.000000000, 2023, 3, 1, 12.0],
            [2000, 13, 1, 0.0, false, 2451910.500000000, 2001, 1, 1, 0.0],
            [2000, 1, 0, 6.0, false, 2451543.750000000, 1999, 12, 31, 6.0],
            [1900, 2, 29, 0.0, false, 2415079.500000000, 1900, 3, 1, 0.0],
            [1582, 10, 10, 12.0, true, 2299156.000000000, 1582, 10, 10, 12.0],
            [2026, 9, 11, 14.25, true, 2461295.093750000, 2026, 9, 11, 14.25],
            [2000, 0, 15, 3.0, false, 2451527.625000000, 1999, 12, 15, 3.0],
            [1999, 4, 31, 23.5, false, 2451300.479166667, 1999, 5, 1, 23.5],
            [1700, 2, 29, 0.0, false, 2342031.500000000, 1700, 3, 1, 0.0],
            [-100, 3, 15, 0.0, true, 1684608.500000000, -100, 3, 15, 0.0],
            [2024, 6, 15, 24.0, false, 2460477.500000000, 2024, 6, 16, 0.0],
            [2024, 6, 15, -1.0, false, 2460476.458333333, 2024, 6, 14, 23.0],
            [2024, 12, 32, 0.0, false, 2460676.500000000, 2025, 1, 1, 0.0],
        ];
    }

    /**
     * A date that does not exist cannot come out looking fine. It is `swe_date_conversion`,
     * and the fifteen cases match Swiss on all three things: whether it is valid, the Julian
     * day and what the real date was.
     *
     * **`civilJulianDay` checks nothing and that is fine**, because it is the inner door and
     * there the dates come from a `DateTimeImmutable` that already exists. But it is a public
     * API, and through it a 31 February goes in and the Julian day of 2 March comes out
     * without anything raising a warning.
     */
    public function test_a_date_that_does_not_exist_is_caught_and_corrected_as_in_swiss(): void
    {
        foreach (self::swissDates() as [$year, $month, $day, $hours, $valid, $jd, $goodYear, $goodMonth, $goodDay, $goodHours]) {
            $checked = Time::checkedJulianDay($year, $month, $day, $hours);
            $where = sprintf('%d-%d-%d at %.2f', $year, $month, $day, $hours);

            $this->assertSame($valid, $checked['valid'], $where);
            $this->assertEqualsWithDelta($jd, $checked['jd'], 1.0e-9, $where);
            $this->assertSame($goodYear, $checked['year'], $where);
            $this->assertSame($goodMonth, $checked['month'], $where);
            $this->assertSame($goodDay, $checked['day'], $where);
            $this->assertEqualsWithDelta($goodHours, $checked['hours'], 1.0e-6, $where);
        }
    }

    /**
     * The days in each month come from subtracting two Julian days, not from a table nor from
     * the leap year rule written by hand. That saves thirteen numbers and, above all, **saves
     * writing the rule twice**: in the Julian calendar every year divisible by four is a leap
     * year and in the Gregorian one the century years not divisible by four hundred have to be
     * taken out.
     *
     * The case that proves it is 1900: twenty eight days in Gregorian and twenty nine in
     * Julian, and here that is not written anywhere, it comes out of the subtraction.
     */
    public function test_the_days_in_a_month_come_from_subtracting_two_julian_days(): void
    {
        $this->assertSame(28, Time::daysInMonth(1900, 2));
        $this->assertSame(29, Time::daysInMonth(1900, 2, false));
        $this->assertSame(29, Time::daysInMonth(2000, 2));
        $this->assertSame(29, Time::daysInMonth(2024, 2));
        $this->assertSame(28, Time::daysInMonth(2023, 2));

        // And the twelve months add up to the year, which is the check that does not look at
        // any single month.
        foreach ([[1900, 365], [2000, 366], [2023, 365], [2024, 366]] as [$year, $days]) {
            $sum = 0;

            for ($month = 1; $month <= 12; $month++) {
                $sum += Time::daysInMonth($year, $month);
            }

            $this->assertSame($days, $sum, (string) $year);
        }
    }

    /**
     * The mean sidereal time and the apparent one carry the equation of the equinoxes, which
     * is the nutation in longitude projected onto the equator. It is `swe_sidtime0` with
     * nutation zero against `swe_sidtime`.
     *
     * **And what is measured here is where what separates us from Swiss comes from**: the
     * equation of the equinoxes matches theirs to thousandths of an arcsecond, so **the whole
     * offset lives in the MEAN sidereal polynomial**, which is the 1982 model here and another
     * one in Swiss. They are the same 0.017, 0.048 and 0.283 arcseconds that show up in the
     * horizon's return leg and in the ascendant, and now it is known which term they come from.
     */
    public function test_mean_and_apparent_sidereal_time_carry_the_equation_of_the_equinoxes(): void
    {
        // [jd UT, Swiss's sidtime0 with nutation zero, Swiss's sidtime], both in degrees.
        $fromSwiss = [
            [2451545.0, 280.460623016, 280.457072438],
            [2460000.25, 64.355519662, 64.353159593],
            [2415020.5, 100.183854911, 100.188297522],
        ];

        foreach ($fromSwiss as [$jdUt, $mean, $apparent]) {
            $ourMean = Time::meanSiderealTime($jdUt);
            $ourApparent = Time::apparentSiderealTime($jdUt);

            // The equation of the equinoxes: the same as theirs to thousandths of a second.
            $this->assertEqualsWithDelta(
                ($apparent - $mean) * 3600.0,
                ($ourApparent - $ourMean) * 3600.0,
                0.002,
                'jd '.$jdUt
            );

            // And the offset against Swiss is the same in the mean as in the apparent, that
            // is, it belongs to the polynomial and not to the nutation.
            $this->assertEqualsWithDelta(
                ($ourMean - $mean) * 3600.0,
                ($ourApparent - $apparent) * 3600.0,
                0.002,
                'jd '.$jdUt
            );

            $this->assertLessThan(0.3, abs($ourMean - $mean) * 3600.0, 'jd '.$jdUt);
        }

        // The mean one carries no nutation, so it does not move when it moves: in 1900 the
        // equation of the equinoxes is worth +16 arcseconds and in the year 2000 -12.8.
        $this->assertGreaterThan(
            0.0,
            Time::apparentSiderealTime(2415020.5) - Time::meanSiderealTime(2415020.5)
        );
        $this->assertLessThan(
            0.0,
            Time::apparentSiderealTime(2451545.0) - Time::meanSiderealTime(2451545.0)
        );
    }

    public function test_the_nutation_table_is_the_whole_iau_2000b_series(): void
    {
        $table = require \Astronomy\DataFolder::path('nutation.php');

        $this->assertSame('IAU 2000B', $table['model']);
        $this->assertCount(77, $table['terms'], 'the IAU 2000B series has 77 luni-solar terms');

        foreach ($table['terms'] as $i => $term) {
            $this->assertCount(11, $term, "term {$i} does not have five multipliers and six coefficients");
        }

        // The first is the lunar node's, with 17.2 arcseconds: the one that dominates.
        $this->assertSame([0, 0, 0, 0, 1], array_slice($table['terms'][0], 0, 5));
        $this->assertSame(-172064161.0, $table['terms'][0][5]);

        // The five Delaunay arguments, with the full polynomial up to the fourth degree.
        $this->assertSame(['l', 'lp', 'f', 'd', 'om'], array_keys($table['arguments']));

        foreach ($table['arguments'] as $name => $coefficients) {
            $this->assertCount(5, $coefficients, "argument {$name} does not carry the five coefficients");
        }

        // And the two fixed planetary biases, in milliarcseconds.
        $this->assertSame([-0.135, 0.388], $table['bias']);
    }

    /**
     * Against `swe_calc` with Swiss Ephemeris 2.10.03's `SE_ECL_NUT`, in TT and with its
     * default model, which is IAU 2000B. Copied by hand from a measurement over 1200 dates
     * from 1600 to 2400 where the difference was 0.000135" in longitude and 0.000388" in
     * obliquity, constant across all of them: it is exactly the fixed planetary bias that
     * 2000B carries and Swiss leaves out. With the bias set to zero the difference is zero at
     * machine precision, so the series and the arguments are the same. The tolerance is one
     * milliarcsecond.
     *
     * For the record, what there was before: with the four terms written by hand, the same
     * measurement gave a worst case of 0.348" and a median of 0.093" in longitude, 0.082" and
     * 0.020" in obliquity.
     */
    public function test_the_nutation_matches_swiss(): void
    {
        $swiss = [
            // [Julian day TT, nutation in longitude, nutation in obliquity], in arcseconds
            [2305448.000000, 15.172342, 4.228402],  // 1600-01-01
            [2319289.682195, 15.085070, 2.725470],  // 1637-11-24
            [2336197.444444, -15.241068, -3.087464],  // 1684-03-09
            [2350840.037037, -15.364017, 5.283110],  // 1724-04-12
            [2366068.333333, 7.907182, 7.504670],  // 1765-12-21
            [2381589.481481, 13.049496, -6.577891],  // 1808-06-20
            [2397696.333333, -16.122843, -1.229292],  // 1852-07-26
            [2414096.037037, 13.533988, 5.230215],  // 1897-06-20
            [2428738.629630, 17.785757, -2.933747],  // 1937-07-24
            [2443381.222222, 6.364087, -8.594775],  // 1977-08-25
            [2451545.000000, -13.931529, -5.769805],  // J2000
            [2459780.925926, -11.813963, 5.836737],  // 2022-07-20
            [2474423.518519, 2.928781, 9.436082],  // 2062-08-22
            [2489066.111111, 15.026467, 5.038725],  // 2102-09-24
            [2505172.962963, -5.831314, -8.772359],  // 2146-10-30
            [2521060.992093, -12.741952, 6.828171],  // 2190-04-30
            [2536508.111111, 15.095881, 5.543652],  // 2232-08-15
            [2552176.245099, 5.839736, -9.427698],  // 2275-07-09
            [2568136.111111, -15.965474, 3.707826],  // 2319-03-21
            [2582193.000000, -12.050965, 6.939690],  // 2357-09-14
            [2598007.000000, 16.632292, 2.488063],  // 2400-12-31
        ];

        foreach ($swiss as [$jd, $expectedDpsi, $expectedDeps]) {
            [$dpsi, $deps] = Time::nutation(Time::centuries($jd));

            $this->assertEqualsWithDelta($expectedDpsi, rad2deg($dpsi) * 3600, 0.001, "nutation in longitude at JD {$jd}");
            $this->assertEqualsWithDelta($expectedDeps, rad2deg($deps) * 3600, 0.001, "nutation in obliquity at JD {$jd}");
        }
    }

    /**
     * The nutation remembers the last ones it has calculated, because a chart asks for it
     * hundreds of times for four or five instants. What has to be kept is that it returns the
     * one for the instant asked for and not a neighbour's: each body asks for it at t and at t
     * plus and minus the speed's step, which is six hours, and mixing them up would carry the
     * speed off course.
     */
    public function test_the_remembered_nutation_is_the_one_for_the_instant_asked_for(): void
    {
        $t = Time::centuries(2451545.0);
        $step = 0.25 / Time::CENTURY;

        $at = Time::nutation($t);
        $before = Time::nutation($t - $step);
        $after = Time::nutation($t + $step);

        // Three different instants, three different nutations.
        $this->assertNotEquals($at[0], $before[0]);
        $this->assertNotEquals($at[0], $after[0]);

        // And asking for them again in another order, each one is its own.
        $this->assertSame($after, Time::nutation($t + $step));
        $this->assertSame($at, Time::nutation($t));
        $this->assertSame($before, Time::nutation($t - $step));
    }
}
