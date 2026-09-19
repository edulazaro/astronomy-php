<?php

namespace Astronomy\Tests;

use Astronomy\Time;
use PHPUnit\Framework\TestCase;

/**
 * The time scales, which are where the two traps of this engine live.
 *
 * Planets run on Terrestrial Time and houses on Universal Time, because houses depend on how far
 * the Earth has turned and that is what the civil clock measures. Today the two are seventy
 * seconds apart, and using one for the other moves the ascendant by almost a minute of arc.
 */
final class TimeTest extends TestCase
{
    public function test_j2000_is_julian_day_2451545(): void
    {
        $jd = Time::julianDay(new \DateTimeImmutable('2000-01-01 12:00:00', new \DateTimeZone('UTC')));

        $this->assertEqualsWithDelta(2451545.0, $jd, 1e-9);
    }

    /**
     * Terrestrial Time runs ahead of Universal Time by delta T, which around 2000 is a bit over
     * sixty seconds and which nobody can know for the future: it measures how much the rotation
     * of the Earth is slowing down.
     */
    public function test_terrestrial_time_runs_ahead_of_universal_time(): void
    {
        $seconds = (Time::tt(2451545.0) - 2451545.0) * 86400.0;

        $this->assertGreaterThan(60.0, $seconds);
        $this->assertLessThan(80.0, $seconds);
    }

    /**
     * Before 1955 delta T comes from the Stephenson, Morrison and Hohenkerk spline of 2016, the
     * same 54 cubic pieces Swiss Ephemeris uses since its version 2.06. In 1700 it is a bit
     * over fourteen seconds; the old annual table that circulates gives other numbers, and that
     * is the difference between reading the published source and reading a summary of it.
     */
    public function test_delta_t_in_the_past_comes_from_the_published_spline(): void
    {
        $jd = Time::julianDay(new \DateTimeImmutable('1700-01-01 12:00:00', new \DateTimeZone('UTC')));
        $seconds = (Time::tt($jd) - $jd) * 86400.0;

        $this->assertEqualsWithDelta(14.29, $seconds, 0.5);
    }

    /**
     * A date that does not exist is caught instead of silently sliding into the next month, and
     * the correction is not computed: it is read off the round trip, because an impossible date
     * lands by the arithmetic itself on the day its count corresponds to. The 31st of February
     * is the 3rd of March.
     */
    public function test_an_impossible_date_is_caught_and_corrected(): void
    {
        $checked = Time::checkedJulianDay(2026, 2, 31, true);

        $this->assertFalse($checked['valid']);
        $this->assertSame(2026, $checked['year']);
        $this->assertSame(3, $checked['month']);
        $this->assertSame(3, $checked['day']);

        $good = Time::checkedJulianDay(2026, 2, 28, true);

        $this->assertTrue($good['valid']);
        $this->assertSame(28, $good['day']);
    }

    /**
     * 1900 had 28 days in February in the gregorian calendar and 29 in the julian one, and that
     * rule is written nowhere: the days of a month come out of subtracting two julian days, so
     * the leap year rule does not have to be written twice.
     */
    public function test_the_leap_year_rule_is_not_written_anywhere(): void
    {
        $gregorian = Time::civilJulianDay(1900, 3, 1, true) - Time::civilJulianDay(1900, 2, 1, true);
        $julian = Time::civilJulianDay(1900, 3, 1, false) - Time::civilJulianDay(1900, 2, 1, false);

        $this->assertEqualsWithDelta(28.0, $gregorian, 1e-9);
        $this->assertEqualsWithDelta(29.0, $julian, 1e-9);
    }

    /**
     * The clock entry point is the only door a birth goes through, and it gives back both scales
     * at once so that nobody can mix them up. The hour comes in with its time zone, not in UTC:
     * nobody knows what time UTC they were born at.
     */
    public function test_the_clock_gives_both_scales(): void
    {
        [$jdTT, $jdUt] = Time::fromClock(new \DateTimeImmutable('1981-05-11 09:15:00', new \DateTimeZone('Europe/Madrid')));

        $this->assertGreaterThan($jdUt, $jdTT);

        /* Half a second below the plain UTC julian day, and that half second is UT1-UTC of that
           day: the clock reads UTC and what turns the sky is UT1. */
        $this->assertEqualsWithDelta(2444735.8020773, $jdUt, 1e-6);
        /* Delta T in 1981, which is a bit under fifty two seconds and not the seventy of
           today: it grows with the centuries and nobody can know it for the future. */
        $this->assertEqualsWithDelta(51.7, ($jdTT - $jdUt) * 86400.0, 1.0);
    }

    /**
     * Sidereal time is what turns, so it comes back to the same value about four minutes earlier
     * every civil day.
     */
    public function test_a_sidereal_day_is_shorter_than_a_civil_one(): void
    {
        $advance = fmod(Time::meanSiderealTime(2451546.0) - Time::meanSiderealTime(2451545.0) + 360.0, 360.0);

        $this->assertEqualsWithDelta(0.9856, $advance, 0.001);
    }

    /**
     * The mean sidereal time is the apparent one with the equation of the equinoxes taken out,
     * and the difference between them is at most about a second of time.
     */
    public function test_mean_and_apparent_sidereal_time_differ_by_the_equation_of_the_equinoxes(): void
    {
        $difference = abs(Time::apparentSiderealTime(2451545.0) - Time::meanSiderealTime(2451545.0)) * 3600.0;

        $this->assertLessThan(20.0, $difference);
    }
}
