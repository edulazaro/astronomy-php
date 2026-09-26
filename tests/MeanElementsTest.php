<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\MeanElements;
use Astronomy\NodesAndApsides;
use Astronomy\Time;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The mean node and the mean perihelion of the planets: `swe_nod_aps` with `SE_NODBIT_MEAN`.
 *
 * The numbers come from the table of Simon, Bretagnon, Chapront, Chapront-Touzé, Francou and
 * Laskar (1994), *A&A* 282, 663-683, downloaded by the `astronomy mean-elements` command from
 * ERFA's `plan94.c`. There is not one hand-typed coefficient here, same as with nutation.
 *
 * Checked three ways, and it is worth seeing that they are three different families:
 *
 * 1. **Against Swiss**, which is the only thing that has this, at three epochs spread over eight
 *    hundred years. The values are copied here so the suite runs with no network, exactly as
 *    `swe_nod_aps` gives them with `SEFLG_HELCTR`: heliocentric and in the true ecliptic of the
 *    date, which is this engine's frame.
 * 2. **Against the definition itself**, which asks nothing of anyone: the sidereal period that
 *    comes out of the linear term of the mean longitude has to be the published period of each
 *    planet. This is the check that catches a unit error on its own, which here is the easiest
 *    mistake to make.
 * 3. **Against the geometry of the frame**: the Earth's mean inclination is zero at J2000 and
 *    grows, which is what shows the table is referred to a FIXED plane and not the ecliptic of
 *    the date.
 *
 * **The only thing that does not match to under an arcsecond is Neptune's perihelion, and it has
 * an owner.** It carries 12.45 seconds against Swiss in 1700, 12.30 in 2000 and 11.84 in 2300: it
 * does not grow with time, so it is neither ERFA's t² truncation (which would be zero at J2000)
 * nor a frame error (which would grow). It is that Swiss's table and Simon's do not carry the
 * same constant term for Neptune. It is pinned separately with its reason, instead of loosening
 * the tolerance of the other six until it fits: a wide tolerance hides the day another one moves.
 */
class MeanElementsTest extends TestCase
{
    /** Mean node margin against Swiss, in arcseconds. Worst measured: 0.98 (Jupiter in 1700). */
    private const NODE_TOLERANCE = 1.5;

    /** And of the perihelion latitude. Worst measured: 0.09 (Saturn). */
    private const LATITUDE_TOLERANCE = 0.15;

    /** Of the perihelion, for the six that are not Neptune. Worst measured: 2.17 (Saturn in 1700). */
    private const PERIHELION_TOLERANCE = 2.5;

    /**
     * Of the perihelion distance, IN RELATIVE terms and not in astronomical units.
     *
     * In absolute terms one would be needed per body, because it runs from 4e-7 in Mercury to
     * 1.4e-2 in Neptune, and the two figures say the same thing divided by the size of the
     * orbit. Worst measured in relative terms: 4.7e-4, Neptune again.
     */
    private const DISTANCE_TOLERANCE = 5.0e-4;

    /**
     * The mean node and perihelion that Swiss gives, heliocentric and in the ecliptic of the
     * date. Each row: Julian day TT, ascending node, perihelion longitude, perihelion latitude
     * and perihelion distance.
     *
     * @return array<string, list<array{float, float, float, float, float}>>
     */
    public static function fromSwiss(): array
    {
        return [
            'mercury' => [
                [2341970.0, 44.772921, 72.611013, 3.281287, 0.307522],
                [2451545.0, 48.327023, 77.270087, 3.402980, 0.307499],
                [2561120.0, 51.886400, 81.936862, 3.523664, 0.307475],
            ],
            'venus' => [
                [2341970.0, 73.979243, 127.298445, 2.721150, 0.718327],
                [2451545.0, 76.676050, 131.512476, 2.776251, 0.718432],
                [2561120.0, 79.382286, 135.709415, 2.828613, 0.718535],
            ],
            'mars' => [
                [2341970.0, 47.240916, 330.544061, -1.801991, 1.381781],
                [2451545.0, 49.554223, 336.064500, -1.773509, 1.381367],
                [2561120.0, 51.869929, 341.589377, -1.739730, 1.380954],
            ],
            'jupiter' => [
                [2341970.0, 97.404189, 9.502289, -1.318917, 4.952873],
                [2451545.0, 100.460571, 14.328438, -1.300303, 4.950304],
                [2561120.0, 103.526283, 19.175235, -1.280575, 4.947779],
            ],
            'saturn' => [
                [2341970.0, 111.032229, 87.192079, -1.010982, 9.014639],
                [2451545.0, 113.661654, 93.070726, -0.875805, 9.024530],
                [2561120.0, 116.291016, 98.966355, -0.738194, 9.034532],
            ],
            'uranus' => [
                [2341970.0, 72.453146, 168.547508, 0.766855, 18.327121],
                [2451545.0, 74.002077, 173.002094, 0.763678, 18.328711],
                [2561120.0, 75.577234, 177.462646, 0.759223, 18.330273],
            ],
            'neptune' => [
                [2341970.0, 128.478815, 43.849948, -1.789924, 29.840332],
                [2451545.0, 131.780187, 48.122823, -1.759125, 29.839752],
                [2561120.0, 135.088349, 52.404603, -1.727791, 29.839173],
            ],
        ];
    }

    public function test_the_mean_node_and_perihelion_match_swiss(): void
    {
        foreach (self::fromSwiss() as $name => $cases) {
            $body = Body::from($name);

            foreach ($cases as [$jd, $node, $perihelion, $latitude, $distance]) {
                $orbit = NodesAndApsides::mean($body, $jd);
                $where = sprintf('%s at Julian day %.1f', $body->name(), $jd);

                $this->assertLessThan(
                    self::NODE_TOLERANCE,
                    $this->differenceInSeconds($orbit->ascendingNode, $node),
                    'Mean node of '.$where
                );

                $this->assertLessThan(
                    self::LATITUDE_TOLERANCE,
                    abs($orbit->perihelionLatitude - $latitude) * 3600,
                    'Mean perihelion latitude of '.$where
                );

                $this->assertLessThan(
                    self::DISTANCE_TOLERANCE,
                    abs($orbit->perihelionDistance - $distance) / $distance,
                    'Mean perihelion distance of '.$where
                );

                if ($body === Body::Neptune) {
                    continue;
                }

                $this->assertLessThan(
                    self::PERIHELION_TOLERANCE,
                    $this->differenceInSeconds($orbit->perihelion, $perihelion),
                    'Mean perihelion of '.$where
                );
            }
        }
    }

    /**
     * Neptune, the only one that does not fit the tolerance of the other six, is pinned with its
     * measured size and with what shows it is not our mistake: **it is flat over time**. A frame
     * error would grow with distance from J2000 and a t² truncation would be zero at J2000; this
     * runs twelve arcseconds at all three epochs, so it is a constant difference between Swiss's
     * table and Simon's.
     */
    public function test_neptunes_perihelion_carries_a_flat_twelve_seconds_against_swiss(): void
    {
        $measurements = [];

        foreach (self::fromSwiss()['neptune'] as [$jd, , $perihelion]) {
            $measurements[] = $this->differenceInSeconds(NodesAndApsides::mean(Body::Neptune, $jd)->perihelion, $perihelion);
        }

        foreach ($measurements as $measurement) {
            $this->assertEqualsWithDelta(12.2, $measurement, 0.6);
        }

        // And flat means this: between the first and the last there is not even one arcsecond.
        $this->assertLessThan(1.0, max($measurements) - min($measurements));
    }

    /**
     * The sidereal period comes from the linear term of the mean longitude, so it has to be the
     * published period of each planet.
     *
     * **This is the check that catches the unit error, which here is the natural mistake**: the
     * table is in julian MILLENNIA and the rest of the engine in centuries. Confusing them gives
     * no error and does not show at J2000, because t is zero there; what it does is multiply or
     * divide every rate by ten, and here Mercury would come out going around in 880 days instead
     * of 88.
     */
    public function test_the_mean_period_is_the_published_one(): void
    {
        $published = [
            'mercury' => 87.969,
            'venus' => 224.701,
            'mars' => 686.980,
            'jupiter' => 4332.589,
            'saturn' => 10759.22,
            'uranus' => 30685.4,
            'neptune' => 60189.0,
        ];

        foreach ($published as $name => $days) {
            $orbit = NodesAndApsides::mean(Body::from($name), 2451545.0);

            /* In RELATIVE terms and to one thousandth, and the figure has a reason. What this
               test is after is a UNIT error, that is a factor of ten, so a thousandth catches it
               with three orders of magnitude to spare. Tightening it further would be arguing
               with the source and not the code: several figures circulate for the published
               sidereal periods depending on who writes them, and Uranus is the case, with
               30,685.4 days in some tables and 30,688.5 in others, which is exactly the part in
               ten thousand that separates the two. The osculating period, which can be held to
               the decimal, is already checked against Horizons in `NodesAndApsidesTest`. */
            $this->assertEqualsWithDelta($days, $orbit->siderealPeriod, $days * 1.0e-3, $name);
            $this->assertEqualsWithDelta(360.0 / $days, $orbit->dailyMotion, 360.0 / $days * 1.0e-3, $name);

            // And the two say the same thing the other way round, which IS exact.
            $this->assertEqualsWithDelta(360.0 / $orbit->dailyMotion, $orbit->siderealPeriod, 1.0e-9, $name);
        }
    }

    /**
     * The table is referred to the ecliptic and equinox of J2000, which is a FIXED plane, and
     * that shows in the Earth's row without comparing against anyone: its inclination is
     * **exactly zero** at J2000 and grows 470 arcseconds per millennium. If the reference plane
     * were the ecliptic of the date, the Earth's inclination would always be zero, because the
     * ecliptic IS its orbit.
     */
    public function test_the_table_is_in_the_j2000_ecliptic(): void
    {
        $this->assertSame(0.0, MeanElements::of(Body::Earth, Time::J2000)['i']);

        // Three hundred years later it is already 141 arcseconds, that is 0.039 degrees.
        $this->assertEqualsWithDelta(0.0391, MeanElements::of(Body::Earth, 2561120.0)['i'], 1.0e-4);

        /* And going backwards it CHANGES SIGN, which is an even better sign: three hundred years
           before J2000 the Earth's orbit was tilted to the other side of that fixed plane, so
           its node falls half a turn further out. An element referred to the ecliptic of the
           DATE could not do this, because it would always be zero on both sides. */
        $this->assertEqualsWithDelta(-0.0392, MeanElements::of(Body::Earth, 2341970.0)['i'], 1.0e-4);
    }

    /**
     * The mean and the osculating elements are NOT the same orbit, and in the outer planets they
     * do not look alike: Neptune's mean perihelion falls a good eleven degrees from the
     * osculating one, that is a third of a sign. In the inner ones, where the perturbations
     * weigh little, they almost coincide.
     */
    public function test_the_mean_elements_are_not_the_osculating_ones(): void
    {
        $neptune = $this->differenceInSeconds(
            NodesAndApsides::mean(Body::Neptune, 2451545.0)->perihelion,
            NodesAndApsides::of(Body::Neptune, 2451545.0)->perihelion
        ) / 3600;

        $mercury = $this->differenceInSeconds(
            NodesAndApsides::mean(Body::Mercury, 2451545.0)->perihelion,
            NodesAndApsides::of(Body::Mercury, 2451545.0)->perihelion
        ) / 3600;

        $this->assertEqualsWithDelta(10.8, $neptune, 0.5);
        $this->assertLessThan(0.01, $mercury);
    }

    /**
     * The three longitudes of the mean orbit close among themselves the same way as in the
     * osculating one: the mean longitude is the perihelion plus the mean anomaly.
     */
    public function test_the_mean_longitude_is_the_perihelion_plus_the_anomaly(): void
    {
        foreach (array_keys(self::fromSwiss()) as $name) {
            $orbit = NodesAndApsides::mean(Body::from($name), 2451545.0);

            $this->assertLessThan(
                1.0e-9,
                $this->differenceInSeconds(
                    $orbit->meanLongitude,
                    $orbit->perihelionLongitude() + $orbit->meanAnomaly
                ) / 3600,
                $name
            );

            // And the opposite points are derived, same as in the osculating orbit.
            $this->assertEqualsWithDelta(180.0, $this->differenceInSeconds($orbit->aphelion(), $orbit->perihelion) / 3600, 1e-9, $name);
            $this->assertEqualsWithDelta(-$orbit->perihelionLatitude, $orbit->aphelionLatitude(), 1e-12, $name);
            $this->assertEqualsWithDelta(
                $orbit->aphelionDistance - $orbit->perihelionDistance,
                $orbit->secondFocusDistance(),
                1e-12,
                $name
            );
        }
    }

    /**
     * Whoever has no published mean elements is told so, instead of getting something else back.
     *
     * **This is exactly what Swiss does not do, and that is why it is measured and written
     * down**: asking it for Pluto's mean elements returns the OSCULATING ones, down to the last
     * decimal, with no warning that it has no table for it. A mean node that is really the
     * osculating one reads just as well as the real thing.
     */
    public function test_a_body_with_no_table_says_so(): void
    {
        $noTable = [Body::Pluto, Body::Chiron, Body::Ceres, Body::Pholus, Body::Moon, Body::Sun, Body::Cupido];

        foreach ($noTable as $body) {
            $this->assertFalse(MeanElements::has($body), $body->name());
            $this->assertNull(MeanElements::of($body, 2451545.0), $body->name());

            try {
                NodesAndApsides::mean($body, 2451545.0);
                $this->fail($body->name().' should throw: it has no published mean elements');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('mean elements', $exception->getMessage());
            }
        }

        // The eight planets do have it, the Earth included, even though its node does not exist.
        foreach (['mercury', 'venus', 'earth', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune'] as $name) {
            $this->assertTrue(MeanElements::has(Body::from($name)), $name);
        }
    }

    /**
     * And the Earth throws for the same reason its osculating orbit throws: the ecliptic IS its
     * orbit, so the line of nodes is the intersection of a plane with itself. Its mean elements
     * are in the table, because the paper publishes them, and they are read through
     * `MeanElements`.
     */
    public function test_the_earth_has_elements_but_no_nodes(): void
    {
        $this->assertIsArray(MeanElements::of(Body::Earth, 2451545.0));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ecliptic IS that orbit');

        NodesAndApsides::mean(Body::Earth, 2451545.0);
    }

    /**
     * The source can be written right next to a number, which is half of why this could be done
     * at all: what was missing was not the arithmetic, it was being able to say where it comes
     * from.
     */
    public function test_the_table_says_where_it_comes_from(): void
    {
        $this->assertStringContainsString('Simon', MeanElements::source());
        $this->assertStringContainsString('1994', MeanElements::source());
    }

    /**
     * @param float $one
     * @param float $other
     * @return float
     */
    private function differenceInSeconds(float $one, float $other): float
    {
        return abs(fmod($one - $other + 540, 360) - 180) * 3600;
    }
}
