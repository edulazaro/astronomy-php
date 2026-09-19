<?php

namespace Astronomy;

use InvalidArgumentException;

/**
 * The path of the Moon's shadow over the Earth during a solar eclipse.
 *
 * `Eclipses` gives the instant and the point of maximum, which is ONE point. This is the
 * rest: where the central line runs along the two or three hours the shadow's passage
 * lasts, how wide the band is at each stretch and how long the central phase lasts for
 * whoever stands still there. It is what NASA's eclipse catalogue publishes in its path
 * tables, and Swiss Ephemeris does not have it: it gives the point of maximum and the local
 * circumstances, but not the band.
 *
 * **Everything comes out of the same geometry `Eclipses` already uses, not from Besselian
 * elements.** The shadow axis is the straight line joining the centre of the Sun with the
 * centre of the Moon, and the central line is where that line cuts the ellipsoid. That cut
 * is already done by `Eclipses::shadow()`, so here it is asked of it: the point of the
 * central line at the instant of maximum has to be the eclipse's own `maximumLatitude` and
 * `maximumLongitude`, and it is, to the millimetre, which is what gets lost going from
 * Terrestrial Time to Universal and back. There is a test that demands it, and it is the
 * one that breaks the day somebody rewrites the cut here instead of asking for it: two
 * different computations for the same point are two places to get it wrong.
 *
 * **Flattening is not a decimal, it is kilometres.** The Earth is 21 km shorter from pole
 * to pole than from equator to equator, and treating it as a sphere shifts the central line
 * by tens of kilometres at middle latitudes. The same WGS84 as `Horizon::observerVector` is
 * used here, and along two paths that are best not mixed: the axis is cut with the trick of
 * `Eclipses::shadow`, which stretches the z of both bodies to turn the ellipsoid into a
 * sphere, and **the cone is not**, because a stretched straight line is still a straight
 * line but a stretched circular cone is no longer circular. The outline of the umbra is cut
 * against the real ellipsoid, solving a quadratic.
 *
 * ## Against NASA's tables
 *
 * Measured against the published tables of three total eclipses, comparing at the SAME UT
 * instants NASA tabulates and keeping the WORST point of each whole path:
 *
 * | | rows | central line | edges | width | duration |
 * |---|---|---|---|---|---|
 * | 2017 Aug 21 (USA) | 96 | 3.01 km | 3.54 km | 0.67 km | 0.19 s |
 * | 2024 Apr 08 (Mexico) | 96 | 10.20 km | 11.67 km | 0.68 km | 0.28 s |
 * | 2026 Aug 12 (Iceland and Spain) | 45 | 9.45 km | 23.86 km | 32.48 km | 0.19 s |
 *
 * **The worst ones are always right at the two ends of the path**, which is where the Sun
 * is on the horizon, the shadow stretches over the ground and runs off the globe. Those
 * 32.48 km of width in 2026 are a single row, the last one; the previous one stays at 8.17
 * and the rest of the path below 5.6. In 2017 and in 2024, which begin and end with the Sun
 * higher up, there is not a single row above 0.7.
 *
 * **And those kilometres of the central line are delta T, not geometry.** Espenak computed
 * each eclipse with the delta T that was estimated back then; we use the observed one,
 * which for 2017 is already measured and for 2026 is still a prediction. Giving the engine
 * the same delta T NASA declares in each case, the worst point of the whole path goes from
 * 9.75 to **0.66 km** in 2017, from 18.09 to 3.49 in 2024 and from 32.48 to 7.79 in 2026.
 * And in 2017, which is the only one of the three NASA computed with a JPL ephemeris
 * (DE405) instead of with VSOP87 and ELP2000-85, the bulk of the path falls **below 0.1
 * km**. One second of delta T moves the shadow a good half kilometre over the ground, and
 * there is no way of knowing today what it will be worth in 2026.
 *
 * Width and duration hardly notice that: they depend on the size of the shadow and on how
 * fast it sweeps, not on where it lands. The duration stays below a third of a second over
 * the three whole paths, and the width below a kilometre in every row except the last two
 * of 2026, which are the ones above.
 */
final class CentralPath
{
    /** Equatorial radius of the Earth in the shadow geometry, in km. */
    private const EARTH_RADIUS_KM = Horizon::EQUATORIAL_RADIUS_KM;

    /**
     * Radius of the Moon for the UMBRA cone, in km.
     *
     * It is not `Horizon::MOON_RADIUS_KM`, which carries the k of the mean limb and is the
     * one that holds for the penumbra and for the first and the last contact. What draws
     * the band is the umbra, and its edge is set by the last light of the Sun slipping
     * through the valleys between the mountains of the limb: a radius 1.45 km smaller.
     * `Eclipses` applies the same criterion to its interior contacts, and that is why both
     * k live in `Horizon` instead of being written by hand here.
     */
    private const MOON_UMBRA_RADIUS_KM = Horizon::MOON_UMBRA_RADIUS_KM;

    /**
     * How far apart in time it steps in order to differentiate, in days: half a minute.
     *
     * It is used by the two derivatives that are needed, the direction of travel and the
     * envelope condition, and both differentiate at the same two instants. Half a minute is
     * short next to how long the shadow takes to change shape (hours) and long next to the
     * noise of a position, which is thousandths of an arcsecond.
     */
    private const DERIVATIVE_STEP = 30.0 / 86400.0;

    /** Directions around the axis along which the edge of the shadow is walked. */
    private const OUTLINE_SAMPLES = 360;

    /**
     * @param EclipseType $type
     * @param list<PathPoint> $points In time order, from the start of the path to the end.
     * @param PathPoint $maximum The point of the instant of maximum eclipse.
     */
    private function __construct(
        public readonly EclipseType $type,
        public readonly array $points,
        public readonly PathPoint $maximum,
    ) {}

    /**
     * The band of a central solar eclipse, sampled along time.
     *
     * The instants are counted from the maximum towards both sides, so the maximum is
     * ALWAYS one of the points: it is the datum any catalogue publishes and the one that
     * gets compared. And the two ends of the path are added as they are, because they are
     * the answer to where the band begins and where it ends and they do not fall on any
     * multiple of the step.
     *
     * @param SolarEclipse $eclipse It has to be central: `$eclipse->central` says so.
     * @param float $stepMinutes Separation between points. Two minutes is the step of
     *        NASA's tables, which is what it was compared against.
     * @return self
     *
     * @throws InvalidArgumentException If the eclipse is partial. Then the shadow axis
     *         passes by without touching the Earth and there is no band at all: returning
     *         an empty list would let one believe the path was computed and came out short.
     */
    public static function of(SolarEclipse $eclipse, float $stepMinutes = 2.0): self
    {
        if ($stepMinutes <= 0.0) {
            throw new InvalidArgumentException('The step between points of the path has to be positive.');
        }

        if (! $eclipse->central || $eclipse->centralLineStart === null || $eclipse->centralLineEnd === null) {
            throw new InvalidArgumentException(
                'A non-central eclipse ('.$eclipse->type->name().') has no path: the shadow axis never touches the Earth.'
            );
        }

        $start = Time::tt($eclipse->centralLineStart->jdUt);
        $end = Time::tt($eclipse->centralLineEnd->jdUt);
        $maximum = Time::tt($eclipse->maximum->jdUt);

        $points = [];
        $maximumPoint = null;

        foreach (self::instants($start, $maximum, $end, $stepMinutes) as $jdTT) {
            $point = self::point($jdTT);

            if ($point === null) {
                continue;
            }

            $points[] = $point;

            if ($jdTT === $maximum) {
                $maximumPoint = $point;
            }
        }

        if ($maximumPoint === null) {
            throw new InvalidArgumentException('The shadow axis does not cut the Earth at the maximum of the eclipse.');
        }

        return new self($eclipse->type, $points, $maximumPoint);
    }

    /**
     * A single point of the path, the one of any given instant.
     *
     * It exists because the path is sampled from the maximum outwards and its instants do
     * not fall on round hours, while any published table goes in whole minutes: without
     * this, comparing against a table would mean interpolating between two points of ours,
     * that is, comparing against a straight line the shadow does not travel. It also serves
     * for what gets asked the other way round, which is where the shadow is at such an hour.
     *
     * @param float $jdUt
     * @return PathPoint|null Null if at that instant the shadow axis passes by without
     *         touching the Earth, which is what happens before and after the path.
     */
    public static function pointAt(float $jdUt): ?PathPoint
    {
        return self::point(Time::tt($jdUt));
    }

    /**
     * Where the band begins: the first place on Earth the shadow axis steps on, at sunrise.
     *
     * @return PathPoint
     */
    public function start(): PathPoint
    {
        return $this->points[0];
    }

    /**
     * Where it ends: the last one, at sunset.
     *
     * @return PathPoint
     */
    public function end(): PathPoint
    {
        return $this->points[count($this->points) - 1];
    }

    /**
     * How long the shadow takes to travel the whole of it, in seconds.
     *
     * @return float
     */
    public function pathDurationSeconds(): float
    {
        return $this->start()->observation->secondsTo($this->end()->observation);
    }

    /**
     * The instants that are going to be computed, in order.
     *
     * @param float $start
     * @param float $maximum
     * @param float $end
     * @param float $stepMinutes
     * @return list<float>
     */
    private static function instants(float $start, float $maximum, float $end, float $stepMinutes): array
    {
        $step = $stepMinutes / 1440.0;
        $list = [$start, $maximum, $end];

        for ($k = 1; ; $k++) {
            $before = $maximum - $k * $step;
            $after = $maximum + $k * $step;
            $any = false;

            if ($before > $start) {
                $list[] = $before;
                $any = true;
            }

            if ($after < $end) {
                $list[] = $after;
                $any = true;
            }

            if (! $any) {
                break;
            }
        }

        sort($list);

        return $list;
    }

    /**
     * A point of the band, or null if at that instant the axis passes by.
     *
     * @param float $jdTT
     * @return PathPoint|null
     */
    private static function point(float $jdTT): ?PathPoint
    {
        $axis = self::axis($jdTT);

        if ($axis === null) {
            return null;
        }

        $jdUt = Time::ut($jdTT);
        [$latitude, $longitude] = Eclipses::geographicCoordinates($axis['point'], $jdUt);

        // The altitude of the Sun is the angle between the vertical of the place and the
        // direction to the Sun. The geodetic one, which is the one a spirit level lines up
        // with, not the geocentric one: they differ by up to eleven arcminutes, and it is
        // the same care the latitude already calls for.
        $altitude = rad2deg(asin(self::dot(
            self::geodeticVertical($axis['point']),
            self::unit(self::subtract($axis['sun'], $axis['point']))
        )));

        [$width, $north, $south] = self::edges($jdTT, $axis);

        return new PathPoint(
            observation: UtInstant::fromJd($jdUt),
            latitude: $latitude,
            longitude: $longitude,
            sunAltitude: $altitude,
            widthKm: $width,
            durationSeconds: self::duration($jdTT, $latitude, $longitude),
            northLatitude: $north === null ? null : $north[0],
            northLongitude: $north === null ? null : $north[1],
            southLatitude: $south === null ? null : $south[0],
            southLongitude: $south === null ? null : $south[1],
        );
    }

    /**
     * Where the shadow axis cuts the ellipsoid, with the positions that define it.
     *
     * The cut is done by `Eclipses::shadow()`, which is the one that has it written, and
     * not a copy: that way the point of maximum of the band and the eclipse's
     * `maximumLatitude` are the same number and not two approximations to the same place.
     *
     * **The condition is that the axis touches the ellipsoid, not the `central` flag.**
     * `central` is stricter (it asks for `r0` below `a·cos f1`, not below `a`) and it is
     * precisely the one `Eclipses` uses to place the two ends of the path: asking it, those
     * two instants fall right on the equality and rounding decides whether there is a point
     * or not.
     *
     * @param float $jdTT
     * @return array{point: array{0: float, 1: float, 2: float}, sun: array{0: float, 1: float, 2: float}, moon: array{0: float, 1: float, 2: float}}|null
     */
    private static function axis(float $jdTT): ?array
    {
        $sun = Horizon::equatorialOf(Body::Sun, $jdTT)->vector();
        $moon = Horizon::equatorialOf(Body::Moon, $jdTT)->vector();
        $shadow = Eclipses::shadow($sun, Horizon::SUN_RADIUS_KM, $moon);

        if ($shadow['r0'] >= self::EARTH_RADIUS_KM) {
            return null;
        }

        return ['point' => $shadow['surface'], 'sun' => $sun, 'moon' => $moon];
    }

    /**
     * The umbra cone at an instant: its axis and its half-angle.
     *
     * @param array{0: float, 1: float, 2: float} $sun
     * @param array{0: float, 1: float, 2: float} $moon
     * @return array{axis: array{0: float, 1: float, 2: float}, moon: array{0: float, 1: float, 2: float}, sine: float, cosine: float}
     */
    private static function cone(array $sun, array $moon): array
    {
        $separation = self::subtract($moon, $sun);
        $distance = self::norm($separation);

        // Half-angle of the umbra cone: the outer tangents to both spheres close at a
        // vertex beyond the Moon, and its sine is the difference of the radii divided by
        // the distance. With the umbra something happens that does not happen with the
        // penumbra: the vertex can fall on this side of the Earth, and then the eclipse is
        // annular.
        $sine = (Horizon::SUN_RADIUS_KM - self::MOON_UMBRA_RADIUS_KM) / $distance;

        return [
            'axis' => self::scaled($separation, 1.0 / $distance),
            'moon' => $moon,
            'sine' => $sine,
            'cosine' => sqrt(1.0 - $sine * $sine),
        ];
    }

    /**
     * Distance from a point to the edge of the umbra cone, in km. Negative inside.
     *
     * The radius of the cone at the height of the point comes out of its vertex: at `s`
     * from the Moon outwards the radius is `r/cos f - s·tan f`, which becomes zero at the
     * vertex and changes sign behind it. **It is compared against the absolute value**, and
     * that absolute value is what makes the same function hold for an annular one: past the
     * vertex the cone opens up again and what is left is the antumbra, where the ring is
     * seen.
     *
     * @param array{axis: array{0: float, 1: float, 2: float}, moon: array{0: float, 1: float, 2: float}, sine: float, cosine: float} $cone
     * @param array{0: float, 1: float, 2: float} $point
     * @return float
     */
    private static function distanceToEdge(array $cone, array $point): float
    {
        $fromTheMoon = self::subtract($point, $cone['moon']);
        $axial = self::dot($fromTheMoon, $cone['axis']);
        $perpendicular = self::norm(self::subtract($fromTheMoon, self::scaled($cone['axis'], $axial)));
        $radius = self::MOON_UMBRA_RADIUS_KM / $cone['cosine'] - $axial * $cone['sine'] / $cone['cosine'];

        return $perpendicular - abs($radius);
    }

    /**
     * A point of the edge of the shadow on the ellipsoid, in the direction `$angle` around
     * the axis. Null if that generatrix of the cone runs off the globe.
     *
     * @param array{axis: array{0: float, 1: float, 2: float}, moon: array{0: float, 1: float, 2: float}, sine: float, cosine: float} $cone
     * @param array{0: float, 1: float, 2: float} $onTheAxis A point of the axis on the surface, to know which side of the vertex the Earth is on.
     * @param float $angle Radians.
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function outline(array $cone, array $onTheAxis, float $angle): ?array
    {
        $axis = $cone['axis'];
        $toTheVertex = self::MOON_UMBRA_RADIUS_KM / $cone['sine'];
        $vertex = self::add($cone['moon'], self::scaled($axis, $toTheVertex));

        // Which side of the vertex the Earth is on: in front in a total one, behind in an
        // annular one. The generatrices leave the vertex towards that side, and without
        // this sign half the annular ones would be computed with the cone pointing the
        // wrong way.
        $sign = self::dot(self::subtract($onTheAxis, $cone['moon']), $axis) > $toTheVertex ? -1.0 : 1.0;

        $one = self::unit([-$axis[1], $axis[0], 0.0]);
        $other = self::cross($axis, $one);
        $direction = [];

        for ($i = 0; $i < 3; $i++) {
            $direction[$i] = $sign * $cone['cosine'] * $axis[$i]
                + $cone['sine'] * (cos($angle) * $one[$i] + sin($angle) * $other[$i]);
        }

        return self::ellipsoidIntersection($vertex, $direction, $cone);
    }

    /**
     * Where a straight line cuts the ellipsoid, in km. The cut that is returned is the one
     * facing the Moon, that is, the one on the lit side.
     *
     * **The trick of stretching the z that `Eclipses::shadow` uses does not hold here**, not
     * entirely: it is stretched so that the ellipsoid becomes a sphere and the quadratic is
     * solved, yes, but the cone has been built beforehand, in real coordinates, because
     * stretched it would stop being circular. What gets stretched here is only the straight
     * line, and a stretched straight line is still a straight line.
     *
     * **And the right cut is not the one with the smaller parameter.** In a total one the
     * vertex of the cone falls behind the Earth, so the generatrices arrive from the night
     * side and the first cut is the one at the back, where there is no eclipse to see. It is
     * chosen by the distance to the Moon measured along the axis, which does not depend on
     * where the line comes from.
     *
     * @param array{0: float, 1: float, 2: float} $origin
     * @param array{0: float, 1: float, 2: float} $direction
     * @param array{axis: array{0: float, 1: float, 2: float}, moon: array{0: float, 1: float, 2: float}, sine: float, cosine: float} $cone
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function ellipsoidIntersection(array $origin, array $direction, array $cone): ?array
    {
        $stretch = 1.0 / (1.0 - Horizon::FLATTENING);
        $o = [$origin[0], $origin[1], $origin[2] * $stretch];
        $d = [$direction[0], $direction[1], $direction[2] * $stretch];

        $a = self::dot($d, $d);
        $b = self::dot($o, $d);
        $c = self::dot($o, $o) - self::EARTH_RADIUS_KM * self::EARTH_RADIUS_KM;
        $discriminant = $b * $b - $a * $c;

        if ($discriminant < 0.0) {
            return null;
        }

        $root = sqrt($discriminant);
        $chosen = null;
        $closest = INF;

        foreach ([(-$b - $root) / $a, (-$b + $root) / $a] as $t) {
            $point = [$origin[0] + $t * $direction[0], $origin[1] + $t * $direction[1], $origin[2] + $t * $direction[2]];
            $axial = self::dot(self::subtract($point, $cone['moon']), $cone['axis']);

            if ($axial < $closest) {
                $closest = $axial;
                $chosen = $point;
            }
        }

        return $chosen;
    }

    /**
     * The two edges of the band at an instant and what there is from one to the other.
     *
     * **An edge of the band is NOT the furthest point of the shadow at that moment**, and
     * that was the first version. The band is what the shadow leaves swept, so its edge is
     * the ENVELOPE of all the shadows: the place where the shadow grazes and leaves, that
     * is, where the central phase lasts zero. The condition is that the distance to the edge
     * of the cone, for that point of the ground while the Earth turns underneath, has a null
     * derivative. With the instantaneous extremes the 2026 eclipse went 425 km off NASA's
     * tables in the stretch where the path turns quickly; with the envelope it stays at 5.
     *
     * **And the width is not the distance between those two points, but their projection
     * perpendicular to the motion.** The two edges are touched at different instants of the
     * path, so when the shadow runs sideways they end up stretched lengthwise: in that same
     * stretch of 2026 there are 424 km from one limit to the other and NASA publishes 318 km
     * of band, which is what comes out of projecting.
     *
     * @param float $jdTT
     * @param array{point: array{0: float, 1: float, 2: float}, sun: array{0: float, 1: float, 2: float}, moon: array{0: float, 1: float, 2: float}} $axis
     * @return array{0: float|null, 1: array{0: float, 1: float}|null, 2: array{0: float, 1: float}|null} [width in km, north limit, south limit]
     */
    private static function edges(float $jdTT, array $axis): array
    {
        $normal = self::normalToMotion($jdTT, $axis);

        if ($normal === null) {
            return [null, null, null];
        }

        $cone = self::cone($axis['sun'], $axis['moon']);
        $jdUt = Time::ut($jdTT);
        $neighbours = [];

        foreach ([-self::DERIVATIVE_STEP, self::DERIVATIVE_STEP] as $jump) {
            $sun = Horizon::equatorialOf(Body::Sun, $jdTT + $jump)->vector();
            $moon = Horizon::equatorialOf(Body::Moon, $jdTT + $jump)->vector();
            $neighbours[] = [
                'cone' => self::cone($sun, $moon),
                // A point of the ground stays put on the Earth and turns with it, and
                // turning with the Earth is exactly turning about the polar axis by however
                // much sidereal time has advanced. It is the same as redoing
                // `observerVector` with the other time, without going through latitude and
                // longitude again.
                'rotation' => deg2rad(Time::apparentSiderealTime(Time::ut($jdTT + $jump)) - Time::apparentSiderealTime($jdUt)),
            ];
        }

        $derivative = function (float $angle) use ($cone, $axis, $neighbours): ?float {
            $point = self::outline($cone, $axis['point'], $angle);

            if ($point === null) {
                return null;
            }

            return self::distanceToEdge($neighbours[1]['cone'], self::rotateAboutAxis($point, $neighbours[1]['rotation']))
                - self::distanceToEdge($neighbours[0]['cone'], self::rotateAboutAxis($point, $neighbours[0]['rotation']));
        };

        $limits = [];
        $previous = null;
        $previousAngle = 0.0;

        for ($i = 0; $i <= self::OUTLINE_SAMPLES; $i++) {
            $angle = 2.0 * M_PI * $i / self::OUTLINE_SAMPLES;
            $value = $derivative($angle);

            if ($value !== null && $previous !== null && (($value < 0.0) !== ($previous < 0.0))) {
                $zero = self::refineEnvelope($derivative, $previousAngle, $angle, $previous);

                if ($zero !== null) {
                    $point = self::outline($cone, $axis['point'], $zero);

                    if ($point !== null) {
                        $limits[] = [$point, self::dot(self::subtract($point, $axis['point']), $normal)];
                    }
                }
            }

            $previous = $value;
            $previousAngle = $angle;
        }

        if (count($limits) < 2) {
            return [null, null, null];
        }

        // The normal to the motion points to the left, and the shadow always runs from west
        // to east because the Moon overtakes the Earth: the left is the north of the band.
        // It is not decided by the latitude, and that matters: in the 2026 eclipse the path
        // goes over the pole and comes back out, and there the north edge of the band is
        // further south than the south edge.
        usort($limits, fn (array $a, array $b): int => $b[1] <=> $a[1]);
        $north = $limits[0];
        $south = $limits[count($limits) - 1];

        return [
            $north[1] - $south[1],
            Eclipses::geographicCoordinates($north[0], $jdUt),
            Eclipses::geographicCoordinates($south[0], $jdUt),
        ];
    }

    /**
     * The zero of the envelope condition between two directions, by bisection.
     *
     * Bisection and not secant because the function comes from an outline that can be cut
     * off (a generatrix that runs off the globe returns null) and there a secant would go
     * outside the stretch without noticing. Twenty-four rounds leave the point within
     * centimetres, and each round is arithmetic: in this loop there is not a single
     * ephemeris.
     *
     * @param callable(float): (float|null) $derivative
     * @param float $from
     * @param float $to
     * @param float $valueFrom
     * @return float|null
     */
    private static function refineEnvelope(callable $derivative, float $from, float $to, float $valueFrom): ?float
    {
        for ($i = 0; $i < 24; $i++) {
            $middle = ($from + $to) / 2.0;
            $value = $derivative($middle);

            if ($value === null) {
                return null;
            }

            if (($value < 0.0) === ($valueFrom < 0.0)) {
                $from = $middle;
                $valueFrom = $value;
            } else {
                $to = $middle;
            }
        }

        return ($from + $to) / 2.0;
    }

    /**
     * Horizontal unit vector perpendicular to the motion of the shadow, pointing to the left
     * of the path.
     *
     * The motion is measured ON THE GROUND and not in space: the three positions of the axis
     * are turned into latitude and longitude and raised again with the same sidereal time,
     * which is the way to subtract two places of the Earth without the rotation slipping
     * into the subtraction. At the two ends of the path there is no neighbour on one side
     * and it differentiates towards the other: it is worse, but there is nothing better
     * there, and the one that is missing is precisely the instant when the shadow had not
     * arrived yet.
     *
     * @param float $jdTT
     * @param array{point: array{0: float, 1: float, 2: float}, sun: array{0: float, 1: float, 2: float}, moon: array{0: float, 1: float, 2: float}} $axis
     * @return array{0: float, 1: float, 2: float}|null
     */
    private static function normalToMotion(float $jdTT, array $axis): ?array
    {
        $before = self::axis($jdTT - self::DERIVATIVE_STEP);
        $after = self::axis($jdTT + self::DERIVATIVE_STEP);

        if ($before === null && $after === null) {
            return null;
        }

        $jdUt = Time::ut($jdTT);
        [$latitude, $longitude] = Eclipses::geographicCoordinates($axis['point'], $jdUt);
        $here = Horizon::observerVector($latitude, $longitude, 0.0, $jdUt);

        /* Each point of the axis is turned into latitude and longitude with ITS OWN time,
           because the axes are stuck to the stars and the Earth turns underneath, and all
           three are raised again with the middle time. Subtracting them without that, what
           comes out is not where the shadow goes but where the shadow goes plus however much
           the Earth has turned in a minute: in 2024 it left the band seven and a half
           kilometres narrower than what NASA publishes. */
        $ground = function (?array $other, float $shift) use ($jdUt, $here, $jdTT): array {
            if ($other === null) {
                return $here;
            }

            [$lat, $lon] = Eclipses::geographicCoordinates($other['point'], Time::ut($jdTT + $shift));

            return Horizon::observerVector($lat, $lon, 0.0, $jdUt);
        };

        $advance = self::subtract(
            $ground($after, self::DERIVATIVE_STEP),
            $ground($before, -self::DERIVATIVE_STEP)
        );

        [$east, $north] = self::horizontalBasis($here);
        $eastwards = self::dot($advance, $east);
        $northwards = self::dot($advance, $north);
        $stepLength = sqrt($eastwards * $eastwards + $northwards * $northwards);

        if ($stepLength <= 0.0) {
            return null;
        }

        // The horizontal basis is rebuilt at the point of the axis, which goes in the axes
        // of the equator of date, because that is where the outline that is projected
        // afterwards lives.
        [$eastThere, $northThere] = self::horizontalBasis($axis['point']);
        $normal = [];

        for ($i = 0; $i < 3; $i++) {
            $normal[$i] = (-$northwards * $eastThere[$i] + $eastwards * $northThere[$i]) / $stepLength;
        }

        return $normal;
    }

    /**
     * How long the central phase lasts for whoever stands still at a point of the ground, in
     * seconds.
     *
     * **It is computed for a fixed GEOGRAPHIC point, which turns with the Earth**, and not
     * by dividing the width of the shadow by how fast it runs. The Earth turns the same way
     * the shadow goes, so the ground flees from it: at the equator and with the Sun high
     * that lengthens the totality by almost a minute over what a shadow passing above still
     * ground would give. It is the same computation `Eclipses::localOccultation` does for
     * the second and third contacts, written here with the cone instead of with the
     * semidiameters so as not to drag along the search for the minimum and the risings and
     * settings, which are not needed here.
     *
     * @param float $jdTT
     * @param float $latitude
     * @param float $longitude
     * @return float|null Null if the point never gets to be inside the umbra.
     */
    private static function duration(float $jdTT, float $latitude, float $longitude): ?float
    {
        $inside = function (float $t) use ($latitude, $longitude): float {
            $cone = self::cone(
                Horizon::equatorialOf(Body::Sun, $t)->vector(),
                Horizon::equatorialOf(Body::Moon, $t)->vector()
            );

            return self::distanceToEdge($cone, Horizon::observerVector($latitude, $longitude, 0.0, Time::ut($t)));
        };

        if ($inside($jdTT) >= 0.0) {
            return null;
        }

        $entry = null;
        $exit = null;

        // The longest totality that can happen is seven and a half minutes, so the bracket
        // opens from two minutes and doubles: it never needs to go past half an hour.
        foreach ([1, 2, 4, 8, 16] as $factor) {
            $half = $factor * 120.0 / 86400.0;

            if ($entry === null && $inside($jdTT - $half) > 0.0) {
                $entry = Crossings::root($inside, $jdTT - $half, $jdTT, 1e-8);
            }

            if ($exit === null && $inside($jdTT + $half) > 0.0) {
                $exit = Crossings::root($inside, $jdTT, $jdTT + $half, 1e-8);
            }

            if ($entry !== null && $exit !== null) {
                break;
            }
        }

        if ($entry === null || $exit === null) {
            return null;
        }

        return ($exit - $entry) * 86400.0;
    }

    /**
     * Turn a vector about the polar axis, which is what the passing of time does to a point
     * of the ground.
     *
     * @param array{0: float, 1: float, 2: float} $vector
     * @param float $angle Radians.
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rotateAboutAxis(array $vector, float $angle): array
    {
        $c = cos($angle);
        $s = sin($angle);

        return [$c * $vector[0] - $s * $vector[1], $s * $vector[0] + $c * $vector[1], $vector[2]];
    }

    /**
     * The geodetic vertical at a point of the ellipsoid: the normal to the surface, which is
     * the one a plumb line marks and not the one that goes to the centre of the Earth.
     *
     * @param array{0: float, 1: float, 2: float} $point
     * @return array{0: float, 1: float, 2: float}
     */
    private static function geodeticVertical(array $point): array
    {
        return self::unit([$point[0], $point[1], $point[2] / (1.0 - Horizon::FLATTENING) ** 2]);
    }

    /**
     * The east and north vectors of the horizon of a point.
     *
     * @param array{0: float, 1: float, 2: float} $point
     * @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}}
     */
    private static function horizontalBasis(array $point): array
    {
        $vertical = self::geodeticVertical($point);
        $east = self::unit([-$point[1], $point[0], 0.0]);

        return [$east, self::cross($vertical, $east)];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return array{0: float, 1: float, 2: float}
     */
    private static function add(array $a, array $b): array
    {
        return [$a[0] + $b[0], $a[1] + $b[1], $a[2] + $b[2]];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return array{0: float, 1: float, 2: float}
     */
    private static function subtract(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param float $k
     * @return array{0: float, 1: float, 2: float}
     */
    private static function scaled(array $a, float $k): array
    {
        return [$a[0] * $k, $a[1] * $k, $a[2] * $k];
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

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return array{0: float, 1: float, 2: float}
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
     * @param array{0: float, 1: float, 2: float} $a
     * @return float
     */
    private static function norm(array $a): float
    {
        return sqrt(self::dot($a, $a));
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @return array{0: float, 1: float, 2: float}
     */
    private static function unit(array $a): array
    {
        return self::scaled($a, 1.0 / self::norm($a));
    }
}
