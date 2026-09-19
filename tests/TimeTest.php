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

    /**
     * Moving a clock label by an offset is something PHP can already do; carrying the 60th second
     * across the move is the thing it cannot, because `DateTimeImmutable` has no way of holding
     * 23:59:60 and turns it into the 00:00:00 of the next day, which is another instant.
     *
     * The 61-second minute travels as a block, so the 60th second is still the 60th on the far
     * side. It holds because every offset any zone has used since the first leap second of 30
     * June 1972 is a whole number of minutes: 51 of them, measured over the IANA database.
     */
    public function test_the_sixtieth_second_survives_an_offset(): void
    {
        /* 31 December 2016 carries the last leap second inserted so far. */
        $cases = [
            /* offset, expected label */
            [60,  [2017, 1, 1, 0, 59, 60.0]],
            [-60, [2016, 12, 31, 22, 59, 60.0]],
            [330, [2017, 1, 1, 5, 29, 60.0]],
            [345, [2017, 1, 1, 5, 44, 60.0]],
            [765, [2017, 1, 1, 12, 44, 60.0]],
            [-720, [2016, 12, 31, 11, 59, 60.0]],
        ];

        foreach ($cases as [$offset, [$year, $month, $day, $hour, $minute, $second]]) {
            $local = Time::utcToLocal(2016, 12, 31, 23, 59, 60.0, $offset);

            $this->assertSame(
                ['year' => $year, 'month' => $month, 'day' => $day, 'hour' => $hour, 'minute' => $minute, 'second' => $second],
                $local,
                'the leap second at '.$offset.' minutes'
            );

            /* And back, which is where the leap second has to be checked on the UTC side. */
            $this->assertSame(
                ['year' => 2016, 'month' => 12, 'day' => 31, 'hour' => 23, 'minute' => 59, 'second' => 60.0],
                Time::localToUtc($year, $month, $day, $hour, $minute, $second, $offset)
            );
        }

        /* The fraction of the second comes back bit for bit, because it is carried alongside the
           integer arithmetic instead of through a julian day. */
        $this->assertSame(60.25, Time::utcToLocal(2016, 12, 31, 23, 59, 60.25, 330)['second']);
    }

    /**
     * A 60th second is accepted only where one was really inserted, and where that is depends on
     * the offset, because a leap second belongs to a UTC day. 00:59:60 on 1 January 2017 in
     * Madrid is real and 23:59:60 on that same 31 December in Madrid is not, because in UTC it is
     * 22:59:60, a minute like any other.
     *
     * Swiss checks neither: `swe_utc_time_zone` takes 23:59:60 on 30 June 2020, a day with no
     * jump, and hands back a 60th second with a straight face.
     */
    public function test_a_sixtieth_second_is_rejected_where_none_was_inserted(): void
    {
        $refused = [
            'a day with no jump' => fn () => Time::utcToLocal(2020, 6, 30, 23, 59, 60.0, 60),
            'the middle of a day that does have one' => fn () => Time::utcToLocal(2016, 12, 31, 12, 0, 60.0, 60),
            'a local label whose UTC minute is not the one' => fn () => Time::localToUtc(2016, 12, 31, 23, 59, 60.0, 60),
            'an hour that does not exist' => fn () => Time::utcToLocal(2026, 1, 1, 24, 0, 0.0, 0),
            'a 61st second past the 61st' => fn () => Time::utcToLocal(2016, 12, 31, 23, 59, 61.0, 0),
        ];

        foreach ($refused as $what => $call) {
            try {
                $call();
                $this->fail($what.' was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        /* The one that is real goes through. */
        $this->assertSame(
            ['year' => 2016, 'month' => 12, 'day' => 31, 'hour' => 23, 'minute' => 59, 'second' => 60.0],
            Time::localToUtc(2017, 1, 1, 0, 59, 60.0, 60)
        );
    }

    /**
     * An offset rolls the date, forwards and backwards, over the end of a month and over the end
     * of a year. February 1900 is the one worth pinning: it has 28 days in the gregorian calendar
     * and the shift has to land on the 28th and not on a 29th that does not exist.
     */
    public function test_an_offset_rolls_the_date_both_ways(): void
    {
        $cases = [
            /* year, month, day, hour, minute, offset, expected date and time */
            [[2016, 1, 1, 0, 30, -60], [2015, 12, 31, 23, 30]],
            [[2015, 12, 31, 23, 30, 60], [2016, 1, 1, 0, 30]],
            [[2016, 3, 1, 0, 15, -60], [2016, 2, 29, 23, 15]],
            [[1900, 3, 1, 0, 15, -60], [1900, 2, 28, 23, 15]],
            [[2026, 9, 19, 12, 0, 840], [2026, 9, 20, 2, 0]],
            [[2026, 9, 19, 12, 0, -720], [2026, 9, 19, 0, 0]],
        ];

        foreach ($cases as [[$year, $month, $day, $hour, $minute, $offset], [$ry, $rm, $rd, $rh, $ri]]) {
            $this->assertSame(
                ['year' => $ry, 'month' => $rm, 'day' => $rd, 'hour' => $rh, 'minute' => $ri, 'second' => 0.0],
                Time::utcToLocal($year, $month, $day, $hour, $minute, 0.0, $offset)
            );
        }
    }

    /**
     * An on-the-minute label comes back on the minute, and that is not obvious: `swe_utc_time_zone`
     * gets it wrong for **45.96% of them**, measured exhaustively over the 1,440 minutes of a day
     * and the 51 offsets, and with the same 33,756 of 73,440 in 1600, 1900, 2000, 2026, 2100 and
     * 2400, so it does not depend on the size of the julian day. It comes back one minute lower
     * with the second at 59.99999999999: the shortfall is at most 1.7e-11 seconds and it moves the
     * printed label by sixty of them. It is the same ulp of a julian day that `ttToUtc()` already
     * has written down for the leap seconds, and it bites where it matters most, because a wall
     * clock time is almost always typed on the minute.
     *
     * The numbers Swiss gives are written in by hand, so this runs with no network and nothing
     * installed.
     */
    public function test_an_on_the_minute_label_does_not_come_back_a_minute_lower(): void
    {
        $cases = [
            /* UTC label, offset, what swe_utc_time_zone 2.10.03 returns, what is right */
            [[1985, 6, 10, 0, 1], 60, [1985, 6, 10, 1, 0, 59.99999999999979], [1985, 6, 10, 1, 1]],
            [[1985, 6, 10, 0, 1], 330, [1985, 6, 10, 5, 30, 59.99999999999979], [1985, 6, 10, 5, 31]],
            [[1985, 6, 10, 0, 1], -210, [1985, 6, 9, 20, 30, 59.99999999999659], [1985, 6, 9, 20, 31]],
            [[1985, 6, 10, 0, 2], 345, [1985, 6, 10, 5, 46, 59.999999999999574], [1985, 6, 10, 5, 47]],
            [[2026, 9, 19, 18, 5], 840, [2026, 9, 20, 8, 4, 59.99999999998295], [2026, 9, 20, 8, 5]],
        ];

        foreach ($cases as [[$year, $month, $day, $hour, $minute], $offset, $swiss, [$ry, $rm, $rd, $rh, $ri]]) {
            $local = Time::utcToLocal($year, $month, $day, $hour, $minute, 0.0, $offset);

            $this->assertSame(
                ['year' => $ry, 'month' => $rm, 'day' => $rd, 'hour' => $rh, 'minute' => $ri, 'second' => 0.0],
                $local
            );

            /* And the two really are the same instant: Swiss is a minute lower and a whole second
               short of it, so the difference between the two labels is 1.7e-11 seconds at most. */
            $ours = $local['hour'] * 3600 + $local['minute'] * 60 + $local['second'];
            $theirs = $swiss[3] * 3600 + $swiss[4] * 60 + $swiss[5];

            $this->assertNotSame($swiss[4], $local['minute']);
            $this->assertEqualsWithDelta($ours, $theirs, 1e-10);
        }
    }

    /**
     * The two directions are inverses, and they are two methods and not one with a sign. Swiss
     * documents a single function for both ways round ("for conversion from local time to UTC,
     * use +(offset)"), which is a parameter whose sign changes what the function does: the same
     * trap this engine already avoids in `Horizon::equatorialWithSpeed()`, where the direction is
     * in the name and not in the sign of the obliquity.
     */
    public function test_the_two_directions_are_inverses(): void
    {
        $offsets = [-720, -570, -210, -60, 0, 60, 330, 345, 525, 765, 840];
        $trips = 0;

        foreach ($offsets as $offset) {
            foreach ([[0, 0, 0.0], [12, 30, 17.125], [23, 59, 59.0], [5, 45, 0.5]] as [$hour, $minute, $second]) {
                $local = Time::utcToLocal(2016, 12, 31, $hour, $minute, $second, $offset);

                $this->assertSame(
                    ['year' => 2016, 'month' => 12, 'day' => 31, 'hour' => $hour, 'minute' => $minute, 'second' => $second],
                    Time::localToUtc($local['year'], $local['month'], $local['day'], $local['hour'], $local['minute'], $local['second'], $offset)
                );

                $trips++;
            }
        }

        $this->assertSame(44, $trips);
    }

    /**
     * The date is not checked, exactly as `utcToJulianDay()` does not check it: a 31 February
     * normalises to a 3 March through the round trip, which is the correction `checkedJulianDay()`
     * reads off. Whoever brings a date in from outside checks it there first, and this is pinned
     * so that the behaviour is a decision and not a surprise.
     */
    public function test_an_impossible_date_normalises_instead_of_being_checked(): void
    {
        $this->assertSame(
            ['year' => 2026, 'month' => 3, 'day' => 3, 'hour' => 10, 'minute' => 0, 'second' => 0.0],
            Time::utcToLocal(2026, 2, 31, 10, 0, 0.0, 0)
        );
    }
}
