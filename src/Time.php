<?php

namespace Astronomy;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Everything that has to do with time and with the orientation of the Earth.
 *
 * Here lives the trap that ruins the most birth charts: **there are two time scales and they
 * are not interchangeable**. The planets are computed in Terrestrial Time (TT) and the houses
 * in Universal Time (UT), because the houses depend on how much the Earth has turned and that
 * is measured by the civil clock, not by the ephemeris one. Between the two there are some 70
 * seconds today. Using the same one for both things moves the ascendant almost a minute of arc
 * and nobody detects it looking at the wheel.
 *
 * And there is a third one, the wall clock scale: **UTC is not UT1**. UT1 is the rotation of
 * the Earth, which is what decides where the ascendant is; UTC is an atomic scale into which a
 * second is inserted every so often so that it does not drift from the former by more than 0.9
 * seconds. `utcToJulianDay()`, `ttToUtc()` and `ut1ToUtc()` are the bridge between the three,
 * and they are here and not in a separate class because the leap second table comes in the same
 * file as delta T, is written by the same command and is read with the same `require`: splitting
 * them into two classes would have meant reading `deltat.php` twice to separate two halves of
 * the same IERS measurement.
 *
 * `BirthChart` comes in through `fromClock()`, which subtracts UT1-UTC where it is known and
 * leaves civil time as UT1 where it is not; the horizon still takes civil time as UT1. What each
 * thing costs is written up in `utcToJulianDay()` and in `fromClock()`.
 */
class Time
{
    /** Julian day of J2000.0, that is, 1 January 2000 at 12:00 TT. */
    public const J2000 = 2451545.0;

    /** Days in a Julian century. */
    public const CENTURY = 36525.0;

    /** Days in a Julian millennium, which is the unit VSOP87 works in. */
    public const MILLENNIUM = 365250.0;

    /**
     * How far sidereal time advances in one day of universal time, in degrees. It is the linear
     * term of `meanSiderealUnwrapped`, and it is a little over 360 because in one day the Earth
     * has to turn its own full turn plus the degree it has moved along its orbit.
     *
     * It has a name because two other classes need it and neither of them has a date to ask for
     * it with: `Houses::speeds()` turns a rate per degree of ARMC into a rate per day, and
     * `RiseSet::solvedPass()` turns a difference of sidereal time into a jump in clock time.
     * Written out in each of them it would be the same number in three places.
     *
     * What it does not carry, measured by differencing `apparentSiderealTime` itself over 1600 to
     * 2400: against the MEAN sidereal time the real daily advance departs from this by 1e-7
     * degrees, which is the quadratic and cubic terms of the polynomial, and against the APPARENT
     * one by between -4.0e-5 and +6.2e-5, which is the equation of the equinoxes and is dominated
     * by the nutation term of thirteen and a half days. Two parts in ten million.
     */
    public const ROTATION_PER_DAY = 360.98564736629;

    /**
     * What separates Terrestrial Time from International Atomic Time, in seconds. It is not
     * measured: it is the definition of TT, chosen so that it would join up with the ephemeris
     * scale that existed before atomic clocks.
     */
    public const TT_MINUS_TAI = 32.184;

    /**
     * How many recent nutations are remembered. A chart uses four different instants; eight is
     * more than enough and searching through eight costs less than one sine.
     */
    private const REMEMBERED_NUTATIONS = 8;

    /**
     * The nutation series, read once per process. Written by `astronomy nutation`.
     *
     * @var array{model: string, arguments: array<string, list<float>>, bias: array{0: float, 1: float}, terms: list<list<float>>}|null
     */
    private static ?array $nutationTable = null;

    /**
     * The last nutations computed, the most recent one first: [t, Δψ, Δε].
     *
     * @var list<array{0: float, 1: float, 2: float}>
     */
    private static array $recentNutations = [];

    /**
     * Secular tidal acceleration of the Moon that delta T is referred to before 1955, in
     * arcseconds per century squared. It is the DE431 one, which is also the one Swiss Ephemeris
     * uses by default. What it is doing here and why it is not the one from our own lunar theory
     * is written up in `deltaT()`.
     */
    public const TIDAL_ACCELERATION = -25.80;

    /** The tidal acceleration with which Stephenson, Morrison and Hohenkerk reduced their observations. */
    private const TIDAL_ACCELERATION_SMH2016 = -25.85;

    /** 1 January 1955 at 0h: where the atomic clocks begin and where the table begins. */
    private const DELTA_T_TABLE_START = 2435108.5;

    /** Days before 1955 over which the step between the spline and the first observation is spread. */
    private const DELTA_T_RAMP = 1000.0;

    /**
     * The historical spline and the delta T observation table, read once per process and with
     * both joins already computed. Written by `astronomy delta-t`.
     *
     * @var array{spline: list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}>, table: list<array{0: float, 1: float}>, observedUntil: float, offset: float, leap: float}|null
     */
    private static ?array $deltaTTable = null;

    /**
     * Julian day from an instant, in the scale of whatever clock is passed in.
     *
     * The date is read ALWAYS as proleptic Gregorian, also before October 1582, and that is
     * deliberate even though it contradicts the algorithm as it is published.
     *
     * The reason is where the date comes from. PHP stores dates in proleptic Gregorian: when
     * somebody writes 1500-01-01, `DateTimeImmutable` counts the leap years with the Gregorian
     * rule backwards, not with the Julian one. If the rule were switched here in 1582 to
     * "respect history", the same instant would have two different representations depending on
     * which way it came through, and nine days of offset would come out with nothing warning
     * about it.
     *
     * Whoever brings in a date from a historical source, which is in the Julian calendar, has
     * `civilJulianDay()` with `$gregorian = false`, which does the arithmetic without going
     * through a PHP date object; and `civilDate()` for the way back.
     *
     * @param DateTimeInterface $instant
     * @return float
     */
    public static function julianDay(DateTimeInterface $instant): float
    {
        $utc = DateTimeImmutable::createFromInterface($instant)->setTimezone(new DateTimeZone('UTC'));

        $fraction = ((int) $utc->format('H')
            + (int) $utc->format('i') / 60
            + ((int) $utc->format('s') + (int) $utc->format('u') / 1e6) / 3600) / 24;

        return self::civilJulianDay(
            (int) $utc->format('Y'),
            (int) $utc->format('n'),
            (int) $utc->format('j') + $fraction,
            true
        );
    }

    /**
     * Julian day of a civil date, in whichever calendar is stated.
     *
     * It is the Meeus algorithm (Astronomical Algorithms, chapter 7). The only difference
     * between the two calendars is the B correction: in the Gregorian one it discounts the
     * century years that are not leap years, and in the Julian one there is nothing to discount
     * because they all are. That is why 4 October 1582 Julian plus one day is 15 October 1582
     * Gregorian: it is the same Julian day, 2299160.5, written in two calendars.
     *
     * Here the calendar is NOT switched in 1582: the caller chooses it. A Julian date is Julian
     * because its source is (a chronicle, a parish register from before the reform), not because
     * it is old. And the other way round, a date earlier than 1582 that comes from PHP is
     * proleptic Gregorian and has to be converted as such.
     *
     * The roundings use `floor` and not `intdiv`, which truncates towards zero: it makes no
     * difference for positive years and stops making none for negative ones, where INT(-47.13)
     * has to be -48. Meeus warns about it, and JD 0 (1 January -4712 at noon) is the date that
     * checks it.
     *
     * @param int $year Astronomical: the year 0 exists and 1 BC is the year 0.
     * @param int $month
     * @param float $day With the fraction of the day if needed: 15.5 is the 15th at noon.
     * @param bool $gregorian
     * @return float
     */
    public static function civilJulianDay(int $year, int $month, float $day, bool $gregorian = true): float
    {
        if ($month <= 2) {
            $year--;
            $month += 12;
        }

        $b = 0.0;

        if ($gregorian) {
            $a = floor($year / 100);
            $b = 2 - $a + floor($a / 4);
        }

        return floor(365.25 * ($year + 4716))
            + floor(30.6001 * ($month + 1))
            + $day + $b - 1524.5;
    }

    /**
     * The civil date of a Julian day, in whichever calendar is stated.
     *
     * The way back from `civilJulianDay()`, also from Meeus. The day carries the fraction: 15.5
     * is the 15th at noon. It returns the astronomical year, with the 0 and the negative ones.
     *
     * Meeus decides the calendar from the Julian day itself (Gregorian from 2299161 on) and
     * here, just as on the way out, it is left to be chosen: the Gregorian branch is applied to
     * any date when Gregorian is asked for, which is what makes this a proleptic Gregorian and
     * what allows the way out and the way back to be exact inverses in any year.
     *
     * @param float $jd
     * @param bool $gregorian
     * @return array{0: int, 1: int, 2: float} [year, month, day with fraction]
     */
    public static function civilDate(float $jd, bool $gregorian = true): array
    {
        $jd += 0.5;
        $z = floor($jd);
        $f = $jd - $z;

        $a = $z;

        if ($gregorian) {
            $alpha = floor(($z - 1867216.25) / 36524.25);
            $a = $z + 1 + $alpha - floor($alpha / 4);
        }

        $b = $a + 1524;
        $c = floor(($b - 122.1) / 365.25);
        $d = floor(365.25 * $c);
        $e = floor(($b - $d) / 30.6001);

        $day = $b - $d - floor(30.6001 * $e) + $f;
        $month = $e < 14 ? $e - 1 : $e - 13;
        $year = $month > 2 ? $c - 4716 : $c - 4715;

        return [(int) $year, (int) $month, $day];
    }

    /**
     * Julian centuries since J2000.
     *
     * @param float $jd
     * @return float
     */
    public static function centuries(float $jd): float
    {
        return ($jd - self::J2000) / self::CENTURY;
    }

    /**
     * Julian millennia since J2000. It is the `tau` of VSOP87.
     *
     * @param float $jd
     * @return float
     */
    public static function millennia(float $jd): float
    {
        return ($jd - self::J2000) / self::MILLENNIUM;
    }

    /**
     * Difference between Terrestrial Time and Universal Time, in seconds.
     *
     * It cannot be computed: it measures how much the rotation of the Earth has slowed down, and
     * nobody predicts that, either backwards or forwards. What there is are observations, and
     * this is a table of observations with a historical reconstruction in front of it and an
     * extrapolation behind it. It is exactly what Swiss Ephemeris does since its version 2.06,
     * and it is measured against `swe_deltat` of 2.10.03: from -720 to 1954 it agrees to a
     * ten-thousandth of a second, from 1955 to 2023 to less than a tenth, and from 2024 on we
     * diverge by up to half a second because that version already extrapolates and here there are
     * measurements up to 2026. One second of delta T moves the Moon half an arcsecond.
     *
     * Here stood the Espenak and Meeus polynomials, which are a 2006 fit to the Morrison and
     * Stephenson reconstruction of 2004. In 2026 they gave 75 seconds where reality is at 69, and
     * those six seconds were a systematic shift of every rising, every eclipse and every
     * ascendant that comes out of the engine; in 1605 they were 32 seconds away from Swiss.
     *
     * The three stretches, and where each one comes from (all of it downloaded by `astronomy delta-t`,
     * nothing written by hand):
     *
     * - **Before 1 January 1955**: the cubic spline of Stephenson, Morrison and Hohenkerk (2016),
     *   their reconstruction from Babylonian, Chinese and Arab eclipses and from the telescopic
     *   occultations since 1600. It runs from -720 to 2016 in 54 stretches (Table S15 of the
     *   paper, which is open access). Before -720 it carries on with their long-term parabola,
     *   -320 + 32.5·u² with u in centuries since 1825, shifted so as to join the spline at -720;
     *   the shift is computed on load, it is not copied.
     *
     *   Careful, because this was the wrong starting point of the assignment: Swiss does NOT use
     *   the annual Astronomical Almanac table from 1620 on. It used it up to 2.05; since 2.06 the
     *   spline rules until 1955 and the table only afterwards. It was checked by measuring, which
     *   is what counts: `swe_deltat` in 1620 gives 66.6 seconds, and the Almanac table says 124.
     *
     * - **From 1955 to 1973**: the six-monthly USNO values, which are the Astronomical Almanac
     *   ones. From 1955 on there are atomic clocks and delta T stops depending on any theory.
     *
     * - **From February 1973 on**: one value per month from the IERS series, which is who
     *   measures the rotation of the Earth: 32.184 + (TAI-UTC) - (UT1-UTC). The last months are
     *   the IERS's own prediction for the following year; `observado_hasta` says where the
     *   measured part ends. Between points it interpolates linearly. Swiss carries one value per
     *   year and interpolates with Bessel's formula; with twelve points per year the interpolation
     *   does not matter, and the measured difference between the two paths is hundredths.
     *
     * - **After the last value**: the Swiss extrapolation for this model, anchored to the last
     *   point of OUR table: a cubic that Koch fitted to the long-term prediction of Stephenson,
     *   Morrison and Hohenkerk (64 + 0.1737·t + 0.0008·t² + 0.00000403·t³, with t in years since
     *   2000) up to 2500 and the parabola 42.5 + 32.5·T² afterwards, plus a linear term that
     *   spreads the step between the table and the formula over a hundred years. And here there
     *   is a trap: the Swiss documentation says that after the table it uses the Stephenson
     *   formula of 1997, -20 + 31·u², and its code does this other thing. They are 130 seconds
     *   apart in 2100. What is here is what the code does, verified against `swe_deltat` up to the
     *   year 3000.
     *
     * **The 1955 step.** The spline at 1955.0 gives 30.41 seconds and the first atomic
     * observation 31.07: they are two different measurements of the same thing and they do not
     * agree. Swiss spreads that step over the preceding thousand days with a linear ramp, and
     * here it is done the same way; the size of the step is computed when the table is loaded, so
     * that if one day the 1955 source changes the ramp goes on closing by itself.
     *
     * **The tidal acceleration.** Before 1955 delta T comes out of comparing observations with a
     * lunar theory, so the value depends on which secular acceleration of the Moon that theory
     * carries: changing it is adding -0.000091·(n' - n'0)·(year - 1955)² seconds. The spline is
     * referred to -25.85 arcseconds per century squared and here it is taken to -25.80, the DE431
     * one, which is what Swiss does by default and what everything is compared against: that is
     * half a second in 1620 and 17 in the year 0. Our Moon is ELP2000-82B, fitted to DE200, which
     * carries -23.8946, and taking it there would be 20 seconds in 1620 and 680 in the year 0. It
     * is not done: delta T is a property of the Earth and its best estimate is the one from the
     * best lunar theory, not ours; correcting it by ours would cover up the tidal error of our
     * Moon by moving the Sun, the planets and the houses to compensate.
     *
     * @param float $jd Julian day, in either of the two scales: the difference on delta T is microseconds.
     * @return float
     */
    public static function deltaT(float $jd): float
    {
        $table = self::deltaTTable();

        if ($jd < self::DELTA_T_TABLE_START) {
            $dt = self::historicDeltaT($jd, $table);

            if ($jd >= self::DELTA_T_TABLE_START - self::DELTA_T_RAMP) {
                $dt += (1 - (self::DELTA_T_TABLE_START - $jd) / self::DELTA_T_RAMP) * $table['leap'];
            }

            return $dt;
        }

        $points = $table['table'];
        [$jdEnd, $dtEnd] = $points[count($points) - 1];

        if ($jd <= $jdEnd) {
            return self::interpolateDeltaT($points, $jd);
        }

        return self::extrapolateDeltaT($jd, $jdEnd, $dtEnd);
    }

    /**
     * Up to which Julian day there is measured delta T. What comes after that in the table is the
     * IERS prediction, and what comes after the table, the formula.
     *
     * @return float
     */
    public static function deltaTObservedUntil(): float
    {
        return self::deltaTTable()['observedUntil'];
    }

    /**
     * The Stephenson, Morrison and Hohenkerk spline, or its parabola before -720, already
     * adjusted to the tidal acceleration. Without the 1955 ramp.
     *
     * @param float $jd
     * @param array{spline: list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}>, offset: float} $table
     * @return float
     */
    private static function historicDeltaT(float $jd, array $table): float
    {
        $year = self::gregorianYear($jd);

        $dt = $jd >= $table['spline'][0][0]
            ? self::deltaTSpline($jd, $table['spline'])
            : self::longTermParabola($year) + $table['offset'];

        return $dt - 0.000091 * (self::TIDAL_ACCELERATION - self::TIDAL_ACCELERATION_SMH2016) * ($year - 1955) ** 2;
    }

    /**
     * One stretch of the spline: delta T = a0 + a1·t + a2·t² + a3·t³, with t the fraction of the
     * stretch measured in Julian days between 1 January of its two years.
     *
     * @param float $jd
     * @param list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}> $segments
     * @return float
     */
    private static function deltaTSpline(float $jd, array $segments): float
    {
        $segment = $segments[count($segments) - 1];

        foreach ($segments as $candidate) {
            if ($jd < $candidate[1]) {
                $segment = $candidate;
                break;
            }
        }

        [$start, $end, $a0, $a1, $a2, $a3] = $segment;
        $t = ($jd - $start) / ($end - $start);

        return $a0 + $t * ($a1 + $t * ($a2 + $t * $a3));
    }

    /**
     * The long-term parabola of Stephenson, Morrison and Hohenkerk: the mean deceleration of the
     * rotation of the Earth, without the decade-scale fluctuations, which cannot be known.
     *
     * @param float $year Gregorian year with decimals.
     * @return float
     */
    private static function longTermParabola(float $year): float
    {
        return -320 + 32.5 * (($year - 1825) / 100) ** 2;
    }

    /**
     * Linear between the two points of the table that bracket the instant, found by bisection:
     * there are seven hundred points and this gets called thousands of times in a transit search.
     *
     * @param list<array{0: float, 1: float}> $points
     * @param float $jd Within the range of the table.
     * @return float
     */
    private static function interpolateDeltaT(array $points, float $jd): float
    {
        $low = 0;
        $high = count($points) - 1;

        while ($high - $low > 1) {
            $mid = intdiv($low + $high, 2);

            if ($points[$mid][0] <= $jd) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        [$jd0, $dt0] = $points[$low];
        [$jd1, $dt1] = $points[$high];

        return $dt0 + ($jd - $jd0) / ($jd1 - $jd0) * ($dt1 - $dt0);
    }

    /**
     * After the table: the long-term cubic up to 2500 and the parabola afterwards, with the step
     * relative to the last point of the table spread linearly over a hundred years. It is the
     * Swiss extrapolation for this model, anchored to our last value.
     *
     * @param float $jd
     * @param float $jdEnd Last Julian day of the table.
     * @param float $dtEnd Its delta T.
     * @return float
     */
    private static function extrapolateDeltaT(float $jd, float $jdEnd, float $dtEnd): float
    {
        $year = self::tableYear($jd);
        $endYear = self::tableYear($jdEnd);

        $dt = $year < 2500
            ? self::longTermCubic($year)
            : 42.5 + 32.5 * (($year - 2000) / 100) ** 2;

        if ($year <= $endYear + 100) {
            $dt += (self::longTermCubic($endYear) - $dtEnd) * ($year - ($endYear + 100)) / 100;
        }

        return $dt;
    }

    /**
     * @param float $year
     * @return float
     */
    private static function longTermCubic(float $year): float
    {
        $t = $year - 2000;

        return 64 + $t * (521 / 3000 + $t * (1 / 1250 + $t * 121 / 30000000));
    }

    /**
     * Loads the table and computes the two joins: the shift of the parabola so that it follows
     * the spline at -720, and the step between the spline and the table in 1955.
     *
     * The years of the stretches are turned into the Julian day of 1 January Gregorian here,
     * once, so that evaluating the spline is a subtraction.
     *
     * @return array{spline: list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}>, table: list<array{0: float, 1: float}>, leapSeconds: list<array{0: float, 1: int}>, observedUntil: float, offset: float, leap: float}
     */
    private static function deltaTTable(): array
    {
        if (self::$deltaTTable !== null) {
            return self::$deltaTTable;
        }

        $raw = require DataFolder::path('deltat.php');

        // An old file, from before the command wrote the leap seconds, would not give an error:
        // it would leave `taiMinusUtc()` returning null always, that is, every date treated as
        // earlier than 1972, which is 37 seconds too many with nothing warning about it.
        if (! isset($raw['leapSeconds']) || $raw['leapSeconds'] === []) {
            throw new RuntimeException('deltat.php does not carry the leap seconds table: run `astronomy delta-t` again.');
        }

        $spline = [];

        foreach ($raw['spline'] as [$start, $end, $a0, $a1, $a2, $a3]) {
            $spline[] = [
                self::civilJulianDay((int) $start, 1, 1.0),
                self::civilJulianDay((int) $end, 1, 1.0),
                $a0, $a1, $a2, $a3,
            ];
        }

        $table = [
            'spline' => $spline,
            'table' => $raw['table'],
            'leapSeconds' => $raw['leapSeconds'],
            'observedUntil' => $raw['observedUntil'],
            'offset' => 0.0,
            'leap' => 0.0,
        ];

        $table['offset'] = self::deltaTSpline($spline[0][0], $spline)
            - self::longTermParabola(self::gregorianYear($spline[0][0]));

        $table['leap'] = $raw['table'][0][1] - self::historicDeltaT(self::DELTA_T_TABLE_START, $table);

        return self::$deltaTTable = $table;
    }

    /**
     * The general precession in longitude accumulated since J2000, in arcseconds.
     *
     * It is how far the Aries point has moved back along the ecliptic, and with it one goes from
     * the inertial origin in which the series are written (ELP, and the rotation of `Precession`)
     * to the equinox of date. The three terms are the IAU 1976 ones (Lieske), which are the ones
     * Meeus and Laskar carry.
     *
     * **The quadratic term is not optional.** With the linear constant alone, which is what the
     * original ELP Fortran program carries, the error grows with the square of time: 1.1
     * arcseconds in 1900, 13.6 in 1650 and in 2350. Two independent measurements caught it on the
     * same day: Pluto and Chiron against the JPL were off by 14 arcseconds symmetrically on both
     * sides of 2000, and the fixed stars against Swiss were dragging 1.5 arcseconds in 1900, the
     * same in all of them. The Sun did not have it, because VSOP87 already comes in the ecliptic
     * of date, and that is what pointed to where the fault was: at the rotation and not at the
     * tables.
     *
     * @param float $t Julian centuries since J2000.
     * @return float
     */
    public static function generalPrecession(float $t): float
    {
        return 5029.0966 * $t + 1.11113 * $t * $t - 0.000006 * $t * $t * $t;
    }

    /**
     * The same instant in Universal Time. The way back from `tt()`.
     *
     * Delta T is evaluated at the instant itself and not at its UT, which is seventy seconds
     * earlier: the difference on delta T is microseconds.
     *
     * @param float $jdTT
     * @return float
     */
    public static function ut(float $jdTT): float
    {
        return $jdTT - self::deltaT($jdTT) / 86400;
    }

    /**
     * The same instant in Terrestrial Time, which is the scale of the ephemerides.
     *
     * @param float $jdUt
     * @return float
     */
    public static function tt(float $jdUt): float
    {
        return $jdUt + self::deltaT($jdUt) / 86400;
    }

    /**
     * TAI-UTC on the UTC day in which that Julian day falls, in whole seconds, or null before
     * 1972.
     *
     * It is the leap second counter: how many have been inserted since in 1972 it was decided
     * that UTC would always run at a whole second from TAI and that the mismatch with the
     * rotation of the Earth would be corrected in jumps. It began at 10 and stands at 37 since
     * 1 January 2017, which is the last jump so far.
     *
     * **It is looked up by the DAY, not by the instant**, and that is the nuance that decides the
     * odd case. In the minute in which a jump is inserted there are 61 seconds, so 23:59:60 on a
     * 31 December, counted crudely as seconds from 0h, gives the Julian day of 0h on 1 January,
     * which is exactly the date on which the counter goes up. Looking it up by that Julian day
     * would give the new value and the instant would be left one second off, with no error given.
     *
     * Before 1972 it returns null, not zero. Zero would be a false statement: TAI and UTC only
     * agreed before 1958, and from 1961 to 1972 there was a UTC of elastic seconds, with
     * fractional jumps and the second itself stretched, which is not modelled here. Whoever asks
     * about an earlier date gets null and decides what to do, which is the same thing `Houses`
     * does when the vertex does not exist.
     *
     * @param float $jdUtc Julian day in UTC. The day it falls on is taken, not the time.
     * @return int|null
     */
    public static function taiMinusUtc(float $jdUtc): ?int
    {
        $day = floor($jdUtc - 0.5) + 0.5;
        $tai = null;

        foreach (self::deltaTTable()['leapSeconds'] as [$jd, $seconds]) {
            if ($day >= $jd) {
                $tai = $seconds;
            }
        }

        return $tai;
    }

    /**
     * A UTC clock date and time, in Terrestrial Time and in Universal Time: it returns
     * [jdTT, jdUT1].
     *
     * **Wall clock time is UTC and what moves the sky is UT1, and they are not the same thing.**
     * UTC runs at a whole second from the atomic clocks and UT1 is the real rotation of the
     * Earth, which is neither uniform nor predictable; what keeps them together below 0.9 seconds
     * is inserting a leap second every so often. Almost everybody takes civil time as if it
     * already were UT1, and the horizon of this engine still does; `BirthChart` no longer does,
     * since it comes in through `fromClock()`. What that costs is measured, and it is not the
     * easy sum of multiplying 0.9 seconds by the 15.041 arcseconds that sidereal time moves in
     * one: the ascendant runs more or less than it depending on the latitude and the time. Over
     * 3,312 combinations with the offset at its maximum, the median is 12.3 arcseconds and the
     * worst case 478, that is, eight minutes, at latitude 66. In real charts, with the UT1-UTC
     * there was on that day, it is 5 arcseconds in the 1985 Ourense one and 12 in the 1981 Madrid
     * one. This is what is needed so as not to have to assume it.
     *
     * It is `swe_utc_to_jd`. The arithmetic, which is the whole function:
     *
     * - TT = UTC + (TAI-UTC) + 32.184 seconds, and both are known constants: the day's leap
     *   second counter and what separates TT from TAI by definition. Nothing measured comes in
     *   here, so this path is exact.
     * - UT1 = TT - delta T, and there the measurement does come in.
     *
     * **Before 1972 civil time is taken as UT1**, which is what Swiss does and the only honest
     * thing: there were no leap seconds to count, and from 1961 to 1972 the UTC of elastic
     * seconds is not modelled here. Measured at the join itself: 23:59:59 on 31 December 1971 and
     * 00:00:00 on 1 January 1972 come out 0.954 seconds from each other instead of 1, and those
     * 46 milliseconds are the UT1-UTC of that day. It is not a fault of the seam: it is the
     * measure of what is being taken for granted before 1972.
     *
     * **The 60th second exists and is accepted**, but only where it exists: in the last minute of
     * a day that carries a jump. Anywhere else it blows up instead of returning a good-looking
     * instant, just as Swiss does.
     *
     * **Past the last known jump it carries on with that one, and that is a stated assumption.**
     * Nobody knows whether there will be more leap seconds, so here it is assumed there will not:
     * since 2017 the counter is 37 and it stays at 37 going forward. Swiss does something else,
     * and it is measured: it keeps the last one while the UT1-UTC that implies does not go over
     * one second and, when it does, it stops applying the table and takes civil time as UT1. With
     * its own delta T that happens to it in 2034, and in 2100 it comes out 24.16 seconds from
     * what is given here. It is not copied, for two reasons. The first is that the date on which
     * its UTC changes meaning depends on delta T, that is, it moves by itself every time the IERS
     * table is refreshed. The second is that in 2022 the IERS itself resolved to stop inserting
     * leap seconds by 2035 and to let UT1-UTC grow, so assuming there will be no more is today
     * the published assumption. Below 2034 the two paths agree exactly.
     *
     * **`swe_utc_time_zone` is `utcToLocal()` and `localToUtc()`**, and it stood here for a
     * while saying it was not worth having, on a reason aimed at the wrong thing: it was read as
     * a worse time zone resolver than `DateTimeZone`, and it does not resolve anything. It
     * shifts a clock LABEL by an offset somebody else already worked out, and it carries the
     * 60th second across the shift, which is the one thing PHP cannot do because
     * `DateTimeImmutable` has no way to hold 23:59:60. Resolving the zone is still
     * `DateTimeZone`'s job and that has not changed.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param float $second From 0 to 60.999...: the 60 only in the minute of a jump.
     * @param bool $gregorian
     * @return array{0: float, 1: float} [Julian day in TT, Julian day in UT1]
     *
     * @throws InvalidArgumentException If the time does not exist.
     */
    public static function utcToJulianDay(int $year, int $month, int $day, int $hour, int $minute, float $second, bool $gregorian = true): array
    {
        self::checkClockTime($hour, $minute, $second);

        $jd0 = self::civilJulianDay($year, $month, $day, $gregorian);
        $tai = self::taiMinusUtc($jd0);

        if ($second >= 60 && ! self::carriesLeapSecond($year, $month, $day, $hour, $minute, $gregorian)) {
            throw self::noLeapSecond($year, $month, $day, $hour, $minute, $second);
        }

        $jdUtc = $jd0 + ($hour * 3600 + $minute * 60 + $second) / 86400;

        if ($tai === null) {
            return [self::tt($jdUtc), $jdUtc];
        }

        $jdTT = $jdUtc + (self::TT_MINUS_TAI + $tai) / 86400;

        return [$jdTT, self::ut($jdTT)];
    }

    /**
     * The way back: from Terrestrial Time to the UTC clock date and time.
     *
     * It is `swe_jdet_to_utc`. It returns [year, month, day, hour, minute, second] with the
     * astronomical year, just like `civilDate()`, and **it can return the 60th second**: if the
     * instant falls inside a leap second, the clock time that corresponds to it is 23:59:60 and
     * there is no other way of writing it.
     *
     * Choosing the leap second counter is the only thing with a catch, because the boundary is in
     * UTC and what comes in here is an instant in TT. It is compared against the TT instant of
     * each jump, which is its 0h UTC with the NEW counter already applied: below that the old
     * counter rules, and with the old one the instant falls on the 0h of the following day or up
     * to one second later, which is exactly the leap second of the day that is ending.
     *
     * The second comes out with the floating point noise of a Julian day: a `double` near
     * 2,460,000 has a resolution of 4e-5 seconds, so here 59.00001 is returned where 59 went in.
     * Measured on the round trip: 1.2e-5 seconds worst case, the same as Swiss gives. Before 1972
     * one has to add what the trip through delta T takes, which reaches 1.006 milliseconds
     * because the spline comes published with the coefficients rounded to thousandths and jumps
     * that at every knot; it is measured in `UtcTest`. And right up against a midnight that noise
     * decides which side of the date it falls on: 1 January 1726 at 0h comes back as 31 December
     * 1725 at 23:59:59.99996, which is the same instant written from the other side of the
     * boundary.
     *
     * It is not rounded on purpose, because rounding to thousandths would turn a legitimate
     * 23:59:59.9999 into a 23:59:60 that does not exist on that day, which is exactly the error
     * this comes to prevent. Whoever wants a pretty time rounds it, knowing what they are
     * rounding.
     *
     * @param float $jdTT
     * @param bool $gregorian
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: float}
     */
    public static function ttToUtc(float $jdTT, bool $gregorian = true): array
    {
        $jumps = self::deltaTTable()['leapSeconds'];
        $chosen = self::leapInForce($jdTT);

        if ($chosen === null) {
            return self::decomposeUtc(self::ut($jdTT), $gregorian);
        }

        $offset = (self::TT_MINUS_TAI + $jumps[$chosen][1]) / 86400;
        $next = $jumps[$chosen + 1][0] ?? null;

        // With the counter from before the jump, the instant goes past the 0h of the jump's date
        // by less than a second. That is not the new day: it is the 60th second of the one that
        // is ending.
        //
        // The comparison goes in TT and not in UTC on purpose. Subtracting the offset first and
        // comparing afterwards, the limit comes out of an `(a + b) - b` that loses an ulp of a,
        // and a is around two and a half million: that is 4e-5 seconds which fall on the wrong
        // side half of the time and take a whole second with them. Written this way, both sides
        // of the comparison are computed with the same expression the outward path uses and the
        // tie is always broken the same way. Swiss compares in UTC and that is why it loses the
        // 60th second in four of its twenty-seven jumps: the ones of 1974, 1983, 1994 and 2015
        // come back to it as the 00:00:00 of the following day, which is already another instant.
        if ($next !== null && $jdTT >= $next + $offset) {
            [$year, $month, $day] = self::civilDate($next - 1, $gregorian);

            return [$year, $month, (int) $day, 23, 59, 60 + ($jdTT - ($next + $offset)) * 86400];
        }

        return self::decomposeUtc($jdTT - $offset, $gregorian);
    }

    /**
     * The same thing from Universal Time, which is the scale the houses work in.
     *
     * It is `swe_jdut1_to_utc`, and as in Swiss it is `ttToUtc()` with delta T added in front:
     * UT1 has neither leap seconds nor dates, it is a rotation angle, so to know what clock time
     * corresponds to it one has to go through TT.
     *
     * @param float $jdUT1
     * @param bool $gregorian
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: float}
     */
    public static function ut1ToUtc(float $jdUT1, bool $gregorian = true): array
    {
        return self::ttToUtc(self::tt($jdUT1), $gregorian);
    }

    /**
     * A UTC clock label written in a zone that is `$offsetMinutes` east of Greenwich, the same
     * instant with the numbers moved: it returns ['year', 'month', 'day', 'hour', 'minute',
     * 'second'], keyed like `checkedJulianDay()`.
     *
     * It is half of `swe_utc_time_zone`, and **it is not a time zone resolver**. Which offset was
     * in force in Ourense on that 10 June, with its summer time and its historical clock changes,
     * is `DateTimeZone`'s job and stays `DateTimeZone`'s job; this takes the offset already worked
     * out and moves the label. What it buys over doing the same thing with a `DateTimeImmutable`
     * is the only thing PHP cannot do: **it carries the 60th second across the shift**, because
     * `DateTimeImmutable` has no way of holding 23:59:60. Measured: it reads
     * "2016-12-31 23:59:60" as the 00:00:00 of 1 January 2017, which is the same answer it gives
     * for 23:59:59 plus one second, so the leap second and the instant after it become the same
     * number and one of the two is lost.
     *
     * **The 61-second minute moves as a block**, which is why the 60th second is still the 60th
     * on the other side. That holds because the offset is a whole number of minutes, and that is
     * not an assumption: measured over the IANA database, the 51 distinct offsets any zone has
     * used since the first leap second of 30 June 1972 are all whole minutes, and 37 of them are
     * in force today. Before leap seconds existed there were 271 distinct sub-minute offsets in
     * use between 1900 and 1972, every one of them a local mean time or a local mean time with a
     * clock change on top; with one of those the 61-second minute would be cut in two and the
     * 60th second would have no label on the far side, so it would not be a question with an
     * answer.
     *
     * **The offset goes in minutes and not in hours**, which is what Swiss takes, so that the
     * whole computation is integer seconds of the day and nothing rounds. Hours as a float would
     * be exact for the offsets that are a multiple of a quarter of an hour, which is all of
     * today's; it is Pacific/Kiritimati at -10:40 until 1979 that is not one, and -38400/3600
     * only comes back to -38400 by luck of the rounding. Working in minutes there is no luck to
     * rely on.
     *
     * **And going in the other direction is `localToUtc()`, not a negative sign.** Swiss
     * documents one function for the two ways round ("for conversion from local time to UTC, use
     * +(offset)"), which is a parameter whose SIGN changes what the function does, and this engine
     * already has that written down as a place to get it wrong without noticing: it is why
     * `Horizon::equatorialWithSpeed()` says the direction in its name instead of in the sign of
     * the obliquity. Here the offset always means the same thing, minutes east of Greenwich, and
     * the name says which way it is being applied.
     *
     * Measured against `swe_utc_time_zone` of pyswisseph 2.10.03 over 50,000 random instants
     * between 1600 and 2400 across the 51 offsets, with a fraction of a second on each: the same
     * label in all 50,000, and the second agreeing to 2.5e-11 seconds, which is Swiss's noise and
     * not ours.
     *
     * **And on whole seconds it is not noise, it is a whole minute.** Over 200,000 conversions
     * with the second an exact number, 1,556 of them, 0.78%, come back from Swiss with the minute
     * one lower and the second at 59.99999999999. Every one of them had the second at exactly 0,
     * so the rate of the mixed sample says nothing; swept exhaustively instead, over the 1,440
     * minutes of a day and the 51 offsets, **33,756 of 73,440 on-the-minute labels, 45.96%, come
     * back one minute lower**, and it is the same 45.96% on 1600, 1700, 1800, 1900, 2000, 2026,
     * 2100, 2250 and 2400, so it does not depend on the size of the Julian day either. The
     * shortfall is at most 1.7e-11 seconds and it moves the printed label by sixty of them. It is
     * the same ulp of a Julian day that `ttToUtc()` already has written down for the leaps, and
     * it bites where it matters most, because a wall clock time is almost always typed on the
     * minute.
     *
     * This does not have it because it never builds a Julian day out of a time of day: the
     * seconds of the day are integers and the fraction of a second is carried alongside, so it
     * comes back bit for bit as it went in, measured on all 250,000. The labels themselves are
     * checked against a path that touches none of this arithmetic, PHP's own calendar shifting
     * the same instant by the same offset in seconds: 250,000 of 250,000 identical.
     *
     * The DATE is not checked, just as `utcToJulianDay()` does not check it: a 31 February goes
     * in and normalises to 3 March through the round trip, which is the correction
     * `checkedJulianDay()` reads off. Whoever brings a date in from outside checks it there
     * first.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param float $second From 0 to 60.999...: the 60 only in the minute of a jump.
     * @param int $offsetMinutes Minutes east of Greenwich: +330 is India, -210 Newfoundland.
     * @param bool $gregorian
     * @return array{year: int, month: int, day: int, hour: int, minute: int, second: float}
     *
     * @throws InvalidArgumentException If the UTC time does not exist.
     */
    public static function utcToLocal(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        float $second,
        int $offsetMinutes,
        bool $gregorian = true,
    ): array {
        self::checkClockTime($hour, $minute, $second);

        if ($second >= 60 && ! self::carriesLeapSecond($year, $month, $day, $hour, $minute, $gregorian)) {
            throw self::noLeapSecond($year, $month, $day, $hour, $minute, $second);
        }

        return self::shiftedClock($year, $month, $day, $hour, $minute, $second, $offsetMinutes, $gregorian);
    }

    /**
     * The way back: a clock label from a zone `$offsetMinutes` east of Greenwich, written in UTC.
     * Same shape and same rules as `utcToLocal()`, and the offset still means minutes east.
     *
     * **The leap second is checked on the UTC side, which is the only side it exists on.** A leap
     * second belongs to a UTC day, so which label may carry a 60 depends on where the offset puts
     * it: 00:59:60 on 1 January 2017 in Madrid is the 23:59:60 of 31 December 2016 UTC and is
     * real, while 23:59:60 on that same 31 December in Madrid would be 22:59:60 UTC, which is a
     * minute like any other and is rejected. Swiss checks neither: measured, `swe_utc_time_zone`
     * takes 23:59:60 on 30 June 2020, a day with no jump, and hands back a 60th second with a
     * straight face; and a 12:00:60 in the middle of a day comes back either as
     * 12:00:59.999999999999 or, in the year 2400, as a 12:00:60 that does not exist, depending on
     * which side of the rounding the day fraction falls.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param float $second From 0 to 60.999...: the 60 only where it lands on a jump in UTC.
     * @param int $offsetMinutes Minutes east of Greenwich: +330 is India, -210 Newfoundland.
     * @param bool $gregorian
     * @return array{year: int, month: int, day: int, hour: int, minute: int, second: float}
     *
     * @throws InvalidArgumentException If the time does not exist, there or in UTC.
     */
    public static function localToUtc(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        float $second,
        int $offsetMinutes,
        bool $gregorian = true,
    ): array {
        self::checkClockTime($hour, $minute, $second);

        $utc = self::shiftedClock($year, $month, $day, $hour, $minute, $second, -$offsetMinutes, $gregorian);

        if ($second >= 60 && ! self::carriesLeapSecond(
            $utc['year'], $utc['month'], $utc['day'], $utc['hour'], $utc['minute'], $gregorian
        )) {
            throw new InvalidArgumentException(sprintf(
                '%04d-%02d-%02d %02d:%02d:%06.3f at %s is %04d-%02d-%02d %02d:%02d:%06.3f UTC, '
                .'and no leap second was inserted into that minute.',
                $year, $month, $day, $hour, $minute, $second,
                self::writtenOffset($offsetMinutes),
                $utc['year'], $utc['month'], $utc['day'], $utc['hour'], $utc['minute'], $utc['second']
            ));
        }

        return $utc;
    }

    /**
     * Moving the label, which is the whole of the two methods above.
     *
     * The 61st second is parked before the arithmetic and put back afterwards, so what is added
     * up is a clock time of at most 23:59:59 and a whole number of minutes. The seconds of the
     * day are integers, the day rolls with an integer division, and the fraction of a second
     * never takes part in any sum: it is set aside and added back at the end, which is why it
     * comes back exactly as it went in instead of as a Julian day's worth of noise.
     *
     * `intdiv` truncates towards zero, so a negative offset that rolls the date backwards needs
     * the remainder brought back into [0, 86400) by hand. That is the case of a 00:30 in Madrid
     * on a 1 January, which is the 23:30 of the previous 31 December in UTC.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param float $second
     * @param int $offsetMinutes
     * @param bool $gregorian
     * @return array{year: int, month: int, day: int, hour: int, minute: int, second: float}
     */
    private static function shiftedClock(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        float $second,
        int $offsetMinutes,
        bool $gregorian,
    ): array {
        $leap = $second >= 60.0;
        $parked = $leap ? $second - 60.0 : $second;

        $wholeSecond = (int) floor($parked);
        $fraction = $parked - $wholeSecond;

        $secondsOfDay = $hour * 3600 + $minute * 60 + $wholeSecond + $offsetMinutes * 60;
        $days = intdiv($secondsOfDay, 86400);
        $rest = $secondsOfDay % 86400;

        if ($rest < 0) {
            $rest += 86400;
            $days--;
        }

        [$shiftedYear, $shiftedMonth, $shiftedDay] = self::civilDate(
            self::civilJulianDay($year, $month, $day, $gregorian) + $days,
            $gregorian
        );

        return [
            'year' => $shiftedYear,
            'month' => $shiftedMonth,
            'day' => (int) floor($shiftedDay),
            'hour' => intdiv($rest, 3600),
            'minute' => intdiv($rest % 3600, 60),
            'second' => $rest % 60 + $fraction + ($leap ? 60.0 : 0.0),
        ];
    }

    /**
     * Whether that UTC minute is the 61-second one, that is, the last minute of a day on which
     * the leap second counter goes up. It is the rule `utcToJulianDay()` has always applied, in
     * one place now that three methods ask it.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param bool $gregorian
     * @return bool
     */
    private static function carriesLeapSecond(int $year, int $month, int $day, int $hour, int $minute, bool $gregorian): bool
    {
        if ($hour !== 23 || $minute !== 59) {
            return false;
        }

        $jd0 = self::civilJulianDay($year, $month, $day, $gregorian);
        $tai = self::taiMinusUtc($jd0);

        return $tai !== null && self::taiMinusUtc($jd0 + 1) === $tai + 1;
    }

    /**
     * The complaint about a 61st second where none was inserted.
     *
     * **It names the MINUTE and not the day, because the day is not what is wrong.** The message
     * used to say "2016-12-31 carries no leap second", and that sentence is false on exactly the
     * day it is most likely to be read on: 31 December 2016 does carry one, at 23:59, and what
     * does not exist is a 12:30:60 in the middle of it. Two different mistakes were coming out
     * with the same wording and one of the two wordings was a lie.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param int $hour
     * @param int $minute
     * @param float $second
     * @return InvalidArgumentException
     */
    private static function noLeapSecond(int $year, int $month, int $day, int $hour, int $minute, float $second): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            '%04d-%02d-%02d %02d:%02d:%06.3f does not exist: no leap second was inserted into that minute.',
            $year, $month, $day, $hour, $minute, $second
        ));
    }

    /**
     * The range of a clock time, which is the same in the three doors that take one.
     *
     * @param int $hour
     * @param int $minute
     * @param float $second
     * @return void
     *
     * @throws InvalidArgumentException
     */
    private static function checkClockTime(int $hour, int $minute, float $second): void
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second >= 61) {
            throw new InvalidArgumentException(sprintf('The time %02d:%02d:%06.3f does not exist.', $hour, $minute, $second));
        }
    }

    /**
     * An offset in minutes written the way a clock offset is written, for the error message.
     *
     * The sign is taken off the minutes before splitting them, because -210 divided by 60 is -3
     * and its remainder -30: printing the two with their own signs would give "-03:-30".
     *
     * @param int $offsetMinutes
     * @return string
     */
    private static function writtenOffset(int $offsetMinutes): string
    {
        return sprintf(
            '%s%02d:%02d',
            $offsetMinutes < 0 ? '-' : '+',
            intdiv(abs($offsetMinutes), 60),
            abs($offsetMinutes) % 60
        );
    }

    /**
     * A clock instant, with its time zone attached, in the two scales of the engine:
     * [jdTT, jdUT1].
     *
     * It is the door through which the birth time enters the chart. What separates it from
     * `julianDay()` is that the latter reads the numbers off the clock and leaves them as they
     * are, and whoever used it took them for UT1; this one knows that a clock reads UTC. Between
     * the two lies UT1-UTC, which does not go over 0.9 seconds of rotation of the Earth and on
     * the ascendant is 12 arcseconds of median and up to eight minutes near the polar circle,
     * measured in `UtcTest`.
     *
     * **It is only corrected where UT1-UTC is known**: from 1 January 1972 up to the last value
     * of the IERS table, with the following year's prediction inside it. Outside, civil time is
     * taken as UT1, just as before, and for two reasons that are not the same one:
     *
     * - **Before 1972** there were no leap seconds and the UTC of elastic seconds is not
     *   modelled. It is the same thing `utcToJulianDay()` already does.
     * - **After the table** nobody knows what a clock will read. The IERS resolved to stop
     *   inserting jumps by 2035, and with the extrapolated delta T UT1-UTC would come out at 24
     *   seconds in 2100: six minutes of arc of ascendant pulled out of an extrapolation. Here
     *   `utcToJulianDay()` carries on with the last counter and it is right that it should,
     *   because it answers what TT is for a given UTC time, which is arithmetic; this one answers
     *   how much the Earth had turned, and outside the table nobody knows that.
     *
     * Both boundaries leave a step of UT1-UTC, at most 0.9 seconds. It is the measure of what is
     * being taken for granted on each side, not a fault of the seam.
     *
     * PHP does not represent the 60th second, so a clock instant never falls inside a jump.
     *
     * @param DateTimeInterface $instant
     * @return array{0: float, 1: float} [Julian day in TT, Julian day in UT1]
     */
    public static function fromClock(DateTimeInterface $instant): array
    {
        $jdUtc = self::julianDay($instant);
        $tai = self::clockKnown($jdUtc) ? self::taiMinusUtc($jdUtc) : null;

        if ($tai === null) {
            return [self::tt($jdUtc), $jdUtc];
        }

        $jdTT = $jdUtc + (self::TT_MINUS_TAI + $tai) / 86400;

        return [$jdTT, self::ut($jdTT)];
    }

    /**
     * The way back: from Terrestrial Time to clock time, in UTC and rounded to the second, which
     * is how the sheet shows it.
     *
     * **It has to use the same criterion as `fromClock()`**, and not out of symmetry: the solar
     * return is searched for in TT, turned into clock time with this and raised again as a chart
     * with that. With a different criterion on each side, the Sun of the return would come back
     * shifted by what the two differ, with no error given.
     *
     * An instant that falls inside a leap second comes out as the 00:00:00 of the following day,
     * because PHP has no 23:59:60. Whoever needs that second has `ttToUtc()`.
     *
     * @param float $jdTT
     * @return DateTimeImmutable In UTC.
     */
    public static function toClock(float $jdTT): DateTimeImmutable
    {
        $jdUt = self::ut($jdTT);
        $jump = self::clockKnown($jdUt) ? self::leapInForce($jdTT) : null;

        $jdClock = $jump === null
            ? $jdUt
            : $jdTT - (self::TT_MINUS_TAI + self::deltaTTable()['leapSeconds'][$jump][1]) / 86400;

        return (new DateTimeImmutable('@'.(int) round(($jdClock - 2440587.5) * 86400.0)))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Whether UT1-UTC is known at that instant: up to the last value of the IERS table, with its
     * prediction. At the lower end there is no need to look, because there the leap second
     * counter rules, and before 1972 that is null.
     *
     * @param float $jd
     * @return bool
     */
    private static function clockKnown(float $jd): bool
    {
        $points = self::deltaTTable()['table'];

        return $jd <= $points[count($points) - 1][0];
    }

    /**
     * Which jump rules at a given instant in TT: its index in the leap seconds table, or null
     * before the first one. The comparison goes in TT on purpose, and the why of it is in
     * `ttToUtc()`.
     *
     * @param float $jdTT
     * @return int|null
     */
    private static function leapInForce(float $jdTT): ?int
    {
        $chosen = null;

        foreach (self::deltaTTable()['leapSeconds'] as $i => [$jd, $seconds]) {
            if ($jdTT >= $jd + (self::TT_MINUS_TAI + $seconds) / 86400) {
                $chosen = $i;
            }
        }

        return $chosen;
    }

    /**
     * A Julian day split into clock date and time, knowing nothing about scales.
     *
     * @param float $jd
     * @param bool $gregorian
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: float}
     */
    private static function decomposeUtc(float $jd, bool $gregorian): array
    {
        [$year, $month, $dayWithFraction] = self::civilDate($jd, $gregorian);

        $day = (int) floor($dayWithFraction);
        $secondsOfDay = ($dayWithFraction - $day) * 86400;

        $hour = (int) floor($secondsOfDay / 3600);
        $minute = (int) floor(($secondsOfDay - $hour * 3600) / 60);

        return [$year, $month, $day, $hour, $minute, $secondsOfDay - $hour * 3600 - $minute * 60];
    }

    /**
     * Nutation in longitude and in obliquity, in radians.
     *
     * The axis of the Earth nods because the Moon and the Sun pull on its equatorial bulge. It is
     * some 17 arcseconds in longitude, with a period of 18.6 years, and it comes into every
     * position, into the true obliquity and into apparent sidereal time, that is, into the
     * houses.
     *
     * It is the IAU 2000B series (McCarthy and Luzum, 2003): 77 luni-solar terms plus two fixed
     * offsets in place of the planetary nutation. The table is written by
     * `astronomy nutation` from the ERFA source, the IAU reference implementation, and
     * there is not a single coefficient written by hand here. It is also the model Swiss
     * Ephemeris uses by default (`SEMOD_NUT_DEFAULT` is `SEMOD_NUT_IAU_2000B`), and that is
     * measured and not assumed: against Swiss over 1200 dates from 1600 to 2400, the difference
     * is 0.000135" in longitude and 0.000388" in obliquity, constant, which is exactly the fixed
     * planetary bias that Swiss omits; with the bias at zero it agrees to machine precision. The
     * IAU 1980 series, with or without the Herring corrections, stays at 0.015", so it is not the
     * one it uses.
     *
     * Here stood only the four big terms, written by hand, and they left half an arcsecond:
     * measured against Swiss, 0.348" worst case and 0.093" median in longitude, 0.082" and 0.020"
     * in obliquity. It was half of the residual that was left in the fixed stars and in the
     * houses when everything else was already down to tenths.
     *
     * The Delaunay arguments go with the complete polynomial of Simon et al. (1994), not with the
     * linear term `eraNut00b` uses. The linear one is enough for the IAU because it declares
     * 2000B for 1995-2050; at four centuries the quadratic term of Ω is 120 arcseconds of
     * argument, which over the 17 seconds of the main term leave 0.0102" in 1606. With the
     * complete polynomial, zero.
     *
     * And why the last nutations are remembered and not only the last one. A chart calls this
     * nearly a hundred times for FOUR different instants: each body asks for its position at t
     * and at t plus and minus the velocity step, in that order, and the houses ask for it in UT.
     * With a single slot, the sequence t, t-δ, t+δ of each body misses on every call: measured,
     * the chart takes two and a half milliseconds longer, which is some eighty-six evaluations of
     * 29 microseconds. With a short list searched by exact equality of t, the series is evaluated
     * four times per chart, and a hit costs 0.14 microseconds, less than the four terms of
     * before.
     *
     * @param float $t Julian centuries since J2000, in TT.
     * @return array{0: float, 1: float} [nutation in longitude, nutation in obliquity]
     */
    public static function nutation(float $t): array
    {
        foreach (self::$recentNutations as [$tCached, $dpsi, $deps]) {
            if ($tCached === $t) {
                return [$dpsi, $deps];
            }
        }

        $table = self::$nutationTable ??= require DataFolder::path('nutation.php');

        // The five arguments, from arcseconds to radians. The remainder modulo a full turn is
        // taken in seconds, before converting, which is where the arithmetic keeps more digits.
        $arguments = [];

        foreach ($table['arguments'] as $coefficients) {
            $arguments[] = deg2rad(fmod(self::polynomial($t, $coefficients), 1296000) / 3600);
        }

        [$l, $lp, $f, $d, $om] = $arguments;

        // From smallest to largest, as ERFA does: the small ones are added before the big ones
        // eat them in the rounding.
        $dp = 0.0;
        $de = 0.0;

        for ($i = count($table['terms']) - 1; $i >= 0; $i--) {
            [$nl, $nlp, $nf, $nd, $nom, $ps, $pst, $pc, $ec, $ect, $es] = $table['terms'][$i];

            $argument = $nl * $l + $nlp * $lp + $nf * $f + $nd * $d + $nom * $om;
            $sine = sin($argument);
            $cosine = cos($argument);

            $dp += ($ps + $pst * $t) * $sine + $pc * $cosine;
            $de += ($ec + $ect * $t) * $cosine + $es * $sine;
        }

        // The coefficients go in tenths of a microarcsecond and the bias in milliarcseconds.
        $dpsi = deg2rad(($dp * 1e-7 + $table['bias'][0] * 1e-3) / 3600);
        $deps = deg2rad(($de * 1e-7 + $table['bias'][1] * 1e-3) / 3600);

        array_unshift(self::$recentNutations, [$t, $dpsi, $deps]);

        if (count(self::$recentNutations) > self::REMEMBERED_NUTATIONS) {
            array_pop(self::$recentNutations);
        }

        return [$dpsi, $deps];
    }

    /**
     * Mean obliquity of the ecliptic, in radians. Laskar series.
     *
     * @param float $t Julian centuries since J2000, in TT.
     * @return float
     */
    public static function meanObliquity(float $t): float
    {
        $u = $t / 100;

        $seconds = 21.448
            - 4680.93 * $u
            - 1.55 * $u ** 2
            + 1999.25 * $u ** 3
            - 51.38 * $u ** 4
            - 249.67 * $u ** 5
            - 39.05 * $u ** 6
            + 7.12 * $u ** 7
            + 27.87 * $u ** 8
            + 5.79 * $u ** 9
            + 2.45 * $u ** 10;

        return deg2rad(23 + 26 / 60 + $seconds / 3600);
    }

    /**
     * True obliquity: the mean one plus the nutation.
     *
     * @param float $t
     * @return float
     */
    public static function trueObliquity(float $t): float
    {
        return self::meanObliquity($t) + self::nutation($t)[1];
    }

    /**
     * MEAN sidereal time at Greenwich, in degrees: that of an equinox that only precesses,
     * without the nutation on top of it.
     *
     * It is what `swe_sidtime0` does when it is passed zero nutation. Swiss exposes it like that,
     * with the obliquity and the nutation as parameters, because its engine needs to pass them in
     * from inside; seen from outside, what there is is two things and they are these two. An
     * arbitrary obliquity is not offered, on purpose: there is one obliquity of date, and putting
     * in another one returns a sidereal time that is from nowhere.
     *
     * **It is used by whoever compares with another source**, because almost all the literature
     * tabulates the mean one: the apparent one differs from it by less than two arcseconds and a
     * table given to the second does not tell them apart. Inside the engine nobody uses it, and
     * nobody should: the houses and the horizon go with the apparent one, which is where the real
     * equinox is.
     *
     * **It is computed with UT, not with TT**, just like the apparent one: it measures the
     * rotation of the planet.
     *
     * @param float $jdUt
     * @return float
     */
    public static function meanSiderealTime(float $jdUt): float
    {
        $mean = self::meanSiderealUnwrapped($jdUt);

        return fmod(fmod($mean, 360) + 360, 360);
    }

    /**
     * The mean sidereal time polynomial as it is, without folding it into one turn: it is
     * millions of degrees for any given date.
     *
     * **It exists so that the folding happens ONCE and at the end, and that is not a whim of
     * style.** The apparent one is this number plus the equation of the equinoxes, and folding
     * before adding gives a different result in the last bits: the polynomial is worth some three
     * million degrees for a date in the seventeenth century, so the remainder of dividing by 360
     * is left with far fewer significant digits than the whole number. Measured: folding first,
     * twelve of the twenty charts of the fingerprint moved **2.9e-5 arcseconds**. It is noise and
     * it makes no difference for any use, and even so the rule of the engine is that the chart
     * does not move by a single bit.
     *
     * @param float $jdUt
     * @return float
     */
    private static function meanSiderealUnwrapped(float $jdUt): float
    {
        $t = self::centuries($jdUt);
        $d = $jdUt - self::J2000;

        return 280.46061837
            + self::ROTATION_PER_DAY * $d
            + 0.000387933 * $t * $t
            - $t ** 3 / 38710000;
    }

    /**
     * Apparent sidereal time at Greenwich, in degrees.
     *
     * How much the Earth has turned, which is what decides where the ascendant falls. **It is
     * computed with UT, not with TT**: it measures the rotation of the planet, and the rotation
     * goes with the civil clock. Putting TT in here shifts the houses.
     *
     * @param float $jdUt
     * @return float
     */
    public static function apparentSiderealTime(float $jdUt): float
    {
        $t = self::centuries($jdUt);

        /* The equation of the equinoxes: the nutation in longitude projected onto the equator.
           It is less than two arcseconds, but it is what separates mean sidereal time from
           apparent sidereal time.

           It is added to the mean one WITHOUT FOLDING and folded once at the end. See
           `meanSiderealUnwrapped`: doing it the other way round moves the chart in the last
           bit. */
        [$dpsi, ] = self::nutation($t);
        $apparent = self::meanSiderealUnwrapped($jdUt) + rad2deg($dpsi * cos(self::trueObliquity($t)));

        return fmod(fmod($apparent, 360) + 360, 360);
    }

    /**
     * How many days a month has, with no table and no leap year rule written by hand.
     *
     * **It comes out of subtracting two Julian days**, the one of the first of the following
     * month and the one of the first of this one. Writing the twelve numbers and the leap year
     * rule would be thirteen places to get it wrong, and on top of that it would have to be
     * written twice, because in the Julian calendar every year divisible by four is a leap year
     * and in the Gregorian one it is not. By subtracting, `civilJulianDay` resolves that, and it
     * is already checked against `swe.julday` over 812 dates.
     *
     * @param int $year
     * @param int $month
     * @param bool $gregorian
     * @return int
     */
    public static function daysInMonth(int $year, int $month, bool $gregorian = true): int
    {
        $nextMonth = $month === 12 ? 1 : $month + 1;
        $nextYear = $month === 12 ? $year + 1 : $year;

        return (int) round(
            self::civilJulianDay($nextYear, $nextMonth, 1.0, $gregorian)
            - self::civilJulianDay($year, $month, 1.0, $gregorian)
        );
    }

    /**
     * The Julian day of a clock date, saying as well whether that date EXISTS and, if it does
     * not, which one was really asked for. It is `swe_date_conversion`.
     *
     * `civilJulianDay` checks nothing, and that is not an oversight: it is the inner door, the
     * one the ephemerides use, and there the dates come from a `DateTimeImmutable` that already
     * exists. But it is public API, and through it **a 31 February goes in and a good-looking
     * Julian day comes out**: the one of 3 March, with nothing warning about it. This is what has
     * to be called when the date comes from outside.
     *
     * **The correction is not computed: it is read off the round trip.** An impossible date
     * falls, by the very arithmetic of `civilJulianDay`, on the day its count corresponds to, so
     * returning it through `civilDate` gives the right date without a single new rule. 31
     * February 2024 comes back as 2 March, day 0 as the last one of the previous month and month
     * 13 as January of the following year. It is the same criterion as Swiss.
     *
     * When the date is valid the numbers that came in are returned, as they are and without going
     * through the way back: that way an on-the-hour time does not come back as 11:59:59.9999
     * because of the rounding of the Julian day. When it is not, the corrected time drags that
     * rounding along and there is nothing to be done, because the day fraction of a date in the
     * seventeenth century is stored with fewer digits than that of one from today.
     *
     * @param int $year
     * @param int $month
     * @param int $day
     * @param float $hours Of the day, from 0 to 24.
     * @param bool $gregorian
     * @return array{valid: bool, jd: float, year: int, month: int, day: int, hours: float}
     */
    public static function checkedJulianDay(
        int $year,
        int $month,
        int $day,
        float $hours = 12.0,
        bool $gregorian = true,
    ): array {
        $jd = self::civilJulianDay($year, $month, $day + $hours / 24.0, $gregorian);

        $valid = $month >= 1 && $month <= 12
            && $day >= 1 && $day <= self::daysInMonth($year, $month, $gregorian)
            && $hours >= 0.0 && $hours < 24.0;

        if ($valid) {
            return ['valid' => true, 'jd' => $jd, 'year' => $year, 'month' => $month, 'day' => $day, 'hours' => $hours];
        }

        [$actualYear, $actualMonth, $dayWithFraction] = self::civilDate($jd, $gregorian);
        $actualDay = (int) floor($dayWithFraction);

        return [
            'valid' => false,
            'jd' => $jd,
            'year' => $actualYear,
            'month' => $actualMonth,
            'day' => $actualDay,
            'hours' => ($dayWithFraction - $actualDay) * 24.0,
        ];
    }

    /**
     * Gregorian year with decimals. It is the variable of the long-term parabola and of the tidal
     * adjustment, and the one Swiss uses for the same thing.
     *
     * @param float $jd
     * @return float
     */
    private static function gregorianYear(float $jd): float
    {
        return 2000 + ($jd - self::J2000) / 365.2425;
    }

    /**
     * Year with decimals measured from 1 January 2000 at 0h in Julian years, which is the
     * variable of the Swiss extrapolation after the table.
     *
     * @param float $jd
     * @return float
     */
    private static function tableYear(float $jd): float
    {
        return 2000 + ($jd - 2451544.5) / 365.25;
    }

    /**
     * @param float $x
     * @param list<float> $coefficients
     * @return float
     */
    private static function polynomial(float $x, array $coefficients): float
    {
        $value = 0.0;

        foreach ($coefficients as $degree => $coefficient) {
            $value += $coefficient * $x ** $degree;
        }

        return $value;
    }
}
