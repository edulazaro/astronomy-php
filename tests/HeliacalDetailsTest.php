<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\HeliacalDetails;
use Astronomy\HeliacalEvent;
use Astronomy\HeliacalPhenomena;
use Astronomy\Horizon;
use Astronomy\Pass;
use Astronomy\Place;
use Astronomy\Stars;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The thirty values of a heliacal phenomenon: `HeliacalPhenomena::detailsAt`.
 *
 * It is checked from two sides, and the two are not worth the same.
 *
 * **Against Yallop's own paper**, which is the better one: his Table 4 publishes the arc of
 * light, the arc of vision, the relative azimuth, the parallax, the crescent width and q of 295
 * observations, so his equations can be fed his own numbers and asked to return his own
 * answers. Eight of those rows are copied in by hand below. A check against the source cannot
 * be fooled by both programs making the same mistake.
 *
 * **Against Swiss Ephemeris 2.10.03**, which pins the geometry. Those reference values are
 * copied by hand too, so the suite runs with no network and with no pyswisseph installed.
 *
 * ### The tolerances are measured, and two of them are large on purpose
 *
 * pyswisseph here runs without ephemeris files, so its positions fall back to Moshier: part of
 * every residual below is that and not this engine. And on top of it **Swiss's heliacal
 * internals disagree with Swiss's own `swe_azalt` at the same instant**, by 7.3 arcseconds in
 * altitude and 14 to 32 in azimuth, which is written up in `HeliacalDetails`.
 *
 * So the tolerances below sound slack and are not: they are a floor that belongs to the
 * comparison rather than to the reckoning, and each is the worst case measured over these six
 * rows rounded up. Altitudes come out within 24 arcseconds, azimuths within 51 and the arcs
 * within 24, and the worst of all three is Venus, whose Moshier position is the furthest from
 * this engine's. What that floor does NOT reach is `parallax`, which is a difference of two
 * altitudes of the same body and lands within a twentieth of an arcsecond.
 */
final class HeliacalDetailsTest extends TestCase
{
    /** Altitudes: Swiss's own internal offset plus Moshier. Worst measured, 23.2 arcseconds. */
    private const ALTITUDE_TOLERANCE = 30.0 / 3600.0;

    /** Azimuths, where Swiss's offset is four times what it is in altitude. Worst, 50.2. */
    private const AZIMUTH_TOLERANCE = 60.0 / 3600.0;

    /** The arcs, which cancel most of that offset because both altitudes carry it. Worst, 23.5. */
    private const ARC_TOLERANCE = 30.0 / 3600.0;

    /**
     * @param string $key
     * @return Place
     */
    private static function place(string $key): Place
    {
        return match ($key) {
            'madrid' => new Place('Madrid', 'Madrid', 'Spain', 'ES', 40.4168, -3.7038, 'Europe/Madrid'),
            'babylon' => new Place('Babylon', null, 'Iraq', 'IQ', 32.5364, 44.4208, 'Asia/Baghdad'),
            'oslo' => new Place('Oslo', null, 'Norway', 'NO', 59.9139, 10.7522, 'Europe/Oslo'),
        };
    }

    /**
     * @param string $when
     * @return float
     */
    private static function jdUt(string $when): float
    {
        return Time::julianDay(new DateTimeImmutable($when, new DateTimeZone('UTC')));
    }

    /**
     * Values copied by hand from `swe_heliacal_pheno_ut` of pyswisseph 2.10.03, with the
     * standard atmosphere (1010 mb, 10 °C, 40 % humidity) and the default observer, which none
     * of these values depends on.
     *
     * Four evening crescents, chosen so that four of Yallop's six classes come out (A, F, C and
     * B), plus an evening planet and a morning one.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: float, 4: float, 5: float, 6: float, 7: float, 8: float, 9: float, 10: float, 11: float, 12: float|null, 13: float|null, 14: string|null}>
     */
    public static function swissValues(): array
    {
        return [
            //                            object      place      instant (UT)            AltO      GeoAltO   AziO       AltS      AziS       ARCV      DAZ        ARCL      ParO        W'       q         class
            'moon, Madrid, thin' => ['moon', 'madrid', '2000-08-29 19:15:00', -0.54949, 0.44827, 283.68144, -5.22363, 286.55629, 5.67189, 2.87485, 6.35674, 0.9977595, 0.10029, -0.55384, 'F'],
            'moon, Madrid' => ['moon', 'madrid', '2000-08-30 19:30:00', 2.99602, 3.98149, 274.00080, -8.24570, 288.87482, 12.22719, 14.87401, 19.16655, 0.9854712, 0.89404, 0.55305, 'A'],
            'moon, Babylon' => ['moon', 'babylon', '2015-06-17 16:00:00', 8.59376, 9.52724, 285.46708, 1.35486, 297.11318, 8.17238, 11.64611, 14.19493, 0.9334810, 0.46718, -0.08603, 'C'],
            'moon, Oslo' => ['moon', 'oslo', '2020-03-25 18:30:00', 1.63972, 2.53665, 272.80962, -6.39532, 285.72417, 8.93197, 12.91454, 15.65911, 0.8969332, 0.54457, 0.03374, 'B'],
            'Venus, Madrid' => ['venus', 'madrid', '2000-08-30 19:00:00', 7.99951, 8.00107, 264.73388, -2.76202, 283.85766, 10.76309, 19.12378, 21.84535, 0.0015553, null, null, null],
            'Mercury, Madrid' => ['mercury', 'madrid', '2000-04-04 04:30:00', -6.73135, -6.72890, 93.24418, -16.13380, 67.38702, 9.40491, -25.85716, 27.40340, 0.0024552, null, null, null],
        ];
    }

    #[DataProvider('swissValues')]
    public function test_the_geometry_matches_swiss_ephemeris(
        string $object,
        string $place,
        string $when,
        float $topocentricAltitude,
        float $geocentricAltitude,
        float $azimuth,
        float $sunAltitude,
        float $sunAzimuth,
        float $arcOfVision,
        float $azimuthDifference,
        float $arcOfLight,
        float $parallax,
        ?float $crescentWidth,
        ?float $yallopQ,
        ?string $yallopClass,
    ): void {
        $body = Body::from($object);
        $details = HeliacalPhenomena::detailsAt($body, self::place($place), self::jdUt($when));

        $this->assertEqualsWithDelta($topocentricAltitude, $details->topocentricAltitude, self::ALTITUDE_TOLERANCE, 'AltO');
        $this->assertEqualsWithDelta($geocentricAltitude, $details->geocentricAltitude, self::ALTITUDE_TOLERANCE, 'GeoAltO');
        $this->assertEqualsWithDelta($azimuth, $details->azimuth, self::AZIMUTH_TOLERANCE, 'AziO');
        $this->assertEqualsWithDelta($sunAltitude, $details->sunAltitude, self::ALTITUDE_TOLERANCE, 'AltS');
        $this->assertEqualsWithDelta($sunAzimuth, $details->sunAzimuth, self::AZIMUTH_TOLERANCE, 'AziS');

        $this->assertEqualsWithDelta($arcOfVision, $details->arcOfVision, self::ARC_TOLERANCE, 'ARCV');
        $this->assertEqualsWithDelta($azimuthDifference, $details->azimuthDifference, self::ARC_TOLERANCE, 'DAZ');
        $this->assertEqualsWithDelta($arcOfLight, $details->arcOfLight, self::ARC_TOLERANCE, 'ARCL');

        /* The parallax is a difference of two altitudes of the SAME body, so Swiss's own offset
           cancels in it completely and what is left is only the two ephemerides. A twentieth of
           an arcsecond over a quantity that reaches a whole degree. */
        $this->assertEqualsWithDelta($parallax, $details->parallax, 0.05 / 3600.0, 'ParO');

        if ($crescentWidth === null) {
            $this->assertNull($details->crescentWidth, 'only the Moon has a crescent');
            $this->assertNull($details->yallopQ);
            $this->assertNull($details->yallopClass);

            return;
        }

        /* W' comes out between 0.2 % and 1.4 % above Swiss's, and every bit of that is the
           parallax of equation (3.8): Swiss feeds it the parallax in altitude and Yallop's own
           Table 4 feeds it the horizontal one. See `test_the_crescent_width_is_built_on_the_true_semi_diameter`. */
        $this->assertEqualsWithDelta($crescentWidth, $details->crescentWidth, 0.02 * $crescentWidth + 0.001, "W'");
        $this->assertEqualsWithDelta($yallopQ, $details->yallopQ, 0.02, 'q');
        $this->assertSame($yallopClass, $details->yallopClass, 'Yallop class');
    }

    /**
     * The arcs come out far closer to Swiss than the altitudes they are made of, and that is
     * not luck: Swiss's heliacal altitudes carry an offset of some 7.3 arcseconds against its
     * own `swe_azalt`, the same one on the object and on the Sun, so subtracting one from the
     * other takes it out.
     *
     * It is worth a test of its own because it is what says the residual is Swiss's and not a
     * mistake here. If one day the altitudes came into line and the arcs did not, that would be
     * the other way round.
     */
    public function test_the_arcs_cancel_the_offset_the_altitudes_carry(): void
    {
        $details = HeliacalPhenomena::detailsAt(Body::Moon, self::place('madrid'), self::jdUt('2000-08-30 19:30:00'));

        $altitudeGap = abs($details->geocentricAltitude - 3.98149) * 3600.0;
        $arcGap = abs($details->arcOfVision - 12.22719) * 3600.0;

        $this->assertGreaterThan(4.0, $altitudeGap, 'the altitude is several arcseconds from Swiss');
        $this->assertLessThan(1.0, $arcGap, 'and the arc built out of it is within one');
    }

    /**
     * Eight rows of Yallop's Table 4, pages 11 and 12, copied by hand: his arc of vision, his
     * relative azimuth and his parallax go in, and his arc of light, his crescent width and his
     * q have to come back.
     *
     * **This is the test that matters most**, because it checks the equations against the paper
     * they come from instead of against another implementation of them. His table is printed to
     * a tenth of a degree, a hundredth of an arcminute and a thousandth of q, and the
     * tolerances below are those roundings and nothing else.
     *
     * The one thing his table does not publish is h, the geocentric altitude of equation (3.9),
     * so zero goes in: measured, sweeping h from 0 to 10 degrees moves W' by less than 0.002
     * arcminutes over these eight rows, which is under the hundredth they are printed to.
     *
     * **And the tolerance on ARCL is the rounding of his own columns, propagated.** His ARCV
     * and DAZ are printed to a tenth of a degree, and (2.1) behaves near enough like
     * `ARCL² = ARCV² + DAZ²`, so half a tenth on each of them is worth up to 0.07 in the ARCL
     * that comes back. Row 169 is the one that uses it, at 0.063.
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}>
     */
    public static function yallopTable4(): array
    {
        //                   ARCL   ARCV   DAZ    parallax'  W''    q
        return [
            'No 275, 1984-11-23' => [9.2, 7.6, 5.2, 59.5, 0.21, -0.296],
            'No 7, 1861-08-07' => [16.0, 5.0, 15.2, 59.0, 0.63, -0.316],
            'No 231, 1988-04-16' => [7.7, 7.6, -1.2, 59.2, 0.15, -0.330],
            'No 169, 1986-12-31' => [12.4, 6.0, 10.8, 61.3, 0.39, -0.348],
            'No 54, 1873-12-20' => [11.6, 5.4, 10.3, 58.3, 0.33, -0.447],
            'No 242, 1989-06-03' => [7.0, 6.3, -3.1, 59.2, 0.12, -0.484],
            'No 229, 1987-09-23' => [9.9, 4.2, 9.0, 55.9, 0.23, -0.620],
            'No 256, 1984-01-03' => [5.5, 4.2, 3.6, 55.0, 0.07, -0.720],
        ];
    }

    #[DataProvider('yallopTable4')]
    public function test_the_equations_reproduce_yallops_own_table(
        float $arcOfLight,
        float $arcOfVision,
        float $azimuthDifference,
        float $parallaxArcminutes,
        float $crescentWidth,
        float $q,
    ): void {
        // (2.1), against the ARCL of his column 9.
        $this->assertEqualsWithDelta(
            $arcOfLight,
            HeliacalDetails::arcOfLightOf($arcOfVision, $azimuthDifference),
            0.08,
            'ARCL out of (2.1)'
        );

        // (3.8) to (3.10), against the W' of his column 15. His parallax is in arcminutes.
        $this->assertEqualsWithDelta(
            $crescentWidth,
            HeliacalDetails::crescentWidthOf($parallaxArcminutes / 60.0, 0.0, $arcOfLight),
            0.008,
            "W' out of (3.8) to (3.10)"
        );

        // (6.1), against the q of his column 16, fed his own W'.
        $this->assertEqualsWithDelta($q, HeliacalDetails::qOf($arcOfVision, $crescentWidth), 0.006, 'q out of (6.1)');
    }

    /**
     * Yallop's Table 5: six classes, five boundaries, and the boundary value itself belongs to
     * the class BELOW it, because his ranges read «+0·216 ≥ q > −0·014».
     */
    public function test_the_six_classes_are_yallops_table_5(): void
    {
        $this->assertSame('A', HeliacalDetails::classOf(0.2161));
        $this->assertSame('B', HeliacalDetails::classOf(0.216), 'the boundary belongs to the class below');
        $this->assertSame('B', HeliacalDetails::classOf(0.0));
        $this->assertSame('C', HeliacalDetails::classOf(-0.014));
        $this->assertSame('D', HeliacalDetails::classOf(-0.160));
        $this->assertSame('E', HeliacalDetails::classOf(-0.232));
        $this->assertSame('F', HeliacalDetails::classOf(-0.293));
        $this->assertSame('F', HeliacalDetails::classOf(-10.0));
    }

    /**
     * The one decision in this class that departs from Swiss, held down by the measurement that
     * settles it: **equation (3.8) takes the horizontal parallax**, because that is the only one
     * of the two that turns `0.27245 π` into a semi-diameter.
     *
     * The numbers are Madrid on 2000-08-30 19:30 UT, with the Moon 3.98 degrees up: the true
     * geocentric semi-diameter is 16.150 arcminutes, `0.27245 × horizontal parallax` gives
     * 16.154 and `0.27245 × parallax in altitude` gives 16.109. One is right to four
     * thousandths of an arcminute and the other is forty short.
     *
     * The gap between the two widens as the Moon climbs, because the parallax in altitude falls
     * away as the cosine: at 3.98 degrees it is a quarter of a per cent and at 13.67 it is 2.6.
     */
    public function test_the_crescent_width_is_built_on_the_true_semi_diameter(): void
    {
        $jdUt = self::jdUt('2000-08-30 19:30:00');
        $details = HeliacalPhenomena::detailsAt(Body::Moon, self::place('madrid'), $jdUt);

        $geocentric = Horizon::equatorialOf(Body::Moon, Time::tt($jdUt));
        $horizontalParallax = rad2deg(asin(Horizon::EQUATORIAL_RADIUS_KM / $geocentric->distanceKm));
        $trueSemiDiameter = 60.0 * Horizon::semidiameter($geocentric, Body::Moon->radiusKm());

        $this->assertEqualsWithDelta($trueSemiDiameter, 60.0 * 0.27245 * $horizontalParallax, 0.006, 'the horizontal parallax gives the semi-diameter');
        $this->assertGreaterThan(0.03, abs($trueSemiDiameter - 60.0 * 0.27245 * $details->parallax), 'and the parallax in altitude does not');

        // And what is returned is the first of the two, to the last bit.
        $this->assertEqualsWithDelta(
            HeliacalDetails::crescentWidthOf($horizontalParallax, $details->geocentricAltitude, $details->arcOfLight),
            $details->crescentWidth,
            1e-12
        );
    }

    /**
     * Yallop (4.1): the best time is four ninths of the way from the Sun's crossing to the
     * object's. It is checked against the instants that are returned, so a change of rise
     * convention cannot hide a change of rule.
     */
    public function test_the_best_time_is_four_ninths_of_the_lag_after_the_sun(): void
    {
        $details = HeliacalPhenomena::detailsAt(Body::Moon, self::place('madrid'), self::jdUt('2000-08-30 19:30:00'));

        $this->assertNotNull($details->bestTime);
        $this->assertNotNull($details->objectPass);
        $this->assertNotNull($details->sunPass);

        $lag = $details->objectPass->jdUt - $details->sunPass->jdUt;
        $fraction = ($details->bestTime->jdUt - $details->sunPass->jdUt) / $lag;

        /* Not to the last bit, and the reason is arithmetic rather than astronomy: a Julian day
           of 2000 is a seven-figure number whose last bit is 4.7e-10 of a day, and dividing that
           by a lag of an hour leaves a part in a hundred million. Measured here, 1.2e-9. */
        $this->assertEqualsWithDelta(4.0 / 9.0, $fraction, 1e-7);
        $this->assertEqualsWithDelta($lag * 1440.0, $details->lag, 1e-9, 'the lag is the same difference, in minutes');
        $this->assertGreaterThan(0.0, $details->lag, 'an evening object sets AFTER the Sun');
    }

    /**
     * A morning object rises before the Sun, so its lag is negative. Yallop's own Table 4 is
     * written that way: its morning rows carry negative lags.
     *
     * Which side of the Sun the object is on is not asked for, it is read off the longitudes,
     * and this is what says the reading works in both directions.
     */
    public function test_the_side_of_the_sun_is_read_off_the_geometry(): void
    {
        $evening = HeliacalPhenomena::detailsAt(Body::Moon, self::place('madrid'), self::jdUt('2000-08-30 19:30:00'));
        $morning = HeliacalPhenomena::detailsAt(Body::Mercury, self::place('madrid'), self::jdUt('2000-04-04 04:30:00'));

        $this->assertSame(Pass::Set, $evening->pass, 'a waxing Moon is an evening object');
        $this->assertFalse($evening->isMorning());

        $this->assertSame(Pass::Rise, $morning->pass);
        $this->assertTrue($morning->isMorning());
        $this->assertLessThan(0.0, $morning->lag, 'a morning object rises BEFORE the Sun');
    }

    /**
     * A star goes through the same door as a body and three things come back different, all
     * three of them true rather than missing: it has no parallax, because it has no distance to
     * have one over; it has no phase, because it shines by itself; and it has no crescent.
     */
    public function test_a_star_has_no_parallax_no_phase_and_no_crescent(): void
    {
        $sirius = Stars::find('Sirius');
        $details = HeliacalPhenomena::detailsAt($sirius, self::place('madrid'), self::jdUt('2000-08-15 03:40:00'));

        $this->assertSame('Sirius', $details->name);
        $this->assertSame(0.0, $details->parallax);
        $this->assertSame($details->geocentricAltitude, $details->topocentricAltitude);
        $this->assertNull($details->illuminatedPercent);
        $this->assertNull($details->crescentWidth);
        $this->assertNull($details->yallopQ);
        $this->assertNull($details->yallopClass);
        $this->assertNull($details->yallopRemark());

        // Its magnitude comes from the catalogue, not from a phase model.
        $this->assertEqualsWithDelta(-1.46, $details->magnitude, 0.01);

        // Mid-August before dawn is the season of its heliacal rising: it comes up about an
        // hour before the Sun.
        $this->assertSame(Pass::Rise, $details->pass);
        $this->assertEqualsWithDelta(-65.0, $details->lag, 5.0);
    }

    /**
     * The eight of the thirty that cannot be produced honestly come back null, and they keep
     * coming back null: five want Schaefer's contrast model, one wants an atmosphere this
     * package does not carry, and two have no published definition at all. The reasons are in
     * `HeliacalDetails`, one per field.
     *
     * A test for this exists because the failure it guards against is silent: a zero in any of
     * these would read exactly like a measurement.
     */
    public function test_the_eight_values_that_cannot_be_produced_come_back_null(): void
    {
        $details = HeliacalPhenomena::detailsAt(Body::Moon, self::place('madrid'), self::jdUt('2000-08-30 19:30:00'));

        $this->assertNull($details->extinctionCoefficient);
        $this->assertNull($details->minimumTopocentricArc);
        $this->assertNull($details->firstVisible);
        $this->assertNull($details->bestVisibleByContrast);
        $this->assertNull($details->lastVisible);
        $this->assertNull($details->visibilityDuration);
        $this->assertNull($details->crescentLength);
        $this->assertNull($details->crescentVisibilityAngle);
    }

    /**
     * The Moon is allowed here and rejected by the search, and neither is an oversight: the
     * search looks for a heliacal date, which for the Moon is the crescent's first visibility
     * and another problem; here the crescent is the whole subject.
     */
    public function test_the_moon_is_allowed_here_and_not_in_the_search(): void
    {
        $details = HeliacalPhenomena::detailsAt(Body::Moon, self::place('madrid'), self::jdUt('2000-08-30 19:30:00'));

        $this->assertNotNull($details->crescentWidth);

        $this->expectException(InvalidArgumentException::class);
        HeliacalPhenomena::find(Body::Moon, self::place('madrid'), self::jdUt('2000-08-30 19:30:00'), HeliacalEvent::FirstEveningVisibility);
    }

    /**
     * @return array<string, array{0: Body}>
     */
    public static function whatCannotBeLookedAt(): array
    {
        return [
            'the Sun, which the arc is measured against' => [Body::Sun],
            'the Earth, which is where one is standing' => [Body::Earth],
            'a lunar node, which is a direction' => [Body::TrueNode],
            'a body nobody has ever seen' => [Body::Cupido],
        ];
    }

    #[DataProvider('whatCannotBeLookedAt')]
    public function test_what_cannot_be_looked_at_throws(Body $body): void
    {
        $this->expectException(InvalidArgumentException::class);
        HeliacalPhenomena::detailsAt($body, self::place('madrid'), self::jdUt('2000-08-30 19:30:00'));
    }

    /**
     * The two arcs and the arc of light hang together by the definitions they are written from,
     * and that can be checked without asking anybody: ARCV is the object's geocentric altitude
     * above the Sun's, TAV is the same with the object seen from the ground, and the difference
     * between the two is exactly the parallax.
     */
    public function test_the_arcs_are_the_differences_they_are_defined_as(): void
    {
        $details = HeliacalPhenomena::detailsAt(Body::Moon, self::place('babylon'), self::jdUt('2015-06-17 16:00:00'));

        $this->assertEqualsWithDelta(
            $details->geocentricAltitude - $details->sunAltitude,
            $details->arcOfVision,
            1e-12
        );
        $this->assertEqualsWithDelta(
            $details->topocentricAltitude - $details->sunAltitude,
            $details->topocentricArcOfVision,
            1e-12
        );
        $this->assertEqualsWithDelta(
            $details->arcOfVision - $details->topocentricArcOfVision,
            $details->parallax,
            1e-12
        );
        $this->assertEqualsWithDelta(
            HeliacalDetails::arcOfLightOf($details->arcOfVision, $details->azimuthDifference),
            $details->arcOfLight,
            1e-12
        );
    }

    /**
     * The written line, which is the only thing most callers will read, and which drops the
     * crescent half where there is no crescent instead of printing zeros for it.
     */
    public function test_the_summary_says_what_it_has_and_nothing_else(): void
    {
        $moon = HeliacalPhenomena::detailsAt(Body::Moon, self::place('madrid'), self::jdUt('2000-08-30 19:30:00'));
        $venus = HeliacalPhenomena::detailsAt(Body::Venus, self::place('madrid'), self::jdUt('2000-08-30 19:00:00'));

        $this->assertStringContainsString('Moon on 2000-08-30 21:30', $moon->summary());
        $this->assertStringContainsString('q = +0.55', $moon->summary());
        $this->assertStringContainsString('(A, easily visible to the unaided eye)', $moon->summary());

        $this->assertStringContainsString('Venus on', $venus->summary());
        $this->assertStringNotContainsString('q =', $venus->summary());
        $this->assertStringNotContainsString('crescent', $venus->summary());
    }
}
