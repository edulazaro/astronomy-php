<?php

namespace Astronomy\Tests;

use Astronomy\ArcusVisionis;
use Astronomy\Houses;
use Astronomy\Body;
use Astronomy\EquationOfTime;
use Astronomy\Stars;
use Astronomy\HeliacalPhenomena;
use Astronomy\Place;
use Astronomy\RiseSet;
use Astronomy\HouseSystem;
use Astronomy\HeliacalEvent;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * The three doors the engine was missing to be usable from outside.
 *
 * They bring no new astronomy: the three computations were already inside, solved and
 * verified, and the only thing that could not be done was ask for them. That is why what
 * has to be checked here is not that the numbers are good (that is already `HousesTest`,
 * `EphemerisTest` and `HeliacalDetailsTest`'s job) but that **the new door and the old
 * road are the same road**, which is exactly where two implementations drift apart without
 * anybody seeing it.
 */
class EngineEntryPointsTest extends TestCase
{
    /** Six sites, two of them past the polar circle, where the convention changes. */
    private const SITES = [
        ['Madrid', 40.4168, -3.7038],
        ['Oslo', 59.9139, 10.7522],
        ['Ushuaia', -54.8019, -68.3030],
        ['Singapur', 1.3521, 103.8198],
        ['Longyearbyen', 78.2232, 15.6267],
        ['Quito', -0.1807, -78.4678],
    ];

    /** From 1655 to 2377, which is the range a chart is cast over here. */
    private const DATES = [2317000.3, 2378500.7, 2446227.09, 2452641.87, 2540000.2];

    /**
     * `fromArmc()` fed the ARMC of an instant gives the SAME cusps as `calculate()`
     * with that instant, down to the last bit.
     *
     * It is the proof that the refactor did not leave two roads. And it has to be to
     * the bit and not «to a microarcsecond»: with a loose tolerance, one of the two
     * doors could be drifting and this would still stay green.
     *
     * The obliquity is passed to it in radians through the same road `calculate`
     * uses, because the public door takes it in DEGREES and that unit round trip is
     * not exact: of 610 true obliquities only 244 survive `deg2rad(rad2deg())`. That
     * is measured in the test next to this one, which bounds what it costs.
     */
    public function test_the_two_doors_of_the_houses_give_the_same_thing_to_the_bit(): void
    {
        $build = new \ReflectionMethod(Houses::class, 'build');
        $build->setAccessible(true);

        $compared = 0;

        foreach (HouseSystem::all() as $system) {
            foreach (self::SITES as [$city, $latitude, $longitude]) {
                foreach (self::DATES as $jdUt) {
                    try {
                        $byClock = Houses::calculate($system, $jdUt, $latitude, $longitude);
                    } catch (RuntimeException) {
                        // Placidus, Koch, Alcabitius and Topocentric do not exist past
                        // the polar circle, and that is already guarded by `HousesTest`.
                        continue;
                    }

                    $armc = fmod(fmod(Time::apparentSiderealTime($jdUt) + $longitude, 360) + 360, 360);
                    $eps = Time::trueObliquity(Time::centuries(Time::tt($jdUt)));

                    $byArmc = $build->invoke(null, $system, $armc, $latitude, $eps, $byClock->sunDeclination);

                    foreach ($byClock->cusps as $house => $cusp) {
                        $this->assertSame($cusp, $byArmc->cusps[$house], sprintf(
                            '%s at %s, jd %.2f, cusp %d', $system->value, $city, $jdUt, $house + 1
                        ));
                        $compared++;
                    }

                    $this->assertSame($byClock->ascendant, $byArmc->ascendant);
                    $this->assertSame($byClock->midheaven, $byArmc->midheaven);
                    $this->assertSame($byClock->vertex, $byArmc->vertex);
                    $this->assertSame($byClock->eastPoint, $byArmc->eastPoint);
                    $this->assertSame($byClock->kochCoAscendant, $byArmc->kochCoAscendant);
                    $this->assertSame($byClock->munkaseyCoAscendant, $byArmc->munkaseyCoAscendant);
                    $this->assertSame($byClock->polarAscendant, $byArmc->polarAscendant);
                }
            }
        }

        $this->assertGreaterThan(7000, $compared, 'The cusps of the 23 systems at six sites and five dates were expected.');
    }

    /**
     * What it costs to go in through the public door, which takes the obliquity in
     * degrees.
     *
     * The number is bounded above on purpose and not checked for equality: what has
     * to be stopped is it growing, not it existing. Measured, the worst case is
     * 1.1e-13 degrees, that is 4e-10 arcseconds, ten orders of magnitude below the
     * 0.18 seconds the engine runs against the JPL. And it is not from the
     * calculation: it is from converting radians to degrees and back.
     */
    public function test_the_door_in_degrees_costs_a_hundred_billionth_of_an_arcsecond(): void
    {
        $worst = 0.0;

        foreach (HouseSystem::all() as $system) {
            foreach (self::SITES as [, $latitude, $longitude]) {
                foreach (self::DATES as $jdUt) {
                    try {
                        $byClock = Houses::calculate($system, $jdUt, $latitude, $longitude);
                    } catch (RuntimeException) {
                        continue;
                    }

                    $armc = fmod(fmod(Time::apparentSiderealTime($jdUt) + $longitude, 360) + 360, 360);
                    $byArmc = Houses::fromArmc($system, $armc, $latitude, $byClock->obliquity, $byClock->sunDeclination);

                    foreach ($byClock->cusps as $house => $cusp) {
                        $worst = max($worst, abs($cusp - $byArmc->cusps[$house]));
                    }
                }
            }
        }

        $this->assertLessThan(1e-12, $worst, sprintf('Worst difference %.3e degrees.', $worst));
    }

    /**
     * The ARMC arrives folded, and an ARMC that already came in range passes through
     * untouched.
     *
     * The second part is not cosmetic: `normalize` adds 360 and takes it back off,
     * which loses the low bits of 68% of the values that were already in [0,360).
     * Without the shortcut, the door could not give the same cusps to the bit.
     */
    public function test_the_armc_folds_only_if_it_needs_to(): void
    {
        $inRange = Houses::fromArmc(HouseSystem::Regiomontanus, 217.4321, 40.4168, 23.4392);
        $wrappedAround = Houses::fromArmc(HouseSystem::Regiomontanus, 217.4321 + 720.0, 40.4168, 23.4392);
        $negative = Houses::fromArmc(HouseSystem::Regiomontanus, 217.4321 - 360.0, 40.4168, 23.4392);

        $this->assertSame(217.4321, $inRange->siderealTime);

        foreach ($inRange->cusps as $house => $cusp) {
            $this->assertEqualsWithDelta($cusp, $wrappedAround->cusps[$house], 1e-9);
            $this->assertEqualsWithDelta($cusp, $negative->cusps[$house], 1e-9);
        }
    }

    /**
     * Sunshine is the only one of the twenty three that needs an ephemeris, and
     * without one it throws.
     *
     * Returning zero would be a Sun on the equator, that is equinox cusps for any
     * day of the year, looking perfectly fine. It is the same rule the engine
     * already follows with Uranus's arcus visionis: outside what is known, nothing,
     * not a number.
     */
    public function test_sunshine_with_no_solar_declination_throws_instead_of_making_one_up(): void
    {
        $this->expectException(RuntimeException::class);

        Houses::fromArmc(HouseSystem::Sunshine, 200.0, 40.4168, 23.4392);
    }

    /**
     * The other twenty two systems come out with no ephemerides at all.
     *
     * That is the whole reason for this door to exist: `Houses` can be used without
     * dragging the ephemeris engine along behind it. It checks that none of them
     * complains and that the twelve cusps are real numbers.
     */
    public function test_the_other_twenty_two_systems_need_no_ephemerides(): void
    {
        $noEphemerides = 0;

        foreach (HouseSystem::all() as $system) {
            if ($system === HouseSystem::Sunshine) {
                continue;
            }

            $houses = Houses::fromArmc($system, 137.5, 40.4168, 23.4392);

            $this->assertCount(12, $houses->cusps);

            foreach ($houses->cusps as $cusp) {
                $this->assertIsFloat($cusp);
                $this->assertTrue(is_finite($cusp));
            }

            $noEphemerides++;
        }

        $this->assertSame(22, $noEphemerides);
    }

    /**
     * The equation of time, against its published extremes.
     *
     * These are the four points any manual carries, and here go the REAL values
     * that come out of sampling 2024 hour by hour, not the rounded ones from the
     * literature. The tolerance is loose on purpose: what is being guarded is that
     * the curve has the shape and the sign it has, not a decimal.
     */
    public function test_the_equation_of_time_gives_its_four_published_extremes(): void
    {
        $measured = [];
        $start = Time::civilJulianDay(2024, 1, 1);

        for ($hour = 0; $hour < 366 * 24; $hour++) {
            $jd = $start + $hour / 24.0;
            $measured[] = [$jd, EquationOfTime::minutes($jd)];
        }

        $values = array_column($measured, 1);

        // Mid February, the minimum: the Sun runs a quarter of an hour BEHIND the clock.
        $this->assertEqualsWithDelta(-14.195, min($values), 0.05);
        // Early November, the maximum, and it is the largest of the four.
        $this->assertEqualsWithDelta(16.454, max($values), 0.05);

        $whenTheMinimum = $measured[array_search(min($values), $values, true)][0];
        $whenTheMaximum = $measured[array_search(max($values), $values, true)][0];

        [, $minimumMonth, $minimumDay] = Time::civilDate($whenTheMinimum);
        [, $maximumMonth, $maximumDay] = Time::civilDate($whenTheMaximum);

        $this->assertSame(2, $minimumMonth);
        $this->assertEqualsWithDelta(12, $minimumDay, 1.5);
        $this->assertSame(11, $maximumMonth);
        $this->assertEqualsWithDelta(2, $maximumDay, 1.5);

        // And the four zeros, which are what really fixes the shape of the curve:
        // checking only the extremes, a curve shifted in time would pass just the same.
        $zeros = [];

        for ($i = 1; $i < count($measured); $i++) {
            if (($measured[$i - 1][1] < 0) !== ($measured[$i][1] < 0)) {
                [, $month, $day] = Time::civilDate($measured[$i][0]);
                $zeros[] = [$month, (int) floor($day)];
            }
        }

        $this->assertSame([[4, 15], [6, 12], [9, 1], [12, 24]], $zeros);
    }

    /**
     * Against the definition and through a road that does not touch `EquationOfTime`.
     *
     * True noon is the Sun's culmination, and the engine already knew about that:
     * `RiseSet` tracks it by sampling the sky and refining between samples, with no
     * idea that an equation of time exists. Here it comes out of subtracting two
     * hours. That the two roads give the same instant is what says this is what it
     * claims to be.
     *
     * It is also the computation that was already half written in
     * `BirthChart::relativeToNoon()`.
     */
    public function test_true_noon_agrees_with_the_suns_culmination(): void
    {
        $worst = 0.0;
        $compared = 0;

        foreach ([['Madrid', 40.4168, -3.7038, 'Europe/Madrid'], ['Ushuaia', -54.8019, -68.3030, 'America/Argentina/Ushuaia']] as [$city, $latitude, $longitude, $zone]) {
            $place = new Place($city, null, $city, 'XX', $latitude, $longitude, $zone);

            foreach ([1, 4, 7, 11] as $month) {
                foreach ([3, 18] as $day) {
                    $instant = new DateTimeImmutable(sprintf('2024-%02d-%02d 12:00:00', $month, $day), new DateTimeZone($zone));
                    $culmination = RiseSet::ofTheDay(Body::Sun, $place, $instant)->upperCulmination;

                    $this->assertNotNull($culmination);

                    $mine = EquationOfTime::trueNoon(Time::julianDay($instant), $longitude);
                    $worst = max($worst, abs($mine - $culmination->jdUt) * 86400.0);
                    $compared++;
                }
            }
        }

        $this->assertSame(16, $compared);
        /* Measured: mean 0.0002 seconds of clock time and worst case 0.0003, over
           those sixteen culminations. The bound is set at a hundredth because what
           has to be stopped is it growing: the two roads share no line in common, so
           if they start drifting apart, one of the two has moved. */
        $this->assertLessThan(0.01, $worst, sprintf('Worst difference %.4f seconds.', $worst));
    }

    /**
     * The round trip between mean time and true time closes.
     *
     * The forward trip is direct and the return one iterates, which is the asymmetry
     * of this pair: the equation of time is evaluated at the instant, and on the way
     * back the instant is what is being searched for. If the iteration did not
     * converge, it would not error: it would give an hour that looks fine.
     */
    public function test_the_round_trip_between_mean_and_true_time_closes(): void
    {
        $worst = 0.0;

        for ($step = 0; $step < 400; $step++) {
            $jdUt = 2305500.0 + $step * 700.0;
            $longitude = -180.0 + ($step % 360);
            $mean = $jdUt + $longitude / 360.0;

            $roundTrip = EquationOfTime::toMean(EquationOfTime::toApparent($mean, $longitude), $longitude);
            $worst = max($worst, abs($roundTrip - $mean) * 86400.0);
        }

        // Measured over 400 cases from 1600 to 2400: worst closure 4.0e-5 seconds of clock time.
        $this->assertLessThan(1e-3, $worst, sprintf('Worst closure %.3e seconds.', $worst));
    }

    /**
     * A sundial reads twelve at true noon, and that is the whole definition.
     *
     * It is the circular check on purpose: it pins down that the sign is not
     * flipped. With the sign flipped the curve still has four zeros and two humps,
     * and the sundial runs fast when it should run slow.
     */
    public function test_at_true_noon_the_sundial_reads_twelve(): void
    {
        foreach ([-3.7038, 0.0, 103.8198] as $longitude) {
            foreach ([2460350.0, 2460500.0, 2460620.0] as $jdUt) {
                $noon = EquationOfTime::trueNoon($jdUt, $longitude);

                $this->assertEqualsWithDelta(12.0, EquationOfTime::trueLocalTime($noon, $longitude), 1e-6);
            }
        }

        /* And the sign, written so it cannot be flipped without tripping this: in
           early November the Sun runs ahead, so true noon falls BEFORE twelve of
           mean local time. */
        $november = Time::civilJulianDay(2024, 11, 3, true);

        $this->assertGreaterThan(0, EquationOfTime::minutes($november));
        $this->assertLessThan(12.0, EquationOfTime::meanLocalTime(EquationOfTime::trueNoon($november, 0.0), 0.0));
    }

    /**
     * The magnitude limit is Schoch's table read backwards, and it is checked that
     * way: asking it for the arc of a magnitude and handing it back to it.
     *
     * There is nothing else to check it against, and that is honest to say: it is
     * not `swe_vis_limit_mag`, which answers a different question with a different
     * model. What can be guaranteed is that the round trip is the same table.
     */
    public function test_the_magnitude_limit_is_the_exact_inverse_of_the_arc(): void
    {
        foreach (HeliacalEvent::cases() as $event) {
            for ($magnitude = -3.0; $magnitude <= 3.0; $magnitude += 0.25) {
                $arc = ArcusVisionis::forMagnitude($magnitude, $event);
                $roundTrip = ArcusVisionis::limitMagnitude($arc, $event);

                $this->assertNotNull($roundTrip, sprintf('magnitude %.2f at a %s', $magnitude, $event->name()));
                $this->assertEqualsWithDelta($magnitude, $roundTrip, 1e-9);
            }
        }
    }

    /**
     * Outside the table the line is not stretched: null is returned.
     *
     * Stretched, it would give magnitude 4 at 18.5 degrees, which is a number that
     * looks exactly like the good ones and that nobody has measured. It is the same
     * rule `of()` already follows with Uranus and Neptune, which come out with no
     * arc.
     */
    public function test_outside_the_table_there_is_no_magnitude_limit(): void
    {
        $event = HeliacalEvent::HeliacalRising;
        [$minimum, $maximum] = ArcusVisionis::range($event);

        $this->assertSame([6.5, 16.0], [$minimum, $maximum]);
        $this->assertNull(ArcusVisionis::limitMagnitude($minimum - 0.01, $event));
        $this->assertNull(ArcusVisionis::limitMagnitude($maximum + 0.01, $event));
        $this->assertSame(-3.0, ArcusVisionis::limitMagnitude($minimum, $event));
        $this->assertSame(3.0, ArcusVisionis::limitMagnitude($maximum, $event));

        // A farewell asks for less darkness than a return, so its table starts earlier.
        $this->assertSame([5.8, 15.0], ArcusVisionis::range(HeliacalEvent::HeliacalSetting));
    }

    /**
     * `isVisible()` is the decision the finder makes on the inside, and it is
     * checked against it.
     *
     * A heliacal phenomenon is the day this answer changes. So on the day `find()`
     * returns it has to say yes, and the day before, no: if they said the same
     * thing, the one pulled out would not be the same decision.
     */
    public function test_is_visible_is_the_same_decision_the_finder_makes(): void
    {
        $place = new Place('Madrid', null, 'Spain', 'ES', 40.4168, -3.7038, 'Europe/Madrid');
        $sirius = Stars::find('sirius');
        $from = Time::julianDay(new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('Europe/Madrid')));

        $rising = HeliacalPhenomena::find($sirius, $place, $from, HeliacalEvent::HeliacalRising);

        $this->assertNotNull($rising);

        $day = $rising->observation->date;

        $this->assertTrue(HeliacalPhenomena::isVisible($sirius, $place, $day, HeliacalEvent::HeliacalRising));
        $this->assertFalse(HeliacalPhenomena::isVisible($sirius, $place, $day->modify('-1 day'), HeliacalEvent::HeliacalRising));
    }

    /**
     * The day's magnitude limit, measured where the engine measures the arc. And as
     * a bonus, the number that a trap already written down was missing.
     *
     * Sirius's heliacal rising in Madrid in 2026 falls on 12 August, and that day
     * the Sun is 8.586 degrees below the horizon when the star rises. The arc grows
     * 0.85 degrees a day, so the day before it is 7.738 and Schoch's criterion (7.80
     * for Sirius) still is not met: that is why the event is that day and not the
     * one before.
     *
     * **And now the interesting part.** At that arc of 8.586, the GENERAL table of
     * stars says the faintest thing visible is magnitude -1.61. Sirius shines at
     * -1.46, that is FAINTER than the limit: by the general table, Sirius still
     * would not be visible. And it is nobody's fault, it is exactly what
     * `ArcusVisionis` warns in its docblock, that Sirius is treated separately
     * because it sits forty degrees from the ecliptic and the general table holds
     * for «A not greater than 25°». Here is measured what the confusion costs: the
     * general table would ask it for 8.81 degrees where Schoch asks it for 7.80,
     * that is **a full degree, which at 0.85 degrees a day is a whole day's delay in
     * the date**.
     *
     * This is written as a test because that is the way for the warning not to be
     * mere prose: if someone ever puts Sirius into the general table, this turns red.
     */
    public function test_the_days_magnitude_limit_and_why_sirius_is_treated_separately(): void
    {
        $place = new Place('Madrid', null, 'Spain', 'ES', 40.4168, -3.7038, 'Europe/Madrid');
        $sirius = Stars::find('sirius');
        $event = HeliacalEvent::HeliacalRising;
        $from = Time::julianDay(new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('Europe/Madrid')));

        $rising = HeliacalPhenomena::find($sirius, $place, $from, $event);

        $this->assertNotNull($rising);
        $this->assertSame('2026-08-12', $rising->observation->date->format('Y-m-d'));

        $day = HeliacalPhenomena::magnitudeLimit($sirius, $place, $rising->observation->date, $event);
        $eve = HeliacalPhenomena::magnitudeLimit($sirius, $place, $rising->observation->date->modify('-1 day'), $event);

        $this->assertNotNull($day);
        $this->assertNotNull($eve);

        // The arc is the same one `arcOfTheDay` already publishes: the same measurement, with no criterion on top.
        $this->assertEqualsWithDelta(8.586, $day['arc'], 0.02);
        $this->assertEqualsWithDelta(7.738, $eve['arc'], 0.02);

        // Schoch's criterion for Sirius, which is what decides the event.
        $this->assertSame(7.8, ArcusVisionis::of($sirius, $event));
        $this->assertGreaterThanOrEqual(7.8, $day['arc']);
        $this->assertLessThan(7.8, $eve['arc']);

        // And the general table, which at that arc still sits ahead of Sirius: it would say no.
        $this->assertEqualsWithDelta(-1.610, $day['magnitude'], 0.02);
        $this->assertLessThan($sirius->magnitude, $day['magnitude']);

        // What the confusion costs, in degrees and therefore in days.
        $byTheGeneralTable = ArcusVisionis::forMagnitude($sirius->magnitude, $event);

        $this->assertEqualsWithDelta(8.81, $byTheGeneralTable, 0.02);
        $this->assertEqualsWithDelta(1.01, $byTheGeneralTable - 7.8, 0.05);
        $this->assertGreaterThan($day['arc'], $byTheGeneralTable, 'A whole day of delay.');
    }
}
