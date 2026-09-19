<?php

namespace Astronomy\Tests;

use Astronomy\Ayanamsa;
use Astronomy\Body;
use Astronomy\CustomAyanamsa;
use Astronomy\Ephemeris;
use Astronomy\Precession;
use Astronomy\Time;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sidereal position measured on the invariable plane of the solar system, which is Swiss's
 * `SE_SIDBIT_SSY_PLANE`.
 *
 * The reference numbers come from pyswisseph 2.10.03 and are copied in by hand, so the suite runs
 * with no network, exactly as `EphemerisTest` does with JPL Horizons.
 *
 * **What is compared is what the projection MOVES, not the position.** pyswisseph here runs with
 * no ephemeris files and falls back to Moshier, so comparing longitudes outright would measure two
 * ephemerides and two precession models rather than this rotation: that comparison comes out at
 * 3.25 arcseconds, of which 3.23 is the ORDINARY sidereal longitude of the same body and has
 * nothing to do with the plane. Subtracting each side's own unprojected position cancels all of
 * that and leaves the rotation, which is the thing under test.
 *
 * And three of the tests below ask nothing of anybody: a fixed direction has to come out at the
 * same sidereal place at any date, the Sun's latitude on this plane cannot exceed its inclination,
 * and a custom pair equal to Lahiri's has to reproduce Lahiri to the bit. A check against your own
 * definition cannot catch a wrong definition, which is why the table above it exists; a check
 * against a third party cannot catch a self-inconsistency, which is why these exist.
 */
final class AyanamsaTest extends TestCase
{
    /**
     * 10 June 1985, 12:00 UT. The date the whole engine is exercised on.
     */
    private const REFERENCE = '1985-06-10 12:00:00';

    /**
     * How far the projection moves each body, against pyswisseph: the shift in longitude in
     * arcseconds and the shift in latitude in degrees, at 12:00 Universal Time of 10 June of each
     * year.
     *
     * Measured residual over these 63 rows: **0.011 arcseconds in longitude and 0.137 in
     * latitude**. The latitude one is larger and is understood: the shift in latitude goes as the
     * sine of the body's longitude from the node, so it inherits the one to three arcseconds by
     * which our longitude and Moshier's differ, times the inclination. Pluto in 1700, which is the
     * worst row, is the body whose longitude the two ephemerides disagree about most.
     *
     * @return array<string, array{0: Body, 1: Ayanamsa, 2: string, 3: float, 4: float}>
     */
    public static function projections(): array
    {
        $rows = [
            // year, ayanamsa, body, shift in longitude ("), shift in latitude (deg)
            ['1700', Ayanamsa::Lahiri, Body::Sun, 54.5094, 0.6804726],
            ['1700', Ayanamsa::Lahiri, Body::Moon, 50.3827, 1.5203558],
            ['1700', Ayanamsa::Lahiri, Body::Mars, 116.4451, -1.4488932],
            ['1700', Ayanamsa::Lahiri, Body::Jupiter, 44.2214, 0.4606401],
            ['1700', Ayanamsa::Lahiri, Body::Saturn, 83.3854, 1.3731177],
            ['1700', Ayanamsa::Lahiri, Body::Neptune, 32.5253, 1.5812676],
            ['1700', Ayanamsa::Lahiri, Body::Pluto, 645.5881, -0.6689315],
            ['1700', Ayanamsa::FaganBradley, Body::Sun, 54.4130, 0.6804726],
            ['1700', Ayanamsa::FaganBradley, Body::Pluto, 645.4917, -0.6689315],
            ['1700', Ayanamsa::Raman, Body::Sun, 53.6329, 0.6804726],
            ['1700', Ayanamsa::Raman, Body::Pluto, 644.7115, -0.6689315],

            ['1985', Ayanamsa::Lahiri, Body::Sun, 54.3605, 0.7389116],
            ['1985', Ayanamsa::Lahiri, Body::Moon, 182.0563, 1.4177088],
            ['1985', Ayanamsa::Lahiri, Body::Mars, 124.7485, 0.4544560],
            ['1985', Ayanamsa::Lahiri, Body::Jupiter, 46.5136, 0.7772728],
            ['1985', Ayanamsa::Lahiri, Body::Saturn, -74.9236, -1.2849636],
            ['1985', Ayanamsa::Lahiri, Body::Neptune, -70.2749, -0.4050028],
            ['1985', Ayanamsa::Lahiri, Body::Pluto, -400.5138, -1.5277386],
            ['1985', Ayanamsa::FaganBradley, Body::Moon, 181.9602, 1.4177088],
            ['1985', Ayanamsa::FaganBradley, Body::Pluto, -400.6098, -1.5277386],
            ['1985', Ayanamsa::Raman, Body::Moon, 181.1820, 1.4177088],
            ['1985', Ayanamsa::Raman, Body::Pluto, -401.3880, -1.5277386],

            ['2300', Ayanamsa::Lahiri, Body::Sun, 54.0195, 0.8062363],
            ['2300', Ayanamsa::Lahiri, Body::Moon, 339.6286, 1.1264928],
            ['2300', Ayanamsa::Lahiri, Body::Mars, 175.3652, -1.2210505],
            ['2300', Ayanamsa::Lahiri, Body::Jupiter, 60.2270, -0.9849650],
            ['2300', Ayanamsa::Lahiri, Body::Saturn, 53.1014, -0.4033860],
            ['2300', Ayanamsa::Lahiri, Body::Neptune, -57.1742, -1.1238241],
            ['2300', Ayanamsa::Lahiri, Body::Pluto, 729.7095, 1.3020048],
            ['2300', Ayanamsa::FaganBradley, Body::Sun, 53.9235, 0.8062363],
            ['2300', Ayanamsa::FaganBradley, Body::Pluto, 729.6135, 1.3020048],
            ['2300', Ayanamsa::Raman, Body::Sun, 53.1455, 0.8062363],
            ['2300', Ayanamsa::Raman, Body::Pluto, 728.8356, 1.3020048],
        ];

        $cases = [];

        foreach ($rows as [$year, $ayanamsa, $body, $longitude, $latitude]) {
            $cases[sprintf('%s %s %s', $body->name(), $ayanamsa->key(), $year)] =
                [$body, $ayanamsa, $year, $longitude, $latitude];
        }

        return $cases;
    }

    #[DataProvider('projections')]
    public function test_the_projection_onto_the_invariable_plane_matches_swiss(
        Body $body,
        Ayanamsa $ayanamsa,
        string $year,
        float $longitudeShift,
        float $latitudeShift
    ): void {
        $jdTT = self::terrestrialTime($year . '-06-10 12:00:00');
        $position = Ephemeris::position($body, $jdTT);

        $sidereal = $ayanamsa->sidereal($position->longitude, $jdTT);
        [$projected, $latitude] = $ayanamsa->projectedOnSolarSystemPlane(
            $position->longitude,
            $position->latitude,
            $jdTT
        );

        /* Unwrapped the same way on both sides: Pluto's shift is a fifth of a degree and Swiss's
           is quoted as a plain difference, so it must not be folded to the shorter arc. */
        $shift = (fmod($projected - $sidereal + 540.0, 360.0) - 180.0) * 3600.0;
        $reference = fmod($longitudeShift + 540.0 * 3600.0, 360.0 * 3600.0) - 180.0 * 3600.0;

        $this->assertEqualsWithDelta($reference, $shift, 0.05, 'shift in longitude, arcseconds');
        $this->assertEqualsWithDelta(
            $latitudeShift,
            $latitude - $position->latitude,
            0.3 / 3600.0,
            'shift in latitude'
        );
    }

    /**
     * The three numbers that say this is another zodiac and not more precision, on the reference
     * date with Lahiri: Pluto moves −400.5 arcseconds in longitude and its latitude falls by 1.528
     * degrees, and the Sun, which sits on the ecliptic by construction, comes out three quarters of
     * a degree off this plane.
     */
    public function test_the_invariable_plane_is_another_zodiac_and_not_more_precision(): void
    {
        $jdTT = self::terrestrialTime(self::REFERENCE);

        $pluto = Ephemeris::position(Body::Pluto, $jdTT);
        $sidereal = Ayanamsa::Lahiri->sidereal($pluto->longitude, $jdTT);
        [$longitude, $latitude] = Ayanamsa::Lahiri->projectedOnSolarSystemPlane(
            $pluto->longitude,
            $pluto->latitude,
            $jdTT
        );

        $this->assertEqualsWithDelta(-400.52, ($longitude - $sidereal) * 3600.0, 0.05, 'Pluto, longitude');
        $this->assertEqualsWithDelta(17.0827, $pluto->latitude, 1e-3, 'Pluto, ecliptic latitude');
        $this->assertEqualsWithDelta(15.5550, $latitude, 1e-3, 'Pluto, latitude on the plane');

        $sun = Ephemeris::position(Body::Sun, $jdTT);

        $this->assertEqualsWithDelta(0.0, $sun->latitude, 1e-3, 'the Sun is on the ecliptic');
        $this->assertEqualsWithDelta(
            0.73872,
            Ayanamsa::Lahiri->projectedOnSolarSystemPlane($sun->longitude, $sun->latitude, $jdTT)[1],
            1e-4,
            'the Sun is NOT on the invariable plane'
        );
    }

    /**
     * Against the definition, asking nothing of anybody: a sidereal frame is fixed, so one and the
     * same direction of space has to come out at the same place in it whatever the date it is
     * asked about. The direction is taken in J2000 and precessed to each date, with the nutation
     * put back, because what the method takes in is a true longitude of date.
     *
     * This is the check that would catch the zero point being anchored at the date instead of at
     * t0, which is a real and wrong way of writing this: the ayanamsa would then be spent twice and
     * the three answers would drift apart by minutes of arc. Measured, they agree to every digit
     * printed.
     */
    public function test_a_fixed_direction_lands_in_the_same_place_at_any_date(): void
    {
        $j2000 = [
            cos(deg2rad(20.0)) * cos(deg2rad(100.0)),
            cos(deg2rad(20.0)) * sin(deg2rad(100.0)),
            sin(deg2rad(20.0)),
        ];

        $answers = [];

        foreach (['1700', '1985', '2300'] as $year) {
            $jdTT = self::terrestrialTime($year . '-03-01 00:00:00');
            $centuries = Time::centuries($jdTT);
            $ofDate = Precession::toDate($j2000, $centuries);

            $answers[] = Ayanamsa::Lahiri->projectedOnSolarSystemPlane(
                rad2deg(atan2($ofDate[1], $ofDate[0])) + rad2deg(Time::nutation($centuries)[0]),
                rad2deg(asin($ofDate[2])),
                $jdTT
            );
        }

        foreach ([0, 1] as $coordinate) {
            $spread = max(array_column($answers, $coordinate)) - min(array_column($answers, $coordinate));

            $this->assertLessThan(1e-7, $spread, 'the sidereal frame moved');
        }
    }

    /**
     * Also against the definition: the Sun rides the ecliptic, so measured on the invariable plane
     * its latitude is bounded by the angle between the two planes, and over a year it has to reach
     * that bound at both ends. That is what pins the inclination constant without asking anybody:
     * a wrong inclination changes this amplitude by exactly its own error.
     *
     * Measured over the year 2000 sampled daily: +1.57844° and −1.57875° against an inclination of
     * 1.578703°. The one arcsecond missing at the top is the daily sampling stepping over a
     * stationary point, which for a degree a day comes to 0.86 arcseconds, and the two tenths over
     * at the bottom are the Sun's own latitude, which is not exactly zero.
     */
    public function test_the_suns_latitude_on_the_plane_is_bounded_by_the_inclination(): void
    {
        $inclination = 1 + 34 / 60 + 43.33124 / 3600;
        $highest = -90.0;
        $lowest = 90.0;

        for ($day = 0; $day < 366; $day++) {
            $jdTT = 2451545.0 + $day;
            $sun = Ephemeris::position(Body::Sun, $jdTT);
            $latitude = Ayanamsa::Lahiri->projectedOnSolarSystemPlane($sun->longitude, $sun->latitude, $jdTT)[1];

            $highest = max($highest, $latitude);
            $lowest = min($lowest, $latitude);
        }

        $this->assertEqualsWithDelta($inclination, $highest, 2.0 / 3600.0, 'highest');
        $this->assertEqualsWithDelta(-$inclination, $lowest, 2.0 / 3600.0, 'lowest');
    }

    /**
     * A custom pair equal to Lahiri's has to give back Lahiri, to the bit: the arithmetic lives in
     * one place and is handed (t0, a0) loose precisely so that there are not two copies of one
     * rotation to drift apart. The pair is read off the enum, so there is not a number typed in
     * here either.
     */
    public function test_a_custom_pair_equal_to_lahiris_reproduces_lahiri(): void
    {
        $jdTT = self::terrestrialTime(self::REFERENCE);
        $pluto = Ephemeris::position(Body::Pluto, $jdTT);

        $custom = new CustomAyanamsa(
            Ayanamsa::Lahiri->epoch(),
            (float) Ayanamsa::Lahiri->initialValue(),
            'Lahiri, by hand'
        );

        $this->assertSame(
            Ayanamsa::Lahiri->projectedOnSolarSystemPlane($pluto->longitude, $pluto->latitude, $jdTT),
            $custom->projectedOnSolarSystemPlane($pluto->longitude, $pluto->latitude, $jdTT)
        );
    }

    /**
     * An ayanamsa anchored to a star has no t0, so there is no vernal point to start counting from
     * and no honest answer to give. It throws rather than falling back to some other epoch, which
     * would return a number that reads exactly as well as the right one.
     */
    public function test_a_star_anchored_ayanamsa_has_no_zero_point_on_this_plane(): void
    {
        $this->expectException(LogicException::class);

        Ayanamsa::TrueCitra->projectedOnSolarSystemPlane(100.0, 2.0, 2451545.0);
    }

    /**
     * The projection is a rotation of the sphere, so it preserves the angle between two
     * directions. This is what would catch the latitude being written as if it were a separate
     * quantity instead of the third component of a turned vector.
     */
    public function test_the_projection_preserves_the_angle_between_two_bodies(): void
    {
        $jdTT = self::terrestrialTime(self::REFERENCE);

        $first = Ephemeris::position(Body::Mars, $jdTT);
        $second = Ephemeris::position(Body::Pluto, $jdTT);

        $before = self::angleBetween(
            [$first->longitude, $first->latitude],
            [$second->longitude, $second->latitude]
        );
        $after = self::angleBetween(
            Ayanamsa::Lahiri->projectedOnSolarSystemPlane($first->longitude, $first->latitude, $jdTT),
            Ayanamsa::Lahiri->projectedOnSolarSystemPlane($second->longitude, $second->latitude, $jdTT)
        );

        $this->assertEqualsWithDelta($before, $after, 1e-9, 'the rotation is not a rotation');
    }

    /**
     * @param array{0: float, 1: float} $first Longitude and latitude, degrees.
     * @param array{0: float, 1: float} $second
     * @return float Degrees.
     */
    private static function angleBetween(array $first, array $second): float
    {
        $unit = static fn (array $spherical): array => [
            cos(deg2rad($spherical[1])) * cos(deg2rad($spherical[0])),
            cos(deg2rad($spherical[1])) * sin(deg2rad($spherical[0])),
            sin(deg2rad($spherical[1])),
        ];

        [$a, $b] = [$unit($first), $unit($second)];

        return rad2deg(acos($a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2]));
    }

    /**
     * The dates are Universal Time, which is how Swiss was asked, and the engine works in
     * Terrestrial Time. Handing a date straight in as TT shifts the Moon by the whole of delta T.
     */
    private static function terrestrialTime(string $when): float
    {
        return Time::tt(Time::julianDay(new \DateTimeImmutable($when, new \DateTimeZone('UTC'))));
    }
}
