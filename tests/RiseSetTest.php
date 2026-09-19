<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Horizon;
use Astronomy\Limb;
use Astronomy\Pass;
use Astronomy\Place;
use Astronomy\RiseSet;
use Astronomy\Stars;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The passes of a body over the horizon of a place, by the two roads that lead there.
 *
 * `RiseSet::ofTheDay()` tracks the day and `RiseSet::solvedOfTheDay()` solves a fixed point, and
 * the point of having both is written up on the second one. What is checked here is that they say
 * the same thing, which is the only way the fast one earns its place, and that the instant they
 * return is the instant the definition names: the moment the body is at the altitude that pass
 * happens at, computed by a road that knows nothing about either of them.
 */
final class RiseSetTest extends TestCase
{
    private function madrid(): Place
    {
        return new Place('Madrid', null, 'Spain', 'ES', 40.4165, -3.7026, 'Europe/Madrid');
    }

    private function oslo(): Place
    {
        return new Place('Oslo', null, 'Norway', 'NO', 59.9139, 10.7522, 'Europe/Oslo');
    }

    /**
     * The two roads agree.
     *
     * Ten bodies, three places and three days, which is 360 passes counting the ones that do not
     * exist. **The worst disagreement is 0.038 seconds of clock time**, and it is the Moon setting
     * in Oslo: the Moon is the one whose declination moves fastest, so it is the one where
     * estimating from the position at midnight and converging has the most to correct.
     *
     * And where the tracker finds nothing this finds nothing, which matters more than the seconds:
     * a civil day can have no moonrise at all, the Moon falling fifty minutes behind each day, and
     * a fast method that quietly invented one there would be worse than a slow one.
     */
    public function test_the_solved_passes_agree_with_the_tracked_ones(): void
    {
        $places = [
            $this->madrid(),
            $this->oslo(),
            new Place('Ushuaia', null, 'Argentina', 'AR', -54.8019, -68.3030, 'America/Argentina/Ushuaia'),
        ];

        $worst = 0.0;

        foreach ($places as $place) {
            foreach (['1985-06-10', '2003-02-28', '1981-12-21'] as $date) {
                $day = new DateTimeImmutable($date, $place->timeZone());

                foreach (Body::classical() as $body) {
                    $tracked = RiseSet::ofTheDay($body, $place, $day);
                    $solved = RiseSet::solvedOfTheDay($body, $place, $day);

                    foreach (['rise', 'set', 'upperCulmination', 'lowerCulmination'] as $pass) {
                        $this->assertSame(
                            $tracked->$pass === null,
                            $solved->$pass === null,
                            "{$place->name} {$date} {$body->value}: the two roads disagree about whether there is a {$pass}"
                        );

                        if ($tracked->$pass === null) {
                            continue;
                        }

                        $seconds = abs($solved->$pass->jdUt - $tracked->$pass->jdUt) * 86400;
                        $worst = max($worst, $seconds);

                        $this->assertLessThan(
                            0.2,
                            $seconds,
                            "{$place->name} {$date} {$body->value}: {$pass} differs by {$seconds} seconds"
                        );
                    }
                }
            }
        }

        $this->assertLessThan(0.05, $worst);

        /* And a star comes in the same way and does better, which is the clearest thing the
           method says about itself: a star does not move in a day, so the first round of the
           fixed point already has the right declination and there is nothing left to correct.
           Measured, three stars in Madrid: 0.0002 seconds against the tracker. */
        $day = new DateTimeImmutable('1985-06-10', $this->madrid()->timeZone());

        foreach (['Antares', 'Sirius', 'Vega'] as $name) {
            $star = Stars::find($name);

            $tracked = RiseSet::ofTheDay($star, $this->madrid(), $day, Limb::Center, false);
            $solved = RiseSet::solvedOfTheDay($star, $this->madrid(), $day, Limb::Center, false);

            $this->assertNotNull($tracked->rise);
            $this->assertEqualsWithDelta(
                $tracked->rise->jdUt,
                $solved->rise->jdUt,
                0.001 / 86400,
                "{$name}: a star should solve in one round"
            );
        }
    }

    /**
     * And against the definition, which asks nothing of either road: at the instant returned, the
     * body is at the altitude that pass happens at.
     *
     * The altitude is read back through `Horizon::at()`, which goes from the ephemeris to the
     * topocentric vector to the horizon without passing through anything in `RiseSet`. With the
     * centre of the disc and no atmosphere that altitude is zero, flat. With the upper limb and
     * refraction it is one semidiameter plus the refraction at the horizon BELOW zero, which for
     * the Sun is the 50 arcminutes an almanac works with.
     *
     * The tolerance is 1e-4 degrees and it is the accuracy of the instant read as an angle, not
     * slack: measured over five bodies and three days the worst residual is 7.2e-5 degrees, and at
     * the rate the Moon climbs out of the horizon that is two hundredths of a second of clock
     * time. It is the same four hundredths that separate this from the tracker.
     */
    public function test_the_solved_instant_is_the_instant_the_definition_names(): void
    {
        $place = $this->madrid();
        $horizon = new Horizon($place);
        $day = new DateTimeImmutable('1985-06-10', $place->timeZone());

        foreach ([Body::Sun, Body::Moon, Body::Mars] as $body) {
            $geometric = RiseSet::solvedOfTheDay($body, $place, $day, Limb::Center, false);

            foreach ([$geometric->rise, $geometric->set] as $instant) {
                $this->assertNotNull($instant);
                $this->assertEqualsWithDelta(0.0, $horizon->at($body, $instant->jdUt)->altitude, 1e-4, "{$body->value}: the centre is not on the horizon");
            }

            $almanac = RiseSet::solvedOfTheDay($body, $place, $day, Limb::Superior, true);

            foreach ([$almanac->rise, $almanac->set] as $instant) {
                $this->assertNotNull($instant);

                $topocentric = $horizon->topocentric(Horizon::equatorialOf($body, \Astronomy\Time::tt($instant->jdUt)), $instant->jdUt);
                $expected = -Horizon::refractionOf(0.0) - Horizon::semidiameter($topocentric, Horizon::radiusKm($body));

                $this->assertEqualsWithDelta($expected, $horizon->at($body, $instant->jdUt)->altitude, 1e-4, "{$body->value}: the upper limb is not on the visible horizon");
            }
        }

        // For the Sun those 50 arcminutes are the number the convention is built on: 0.267 of
        // semidiameter because what comes up is its edge, plus 0.567 of refraction because the air
        // lifts the image.
        $sun = RiseSet::solvedOfTheDay(Body::Sun, $place, $day, Limb::Superior, true);

        $this->assertEqualsWithDelta(-0.833, $horizon->at(Body::Sun, $sun->rise->jdUt)->altitude, 0.005);
    }

    /**
     * Forwards and backwards from the same instant give the pass either side of it, and they are
     * consecutive: between the two there is no third.
     *
     * The seam is the case worth pinning, and it is what the Gauquelin sectors land on at every
     * rise. At the instant of a pass, both directions have to return that instant and not one a
     * whole sidereal day away. What makes that hard is that the two sidereal times differ there by
     * however accurate the instant is, four hundredths of a second, and folded into [0, 360) a
     * difference below zero comes back as 359.99: a snap tolerance set to float noise is not
     * enough and this is the test that showed it.
     */
    public function test_a_pass_is_found_on_either_side_and_the_seam_returns_itself(): void
    {
        $place = $this->madrid();
        $jdUt = 2446227.0;

        $before = RiseSet::solvedPass(Body::Mars, $place, $jdUt, Pass::Rise, Limb::Center, false, backwards: true);
        $after = RiseSet::solvedPass(Body::Mars, $place, $jdUt, Pass::Rise, Limb::Center, false);

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertLessThan($jdUt, $before->jdUt);
        $this->assertGreaterThan($jdUt, $after->jdUt);

        // Consecutive: one rise apart, which for a planet is a sidereal day give or take how far
        // it has moved in right ascension.
        $this->assertEqualsWithDelta(0.9973, $after->jdUt - $before->jdUt, 0.01);

        foreach ([$before, $after] as $instant) {
            $itself = RiseSet::solvedPass(Body::Mars, $place, $instant->jdUt, Pass::Rise, Limb::Center, false, backwards: true);
            $forwards = RiseSet::solvedPass(Body::Mars, $place, $instant->jdUt, Pass::Rise, Limb::Center, false);

            // A millionth of a day is 0.086 seconds. Measured over six bodies, four passes,
            // three places and three dates, the worst wobble is 0.045: the Moon, which is what the
            // whole method is accurate to.
            $this->assertEqualsWithDelta($instant->jdUt, $itself->jdUt, 1e-6, 'looking back from a rise did not return that rise');
            $this->assertEqualsWithDelta($instant->jdUt, $forwards->jdUt, 1e-6, 'looking forward from a rise did not return that rise');
        }
    }

    /**
     * A body that neither rises nor sets returns null for both and keeps its two culminations,
     * which always exist. The Sun at Tromsø in midsummer is the case with a name.
     */
    public function test_a_body_that_does_not_cross_the_horizon_has_no_rise_and_no_set(): void
    {
        $tromso = new Place('Tromsø', null, 'Norway', 'NO', 69.65, 18.96, 'Europe/Oslo');
        $midsummer = new DateTimeImmutable('1985-06-21', $tromso->timeZone());

        $solved = RiseSet::solvedOfTheDay(Body::Sun, $tromso, $midsummer);

        $this->assertNull($solved->rise);
        $this->assertNull($solved->set);
        $this->assertNotNull($solved->upperCulmination);
        $this->assertNotNull($solved->lowerCulmination);

        // And the tracker says the same, which is what makes them two roads and not two answers.
        $tracked = RiseSet::ofTheDay(Body::Sun, $tromso, $midsummer);

        $this->assertNull($tracked->rise);
        $this->assertNull($tracked->set);
        $this->assertEqualsWithDelta($tracked->upperCulmination->jdUt, $solved->upperCulmination->jdUt, 1e-5 / 86400 * 1e5);
    }

    /**
     * A civil day with no moonrise. The Moon falls some fifty minutes behind each day, so about
     * one day in twenty five is left without one of its passes, and both roads have to say so
     * rather than reaching into the next day for it.
     *
     * Measured over the seventy days from 1 January 2024 at Madrid: five of them are missing a
     * moonrise or a moonset, and the two roads name the same five.
     */
    public function test_a_day_with_no_moonrise_has_none_by_either_road(): void
    {
        $place = $this->madrid();
        $missing = 0;

        for ($i = 0; $i < 70; $i++) {
            $day = (new DateTimeImmutable('2024-01-01', $place->timeZone()))->modify("+{$i} day");

            $tracked = RiseSet::ofTheDay(Body::Moon, $place, $day);
            $solved = RiseSet::solvedOfTheDay(Body::Moon, $place, $day);

            foreach (['rise', 'set'] as $pass) {
                $this->assertSame($tracked->$pass === null, $solved->$pass === null, $day->format('Y-m-d')." {$pass}");

                if ($tracked->$pass === null) {
                    $missing++;
                }
            }
        }

        $this->assertSame(5, $missing);
    }
}
