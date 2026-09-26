<?php

namespace Astronomy\Tests;

use Astronomy\Ayanamsa;
use Astronomy\CustomAyanamsa;
use Astronomy\Body;
use Astronomy\Ephemeris;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sidereal position measured on the ecliptic of t0: Swiss's `SE_SIDBIT_ECL_T0`.
 *
 * It exists because it is the piece behind precession-corrected transits, which is the only one
 * of Swiss's two projection bits anyone actually uses. The other, the invariable plane
 * (`SIDBIT_SSY_PLANE`), was measured and dropped: no school uses it, and it is written up in
 * CLAUDE.md.
 *
 * **What is compared is what MOVES under the projection, not the absolute position, and that is
 * the whole point of the test.** Our ephemeris and Swiss's without files (Moshier) differ by up
 * to 0.9 arcseconds in the Moon, so comparing positions would measure the two ephemerides and
 * not this. Subtracting each one's own unprojected position, what is left is just the rotation,
 * and there **engine and Swiss match to 0.002 arcseconds in all ten cases**.
 *
 * The values are from `swe_calc` with `SEFLG_SIDEREAL`, Lahiri, with and without the bit, Swiss
 * Ephemeris 2.10.03 via pyswisseph, copied by hand so the suite runs with no network.
 */
class ProjectedSiderealTest extends TestCase
{
    /**
     * By body and date: Swiss's normal sidereal longitude and the projected one, in degrees.
     *
     * @return array<string, array{0: Body, 1: float, 2: float, 3: float}>
     */
    public static function fromSwiss(): array
    {
        return [
            'Sun in 1985' => [Body::Sun, 2446226.5, 55.413797, 55.413797],
            'Moon in 1985' => [Body::Moon, 2446226.5, 321.504239, 321.503934],
            'Mars in 1985' => [Body::Mars, 2446226.5, 66.719737, 66.719731],
            'Saturn in 1985' => [Body::Saturn, 2446226.5, 209.390647, 209.390565],
            'Pluto in 1985' => [Body::Pluto, 2446226.5, 188.556855, 188.555923],
            'Sun in J2000' => [Body::Sun, 2451545.0, 256.514944, 256.514944],
            'Moon in J2000' => [Body::Moon, 2451545.0, 199.461672, 199.461328],
            'Mars in J2000' => [Body::Mars, 2451545.0, 304.109518, 304.109423],
            'Saturn in J2000' => [Body::Saturn, 2451545.0, 16.542431, 16.542260],
            'Pluto in J2000' => [Body::Pluto, 2451545.0, 227.601460, 227.601204],
        ];
    }

    #[DataProvider('fromSwiss')]
    public function test_it_moves_the_same_as_swiss(Body $body, float $jd, float $swissNormal, float $swissProjected): void
    {
        $position = Ephemeris::position($body, $jd);
        $normal = $position->longitude - Ayanamsa::Lahiri->value($jd);
        [$projected] = Ayanamsa::Lahiri->projected($position->longitude, $position->latitude, $jd);

        $ourShift = self::difference($projected, $normal);
        $theirShift = self::difference($swissProjected, $swissNormal);

        $this->assertEqualsWithDelta($theirShift, $ourShift, 0.01 / 3600);
    }

    /**
     * The projection does not change the Sun's longitude, and it does change Pluto's: it is a
     * rotation of the plane, not a shift of the zero point. The Sun sits ON the ecliptic, so
     * rotating it barely moves it along; Pluto, with seventeen degrees of latitude, feels it.
     */
    public function test_it_does_not_move_the_suns_longitude_but_it_does_plutos(): void
    {
        $jd = 2446226.5;

        $sun = Ephemeris::position(Body::Sun, $jd);
        [$projectedSun] = Ayanamsa::Lahiri->projected($sun->longitude, $sun->latitude, $jd);
        $sunShift = self::difference($projectedSun, $sun->longitude - Ayanamsa::Lahiri->value($jd));

        $pluto = Ephemeris::position(Body::Pluto, $jd);
        [$projectedPluto] = Ayanamsa::Lahiri->projected($pluto->longitude, $pluto->latitude, $jd);
        $plutoShift = self::difference($projectedPluto, $pluto->longitude - Ayanamsa::Lahiri->value($jd));

        $this->assertLessThan(0.001 / 3600, abs($sunShift));
        $this->assertEqualsWithDelta(-3.355 / 3600, $plutoShift, 0.01 / 3600);
    }

    /**
     * And what it measures is the distance from the date to t0, not the date itself: Hipparchus's,
     * anchored twenty-one centuries back, moves Pluto seventy times more than Lahiri's.
     */
    public function test_what_it_measures_is_the_distance_to_t0(): void
    {
        $jd = 2446226.5;
        $pluto = Ephemeris::position(Body::Pluto, $jd);

        $shift = function (Ayanamsa $ayanamsa) use ($pluto, $jd): float {
            [$projected] = $ayanamsa->projected($pluto->longitude, $pluto->latitude, $jd);

            return self::difference($projected, $pluto->longitude - $ayanamsa->value($jd)) * 3600;
        };

        $this->assertEqualsWithDelta(-3.36, $shift(Ayanamsa::Lahiri), 0.05);
        $this->assertEqualsWithDelta(-262.89, $shift(Ayanamsa::Hipparchus), 0.5);
    }

    /**
     * The projection also changes the latitude, which is exactly what does not fit in the
     * chart's path, where the zodiac is an angle that gets subtracted from the longitude.
     */
    public function test_it_also_changes_the_latitude(): void
    {
        $jd = 2446226.5;
        $pluto = Ephemeris::position(Body::Pluto, $jd);
        [, $latitude] = Ayanamsa::Lahiri->projected($pluto->longitude, $pluto->latitude, $jd);

        $this->assertEqualsWithDelta(8.3 / 3600, $latitude - $pluto->latitude, 0.5 / 3600);
    }

    /**
     * A custom ayanamsa projects the same way: it is the same pair (t0, a0) with different numbers.
     */
    public function test_a_custom_ayanamsa_projects_the_same_way(): void
    {
        $jd = 2446226.5;
        $lahiri = Ayanamsa::Lahiri;
        $custom = new CustomAyanamsa((float) $lahiri->epoch(), (float) $lahiri->initialValue(), 'Lahiri by hand');
        $pluto = Ephemeris::position(Body::Pluto, $jd);

        $this->assertSame(
            $lahiri->projected($pluto->longitude, $pluto->latitude, $jd),
            $custom->projected($pluto->longitude, $pluto->latitude, $jd)
        );
    }

    /**
     * Ones anchored to a star have no t0, so there is no ecliptic to project onto. It says so
     * instead of returning a number.
     */
    public function test_a_star_anchored_ayanamsa_cannot_be_projected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not anchored to an epoch');

        Ayanamsa::TrueCitra->projected(100.0, 2.0, 2446226.5);
    }

    /**
     * @param float $a
     * @param float $b
     * @return float Degrees, folded to (-180, 180].
     */
    private static function difference(float $a, float $b): float
    {
        return fmod(fmod($a - $b, 360.0) + 540.0, 360.0) - 180.0;
    }
}
