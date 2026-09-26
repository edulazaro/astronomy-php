<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;

/**
 * The gravitational deflection of light: the Sun's mass bends the light that passes near it, so
 * a body seen almost behind the Sun looks a little farther from it than it really is.
 *
 * It is checked two ways, which is what a computation needs when it has a published formula AND
 * a third party to measure against. Against the definition: for a distant source the
 * displacement has to equal 0.00407 arcseconds times the cotangent of half the elongation, which
 * is the classical formula, the one that gives 1.75 arcseconds grazing the Sun's edge and the one
 * Eddington used to make Einstein famous in 1919. And against Horizons, at the only two spots
 * where this shows up in a chart: a planet stuck to the Sun.
 */
class DeflectionTest extends TestCase
{
    /**
     * The constant from the classical formula, in arcseconds, for an observer at one
     * astronomical unit from the Sun: twice the Sun's Schwarzschild radius divided by the
     * astronomical unit, turned into arcseconds.
     */
    private const CONSTANT = 0.00407;

    /**
     * Apparent geocentric ecliptic longitude and latitude per JPL Horizons, in degrees, with the
     * time requested in TT (`TIME_TYPE='TT'`, `QUANTITIES='31'`, `CENTER='500@399'`).
     *
     * The two dates are from `astro:verificar`'s list of when a planet passes stuck to the Sun,
     * which is where the deflection is in charge: without it, Jupiter came out at 0.94 arcseconds
     * and Neptune at 0.36, while the rest of the engine stood at 0.10.
     *
     * @var array<string, array{0: string, 1: float, 2: float, 3: float}>
     */
    private const HORIZONS = [
        // [TT date, longitude, latitude, degrees of separation from the Sun]
        'jupiter' => ['1700-01-01', 280.3758130, -0.0575176, 0.34],
        'neptune' => ['1990-01-01', 282.0191120, 0.8474160, 1.72],
    ];

    /**
     * @return array{0: Closure, 1: Closure}
     */
    private function reflection(): array
    {
        $deflection = new ReflectionMethod(Ephemeris::class, 'deflection');
        $deflection->setAccessible(true);

        $earthMethod = new ReflectionMethod(Ephemeris::class, 'geometricEarth');
        $earthMethod->setAccessible(true);

        return [
            fn (array $vector, float $distance, float $jd) => $deflection->invoke(null, $vector, $distance, $jd),
            fn (float $jd) => $earthMethod->invoke(null, $jd),
        ];
    }

    /**
     * A body placed at a given elongation from the Sun, at whatever distance is asked for, and
     * how much the deflection moves it. Returns the displacement in arcseconds.
     */
    private function displacement(float $elongationDegrees, float $distance, float $jd): float
    {
        [$deflection, $earthAt] = $this->reflection();

        $earth = $earthAt($jd);
        $magnitude = sqrt($earth[0] ** 2 + $earth[1] ** 2 + $earth[2] ** 2);
        $towards = array_map(fn (float $c) => $c / $magnitude, $earth);

        // Any perpendicular to the Sun-Earth axis: the elongation is measured on that plane.
        $perpendicular = [-$towards[1], $towards[0], 0.0];
        $norm = sqrt($perpendicular[0] ** 2 + $perpendicular[1] ** 2);
        $perpendicular = [$perpendicular[0] / $norm, $perpendicular[1] / $norm, 0.0];

        $angle = deg2rad($elongationDegrees);
        $vector = [];

        foreach ([0, 1, 2] as $axis) {
            // From Earth, the Sun is in the direction opposite its heliocentric one.
            $vector[$axis] = (-$towards[$axis] * cos($angle) + $perpendicular[$axis] * sin($angle)) * $distance;
        }

        $deflected = $deflection($vector, $distance, $jd);
        $deflectedMagnitude = sqrt($deflected[0] ** 2 + $deflected[1] ** 2 + $deflected[2] ** 2);

        $cosine = ($vector[0] * $deflected[0] + $vector[1] * $deflected[1] + $vector[2] * $deflected[2])
            / ($distance * $deflectedMagnitude);

        return rad2deg(acos(min(1.0, max(-1.0, $cosine)))) * 3600;
    }

    /**
     * Against the definition. For a source far enough away, the displacement converges to the
     * classical formula; with a nearby source it comes out somewhat LESS, and that is not an
     * error, it is the finite-distance term: a solar system body only travels a stretch of the
     * curved path.
     */
    public function test_a_distant_source_gives_the_classical_formula(): void
    {
        $jd = 2451545.0;
        [, $earthAt] = $this->reflection();

        $earth = $earthAt($jd);
        $earthDistance = sqrt($earth[0] ** 2 + $earth[1] ** 2 + $earth[2] ** 2);

        foreach ([0.2666, 0.5, 1.0, 5.0, 30.0, 90.0] as $elongation) {
            // The constant is given for an observer at one astronomical unit from the Sun, and
            // in January the Earth is at perihelion, at 0.983.
            $expected = self::CONSTANT / tan(deg2rad($elongation) / 2) / $earthDistance;

            $this->assertEqualsWithDelta(
                $expected,
                $this->displacement($elongation, 100000.0, $jd),
                max(0.0005, $expected * 0.002),
                "at {$elongation} degrees from the Sun"
            );
        }
    }

    /**
     * The numbers quoted everywhere, checked: 1.75 arcseconds grazing the Sun's edge and 0.004 at
     * ninety degrees. It is the scale that decides whether this matters at all.
     */
    public function test_the_order_of_magnitude_is_the_published_one(): void
    {
        $jd = 2451545.0;

        $this->assertEqualsWithDelta(1.75, $this->displacement(0.2666, 100000.0, $jd), 0.03);
        $this->assertEqualsWithDelta(0.0041, $this->displacement(90.0, 100000.0, $jd), 0.0003);

        // And a solar system body gets less of it, because of the finite distance.
        $this->assertLessThan($this->displacement(1.0, 100000.0, $jd), $this->displacement(1.0, 5.0, $jd));
    }

    /**
     * Behind the Sun's disk the formula runs off to infinity, because the light would have to
     * pass through the inside of the star. There is no apparent direction to correct there: the
     * body is hidden. The vector is returned as-is instead of a huge number.
     */
    public function test_behind_the_suns_disk_there_is_no_deflection(): void
    {
        $jd = 2451545.0;

        // Grazing the edge it still deflects, and a lot.
        $this->assertGreaterThan(1.0, $this->displacement(0.2666, 100000.0, $jd));

        // Inside the disk, nothing.
        $this->assertSame(0.0, $this->displacement(0.05, 100000.0, $jd));
        $this->assertSame(0.0, $this->displacement(0.0, 100000.0, $jd));
    }

    /**
     * And against Horizons, in the two cases in a chart where this matters. The margin is the
     * same as the rest of the engine, a tenth of an arcsecond; without deflection, Jupiter was
     * off by nine times that margin.
     */
    public function test_a_planet_stuck_to_the_sun_matches_horizons(): void
    {
        foreach (self::HORIZONS as $key => [$date, $longitude, $latitude, $separation]) {
            $body = Body::from($key);
            $jd = Time::julianDay(new DateTimeImmutable($date.' 00:00', new DateTimeZone('UTC')));
            $position = Ephemeris::position($body, $jd);

            $this->assertEqualsWithDelta($longitude, $position->longitude, 0.1 / 3600, "longitude of {$key}");
            $this->assertEqualsWithDelta($latitude, $position->latitude, 0.1 / 3600, "latitude of {$key}");

            /* And the proof that this test measures what it claims: at that separation from the
               Sun the deflection is worth much more than the margin above, so without it the
               test would not pass. */
            $this->assertGreaterThan(
                0.1,
                $this->displacement($separation, 100000.0, $jd),
                "at {$separation} degrees from the Sun the deflection has to weigh more than the tolerance"
            );
        }
    }
}
