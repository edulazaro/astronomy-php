<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\SolarEclipse;
use Astronomy\Eclipses;
use Astronomy\CentralPath;
use Astronomy\Horizon;
use Astronomy\Place;
use Astronomy\PathPoint;
use Astronomy\Time;
use Astronomy\EclipseType;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The path of a solar eclipse, against the NASA catalogue.
 *
 * The figures do NOT come out of our own code: they are the ones Fred Espenak publishes in
 * the path tables and the Besselian elements of the NASA eclipse site, copied in by hand so
 * the suite runs with no network, exactly as `EphemerisTest` does with the JPL positions.
 * Comparing against yourself proves nothing.
 *
 * ## What was measured
 *
 * Four eclipses, three total and one annular, comparing the point of greatest eclipse, the
 * width of the path there, the duration of totality and the Sun's altitude:
 *
 * | | greatest point | width | duration | Sun's altitude |
 * |---|---|---|---|---|
 * | 2017 Aug 21, total | 3.03 km | 0.01 km | 0.13 s | 0.00° |
 * | 2024 Apr 08, total | 4.57 km | 0.04 km | 0.19 s | 0.01° |
 * | 2026 Aug 12, total | 12.67 km | 0.39 km | 0.08 s | 0.04° |
 * | 2023 Oct 14, annular | 3.61 km | 0.16 km | 0.04 s | 0.01° |
 *
 * And the whole path, row by row and at the same UT instants the NASA tabulates: in 2017,
 * over its 96 rows, the central line comes out at 3.01 km at the worst point, the two edges
 * of the path at 3.54 and the width at 0.67. Here go six rows of that path and two of 2026's,
 * which are the ones it takes for a fault not to slip through.
 *
 * ## Why the kilometres of the greatest point are larger than the ones of the width
 *
 * **Because they are delta T, and delta T is not geometry.** The NASA computed each eclipse
 * with the delta T estimated at the time (68.4 s for 2017, 71.4 for 2026) and here the
 * observed one is used, which for 2026 is still a prediction. Handing the engine the delta T
 * the NASA declares for each case, the worst point of the whole path goes from 9.75 to
 * 0.66 km in 2017, from 18.09 to 3.49 in 2024 and from 32.48 to 7.79 in 2026. And in 2017,
 * which is the only one of the four the NASA computed with a JPL ephemeris (DE405) instead
 * of VSOP87 and ELP2000-85, the bulk of the path stays under 0.1 km.
 *
 * The width and the duration barely notice: they depend on the size of the shadow and how
 * fast it sweeps, not on where it lands.
 *
 * ## And two checks that ask nothing of anybody
 *
 * Against the definition, which is stronger than against a table because it does not depend
 * on what ephemeris the other side used:
 *
 * - **On the central line the Sun and the Moon look aligned**, meaning the separation between
 *   their centres seen from there is zero. Measured: 5e-13 degrees, which is the rounding of
 *   going through latitude and longitude and back.
 * - **At the edge of the path totality lasts zero.** That is where the shadow grazes and
 *   leaves, which is what defines an envelope. Measured with `Eclipses::localSolar`, which is
 *   an entirely different stretch of code: at the edge there is no totality or it lasts under
 *   0.15 s, a touch further in it lasts three quarters of a minute, and a touch further out
 *   there is nothing at all.
 */
class CentralPathTest extends TestCase
{
    /** Margin for the point of greatest eclipse, in km. Worst measured case: 12.67 in 2026. */
    private const TOLERANCE_MAXIMUM_KM = 15.0;

    /** Margin for the width at the greatest point, in km. Worst measured case: 0.39 in 2026. */
    private const TOLERANCE_WIDTH_KM = 0.6;

    /** Margin for the duration of totality, in seconds. Worst measured case: 0.19 in 2024. */
    private const TOLERANCE_DURATION_S = 0.4;

    /** Margin for the Sun's altitude, in degrees. The NASA publishes it to one decimal. */
    private const TOLERANCE_ALTITUDE = 0.1;

    /**
     * The NASA's instant of greatest eclipse, from the Besselian elements page of each
     * eclipse.
     *
     * The NASA defines it as the moment the shadow axis passes closest to the centre of
     * the Earth, which is exactly what `Eclipses` looks for, so the two numbers speak of
     * the same thing.
     *
     * [day, type, latitude, longitude, Sun's altitude, width in km, duration in seconds]
     */
    private const MAXIMA = [
        ['2017-08-21', 'total', 36.966667, -87.671667, 63.9, 114.7, 160.1],
        ['2024-04-08', 'total', 25.286667, -104.138333, 69.8, 197.5, 268.1],
        ['2026-08-12', 'total', 65.225000, -25.228333, 25.8, 294.0, 138.2],
        ['2023-10-14', 'annular', 11.370000, -83.101667, 67.9, 187.4, 317.2],
    ];

    /**
     * Rows of the path table of the total of 21 August 2017 over the United States,
     * which is the best documented eclipse of all and the only one of the four the NASA
     * computed with a JPL ephemeris.
     *
     * [UT time, north limit, south limit, central line, width in km, duration in seconds]
     */
    private const PATH_2017 = [
        ['17:00', 44.751667, -142.046667, 44.048333, -140.963333, 44.401667, -141.495000, 87.0, 90.9],
        ['17:30', 44.611667, -114.650000, 43.636667, -114.468333, 44.123333, -114.555000, 105.0, 134.0],
        ['18:00', 41.333333, -98.148333, 40.343333, -98.461667, 40.838333, -98.305000, 112.0, 155.7],
        ['18:26', 37.350000, -87.201667, 36.425000, -87.778333, 36.888333, -87.491667, 115.0, 160.1],
        ['19:00', 30.998333, -74.581667, 30.206667, -75.355000, 30.603333, -74.970000, 114.0, 146.8],
        ['19:30', 24.151667, -62.225000, 23.521667, -63.106667, 23.838333, -62.668333, 105.0, 118.1],
    ];

    /**
     * Two rows of the total of 12 August 2026, chosen where the path crosses over the
     * North Pole and comes back down.
     *
     * **The 17:06 one is the one that catches, on its own, the fault of naming the edges
     * by their latitude**: there the NORTH edge of the path sits at 84.85° and the SOUTH
     * one at 89.07°, four degrees further north. One edge is the one on the left of the
     * march and the other the one on the right, and that does not change because the
     * path bends; the latitude does. Confusing them is five hundred kilometres.
     *
     * [UT time, north limit, south limit, central line, width in km, duration in seconds]
     */
    private const PATH_2026 = [
        ['17:06', 84.850000, 90.395000, 89.066667, 38.148333, 87.278333, 81.525000, 274.0, 114.6],
        ['17:20', 80.441667, -18.841667, 78.986667, -33.760000, 79.773333, -26.981667, 278.0, 130.0],
    ];

    /** Margin for the path rows, wider than the greatest point's for the same reason: delta T. */
    private const TOLERANCE_PATH_KM = 10.0;

    /** The NASA tabulates the width in whole kilometres, so half a km is its own rounding. */
    private const TOLERANCE_PATH_WIDTH_KM = 3.0;

    /** @var array<string, CentralPath> */
    private static array $paths = [];

    /** @var array<string, SolarEclipse> */
    private static array $eclipses = [];

    public function test_the_point_of_greatest_eclipse_is_the_one_the_nasa_publishes(): void
    {
        foreach (self::MAXIMA as [$day, $type, $latitude, $longitude, , , ]) {
            $path = self::path($day);

            $this->assertSame($type, $path->type->value, "The type of the eclipse of $day");

            $distance = self::distanceKm(
                $path->maximum->latitude, $path->maximum->longitude, $latitude, $longitude
            );

            $this->assertLessThan(
                self::TOLERANCE_MAXIMUM_KM,
                $distance,
                sprintf('The greatest point of %s falls %.2f km from the NASA\'s', $day, $distance)
            );
        }
    }

    public function test_the_width_the_duration_and_the_suns_altitude_at_the_greatest_point_are_the_nasas(): void
    {
        foreach (self::MAXIMA as [$day, , , , $altitude, $width, $duration]) {
            $point = self::path($day)->maximum;

            $this->assertNotNull($point->widthKm, "The path of $day has to have a width at the greatest point");
            $this->assertNotNull($point->durationSeconds, "The path of $day has to have a duration at the greatest point");

            $this->assertEqualsWithDelta($width, $point->widthKm, self::TOLERANCE_WIDTH_KM, "Width of $day");
            $this->assertEqualsWithDelta($duration, $point->durationSeconds, self::TOLERANCE_DURATION_S, "Duration of $day");
            $this->assertEqualsWithDelta($altitude, $point->sunAltitude, self::TOLERANCE_ALTITUDE, "Sun's altitude of $day");
        }
    }

    public function test_the_path_of_2017_passes_where_the_nasa_says(): void
    {
        $this->checkPath('2017-08-21', self::PATH_2017);
    }

    public function test_the_path_of_2026_crosses_the_pole_without_the_edges_swapping_places(): void
    {
        $this->checkPath('2026-08-12', self::PATH_2026);
    }

    public function test_on_the_central_line_the_sun_and_the_moon_look_aligned(): void
    {
        // The check against the definition: the central line is where the shadow axis
        // touches the ground, and the axis is the straight line joining the two centres.
        // Anyone standing there sees them one on top of the other. There is no need to
        // ask anybody outside, and it does not depend on what ephemeris the outside
        // source used. It is reached by a different road than the calculation: latitude
        // and longitude, the observer's vector and parallax, that is `Horizon` instead
        // of `Eclipses`.
        $worst = 0.0;

        foreach (['2017-08-21', '2023-10-14'] as $day) {
            foreach (self::path($day, 25.0)->points as $point) {
                $jdTT = Time::tt($point->observation->jdUt);
                $horizon = new Horizon(self::place($point->latitude, $point->longitude));

                $sun = $horizon->topocentric(Horizon::equatorialOf(Body::Sun, $jdTT), $point->observation->jdUt);
                $moon = $horizon->topocentric(Horizon::equatorialOf(Body::Moon, $jdTT), $point->observation->jdUt);

                $worst = max($worst, $sun->separation($moon));
            }
        }

        $this->assertLessThan(1e-8, $worst, sprintf('The centres are %.3e degrees apart on the central line', $worst));
    }

    public function test_the_duration_is_the_same_as_the_local_circumstances(): void
    {
        // `Eclipses::localOccultation` computes the second and third contact through an
        // entirely different road: angular separation between the two centres and
        // topocentric semidiameters, in degrees, against the shadow cone in kilometres
        // used here. If the two do not agree, one of them is wrong, and no outside
        // table is needed to know it.
        $worst = 0.0;

        foreach (['2017-08-21', '2023-10-14'] as $day) {
            $eclipse = self::eclipse($day);

            foreach (self::path($day, 25.0)->points as $point) {
                if ($point->durationSeconds === null) {
                    continue;
                }

                $local = Eclipses::localSolar($eclipse, self::place($point->latitude, $point->longitude));

                $this->assertNotNull($local?->contact2, 'On the central line there has to be totality');
                $this->assertNotNull($local->contact3);

                $worst = max($worst, abs($point->durationSeconds - $local->contact2->secondsTo($local->contact3)));
            }
        }

        $this->assertLessThan(0.01, $worst, sprintf('The two durations are %.5f s apart', $worst));
    }

    public function test_the_edge_of_the_path_is_where_totality_lasts_zero(): void
    {
        // The other check against the definition. The path is what the shadow leaves
        // swept out, so its edge is not the point furthest from the shadow at one
        // instant, it is where the shadow grazes and leaves: totality lasts zero. It is
        // measured by walking from the central line towards the edge and stepping a bit
        // past it.
        $eclipse = self::eclipse('2017-08-21');

        foreach (self::path('2017-08-21', 40.0)->points as $point) {
            if ($point->northLatitude === null) {
                continue;
            }

            foreach ([[$point->northLatitude, $point->northLongitude], [$point->southLatitude, $point->southLongitude]] as [$latitude, $longitude]) {
                $inside = self::localDuration($eclipse, $point, $latitude, $longitude, 0.90);
                $right = self::localDuration($eclipse, $point, $latitude, $longitude, 1.00);
                $outside = self::localDuration($eclipse, $point, $latitude, $longitude, 1.02);

                $this->assertNotNull($inside, 'Inside the path there has to be totality');
                $this->assertGreaterThan(30.0, $inside, 'At 90% of the way to the edge totality still lasts');
                $this->assertLessThan(0.5, $right ?? 0.0, 'At the edge totality lasts zero');
                $this->assertNull($outside, 'Outside the path there is no totality');
            }
        }
    }

    public function test_the_greatest_point_of_the_path_is_the_greatest_point_of_the_eclipse(): void
    {
        // The two come out of the same cut of the axis with the ellipsoid, the one in
        // `Eclipses::shadow`, and that is why they have to be the same number and not
        // two similar approximations. This test is the one that breaks the day someone
        // rewrites the cut here instead of asking for it.
        foreach (self::MAXIMA as [$day, , , , , , ]) {
            $eclipse = self::eclipse($day);
            $maximum = self::path($day)->maximum;

            $this->assertEqualsWithDelta($eclipse->maximumLatitude, $maximum->latitude, 1e-6, "Latitude of the greatest point of $day");
            $this->assertEqualsWithDelta($eclipse->maximumLongitude, $maximum->longitude, 1e-6, "Longitude of the greatest point of $day");
            $this->assertEqualsWithDelta($eclipse->maximum->jdUt, $maximum->observation->jdUt, 1e-9, "Instant of the greatest point of $day");
        }
    }

    public function test_the_path_starts_and_ends_at_the_central_line_contacts(): void
    {
        foreach (self::MAXIMA as [$day, , , , , , ]) {
            $eclipse = self::eclipse($day);
            $path = self::path($day);

            $this->assertEqualsWithDelta(
                $eclipse->centralLineStart->jdUt, $path->start()->observation->jdUt, 1e-9, "Start of $day"
            );
            $this->assertEqualsWithDelta(
                $eclipse->centralLineEnd->jdUt, $path->end()->observation->jdUt, 1e-9, "End of $day"
            );

            // The points run in time order and none repeats.
            $previous = -INF;

            foreach ($path->points as $point) {
                $this->assertGreaterThan($previous, $point->observation->jdUt);
                $previous = $point->observation->jdUt;
            }
        }
    }

    public function test_at_the_ends_of_the_path_the_shadow_arrives_grazing(): void
    {
        // This is what explains why the two ends are the worst measured points of the
        // path: the shadow lands and takes off tangentially, so its speed over the
        // ground is tens of kilometres a second, against the 0.65 of the greatest
        // point. There a second of delta T is worth thirty kilometres and halfway
        // along the path it is worth half one.
        $path = self::path('2017-08-21', 1.0);
        $points = $path->points;
        $last = count($points) - 1;

        $atTheStart = self::speedKmPerSecond($points[0], $points[1]);
        $atTheEnd = self::speedKmPerSecond($points[$last - 1], $points[$last]);
        $inTheMiddle = self::speedKmPerSecond($path->maximum, self::pointAfter($points, $path->maximum));

        $this->assertGreaterThan(10.0, $atTheStart, 'The shadow lands tangentially and runs very fast');
        $this->assertGreaterThan(10.0, $atTheEnd, 'And takes off the same way');
        $this->assertLessThan(1.0, $inTheMiddle, 'At the greatest point the shadow runs under a km a second');

        // And there the shadow runs off the globe, so there are no two edges to measure.
        $this->assertNull($points[0]->widthKm);
        $this->assertFalse($points[0]->hasEdges());
        $this->assertNull($points[$last]->widthKm);
    }

    public function test_a_partial_eclipse_has_no_path(): void
    {
        // The partial of 30 April 2022: gamma -1.19, meaning the axis passes a radius
        // and a bit from the centre of the Earth and never touches it. An empty list
        // would leave the impression that the path was computed and came out short.
        $eclipse = self::eclipse('2022-04-30');

        $this->assertSame(EclipseType::Partial, $eclipse->type);
        $this->assertFalse($eclipse->central);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no path');

        CentralPath::of($eclipse);
    }

    public function test_the_step_between_points_has_to_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CentralPath::of(self::eclipse('2017-08-21'), 0.0);
    }

    public function test_outside_the_path_there_is_no_point_of_the_path(): void
    {
        $path = self::path('2017-08-21');

        // Half an hour before the axis touches the Earth and half an hour after it leaves.
        $this->assertNull(CentralPath::pointAt($path->start()->observation->jdUt - 0.5 / 24.0));
        $this->assertNull(CentralPath::pointAt($path->end()->observation->jdUt + 0.5 / 24.0));
        $this->assertInstanceOf(PathPoint::class, CentralPath::pointAt($path->maximum->observation->jdUt));
    }

    /**
     * Compares a path against the rows the NASA publishes, at the same UT instants.
     *
     * @param string $day
     * @param list<array{0: string, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float, 8: float}> $rows
     * @return void
     */
    private function checkPath(string $day, array $rows): void
    {
        $midnight = Time::julianDay(new DateTimeImmutable($day.' 00:00:00', new DateTimeZone('UTC')));

        foreach ($rows as [$time, $latN, $lonN, $latS, $lonS, $latC, $lonC, $width, $duration]) {
            [$hours, $minutes] = explode(':', $time);
            $point = CentralPath::pointAt($midnight + ((int) $hours * 60 + (int) $minutes) / 1440.0);

            $this->assertNotNull($point, "$day at $time there has to be a path");
            $this->assertNotNull($point->widthKm, "$day at $time it has to have edges");
            $this->assertNotNull($point->durationSeconds);

            foreach ([
                ['central line', $point->latitude, $point->longitude, $latC, $lonC],
                ['north limit', $point->northLatitude, $point->northLongitude, $latN, $lonN],
                ['south limit', $point->southLatitude, $point->southLongitude, $latS, $lonS],
            ] as [$which, $ourLatitude, $ourLongitude, $theirLatitude, $theirLongitude]) {
                $distance = self::distanceKm($ourLatitude, $ourLongitude, $theirLatitude, $theirLongitude);

                $this->assertLessThan(
                    self::TOLERANCE_PATH_KM,
                    $distance,
                    sprintf('%s of %s at %s: %.2f km from the NASA\'s', $which, $day, $time, $distance)
                );
            }

            $this->assertEqualsWithDelta($width, $point->widthKm, self::TOLERANCE_PATH_WIDTH_KM, "Width of $day at $time");
            $this->assertEqualsWithDelta($duration, $point->durationSeconds, self::TOLERANCE_DURATION_S, "Duration of $day at $time");
        }
    }

    /**
     * How long totality lasts at a point that is `$fraction` of the way from the
     * central line to one of the edges. At one it is the edge and past one it is
     * already outside.
     *
     * @param SolarEclipse $eclipse
     * @param PathPoint $point
     * @param float $latitude
     * @param float $longitude
     * @param float $fraction
     * @return float|null
     */
    private static function localDuration(SolarEclipse $eclipse, PathPoint $point, float $latitude, float $longitude, float $fraction): ?float
    {
        $local = Eclipses::localSolar($eclipse, self::place(
            $point->latitude + $fraction * ($latitude - $point->latitude),
            $point->longitude + $fraction * ($longitude - $point->longitude)
        ));

        if ($local?->contact2 === null || $local->contact3 === null) {
            return null;
        }

        return $local->contact2->secondsTo($local->contact3);
    }

    /**
     * @param list<PathPoint> $points
     * @param PathPoint $point
     * @return PathPoint
     */
    private static function pointAfter(array $points, PathPoint $point): PathPoint
    {
        foreach ($points as $candidate) {
            if ($candidate->observation->jdUt > $point->observation->jdUt) {
                return $candidate;
            }
        }

        return $point;
    }

    /**
     * @param PathPoint $one
     * @param PathPoint $other
     * @return float
     */
    private static function speedKmPerSecond(PathPoint $one, PathPoint $other): float
    {
        return self::distanceKm($one->latitude, $one->longitude, $other->latitude, $other->longitude)
            / $one->observation->secondsTo($other->observation);
    }

    /**
     * Any place on Earth, with no name: here only its coordinates are needed.
     *
     * @param float $latitude
     * @param float $longitude
     * @return Place
     */
    private static function place(float $latitude, float $longitude): Place
    {
        return new Place('path', null, '', '', $latitude, $longitude, 'UTC');
    }

    /**
     * Distance over the sphere between two points, in km. With the mean radius, which
     * is more than enough for comparing two positions that are kilometres apart.
     *
     * @param float $latitude1
     * @param float $longitude1
     * @param float $latitude2
     * @param float $longitude2
     * @return float
     */
    private static function distanceKm(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $radius = 6371.0;
        $a = deg2rad($latitude1);
        $b = deg2rad($latitude2);
        $sine = sin(deg2rad($latitude2 - $latitude1) / 2) ** 2
            + cos($a) * cos($b) * sin(deg2rad($longitude2 - $longitude1) / 2) ** 2;

        return 2 * $radius * asin(min(1.0, sqrt($sine)));
    }

    /**
     * The solar eclipse of a day, computed only once.
     *
     * @param string $day
     * @return SolarEclipse
     */
    private static function eclipse(string $day): SolarEclipse
    {
        if (! isset(self::$eclipses[$day])) {
            $midnight = Time::julianDay(new DateTimeImmutable($day.' 00:00:00', new DateTimeZone('UTC')));
            self::$eclipses[$day] = Eclipses::solar($midnight, $midnight + 1.0)[0];
        }

        return self::$eclipses[$day];
    }

    /**
     * The path of an eclipse, computed only once per step.
     *
     * @param string $day
     * @param float $stepMinutes
     * @return CentralPath
     */
    private static function path(string $day, float $stepMinutes = 15.0): CentralPath
    {
        $key = $day.'|'.$stepMinutes;

        if (! isset(self::$paths[$key])) {
            self::$paths[$key] = CentralPath::of(self::eclipse($day), $stepMinutes);
        }

        return self::$paths[$key];
    }
}
