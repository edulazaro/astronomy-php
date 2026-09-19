<?php

namespace Astronomy;

use InvalidArgumentException;

/**
 * The nineteen bodies that do not exist and still have a position: a postulated ellipse that
 * gets propagated.
 *
 * They are three different things, and wherever they are shown it has to say which one each is:
 *
 * - **The eight of the Hamburg school** (Cupido, Hades, Zeus, Kronos, Apollon, Admetos,
 *   Vulkanus and Poseidon), postulated by Alfred Witte and Friedrich Sieggrün in the twenties
 *   and thirties. They are here because the midpoints were already here (`Midpoint`), which
 *   is the central technique of that same school: having one half of the system and not the
 *   other was an odd hole.
 * - **Four hypothetical ones with followers**: Isis-Transpluto, Selena, Proserpina and Vulcan.
 * - **Four failed predictions and three discarded or pseudoscientific hypotheses**: the
 *   positions that Leverrier, Adams, Lowell and Pickering calculated before Neptune and Pluto
 *   were found, plus Nibiru, Harrington and Waldemath's second moon.
 *
 * **None of them has ever been observed.** They are not in JPL, nor in the minor body
 * catalogue, nor anywhere, so here there is no analytical theory and no table to interpolate:
 * there is a fixed ellipse and not even the perturbation of any other planet is taken into
 * account. Swiss's own documentation warns that this can be worth a degree in the twentieth
 * century and more before that. They come switched off by default, and that is said in the form
 * checkbox and in the text the interpreter receives, just as the three numerology tools say
 * that their arithmetic is a twentieth century method. The elements and where they come from
 * are in `resources/astro/fictitious.php`.
 *
 * The arithmetic is the usual one for a Keplerian orbit: mean anomaly, Kepler's equation,
 * position in the plane of the orbit and three rotations to take it out to the plane of the
 * ecliptic.
 *
 * This class used to be called `Uranianos` and served only the eight of Hamburg. The
 * `seorbel.txt` format that was already being read back then carries two more things that those
 * eight did not use and that are needed now, so they are implemented here and not in a second
 * parallel class: GEOCENTRIC bodies (see `isGeocentric`) and angles given as POLYNOMIALS in
 * time.
 */
class FictitiousBodies
{
    /**
     * Mean motion of a body one astronomical unit from the Sun, in degrees per day.
     *
     * It is the Gaussian gravitational constant (0.01720209895 radians per day) turned into
     * degrees. From it comes the mean motion of the bodies whose mean anomaly arrives without a
     * linear term, by Kepler's third law. Writing it out separately would be nineteen more
     * numbers that can fail to match their own semi-major axis.
     */
    private const MOTION_AT_ONE_AU = 0.9856076686;

    /** @var array<string, array{epoch: float, equinox: float|null, anomaly: list<float>, semiMajorAxis: float, eccentricity: float, perihelion: list<float>, node: list<float>, inclination: float, geocentric: bool}>|null */
    private static ?array $table = null;

    /**
     * The vector from its centre to the body, geometric, in the ecliptic of the DATE and in
     * astronomical units.
     *
     * **From ITS centre, which is not always the Sun.** Seventeen are heliocentric and two,
     * Selena and Waldemath's moon, orbit the EARTH. Whoever calls has to ask `isGeocentric` and
     * add the Earth to those two, which is what `Ephemeris::geometricHeliocentric` does and by
     * the same route as the Moon, so that the correction of the Earth towards JPL is applied
     * once only and in a single place. Always returning a heliocentric one here would have
     * forced this class to ask for the Earth, and then there would be two places that know
     * where each body comes from instead of one.
     *
     * **The last rotation is not decorative.** The elements of nearly all of them are referred
     * to the mean ecliptic and equinox of another epoch (1900 the eight of Hamburg, 1850 the
     * predictions of Neptune, 1930 those of Pluto), so the ellipse comes out drawn on a plane
     * that is no longer today's: it has to be precessed to the equinox of the date. That is
     * nearly a degree and a half per century, meaning that without that rotation the chart
     * would come out perfect at the epoch of the elements and half a dozen degrees wrong in
     * 2400. The same happens to the Moon of ELP. It is done in two hops, to the equinox of
     * J2000 and from there to the date, because `Precession` is written around J2000 and
     * composing two of its rotations is exact; inventing a direct rotation here would be a
     * third precession model in the same engine, which is exactly what the docblock of
     * `Precession` warns against doing.
     *
     * The three that carry `equinoccio` as null are the ones that in `seorbel.txt` carry
     * `JDATE`: their angles are already referred to the equinox of the date and there is
     * nothing to rotate.
     *
     * @param Body $body
     * @param float $jdTT Julian day in Terrestrial Time.
     * @return array{0: float, 1: float, 2: float}
     */
    public static function rectangular(Body $body, float $jdTT): array
    {
        $elements = self::elements($body);

        $a = $elements['semiMajorAxis'];
        $e = $elements['eccentricity'];

        /* T is Julian centuries from THE EPOCH OF THIS BODY, not from J2000, and it is measured:
           counting it from J2000, Waldemath comes out 177 degrees away from Swiss and Vulcano
           16. It does not show up with just any body, because the epoch of Selena IS J2000 and
           with it both readings give the same thing. */
        $t = ($jdTT - $elements['epoch']) / 36525.0;

        $meanAnomaly = deg2rad(self::normalize(
            count($elements['anomaly']) > 1
                ? self::polynomial($elements['anomaly'], $t)
                : $elements['anomaly'][0] + self::dailyMotion($body) * ($jdTT - $elements['epoch'])
        ));

        $eccentricAnomaly = self::solveKepler($meanAnomaly, $e);

        // The plane of the orbit, with the x axis towards the perihelion.
        $x = $a * (cos($eccentricAnomaly) - $e);
        $y = $a * sqrt(1 - $e * $e) * sin($eccentricAnomaly);

        $vector = self::rotate(
            $x,
            $y,
            self::polynomial($elements['perihelion'], $t),
            self::polynomial($elements['node'], $t),
            $elements['inclination']
        );

        if ($elements['equinox'] === null) {
            return $vector;
        }

        // From the equinox of the elements to that of the date, by way of J2000.
        return Precession::toDate(
            Precession::toJ2000($vector, ($elements['equinox'] - Time::J2000) / 36525.0),
            Time::centuries($jdTT)
        );
    }

    /**
     * Whether its elements describe an orbit around the EARTH and not around the Sun.
     *
     * They are two of the nineteen, Selena and Waldemath's moon, and `seorbel.txt` marks it
     * with the word `geo` at the end of the line. **A geocentric body treated as heliocentric
     * comes out one astronomical unit away from where it belongs and looking every bit like a
     * normal planet in some sign or other**, which is the kind of failure you do not see by
     * looking at a wheel. Waldemath is the one it hurts most: its vector measures seven
     * thousandths of an astronomical unit and what would be added to it on top is the whole
     * unit.
     *
     * @param Body $body
     * @return bool
     */
    public static function isGeocentric(Body $body): bool
    {
        return self::elements($body)['geocentric'];
    }

    /**
     * The mean motion, in degrees per day.
     *
     * From two different places, and the elements file says which one: if the mean anomaly
     * carries a linear term, that term IS the mean motion (in degrees per Julian century); if it
     * comes on its own, it comes out of the semi-major axis by Kepler's third law.
     *
     * **And the third law is only good for the heliocentric ones.** The Gaussian constant has
     * the mass of the Sun inside it, so applying it to a body that orbits the Earth would give a
     * period hundreds of times shorter. The two geocentric ones there are carry their linear
     * term, so the case does not arise; if some day one is added that does not carry it, this
     * throws instead of returning a made up number.
     *
     * @param Body $body
     * @return float
     */
    public static function dailyMotion(Body $body): float
    {
        $elements = self::elements($body);

        if (count($elements['anomaly']) > 1) {
            return $elements['anomaly'][1] / 36525.0;
        }

        if ($elements['geocentric']) {
            throw new InvalidArgumentException(sprintf(
                '%s orbits the Earth and its elements carry no mean motion: the third law '
                .'of Kepler has the mass of the Sun inside it and is no use here.',
                $body->name()
            ));
        }

        $a = $elements['semiMajorAxis'];

        return self::MOTION_AT_ONE_AU / ($a * sqrt($a));
    }

    /**
     * How long it takes to go round, in Julian years.
     *
     * It comes out of its own elements and not out of a separate list, which is the check
     * against the definition: if the semi-major axis were mistyped, the period would stop being
     * the published one.
     *
     * @param Body $body
     * @return float
     */
    public static function periodYears(Body $body): float
    {
        return 360.0 / self::dailyMotion($body) / 365.25;
    }

    /**
     * The elements of a body.
     *
     * @param Body $body
     * @return array{epoch: float, equinox: float|null, anomaly: list<float>, semiMajorAxis: float, eccentricity: float, perihelion: list<float>, node: list<float>, inclination: float, geocentric: bool}
     */
    public static function elements(Body $body): array
    {
        $bodies = self::table();

        if (! isset($bodies[$body->value])) {
            throw new InvalidArgumentException(sprintf(
                '%s exists: it is not one of the bodies propagated from postulated elements.',
                $body->name()
            ));
        }

        return $bodies[$body->value];
    }

    /**
     * An angle that may come given as a polynomial in time, in degrees.
     *
     * Three of the nineteen need it: Vulcano and Waldemath give the mean anomaly, the perihelion
     * and the node as `a + b*T`, and Selena the anomaly. The other sixteen carry a single
     * number, that is, a polynomial of one term, and that is why they are all stored the same
     * way: a list with a single coefficient is not a special case anyone has to remember.
     *
     * @param list<float> $coefficients
     * @param float $t Julian centuries from the epoch of the elements.
     * @return float
     */
    private static function polynomial(array $coefficients, float $t): float
    {
        $value = 0.0;

        foreach (array_reverse($coefficients) as $coefficient) {
            $value = $value * $t + $coefficient;
        }

        return $value;
    }

    /**
     * The eccentric anomaly: the root of E minus e times the sine of E equals M.
     *
     * Newton with a bisection safeguard, and the safeguard is not superfluous. The first eighteen
     * bodies have nearly circular orbits (the largest eccentricity is 0.31) and with them bare
     * Newton converges in two turns. **Nibiru has 0.981**, that is, a nearly parabolic orbit,
     * and there Newton starting at the mean anomaly jumps out of the interval and oscillates
     * without converging. The equation has one root and only one in [0, 2π), so it is enough to
     * keep narrowing the interval by the sign of the residual and to fall on the midpoint every
     * time Newton's step goes outside: that always converges.
     *
     * @param float $meanAnomaly In radians, already normalized to [0, 2π).
     * @param float $eccentricity
     * @return float In radians.
     */
    private static function solveKepler(float $meanAnomaly, float $eccentricity): float
    {
        $low = 0.0;
        $high = 2 * M_PI;

        // For small eccentricities the mean anomaly is already almost the answer. For the large
        // ones it starts at the centre of the interval, which is the one starting point that
        // cannot leave it.
        $eccentricAnomaly = $eccentricity < 0.8
            ? $meanAnomaly + $eccentricity * sin($meanAnomaly)
            : M_PI;

        for ($iteration = 0; $iteration < 200; $iteration++) {
            $residual = $eccentricAnomaly - $eccentricity * sin($eccentricAnomaly) - $meanAnomaly;

            if ($residual > 0) {
                $high = $eccentricAnomaly;
            } else {
                $low = $eccentricAnomaly;
            }

            $derivative = 1 - $eccentricity * cos($eccentricAnomaly);
            $next = abs($derivative) > 1e-12 ? $eccentricAnomaly - $residual / $derivative : $eccentricAnomaly;

            if ($next <= $low || $next >= $high) {
                $next = ($low + $high) / 2;
            }

            if (abs($next - $eccentricAnomaly) < 1e-15) {
                return $next;
            }

            $eccentricAnomaly = $next;
        }

        return $eccentricAnomaly;
    }

    /**
     * From the plane of the orbit to that of the ecliptic of the elements.
     *
     * Three rotations and in this order: the argument of the perihelion around the axis of the
     * orbit, the inclination around the line of nodes and the node around the axis of the
     * ecliptic. Changing the order gives a perfectly believable position somewhere else.
     *
     * @param float $x In the plane of the orbit, towards the perihelion.
     * @param float $y
     * @param float $perihelion Argument of the perihelion, in degrees.
     * @param float $node Longitude of the ascending node, in degrees.
     * @param float $inclination In degrees.
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rotate(float $x, float $y, float $perihelion, float $node, float $inclination): array
    {
        $cw = cos(deg2rad($perihelion));
        $sw = sin(deg2rad($perihelion));
        $cn = cos(deg2rad($node));
        $sn = sin(deg2rad($node));
        $ci = cos(deg2rad($inclination));
        $si = sin(deg2rad($inclination));

        // From the perihelion to the node.
        $xn = $x * $cw - $y * $sw;
        $yn = $x * $sw + $y * $cw;

        // The plane of the orbit is lifted.
        $ye = $yn * $ci;
        $ze = $yn * $si;

        // And the node is taken to its longitude.
        return [$xn * $cn - $ye * $sn, $xn * $sn + $ye * $cn, $ze];
    }

    /**
     * @param float $degrees
     * @return float
     */
    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }

    /**
     * @return array<string, array{epoch: float, equinox: float|null, anomaly: list<float>, semiMajorAxis: float, eccentricity: float, perihelion: list<float>, node: list<float>, inclination: float, geocentric: bool}>
     */
    private static function table(): array
    {
        return self::$table ??= require DataFolder::path('fictitious.php');
    }
}
