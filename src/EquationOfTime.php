<?php

namespace Astronomy;

/**
 * The equation of time: how far the real Sun runs from the clock Sun.
 *
 * The clock measures a day of exactly twenty-four hours, all of them alike. The Sun does
 * not: some days it takes a little longer to come back to the meridian and others a little
 * less, for two reasons that add up. The orbit of the Earth is an ellipse, so in January we
 * run faster than in July; and the Sun moves along the ECLIPTIC, which is tilted, while what
 * marks the hour is its progress along the EQUATOR. Out of that comes a difference that
 * reaches a quarter of an hour and that cancels four times a year.
 *
 * It is what lies underneath a sundial, which measures TRUE time and for that reason never
 * agrees with the one on the wrist; underneath the planetary hours, which split the real
 * solar day into twelve and not the clock one; and underneath the analemma, that figure of
 * eight that comes out of photographing the Sun at the same hour for a year.
 *
 * What it is exactly, and why it is computed this way and not with a formula
 *
 * Apparent solar time minus mean solar time, both at the same place. And both are already in
 * the engine, so no approximate series is needed here:
 *
 * - the apparent one is the hour angle of the real Sun, that is the apparent sidereal time
 * minus the apparent right ascension of the Sun, plus half a turn because the civil day
 * starts at midnight and not at noon;
 * - the mean one is Universal Time itself, which is what a clock measures.
 *
 * A short four-term series by Meeus circulates everywhere, and it is not here for the usual
 * reason: it would be a hand-written coefficient next to an engine that already has the Sun
 * verified against JPL below two tenths of an arcsecond. Subtracting two things that are
 * already known is more exact and adds nothing to maintain.
 *
 * The two time scales, once again
 *
 * It is the trap of this codebase and here the two cross in the same subtraction: sidereal
 * time goes in UT (it measures how much the Earth has turned, and that is told by the
 * civil clock) and the position of the Sun in TT (it is an ephemeris). Using the same
 * scale for both puts the whole seventy seconds of delta T inside the result, that is more
 * than a minute of error in a number that is at most sixteen.
 *
 * Where this lives, and why not in `Time`
 *
 * Because `Time` does not know where the Sun is, and it has to stay that way. It is the only
 * class of the engine that does not depend on any other: scales, delta T, nutation,
 * obliquity and rotation, and everything else hangs from it starting with `Ephemeris`.
 * Putting an ephemeris inside it would close the circle right at the class that exists so
 * that the two time scales do not get confused. The equation of time is not a property of
 * time: it is of the Sun.
 *
 * The sign
 *
 * Positive means that the real Sun runs AHEAD of the clock, that is, the sundial reads
 * later than the wristwatch and true noon has already passed. It is the usual convention
 * (apparent minus mean) and the one that makes the maximum of early November come out
 * positive. There is literature that writes it the other way round, so it is not inherited
 * from anyone: it is defined here and the tests pin it down.
 */
final class EquationOfTime
{
    /** Minutes of a day. The natural unit of this: it is read in minutes, not in days. */
    private const MINUTES_PER_DAY = 1440.0;

    /**
     * How many turns the inverse gets. See `toMean`: it converges in two, and the cap is
     * there so that a future change that breaks the contraction shows up instead of hanging.
     */
    private const MAX_ROUNDS = 8;

    /** When the inverse is taken as good, in days: one thousandth of a second. */
    private const TOLERANCE = 1e-3 / 86400.0;

    /**
     * The equation of time at that instant, in minutes.
     *
     * @param float $jdUt Julian day in Universal Time.
     * @return float Minutes. Positive, the Sun runs ahead of the clock.
     */
    public static function minutes(float $jdUt): float
    {
        return self::degrees($jdUt) * 4.0;
    }

    /**
     * The same in degrees of rotation of the Earth, which is the unit it is computed in.
     *
     * One degree is four minutes of clock. It is exposed because whoever is working with
     * hour angles wants it this way, and multiplying by four in order to divide again is an
     * opportunity to get it wrong.
     *
     * @param float $jdUt
     * @return float Degrees.
     */
    public static function degrees(float $jdUt): float
    {
        $sun = Horizon::equatorialOf(Body::Sun, Time::tt($jdUt));

        /* The hour angle of the Sun at Greenwich, and from there apparent solar time: at
           true noon the hour angle is zero, and the civil day starts twelve hours earlier,
           so the apparent time in degrees is the hour angle plus 180. */
        $hourAngle = Time::apparentSiderealTime($jdUt) - $sun->rightAscension;
        $apparent = $hourAngle + 180.0;

        // MEAN solar time at Greenwich is UT itself: that is its definition. The half
        // Julian day of offset is because a Julian day starts at noon and a civil one does not.
        $mean = self::dayFraction($jdUt + 0.5) * 360.0;

        return self::wrap($apparent - $mean);
    }

    /**
     * From local MEAN time to local TRUE time: what a sundial would read.
     *
     * Both go as a Julian day, that is, the decimal part carries the hour inside. The mean
     * one is Universal Time shifted to the longitude of the place (`jdUt + longitude/360`),
     * which is the time a clock set by the meridian of the place itself would have and not
     * by the meridian of the time zone: the zone is a convention of bands and the Sun knows
     * nothing of bands.
     *
     * @param float $jdMean Julian day of local mean time.
     * @param float $longitude Degrees, east positive.
     * @return float Julian day of local true time.
     */
    public static function toApparent(float $jdMean, float $longitude): float
    {
        return $jdMean + self::minutes($jdMean - $longitude / 360.0) / self::MINUTES_PER_DAY;
    }

    /**
     * The way back: from the time the sundial reads to the one on the wristwatch.
     *
     * And this one does have to iterate, which is the asymmetry of the pair. The
     * equation of time is evaluated at the instant, and here the instant is exactly what is
     * being looked for. It converges very fast because the value changes at most half a
     * minute a day, that is, each turn divides the error by some three thousand: with two it
     * is already below a thousandth of a second. The cap on turns is not there to get there,
     * it is so that it shows the day someone changes something that breaks the contraction.
     *
     * @param float $jdTrue Julian day of local true time.
     * @param float $longitude Degrees, east positive.
     * @return float Julian day of local mean time.
     */
    public static function toMean(float $jdTrue, float $longitude): float
    {
        $jdMean = $jdTrue;

        for ($iteration = 0; $iteration < self::MAX_ROUNDS; $iteration++) {
            $next = $jdTrue - self::minutes($jdMean - $longitude / 360.0) / self::MINUTES_PER_DAY;

            if (abs($next - $jdMean) < self::TOLERANCE) {
                return $next;
            }

            $jdMean = $next;
        }

        return $jdMean;
    }

    /**
     * The time a sundial reads at that place, from 0 to 24.
     *
     * It is `toApparent` read as time of day, which is how a sundial is looked at. Careful
     * with the boundary: at 23:55 true of one day the wristwatch may already be in the next
     * one, and the other way round. That is why what is returned is the time and not the date.
     *
     * @param float $jdUt
     * @param float $longitude Degrees, east positive.
     * @return float Hours, from 0 to 24.
     */
    public static function trueLocalTime(float $jdUt, float $longitude): float
    {
        $jdMean = $jdUt + $longitude / 360.0;

        return self::dayFraction(self::toApparent($jdMean, $longitude) + 0.5) * 24.0;
    }

    /**
     * Local mean time, from 0 to 24: that of the clock set by the meridian of the place.
     *
     * It is not official time. Between the two stands the time zone, which is a convention
     * of bands: in Madrid, which is three and a half degrees west of Greenwich but runs on
     * central European time, they differ by more than an hour, and with summer time by two.
     *
     * @param float $jdUt
     * @param float $longitude Degrees, east positive.
     * @return float Hours, from 0 to 24.
     */
    public static function meanLocalTime(float $jdUt, float $longitude): float
    {
        return self::dayFraction($jdUt + $longitude / 360.0 + 0.5) * 24.0;
    }

    /**
     * When the Sun culminates at that place that day: true noon, in UT.
     *
     * It comes out of the definition and not from tracking anything, because true noon IS
     * twelve o'clock of true time. An integer Julian day is noon by construction, so the mean
     * noon of the civil day that contains the instant is `floor(jdMean + 0.5)`, and from
     * there to the mean time that corresponds to it goes `toMean`, which is the only one of
     * the two conversions that needs to iterate.
     *
     * And it is nobody's twelve o'clock, not even taking the time zone away: in Madrid,
     * with summer time, the Sun culminates past two. The time zone is a convention of bands,
     * the longitude of the place shifts noon four minutes per degree, and on top of that
     * there is this, which adds or takes away up to a quarter of an hour.
     *
     * It is the same computation that a chart does by the other route when it works out how
     * tracking the hour angle of the Sun with `RiseSet`, and that is why the two are checked
     * against each other.
     *
     * @param float $jdUt Any instant of the day being asked about.
     * @param float $longitude Degrees, east positive.
     * @return float Julian day in UT of true noon of that day at that place.
     */
    public static function trueNoon(float $jdUt, float $longitude): float
    {
        $meanNoon = floor($jdUt + $longitude / 360.0 + 0.5);

        return self::toMean($meanNoon, $longitude) - $longitude / 360.0;
    }

    /**
     * The decimal part of a Julian day, always positive.
     *
     * `fmod` with a negative Julian day (and they exist: the year 4713 before Christ is not
     * the beginning of anything) returns a negative fraction, and that would send the hour to
     * the previous day. It is the same trap already noted in `Eclipses` with
     * `createFromFormat`.
     *
     * @param float $jd
     * @return float From 0 to 1.
     */
    private static function dayFraction(float $jd): float
    {
        return $jd - floor($jd);
    }

    /**
     * To the shorter arc, from -180 to 180.
     *
     * Unfolded, the subtraction all of this comes out of gives values near 360 or near -360
     * half of the time, depending on which side of midnight the instant falls on. The real
     * result never goes past four and a half degrees.
     *
     * @param float $degrees
     * @return float
     */
    private static function wrap(float $degrees): float
    {
        $g = fmod(fmod($degrees, 360.0) + 360.0, 360.0);

        return $g > 180.0 ? $g - 360.0 : $g;
    }
}
