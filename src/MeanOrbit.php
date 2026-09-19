<?php

namespace Astronomy;

/**
 * The MEAN orbit of a planet: the averaged ellipse its mean node and its mean perihelion are taken
 * from. It is what Swiss returns with `SE_NODBIT_MEAN`.
 *
 * It is not the same thing as `OsculatingOrbit` with different numbers, and that is why it is
 * another class.** The osculating one comes from the position and the velocity of one instant, so
 * it is the ellipse the body would describe if at that moment the other planets stopped pulling on
 * it: it changes every day and carries every tug inside. The mean one comes from a TABLE of
 * averaged elements, and what it describes is where the orbit runs once the periodic perturbations
 * are taken away. They are two different questions, and in the outer bodies they separate
 * enormously: measured in the year 2000, Neptune's mean perihelion falls at degree 48.1 and the
 * osculating one at 37.3, eleven degrees apart, that is, a third of a sign.
 *
 * That is why this class has neither the three anomalies nor the three periods of that one: the
 * mean orbit does not describe where the body is NOW, and a true anomaly taken from an averaged
 * ellipse would be a good-looking number that points nowhere. What does make sense is the MEAN
 * anomaly, which is precisely the one the table publishes (the mean longitude minus the
 * perihelion's), and the mean motion that comes from its linear term.
 *
 * Longitudes go in the TRUE ecliptic of date, the same frame in which `Ephemeris` leaves the
 * planets and in which `OsculatingOrbit` gives its own, so that Mars's mean node and its
 * osculating node are read on the same wheel without translating anything. Distances, in
 * astronomical units.
 *
 * Where the numbers come from and what they are checked against, in `MeanElements` and in
 * `NodesAndApsides`.
 */
readonly class MeanOrbit
{
    /**
     * The mean orbit of a planet: elements averaged, not osculating.
     */
    public function __construct(
        public Body $body,
        /** Ecliptic longitude of the mean ascending node, in degrees. */
        public float $ascendingNode,
        /** Distance to the Sun at the ascending node, in astronomical units. */
        public float $ascendingNodeDistance,
        /** Distance to the Sun at the descending node, in astronomical units. */
        public float $descendingNodeDistance,
        /** Ecliptic longitude of the mean perihelion, in degrees. */
        public float $perihelion,
        /** Ecliptic latitude of the mean perihelion, in degrees. */
        public float $perihelionLatitude,
        /** Distance from the perihelion to the Sun, in astronomical units. */
        public float $perihelionDistance,
        /** Distance from the aphelion to the Sun, in astronomical units. */
        public float $aphelionDistance,
        public float $eccentricity,
        /** Inclination of the mean orbit on the ecliptic of date, in degrees. */
        public float $inclination,
        /** Mean semi-major axis, in astronomical units. */
        public float $semiMajorAxis,
        /** Argument of perihelion: from the node to the perihelion, WITHIN the orbital plane. */
        public float $argumentOfPerihelion,
        /** Mean anomaly, in degrees: the mean longitude minus the perihelion's. */
        public float $meanAnomaly,
        /** Mean longitude, in degrees, exactly as the table publishes it. */
        public float $meanLongitude,
        /** Mean motion, in degrees per day, from the linear term of the mean longitude. */
        public float $dailyMotion,
        /** Mean sidereal period, in days. */
        public float $siderealPeriod,
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
     * The latitude of the aphelion, which is the perihelion's with the sign changed: the two
     * apsides and the Sun lie on the same straight line.
     *
     * @return float
     */
    public function aphelionLatitude(): float
    {
        return -$this->perihelionLatitude;
    }

    /**
     * The perihelion longitude of the textbooks, ϖ: the node plus the argument of perihelion.
     *
     * It is a BROKEN angle, measured half on the ecliptic and half on the orbital plane, and it is
     * NOT `$perihelion`, which is where the point is seen. The trap and its size are told in
     * `OsculatingOrbit::perihelionLongitude()`; here it bites just the same. And here it has one
     * more reason to exist: ϖ is exactly the angle the table of mean elements publishes, so it is
     * the one that lets this be checked against the source without redoing any arithmetic.
     *
     * @return float
     */
    public function perihelionLongitude(): float
    {
        return fmod($this->ascendingNode + $this->argumentOfPerihelion, 360);
    }

    /**
     * The distance from the Sun to the second focus, the empty one, in astronomical units. It is
     * in the direction of the aphelion, just as in the osculating orbit and by the same geometry.
     *
     * @return float
     */
    public function secondFocusDistance(): float
    {
        return 2 * $this->semiMajorAxis * $this->eccentricity;
    }
}
