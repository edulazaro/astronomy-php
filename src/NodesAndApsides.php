<?php

namespace Astronomy;

use LogicException;

/**
 * The whole orbit of each body around the Sun: where it cuts the ecliptic, where its perihelion
 * and its aphelion are, what shape it has, whereabouts in it the body is, how long it takes to
 * go round and the closest and the farthest it can ever get from us.
 *
 * It is three Swiss Ephemeris functions at once, `swe_nod_aps`, `swe_get_orbital_elements` and
 * `swe_orbit_max_min_true_distance`, and they are together because **the three of them come out
 * of the same state**: once you have the position and the velocity of an instant, everything else
 * is algebra. Splitting them would have meant asking three times for the five ephemerides that
 * the derivative costs.
 *
 * It is the same trade as `LunarPoints` and that one is worth reading first: **these are not
 * bodies, they are elements of an orbit**, and that is why there is no table and no series to
 * ask for. They come out of the position and the velocity, which `Ephemeris` already gives, with
 * the same computation that yields the lunar node there (from the angular momentum `r × v`) and
 * Lilith (from the eccentricity vector). What changes is the orbit being looked at: there, that
 * of the Moon around the Earth; here, that of each body around the Sun.
 *
 * ## Osculating, mean and barycentric: three questions, and they are not the same one
 *
 * - **`of()`, osculating.** The ellipse the body would describe if the other planets stopped
 *   pulling at it this very instant. It comes out entirely of the position and the velocity of
 *   that instant, so it changes every day and carries every tug inside it.
 * - **`mean()`.** The averaged orbit, without the periodic perturbations, which comes out of no
 *   state at all but out of a table of elements. In the outer bodies the two of them drift far
 *   apart: the mean perihelion of Neptune in the year 2000 falls on degree 48.1 and the
 *   osculating one on 37.3, **eleven degrees**, that is, a third of a sign.
 * - **`barycentric()`.** The osculating one again, but measured from the barycentre of the solar
 *   system instead of from the Sun, which is not standing still either.
 *
 * **The mean ones went undone for a long time, and the reason written down was a good one**: this
 * repository has VSOP87 (which publishes positions, not elements), ELP and JPL tables, and none of
 * them carries mean planetary elements; copying them from the Swiss code is ruled out by licence
 * and writing them from memory is what this engine does not do. What has changed is that the
 * source turned up, which was the missing condition: Simon and others (1994), and published in
 * ERFA under a BSD licence on top of that, which is where the nutation is already downloaded from.
 * It is told in `MeanElements`.
 *
 * With the Moon it could be done from the start, and that is why `LunarPoints::meanNode()` exists:
 * ELP publishes its mean longitudes (`w2` the perigee, `w3` the node) and with those the mean node
 * is a two-line computation.
 *
 * ## The five bodies that do not have this, and why it throws instead of returning a number
 *
 * - **The Sun** is the origin of the frame: it has no orbit around itself.
 * - **The Earth** is the degenerate case and the easiest one to serve wrong. The ecliptic IS, by
 *   definition, the plane of the orbit of the Earth, so the inclination of its orbit on the
 *   ecliptic is zero but for the wobble the Moon and the planets put into it, and the line of
 *   nodes is the intersection of a plane with itself. A number comes out, it changes from one
 *   week to the next and it means nothing. Measured: its osculating inclination on the ecliptic
 *   of date goes from 1.5 to 7.4 arcseconds in four months and its node wanders from degree 134
 *   to 188 in three, that is, the number exists and says nothing.
 * - **The Moon** has real nodes and apsides, but of its orbit around the EARTH, and they are in
 *   `LunarPoints`. Those of its orbit around the Sun would be almost those of the Earth, that
 *   is, the same degeneracy.
 * - **The nodes and the Liliths** are not bodies: they have no orbit of their own to look at.
 * - **Selena and the Waldemath moon** are the two fictitious ones that orbit the EARTH. Their
 *   heliocentric vector is the Earth plus their own, so their heliocentric elements would
 *   describe an Earth orbit with a wobble on top: a planet that looks perfectly fine in any old
 *   sign.
 *
 * ## Precision, measured against JPL Horizons
 *
 * Against the osculating elements Horizons publishes (`EPHEM_TYPE='ELEMENTS'`, centre
 * `500@10`, ecliptic of J2000), fourteen bodies in 1700, 2000 and 2300:
 *
 * | | worst difference |
 * |---|---|
 * | Node, tabulated bodies (Pluto, Chiron, Pholus, the four asteroids) | 0.06″ |
 * | Node, VSOP87 planets | 5.8″ (Uranus in 2300) |
 * | Apsides, tabulated bodies | 0.60″ |
 * | Apsides, Mercury, Venus, Mars, Jupiter, Saturn, Uranus | 8.4″ |
 * | Apsides, Neptune | 64.7″ (in 2300) |
 * | Perihelion latitude | 0.65″ |
 * | Inclination | 0.09″ |
 * | Eccentricity | 6.6e-6 |
 * | Semi-major axis | 1.2e-4 AU |
 * | Perihelion and aphelion distance | 2.4e-4 AU |
 * | The three anomalies and the perihelion passage | 0.018° (Neptune in 2300) |
 * | Mean motion and sidereal period | 5.9e-6 relative |
 *
 * The last two rows are the same measurement as the ones above seen from another side: the period
 * comes out of the semi-major axis by Kepler's third law, so it inherits its 1.2e-4 astronomical
 * units, and the anomalies come out of the eccentricity vector divided by the eccentricity, which
 * is where the worst case of this class always comes from.
 *
 * **What is read in degrees is not the error, it is the error divided by the eccentricity.** The
 * eccentricity vector, which is where the apside comes from, stays below **6.7e-6** in all
 * forty-two cases; what happens is that this vector measures `e`, so the angle it defines is
 * known with an error of 6.7e-6 divided by `e`. Neptune has 0.0085 in 2300 and out of that come
 * those 64.7 arcseconds, which are 1.1 minutes: the apside of an almost round orbit is ill
 * defined, just like the apogee of Lilith. The nodes do not have that problem because they come
 * out of the angular momentum, which is not divided by anything.
 *
 * ## The three periods, and the Earth the third one needed
 *
 * The **sidereal** one is a turn against the stars and comes out of Kepler's third law. The
 * **tropical** one is a turn against the equinox, which comes to meet the body because it
 * precesses, so it is somewhat shorter: it is the same computation that separates the tropical
 * year from the sidereal year, and that is its check. The rate comes out of differentiating
 * `Time::generalPrecession`, the same precession with which the engine rotates everything else,
 * and not out of a constant written here.
 *
 * The **synodic** one is from one opposition to the next, that is, against the Earth, and that is
 * where the real work turned up. **The Earth has to come in through its barycentre with the
 * Moon**, and it is not a refinement: the centre of the planet goes round that barycentre at 12.5
 * metres per second, the semi-major axis comes out of the energy and therefore out of the square
 * of the velocity, and out of that come eight ten-thousandths of an astronomical unit of
 * semi-major axis which on top of it change sign every fortnight. Measured: with the centre, the
 * osculating year came out at 365.50 days and the synodic period of Mercury moved half a day from
 * one week to the next; with the barycentre it comes out 365.2544, which is what Horizons
 * publishes for that date to the fourth decimal, and the eight synodic periods agree with the
 * published ones.
 *
 * That is why `ellipse()` is private and `of()` still throws for the Earth: its ellipse exists and
 * is the one that gives the year, and what does not exist are its nodes on the ecliptic. And its
 * orbit is remembered per instant, because whoever asks for this asks for the nine bodies of the
 * same date.
 *
 * ## The extreme distances
 *
 * `distances()` is `swe_orbit_max_min_true_distance`: the closest and the farthest this body and
 * the Earth can ever get. **They really are the extrema between the two ELLIPSES**, searched on
 * both at once with their inclinations in place, and not the aphelion of one plus the aphelion of
 * the other: two aphelia only add up if they fall in opposite directions, and the lines of apsides
 * are where they are. On Jupiter in the year 2000, the quick computation gives 6.4748 astronomical
 * units and the good one 6.4574.
 *
 * Against Swiss, the eight planets in the year 2000: **worst 2.3e-3 astronomical units in the
 * extrema (Pluto) and 1e-4 in today's distance**. What is left is the ephemeris, because
 * pyswisseph runs here without files, that is, with Moshier: the distance of Jupiter today, which
 * does not go through any ellipse, agrees to 2.8e-7.
 *
 * ## The fictitious ones, which do come out and do not return what they have written down
 *
 * The seventeen of `FictitiousBodies` that do not orbit the Earth go through here and give their
 * four points, but **the osculating one does not have to return the element that
 * `resources/astro/fictitious.php` has written down, and when it does not match it is not a bug**.
 * It happens in two cases and in both for the same reason, which is that their orbit is not a
 * fixed ellipse:
 *
 * - The ones that carry the angles as polynomials in time (Vulcan is the only one of these that
 *   gets this far) have the perihelion and the node turning 1,670 degrees per century, so their
 *   motion is not Keplerian and the osculating ellipse is not the written one: eccentricity
 *   0.0212 against the 0.019 of the file.
 * - The ones referred to the ecliptic of date (`JDATE`, with no equinox of their own) precess
 *   with the equinox by definition, and that is motion too. Proserpina has an eccentricity of
 *   zero written down and its osculating orbit comes out with 0.054.
 *
 * The ones referred to a fixed equinox do return their own to the decimal, because the rotation
 * `FictitiousBodies` puts into them to carry them to the date is exactly the one that is undone
 * here: Cupido comes out with semi-major axis 40.9984 against the 40.99837 written down and
 * eccentricity 0.0046 against 0.0046.
 *
 * A whole orbit costs some 10 milliseconds and its extreme distances another 2: five positions,
 * the algebra and a sweep of the two ellipses. The first one of the instant pays for the orbit of
 * the Earth as well, which is another five positions. And the derivative
 * samples a day away on each side, so a tabulated body has to be asked for this a day inside the
 * edge of its table; beyond that `EphemerisPositions` throws with its own message, which already
 * says from what date to what date it reaches.
 */
class NodesAndApsides
{
    /**
     * Days the light takes to travel one astronomical unit.
     *
     * It repeats the private constant of `Ephemeris` and the one of `Moon`, and here the exact
     * value hardly matters: it only serves to ask for the position at the instant that leaves it
     * GEOMETRIC at the date being looked for (see `geometricJ2000`). An error in this number
     * shifts the five samples by the same amount, and what is derived from them is a difference.
     */
    private const LIGHT_PER_AU = 0.005775518331;

    /**
     * Cap on the step of the derivative, in days.
     *
     * Measured, and the number is not free on either side. From above what rules is the truncation
     * of the four-point formula, which goes as the fourth power of what the body travels in the
     * step: with two days, Mercury drifts 165 arcseconds from Horizons at the perihelion, and with
     * five, 6,150. From below what rules is the ripple the positions carry on top: going under
     * half a day improves nothing, because the residual against the JPL stops being smooth before
     * that. Half a day is where the two things meet for the whole catalogue.
     */
    private const MAX_STEP = 0.5;

    /**
     * Fraction of the orbit travelled in one step, for the fast bodies.
     *
     * The step is not well measured in days but in how much the body advances: half a day is a
     * comfortable step for Mercury (which goes round in 88) and an outrage for a hypothetical
     * inner body of eighteen, such as the Vulcan of the fictitious ones. This number is the
     * fraction of a radian of mean anomaly admitted per step, and it is the one that corresponds
     * to half a day on Mercury.
     */
    private const ORBIT_FRACTION = 0.04;

    /**
     * How many points are taken from each ellipse in the coarse sweep of the extreme distances.
     *
     * Seventy-two are five degrees of eccentric anomaly, and the number is measured against a
     * sweep thirty times finer: with 72 the fourteen bodies fall on the same extremum, and
     * dropping to 24 the minimum of Pluto goes to the wrong valley. What decides is not the
     * precision, which the refinement provides, but not getting the valley wrong.
     */
    private const ELLIPSE_SAMPLES = 72;

    /** How many times the window is halved when refining an extremum. */
    private const REFINEMENT_ROUNDS = 50;

    /** @var array{gm: array<string, float>}|null */
    private static ?array $masses = null;

    /** The instant whose orbit of the Earth is remembered. See `earthEllipse`. */
    private static ?float $yearInstant = null;

    /** @var array<string, mixed> */
    private static array $ofTheEarth = [];

    /**
     * The four marked points of the orbit of a body at an instant, the shape of that orbit and
     * whereabouts in it the body is.
     *
     * It is `swe_nod_aps` and `swe_get_orbital_elements` at once, and they go together because
     * **they come out of the same state**: once you have the position and the velocity, the three
     * anomalies, the mean motion and the three periods are arithmetic on what is already computed.
     * Splitting them would have meant asking twice for the five ephemerides the derivative costs.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT Julian day in Terrestrial Time.
     * @return OsculatingOrbit
     */
    public static function of(Body|DownloadableBody $body, float $jdTT): OsculatingOrbit
    {
        return self::build($body, $jdTT, false);
    }

    /**
     * The same osculating orbit, but measured with respect to the BARYCENTRE of the solar system
     * instead of with respect to the Sun. It is the `SE_NODBIT_OSCU_BAR` of Swiss.
     *
     * **It is not a refinement of the heliocentric one: it is another orbit, and it shows at a
     * glance.** Measured against Horizons in the year 2000, Jupiter has semi-major axis 5.2043
     * around the Sun and 5.1889 around the barycentre, fifteen thousandths of an astronomical unit
     * of difference, and its eccentricity goes from 0.0488 to 0.0476. The reason is that the Sun
     * is not standing still: Jupiter makes it go round the barycentre 743,000 kilometres away,
     * that is, more than the solar radius itself, so an orbit referred to the centre of the Sun
     * carries that wobble inside it. It is the same argument, multiplied by a thousand, that
     * forces the Earth to come in through its barycentre with the Moon so that its osculating year
     * does not come out at 365.50 days.
     *
     * **What it is really good for: the distant bodies.** From a few hundred astronomical units
     * onwards the Sun and the planets are seen as a single mass and what the body orbits is the
     * barycentre; that is why the published elements of Sedna and of the other sednoids are
     * barycentric, and comparing them with heliocentric ones is comparing two different things.
     *
     * **Against Swiss it cannot be checked, and it is worth saying why**: pyswisseph without
     * ephemeris files does not compute the barycentric one and returns the heliocentric one
     * without warning, measured on Jupiter, Saturn, Neptune and Pluto, where the four of them give
     * exactly the same numbers with `NODBIT_OSCU_BAR` as with `NODBIT_OSCU`. The reference here is
     * JPL Horizons with `CENTER='500@0'`, which is what `NodosYApsidesTest` does.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT Julian day in Terrestrial Time.
     * @return OsculatingOrbit
     */
    public static function barycentric(Body|DownloadableBody $body, float $jdTT): OsculatingOrbit
    {
        return self::build($body, $jdTT, true);
    }

    /**
     * The body of `of()` and of `barycentric()`, which only differ in where it is measured from.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param bool $barycentric
     * @return OsculatingOrbit
     */
    private static function build(Body|DownloadableBody $body, float $jdTT, bool $barycentric): OsculatingOrbit
    {
        self::requireOrbitAroundSun($body);

        $ellipse = self::ellipse($body, $jdTT, $barycentric);

        $position = $ellipse['position'];
        $momentum = $ellipse['moment'];
        $eccentricity = $ellipse['eccentricity'];
        $semiMajorAxis = $ellipse['semiMajorAxis'];

        // The line of nodes is perpendicular both to the axis of the ecliptic and to the angular
        // momentum: (0,0,1) x (hx,hy,hz) = (-hy, hx, 0). Written already solved, as over there.
        $nodeLine = [-$momentum[1], $momentum[0], 0.0];

        $node = self::unit($nodeLine);
        $perihelion = self::unit($ellipse['eccentricityVector']);
        $axis = self::unit($momentum);

        // The argument of perihelion: from the node to the perihelion, measured inside the plane
        // of the orbit and in the direction of motion, which is what the angular momentum says.
        $cosine = self::dot($node, $perihelion);
        $argument = atan2(self::dot(self::cross($node, $perihelion), $axis), $cosine);

        /* The three anomalies, and all three counted forward from the perihelion. The TRUE one is
           the same computation as the argument of perihelion with the body in the role of the
           node: the angle from the perihelion to the body, inside the plane and in the direction
           of motion. */
        $radial = self::unit($position);
        $trueAnomaly = atan2(
            self::dot(self::cross($perihelion, $radial), $axis),
            self::dot($perihelion, $radial)
        );

        /* The ECCENTRIC one comes out of the true one without solving anything: Kepler's equation
           only has to be iterated in the other direction, from the mean to the eccentric one. It
           goes with `atan2` of the two components and not with the tangent of the half angle,
           which is how the books write it: `tan(v/2)` goes to infinity at the aphelion, which is
           a place bodies do pass through. */
        $eccentricAnomaly = atan2(
            sqrt(1 - $eccentricity ** 2) * sin($trueAnomaly),
            $eccentricity + cos($trueAnomaly)
        );

        // And the MEAN one, which is Kepler in its easy direction.
        $meanAnomaly = self::normalize(rad2deg($eccentricAnomaly - $eccentricity * sin($eccentricAnomaly)));

        /* Kepler's third law, with the SAME mu the semi-major axis came out of. It does not matter
           which one is used as long as it is the same in both places: mixing them leaves a mean
           motion that is not the one of this ellipse. Swiss uses the bare GM of the Sun here and
           Horizons the sum of the two masses, which is what we do, and that is why its period for
           Jupiter and ours differ by two days. */
        $dailyMotion = rad2deg(sqrt($ellipse['mu'] / $semiMajorAxis ** 3));
        $period = 360.0 / $dailyMotion;

        $perihelionLongitude = self::normalize(rad2deg(atan2($node[1], $node[0])) + rad2deg($argument));

        /* The distances of the two nodes are NOT equal, and that is the trap of reading an
           ellipse as if it were a circle: the Sun is at one focus. At the ascending node the
           true anomaly is minus the argument of perihelion (by definition, the argument is
           counted from there), so its cosine is the same; at the descending one, half a turn
           further on, it changes sign. */
        $parameter = $semiMajorAxis * (1 - $eccentricity ** 2);

        return new OsculatingOrbit(
            body: $body,
            ascendingNode: self::normalize(rad2deg(atan2($node[1], $node[0]))),
            ascendingNodeDistance: $parameter / (1 + $eccentricity * $cosine),
            descendingNodeDistance: $parameter / (1 - $eccentricity * $cosine),
            perihelion: self::normalize(rad2deg(atan2($perihelion[1], $perihelion[0]))),
            perihelionLatitude: rad2deg(asin($perihelion[2] / self::norm($perihelion))),
            perihelionDistance: $semiMajorAxis * (1 - $eccentricity),
            aphelionDistance: $semiMajorAxis * (1 + $eccentricity),
            eccentricity: $eccentricity,
            inclination: rad2deg(atan2(sqrt($momentum[0] ** 2 + $momentum[1] ** 2), $momentum[2])),
            semiMajorAxis: $semiMajorAxis,
            argumentOfPerihelion: self::normalize(rad2deg($argument)),
            meanAnomaly: $meanAnomaly,
            trueAnomaly: self::normalize(rad2deg($trueAnomaly)),
            eccentricAnomaly: self::normalize(rad2deg($eccentricAnomaly)),
            meanLongitude: self::normalize($meanAnomaly + $perihelionLongitude),
            dailyMotion: $dailyMotion,
            siderealPeriod: $period,
            tropicalPeriod: 1.0 / (1.0 / $period + self::precessionPerDay($jdTT)),
            synodicPeriod: self::synodic($period, self::earthYear($jdTT)),
            /* The perihelion passage is the LAST one, not the nearest one, and it comes out of
               the mean anomaly being normalized to [0, 360): the body is `meanAnomaly` degrees
               along counted from there, so it was `meanAnomaly / n` days ago. It is the same
               thing Horizons and Swiss publish, and that is why the one for Saturn in the year
               2000 falls in 1973. */
            perihelionPassage: $jdTT - $meanAnomaly / $dailyMotion,
        );
    }

    /**
     * The MEAN node and the MEAN perihelion of a planet: its averaged orbit, without the periodic
     * perturbations. It is `swe_nod_aps` with `SE_NODBIT_MEAN`.
     *
     * The numbers come out of `MeanElements`, which reads the table by Simon and others (1994)
     * downloaded from ERFA; there it is told where it comes from and what it is cross-checked
     * against. Here only what that table cannot do on its own is done, which is to carry its
     * angles to the frame of the engine.
     *
     * **And that is not precessing the angles: it is rotating the VECTORS and cutting again.** The
     * elements are referred to the ecliptic of J2000, which is a fixed plane, and here the work is
     * in the one of date; a node is the cut of the orbit with the ecliptic, and there are two
     * ecliptics. It is measured against Swiss and the difference is not subtle: cutting with the
     * ecliptic of date, the mean node of Uranus in 1700 lands within **0.01 arcseconds**; rotating
     * the Ω of the table as if it were a point of the sky, within **10,274**. And the one that
     * strays the most is precisely the one with the most tilted orbit, because the angle between
     * the two planes is divided by the tangent of the inclination. It is the same trap `ellipse()`
     * already has written down for the osculating orbit.
     *
     * Against Swiss, the seven planets with a node in 1700, 2000 and 2300: **node 0.98 arcseconds
     * worst case and perihelion latitude 0.09**. The perihelion differs by 12.45 on Neptune, and
     * that one is not a frame error nor truncation but a difference of constant between its table
     * and Simon's: it is worth the same in the three epochs. It is broken down in `MeanElements`.
     *
     * @param Body $body
     * @param float $jdTT Julian day in Terrestrial Time.
     * @return MeanOrbit
     *
     * @throws LogicException If there are no published mean elements for this body.
     */
    public static function mean(Body $body, float $jdTT): MeanOrbit
    {
        $elements = MeanElements::of($body, $jdTT);

        if ($elements === null) {
            throw new LogicException(sprintf(
                'There are no published mean elements for %s: the table by Simon and others (1994) carries the eight '
                .'planets and nobody else, not Pluto, not the asteroids, not the fictitious ones. Its osculating orbit '
                .'is there, in `of()`, and the Moon mean elements in `LunarPoints`. Careful: Swiss '
                .'here returns the OSCULATING ones without warning instead of saying it has none: measured on '
                .'Pluto, its mean and its osculating ones come out identical to the last decimal.',
                $body->name()
            ));
        }

        if ($body === Body::Earth) {
            throw new LogicException(
                'The mean orbit of the Earth has no nodes on the ecliptic, for the same reason the osculating '
                .'one has none: the ecliptic IS that orbit. Its mean elements are in the table, '
                .'because the paper publishes them, and they are read with `MeanElements::of`.'
            );
        }

        $i = deg2rad($elements['i']);
        $om = deg2rad($elements['om']);
        $inPlane = deg2rad($elements['pi'] - $elements['om']);

        $normal = self::toDate([sin($i) * sin($om), -sin($i) * cos($om), cos($i)], $jdTT);

        $perihelion = self::toDate([
            cos($om) * cos($inPlane) - sin($om) * sin($inPlane) * cos($i),
            sin($om) * cos($inPlane) + cos($om) * sin($inPlane) * cos($i),
            sin($inPlane) * sin($i),
        ], $jdTT);

        // The line of nodes, the same as in `of()`: (0,0,1) x normal, written already solved.
        $node = self::unit([-$normal[1], $normal[0], 0.0]);
        $direction = self::unit($perihelion);
        $cosine = self::dot($node, $direction);

        $argument = atan2(self::dot(self::cross($node, $direction), self::unit($normal)), $cosine);

        $a = $elements['a'];
        $e = $elements['e'];
        $parameter = $a * (1 - $e ** 2);

        /* The mean anomaly is a SUBTRACTION of two longitudes of the same frame, so there is no
           need to rotate it: the mean longitude minus the one of the perihelion is worth the same
           in J2000 and in the date. The mean longitude is rebuilt on the already rotated ϖ, so
           that the three of them close with each other as they close in the osculating orbit. */
        $meanAnomaly = self::normalize($elements['l'] - $elements['pi']);
        $nodeLongitude = self::normalize(rad2deg(atan2($node[1], $node[0])));

        return new MeanOrbit(
            body: $body,
            ascendingNode: $nodeLongitude,
            ascendingNodeDistance: $parameter / (1 + $e * $cosine),
            descendingNodeDistance: $parameter / (1 - $e * $cosine),
            perihelion: self::normalize(rad2deg(atan2($perihelion[1], $perihelion[0]))),
            perihelionLatitude: rad2deg(asin($direction[2])),
            perihelionDistance: $a * (1 - $e),
            aphelionDistance: $a * (1 + $e),
            eccentricity: $e,
            inclination: rad2deg(atan2(sqrt($normal[0] ** 2 + $normal[1] ** 2), $normal[2])),
            semiMajorAxis: $a,
            argumentOfPerihelion: self::normalize(rad2deg($argument)),
            meanAnomaly: $meanAnomaly,
            meanLongitude: self::normalize($meanAnomaly + $nodeLongitude + rad2deg($argument)),
            dailyMotion: $elements['n'],
            siderealPeriod: 360.0 / $elements['n'],
        );
    }

    /**
     * The closest and the farthest this body and the Earth can ever get, and how far apart they
     * are now. It is `swe_orbit_max_min_true_distance`.
     *
     * **The extrema are really geometric: the greatest and the smallest distance between the two
     * ELLIPSES**, searched on both orbits at once and with their inclinations in place. They are
     * not the aphelion of one plus the aphelion of the other, which is the quick computation and
     * comes out too big: two aphelia only add up if they fall in opposite directions, and the
     * lines of apsides are where they are. Measured on Jupiter in the year 2000, the quick
     * computation gives 6.4748 astronomical units and the good one 6.4574, that is, seventeen
     * thousandths too much.
     *
     * The heliocentric version of Swiss is not here and is not needed: its three numbers are
     * `aphelionDistance`, `perihelionDistance` and the distance `Ephemeris::heliocentric`
     * returns, already computed. Repeating them here would leave two places where they could
     * diverge.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT Julian day in Terrestrial Time.
     * @return ExtremeDistances
     */
    public static function distances(Body|DownloadableBody $body, float $jdTT): ExtremeDistances
    {
        self::requireOrbitAroundSun($body);

        [$maximum, $minimum] = self::extremaBetween(
            self::frame(self::ellipse($body, $jdTT)),
            self::frame(self::earthEllipse($jdTT))
        );

        return new ExtremeDistances(
            body: $body,
            maximum: $maximum,
            minimum: $minimum,
            current: Ephemeris::position($body, $jdTT)->distance,
        );
    }

    /**
     * The raw osculating ellipse: the state in the ecliptic of date and what comes out of it
     * without any angle taking part.
     *
     * It goes apart from `of()` because **the Earth has to go through here and cannot go through
     * there**. Its ellipse is perfectly well defined (it is the one that gives the year) and what
     * does not exist are its nodes on the ecliptic, which is half of what `of()` returns. The
     * synodic period of any planet is measured against the year of the Earth and the extreme
     * distances against its whole orbit, so both things need it.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return array{mu: float, position: array{float, float, float}, velocity: array{float, float, float}, distance: float, angularMomentum: array{float, float, float}, eccentricityVector: array{float, float, float}, eccentricity: float, semiMajorAxis: float}
     */
    private static function ellipse(Body|DownloadableBody $body, float $jdTT, bool $barycentric = false): array
    {
        $mu = self::mu($body, $barycentric);
        [$positionJ2000, $velocityJ2000] = self::state($body, $jdTT, $mu, $barycentric);

        /* The state is built in J2000 because the derivative needs a frame that stands still (see
           `state`), and it is taken to the ecliptic of date BEFORE anything is drawn out of it.
           It is not the same as drawing the elements in J2000 and precessing the angles: **a node
           is the cut of the orbit with the ecliptic, and there are two ecliptics**. The plane of
           J2000 and the one of date differ by almost fifty arcseconds per century, and that angle
           is divided by the tangent of the inclination when it is carried into a node: on
           Jupiter, which is tilted 1.3 degrees, precessing the node from J2000 instead of cutting
           with the ecliptic of date leaves the node 1.65 degrees off its place in 1700, and on
           Uranus, 2.86. Rotating the whole state is besides safe for the velocity, because it is
           ONE fixed rotation applied to both vectors and not a rotation that changes between the
           samples. */
        $position = self::toDate($positionJ2000, $jdTT);
        $velocity = self::toDate($velocityJ2000, $jdTT);

        $distance = self::norm($position);
        $momentum = self::cross($position, $velocity);

        // e = (v x h)/mu - r/|r|, the eccentricity vector, which points to the perihelion and
        // whose magnitude is the eccentricity itself. It is the same computation as `LunarPoints`.
        $crossed = self::cross($velocity, $momentum);
        $eccentricityVector = [
            $crossed[0] / $mu - $position[0] / $distance,
            $crossed[1] / $mu - $position[1] / $distance,
            $crossed[2] / $mu - $position[2] / $distance,
        ];

        $eccentricity = self::norm($eccentricityVector);

        // Vis-viva: the semi-major axis comes out of the energy, without going through any angle.
        $semiMajorAxis = 1 / (2 / $distance - self::dot($velocity, $velocity) / $mu);

        /* An open orbit has no semi-major axis, no period, no aphelion and no mean anomaly that
           resemble what those fields return: the three anomalies turn hyperbolic and the period
           stops existing. It does not happen to any body of the catalogue (the most eccentric one
           is Nibiru, with 0.98, and it is fictitious), so it warns instead of serving an
           impossible ellipse that looks perfectly fine. */
        if ($eccentricity >= 1.0 || $semiMajorAxis <= 0.0) {
            throw new LogicException(sprintf(
                'The osculating orbit of %s at this instant is not an ellipse (eccentricity %.6f): '
                .'it has no period and no aphelion, and its anomalies would be hyperbolic.',
                $body->name(),
                $eccentricity
            ));
        }

        return [
            'mu' => $mu,
            'position' => $position,
            'speed' => $velocity,
            'distance' => $distance,
            'moment' => $momentum,
            'eccentricityVector' => $eccentricityVector,
            'eccentricity' => $eccentricity,
            'semiMajorAxis' => $semiMajorAxis,
        ];
    }

    /**
     * The synodic period: how often the same configuration with the Earth repeats, that is, from
     * one opposition to the next.
     *
     * It comes out of subtracting the two angular velocities, and that is why it blows up when the
     * two periods are alike: a body with the year of the Earth would never get ahead of it. That
     * does not happen here with anything real, and if it did the number would be huge and not
     * false.
     *
     * Swiss returns it with a SIGN, negative for the inner planets and for the Moon. Here it goes
     * positive: a negative period is not a period, and which one overtakes which is already said
     * by the two semi-major axes.
     *
     * @param float $period
     * @param float $year
     * @return float
     */
    private static function synodic(float $period, float $year): float
    {
        $difference = abs(1.0 / $period - 1.0 / $year);

        return $difference > 0.0 ? 1.0 / $difference : INF;
    }

    /**
     * The orbit of the Earth at this instant, drawn like that of any other body and not out of a
     * constant.
     *
     * **The last one is remembered, and that is enough**: whoever asks for this asks for the nine
     * bodies of the same instant, so the Earth is computed once and the other eight find it
     * done. Without the memo, each orbit would cost twice as much, because the state of the Earth
     * is the same five ephemerides as that of the body.
     *
     * @param float $jdTT
     * @return array<string, mixed>
     */
    private static function earthEllipse(float $jdTT): array
    {
        if (self::$yearInstant !== $jdTT) {
            self::$yearInstant = $jdTT;
            self::$ofTheEarth = self::ellipse(Body::Earth, $jdTT);
        }

        return self::$ofTheEarth;
    }

    /**
     * The sidereal year, in days, drawn from that same ellipse.
     *
     * @param float $jdTT
     * @return float
     */
    private static function earthYear(float $jdTT): float
    {
        $ellipse = self::earthEllipse($jdTT);

        return 360.0 / rad2deg(sqrt($ellipse['mu'] / $ellipse['semiMajorAxis'] ** 3));
    }

    /**
     * How much the equinox goes back in a day, in whole turns.
     *
     * It is what separates the tropical period from the sidereal one: the body has to go somewhat
     * less than a whole turn because the point it is measured from comes to meet it. It comes out
     * of differentiating `Time::generalPrecession`, which is the same precession with which the
     * engine rotates everything else, and it is differentiated instead of repeating its
     * coefficients so that they do not go stale here the day they are touched there.
     *
     * The check that this is what it is comes from the Earth itself: with its sidereal year of
     * 365.2564 days it gives 365.2422, which is the published tropical year.
     *
     * @param float $jdTT
     * @return float
     */
    private static function precessionPerDay(float $jdTT): float
    {
        $centuries = Time::centuries($jdTT);
        $step = 1.0e-4;

        // Arcseconds per Julian century, and from there to turns per day.
        $rate = (Time::generalPrecession($centuries + $step) - Time::generalPrecession($centuries - $step))
            / (2 * $step);

        return $rate / (1296000.0 * 36525.0);
    }

    /**
     * The ellipse written as the search for extrema needs it: its size, its shape and the two
     * vectors of the plane of the orbit.
     *
     * `p` points to the perihelion and `q` goes ninety degrees ahead INSIDE the plane, in the
     * direction of motion. With those two, a point of the orbit is
     * `a(cos E - e)·p + a√(1-e²) sin E·q`, which is the ellipse written from the focus where the
     * Sun is. They come out of the eccentricity vector and of the angular momentum, that is, out
     * of the same thing the angles come out of, and not out of rebuilding them from those: going
     * round via the angles would be manufacturing a second definition of the same orbit.
     *
     * @param array{eccentricity: float, semiMajorAxis: float, angularMomentum: array{float, float, float}, eccentricityVector: array{float, float, float}} $ellipse
     * @return array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}}
     */
    private static function frame(array $ellipse): array
    {
        $p = self::unit($ellipse['eccentricityVector']);

        return [
            'a' => $ellipse['semiMajorAxis'],
            'e' => $ellipse['eccentricity'],
            'p' => $p,
            'q' => self::cross(self::unit($ellipse['moment']), $p),
        ];
    }

    /**
     * A point of the ellipse, by its eccentric anomaly.
     *
     * It is walked in ECCENTRIC anomaly and not in true anomaly because that is the one that
     * spreads the points evenly around the ellipse. With the true one, an orbit like that of Pluto
     * would pile almost every sample at the perihelion and would leave the aphelion, which is
     * where the extrema being looked for are, with four points.
     *
     * @param array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}} $frame
     * @param float $eccentricAnomaly Radians.
     * @return array{float, float, float}
     */
    private static function ellipsePoint(array $frame, float $eccentricAnomaly): array
    {
        $x = $frame['a'] * (cos($eccentricAnomaly) - $frame['e']);
        $y = $frame['a'] * sqrt(1 - $frame['e'] ** 2) * sin($eccentricAnomaly);

        return [
            $x * $frame['p'][0] + $y * $frame['q'][0],
            $x * $frame['p'][1] + $y * $frame['q'][1],
            $x * $frame['p'][2] + $y * $frame['q'][2],
        ];
    }

    /**
     * The greatest and the smallest distance between two ellipses.
     *
     * It is done in two stages, and the first one cannot be skipped: **a coarse sweep of both
     * orbits at once** to know which zone each extremum is in, and then local refinement. The
     * comfortable alternative, starting the refinement from a reasonable configuration (the two
     * aphelia facing each other, say), falls on the wrong extremum as soon as the two lines of
     * apsides are not aligned, and it gives no error at all: it gives a number that looks fine.
     *
     * @param array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}} $one
     * @param array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}} $other
     * @return array{float, float}
     */
    private static function extremaBetween(array $one, array $other): array
    {
        $step = 2 * M_PI / self::ELLIPSE_SAMPLES;

        $fromOne = [];
        $fromOther = [];

        for ($i = 0; $i < self::ELLIPSE_SAMPLES; $i++) {
            $fromOne[$i] = self::ellipsePoint($one, $i * $step);
            $fromOther[$i] = self::ellipsePoint($other, $i * $step);
        }

        $maximum = [-INF, 0.0, 0.0];
        $minimum = [INF, 0.0, 0.0];

        for ($i = 0; $i < self::ELLIPSE_SAMPLES; $i++) {
            for ($j = 0; $j < self::ELLIPSE_SAMPLES; $j++) {
                $separation = self::separation($fromOne[$i], $fromOther[$j]);

                if ($separation > $maximum[0]) {
                    $maximum = [$separation, $i * $step, $j * $step];
                }

                if ($separation < $minimum[0]) {
                    $minimum = [$separation, $i * $step, $j * $step];
                }
            }
        }

        return [
            self::refineExtremum($one, $other, $maximum, $step, true),
            self::refineExtremum($one, $other, $minimum, $step, false),
        ];
    }

    /**
     * Tightens an extremum found by brute force, narrowing the window to half on each turn over
     * both anomalies at once.
     *
     * @param array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}} $one
     * @param array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}} $other
     * @param array{float, float, float} $best Distance and the two anomalies where it was found.
     * @param float $window
     * @param bool $upwards
     * @return float
     */
    private static function refineExtremum(array $one, array $other, array $best, float $window, bool $upwards): float
    {
        [$value, $here, $there] = $best;

        for ($turn = 0; $turn < self::REFINEMENT_ROUNDS; $turn++) {
            $bestHere = $here;
            $bestThere = $there;

            for ($i = -2; $i <= 2; $i++) {
                for ($j = -2; $j <= 2; $j++) {
                    $candidateHere = $here + $i * $window / 2;
                    $candidateThere = $there + $j * $window / 2;

                    $separation = self::separation(
                        self::ellipsePoint($one, $candidateHere),
                        self::ellipsePoint($other, $candidateThere)
                    );

                    if ($upwards ? $separation > $value : $separation < $value) {
                        $value = $separation;
                        $bestHere = $candidateHere;
                        $bestThere = $candidateThere;
                    }
                }
            }

            $here = $bestHere;
            $there = $bestThere;
            $window /= 2;
        }

        return $value;
    }

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     * @return float
     */
    private static function separation(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /**
     * Heliocentric position and velocity, geometric and in the ecliptic of J2000.
     *
     * **The frame has to be INERTIAL, and that is the bug you step on without noticing.** The
     * ecliptic of date rotates: precession moves it fifty arcseconds a year and nutation shakes it
     * seventeen from one side to the other every eighteen years and a half. Differentiating
     * positions written in a frame that rotates puts the rotation of the frame INSIDE the
     * velocity, and out of that comes an orbit that is not the one of the body. It is measured
     * against Horizons, on the perihelion of Neptune in the year 2000: with the positions in the
     * ecliptic of date it comes out 187,110 arcseconds off (that is, 52 degrees), and leaving the
     * nutation in and precessing the rest, 21,898. With both things taken out, 15. The reason it
     * bites so hard is the usual one: the error of the eccentricity vector is divided by the
     * eccentricity, and Neptune has it at 0.011.
     *
     * The derivative goes by centred differences of FOUR points and not of two. The two-point one
     * leaves Mercury at 10.4 arcseconds of perihelion; the four-point one, at 0.11. It comes free:
     * four evaluations instead of two.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param float $mu
     * @return array{0: array{float, float, float}, 1: array{float, float, float}}
     */
    private static function state(Body|DownloadableBody $body, float $jdTT, float $mu, bool $barycentric = false): array
    {
        // The light-time seed is shared between the five samples: it changes so little from one to
        // the next that from the first one on it converges in a single turn.
        $delay = 0.0;

        $centre = self::geometricJ2000($body, $jdTT, $delay, $barycentric);
        $step = self::step(self::norm($centre), $mu);

        $minusTwo = self::geometricJ2000($body, $jdTT - 2 * $step, $delay, $barycentric);
        $minusOne = self::geometricJ2000($body, $jdTT - $step, $delay, $barycentric);
        $plusOne = self::geometricJ2000($body, $jdTT + $step, $delay, $barycentric);
        $plusTwo = self::geometricJ2000($body, $jdTT + 2 * $step, $delay, $barycentric);

        $velocity = [];

        for ($axis = 0; $axis < 3; $axis++) {
            $velocity[$axis] = ($minusTwo[$axis] - 8 * $minusOne[$axis] + 8 * $plusOne[$axis] - $plusTwo[$axis])
                / (12 * $step);
        }

        return [$centre, $velocity];
    }

    /**
     * The step of the derivative, in days.
     *
     * It comes out of the cap and of the fraction of the orbit, with the period estimated by
     * Kepler's third law on the distance to the Sun. The distance is not the semi-major axis
     * unless the orbit is round, and it does not matter: here it is only used so as not to overdo
     * it with a fast body, and the third law gets the order of magnitude right more than enough
     * for that.
     *
     * @param float $distance
     * @param float $mu
     * @return float
     */
    private static function step(float $distance, float $mu): float
    {
        return min(self::MAX_STEP, self::ORBIT_FRACTION * sqrt($distance ** 3 / $mu));
    }

    /**
     * The GEOMETRIC heliocentric position of instant `$jd`, carried to the ecliptic of J2000.
     *
     * Two things that have to be undone to `Ephemeris::heliocentric`, and both because an orbital
     * element describes where the orbit IS and not where it is looked at from:
     *
     * **The light time.** `heliocentric` returns the body where it was when the light that now
     * reaches the Sun set out, which on Neptune is four hours and on Pluto five and a half.
     * Undoing it needs no new datum, because the answer itself carries the distance: the instant
     * asked for is the one being looked for PLUS the delay, and it converges in two turns. Without
     * undoing it, the perihelion of Neptune comes out 16.4 arcseconds off and the semi-major axis
     * of Pluto 1.8e-4 astronomical units.
     *
     * **The nutation, and at the instant the engine applies it.** `heliocentric` rotates the
     * longitude by the nutation of the REQUESTED instant, not the one of the instant the position
     * corresponds to, which is the requested one minus the delay. That is the right thing for an
     * apparent position, because the frame is the true equinox of the date of observation, and it
     * is a trap for what is done here, where the requested instant is moved on purpose. Taking out
     * the nutation of the geometric instant instead of the one of the requested instant, a
     * spurious rotation appears and slips into the velocity: measured, it leaves the position of
     * Pluto 2.5e-7 astronomical units from the JPL instead of 4.9e-10, and the velocity of Neptune
     * with 2.7e-4 of relative error instead of 6.6e-6. It is the kind of bug that gives no error
     * and does not show on a wheel.
     *
     * @param Body|DownloadableBody $body
     * @param float $jd Geometric instant being looked for, in Terrestrial Time.
     * @param float $delay Light-time seed, in days. It is updated.
     * @return array{float, float, float}
     */
    private static function geometricJ2000(Body|DownloadableBody $body, float $jd, float &$delay, bool $barycentric = false): array
    {
        $requested = $jd + $delay;
        $position = self::seenFrom($body, $requested, $barycentric);

        for ($turn = 0; $turn < 8; $turn++) {
            $updated = self::LIGHT_PER_AU * $position->distance;

            if (abs($updated - $delay) < 1e-12) {
                break;
            }

            $delay = $updated;
            $requested = $jd + $delay;
            $position = self::seenFrom($body, $requested, $barycentric);
        }

        $vector = self::rectangular($position);

        /* **The Earth comes in through its BARYCENTRE WITH THE MOON, and it is not a refinement:
           without that its orbit comes out wrong at a glance.** The centre of the Earth goes round
           that barycentre, 4,700 kilometres of radius every 27 days, and that is 12.5 metres per
           second of wobble on top of the 29.8 kilometres per second of the revolution. The
           semi-major axis comes out of the energy, that is, of the square of the velocity, so a
           wobble of four ten-thousandths in the velocity turns into eight ten-thousandths of an
           astronomical unit of semi-major axis, and on top of it changing sign every fortnight.
           Measured: with the centre of the Earth, its osculating year came out at 365.50 days and
           the synodic period of Mercury, which is the one that notices it most, moved half a day
           from one week to the next. With the barycentre, the year comes out 365.2564 and the
           synodic period of Mercury 115.877, which is the published one.

           What orbits the Sun is the barycentre; the planet merely goes along with it. That is why
           what VSOP87 and the JPL tables represent for the other planets is their barycentre too,
           and the Earth is the only exception of the engine, which goes by `399` because a chart
           is cast from the planet and not from a point 4,700 kilometres away from it. Here, and
           only here, it is needed the other way round.

           It is built the same way as in `Ephemeris::barycentricSun`: the Earth plus the geocentric
           Moon divided by one plus EMRAT. The two heliocentric ones carry their own light time from
           the Sun and differ by 1.3 seconds between them, which on the barycentre is 160 metres. */
        if ($body === Body::Earth) {
            $weight = 1 / (1 + (self::$masses ??= require DataFolder::path('masses.php'))['emrat']);
            $moon = self::rectangular(self::seenFrom(Body::Moon, $requested, $barycentric));

            $vector = [
                $vector[0] * (1 - $weight) + $moon[0] * $weight,
                $vector[1] * (1 - $weight) + $moon[1] * $weight,
                $vector[2] * (1 - $weight) + $moon[2] * $weight,
            ];
        }

        $vector = self::longitudeRotation($vector, -Time::nutation(Time::centuries($requested))[0]);

        return Precession::toJ2000($vector, Time::centuries($jd));
    }

    /**
     * The position of the body from whichever origin applies, which is the ONLY thing that changes
     * between a heliocentric orbit and a barycentric one.
     *
     * Everything else (the light time, the nutation, the rotation to J2000, the derivative, the
     * eccentricity vector, vis-viva) is identical and knows nothing about where it is being looked
     * at from. That is why the frame travels as a boolean all the way here instead of there being
     * two parallel paths: two copies of this chain would be two places to fix the same bug, and
     * this class already had one of those with the nutation of the requested instant.
     *
     * @param Body|DownloadableBody $body
     * @param float $jd
     * @param bool $barycentric
     * @return Position
     */
    private static function seenFrom(Body|DownloadableBody $body, float $jd, bool $barycentric): Position
    {
        return $barycentric
            ? Ephemeris::barycentric($body, $jd)
            : Ephemeris::heliocentric($body, $jd);
    }

    /**
     * A spherical position written as a vector.
     *
     * @param Position $position
     * @return array{float, float, float}
     */
    private static function rectangular(Position $position): array
    {
        $longitude = deg2rad($position->longitude);
        $latitude = deg2rad($position->latitude);

        return [
            $position->distance * cos($latitude) * cos($longitude),
            $position->distance * cos($latitude) * sin($longitude),
            $position->distance * sin($latitude),
        ];
    }

    /**
     * From the ecliptic of J2000 to the true one of date, which is where `Ephemeris` leaves the
     * planets. Precession and nutation, the same path back and in the same order.
     *
     * @param array{float, float, float} $vector
     * @param float $jdTT
     * @return array{float, float, float}
     */
    private static function toDate(array $vector, float $jdTT): array
    {
        $centuries = Time::centuries($jdTT);

        return self::longitudeRotation(
            Precession::toDate($vector, $centuries),
            Time::nutation($centuries)[0]
        );
    }

    /**
     * Rotates the POINT around the axis of the ecliptic, which is adding the angle to its
     * longitude.
     *
     * Careful with the sign: `Precession::rotateInLongitude`, which is private, rotates the
     * SYSTEM, that is, the opposite. Here the point is rotated because that is what `Ephemeris`
     * does when it adds the nutation to the longitude, and that is what has to be undone.
     *
     * @param array{float, float, float} $vector
     * @param float $angle Radians.
     * @return array{float, float, float}
     */
    private static function longitudeRotation(array $vector, float $angle): array
    {
        $cosine = cos($angle);
        $sine = sin($angle);

        return [
            $cosine * $vector[0] - $sine * $vector[1],
            $sine * $vector[0] + $cosine * $vector[1],
            $vector[2],
        ];
    }

    /**
     * The gravitational constant of the orbit: the GM of the Sun PLUS the one of the body.
     *
     * It is not a refinement, it is the difference between getting it right and not: the
     * heliocentric orbit is the one of the RELATIVE motion of the two, and its constant is the sum
     * of the two masses. The eccentricity vector carries mu dividing, so a mu with a relative
     * error `d` shifts the perihelion by `d` divided by the eccentricity. Measured in the year
     * 2000 with the bare GM of the Sun: **Jupiter goes off by 0.39 degrees, Neptune 0.26 and
     * Saturn 0.20**, and all of them with a perfectly believable sign and value. It is also what
     * Horizons does, which prints the sum in the header of its elements ("Keplerian GM").
     *
     * The masses are the DE440 ones `astro:masas` downloads, the same ones `Ephemeris` computes
     * the barycentre with. Of Chiron, Pholus and the four asteroids there is no mass in that
     * header and none is needed either: the one of Ceres, which is the largest of the six, is five
     * ten-billionths of the one of the Sun. Horizons does the same with them, and its "Keplerian
     * GM" for an asteroid is the bare one of the Sun. And the nineteen fictitious ones have no
     * mass because they do not exist.
     *
     * ## And the one of a BARYCENTRIC orbit, which is neither of the two things one would write
     *
     * Around the barycentre there is no mass at all, so neither the GM of the Sun nor the one of
     * everything together is any good there. What there is is the same two-body problem seen from
     * the centre of mass: the body on one side and EVERYTHING ELSE on the other, going round each
     * other. With `M` the mass of the whole solar system and `m` the one of the body, the rest
     * weighs `M - m` and the orbit of the body with respect to the barycentre comes out with
     * **`μ = (M - m)³ / M²`**.
     *
     * It is not an armchair deduction: **it agrees to nine figures with the "Keplerian GM" that
     * Horizons itself prints when it is asked for elements with `CENTER='500@0'`**. Neptune,
     * 2.96263547e-4 computed against 2.96263547e-4 published; Saturn, 2.96055556e-4 against
     * 2.96055556e-4. And in passing it explains what otherwise looks like nonsense: the barycentric
     * μ of Jupiter, 2.9546e-4, is SMALLER than the GM of the Sun, because Jupiter is discounted
     * from the central mass; the one of Mercury, 2.9631e-4, is larger, because the eight whole
     * planets are added to it.
     *
     * For a body with no mass in the table the limit comes out on its own: with `m = 0` it is left
     * as `μ = M`, the GM of the whole solar system, which is what Horizons uses for an asteroid.
     *
     * @param Body|DownloadableBody $body
     * @param bool $barycentric Whether the orbit is measured with respect to the barycentre of the solar system.
     * @return float
     */
    private static function mu(Body|DownloadableBody $body, bool $barycentric = false): float
    {
        $gm = (self::$masses ??= require DataFolder::path('masses.php'))['gm'];

        if ($barycentric) {
            $total = array_sum($gm);

            return ($total - self::bodyGm($body, $gm)) ** 3 / $total ** 2;
        }

        return $gm['sun'] + self::bodyGm($body, $gm);
    }

    /**
     * The GM of a body, or zero if the DE440 header does not carry it.
     *
     * @param Body|DownloadableBody $body
     * @param array<string, float> $gm
     * @return float
     */
    private static function bodyGm(Body|DownloadableBody $body, array $gm): float
    {
        /* The Earth comes in through its barycentre with the Moon (see `geometricJ2000`), so its
           mass is the one of the SYSTEM. In the DE440 header that one is `GMB` and here it is
           called `earth-moon`; there is no `earth` entry at all, so without this case the
           `?? 0.0` below would have swallowed it in silence. */
        if ($body === Body::Earth) {
            return $gm['earth-moon'];
        }

        /* Of a body downloaded from the JPL there is no mass in the DE440 header, and it is the
           same case as Chiron and the four asteroids: they weigh so little that Horizons does not
           count them either, and its "Keplerian GM" for an asteroid is the bare one of the Sun. */
        if ($body instanceof DownloadableBody) {
            return 0.0;
        }

        return $gm[$body->value] ?? 0.0;
    }

    /**
     * @param Body|DownloadableBody $body
     * @return void
     */
    private static function requireOrbitAroundSun(Body|DownloadableBody $body): void
    {
        /* A satellite does not orbit the Sun but its planet, so its nodes and its apsides are those
           of that other orbit and do not come out of here. It is the same case as the Moon, which
           is right below. */
        if ($body instanceof Satellite) {
            throw new LogicException(sprintf(
                'The nodes and apsides of %s are those of its orbit around %s, and this computes orbits around the Sun.',
                $body->name(),
                $body->planet()->name()
            ));
        }

        if ($body instanceof DownloadableBody) {
            return;
        }

        if ($body === Body::Sun) {
            throw new LogicException('The Sun has no nodes or apsides: it is the origin of the heliocentric frame, not a body orbiting it.');
        }

        if ($body === Body::Earth) {
            throw new LogicException(
                'The orbit of the Earth has no nodes on the ecliptic: the ecliptic IS that orbit, '
                .'so its inclination is zero but for a wobble of a couple of arcseconds, and the '
                .'line of nodes that comes out of it is noise that looks like data.'
            );
        }

        if ($body === Body::Moon) {
            throw new LogicException(
                'The nodes and apsides of the Moon are those of its orbit around the Earth and are in '
                .'`LunarPoints`. Those of its orbit around the Sun would be the Earth ones, that is the same '
                .'degenerate case.'
            );
        }

        if ($body->isLunarPoint()) {
            throw new LogicException(sprintf(
                '%s is already an element of the Moon orbit: it has no orbit of its own to take nodes or apsides from.',
                $body->name()
            ));
        }

        if ($body->isFictitious() && FictitiousBodies::isGeocentric($body)) {
            throw new LogicException(sprintf(
                '%s orbits the Earth, not the Sun: its heliocentric elements would describe the orbit of the Earth with a wobble on top.',
                $body->name()
            ));
        }
    }

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     * @return array{float, float, float}
     */
    private static function cross(array $a, array $b): array
    {
        return [
            $a[1] * $b[2] - $a[2] * $b[1],
            $a[2] * $b[0] - $a[0] * $b[2],
            $a[0] * $b[1] - $a[1] * $b[0],
        ];
    }

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     * @return float
     */
    private static function dot(array $a, array $b): float
    {
        return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
    }

    /**
     * @param array{float, float, float} $a
     * @return float
     */
    private static function norm(array $a): float
    {
        return sqrt(self::dot($a, $a));
    }

    /**
     * @param array{float, float, float} $a
     * @return array{float, float, float}
     */
    private static function unit(array $a): array
    {
        $magnitude = self::norm($a);

        return [$a[0] / $magnitude, $a[1] / $magnitude, $a[2] / $magnitude];
    }

    /**
     * @param float $degrees
     * @return float
     */
    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }
}
