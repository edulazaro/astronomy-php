<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\MoonPhase;
use Astronomy\Phenomenon;
use Astronomy\Phenomena;
use Astronomy\Magnitudes;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase, apparent size and brightness, against JPL Horizons.
 *
 * The figures do NOT come from our own code: they are what Horizons returns for
 * 2015 June 21 at 12:00 TT, geocentric, copied by hand so the suite runs with no network. It is
 * the same way of verifying that `EphemerisTest` uses.
 *
 * Requested with `QUANTITIES='9,10,13,23,24'`: magnitude, illuminated percentage, apparent
 * diameter, elongation and phase angle, for twelve bodies.
 *
 * **The margins are what was measured and not a round number**, which is what makes them useful
 * for something. Over these nine bodies:
 *
 * | | worst difference with the JPL |
 * |---|---|
 * | Elongation | 0.14 arcseconds |
 * | Apparent diameter | 0.0071 % (0.048 % in the Moon, which is the choice of radius) |
 * | Illuminated fraction | 0.0065 percentage points |
 * | Phase angle | 26.6 arcseconds |
 * | Magnitude | 0.037 (Neptune) |
 *
 * Of those, the **phase angle one is left unexplained and is bounded**, and it is worth writing
 * down so nobody has to chase it again from scratch. It is not the distances: the three sides of
 * the triangle match what Horizons publishes to one part in ten million. It is not the formula:
 * by the law of cosines and by the angle between the vectors it comes out the same to the
 * thirteenth digit. And it is not the aberration: applying it, Saturn, Neptune and Pluto improve
 * and the other six get worse. It is twenty seven arcseconds in a number that is read in
 * degrees, and from there come the 0.0065 points of illuminated fraction, that is, the Moon
 * comes out at 23.398 % where the JPL says 23.405 %.
 */
class PhenomenaTest extends TestCase
{
    /**
     * Body, phase angle, illuminated percentage, diameter in arcseconds,
     * elongation and magnitude, per Horizons.
     *
     * @return array<string, array{0: Body, 1: float, 2: float, 3: float, 4: float, 5: float}>
     */
    public static function reference(): array
    {
        return [
            //                        phase      illum.     diameter"   elongation  magnitude
            'Mercury' => [Body::Mercury, 114.2452, 29.46378, 8.751382, 22.1278, 0.768],
            'Venus' => [Body::Venus, 100.7148, 40.70737, 28.25632, 44.4623, -4.548],
            'Mars' => [Body::Mars, 1.3026, 99.98697, 3.634881, 2.0032, 1.442],
            'Jupiter' => [Body::Jupiter, 8.3403, 99.47165, 33.03923, 50.0462, -1.826],
            'Saturn' => [Body::Saturn, 2.9369, 99.93411, 18.27831, 149.7157, 0.197],
            'Uranus' => [Body::Uranus, 2.7338, 99.94302, 3.468507, 69.8022, 5.907],
            'Neptune' => [Body::Neptune, 1.8290, 99.97458, 2.306967, 110.0180, 7.740],
            'Pluto' => [Body::Pluto, 0.4627, 99.99841, 0.102686, 165.0482, 14.124],
            'Moon' => [Body::Moon, 122.1373, 23.40470, 1786.401, 57.7348, -8.530],
            /* Chiron and two asteroids. They go here because they are on the chart and because
               their brightness follows the H-G model, which is also a function of the phase
               angle and fits in the same regression: the six small bodies land within a
               thousandth of a magnitude. As a bonus, this verifies their radii, which for a
               hundred-kilometre body is an apparent diameter of hundredths of an arcsecond. */
            'Chiron' => [Body::Chiron, 3.1825, 99.92293, 0.012749, 98.2366, 18.420],
            'Ceres' => [Body::Ceres, 12.6769, 98.78195, 0.642070, 140.7781, 8.034],
            'Vesta' => [Body::Vesta, 25.8713, 94.98863, 0.360823, 85.0805, 7.717],
        ];
    }

    private function instant(): float
    {
        return Time::julianDay(new DateTimeImmutable('2015-06-21 12:00:00', new DateTimeZone('UTC')));
    }

    /**     */
    #[DataProvider('reference')]
    public function test_the_phase_angle_matches_the_jpl(Body $body, float $phase): void
    {
        $phenomenon = Phenomena::of($body, $this->instant());

        $this->assertEqualsWithDelta($phase, $phenomenon->phaseAngle, 0.008, $body->value);
    }

    /**     */
    #[DataProvider('reference')]
    public function test_the_illuminated_fraction_matches_the_jpl(Body $body, float $phase, float $illuminated): void
    {
        $phenomenon = Phenomena::of($body, $this->instant());

        $this->assertEqualsWithDelta($illuminated, $phenomenon->illuminatedFraction * 100.0, 0.01, $body->value);
    }

    /**     */
    #[DataProvider('reference')]
    public function test_the_apparent_diameter_matches_the_jpl(
        Body $body,
        float $phase,
        float $illuminated,
        float $diameter,
    ): void {
        $phenomenon = Phenomena::of($body, $this->instant());

        // In proportion and not in absolute terms: the diameter runs from a tenth of a second in
        // Pluto to half a whole moon, so a fixed margin either says nothing or nobody clears it.
        $this->assertEqualsWithDelta(
            1.0,
            $phenomenon->diameterInArcseconds() / $diameter,
            0.0006,
            $body->value
        );
    }

    /**     */
    #[DataProvider('reference')]
    public function test_the_elongation_matches_the_jpl(
        Body $body,
        float $phase,
        float $illuminated,
        float $diameter,
        float $elongation,
    ): void {
        $phenomenon = Phenomena::of($body, $this->instant());

        $this->assertEqualsWithDelta($elongation, $phenomenon->elongation, 0.0005, $body->value);
    }

    /**     */
    #[DataProvider('reference')]
    public function test_the_magnitude_matches_the_jpl(
        Body $body,
        float $phase,
        float $illuminated,
        float $diameter,
        float $elongation,
        float $magnitude,
    ): void {
        $phenomenon = Phenomena::of($body, $this->instant());

        $this->assertNotNull($phenomenon->magnitude, $body->value);

        /* The margin is the worst error the fit itself declares, plus a bit: the model is
           `V = 5·log10(r·Δ) + f(α)` and there are bodies for which that is not enough, because
           their brightness also depends on which face they show us. The number is not written
           here by hand: it is read from the table, so if the fit improves one day, the test
           tightens on its own. */
        $margin = Magnitudes::residual($body);

        $this->assertNotNull($margin, $body->value);
        $this->assertEqualsWithDelta($magnitude, $phenomenon->magnitude, max(0.02, $margin * 4.0), $body->value);
    }

    /**
     * The Sun does not illuminate itself, so it has no phase and no elongation. And the
     * triangle everything else comes from has a side of zero length there, that is, a division
     * by zero.
     */
    public function test_the_sun_has_no_phase_and_its_disk_is_whole(): void
    {
        $phenomenon = Phenomena::of(Body::Sun, $this->instant());

        $this->assertSame(0.0, $phenomenon->phaseAngle);
        $this->assertSame(1.0, $phenomenon->illuminatedFraction);
        $this->assertSame(0.0, $phenomenon->elongation);
        $this->assertEqualsWithDelta(1887.788, $phenomenon->diameterInArcseconds(), 0.5);
    }

    /**
     * The phase angle is symmetric and does not tell waxing from waning apart: at first quarter
     * and at last quarter it has the same value. What separates them is which side of the Sun
     * the body falls on.
     */
    public function test_waxing_and_waning_are_told_apart_by_the_oriented_elongation(): void
    {
        // First quarter of July 2015: the Moon runs ahead of the Sun and is waxing.
        $waxing = Time::julianDay(new DateTimeImmutable('2015-07-24 04:04:00', new DateTimeZone('UTC')));
        // Last quarter of August: it runs behind and is waning.
        $waning = Time::julianDay(new DateTimeImmutable('2015-08-07 02:03:00', new DateTimeZone('UTC')));

        $oneWaxing = Phenomena::of(Body::Moon, $waxing);
        $oneWaning = Phenomena::of(Body::Moon, $waning);

        // Both show half a disk, and by that alone they are indistinguishable.
        $this->assertEqualsWithDelta(50.0, $oneWaxing->illuminatedFraction * 100.0, 1.5);
        $this->assertEqualsWithDelta(50.0, $oneWaning->illuminatedFraction * 100.0, 1.5);
        $this->assertEqualsWithDelta(90.0, $oneWaxing->phaseAngle, 2.0);
        $this->assertEqualsWithDelta(90.0, $oneWaning->phaseAngle, 2.0);

        // And the oriented elongation does tell them apart.
        $this->assertTrue(Phenomenon::isWaxing(Phenomena::orientedElongation(Body::Moon, $waxing)));
        $this->assertFalse(Phenomenon::isWaxing(Phenomena::orientedElongation(Body::Moon, $waning)));
    }

    /**
     * Saturn's rings are worth nearly a magnitude between wide open and edge on, so a model
     * that only looks at the phase angle gives a believable and skewed brightness. Checked on
     * two dates chosen for that: 2003, with the ring wide open, and 2009, with the ring edge
     * on.
     */
    public function test_saturn_shines_differently_with_the_ring_open_and_edge_on(): void
    {
        // Horizons, same requests: 2002-12-17 (ring wide open) and 2009-09-04 (edge on).
        $open = Phenomena::of(Body::Saturn, Time::julianDay(
            new DateTimeImmutable('2002-12-17 12:00:00', new DateTimeZone('UTC'))
        ));
        $edgeOn = Phenomena::of(Body::Saturn, Time::julianDay(
            new DateTimeImmutable('2009-09-04 12:00:00', new DateTimeZone('UTC'))
        ));

        $this->assertNotNull($open->magnitude);
        $this->assertNotNull($edgeOn->magnitude);

        // With the ring open Saturn is visibly brighter, and that is what a phase-only model
        // cannot say.
        $this->assertLessThan($edgeOn->magnitude - 0.5, $open->magnitude);
    }

    /**
     * The four named phases are INSTANTS, not stretches of the cycle, so they carry a margin and
     * last about a day. Splitting the lunation into eight equal stretches, which is what there
     * used to be, left "new moon" lasting 3.7 days and cut every full moon in half: the closest
     * full moon in seventy years, that of 2016 November 14, came out on the sheet as "waxing
     * gibbous" with 99.83 % of the disk lit.
     */
    public function test_the_full_moon_is_called_full_and_does_not_last_half_a_lunation(): void
    {
        $full = Time::tt(Time::julianDay(
            new DateTimeImmutable('2016-11-14 13:52:00', new DateTimeZone('UTC'))
        ));

        $phase = MoonPhase::at($full);

        $this->assertSame('full-moon', $phase->name);
        $this->assertGreaterThan(99.5, $phase->illumination * 100.0);

        // And each named phase lasts about a day, not four.
        $start = Time::tt(Time::julianDay(
            new DateTimeImmutable('2015-06-01 00:00:00', new DateTimeZone('UTC'))
        ));
        $hours = [];

        for ($i = 0; $i < 30 * 24; $i++) {
            $name = MoonPhase::at($start + $i / 24.0)->name;
            $hours[$name] = ($hours[$name] ?? 0) + 1;
        }

        foreach (['new-moon', 'first-quarter', 'full-moon', 'last-quarter'] as $name) {
            $this->assertArrayHasKey($name, $hours);
            $this->assertLessThan(36, $hours[$name], $name.' lasts too long');
            $this->assertGreaterThan(12, $hours[$name], $name.' lasts too little');
        }
    }

    /**
     * The Moon's orbit is elliptical, so its disk grows and shrinks by fourteen percent between
     * perigee and apogee. That is what sits underneath the word "supermoon", which is not an
     * astronomical term but is a measurable fact.
     */
    public function test_the_moons_disk_grows_at_perigee_and_shrinks_at_apogee(): void
    {
        // The full moon of 2016 November 14 was the closest since 1948; that of 2017 June 9
        // fell almost at apogee.
        $near = MoonPhase::at(Time::tt(Time::julianDay(
            new DateTimeImmutable('2016-11-14 13:52:00', new DateTimeZone('UTC'))
        )));
        $far = MoonPhase::at(Time::tt(Time::julianDay(
            new DateTimeImmutable('2017-06-09 13:10:00', new DateTimeZone('UTC'))
        )));

        $this->assertGreaterThan(5.0, $near->relativeToMeanSize());
        $this->assertLessThan(-4.0, $far->relativeToMeanSize());

        // In arcminutes, which is how it is published: between 29.4 and 33.5.
        $this->assertEqualsWithDelta(33.5, $near->apparentDiameter * 60.0, 0.1);
        $this->assertEqualsWithDelta(29.4, $far->apparentDiameter * 60.0, 0.1);
    }

    /**
     * The illuminated fraction does NOT come from the elongation, which is the angle seen from
     * here, but from the angle seen from the Moon. The formula that circulates uses the
     * elongation and gives an almost-right number: it is off by 0.2 percentage points, and near
     * new moon that is a 5% difference in proportion.
     */
    public function test_the_illuminated_fraction_is_not_the_one_from_the_elongation(): void
    {
        $jd = $this->instant();

        $phase = MoonPhase::at($jd);
        $fromElongation = (1.0 - cos(deg2rad($phase->elongation))) / 2.0;

        // The right one is the JPL's for that instant, 23.4047 %.
        $this->assertEqualsWithDelta(23.4047, $phase->illumination * 100.0, 0.01);

        // And the one from the elongation falls short, which is exactly what there used to be.
        $this->assertLessThan($phase->illumination - 0.0005, $fromElongation);
    }

    public function test_a_body_with_no_disk_is_refused_instead_of_returning_zeros(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Phenomena::of(Body::TrueNode, $this->instant());
    }

    /**
     * Outside the range of phases the fit was made against there is no extrapolating: a
     * polynomial out of range does not fail, it gives a number, and an invented magnitude reads
     * exactly like a good one.
     */
    public function test_outside_the_fitted_range_the_magnitude_is_null(): void
    {
        $this->assertNull(Magnitudes::of(Body::Mars, 1.5, 0.5, 179.0));
        $this->assertNotNull(Magnitudes::of(Body::Mars, 1.5, 0.5, 20.0));
    }
}
