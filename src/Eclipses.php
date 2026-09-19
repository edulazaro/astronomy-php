<?php

namespace Astronomy;

use Closure;
use DateTimeZone;

/**
 * Solar and lunar eclipses: when, of what kind, and what is seen from a given place.
 *
 * This is what `swe_sol_eclipse_*` and `swe_lun_eclipse_*` do, and it comes out of the same
 * thing the engine already had: the position of the Sun and of the Moon. An eclipse is shadow
 * geometry, so there are no eclipse tables or formulae copied from a canon here: the shadow
 * axis is computed from the vectors of the two bodies in kilometres and then it is checked
 * whether it touches the Earth. The only constant that does not come from the ephemerides is
 * how much the atmosphere widens the Earth's shadow, which is an empirical adjustment and its
 * source is stated.
 *
 * **The search goes by syzygies and not by brute force.** A solar eclipse can only happen at
 * new Moon and a lunar one only at full Moon, and there are twelve or thirteen of those a
 * year. Each one is computed with the ephemeris (starting from the mean phase, which gives the
 * date to within half a day, and refining with the real elongation), the latitude of the Moon
 * at that instant is looked at, and only if it is less than two degrees from the ecliptic is
 * the whole geometry built. With that, a decade is some thirty candidates and not four
 * thousand days.
 *
 * **The instants are found from the projected motion, not by blind bisection.** The distance
 * from the shadow axis to the centre of the Earth is, over a few hours, the hypotenuse of a
 * rectilinear motion: its square is an almost exact parabola. Three evaluations give the
 * vertex, and the contacts come out of that same parabola and are refined with two secant
 * steps. A whole eclipse is some forty positions of the Moon instead of forty thousand.
 *
 * Everything inside goes in Terrestrial Time, which is the scale of the ephemerides, and it is
 * only converted to Universal Time on the way out. Delta T is applied once, on output, and not
 * to every position.
 */
class Eclipses
{
    /** Equatorial radius of the Earth in the shadow geometry, in km. */
    private const EARTH_RADIUS_KM = Horizon::EQUATORIAL_RADIUS_KM;

    /**
     * Ratio between the k of the umbra and that of the penumbra: 0.272281 / 0.2725076.
     *
     * For the second and third contacts (when the disc of the Moon is inside the Sun's, or the
     * other way round) the limb that counts is not the mean one but the one of the valleys
     * between mountains, through which the Sun is still seen: a lunar radius 0.08% smaller. It
     * is the convention of the IAU and of NASA, and Swiss applies it with the same number.
     *
     * The two k values live in `Horizon`, which is where the radius of the Moon lives, because
     * `CentralPath` needs the same ones and they were written by hand in both places.
     */
    private const UMBRA_LIMB_FACTOR = Horizon::K_UMBRA_LIMB / Horizon::K_MEAN_LIMB;

    /**
     * How much the atmosphere widens the shadow of the Earth in a lunar eclipse.
     *
     * The atmosphere refracts light into the shadow and makes it larger than pure geometry
     * gives: one fiftieth (Astronomical Almanac 1998, L4), which is what Swiss applies, and on
     * top of that a separate fine adjustment to the umbra and to the penumbra so that their
     * magnitudes give NASA's. The three numbers are Swiss's (`lun_eclipse_how` in `swecl.c`),
     * and the same ones are used so that the magnitudes can be compared with those published
     * by any canon.
     */
    private const ATMOSPHERE_WIDENING = 1 + 1 / 50;

    private const UMBRA_ADJUSTMENT = 0.99405;

    private const PENUMBRA_ADJUSTMENT = 0.98813;

    /** Ecliptic latitude of the Moon above which no eclipse is possible. */
    private const LATITUDE_LIMIT = 2.0;

    /** Mean synodic month, in days, and Meeus's reference new Moon. */
    private const SYNODIC_MONTH = 29.530588861;

    private const LUNA_NUEVA_2000 = 2451550.09766;

    /**
     * The solar eclipses between two instants, in order.
     *
     * @param float $jdUtFrom
     * @param float $jdUtTo
     * @return list<SolarEclipse>
     */
    public static function solar(float $jdUtFrom, float $jdUtTo): array
    {
        $eclipses = [];

        foreach (self::syzygies($jdUtFrom, $jdUtTo, isNew: true) as $jdTT) {
            $eclipse = self::solarAt($jdTT);

            if ($eclipse !== null && $eclipse->maximum->jdUt >= $jdUtFrom && $eclipse->maximum->jdUt <= $jdUtTo) {
                $eclipses[] = $eclipse;
            }
        }

        return $eclipses;
    }

    /**
     * The lunar eclipses between two instants, in order. The penumbral ones too.
     *
     * @param float $jdUtFrom
     * @param float $jdUtTo
     * @return list<LunarEclipse>
     */
    public static function lunar(float $jdUtFrom, float $jdUtTo): array
    {
        $eclipses = [];

        foreach (self::syzygies($jdUtFrom, $jdUtTo, isNew: false) as $jdTT) {
            $eclipse = self::lunarAt($jdTT);

            if ($eclipse !== null && $eclipse->maximum->jdUt >= $jdUtFrom && $eclipse->maximum->jdUt <= $jdUtTo) {
                $eclipses[] = $eclipse;
            }
        }

        return $eclipses;
    }

    /**
     * What is seen of a solar eclipse from a place. Null if nothing is seen from there.
     *
     * @param SolarEclipse $eclipse
     * @param Place $place
     * @param float $heightMetres
     * @return LocalCircumstances|null
     */
    public static function localSolar(SolarEclipse $eclipse, Place $place, float $heightMetres = 0.0): ?LocalCircumstances
    {
        return self::localOccultation(Body::Sun, $eclipse->maximum->jdUt, $place, $heightMetres);
    }

    /**
     * Which phases of a lunar eclipse catch the Moon above the horizon of a place.
     * Null if none of them do.
     *
     * @param LunarEclipse $eclipse
     * @param Place $place
     * @param float $heightMetres
     * @return LunarCircumstances|null
     */
    public static function localLunar(LunarEclipse $eclipse, Place $place, float $heightMetres = 0.0): ?LunarCircumstances
    {
        $horizon = new Horizon($place, $heightMetres);
        $visible = [];
        $atMaximum = null;

        foreach ($eclipse->contacts() as $name => $instant) {
            $horizontal = $horizon->at(Body::Moon, $instant->jdUt);
            $visible[$name] = $horizontal->isVisible();

            if ($name === 'maximum') {
                $atMaximum = $horizontal;
            }
        }

        if (! in_array(true, $visible, true)) {
            return null;
        }

        $timezone = $place->timeZone();

        return new LunarCircumstances(
            visible: $visible,
            altitude: $atMaximum->altitude,
            apparentAltitude: $atMaximum->apparentAltitude,
            azimuth: $atMaximum->azimuth,
            moonRise: self::passWithin(Body::Moon, $place, $heightMetres, Pass::Rise, $eclipse->p1->jdUt, $eclipse->p4->jdUt, $timezone),
            moonSet: self::passWithin(Body::Moon, $place, $heightMetres, Pass::Set, $eclipse->p1->jdUt, $eclipse->p4->jdUt, $timezone),
        );
    }

    /**
     * The Moon passing in front of something, seen from a place: the Sun in an eclipse, a
     * planet or a star in an occultation. It is the same computation, and that is why it is
     * the same function.
     *
     * Everything in TOPOCENTRIC coordinates: the parallax of the Moon reaches one degree, that
     * is, twice its diameter, and from the centre of the Earth a total eclipse is seen as
     * partial and an occultation is not seen at all.
     *
     * @param Body|Star|callable(float): Equatorial $object What is covered: the Sun, a
     *        planet, a star from the catalogue or a bare direction.
     * @param float $approximateJdUt An instant near the maximum (the geocentric one will do).
     * @param Place $place
     * @param float $heightMetres
     * @return LocalCircumstances|null Null if from there the discs never get to touch each
     *         other, or if no phase catches the body above the horizon.
     */
    public static function localOccultation(Body|Star|callable $object, float $approximateJdUt, Place $place, float $heightMetres = 0.0): ?LocalCircumstances
    {
        $horizon = new Horizon($place, $heightMetres);
        $track = Horizon::track($object);
        $radius = Horizon::radiusKm($object);

        $status = function (float $jdTT) use ($horizon, $track, $radius): array {
            $jdUt = Time::ut($jdTT);
            $body = $horizon->topocentric($track($jdTT), $jdUt);
            $moon = $horizon->topocentric(Horizon::equatorialOf(Body::Moon, $jdTT), $jdUt);

            return [
                'separation' => $body->separation($moon),
                'rc' => Horizon::semidiameter($body, $radius),
                'rl' => Horizon::semidiameter($moon, Horizon::MOON_RADIUS_KM),
                'body' => $body,
                'jdUt' => $jdUt,
            ];
        };

        [$tMaximum, $minimum, $curvature] = self::minimum(fn (float $t) => $status($t)['separation'], Time::tt($approximateJdUt));

        $atMaximum = $status($tMaximum);
        $rc = $atMaximum['rc'];
        $rl = $atMaximum['rl'];

        // A point (a star, or a bare direction) has no disc: it is covered or it is not, and
        // it is covered all at once, so its four contacts are two. And they are the INNER
        // ones, those of the umbral limb, because the last light of a star passes through the
        // valleys of the edge just like the light of the Sun. It is what Swiss does («fixed
        // stars are point sources, contacts 1 and 4 = contacts 2 and 3»). Looking for the four
        // separately, the two radii differed by up to eight seconds in an oblique graze: a
        // star «disappearing» for eight seconds with nothing to cover it.
        $isPoint = $rc <= 0.0;

        if ($minimum > ($isPoint ? self::UMBRA_LIMB_FACTOR * $rl : $rc + $rl)) {
            return null;
        }

        $type = match (true) {
            $minimum < $rc - $rl => EclipseType::Annular,
            $minimum < abs($rc - $rl) => EclipseType::Total,
            default => EclipseType::Partial,
        };

        $outer = function (float $t) use ($status): float {
            $at = $status($t);

            return $at['separation'] - ($at['rc'] + $at['rl']);
        };

        $inner = function (float $t) use ($status): float {
            $at = $status($t);

            return $at['separation'] - abs($at['rc'] - self::UMBRA_LIMB_FACTOR * $at['rl']);
        };

        if ($isPoint) {
            [$c2, $c3] = self::contacts($inner, $tMaximum, $minimum, $curvature, self::UMBRA_LIMB_FACTOR * $rl);
            [$c1, $c4] = [$c2, $c3];
        } else {
            [$c1, $c4] = self::contacts($outer, $tMaximum, $minimum, $curvature, $rc + $rl);
            [$c2, $c3] = $type->hasCentralPhase()
                ? self::contacts($inner, $tMaximum, $minimum, $curvature, abs($rc - self::UMBRA_LIMB_FACTOR * $rl))
                : [null, null];
        }

        // Magnitude, ratio of diameters and obscuration, as Swiss defines them so that they
        // can be compared. Capped at one: the fraction of the diameter that is covered cannot
        // go beyond a whole one, and without the cap the one for a planet behind the Moon
        // comes out in the tens, because its disc is tiny next to the Moon's. A star has no disc: it
        // is covered whole or not at all.
        $magnitude = $rc > 0 ? min(1.0, ($rc + $rl - $minimum) / (2 * $rc)) : 1.0;
        $ratio = $rc > 0 ? $rl / $rc : 0.0;
        $obscuration = self::obscuration($type, $minimum, $rc, $rl);

        $timezone = $place->timeZone();
        $visible = [];
        $horizontalAtMaximum = null;

        foreach (['contact1' => $c1, 'contact2' => $c2, 'maximum' => $tMaximum, 'contact3' => $c3, 'contact4' => $c4] as $name => $t) {
            if ($t === null) {
                continue;
            }

            $at = $status($t);
            $horizontal = $horizon->horizontal($at['body'], $at['jdUt']);
            $visible[$name] = $horizontal->isVisible();

            if ($name === 'maximum') {
                $horizontalAtMaximum = $horizontal;
            }
        }

        if (! in_array(true, $visible, true)) {
            return null;
        }

        /* The first and the last contact are checked just like the second and the third, six
           lines further down, and for the same reason: `contacts()` returns nulls when there is
           no crossing, and here they were not being checked. This method is public and its
           docblock invites seeding it with «an instant near the maximum», so whoever seeded it
           by eye got a TypeError instead of a null. Without a first and a last contact there
           are no local circumstances to describe. */
        if ($c1 === null || $c4 === null) {
            return null;
        }

        $jdC1 = Time::ut($c1);
        $jdC4 = Time::ut($c4);

        return new LocalCircumstances(
            type: $type,
            maximum: UtInstant::fromJd(Time::ut($tMaximum), $timezone),
            contact1: UtInstant::fromJd($jdC1, $timezone),
            contact2: $c2 === null ? null : UtInstant::fromJd(Time::ut($c2), $timezone),
            contact3: $c3 === null ? null : UtInstant::fromJd(Time::ut($c3), $timezone),
            contact4: UtInstant::fromJd($jdC4, $timezone),
            magnitude: $magnitude,
            diameterRatio: $ratio,
            obscuration: $obscuration,
            minimumSeparation: $minimum,
            altitude: $horizontalAtMaximum->altitude,
            apparentAltitude: $horizontalAtMaximum->apparentAltitude,
            azimuth: $horizontalAtMaximum->azimuth,
            visible: $visible,
            rise: self::passWithin($object, $place, $heightMetres, Pass::Rise, $jdC1, $jdC4, $timezone),
            set: self::passWithin($object, $place, $heightMetres, Pass::Set, $jdC1, $jdC4, $timezone),
        );
    }

    /**
     * The geometry of the shadow that the Moon casts towards the Earth, covering a body.
     *
     * It is the one of Swiss's `eclipse_where`, with the vectors in km and in the axes of the
     * equator of date. The covered body is the Sun in an eclipse and a planet or a star in an
     * occultation: the radius changes and nothing else, and that is why `Occultations` uses
     * this same function.
     *
     * **The flattening of the Earth is put in by stretching the z coordinate of the two
     * bodies** instead of squashing the Earth: that way the ellipsoid becomes a sphere of
     * equatorial radius and the question «does the axis touch the Earth?» is comparing a
     * distance with a number. It is the trick of the Besselian elements and the one Swiss
     * uses.
     *
     * @param array{0: float, 1: float, 2: float} $bodyKm Geocentric.
     * @param float $bodyRadiusKm
     * @param array{0: float, 1: float, 2: float} $moonKm Geocentric.
     * @return array{
     *     r0: float, gamma: float, d0: float, D0: float, cosf1: float, cosf2: float,
     *     central: bool, umbra: bool, penumbra: bool, anular: bool, nucleo: float,
     *     superficie: array{0: float, 1: float, 2: float}
     * } Distances in km. `superficie` is the point of the Earth where the eclipse is greatest,
     *   no longer stretched, in the same axes.
     */
    public static function shadow(array $bodyKm, float $bodyRadiusKm, array $moonKm): array
    {
        $a = self::EARTH_RADIUS_KM;
        $rl = Horizon::MOON_RADIUS_KM;
        $stretch = 1 / (1 - Horizon::FLATTENING);

        $rs = [$bodyKm[0], $bodyKm[1], $bodyKm[2] * $stretch];
        $rm = [$moonKm[0], $moonKm[1], $moonKm[2] * $stretch];

        // The axis of the shadow: from the body to the Moon.
        $e = [$rm[0] - $rs[0], $rm[1] - $rs[1], $rm[2] - $rs[2]];
        $dsm = self::norm($e);
        $e = [$e[0] / $dsm, $e[1] / $dsm, $e[2] / $dsm];
        $dm = self::norm($rm);

        // The half-angles of the umbral and penumbral cones.
        $sinf1 = ($bodyRadiusKm - $rl) / $dsm;
        $cosf1 = sqrt(1 - $sinf1 * $sinf1);
        $sinf2 = ($bodyRadiusKm + $rl) / $dsm;
        $cosf2 = sqrt(1 - $sinf2 * $sinf2);

        // Distance from the Moon to the fundamental plane (the one that passes through the
        // centre of the Earth perpendicular to the axis) and distance from the axis to the
        // centre of the Earth.
        $s0 = -self::dot($rm, $e);
        $r0 = sqrt(max(0.0, $dm * $dm - $s0 * $s0));

        // Diameters of the umbra (negative when the cone has already closed: antumbra) and of
        // the penumbra on the fundamental plane.
        $d0 = ($s0 / $dsm * (2 * $bodyRadiusKm - 2 * $rl) - 2 * $rl) / $cosf1;
        $D0 = ($s0 / $dsm * (2 * $bodyRadiusKm + 2 * $rl) + 2 * $rl) / $cosf2;

        $central = $a * $cosf1 >= $r0;
        $umbra = $r0 <= $a * $cosf1 + abs($d0) / 2;
        $penumbra = $r0 <= $a * $cosf2 + $D0 / 2;

        // The point of the axis nearest to the centre of the Earth, and from it the sign of
        // gamma: positive if the axis passes to the north.
        $q = [$rm[0] + $s0 * $e[0], $rm[1] + $s0 * $e[1], $rm[2] + $s0 * $e[2]];
        $north = [-$e[0] * $e[2], -$e[1] * $e[2], 1 - $e[2] * $e[2]];
        $gamma = (self::dot($q, $north) >= 0 ? 1 : -1) * $r0 / $a;

        // The point of the surface where the eclipse is greatest: where the axis cuts the
        // sphere if it cuts it, and if not, the point of the sphere nearest to the axis.
        if ($r0 < $a) {
            $s = $s0 - sqrt($a * $a - $r0 * $r0);
            $xs = [$rm[0] + $s * $e[0], $rm[1] + $s * $e[1], $rm[2] + $s * $e[2]];
        } else {
            $xs = [$q[0] * $a / $r0, $q[1] * $a / $r0, $q[2] * $a / $r0];
        }

        $surface = [$xs[0], $xs[1], $xs[2] / $stretch];

        // Diameter of the core of the shadow at that point, with the real distances: if it is
        // positive the umbral cone already closed before arriving and the eclipse is annular.
        $moonToSurfaceDistance = self::norm([
            $moonKm[0] - $surface[0], $moonKm[1] - $surface[1], $moonKm[2] - $surface[2],
        ]);
        $dsmActual = self::norm([$moonKm[0] - $bodyKm[0], $moonKm[1] - $bodyKm[1], $moonKm[2] - $bodyKm[2]]);
        $core = ($moonToSurfaceDistance / $dsmActual * (2 * $bodyRadiusKm - 2 * $rl) - 2 * $rl) * $cosf1;

        return [
            'r0' => $r0,
            'gamma' => $gamma,
            'd0' => $d0,
            'D0' => $D0,
            'cosf1' => $cosf1,
            'cosf2' => $cosf2,
            'central' => $central,
            'umbra' => $umbra,
            'penumbra' => $penumbra,
            'annular' => $core > 0,
            'core' => $core,
            'surface' => $surface,
        ];
    }

    /**
     * Geographic latitude and longitude of a point of the surface given in km, in the axes of
     * the true equator of date.
     *
     * The latitude is the GEODETIC one, which is the one on maps: a point of the ellipsoid is
     * not in the direction of its geographic latitude, and the difference reaches eleven
     * arcminutes. The longitude comes from subtracting the sidereal time of Greenwich, because
     * the axes are fixed to the stars and the Earth turns underneath.
     *
     * @param array{0: float, 1: float, 2: float} $point
     * @param float $jdUt
     * @return array{0: float, 1: float} [latitude, longitude], east positive.
     */
    public static function geographicCoordinates(array $point, float $jdUt): array
    {
        $rho = sqrt($point[0] ** 2 + $point[1] ** 2);
        $latitude = rad2deg(atan2($point[2], $rho * (1 - Horizon::FLATTENING) ** 2));
        $longitude = rad2deg(atan2($point[1], $point[0])) - Time::apparentSiderealTime($jdUt);

        return [$latitude, fmod(fmod($longitude, 360.0) + 540.0, 360.0) - 180.0];
    }

    /**
     * The instant at which a function reaches its minimum, by a parabola over its square.
     *
     * It works for what is minimised here: distances and angular separations between two
     * things that move almost in a straight line. For a uniform rectilinear motion the squared
     * distance IS a parabola, so three points give the exact vertex and what is left is to
     * correct the small real curvature by repeating with shorter steps. Three rounds, nine
     * evaluations, and the instant is good to better than a second.
     *
     * @param Closure(float): float $function
     * @param float $t Starting point.
     * @return array{0: float, 1: float, 2: float} [instant, minimum value, curvature of the
     *         parabola of the square in units²/day²]
     */
    public static function minimum(Closure $function, float $t): array
    {
        $curvature = 0.0;
        $value = 0.0;

        foreach ([1 / 24, 1 / 144, 1 / 1440, 1 / 8640] as $h) {
            $y0 = $function($t - $h) ** 2;
            $y1 = $function($t) ** 2;
            $y2 = $function($t + $h) ** 2;

            $curvature = ($y0 - 2 * $y1 + $y2) / (2 * $h * $h);
            $slope = ($y2 - $y0) / (2 * $h);

            if ($curvature <= 0) {
                // With no downward vertex there is no minimum among the three: it carries on
                // the way it goes down, which is what anyone would do looking at the three
                // numbers.
                $t += $y0 < $y2 ? -$h : $h;

                continue;
            }

            /* The Newton step is capped at ten times the interval with which the slope and the
               curvature have been measured. Without a cap, an almost flat curvature sends the
               point weeks away, and with the three samples available nothing can be asserted at
               that distance. Ten times covers the real basin of convergence with room to spare,
               which is some eight hours, and along the real path the local maximum never gets
               more than two hours away from the geocentric one. */
            $step = $slope / (2 * $curvature);
            $t -= max(-10 * $h, min(10 * $h, $step));
        }

        /* The value is evaluated at the instant that is returned, and it is not taken from the
           vertex of the parabola. With the vertex, if the last round came out with a
           non-positive curvature the value was left over from an earlier round while the instant
           had already moved, and the triple contradicted itself: one instant, the value from
           somewhere else and a curvature from a third place. Out of it come `minimumSeparation`,
           the magnitude, the obscuration and the classification of the eclipse. */
        return [$t, abs($function($t)), $curvature];
    }

    /**
     * The two instants, before and after the minimum, at which the function equals a
     * threshold.
     *
     * The first estimate comes out of the parabola of the minimum (the two points where the
     * square equals the threshold squared) and from there two or three secant steps over the
     * real function. If the threshold falls below the minimum there is no contact and nulls
     * are returned.
     *
     * @param Closure(float): float $deviation Function that vanishes at the contact.
     * @param float $tMinimum
     * @param float $minimum
     * @param float $curvature
     * @param float $threshold
     * @return array{0: float|null, 1: float|null}
     */
    public static function contacts(Closure $deviation, float $tMinimum, float $minimum, float $curvature, float $threshold): array
    {
        if ($curvature <= 0 || $threshold <= $minimum) {
            return [null, null];
        }

        $halfWidth = sqrt(($threshold * $threshold - $minimum * $minimum) / $curvature);

        return [
            self::root($deviation, $tMinimum - $halfWidth),
            self::root($deviation, $tMinimum + $halfWidth),
        ];
    }

    /**
     * A zero of a function near a point, by the secant method.
     *
     * @param Closure(float): float $function
     * @param float $t
     * @return float
     */
    public static function root(Closure $function, float $t): float
    {
        $t0 = $t;
        $y0 = $function($t0);
        $t1 = $t + 30 / 86400;
        $y1 = $function($t1);

        for ($i = 0; $i < 20; $i++) {
            if ($y1 === $y0) {
                break;
            }

            $t2 = $t1 - $y1 * ($t1 - $t0) / ($y1 - $y0);

            // A step larger than half a day means the secant has run away: it is shortened.
            if (abs($t2 - $t1) > 0.5) {
                $t2 = $t1 + ($t2 > $t1 ? 0.5 : -0.5);
            }

            [$t0, $y0] = [$t1, $y1];
            [$t1, $y1] = [$t2, $function($t2)];

            if (abs($t1 - $t0) < 0.01 / 86400) {
                break;
            }
        }

        return $t1;
    }

    /**
     * A solar eclipse at the new Moon that falls near an instant, or null.
     *
     * @param float $jdTT
     * @return SolarEclipse|null
     */
    private static function solarAt(float $jdTT): ?SolarEclipse
    {
        $shadowAt = fn (float $t): array => self::shadow(
            Horizon::equatorialOf(Body::Sun, $t)->vector(),
            Horizon::SUN_RADIUS_KM,
            Horizon::equatorialOf(Body::Moon, $t)->vector()
        );

        [$tMaximum, , $curvature] = self::minimum(fn (float $t) => $shadowAt($t)['r0'], $jdTT);

        $atMaximum = $shadowAt($tMaximum);

        if (! $atMaximum['penumbra']) {
            return null;
        }

        $a = self::EARTH_RADIUS_KM;
        $r0 = $atMaximum['r0'];

        // The three pairs of global contacts: penumbra, umbra (or antumbra) and axis on the
        // surface. Each one is a different threshold over the same distance r0.
        $contacts = function (string $threshold) use ($shadowAt, $tMaximum, $r0, $curvature, $a, $atMaximum): array {
            $limit = fn (array $s): float => match ($threshold) {
                'penumbra' => $a * $s['cosf2'] + $s['D0'] / 2,
                'umbra' => $a * $s['cosf1'] + abs($s['d0']) / 2,
                default => $a * $s['cosf1'],
            };

            return self::contacts(
                function (float $t) use ($shadowAt, $limit): float {
                    $shadow = $shadowAt($t);

                    return $shadow['r0'] - $limit($shadow);
                },
                $tMaximum, $r0, $curvature, $limit($atMaximum)
            );
        };

        [$p1, $p4] = $contacts('penumbra');
        [$u1, $u4] = $atMaximum['umbra'] ? $contacts('umbra') : [null, null];
        [$c1, $c2] = $atMaximum['central'] ? $contacts('central') : [null, null];

        $type = EclipseType::Partial;

        if ($atMaximum['umbra']) {
            $type = $atMaximum['annular'] ? EclipseType::Annular : EclipseType::Total;

            // Hybrid: total at the maximum and annular at one end of the path, because there
            // the surface is farther from the Moon and the umbral cone closes before arriving.
            // The sign of the core is checked at the two ends.
            if ($type === EclipseType::Total && $u1 !== null && $u4 !== null
                && ($shadowAt($u1)['annular'] || $shadowAt($u4)['annular'])) {
                $type = EclipseType::Hybrid;
            }
        }

        $jdUtMaximum = Time::ut($tMaximum);
        [$latitude, $longitude] = self::geographicCoordinates($atMaximum['surface'], $jdUtMaximum);

        // The magnitude at the point of greatest eclipse, which is the one that gets
        // published: the same topocentric computation as for any other place.
        $local = self::magnitudeAt($latitude, $longitude, $tMaximum);

        $sun = Ephemeris::position(Body::Sun, $tMaximum);
        $moon = Ephemeris::position(Body::Moon, $tMaximum);
        $geocentricSeparation = Horizon::equatorial($sun, $tMaximum)->separation(Horizon::equatorial($moon, $tMaximum));
        $rsGeo = rad2deg(asin(Horizon::SUN_RADIUS_KM / ($sun->distance * Equatorial::AU_KM)));
        $rlGeo = rad2deg(asin(Horizon::MOON_RADIUS_KM / ($moon->distance * Equatorial::AU_KM)));

        $instant = fn (?float $t): ?UtInstant => $t === null ? null : UtInstant::fromJd(Time::ut($t));

        return new SolarEclipse(
            type: $type,
            central: $atMaximum['central'],
            maximum: UtInstant::fromJd($jdUtMaximum),
            gamma: $atMaximum['gamma'],
            magnitude: $type === EclipseType::Partial ? $local['magnitude'] : $local['ratio'],
            diameterRatio: $local['ratio'],
            obscuration: $local['obscuration'],
            geocentricMagnitude: ($rsGeo + $rlGeo - $geocentricSeparation) / (2 * $rsGeo),
            eclipticLongitude: $sun->longitude,
            maximumLatitude: $latitude,
            maximumLongitude: $longitude,
            partialStart: $instant($p1),
            partialEnd: $instant($p4),
            centralStart: $instant($u1),
            centralEnd: $instant($u4),
            centralLineStart: $instant($c1),
            centralLineEnd: $instant($c2),
        );
    }

    /**
     * Magnitude, ratio of diameters and obscuration seen from a point at an instant.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $jdTT
     * @return array{magnitude: float, ratio: float, obscuration: float}
     */
    private static function magnitudeAt(float $latitude, float $longitude, float $jdTT): array
    {
        $horizon = new Horizon(new Place('maximum', null, '', '', $latitude, $longitude, 'UTC'));
        $jdUt = Time::ut($jdTT);

        $sun = $horizon->topocentric(Horizon::equatorialOf(Body::Sun, $jdTT), $jdUt);
        $moon = $horizon->topocentric(Horizon::equatorialOf(Body::Moon, $jdTT), $jdUt);

        $separation = $sun->separation($moon);
        $rs = Horizon::semidiameter($sun, Horizon::SUN_RADIUS_KM);
        $rl = Horizon::semidiameter($moon, Horizon::MOON_RADIUS_KM);

        $type = match (true) {
            $separation < $rs - $rl => EclipseType::Annular,
            $separation < abs($rs - $rl) => EclipseType::Total,
            default => EclipseType::Partial,
        };

        return [
            'magnitude' => ($rs + $rl - $separation) / (2 * $rs),
            'ratio' => $rl / $rs,
            'obscuration' => self::obscuration($type, $separation, $rs, $rl),
        ];
    }

    /**
     * Fraction of the disc that is covered: the area of the lens formed by two circles that
     * cut each other, divided by the area of the covered disc. In a central phase it is the
     * ratio of areas, capped at one: a Sun that is covered whole is not covered any further.
     *
     * @param EclipseType $type
     * @param float $separation
     * @param float $rc Semidiameter of the covered body.
     * @param float $rl Semidiameter of the Moon.
     * @return float
     */
    private static function obscuration(EclipseType $type, float $separation, float $rc, float $rl): float
    {
        if ($rc <= 0) {
            return 1.0;
        }

        if ($type->hasCentralPhase()) {
            return min(1.0, $rl * $rl / ($rc * $rc));
        }

        if ($separation < 1e-9) {
            return min(1.0, $rl * $rl / ($rc * $rc));
        }

        $a = acos(max(-1.0, min(1.0, ($separation ** 2 + $rl ** 2 - $rc ** 2) / (2 * $separation * $rl))));
        $b = acos(max(-1.0, min(1.0, ($separation ** 2 + $rc ** 2 - $rl ** 2) / (2 * $separation * $rc))));

        $lens = $rl * $rl * ($a - sin($a) * cos($a)) + $rc * $rc * ($b - sin($b) * cos($b));

        return $lens / (M_PI * $rc * $rc);
    }

    /**
     * A lunar eclipse at the full Moon that falls near an instant, or null.
     *
     * @param float $jdTT
     * @return LunarEclipse|null
     */
    private static function lunarAt(float $jdTT): ?LunarEclipse
    {
        [$tMaximum, , $curvature] = self::minimum(fn (float $t) => self::earthShadow($t)['r0'], $jdTT);

        $atMaximum = self::earthShadow($tMaximum);
        $rl = Horizon::MOON_RADIUS_KM;
        $r0 = $atMaximum['r0'];

        $type = match (true) {
            $atMaximum['d0'] / 2 >= $r0 + $rl / $atMaximum['cosf1'] => EclipseType::Total,
            $atMaximum['d0'] / 2 >= $r0 - $rl / $atMaximum['cosf1'] => EclipseType::Partial,
            $atMaximum['D0'] / 2 >= $r0 - $rl / $atMaximum['cosf2'] => EclipseType::Penumbral,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        $contacts = function (string $phase) use ($tMaximum, $r0, $curvature, $atMaximum, $rl): array {
            $limit = fn (array $s): float => match ($phase) {
                'penumbra' => $s['D0'] / 2 + $rl / $s['cosf2'],
                'umbra' => $s['d0'] / 2 + $rl / $s['cosf1'],
                default => $s['d0'] / 2 - $rl / $s['cosf1'],
            };

            return self::contacts(
                function (float $t) use ($limit): float {
                    $shadow = self::earthShadow($t);

                    return $shadow['r0'] - $limit($shadow);
                },
                $tMaximum, $r0, $curvature, $limit($atMaximum)
            );
        };

        [$p1, $p4] = $contacts('penumbra');
        [$u1, $u4] = $type !== EclipseType::Penumbral ? $contacts('umbra') : [null, null];
        [$u2, $u3] = $type === EclipseType::Total ? $contacts('total') : [null, null];

        $instant = fn (?float $t): ?UtInstant => $t === null ? null : UtInstant::fromJd(Time::ut($t));

        /* With no entry into and exit from the penumbra there is no eclipse to tell about, and
           `LunarEclipse` does not admit those as null because a lunar eclipse ALWAYS has those
           two. Null is returned and whoever is looking for eclipses skips it, which is what it
           does with any syzygy that gives no eclipse; before, a TypeError came out from inside
           the loop of `lunar()`. The four umbral contacts are indeed null in a penumbral one,
           and that is why they are declared that way. */
        if ($p1 === null || $p4 === null) {
            return null;
        }

        return new LunarEclipse(
            type: $type,
            maximum: UtInstant::fromJd(Time::ut($tMaximum)),
            gamma: $atMaximum['gamma'],
            umbralMagnitude: $type === EclipseType::Penumbral ? 0.0 : ($atMaximum['d0'] / 2 - $r0 + $rl) / (2 * $rl),
            penumbralMagnitude: ($atMaximum['D0'] / 2 - $r0 + $rl) / (2 * $rl),
            eclipticLongitude: Ephemeris::position(Body::Moon, $tMaximum)->longitude,
            p1: $instant($p1),
            u1: $instant($u1),
            u2: $instant($u2),
            u3: $instant($u3),
            u4: $instant($u4),
            p4: $instant($p4),
        );
    }

    /**
     * The shadow of the Earth at the distance of the Moon.
     *
     * It is Swiss's `lun_eclipse_how`: the same construction of cones as the shadow of the
     * Moon, with the Earth as the covering body and seen from the Moon. Here there is no
     * flattening to put in: the Moon is a point with respect to the shadow, and what matters
     * is the size of the shadow, which goes with the atmospheric widening.
     *
     * @param float $jdTT
     * @return array{r0: float, gamma: float, d0: float, D0: float, cosf1: float, cosf2: float}
     */
    private static function earthShadow(float $jdTT): array
    {
        $rs = Horizon::equatorialOf(Body::Sun, $jdTT)->vector();
        $rm = Horizon::equatorialOf(Body::Moon, $jdTT)->vector();

        $sunDiameter = 2 * Horizon::SUN_RADIUS_KM;
        $earthDiameter = 2 * self::EARTH_RADIUS_KM;

        $dm = self::norm($rm);
        $ds = self::norm($rs);

        // The axis of the shadow of the Earth points from the Sun to the Earth, that is, the
        // other way round from the geocentric vector of the Sun.
        $e = [-$rs[0] / $ds, -$rs[1] / $ds, -$rs[2] / $ds];

        $f1 = (Horizon::SUN_RADIUS_KM - self::EARTH_RADIUS_KM) / $ds;
        $cosf1 = sqrt(1 - $f1 * $f1);
        $f2 = (Horizon::SUN_RADIUS_KM + self::EARTH_RADIUS_KM) / $ds;
        $cosf2 = sqrt(1 - $f2 * $f2);

        // Distance from the Earth to the plane that passes through the Moon perpendicular to
        // the axis, and distance from the axis to the centre of the Moon.
        $s0 = self::dot($rm, $e);
        $r0 = sqrt(max(0.0, $dm * $dm - $s0 * $s0));

        $d0 = abs($s0 / $ds * ($sunDiameter - $earthDiameter) - $earthDiameter)
            * self::ATMOSPHERE_WIDENING / ($cosf1 * $cosf1) * self::UMBRA_ADJUSTMENT;
        $D0 = ($s0 / $ds * ($sunDiameter + $earthDiameter) + $earthDiameter)
            * self::ATMOSPHERE_WIDENING / ($cosf2 * $cosf2) * self::PENUMBRA_ADJUSTMENT;

        // Gamma: on which side of the axis the Moon passes. The point of the axis nearest to
        // it is s0 times e; what separates it from there, projected northwards, gives the
        // sign.
        $offset = [$rm[0] - $s0 * $e[0], $rm[1] - $s0 * $e[1], $rm[2] - $s0 * $e[2]];
        $north = [-$e[0] * $e[2], -$e[1] * $e[2], 1 - $e[2] * $e[2]];
        $gamma = (self::dot($offset, $north) >= 0 ? 1 : -1) * $r0 / self::EARTH_RADIUS_KM;

        return ['r0' => $r0, 'gamma' => $gamma, 'd0' => $d0, 'D0' => $D0, 'cosf1' => $cosf1, 'cosf2' => $cosf2];
    }

    /**
     * The new or full Moons of a stretch, in TT, with the Moon less than two degrees from the
     * ecliptic: the only ones in which there can be an eclipse.
     *
     * The starting date is Meeus's mean phase (Astronomical Algorithms, ch. 49), which
     * deviates by up to half a day from the real one, and it is corrected with the elongation
     * that our own ephemeris gives, at the mean rate of the Moon with respect to the Sun. With
     * that it is left within a couple of hours, which is all that the search for the maximum
     * needs: refining further here would be paying for positions of the Moon for a datum
     * nobody uses. And the result does not depend on having copied a series of phase
     * corrections correctly: it depends on the Moon, which is already verified.
     *
     * @param float $jdUtFrom
     * @param float $jdUtTo
     * @param bool $isNew New Moons (solar eclipses) or full ones (lunar eclipses).
     * @return list<float>
     */
    private static function syzygies(float $jdUtFrom, float $jdUtTo, bool $isNew): array
    {
        $target = $isNew ? 0.0 : 180.0;
        $kFrom = (int) floor(($jdUtFrom - self::LUNA_NUEVA_2000) / self::SYNODIC_MONTH) - 1;
        $kTo = (int) ceil(($jdUtTo - self::LUNA_NUEVA_2000) / self::SYNODIC_MONTH) + 1;

        $candidates = [];

        for ($k = $kFrom; $k <= $kTo; $k++) {
            $kk = $k + ($isNew ? 0.0 : 0.5);
            $centuries = $kk / 1236.85;

            $t = self::LUNA_NUEVA_2000 + self::SYNODIC_MONTH * $kk + 0.00015437 * $centuries * $centuries;

            // One step at the mean rate of the elongation, 12.19 degrees per day.
            $deviation = fmod(MoonPhase::elongation($t) - $target + 540.0, 360.0) - 180.0;
            $t -= $deviation / 12.19;

            if ($t < $jdUtFrom - 1 || $t > $jdUtTo + 1) {
                continue;
            }

            if (abs(Ephemeris::position(Body::Moon, $t)->latitude) > self::LATITUDE_LIMIT) {
                continue;
            }

            $candidates[] = $t;
        }

        return $candidates;
    }

    /**
     * The rise or the set of a body if it falls within a stretch, with the whole disc.
     *
     * @param Body|Star|callable $object
     * @param Place $place
     * @param float $heightMetres
     * @param Pass $step
     * @param float $jdUtFrom
     * @param float $jdUtTo
     * @param DateTimeZone $timezone
     * @return UtInstant|null
     */
    private static function passWithin(Body|Star|callable $object, Place $place, float $heightMetres, Pass $step, float $jdUtFrom, float $jdUtTo, DateTimeZone $timezone): ?UtInstant
    {
        $instant = RiseSet::next($object, $place, $jdUtFrom, $step, Limb::Inferior, true, null, $heightMetres);

        if ($instant === null || $instant->jdUt > $jdUtTo) {
            return null;
        }

        return $instant->in($timezone);
    }


    /**
     * @param array{0: float, 1: float, 2: float} $v
     * @return float
     */
    private static function norm(array $v): float
    {
        return sqrt($v[0] * $v[0] + $v[1] * $v[1] + $v[2] * $v[2]);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return float
     */
    private static function dot(array $a, array $b): float
    {
        return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
    }
}
