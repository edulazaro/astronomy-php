<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Houses;
use Astronomy\HouseSystem;
use Astronomy\Limb;
use Astronomy\Place;
use Astronomy\RiseSet;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
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

    /*
     |--------------------------------------------------------------------------
     | The speeds of the cusps
     |--------------------------------------------------------------------------
     */

    /**
     * The test that means something: a speed is the motion of the cusp, so the cusp half an hour
     * later minus the cusp half an hour earlier, over the hour, has to be it.
     *
     * It is worth more than any comparison against a third party because it goes by a completely
     * different road. `speeds()` moves the ARMC by a thousandth of a degree and multiplies by a
     * constant; this moves the CLOCK, so it goes through `Time`, through the apparent sidereal
     * time of two different instants and through the obliquity of each one, and asks nothing of
     * the constant at all.
     *
     * What is left over is the truncation of measuring a bending curve across a whole hour, and
     * that it is truncation and not a wrong rate is shown here rather than asserted: shrinking
     * the window from half an hour to five minutes, a factor of six, cuts the residual by
     * thirty six, which is what a second order difference does and what a systematic error
     * would not.
     */
    public function test_the_cusp_speeds_are_the_motion_of_the_cusps(): void
    {
        $systems = [
            HouseSystem::Placidus, HouseSystem::Koch, HouseSystem::Regiomontanus,
            HouseSystem::Campanus, HouseSystem::Porphyry, HouseSystem::Alcabitius,
            HouseSystem::Topocentric, HouseSystem::Equal, HouseSystem::Vehlow,
            HouseSystem::Morinus, HouseSystem::Meridian, HouseSystem::Azimuthal,
            HouseSystem::Krusinski, HouseSystem::Sripati, HouseSystem::Carter,
            HouseSystem::Apc, HouseSystem::PullenSD, HouseSystem::PullenSR,
            HouseSystem::EqualMidheaven, HouseSystem::SavardA,
        ];

        foreach ($systems as $system) {
            $speeds = Houses::calculate($system, self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds();

            foreach (range(1, 12) as $cusp) {
                $measured = $this->motionOverAWindow($system, $cusp, 1 / 48);

                $this->assertEqualsWithDelta(
                    $measured,
                    $speeds->cusps[$cusp],
                    0.005 * abs($speeds->cusps[$cusp]),
                    "{$system->value}: cusp {$cusp} does not move at the speed it says"
                );
            }
        }

        // And the leftover is truncation: six times narrower, thirty six times smaller.
        $speeds = Houses::calculate(HouseSystem::Regiomontanus, self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds();

        $wide = 0.0;
        $narrow = 0.0;

        foreach (range(1, 12) as $cusp) {
            $wide = max($wide, abs($this->motionOverAWindow(HouseSystem::Regiomontanus, $cusp, 1 / 48) - $speeds->cusps[$cusp]));
            $narrow = max($narrow, abs($this->motionOverAWindow(HouseSystem::Regiomontanus, $cusp, 1 / 288) - $speeds->cusps[$cusp]));
        }

        $this->assertGreaterThan(25.0, $wide / $narrow);
        $this->assertLessThan(50.0, $wide / $narrow);
    }

    /**
     * How fast a cusp really moves, measured by moving the clock and not the ARMC.
     *
     * @param HouseSystem $system
     * @param int $cusp
     * @param float $halfWindow In days.
     * @return float Degrees per day.
     */
    private function motionOverAWindow(HouseSystem $system, int $cusp, float $halfWindow): float
    {
        $before = Houses::calculate($system, self::JD_UT - $halfWindow, self::LATITUDE, self::LONGITUDE)->cusps[$cusp];
        $after = Houses::calculate($system, self::JD_UT + $halfWindow, self::LATITUDE, self::LONGITUDE)->cusps[$cusp];

        $moved = fmod(fmod($after - $before, 360.0) + 360.0, 360.0);

        return ($moved >= 180.0 ? $moved - 360.0 : $moved) / (2 * $halfWindow);
    }

    /**
     * The rotation of the Earth is written out in `Houses` and computed in `Time`, and this is
     * what holds the two together.
     *
     * A set of houses has no date, so the constant cannot be reached for; the price is a number in
     * two places and the guard against it is this. The tolerance is the equation of the equinoxes,
     * which is what separates the apparent rate from the mean one and which the constant does not
     * carry: measured over 1600 to 2400 it runs between -4.0e-5 and +6.2e-5 degrees per day, and
     * it is dominated by the nutation term of thirteen and a half days.
     */
    public function test_the_rotation_rate_is_the_one_the_sidereal_time_runs_at(): void
    {
        $speeds = Houses::calculate(HouseSystem::Equal, self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds();

        foreach ([2305448.0, self::JD_UT, 2451545.0, 2597641.0] as $jd) {
            $moved = Time::apparentSiderealTime($jd + 0.5) - Time::apparentSiderealTime($jd - 0.5);
            $moved = fmod(fmod($moved, 360.0) + 360.0, 360.0);
            $advance = ($moved >= 180.0 ? $moved - 360.0 : $moved) + 360.0;

            $this->assertEqualsWithDelta($advance, $speeds->armc, 1e-4, 'the ARMC rate is not the one sidereal time runs at');
        }

        // And it is the same one Swiss uses: `ascmc_speed[2]` of `swe_houses_armc_ex2`.
        $this->assertEqualsWithDelta(360.985647, $speeds->armc, 1e-6);
    }

    /**
     * The speeds against Swiss Ephemeris, where the two agree.
     *
     * pyswisseph 2.10.03, `swe.houses_armc_ex2(armc, 40.4165, eps, letter)`, given OUR sidereal
     * time and OUR obliquity so that what is compared is the algorithm and not the ephemeris.
     * Sixteen of the twenty three systems come out like this; the other seven are the next test.
     */
    public function test_the_cusp_speeds_agree_with_swiss_where_swiss_agrees_with_its_own_cusps(): void
    {
        $swiss = [
            'regiomontanus' => [341.824507, 301.513112, 316.908590, 379.771587, 481.467936, 458.183846, 341.824507, 301.513112, 316.908590, 379.771587, 481.467936, 458.183846],
            'campanus' => [341.824507, 301.023087, 325.827692, 379.771587, 461.848458, 483.628585, 341.824507, 301.023087, 325.827692, 379.771587, 461.848458, 483.628585],
        ];

        foreach ($swiss as $value => $expected) {
            $speeds = Houses::calculate(HouseSystem::from($value), self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds();

            foreach ($expected as $i => $one) {
                $this->assertEqualsWithDelta($one, $speeds->cusps[$i + 1], 1e-5, "{$value}: cusp ".($i + 1));
            }
        }

        // The eight points of `ascmc`, in Swiss's own order: ascendant, midheaven, ARMC, vertex,
        // east point, Koch's co-ascendant, Munkasey's co-ascendant and the polar ascendant. All
        // eight agree, in every system: they are not part of the disagreement below.
        $points = Houses::calculate(HouseSystem::Regiomontanus, self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds()->points();

        foreach ([341.824507, 379.771587, 360.985647, 259.105757, 341.554851, 283.610279, 316.718813, 283.610279] as $i => $one) {
            $this->assertEqualsWithDelta($one, $points[$i], 1e-5, "point {$i}");
        }

        // Sunshine is the one system that needs an ephemeris, and the decision is that its Sun is
        // held still: through `fromArmc` the declination is a parameter, not a function of the
        // date. That is what Swiss does too, and this is the proof. `swe.houses_armc_ex2(..., b'I', 17.5)`.
        $sunshine = Houses::fromArmc(HouseSystem::Sunshine, 334.036198756152316, self::LATITUDE, 23.440207338093177, 17.5)->speeds();

        foreach ([341.824507, 302.828801, 313.044165, 379.771587, 489.557986, 443.250853, 341.824507, 300.997858, 319.586264, 379.771587, 475.579079, 468.668514] as $i => $one) {
            $this->assertEqualsWithDelta($one, $sunshine->cusps[$i + 1], 1e-5, 'sunshine: cusp '.($i + 1));
        }
    }

    /**
     * And where the two do not agree, it is Swiss that parts company with its own cusps.
     *
     * Three of the seven are checked here, each by the thing that makes it checkable rather than
     * by an opinion:
     *
     * - Porphyry has the anchor the wrong way round, and the arithmetic says so out loud. Its
     * cusp five is the imum coeli plus a third of the quadrant, so its derivative is
     * `MC' + (ASC' - MC')/3`; Swiss publishes `ASC' + (ASC' - MC')/3`, the same correction hung
     * on the other angle. Both are written out below from the two angle speeds, so the test
     * shows which one moves the cusp.
     * - Krusinski returns exactly 0.0 on eight of its twelve, where the cusps are moving at
     * three hundred and fifty degrees a day.
     * - Whole sign returns the angle speeds on cusps 1, 4, 7 and 10, where the cusps do not
     * move at all.
     *
     * pyswisseph 2.10.03, the same call as the test above.
     */
    public function test_where_swiss_disagrees_with_its_own_cusps(): void
    {
        $porphyry = Houses::calculate(HouseSystem::Porphyry, self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds();

        $ascendant = $porphyry->ascendant;
        $midheaven = $porphyry->midheaven;
        $third = ($ascendant - $midheaven) / 3;

        $this->assertEqualsWithDelta($midheaven + $third, $porphyry->cusps[5], 1e-5, 'cusp 5 is anchored on the imum coeli');
        $this->assertEqualsWithDelta(329.175480, $ascendant + $third, 1e-5, 'that is the number Swiss publishes');
        $this->assertEqualsWithDelta($this->motionOverAWindow(HouseSystem::Porphyry, 5, 1 / 48), $porphyry->cusps[5], 1.0);
        $this->assertGreaterThan(30.0, abs($porphyry->cusps[5] - ($ascendant + $third)));

        // Krusinski: Swiss fills four of the twelve and zeroes the rest.
        $krusinski = Houses::calculate(HouseSystem::Krusinski, self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds();

        foreach ([2, 3, 5, 6, 8, 9, 11, 12] as $cusp) {
            $this->assertGreaterThan(300.0, $krusinski->cusps[$cusp], "Krusinski cusp {$cusp} does move");
            $this->assertEqualsWithDelta($this->motionOverAWindow(HouseSystem::Krusinski, $cusp, 1 / 48), $krusinski->cusps[$cusp], 1.0);
        }

        foreach ([1, 7] as $cusp) {
            $this->assertEqualsWithDelta($krusinski->ascendant, $krusinski->cusps[$cusp], 1e-9);
        }

        // Whole sign: Swiss puts 341.824507 on cusps 1 and 7 and 379.771587 on 4 and 10, which
        // are the speeds of the ascendant and the midheaven. Those cusps are nailed to the sign
        // boundaries and do not move.
        $whole = Houses::calculate(HouseSystem::WholeSign, self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds();

        foreach (range(1, 12) as $cusp) {
            $this->assertSame(0.0, $whole->cusps[$cusp], "Whole sign cusp {$cusp} does not move");
        }

        /* And the window cannot measure this one, which is worth showing rather than working
           around: a cusp nailed to a sign boundary does not move at all until the ascendant
           changes sign, and then all twelve jump thirty degrees at once. Over this hour that jump
           does happen, and the difference reads 720 degrees a day. That is why the answer comes
           from `restsOnTheSigns()` and not from a difference, and it is the same reason the step
           of a difference could never be trusted here. */
        $before = Houses::calculate(HouseSystem::WholeSign, self::JD_UT - 1 / 48, self::LATITUDE, self::LONGITUDE)->cusps;
        $after = Houses::calculate(HouseSystem::WholeSign, self::JD_UT + 1 / 48, self::LATITUDE, self::LONGITUDE)->cusps;

        foreach (range(1, 12) as $cusp) {
            $moved = fmod(fmod($after[$cusp] - $before[$cusp], 360.0) + 360.0, 360.0);

            $this->assertTrue(
                abs($moved) < 1e-9 || abs($moved - 30.0) < 1e-9,
                "Whole sign cusp {$cusp} moved {$moved} degrees, which is neither nothing nor a whole sign"
            );
        }

        $this->assertEqualsWithDelta(341.824507, $whole->ascendant, 1e-5);
        $this->assertEqualsWithDelta(379.771587, $whole->midheaven, 1e-5);

        // Equal from Aries is the same case and even plainer: its cusps depend on neither the
        // time nor the place.
        $aries = Houses::calculate(HouseSystem::EqualAries, self::JD_UT, self::LATITUDE, self::LONGITUDE)->speeds();

        foreach (range(1, 12) as $cusp) {
            $this->assertSame(0.0, $aries->cusps[$cusp]);
        }
    }

    /**
     * Past the polar circle the whole wheel flips half a turn twice a day, when the midheaven
     * crosses the horizon. That is a jump in the convention and not motion in the sky: differenced
     * straight across it, the ascendant comes out at 32,488,708 degrees a day.
     *
     * At the flip in Tromsø, Swiss gives -0.0046117 for the ascendant, and the three point one
     * sided difference `bracket()` falls back to gives the same to seven figures. The naive one
     * would be off by ten orders of magnitude.
     */
    public function test_the_polar_flip_is_a_jump_and_not_a_speed(): void
    {
        $speeds = Houses::fromArmc(HouseSystem::Campanus, 238.8265, 69.65, 23.4368)->speeds();

        $this->assertEqualsWithDelta(-0.0046117, $speeds->ascendant, 1e-6);
        $this->assertEqualsWithDelta(345.864282, $speeds->midheaven, 1e-5);
        $this->assertEqualsWithDelta(308.403183, $speeds->cusps[11], 1e-5);

        // And away from the flip, where nothing special happens, it is the plain central
        // difference: at this ARMC the ascendant is running backwards, which is what the
        // clockwise wheel of that convention means.
        $away = Houses::fromArmc(HouseSystem::Campanus, 250.0, 69.65, 23.4368)->speeds();

        $this->assertEqualsWithDelta(-201.997722, $away->ascendant, 1e-5);
    }

    /**
     * The thirty six sectors move too, and one in every three of them is a Placidus cusp, so its
     * speed has to be that cusp's.
     */
    public function test_the_gauquelin_sector_speeds_are_the_motion_of_the_sectors(): void
    {
        $houses = Houses::calculate(HouseSystem::Placidus, self::JD_UT, self::LATITUDE, self::LONGITUDE);
        $speeds = $houses->gauquelinSectorSpeeds();
        $cusps = $houses->speeds();

        $this->assertCount(36, $speeds);
        $this->assertEqualsWithDelta($cusps->cusps[1], $speeds[1], 1e-9);
        $this->assertEqualsWithDelta($cusps->cusps[10], $speeds[10], 1e-9);

        // Sector 4 has covered three ninths of its semiarc and cusp 11 a third, so they are the
        // same point; sector 7 and cusp 12 likewise.
        $this->assertEqualsWithDelta($cusps->cusps[12], $speeds[4], 1e-9);
        $this->assertEqualsWithDelta($cusps->cusps[11], $speeds[7], 1e-9);

        // And against the definition: the sector half an hour either side of the instant.
        foreach ([1, 4, 7, 10, 20, 33] as $sector) {
            $before = Houses::calculate(HouseSystem::Placidus, self::JD_UT - 1 / 48, self::LATITUDE, self::LONGITUDE)->gauquelinSectors()[$sector];
            $after = Houses::calculate(HouseSystem::Placidus, self::JD_UT + 1 / 48, self::LATITUDE, self::LONGITUDE)->gauquelinSectors()[$sector];
            $moved = fmod(fmod($after - $before, 360.0) + 360.0, 360.0);

            $this->assertEqualsWithDelta(
                ($moved >= 180.0 ? $moved - 360.0 : $moved) * 24,
                $speeds[$sector],
                0.005 * abs($speeds[$sector]),
                "sector {$sector} does not move at the speed it says"
            );
        }
    }

    /*
     |--------------------------------------------------------------------------
     | The Gauquelin sector taken from the rising and the setting
     |--------------------------------------------------------------------------
     */

    /**
     * Methods 2 to 5 of `swe_gauquelin_sector`, against Swiss and against each other.
     *
     * pyswisseph 2.10.03, `swe.gauquelin_sector(jd, body, method, geopos, 1010.0, 10.0, flags)`
     * with `FLG_MOSEPH | FLG_TOPOCTR` for 2 to 5 and `FLG_MOSEPH` for 0 and 1, which is the
     * geocentric position our own method 0 places.
     *
     * The atmosphere has to be handed over and that is not a formality: methods 3 and 5 carry
     * the refraction, so giving Swiss its own default of 0 °C instead of the 10 °C of
     * `Horizon::TEMPERATURE` moves the sector by up to 0.12 at Tromsø. That is six hundred times
     * everything else in this test.
     */
    public function test_the_gauquelin_sector_from_rising_and_setting_agrees_with_swiss(): void
    {
        $swiss = [
            // place, latitude, longitude, jdUt, body, [method 0, 1, 2, 3, 4, 5]
            ['Madrid', 40.4165, -3.7026, 2446227.0, Body::Sun, [9.713291, 9.713293, 9.711258, 9.713488, 9.712281, 9.714501]],
            ['Madrid', 40.4165, -3.7026, 2446227.0, Body::Moon, [18.762231, 18.593228, 18.889021, 18.804837, 18.852286, 18.768850]],
            ['Madrid', 40.4165, -3.7026, 2446227.0, Body::Mars, [8.745999, 8.735858, 8.745920, 8.755856, 8.745929, 8.755865]],
            ['Oslo', 59.9139, 10.7522, 2452698.833333333, Body::Sun, [3.778915, 3.778930, 3.769559, 3.865557, 3.814974, 3.909369]],
            ['Oslo', 59.9139, 10.7522, 2452698.833333333, Body::Moon, [5.428269, 6.578823, 4.917655, 5.139461, 5.020320, 5.230180]],
            ['Oslo', 59.9139, 10.7522, 2452698.833333333, Body::Mars, [14.679096, 14.678221, 14.681400, 14.482706, 14.681089, 14.482430]],
        ];

        $byRiseAndSet = [
            2 => [Limb::Center, false],
            3 => [Limb::Center, true],
            4 => [Limb::Superior, false],
            5 => [Limb::Superior, true],
        ];

        $spread = 0.0;

        foreach ($swiss as [$name, $latitude, $longitude, $jdUt, $body, $expected]) {
            $place = new Place($name, null, 'Reference', 'XX', $latitude, $longitude, 'UTC');
            $position = Ephemeris::position($body, Time::tt($jdUt));
            $houses = Houses::calculate(HouseSystem::Placidus, $jdUt, $latitude, $longitude);

            // 0 and 1 are the position, with its latitude and without it.
            $this->assertEqualsWithDelta($expected[0], $houses->gauquelinSector($position->longitude, $position->latitude), 1e-3, "{$name} {$body->value}: method 0");
            $this->assertEqualsWithDelta($expected[1], $houses->gauquelinSector($position->longitude, 0.0), 1e-3, "{$name} {$body->value}: method 1");

            foreach ($byRiseAndSet as $method => [$limb, $refraction]) {
                $ours = Houses::gauquelinSectorByRiseAndSet($body, $place, $jdUt, $limb, $refraction);

                $this->assertEqualsWithDelta($expected[$method], $ours, 1e-3, "{$name} {$body->value}: method {$method}");

                $spread = max($spread, abs($ours - $expected[0]));
            }
        }

        // And they are a different definition and not a different road to the same number: half a
        // sector between them, which is twenty minutes of the Earth's turn.
        $this->assertGreaterThan(0.1, $spread);
    }

    /**
     * A body that does not both rise and set has no arc to divide, and this says so instead of
     * returning a number. It is what Swiss documents: «returns an error in a number of cases, for
     * example circumpolar bodies with imeth=2».
     *
     * The Sun at Tromsø in midsummer does not set and in midwinter does not rise, and both throw.
     * The other reading, `gauquelinSector()`, throws there too, for its own reason: it divides
     * diurnal semiarcs like Placidus.
     */
    public function test_a_body_that_does_not_rise_and_set_has_no_sector(): void
    {
        $tromso = new Place('Tromsø', null, 'Norway', 'NO', 69.65, 18.96, 'Europe/Oslo');

        foreach ([2446238.0, 2446421.0] as $jdUt) {
            try {
                Houses::gauquelinSectorByRiseAndSet(Body::Sun, $tromso, $jdUt, Limb::Center, false);
                $this->fail('the midnight Sun has no diurnal arc and should have said so');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('no arc', $e->getMessage());
            }
        }

        // And at Madrid on the same days it answers perfectly well.
        $madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4165, -3.7026, 'Europe/Madrid');

        foreach ([2446238.0, 2446421.0] as $jdUt) {
            $sector = Houses::gauquelinSectorByRiseAndSet(Body::Sun, $madrid, $jdUt, Limb::Center, false);

            $this->assertGreaterThanOrEqual(1.0, $sector);
            $this->assertLessThan(37.0, $sector);
        }
    }

    /**
     * The seam of the two arcs: at the instant of the rise the sector is exactly 1 and at the set
     * exactly 19, with nothing to round.
     *
     * That is not luck, it is what comes of deciding which arc the body is in by WHICH of the two
     * passes before the instant is the later one, rather than by measuring its altitude: the
     * boundary of the arc and the test for the arc are the same computation, so they cannot
     * disagree by a second.
     */
    public function test_the_sector_is_one_at_the_rise_and_nineteen_at_the_set(): void
    {
        $madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4165, -3.7026, 'Europe/Madrid');
        $day = new DateTimeImmutable('1985-06-10', new DateTimeZone('Europe/Madrid'));

        $passes = RiseSet::solvedOfTheDay(Body::Mars, $madrid, $day, Limb::Center, false);

        $this->assertNotNull($passes->rise);
        $this->assertNotNull($passes->set);

        $this->assertEqualsWithDelta(1.0, Houses::gauquelinSectorByRiseAndSet(Body::Mars, $madrid, $passes->rise->jdUt, Limb::Center, false), 1e-6);
        $this->assertEqualsWithDelta(19.0, Houses::gauquelinSectorByRiseAndSet(Body::Mars, $madrid, $passes->set->jdUt, Limb::Center, false), 1e-6);

        // Halfway up the diurnal arc it is halfway through the eighteen sectors of that arc.
        $middle = ($passes->rise->jdUt + $passes->set->jdUt) / 2;

        $this->assertEqualsWithDelta(10.0, Houses::gauquelinSectorByRiseAndSet(Body::Mars, $madrid, $middle, Limb::Center, false), 1e-6);
    }

    /**
     * A sidereal chart answers with the speeds of its tropical one, because the cusps are the
     * same cusps shifted by a constant. What that leaves out is the motion of the ayanamsa
     * itself, some fifty arcseconds a year, and that is written up in `speeds()`.
     */
    public function test_a_sidereal_chart_moves_at_the_same_speed(): void
    {
        $tropical = Houses::calculate(HouseSystem::Regiomontanus, self::JD_UT, self::LATITUDE, self::LONGITUDE);
        $sidereal = $tropical->sidereal(23.7);

        foreach (range(1, 12) as $cusp) {
            $this->assertSame($tropical->speeds()->cusps[$cusp], $sidereal->speeds()->cusps[$cusp]);
        }

        $this->assertSame($tropical->speeds()->ascendant, $sidereal->speeds()->ascendant);
    }

    /**
     * A set of houses built by hand through the constructor carries no latitude and no obliquity,
     * and without those there is no way to move the cusps to see how fast they run. It says so
     * instead of returning a number, which is what `housePosition` does with the same gap.
     */
    public function test_houses_with_no_geometry_cannot_be_moved(): void
    {
        $houses = new Houses(
            system: HouseSystem::Equal,
            cusps: array_fill(1, 12, 0.0),
            ascendant: 0.0,
            midheaven: 270.0,
            vertex: null,
            eastPoint: 0.0,
            siderealTime: 0.0,
        );

        $this->expectException(\RuntimeException::class);

        $houses->speeds();
    }
}
