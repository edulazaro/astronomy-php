<?php

namespace Astronomy\Tests;

use Astronomy\Saros;
use Astronomy\Time;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The saros series number, checked against the NASA catalog.
 *
 * Checking against the definition itself does not work here, which is the path houses, nodes
 * and crossings use. The saros is not geometry: it is a numbering convention with an origin
 * chosen by van den Bergh in 1955, so the only check that means anything is against the numbers
 * that are published. Either the same numbers come out or they do not.
 *
 * The eclipses below are **copied by hand** from NASA's Five Millennium Catalog
 * (Espenak and Meeus), the solar one from `SEcat5` and the lunar one from `LEcat5`, with the
 * date, the TD time of the maximum, the lunation number and the series exactly as each row
 * prints them. They are spread over five millennia, from -1982 to 3000, and carry both
 * calendars: **before 1582 October 15 the catalog runs in Julian**, which is the same trap that
 * already bit in the heliacal phenomena.
 *
 * As a bonus the lunation number is checked too, because the NASA publishes it in its own
 * column ("Luna Num"), so the test does not only look at the final result but at the
 * intermediate step.
 *
 * @see https://eclipse.gsfc.nasa.gov/SEcat5/SEcatalog.html
 * @see https://eclipse.gsfc.nasa.gov/LEcat5/LEcatalog.html
 */
class SarosTest extends TestCase
{
    /**
     * Solar eclipses from the NASA catalog: date, TD time, calendar, lunation and series.
     *
     * The 1602 one carries series 102 with those of its era running around 130, and the -1982
     * one carries series 0: both are here because they are the ones that punish the choice of
     * modulus representative, not because there is anything special about them.
     *
     * @return array<string, array{int, int, float, string, bool, int, int}>
     */
    public static function solar(): array
    {
        //        year    month day  TD time     greg.  lunation  series
        return [
            '-1982 Dec 28' => [-1982, 12, 28.0, '07:38:06', false, -49239, 0],
            '-0998 Aug 13' => [-998, 8, 13.0, '04:30:26', false, -37073, 29],
            '1601 Jan 04' => [1601, 1, 4.0, '12:24:38', true, -4935, 125],
            '1602 May 21' => [1602, 5, 21.0, '13:06:44', true, -4918, 102],
            '1698 Apr 10' => [1698, 4, 10.0, '18:34:26', true, -3732, 124],
            '2001 Jun 21' => [2001, 6, 21.0, '12:04:46', true, 18, 127],
            '2017 Aug 21' => [2017, 8, 21.0, '18:26:40', true, 218, 145],
            '2024 Apr 08' => [2024, 4, 8.0, '18:18:29', true, 300, 139],
            '2026 Aug 12' => [2026, 8, 12.0, '17:47:06', true, 329, 126],
            '2081 Sep 03' => [2081, 9, 3.0, '09:07:31', true, 1010, 136],
            '3000 Oct 19' => [3000, 10, 19.0, '16:10:16', true, 12378, 169],
        ];
    }

    /**
     * Lunar eclipses from the NASA catalog, in the same shape.
     *
     * Careful with the lunation: the NASA numbers a lunar eclipse by the NEW moon that precedes
     * it, not by the full moon it happens at. The one on 1601 January 18 and the solar one on
     * 1601 January 4 both carry lunation -4935, and they are different eclipses of the same
     * lunation.
     *
     * @return array<string, array{int, int, float, string, bool, int, int}>
     */
    public static function lunar(): array
    {
        return [
            '-1499 Jan 22' => [-1499, 1, 22.0, '15:42:24', false, -43277, 0],
            '-1481 Feb 02' => [-1481, 2, 2.0, '23:12:49', false, -43054, 0],
            '1601 Jan 18' => [1601, 1, 18.0, '14:32:25', true, -4935, 137],
            '1601 Jun 15' => [1601, 6, 15.0, '17:39:34', true, -4930, 104],
            '2018 Jul 27' => [2018, 7, 27.0, '20:22:54', true, 229, 129],
            '2019 Jan 21' => [2019, 1, 21.0, '05:13:27', true, 235, 134],
            '2022 May 16' => [2022, 5, 16.0, '04:12:42', true, 276, 131],
            '2025 Mar 14' => [2025, 3, 14.0, '06:59:56', true, 311, 123],
            '2094 Jun 28' => [2094, 6, 28.0, '10:01:57', true, 1168, 131],
        ];
    }

    /**
     * The eleven published solar series come out right, from -1982 to 3000.
     *     */
    #[DataProvider('solar')]
    public function test_the_solar_series_is_the_one_the_nasa_publishes(
        int $year, int $month, float $day, string $time, bool $gregorian, int $lunation, int $series
    ): void {
        $saros = Saros::forSolar(self::jd($year, $month, $day, $time, $gregorian));

        $this->assertNotNull($saros, 'The eclipse falls within the canon, so it has to give a series.');
        $this->assertSame($series, $saros['series']);
    }

    /**
     * And the nine lunar ones.
     *     */
    #[DataProvider('lunar')]
    public function test_the_lunar_series_is_the_one_the_nasa_publishes(
        int $year, int $month, float $day, string $time, bool $gregorian, int $lunation, int $series
    ): void {
        $saros = Saros::forLunar(self::jd($year, $month, $day, $time, $gregorian));

        $this->assertNotNull($saros);
        $this->assertSame($series, $saros['series']);
    }

    /**
     * The lunation number matches the catalog's "Luna Num" column.
     *
     * It is the intermediate step everything else comes from, and the NASA publishes it, so it
     * is checked on its own: if the series ever failed, this says whether the fault is in the
     * lunation number or in the arithmetic that comes after it.
     */
    public function test_the_lunation_number_is_the_one_the_nasa_prints(): void
    {
        foreach (self::solar() as $name => [$year, $month, $day, $time, $gregorian, $lunation,]) {
            $this->assertSame($lunation, Saros::lunation(self::jd($year, $month, $day, $time, $gregorian)), $name);
        }

        foreach (self::lunar() as $name => [$year, $month, $day, $time, $gregorian, $lunation,]) {
            $this->assertSame($lunation, Saros::lunation(self::jd($year, $month, $day, $time, $gregorian), true), $name);
        }
    }

    /**
     * Series 0 exists, and the popular rule that numbers from 1 to 223 turns it into 223.
     *
     * This is the only place where the [0, 222] window and the [1, 223] window do not say the
     * same thing, and it really happens in the canon: three solar eclipses and several lunar
     * ones from the second millennium BC carry series 0. Without this test, changing the
     * window would break nothing visible between 1600 and 2400, and the fault would only show
     * up in ancient dates.
     */
    public function test_series_zero_exists_and_is_not_confused_with_223(): void
    {
        $solar = Saros::forSolar(self::jd(-1982, 12, 28.0, '07:38:06', false));
        $lunar = Saros::forLunar(self::jd(-1499, 1, 22.0, '15:42:24', false));

        $this->assertSame(0, $solar['series']);
        $this->assertSame(0, $lunar['series']);

        foreach (array_merge(self::solar(), self::lunar()) as $name => $row) {
            $this->assertLessThan(223, $row[6], "Series 223 does not appear in the canon: $name");
        }
    }

    /**
     * The constant 38 reproduces the NASA's cycle table, which is where it comes from.
     *
     * This is the structural check and it is worth more than any single case: the NASA
     * publishes eight named intervals and what happens to the series in each one. A single
     * constant has to give all eight. If anyone touched the 38 or the 223 to make a particular
     * eclipse fit, this falls apart entirely.
     */
    public function test_the_nasas_cycle_table_comes_from_the_same_constant(): void
    {
        $table = [
            'lunation' => [1, 38],
            'short semester' => [5, -33],
            'semester' => [6, 5],
            'tritos' => [135, 1],
            'saros' => [223, 0],
            'metonic cycle' => [235, 10],
            'inex' => [358, 1],
            'exeligmos' => [669, 0],
        ];

        // A real starting eclipse, so the shifts are measured along the same path the code
        // walks and not on loose arithmetic.
        $start = self::jd(2017, 8, 21.0, '18:26:40', true);
        $base = Saros::forSolar($start)['series'];

        foreach ($table as $name => [$lunations, $shift]) {
            $arrival = Saros::forSolar($start + $lunations * 29.530588853);

            $expected = (($base + $shift) % 223 + 223) % 223;

            $this->assertSame($expected, $arrival['series'], "The \"{$name}\" cycle does not give the published shift.");
        }
    }

    /**
     * A whole saros later, the same series. That is the definition of the cycle.
     *
     * Checked against the real eclipses of the catalog and not against a made-up date, and
     * chained ten times, which is a hundred and eighty years: if the modulus arithmetic
     * drifted, it would show up here.
     */
    public function test_a_whole_saros_later_the_series_does_not_change(): void
    {
        foreach (self::solar() as $name => [$year, $month, $day, $time, $gregorian, , $series]) {
            $jd = self::jd($year, $month, $day, $time, $gregorian);

            for ($turn = 1; $turn <= 10; $turn++) {
                $next = Saros::forSolar($jd + $turn * 223 * 29.530588853);

                if ($next === null) {
                    continue;   // it has gone outside the canon, which is what is supposed to happen
                }

                $this->assertSame($series, $next['series'], "$name, turn $turn");
            }
        }
    }

    /**
     * Outside the verified five-millennium canon it returns null, not a number with a good
     * enough look to it.
     *
     * What breaks there is not the formula, which is exact arithmetic, but which modulus
     * representative applies: the active series drift with the epoch and end up falling
     * outside the window. It is exactly the kind of fault that does not show, because it would
     * return a series within the right range and perfectly believable.
     */
    public function test_outside_the_verified_canon_nothing_is_returned(): void
    {
        $this->assertNull(Saros::forSolar(Time::civilJulianDay(-3000, 6, 15.0, false)));
        $this->assertNull(Saros::forLunar(Time::civilJulianDay(-3000, 6, 15.0, false)));
        $this->assertNull(Saros::forSolar(Time::civilJulianDay(4000, 6, 15.0)));
        $this->assertNull(Saros::forLunar(Time::civilJulianDay(4000, 6, 15.0)));
    }

    /**
     * An instant that is not close to a syzygy has no lunation number.
     *
     * This is not an eclipse detector, that is `Eclipses`'s job: it is that halfway through a
     * lunation the rounding no longer means anything, and answering there would be inventing
     * the series of an eclipse that does not exist. The first quarter of the 2017 eclipse
     * serves as an example because it falls right in the middle.
     */
    public function test_an_instant_far_from_the_syzygy_has_no_lunation(): void
    {
        $eclipse = self::jd(2017, 8, 21.0, '18:26:40', true);

        $this->assertNotNull(Saros::lunation($eclipse));
        $this->assertNull(Saros::lunation($eclipse + 29.530588853 / 4), 'A quarter of a lunation later there is no syzygy.');
        $this->assertNull(Saros::forSolar($eclipse + 29.530588853 / 4));

        // And the full moon of that same lunation is not a new moon, nor the other way round.
        $this->assertNull(Saros::lunation($eclipse, true));
    }

    /**
     * The time scale does not move the result, and it is worth stating.
     *
     * The signature asks for TT because that is what the engine handles, but the maximum of an
     * eclipse arrives from `Eclipses` in UT and the two give the same thing: delta T is
     * minutes, and here it is rounded to the nearest lunation, with almost fifteen days of
     * margin. Without this test, someone would end up building a conversion that is not needed.
     */
    public function test_it_makes_no_difference_to_give_it_tt_or_ut(): void
    {
        foreach (self::solar() as $name => [$year, $month, $day, $time, $gregorian, , $series]) {
            $jd = self::jd($year, $month, $day, $time, $gregorian);

            // Delta T in the year 3000 is over an hour, and even that does not move it.
            $this->assertSame($series, Saros::forSolar($jd - 4428 / 86400.0)['series'], $name);
        }
    }

    /**
     * The member within the series is returned null on purpose.
     *
     * The key exists in the response and always holds null. It is not missing: it is that
     * where each series starts does not come from any formula (they last between 69 and 87
     * eclipses and each one starts wherever the geometry dictates), and the obvious linear
     * model gives the 2024 eclipse member 32 where the NASA publishes 30. An invented member
     * reads exactly as well as the right one, so it stays null until there is somewhere to get
     * it from.
     */
    public function test_the_member_is_null_because_there_is_nowhere_to_get_it_from(): void
    {
        $saros = Saros::forSolar(self::jd(2017, 8, 21.0, '18:26:40', true));

        $this->assertArrayHasKey('member', $saros);
        $this->assertNull($saros['member']);
    }

    /**
     * The Julian day of a catalog row, with its calendar and its TD time.
     */
    private static function jd(int $year, int $month, float $day, string $time, bool $gregorian): float
    {
        [$h, $m, $s] = array_map('intval', explode(':', $time));

        return Time::civilJulianDay($year, $month, $day + ($h + $m / 60 + $s / 3600) / 24.0, $gregorian);
    }
}
