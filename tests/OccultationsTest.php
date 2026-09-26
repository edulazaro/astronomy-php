<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Equatorial;
use Astronomy\Horizon;
use Astronomy\Place;
use Astronomy\Occultations;
use Astronomy\Time;
use Astronomy\EclipseType;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Occultations of planets by the Moon, against `swe_lun_occult_when_glob` and `_loc`.
 *
 * The figures are from Swiss Ephemeris 2.10.03 with Moshier, in UT. The four most talked
 * about bright-planet occultations of the decade are checked: Mars in December 2022, Jupiter
 * in May 2023, Venus in November 2023 and Saturn in August 2024. And two of them as seen from a
 * place they were actually visible from: Mars and Saturn from Madrid, Jupiter from New York.
 */
class OccultationsTest extends TestCase
{
    /** Margin on the instants, in seconds. */
    private const TOLERANCE = 60.0;

    /**
     * [body, from, maximum, start and end somewhere on Earth] per Swiss. The four are total and
     * central.
     */
    private const GLOBAL = [
        ['mars', '2022-12-01', 2459921.6765433196, 2459921.5948153106, 2459921.7581259753],
        ['venus', '2023-11-01', 2460257.9405429205, 2460257.8731547315, 2460258.0083975303],
        ['saturn', '2024-08-01', 2460543.612229999, 2460543.5308649745, 2460543.6933859773],
        ['jupiter', '2023-05-01', 2460082.027795461, 2460081.9528388595, 2460082.102378842],
    ];

    /** [body, place, maximum, contacts 1 through 4, the planet's altitude] per Swiss. */
    private const LOCAL = [
        ['mars', 'Madrid', 2459921.7393816602, 2459921.7229781975, 2459921.723534508, 2459921.7546822084, 2459921.755200365, 19.655451117213186],
        ['saturn', 'Madrid', 2460543.661080895, 2460543.6374589726, 2460543.6379186446, 2460543.683563045, 2460543.683996574, 32.689435572084044],
        ['jupiter', 'New York', 2460082.0100382464, 2460081.98597526, 2460081.98680685, 2460082.0342246974, 2460082.0351257534, 40.99781080682582],
    ];

    private const PLACES = [
        'Madrid' => [40.4168, -3.7038, 'Europe/Madrid'],
        'New York' => [40.7128, -74.006, 'America/New_York'],
    ];

    private static function jd(string $date): float
    {
        return Time::julianDay(new DateTimeImmutable($date, new DateTimeZone('UTC')));
    }

    private function place(string $name): Place
    {
        [$latitude, $longitude, $timezone] = self::PLACES[$name];

        return new Place($name, null, '', '', $latitude, $longitude, $timezone);
    }

    private function seconds(float $jdA, float $jdB): float
    {
        return abs($jdA - $jdB) * 86400.0;
    }

    public function test_the_global_occultations_match_swiss(): void
    {
        foreach (self::GLOBAL as [$body, $from, $maximum, $start, $end]) {
            $occultation = Occultations::next(Body::from($body), self::jd($from));

            $this->assertNotNull($occultation, "Occultation of {$body} from {$from}");
            $this->assertSame(EclipseType::Total, $occultation->type, $body);
            $this->assertTrue($occultation->central, $body);
            $this->assertLessThan(self::TOLERANCE, $this->seconds($occultation->maximum->jdUt, $maximum), "Maximum of {$body}");
            $this->assertLessThan(self::TOLERANCE, $this->seconds($occultation->start->jdUt, $start), "Start of {$body}");
            $this->assertLessThan(self::TOLERANCE, $this->seconds($occultation->end->jdUt, $end), "End of {$body}");

            // Central means the axis passes through the surface: gamma less than one.
            $this->assertLessThan(1.0, abs($occultation->gamma));

            // And the minimum geocentric separation is less than the Moon's parallax plus its
            // semi-diameter, which is the condition for it to be seen from somewhere.
            $this->assertLessThan(1.3, $occultation->minimumSeparation);
        }
    }

    public function test_the_local_occultations_match_swiss(): void
    {
        foreach (self::LOCAL as [$body, $name, $maximum, $c1, $c2, $c3, $c4, $height]) {
            $occultation = Occultations::next(Body::from($body), $maximum - 15.0);
            $local = Occultations::local($occultation, $this->place($name));

            $this->assertNotNull($local, "{$body} from {$name}");
            $this->assertSame(EclipseType::Total, $local->type);
            $this->assertTrue($local->isVisible());

            foreach (['maximum' => $maximum, 'contact1' => $c1, 'contact2' => $c2, 'contact3' => $c3, 'contact4' => $c4] as $contact => $expected) {
                $this->assertLessThan(self::TOLERANCE, $this->seconds($local->$contact->jdUt, $expected), "{$contact} of {$body} from {$name}");
            }

            $this->assertEqualsWithDelta($height, $local->altitude, 0.05);
            $this->assertSame(1.0, $local->magnitude);

            // A planet takes less than a minute to vanish behind the limb.
            $this->assertLessThan(90.0, $local->contact1->secondsTo($local->contact2));
            $this->assertLessThan(90.0, $local->contact3->secondsTo($local->contact4));
        }
    }

    /**
     * The Jupiter one from May 2023 happened by day in America and by night in Europe: seen
     * from New York, and from Madrid the Moon is below the horizon, so null.
     */
    public function test_from_where_it_cannot_be_seen_there_are_no_circumstances(): void
    {
        $occultation = Occultations::next(Body::Jupiter, self::jd('2023-05-01'));

        $this->assertNotNull(Occultations::local($occultation, $this->place('New York')));
        $this->assertNull(Occultations::local($occultation, $this->place('Madrid')));
    }

    public function test_between_two_dates_the_ones_there_are_come_out_in_order(): void
    {
        // In the second half of 2024 the Moon occulted Saturn every month from somewhere on
        // Earth: these are the ones Swiss chains starting on August 21.
        $saturn = Occultations::between(Body::Saturn, self::jd('2024-08-01'), self::jd('2024-12-31'));

        $this->assertGreaterThanOrEqual(4, count($saturn));
        $this->assertSame('2024-08-21', $saturn[0]->maximum->date->format('Y-m-d'));

        for ($i = 1; $i < count($saturn); $i++) {
            $this->assertGreaterThan($saturn[$i - 1]->maximum->jdUt + 20.0, $saturn[$i]->maximum->jdUt);
        }

        // And where there are none, an empty list and not an error: Mars in the first three
        // months of 2024 passed far from the Moon.
        $this->assertSame([], Occultations::between(Body::Mars, self::jd('2024-01-01'), self::jd('2024-03-31')));
    }

    /**
     * An arbitrary direction gets occulted the same way. With no Swiss for stars (it needs its
     * star file), it is checked against the definition: if the direction is the one the Moon
     * had at some instant, the occultation has to be right there, be central and have zero
     * separation.
     */
    public function test_an_arbitrary_direction_gets_occulted(): void
    {
        $jdUt = self::jd('2024-03-20 12:00:00');
        $moon = Horizon::equatorialOf(Body::Moon, Time::tt($jdUt));
        $star = fn (float $jdTT): Equatorial => Equatorial::direction($moon->rightAscension, $moon->declination);

        $occultation = Occultations::next($star, $jdUt - 10.0);

        $this->assertNotNull($occultation);
        $this->assertSame('star', $occultation->name);
        $this->assertTrue($occultation->central);
        $this->assertLessThan(self::TOLERANCE, $this->seconds($occultation->maximum->jdUt, $jdUt));
        $this->assertLessThan(1 / 3600, $occultation->minimumSeparation);
        $this->assertLessThan(0.01, abs($occultation->gamma));

        // A star has no disk: it vanishes all at once, so the first contact and the second are
        // the same instant.
        $local = Occultations::local($occultation, $this->place('Madrid'));

        if ($local !== null) {
            $this->assertLessThan(1.0, $local->contact1->secondsTo($local->contact2));
            $this->assertSame(1.0, $local->magnitude);
            $this->assertSame(0.0, $local->diameterRatio);
        }
    }
}
