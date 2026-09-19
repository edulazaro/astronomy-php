<?php

namespace Astronomy;

/**
 * The instantaneous orbit of a body around the Sun: its four marked points, its shape
 * and where within it the body is.
 *
 * The four points are the two nodes, where it crosses the ecliptic, and the two apsides, where
 * it passes closest and farthest. Longitudes are in the TRUE ecliptic of date, which is the
 * same frame in which `Ephemeris::position()` gives the planets: so Mars's node and Mars are
 * read on the same wheel without translating anything. Distances, in astronomical units.
 *
 * Of the four points only two are stored, and the other two are derived, which is the same
 * rule by which the Moon's south node does not exist as a body: the descending node is always
 * half a turn from the ascending one, and the aphelion half a turn from the perihelion with the
 * latitude's sign flipped. Storing all four would be storing the same number twice and leaving
 * the door open for them to disagree one day.
 *
 * The distances really are four, and that is the asymmetry that misleads: one node is opposite
 * the other but NOT at the same distance from the Sun, because the orbit is an ellipse and the
 * Sun sits at one focus, not at the centre. Measured on Pluto in the year 2000, the ascending
 * node falls at 40.95 astronomical units and the descending one at 33.60.
 *
 * `eccentricity` and `inclination` are not decoration: they say how far the rest can be
 * trusted. An apsis on an almost round orbit is as badly defined as Lilith's apogee on the
 * Moon's, and for the same reason; a node on an orbit lying almost flat in the ecliptic,
 * likewise. That is why `LunarPoints` also exposes its eccentricity.
 *
 * The three anomalies, which are three ways of telling the same time
 *
 * The three measure the same thing, how far the body has travelled since perihelion, and they
 * only agree at perihelion and at aphelion. The mean one is the clock's: it grows at a
 * constant rate and corresponds to no angle anyone can see. The true one is the real angle,
 * the one the body makes with the perihelion seen from the Sun, and that is why it runs fast
 * near perihelion and slow at aphelion. The eccentric one is what joins them, and means
 * nothing on its own: it is the auxiliary angle on the circle that encloses the ellipse, and it
 * exists because that is where Kepler's equation is written.
 *
 * On Pluto, with eccentricity 0.24, the three are ten degrees apart; on Venus, with 0.0068,
 * half a degree. And the order in which they are worked out matters: the mean comes from the
 * eccentric and that one from the true, so an eccentricity error is amplified towards the
 * mean.
 */
readonly class OsculatingOrbit
{
    /**
     * The ellipse a body would describe if the rest stopped pulling on it.
     */
    public function __construct(
        public Body|DownloadableBody $body,
        /** Ecliptic longitude of the ascending node, in degrees. */
        public float $ascendingNode,
        /** Distance to the Sun at the ascending node, in astronomical units. */
        public float $ascendingNodeDistance,
        /** Distance to the Sun at the descending node, in astronomical units. */
        public float $descendingNodeDistance,
        /** Ecliptic longitude of the perihelion, in degrees. */
        public float $perihelion,
        /** Ecliptic latitude of the perihelion, in degrees. */
        public float $perihelionLatitude,
        /** Distance from the perihelion to the Sun, in astronomical units. */
        public float $perihelionDistance,
        /** Distance from the aphelion to the Sun, in astronomical units. */
        public float $aphelionDistance,
        public float $eccentricity,
        /** Inclination to the ecliptic of date, in degrees. */
        public float $inclination,
        /** Semi-major axis, in astronomical units. */
        public float $semiMajorAxis,
        /** Argument of perihelion: from the node to the perihelion, measured WITHIN the plane of the orbit. */
        public float $argumentOfPerihelion,
        /** Mean anomaly, in degrees: the clock's, the one that grows at a constant rate. */
        public float $meanAnomaly,
        /** True anomaly, in degrees: the angle from the perihelion seen from the Sun. */
        public float $trueAnomaly,
        /** Eccentric anomaly, in degrees: the auxiliary angle of Kepler's equation. */
        public float $eccentricAnomaly,
        /** Mean longitude, in degrees: the mean anomaly plus the longitude of perihelion. */
        public float $meanLongitude,
        /** Mean motion, in degrees per day. */
        public float $dailyMotion,
        /** Sidereal period, in days: one whole turn against the stars. */
        public float $siderealPeriod,
        /** Tropical period, in days: one turn against the equinox, which comes to meet it. */
        public float $tropicalPeriod,
        /** Synodic period against the Earth, in days: from one opposition to the next. */
        public float $synodicPeriod,
        /** Julian day in Terrestrial Time of the LAST perihelion passage. */
        public float $perihelionPassage,
    ) {}

    /**
     * The descending node, opposite the ascending one.
     *
     * @return float
     */
    public function descendingNode(): float
    {
        return fmod($this->ascendingNode + 180, 360);
    }

    /**
     * The aphelion, opposite the perihelion.
     *
     * @return float
     */
    public function aphelion(): float
    {
        return fmod($this->perihelion + 180, 360);
    }

    /**
     * The latitude of the aphelion, which is the perihelion's with the sign flipped: the two
     * apsides and the Sun lie on the same straight line.
     *
     * @return float
     */
    public function aphelionLatitude(): float
    {
        return -$this->perihelionLatitude;
    }

    /**
     * The distance from the Sun to the SECOND FOCUS of the ellipse, the empty focus, in
     * astronomical units. It is Swiss's `SE_NODBIT_FOPOINT`.
     *
     * No new angle is needed, and that is all there is to it: the empty focus lies in the
     * direction of the APHELION. The two foci and the two apsides fall on the same straight
     * line, which is the major axis, so its longitude is `aphelion()` and its latitude
     * `aphelionLatitude()`; the only thing that changes is how far away it is, `2ae` instead of
     * `a(1+e)`. Measured against Swiss in heliocentric coordinates, eight bodies and three
     * epochs from 1700 to 2300: longitude and latitude agree with the aphelion's to 0.15
     * arcseconds worst case** (Saturn in 2300, and that is an internal difference of its own)
     * and the distance to 1e-6 astronomical units.
     *
     * Watch out for what Swiss returns by default, which is where the confusion comes from.
     * Its apsides come out GEOCENTRIC, and there Neptune's empty focus appears 36 degrees from
     * its aphelion: it is not another point in the sky, it is the same point in space seen from
     * here, and what separates them is that one is at 30 astronomical units and the other at
     * 0.67. With `SEFLG_HELCTR` the two longitudes become the same again down to the sixth
     * figure. It cost a whole measurement to notice, so it is written down here.
     *
     * What it is good for, and why it is a point and not a curiosity: in an ellipse the sum of
     * the distances to the two foci is always `2a`, and from the empty focus the body is seen to
     * move at an almost constant angular speed. It is also the other definition of Lilith that
     * Swiss's own documentation records, the empty focus of the Moon's orbit instead of its
     * apogee.
     *
     * @return float
     */
    public function secondFocusDistance(): float
    {
        return 2 * $this->semiMajorAxis * $this->eccentricity;
    }

    /**
     * The longitude of perihelion of the textbooks, the one written ϖ: the node plus the
     * argument of perihelion.
     *
     * It is NOT `$perihelion`, and confusing them is no small error. ϖ is a BROKEN angle:
     * one piece is measured on the ecliptic, from the Aries point to the node, and the other
     * on the plane of the orbit, from the node to the perihelion. Adding them is a convenient
     * convention, not the longitude of any point in the sky. The ecliptic longitude of the
     * perihelion, which is where it is seen in the zodiac, is the real projection, and the two
     * separate as much as the orbit is inclined: measured in the year 2000, two hundredths of a
     * degree on Saturn, 0.98 on Pluto and 5.64 on Pallas, which is inclined 35 degrees. It
     * is exposed because it is what any published table of elements carries (`OM + W` in JPL
     * Horizons) and without it there is no way to check this against one.
     *
     * @return float
     */
    public function perihelionLongitude(): float
    {
        return fmod($this->ascendingNode + $this->argumentOfPerihelion, 360);
    }

    /**
     * The next perihelion passage, which is the previous one plus one turn.
     *
     * It holds as long as the orbit does not change, that is, never entirely: it is an
     * osculating ellipse and the other planets keep pulling. For a slow body the difference
     * between this and searching for the real passage is days. It serves to date something, not
     * to point a telescope.
     *
     * @return float
     */
    public function nextPerihelionPassage(): float
    {
        return $this->perihelionPassage + $this->siderealPeriod;
    }
}
