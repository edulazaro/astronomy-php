<?php

namespace Astronomy\Tests;

use Astronomy\Ayanamsa;
use Astronomy\Crossings;
use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Sign;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Longitude crossings, checked against their own definition.
 *
 * A crossing is "the instant at which this body's longitude is worth exactly this", so the
 * check is direct and needs nobody from outside: the instant is asked for and the longitude is
 * looked at there. It is the same way of verifying the lunar nodes and the houses use.
 *
 * What that route cannot check is that no crossing is lost, because a crossing that is not
 * found cannot be evaluated. That is what the fine sweep test is for, which looks for
 * crossings by brute force with a step a hundred times shorter and demands the same count.
 */
class CrossingsTest extends TestCase
{
    /** A millionth of a degree of longitude, to demand exactness in the checks. */
    private const TOLERANCE_DEGREES = 1e-6;

    /**
     * What is left to the nearest sign boundary.
     *
     * It is not `fmod($longitude, 30)`: a longitude one ten millionth BELOW the boundary gives
     * 29.9999999, which is that ten millionth away from the next sign and thirty degrees away
     * from zero. Comparing the remainder against zero fails the good case half the time.
     *
     * @param float $longitude
     * @return float
     */
    private function toTheSignBoundary(float $longitude): float
    {
        $remainder = fmod(fmod($longitude, 30.0) + 30.0, 30.0);

        return min($remainder, 30.0 - $remainder);
    }

    private function jd(string $date): float
    {
        return Time::tt(Time::julianDay(new DateTimeImmutable($date, new DateTimeZone('UTC'))));
    }

    public function test_the_root_of_a_known_function_comes_out_exact(): void
    {
        // A cosine has its zero at pi over two, and that number is known without calculating
        // anything.
        $root = Crossings::root(fn (float $x): float => cos($x), 1.0, 2.0);

        $this->assertEqualsWithDelta(M_PI / 2.0, $root, 1e-9);
    }

    public function test_the_root_refuses_if_the_bracket_does_not_corner_any_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Crossings::root(fn (float $x): float => $x * $x + 1.0, -1.0, 1.0);
    }

    /**
     * Regula falsi gets stuck when the function is very curved: one end stays put and the
     * other approaches the zero in ever smaller steps. The Illinois correction exists exactly
     * for that, and without it this case stalls halfway.
     */
    public function test_the_root_converges_on_a_function_that_stalls_the_secant(): void
    {
        $root = Crossings::root(fn (float $x): float => $x ** 9 - 1e-9, 0.0, 2.0);

        $this->assertEqualsWithDelta(0.1, $root, 1e-7);
    }

    /**
     * The March equinox is, by definition, when the Sun reaches the zero degree mark: it is
     * not an astronomical figure that has to be copied from anywhere, it is what the word
     * means.
     */
    public function test_the_sun_crosses_zero_degrees_at_the_march_equinox(): void
    {
        $crossing = Crossings::ofTheSun(0.0, $this->jd('2024-03-01 00:00:00'));

        $this->assertNotNull($crossing);

        [$year, $month, $day] = Time::civilDate(Time::ut($crossing));

        $this->assertSame(2024, $year);
        $this->assertSame(3, $month);
        $this->assertSame(20, (int) $day);

        $this->assertEqualsWithDelta(
            0.0,
            fmod(Ephemeris::apparentLongitude(Body::Sun, $crossing) + 180.0, 360.0) - 180.0,
            self::TOLERANCE_DEGREES
        );
    }

    /**
     * @return list<array{0: Body, 1: float}>
     */
    public static function bodiesAndTargets(): array
    {
        return [
            'Moon' => [Body::Moon, 210.0],
            'Mercury' => [Body::Mercury, 300.0],
            'Venus' => [Body::Venus, 45.0],
            'Mars' => [Body::Mars, 123.456],
            'Jupiter' => [Body::Jupiter, 15.0],
            'Saturn' => [Body::Saturn, 270.0],
            'Uranus' => [Body::Uranus, 88.0],
            'Neptune' => [Body::Neptune, 350.0],
            'Pluto' => [Body::Pluto, 200.0],
        ];
    }

    /**     */
    #[DataProvider('bodiesAndTargets')]
    public function test_at_the_instant_of_the_crossing_the_longitude_is_the_one_asked_for(Body $body, float $target): void
    {
        foreach ([1.0, -1.0] as $direction) {
            $crossing = Crossings::ofLongitude($body, $target, $this->jd('1987-03-05 00:00:00'), $direction);

            $this->assertNotNull($crossing, "{$body->value} finds no crossing towards {$direction}");

            $deviation = fmod(Ephemeris::apparentLongitude($body, $crossing) - $target + 540.0, 360.0) - 180.0;

            $this->assertEqualsWithDelta(0.0, $deviation, self::TOLERANCE_DEGREES, $body->value);
        }
    }

    /**
     * The folded deviation jumps from +179 to -179 when passing through the opposite point,
     * and that jump is a sign change that looks just like the crossing. If the sweep took it
     * for the real thing, the crossing would come out **half a zodiac off**, which is a fault
     * that does not show up looking at a date.
     */
    public function test_passing_through_the_opposite_point_is_not_confused_with_a_crossing(): void
    {
        foreach ([Body::Moon, Body::Mercury, Body::Mars, Body::Saturn] as $body) {
            $target = 100.0;
            $crossing = Crossings::ofLongitude($body, $target, $this->jd('2001-07-01 00:00:00'));

            $this->assertNotNull($crossing);

            $longitude = Ephemeris::apparentLongitude($body, $crossing);
            $distanceToTheOpposite = abs(fmod($longitude - ($target + 180.0) + 540.0, 360.0) - 180.0);

            $this->assertGreaterThan(90.0, $distanceToTheOpposite, $body->value.' has landed on the opposite point');
        }
    }

    /**
     * Mars passes through the same degree three times when it retrogrades, and all three are
     * real crossings. A sweep with too long a step swallows the second and third at once,
     * because the two fit inside one step, and returns a single transit where there were
     * three.
     */
    public function test_the_three_passes_of_a_retrograde_are_all_three_found(): void
    {
        /* Degree 300 and no other: in 2018 Mars retrogrades between 262 and 302, so it crosses
           300 three times and 280 only once. The degree is chosen by measuring, because "a
           retrograde passes three times" only holds within the retrogradation's own arc. */
        $start = $this->jd('2018-04-01 00:00:00');
        $target = 300.0;
        $crossings = [];
        $jd = $start;

        while (count($crossings) < 5) {
            $crossing = Crossings::ofLongitude(Body::Mars, $target, $jd, 1.0, $start + 400.0 - $jd);

            if ($crossing === null) {
                break;
            }

            $crossings[] = $crossing;
            $jd = $crossing + 1e-4;
        }

        $this->assertCount(3, $crossings, 'Mars passes through degree 280 three times in 2018');

        foreach ($crossings as $crossing) {
            $deviation = fmod(Ephemeris::apparentLongitude(Body::Mars, $crossing) - $target + 540.0, 360.0) - 180.0;
            $this->assertEqualsWithDelta(0.0, $deviation, self::TOLERANCE_DEGREES);
        }

        // And they are ordered and separated: three events, not the same one found three times.
        $this->assertGreaterThan(20.0, $crossings[1] - $crossings[0]);
        $this->assertGreaterThan(20.0, $crossings[2] - $crossings[1]);
    }

    /**
     * The check the definition cannot do: that none is lost. It is swept with a very short
     * step and the same count the API gives is demanded.
     */
    public function test_the_sweep_does_not_skip_any_crossing(): void
    {
        $cases = [
            [Body::Mercury, 0.05, 200.0],
            [Body::Venus, 0.1, 400.0],
            [Body::Mars, 0.1, 500.0],
            [Body::Jupiter, 0.25, 800.0],
        ];

        foreach ($cases as [$body, $step, $days]) {
            $start = $this->jd('2018-01-01 00:00:00');
            $target = 300.0;

            $reference = 0;
            $previous = fmod(Ephemeris::apparentLongitude($body, $start) - $target + 540.0, 360.0) - 180.0;

            for ($i = 1; $i * $step <= $days; $i++) {
                $value = fmod(
                    Ephemeris::apparentLongitude($body, $start + $i * $step) - $target + 540.0,
                    360.0
                ) - 180.0;

                if (($previous < 0.0) !== ($value < 0.0) && abs($value - $previous) < 180.0) {
                    $reference++;
                }

                $previous = $value;
            }

            $found = 0;
            $jd = $start;

            while ($found < $reference + 2) {
                $crossing = Crossings::ofLongitude($body, $target, $jd, 1.0, $start + $days - $jd);

                if ($crossing === null) {
                    break;
                }

                $found++;
                $jd = $crossing + 1e-4;
            }

            $this->assertSame($reference, $found, $body->value);
        }
    }

    /**
     * A boundary GRAZE is not lost, and this is what the retrogradation arc reasoning did NOT
     * cover.
     *
     * That reasoning, written up in `Crossings`'s docblock, says that with half a degree per
     * step an outbound crossing and a return one cannot fit together because the narrowest
     * retrogradation arc is 2.8 degrees. It is true and it is not enough: **what bounds a pair
     * of crossings is not how much the body retrogrades, but how far it STRAYS from the
     * boundary**, and that can be as little as one likes. Pluto crosses into Pisces in June
     * 2066 and into Cancer in April 2185 straying in by **a minute and a half of arc** and
     * turning back three weeks later. Between those two crossings the body barely moves at
     * all, so the step that comes out of how far it has travelled shoots up and the two fit
     * inside a single one: the sweep gave 62 crossings in Pluto's window where brute force
     * gives 66, raising no error at all and with a perfectly normal looking ingress table.
     *
     * It was uncovered by chance by a refresh of the IERS table: delta T changed by fourteen
     * thousandths of a second in the prediction, the sampling grid shifted by five
     * billionths of a day and the 2185 graze went from being found to being lost. So it was
     * **at the mercy of the grid**, which is the worst way to be wrong.
     *
     * That is why the new rule is one of position and not of speed: near a boundary it looks
     * at most every five days, whether the body is moving fast or not.
     */
    public function test_a_boundary_graze_is_not_lost(): void
    {
        $cases = [
            // Pluto pokes into Aries in 2066 and turns back into Pisces 24 days later.
            [2065, 2067, ['2066-06-17', '2066-07-11', '2067-04-08']],
            // And into Leo in 2185, turning back into Cancer 21 days later.
            [2184, 2186, ['2184-01-09', '2184-07-06', '2185-04-01', '2185-04-22']],
        ];

        foreach ($cases as [$firstYear, $lastYear, $expected]) {
            $from = Time::civilJulianDay($firstYear, 1, 1.0);
            $until = Time::civilJulianDay($lastYear, 7, 1.0);

            $ingresses = Crossings::ingresses(Body::Pluto, $from, $until);

            $dates = array_map(
                fn (array $i): string => (new DateTimeImmutable('@'.(int) (($i['jd'] - 2440587.5) * 86400)))
                    ->format('Y-m-d'),
                $ingresses
            );

            $this->assertSame($expected, $dates, 'Pluto\'s crossings between '.$firstYear.' and '.$lastYear);

            // And the same count as brute force, which is what the definition cannot see.
            $this->assertCount(
                $this->boundaryCrossingsByBruteForce(Body::Pluto, $from, $until, 0.5),
                $ingresses
            );
        }
    }

    /**
     * And what turns those two cases into GRAZES and not ordinary ingresses: how little the
     * body strays from the boundary while it is on the other side. It is fixed here because
     * it is the number that explains why the margin in degrees does not catch them, and
     * because if someone raises the step someday looking only at the retrogradation arc, this
     * test says why not.
     */
    public function test_plutos_two_grazes_are_a_minute_and_a_half_of_arc(): void
    {
        $cases = [
            ['2066-06-20 00:00:00', '2066-07-09 00:00:00', 360.0, 1.6],
            ['2185-04-03 00:00:00', '2185-04-20 00:00:00', 120.0, 1.4],
        ];

        foreach ($cases as [$from, $until, $boundary, $minutes]) {
            $start = $this->jd($from);
            $end = $this->jd($until);
            $deepest = 0.0;

            for ($jd = $start; $jd <= $end; $jd += 0.25) {
                $longitude = Ephemeris::apparentLongitude(Body::Pluto, $jd);
                $deepest = max($deepest, abs(fmod($boundary - $longitude + 540.0, 360.0) - 180.0));
            }

            $this->assertEqualsWithDelta($minutes, $deepest * 60.0, 0.1, $from);
        }
    }

    /**
     * How many times a body changes tens in a window, sweeping at a fixed step. It is the
     * count's second opinion, and that is why it looks at the tens and does not call
     * `Crossings`.
     *
     * @param Body $body
     * @param float $from
     * @param float $until
     * @param float $step
     * @return int
     */
    private function boundaryCrossingsByBruteForce(Body $body, float $from, float $until, float $step): int
    {
        $crossings = 0;
        $previous = (int) floor(Ephemeris::apparentLongitude($body, $from) / 30.0);

        for ($jd = $from + $step; $jd <= $until; $jd += $step) {
            $ten = (int) floor(Ephemeris::apparentLongitude($body, $jd) / 30.0);

            if ($ten !== $previous) {
                $crossings++;
            }

            $previous = $ten;
        }

        return $crossings;
    }

    /**
     * The Moon's pass through its node is not a longitude crossing but a LATITUDE one: what is
     * worth zero is its distance to the ecliptic. And that instant's longitude has to be the
     * true node's, which is exactly its definition.
     */
    public function test_the_moon_crosses_the_ecliptic_at_its_node_at_the_true_node(): void
    {
        $pass = Crossings::throughLunarNode($this->jd('2005-09-01 00:00:00'));

        $this->assertNotNull($pass);
        $this->assertEqualsWithDelta(0.0, Ephemeris::position(Body::Moon, $pass['jd'])->latitude, 1e-7);

        /* Against the true node there is about four arcseconds left, and it is not either
           one's fault: the true node is the OSCULATING orbit's, taken from `r × v` on the
           geometric position, and this is where the APPARENT Moon crosses the ecliptic, with
           its light time on top. They are two definitions that almost agree, and the "almost"
           is worth that much. */
        $node = Ephemeris::apparentLongitude(Body::TrueNode, $pass['jd']);
        $reference = $pass['north'] ? $node : $node + 180.0;

        $this->assertEqualsWithDelta(
            0.0,
            fmod($pass['longitude'] - $reference + 540.0, 360.0) - 180.0,
            0.01
        );
    }

    public function test_the_twelve_ingresses_of_the_sun_in_a_year_come_out_in_order(): void
    {
        $from = $this->jd('2020-01-01 00:00:00');
        $ingresses = Crossings::ingresses(Body::Sun, $from, $from + 365.0);

        $this->assertCount(12, $ingresses);

        $previous = $from;

        foreach ($ingresses as $index => $ingress) {
            $this->assertGreaterThan($previous, $ingress['jd']);
            $previous = $ingress['jd'];

            // The Sun never retrogrades, so none of them can come out marked as such.
            $this->assertFalse($ingress['retrograde']);

            // And at every instant the Sun is at the zero degree of the sign being announced.
            $longitude = Ephemeris::apparentLongitude(Body::Sun, $ingress['jd']);
            $this->assertSame($ingress['sign'], Sign::fromLongitude($longitude + 1e-7));
            $this->assertEqualsWithDelta(0.0, $this->toTheSignBoundary($longitude), self::TOLERANCE_DEGREES);
        }

        // It starts in Aquarius: the Sun enters there in late January.
        $this->assertSame(Sign::Aquarius, $ingresses[0]['sign']);
    }

    /**
     * A retrograde body enters and leaves the same sign three times, and the exits are
     * marked: without that, a list of ingresses would count an exit as an entry.
     */
    public function test_a_backward_ingress_is_marked_as_retrograde(): void
    {
        $from = $this->jd('2018-01-01 00:00:00');
        $ingresses = Crossings::ingresses(Body::Mars, $from, $from + 730.0);

        $retrogrades = array_filter($ingresses, fn (array $i): bool => $i['retrograde']);

        $this->assertNotEmpty($retrogrades, 'Mars retrogrades in 2018 and crosses a sign boundary');

        foreach ($ingresses as $ingress) {
            $longitude = Ephemeris::apparentLongitude(Body::Mars, $ingress['jd']);
            $this->assertEqualsWithDelta(0.0, $this->toTheSignBoundary($longitude), self::TOLERANCE_DEGREES);
        }
    }

    /**
     * An ingress is named after the sign being ENTERED, and that is not the same as the
     * boundary being crossed.
     *
     * Going backwards, a planet leaves its sign from the bottom: it crosses the zero degree of
     * the sign it WAS in to get into the previous one. Naming the ingress after that
     * boundary's sign, Pluto retrograding from Scorpio into Libra said "enters Scorpio", which
     * is where it was leaving from. Its three crossings of 1983 and 1984 all came out as
     * Scorpio, and it raised no error at all: it gave a sign name that looked just fine.
     *
     * It is caught by asking `Sign` for the sign of where the body is right AFTER the
     * crossing, which knows nothing about boundaries or directions.
     */
    public function test_an_ingress_is_named_after_the_sign_being_entered(): void
    {
        foreach ([Body::Pluto, Body::Mars, Body::Jupiter, Body::Saturn] as $body) {
            $from = $this->jd('1983-01-01 00:00:00');
            $ingresses = Crossings::ingresses($body, $from, $from + 3000.0);

            $this->assertNotEmpty($ingresses, $body->value);

            $retrogrades = 0;

            foreach ($ingresses as $ingress) {
                /* The longitude is looked at a hair PAST the boundary in the direction of
                   travel, and not a while later. Moving the clock forward does not work:
                   Pluto crosses its 1984 boundary two weeks after its station, moving so
                   slowly that half a day later it still has not pulled away from zero degrees,
                   and the test would fail with nothing actually wrong. */
                $longitude = Ephemeris::apparentLongitude($body, $ingress['jd']);
                $aHair = $ingress['retrograde'] ? -0.01 : 0.01;

                $this->assertSame(
                    $ingress['sign'],
                    Sign::fromLongitude(fmod($longitude + $aHair + 360.0, 360.0)),
                    $body->value.' says it enters '.$ingress['sign']->name()
                );

                $retrogrades += $ingress['retrograde'] ? 1 : 0;
            }

            $this->assertGreaterThan(0, $retrogrades, $body->value.' has no retrograde ingress in the window');
        }
    }

    /**
     * With the sidereal zodiac the crossing is on the sidereal longitude, and the ayanamsa is
     * evaluated at the instant of the crossing and not at the starting one: with the starting
     * one, an ingress a year out would be off by the fifty arcseconds the ayanamsa moves in a
     * year.
     */
    public function test_a_sidereal_ingress_lands_where_the_sidereal_longitude_is_the_one_asked_for(): void
    {
        $ayanamsa = Ayanamsa::Lahiri;
        $from = $this->jd('2020-01-01 00:00:00');

        $crossing = Crossings::ingressInto(Body::Sun, Sign::Aries, $from, 1.0, $ayanamsa);

        $this->assertNotNull($crossing);

        $sidereal = fmod(
            Ephemeris::apparentLongitude(Body::Sun, $crossing) - $ayanamsa->value($crossing) + 720.0,
            360.0
        );

        $this->assertEqualsWithDelta(0.0, fmod($sidereal + 180.0, 360.0) - 180.0, self::TOLERANCE_DEGREES);

        // And it falls quite a few days after the tropical equinox, which is what separates
        // the two zodiacs: the ayanamsa is worth about twenty four degrees today.
        $tropical = Crossings::ofTheSun(0.0, $from);
        $this->assertGreaterThan(20.0, $crossing - $tropical);
    }

    public function test_the_heliocentric_crossing_lands_where_the_heliocentric_longitude_is_the_one_asked_for(): void
    {
        foreach ([Body::Mars, Body::Jupiter, Body::Earth] as $body) {
            $crossing = Crossings::heliocentric($body, 200.0, $this->jd('2010-01-01 00:00:00'));

            $this->assertNotNull($crossing, $body->value);

            $deviation = fmod(Ephemeris::heliocentric($body, $crossing)->longitude - 200.0 + 540.0, 360.0) - 180.0;

            $this->assertEqualsWithDelta(0.0, $deviation, self::TOLERANCE_DEGREES, $body->value);
        }
    }

    /**
     * Searching backwards has to give the previous crossing and not the next one, and the
     * starting instant counts as previous: it is the same rule the lunations already
     * followed.
     */
    public function test_searching_backwards_returns_the_previous_crossing(): void
    {
        $jd = $this->jd('2015-08-15 00:00:00');

        $backwards = Crossings::ofLongitude(Body::Sun, 90.0, $jd, -1.0);
        $forwards = Crossings::ofLongitude(Body::Sun, 90.0, $jd, 1.0);

        $this->assertNotNull($backwards);
        $this->assertNotNull($forwards);
        $this->assertLessThan($jd, $backwards);
        $this->assertGreaterThan($jd, $forwards);

        // They are two consecutive turns of the Sun, that is, exactly a year apart.
        $this->assertEqualsWithDelta(365.25, $forwards - $backwards, 1.0);
    }

    public function test_outside_the_window_null_is_returned_instead_of_a_made_up_number(): void
    {
        // Pluto does not reach degree 200 in a week: there is no crossing and it says so.
        $this->assertNull(
            Crossings::ofLongitude(Body::Pluto, 200.0, $this->jd('1900-01-01 00:00:00'), 1.0, 7.0)
        );
    }
}
