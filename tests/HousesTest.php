<?php

namespace Astronomy\Tests;

use Astronomy\Houses;
use Astronomy\HouseSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The houses, checked against their own definition.
 *
 * Checking a house system against its own definition is strong and it is not enough on its own:
 * it cannot catch a definition that is wrong. That is what comparing against a third party is
 * for, and it is how Koch was caught taking the ascensional difference of the ascendant instead
 * of that of the midheaven, with its eight intermediate cusps up to 35 degrees out while the
 * four angles looked perfectly normal.
 */
final class HousesTest extends TestCase
{
    /** 11 May 1981, 07:15 UTC, Madrid. */
    private const JD_UT = 2444735.802083334;

    private const LATITUDE = 40.4165;

    private const LONGITUDE = -3.7026;

    /**
     * At the equator the systems that divide the SKY arrive by completely different routes and
     * collapse into the same one: the equator, the prime vertical and the diurnal arcs all line
     * up there. If one of them drifts, that one is wrong.
     *
     * Porphyry is not among them and that is not a fault: it divides the arc of the ECLIPTIC
     * between the angles, not a circle of the sky, so it has no reason to agree.
     */
    public function test_at_the_equator_the_systems_that_divide_the_sky_agree(): void
    {
        $systems = [
            HouseSystem::Placidus, HouseSystem::Koch, HouseSystem::Regiomontanus,
            HouseSystem::Campanus, HouseSystem::Alcabitius, HouseSystem::Topocentric,
        ];

        $reference = Houses::calculate($systems[0], self::JD_UT, 0.0, self::LONGITUDE);

        foreach ($systems as $system) {
            $houses = Houses::calculate($system, self::JD_UT, 0.0, self::LONGITUDE);

            foreach (range(1, 12) as $cusp) {
                $this->assertEqualsWithDelta(
                    $reference->cusps[$cusp],
                    $houses->cusps[$cusp],
                    1e-6,
                    "{$system->value}: cusp {$cusp} does not agree at the equator"
                );
            }
        }

        $porphyry = Houses::calculate(HouseSystem::Porphyry, self::JD_UT, 0.0, self::LONGITUDE);

        $this->assertNotEqualsWithDelta($reference->cusps[2], $porphyry->cusps[2], 1e-6);
    }

    /**
     * The two doors are the same path: `calculate()` is `fromArmc()` with a clock in front of
     * it, and behind both there is one private builder so that they cannot diverge.
     *
     * Sunshine is left out because it is the only system that needs an ephemeris, the
     * declination of the Sun of that day, and through the ARMC door that is a parameter.
     */
    public function test_both_doors_give_the_same_cusps(): void
    {
        foreach (HouseSystem::cases() as $system) {
            if ($system === HouseSystem::Sunshine) {
                continue;
            }

            $byClock = Houses::calculate($system, self::JD_UT, self::LATITUDE, self::LONGITUDE);
            $byArmc = Houses::fromArmc($system, $byClock->siderealTime, self::LATITUDE, $byClock->obliquity);

            foreach (range(1, 12) as $cusp) {
                $this->assertEqualsWithDelta(
                    $byClock->cusps[$cusp],
                    $byArmc->cusps[$cusp],
                    1e-6,
                    "{$system->value}: the two doors disagree on cusp {$cusp}"
                );
            }
        }
    }

    /**
     * Past the polar circle Placidus, Koch, Alcabitius and Topocentric stop existing: there are
     * degrees that never rise and never set, so there is no diurnal arc to divide. They say so
     * with an exception instead of returning a made up number, which is the whole point.
     */
    #[DataProvider('polarSystems')]
    public function test_past_the_polar_circle_the_time_systems_throw(HouseSystem $system): void
    {
        $this->assertTrue($system->failsAtThePoles());

        $this->expectException(\RuntimeException::class);

        Houses::calculate($system, self::JD_UT, 78.2232, 15.6267);
    }

    /**
     * @return array<string, array{0: HouseSystem}>
     */
    public static function polarSystems(): array
    {
        return [
            'Placidus' => [HouseSystem::Placidus],
            'Koch' => [HouseSystem::Koch],
            'Alcabitius' => [HouseSystem::Alcabitius],
            'Topocentric' => [HouseSystem::Topocentric],
        ];
    }

    /**
     * And the ones that do not divide time keep working up there, which is what makes a chart
     * from Tromsø possible at all.
     */
    public function test_past_the_polar_circle_the_geometric_systems_do_work(): void
    {
        foreach ([HouseSystem::Regiomontanus, HouseSystem::Campanus, HouseSystem::Porphyry, HouseSystem::WholeSign] as $system) {
            $houses = Houses::calculate($system, self::JD_UT, 78.2232, 15.6267);

            $this->assertCount(12, $houses->cusps);
            $this->assertGreaterThanOrEqual(0.0, $houses->ascendant);
            $this->assertLessThan(360.0, $houses->ascendant);
        }
    }

    /**
     * Whole signs is nailed to the sign boundaries by definition: every cusp sits at zero
     * degrees of a sign. It is the reason it cannot be carried to the sidereal zodiac by
     * subtracting the ayanamsa, and has to be rebuilt over the sidereal signs instead.
     */
    public function test_whole_signs_falls_on_the_sign_boundaries(): void
    {
        $houses = Houses::calculate(HouseSystem::WholeSign, self::JD_UT, self::LATITUDE, self::LONGITUDE);

        foreach (range(1, 12) as $cusp) {
            $this->assertEqualsWithDelta(0.0, fmod($houses->cusps[$cusp], 30.0), 1e-9);
        }

        $this->assertTrue($houses->system->restsOnTheSigns());
        $this->assertTrue(HouseSystem::EqualAries->restsOnTheSigns());
        $this->assertFalse(HouseSystem::Placidus->restsOnTheSigns());
    }

    public function test_equal_houses_are_thirty_degrees_from_the_ascendant(): void
    {
        $houses = Houses::calculate(HouseSystem::Equal, self::JD_UT, self::LATITUDE, self::LONGITUDE);

        $this->assertEqualsWithDelta($houses->ascendant, $houses->cusps[1], 1e-9);

        foreach (range(2, 12) as $cusp) {
            $expected = fmod($houses->ascendant + 30.0 * ($cusp - 1), 360.0);
            $this->assertEqualsWithDelta($expected, $houses->cusps[$cusp], 1e-9);
        }
    }

    /**
     * The vertex does not exist at the equator, and returning a number there would be worse
     * than returning nothing: the circle the ecliptic is cut with goes through the celestial
     * poles and does not cut it at a point. It is not an awkward case worth avoiding, it is
     * that the definition does not reach there.
     */
    public function test_there_is_no_vertex_at_the_equator(): void
    {
        $houses = Houses::calculate(HouseSystem::Placidus, self::JD_UT, 0.0, self::LONGITUDE);

        $this->assertNull($houses->vertex);

        $madrid = Houses::calculate(HouseSystem::Placidus, self::JD_UT, self::LATITUDE, self::LONGITUDE);

        $this->assertNotNull($madrid->vertex);
    }

    /**
     * Opposite cusps face each other in the systems built on great circles. APC and Sunshine are
     * the exception and it follows from their definition: the point dividing the diurnal arc of
     * a parallel is not the antipode of the one dividing the nocturnal arc.
     */
    public function test_opposite_cusps_face_each_other(): void
    {
        foreach ([HouseSystem::Placidus, HouseSystem::Regiomontanus, HouseSystem::Campanus, HouseSystem::Porphyry] as $system) {
            $houses = Houses::calculate($system, self::JD_UT, self::LATITUDE, self::LONGITUDE);

            foreach (range(1, 6) as $cusp) {
                $difference = fmod($houses->cusps[$cusp + 6] - $houses->cusps[$cusp] + 360.0, 360.0);
                $this->assertEqualsWithDelta(180.0, $difference, 1e-9, "{$system->value}: cusp {$cusp}");
            }
        }
    }

    /**
     * Cusp one is the ascendant and cusp ten the midheaven in the quadrant systems. Morinus and
     * Carter are the documented exceptions: Morinus drops the horizon on purpose, and Carter
     * starts from the right ascension of the ascendant so its house ten is not the midheaven.
     */
    public function test_the_angles_are_the_first_and_tenth_cusps(): void
    {
        foreach ([HouseSystem::Placidus, HouseSystem::Koch, HouseSystem::Regiomontanus, HouseSystem::Campanus, HouseSystem::Porphyry] as $system) {
            $houses = Houses::calculate($system, self::JD_UT, self::LATITUDE, self::LONGITUDE);

            $this->assertEqualsWithDelta($houses->ascendant, $houses->cusps[1], 1e-9, "{$system->value}: cusp 1");
            $this->assertEqualsWithDelta($houses->midheaven, $houses->cusps[10], 1e-9, "{$system->value}: cusp 10");
        }
    }

    public function test_there_are_twenty_three_systems_and_the_names_are_english(): void
    {
        $this->assertCount(23, HouseSystem::cases());
        $this->assertSame('Placidus', HouseSystem::Placidus->name());
        $this->assertSame('placidus', HouseSystem::Placidus->value);
        $this->assertSame('Whole sign', HouseSystem::WholeSign->name());
        $this->assertStringContainsString('Divides the time', HouseSystem::Placidus->description());
    }

    /**
     * The thirty six Gauquelin sectors are Placidus with the semiarc cut in nine instead of in
     * three, so one in every three of them IS a Placidus cusp.
     */
    public function test_the_gauquelin_sectors_are_placidus_in_ninths(): void
    {
        $houses = Houses::calculate(HouseSystem::Placidus, self::JD_UT, self::LATITUDE, self::LONGITUDE);
        $sectors = $houses->gauquelinSectors();

        $this->assertCount(36, $sectors);
        $this->assertEqualsWithDelta($houses->cusps[1], $sectors[1], 1e-6);
        $this->assertEqualsWithDelta($houses->cusps[10], $sectors[10], 1e-6);
    }
}
