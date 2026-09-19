<?php

namespace Astronomy;

use RuntimeException;

/**
 * The lunar nodes and Lilith.
 *
 * They are not bodies: they are elements of the Moon's orbit, and that is why they do not
 * come from a table or from a series of their own. The nodes are the two points where the lunar
 * orbit crosses the ecliptic; Lilith is its apogee, the farthest end.
 *
 * There are two versions of each one and they do not give the same thing:
 *
 * - Mean: the averaged value, which advances at a constant rate. The mean node moves
 * backwards 1934.14 degrees per century, always, with no ups and downs.
 * - True: the one of the instantaneous orbit, computed from the position vector and the
 * velocity vector of the Moon at that moment. It oscillates around the mean one, up to a degree
 * and a half in the node and up to thirty degrees in Lilith.
 *
 * And of Lilith there is a third one, the interpolated one, with its opposite Priapus. It is
 * the one Astrodienst takes to be the physically correct one: the position of the Moon at its real
 * apogee (or perigee), followed continuously between one passage and the next. It oscillates five
 * degrees around the mean one at the apogee and twenty-five at the perigee, against the thirty of
 * the osculating one. How it is computed, and why it is not computed the way its name suggests, is
 * in `interpolatedLilith()`.
 *
 * The advantage of them being orbital elements and not bodies is that they can be checked
 * against their own definition, without asking anyone for anything: the true node has to match
 * the longitude of the Moon at the instant when its latitude crosses zero northwards, and the two
 * non-mean Liliths the longitude of the Moon at the instant of the apogee. That is exactly what
 * the tests do.
 */
class LunarPoints
{
    /**
     * Gravitational constant of the Earth-Moon system, in AU³/day².
     *
     * It is needed for the eccentricity vector, which is where the apogee comes from. It comes
     * from adding the masses of the Earth and the Moon: the apogee is that of the orbit of the two
     * around their common centre, not that of the Moon around a motionless Earth.
     */
    private const MU = 8.997011e-10;

    /** Step used to derive the velocity of the Moon, in days. */
    private const STEP = 0.05;

    /**
     * How far the interpolated apsis is allowed to drift from the mean one, in radians.
     *
     * Swiss measures twenty-five degrees of amplitude at the perigee. Sixty leaves plenty of
     * margin and at the same time keeps the secant method, if it ever ran away, from returning an
     * apsis on the other side of the orbit looking perfectly fine.
     */
    private const REACH = 1.0471975511965976;

    /** @var array{w1: list<float>, w2: list<float>, w3: list<float>, precession: float, rad: float, distance_scale: float}|null */
    private static ?array $constants = null;

    /**
     * The longitude and distance series of ELP, each term with the multiplier of the Moon's mean
     * longitude in its argument. It is what makes it possible to "shift" the Moon along its orbit
     * while leaving the Sun still. See `shiftedSeries()`.
     *
     * @var array<string, array<int, list<array{float, float, float, float, float, float, int}>>>|null
     */
    private static ?array $shiftedSeries = null;

    /**
     * The mean north node.
     *
     * @param float $jdTT
     * @return float
     */
    public static function meanNode(float $jdTT): float
    {
        return self::fromMeanLongitude('w3', $jdTT);
    }

    /**
     * Mean Lilith: the apogee of the mean orbit, that is, the mean perigee plus half a turn.
     *
     * The mean perigee has no body of its own and does not need one: it is exactly this minus one
     * hundred and eighty degrees, with no other difference. The same holds for the osculating
     * perigee with respect to `trueLilith()`. The only perigee that is NOT its Lilith turned
     * around is the interpolated one, and that is why that one does have a body: Priapus.
     *
     * @param float $jdTT
     * @return float
     */
    public static function meanLilith(float $jdTT): float
    {
        return self::normalize(self::fromMeanLongitude('w2', $jdTT) + 180);
    }

    /**
     * The true north node.
     *
     * It comes from the angular momentum of the instantaneous orbit. The vector `r × v` is
     * perpendicular to the plane in which the Moon is moving right now, and the line of nodes is
     * the intersection of that plane with the ecliptic: that is, perpendicular at once to the
     * angular momentum and to the axis of the ecliptic.
     *
     * @param float $jdTT
     * @return float
     */
    public static function trueNode(float $jdTT): float
    {
        [, $angularMomentum] = self::state($jdTT);

        // The cross product of the axis of the ecliptic with the angular momentum, written out
        // already: (0,0,1) x (hx,hy,hz) = (-hy, hx, 0).
        return self::normalize(rad2deg(atan2($angularMomentum[0], -$angularMomentum[1])));
    }

    /**
     * True Lilith: the apogee of the instantaneous orbit.
     *
     * It comes from the eccentricity vector, which points at the perigee and whose length is the
     * eccentricity of the orbit itself. The apogee is right opposite.
     *
     * Mind what this means: the instantaneous orbit of the Moon changes fast because the Sun is
     * pulling at it all the time, so true Lilith lurches by tens of degrees and can move backwards
     * for weeks. It is not a computation error, it is the nature of the point, and it is the
     * reason many astrologers prefer the mean one.
     *
     * @param float $jdTT
     * @return float
     */
    public static function trueLilith(float $jdTT): float
    {
        [$position, $angularMomentum, $velocity] = self::state($jdTT);

        $distance = sqrt($position[0] ** 2 + $position[1] ** 2 + $position[2] ** 2);

        // e = (v x h)/mu - r/|r|, which points at the perigee.
        $crossed = self::cross($velocity, $angularMomentum);

        $perigee = [
            $crossed[0] / self::MU - $position[0] / $distance,
            $crossed[1] / self::MU - $position[1] / $distance,
            $crossed[2] / self::MU - $position[2] / $distance,
        ];

        // The apogee is opposite the perigee.
        return self::normalize(rad2deg(atan2(-$perigee[1], -$perigee[0])));
    }

    /**
     * Interpolated Lilith: the apogee the Moon really passes through, followed continuously.
     *
     * It is Swiss Ephemeris's `SE_INTP_APOG`, the one Astrodienst takes to be the physically
     * correct one. Its documentation describes it as "an interpolation between the real passages
     * of the Moon through its apogee", and that is the first thing that was tried here: locating
     * each passage as a maximum of the distance (parabolic search from the mean apogee, which
     * lands 0.4 days away from the real one; the perigee, 1.7), taking the longitude of the Moon
     * at each one and interpolating. Against Swiss, across six hundred dates between 1900 and
     * 2100: linear 0.80 degrees at the apogee and 8.6 at the perigee; four-point cubic 0.41 and
     * 7.6; six-point Lagrange 0.31 and 7.3; Hermite 0.44 and 7.9. And it was NOT the
     * interpolation: at the passage instants themselves, our longitude and the Swiss curve agree
     * to 0.013 degrees. What happens is that the perigee curve turns 23 degrees in 32 days when
     * the Sun is perpendicular to the line of apsides, and there is one passage every 27.55 days:
     * no interpolation in time can follow a feature narrower than its sampling. It is Nyquist,
     * not a bad method.
     *
     * What Swiss really does (`swi_intp_apsides`, in `swemmoon.c`) is another thing: it takes
     * the lunar arguments of the theory (mean anomaly, elongation, argument of latitude, mean
     * longitude) and shifts them ALL by the same angle until the mean anomaly sits at the apogee,
     * with the Sun and the planets where they are at the requested instant. That is, it moves the
     * Moon along its orbit and leaves the Sun still. And over that configuration it looks for the
     * extremum of the distance with a three-point template that advances in REAL time, with the
     * Sun moving. Put as a condition: the shift δ such that the time derivative of the distance
     * vanishes for the Moon shifted by δ. At a real passage, δ = 0 is an exact root: that is why
     * Swiss goes through the real passages. Between passages it is continuous because the Sun
     * moves slowly.
     *
     * Here exactly that is done with our own ELP series. Shifting the Moon by δ along its orbit
     * adds m·δ to each argument, where m is how many times the MEAN LONGITUDE of the Moon enters
     * it (D, l and F carry it with coefficient one; l' and the planets do not), and that m is
     * written by the series generator as the seventh field of each term. It was not always there:
     * the first attempt was to recover it from the frequency, dividing by the lunar rate and
     * rounding, because everything else that enters the frequency adds up to at most a few
     * thousand radians per century against the 8400 of the Moon. It failed twice. First in
     * 6D - l' - 2l (0.09 arcseconds), which fell at 3.493 and was read as 3 when it is 4: that one
     * was fixed by reconstructing the argument. And then where nothing fixes it: the fourteen
     * arcsecond Venus term, 18V - 16T - l, whose planetary part cancels the rate of l and leaves a
     * frequency of 2.3 radians per century. The rounding gives 0, the multiplier is -1, and the
     * error that leaves (six arcseconds with the Moon shifted by half a radian) fitted inside the
     * margin measured against Swiss, that is, nobody would have caught it. That is why the datum
     * comes from the place where it is really known.
     *
     * And a trap that cost twelve degrees: the condition is the TIME derivative at fixed δ, not
     * the extremum of the distance over δ. They look like the same thing and they are not, because
     * the Sun enters the distance through the elongation: at the perigee the curvature of the
     * distance drops to some 5400 km per squared radian when the evection and the variation oppose
     * the eccentricity, and the cross term of the Sun, some thousand kilometres per radian, moves
     * the root ten degrees. With the extremum over δ, the perigee drifted twelve degrees from
     * Swiss; with the time derivative, 0.021.
     *
     * Measured against Swiss 2.10.03 (Moshier, without nutation, geometric), six hundred dates
     * between 1900 and 2100: apogee, worst 0.016 degrees and median 0.003; perigee, worst 0.021
     * and median 0.004. With no drift over the centuries. What is left is the difference between
     * Moshier's theory and ELP's, which in the Moon itself reaches three arcseconds. And at the
     * real passages it matches the Moon to machine precision, which is the check against the
     * definition.
     *
     * It costs about seven evaluations of the distance series and one of the longitude series, a
     * millisecond and a half. There is nothing to cache: nothing is searched for in time.
     *
     * @param float $jdTT
     * @return float Longitude in the mean ecliptic of the date, without nutation, like the rest.
     */
    public static function interpolatedLilith(float $jdTT): float
    {
        return self::interpolatedApsis($jdTT, M_PI);
    }

    /**
     * Priapus: the interpolated perigee, Swiss's `SE_INTP_PERG`.
     *
     * It has a body of its own because it is NOT interpolated Lilith plus half a turn, unlike the
     * mean perigee and the osculating one: the Moon does not pass through its perigee opposite
     * where it passed through its apogee, because between one and the other the Sun has moved the
     * orbit. It oscillates twenty-five degrees around the mean perigee, five times more than the
     * apogee, and that is why it is the one that makes visible the method failures that the apogee
     * forgives.
     *
     * @param float $jdTT
     * @return float
     */
    public static function interpolatedPriapus(float $jdTT): float
    {
        return self::interpolatedApsis($jdTT, 0.0);
    }

    /**
     * The eccentricity of the instantaneous orbit. It is exposed because it is what says how much
     * one can trust true Lilith: the rounder the orbit, the worse defined the apogee is.
     *
     * @param float $jdTT
     * @return float
     */
    public static function eccentricity(float $jdTT): float
    {
        [$position, $angularMomentum, $velocity] = self::state($jdTT);

        $distance = sqrt($position[0] ** 2 + $position[1] ** 2 + $position[2] ** 2);
        $crossed = self::cross($velocity, $angularMomentum);

        $vector = [
            $crossed[0] / self::MU - $position[0] / $distance,
            $crossed[1] / self::MU - $position[1] / $distance,
            $crossed[2] / self::MU - $position[2] / $distance,
        ];

        return sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);
    }

    /**
     * The terms of a series just as the shift uses them, so that a test can check the multiplier
     * of the Moon against the frequency written in each one.
     *
     * @param string $variable 'L' or 'R'.
     * @return list<array{amplitud: float, frecuencia: float, curvatura: float, luna: int, potencia: int}>
     */
    public static function seriesTerms(string $variable): array
    {
        $list = [];

        foreach (self::shiftedSeries()[$variable] as $power => $terms) {
            foreach ($terms as [$amplitude, , $c1, $c2, , , $moon]) {
                $list[] = [
                    'amplitude' => abs($amplitude),
                    'frequency' => $c1,
                    'curvature' => $c2,
                    'moon' => $moon,
                    'power' => $power,
                ];
            }
        }

        return $list;
    }

    /**
     * The interpolated apsis: the Moon shifted to where the time derivative of its distance
     * vanishes, starting from the mean apsis.
     *
     * @param float $jdTT
     * @param float $targetAnomaly Zero for the perigee, pi for the apogee.
     * @return float
     */
    private static function interpolatedApsis(float $jdTT, float $targetAnomaly): float
    {
        $t = Time::centuries($jdTT);

        // The shift that leaves the mean anomaly at the target, folded to half a turn so as to
        // start from the nearest apsis and not from the one of the next revolution.
        $initial = $targetAnomaly - self::meanAnomaly($t);
        $initial = atan2(sin($initial), cos($initial));

        $delta = self::derivativeRoot($t, $initial);

        return self::normalize(rad2deg(self::shiftedLongitude($t, $delta)));
    }

    /**
     * The shift at which the time derivative of the distance vanishes.
     *
     * Secant method from the mean apsis, which converges in half a dozen rounds because the
     * function is almost a sinusoid. If it does not converge, or if it goes farther than an apsis
     * can drift from the mean one, it is redone by bisection over the allowed stretch, which is
     * slow but cannot fail as long as the root is inside.
     *
     * @param float $t Julian centuries since J2000.
     * @param float $initial
     * @return float
     */
    private static function derivativeRoot(float $t, float $initial): float
    {
        $a = $initial;
        $ga = self::shiftedDistanceDerivative($t, $a);
        $b = $initial + deg2rad(1);
        $gb = self::shiftedDistanceDerivative($t, $b);

        for ($round = 0; $round < 40; $round++) {
            if ($gb === $ga) {
                break;
            }

            $c = $b - $gb * ($b - $a) / ($gb - $ga);

            $a = $b;
            $ga = $gb;
            $b = $c;
            $gb = self::shiftedDistanceDerivative($t, $b);

            if (abs($b - $a) < 1e-10) {
                if (abs($b - $initial) < self::REACH) {
                    return $b;
                }

                break;
            }
        }

        $low = $initial - self::REACH;
        $high = $initial + self::REACH;
        $gLow = self::shiftedDistanceDerivative($t, $low);

        // Without a change of sign there is no root to bracket. It does not happen with the Moon,
        // but if it did, the mean apsis is an honest answer and the other side of the orbit is not.
        if ($gLow * self::shiftedDistanceDerivative($t, $high) > 0) {
            return $initial;
        }

        for ($round = 0; $round < 60; $round++) {
            $middle = ($low + $high) / 2;

            if ($gLow * self::shiftedDistanceDerivative($t, $middle) > 0) {
                $low = $middle;
                $gLow = self::shiftedDistanceDerivative($t, $middle);
            } else {
                $high = $middle;
            }
        }

        return ($low + $high) / 2;
    }

    /**
     * Derivative with respect to time of the distance series, with the Moon shifted by delta.
     *
     * In units per Julian century, which do not matter: only where it is zero is being looked for.
     * The derivative is analytic, term by term: that of the argument times the cosine, plus that
     * of the T^p factor in the Poisson terms.
     *
     * @param float $t
     * @param float $delta
     * @return float
     */
    private static function shiftedDistanceDerivative(float $t, float $delta): float
    {
        $derivative = 0.0;

        foreach (self::shiftedSeries()['R'] as $power => $terms) {
            $sum = 0.0;
            $derivativeSum = 0.0;

            foreach ($terms as [$amplitude, $c0, $c1, $c2, $c3, $c4, $m]) {
                $phase = $c0 + $t * ($c1 + $t * ($c2 + $t * ($c3 + $t * $c4))) + $m * $delta;
                $rate = $c1 + $t * (2 * $c2 + $t * (3 * $c3 + 4 * $t * $c4));

                $sum += $amplitude * sin($phase);
                $derivativeSum += $amplitude * $rate * cos($phase);
            }

            $derivative += $derivativeSum * $t ** $power;

            if ($power > 0) {
                $derivative += $sum * $power * $t ** ($power - 1);
            }
        }

        return $derivative;
    }

    /**
     * The longitude of the Moon shifted by delta, in radians and in the mean ecliptic of the date.
     *
     * It is `Moon::spherical()` with the shift put into each term and into the mean longitude,
     * general precession included. With delta zero it gives the same as the Moon, and that is
     * checked.
     *
     * @param float $t
     * @param float $delta
     * @return float
     */
    private static function shiftedLongitude(float $t, float $delta): float
    {
        $constants = self::constants();
        $sum = 0.0;

        foreach (self::shiftedSeries()['L'] as $power => $terms) {
            $partial = 0.0;

            foreach ($terms as [$amplitude, $c0, $c1, $c2, $c3, $c4, $m]) {
                $partial += $amplitude * sin($c0 + $t * ($c1 + $t * ($c2 + $t * ($c3 + $t * $c4))) + $m * $delta);
            }

            $sum += $partial * $t ** $power;
        }

        return self::polynomial($constants['w1'], $t)
            + $delta
            + $sum / $constants['rad']
            + deg2rad(Time::generalPrecession($t) / 3600);
    }

    /**
     * The mean anomaly of the Moon in radians, unnormalized: mean longitude minus mean longitude
     * of the perigee.
     *
     * @param float $t
     * @return float
     */
    private static function meanAnomaly(float $t): float
    {
        $constants = self::constants();

        return self::polynomial($constants['w1'], $t) - self::polynomial($constants['w2'], $t);
    }

    /**
     * The longitude and distance series, each term with the multiplier of the mean longitude of
     * the Moon that the generator writes as the seventh field.
     *
     * They are the same files that `Moon` reads, which ignores that field. If it is not there, the
     * series are from before the generator wrote it, and that is said instead of making it up: a
     * multiplier deduced from the frequency comes out wrong precisely in the Venus term, which is
     * the heaviest of the planetary ones.
     *
     * @return array<string, array<int, list<array{float, float, float, float, float, float, int}>>>
     */
    private static function shiftedSeries(): array
    {
        if (self::$shiftedSeries !== null) {
            return self::$shiftedSeries;
        }

        $series = [];

        foreach (['L', 'R'] as $variable) {
            foreach (require DataFolder::path("elp2000/{$variable}.php") as $power => $terms) {
                foreach ($terms as $index => $term) {
                    if (! isset($term[6])) {
                        throw new RuntimeException(sprintf(
                            'Term %d of the ELP series %s does not carry the Moon multiplier: '
                            .'regenerate the series with `astronomy elp2000 --umbral=1e-8`.',
                            $index, $variable
                        ));
                    }

                    $series[$variable][$power][] = [
                        $term[0], $term[1], $term[2], $term[3], $term[4], $term[5], (int) $term[6],
                    ];
                }
            }
        }

        return self::$shiftedSeries = $series;
    }

    /**
     * Position, angular momentum and velocity of the Moon at that instant.
     *
     * The GEOMETRIC position is used, without discounting the light-time. An orbital element
     * describes where the orbit is, not from where it is looked at: putting the light-time into it
     * would be computing the orbit of a Moon that is no longer there.
     *
     * @param float $jdTT
     * @return array{0: array{float, float, float}, 1: array{float, float, float}, 2: array{float, float, float}}
     */
    private static function state(float $jdTT): array
    {
        $position = self::geometric($jdTT);
        $before = self::geometric($jdTT - self::STEP);
        $after = self::geometric($jdTT + self::STEP);

        $velocity = [
            ($after[0] - $before[0]) / (2 * self::STEP),
            ($after[1] - $before[1]) / (2 * self::STEP),
            ($after[2] - $before[2]) / (2 * self::STEP),
        ];

        return [$position, self::cross($position, $velocity), $velocity];
    }

    /**
     * @param float $jdTT
     * @return array{float, float, float}
     */
    private static function geometric(float $jdTT): array
    {
        [$lon, $lat, $distance] = Moon::spherical($jdTT);

        return [
            $distance * cos($lat) * cos($lon),
            $distance * cos($lat) * sin($lon),
            $distance * sin($lat),
        ];
    }

    /**
     * One of the ELP mean longitudes, brought to the equinox of the date.
     *
     * The three of them (Moon, perigee and node) are referred to the inertial equinox, so the
     * general precession has to be added to them. Without that step the rate of the node comes out
     * as -1935.53 degrees per century instead of -1934.14, and the error grows with the distance
     * from the year 2000.
     *
     * @param string $which
     * @param float $jdTT
     * @return float
     */
    private static function fromMeanLongitude(string $which, float $jdTT): float
    {
        $constants = self::constants();
        $t = Time::centuries($jdTT);

        return self::normalize(
            rad2deg(self::polynomial($constants[$which], $t)) + Time::generalPrecession($t) / 3600
        );
    }

    /**
     * @return array{w1: list<float>, w2: list<float>, w3: list<float>, precession: float, rad: float, distance_scale: float}
     */
    private static function constants(): array
    {
        return self::$constants ??= require DataFolder::path('elp2000/constants.php');
    }

    /**
     * @param list<float> $coefficients
     * @param float $t
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
     * @param float $degrees
     * @return float
     */
    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }
}
