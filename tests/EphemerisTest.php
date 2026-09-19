<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Sign;
use Astronomy\Time;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Positions against JPL Horizons.
 *
 * The values below are copied by hand from Horizons, so the suite runs with no network: a test
 * that has to reach a server is a test that fails on the day that server is down, and then
 * nobody can tell whether the engine broke or the connection did.
 *
 * They are apparent geocentric ecliptic longitudes and latitudes of the date, in degrees, for
 * 12:00 Universal Time of each date, which is the instant of Terrestrial Time this engine
 * converts it into. The conversion has to happen on this side: handing the date straight in as
 * Terrestrial Time shifts the Moon by the whole of delta T, some thirty arcseconds, which is a
 * hundred times the margin below.
 */
final class EphemerisTest extends TestCase
{
    /** A quarter of an arcsecond, the same margin the application suite uses. */
    private const TOLERANCE = 0.25 / 3600.0;

    /**
     * @return array<string, array{0: Body, 1: string, 2: float, 3: float}>
     */
    public static function positions(): array
    {
        return [
            'Sun 1970' => [Body::Sun, '1970-01-01 12:00:00', 280.6659107, -0.0000160],
            'Sun 2010' => [Body::Sun, '2010-01-01 12:00:00', 280.9605709, -0.0000042],
            'Moon 1970' => [Body::Moon, '1970-01-01 12:00:00', 197.0194903, -2.7752888],
            'Moon 1990' => [Body::Moon, '1990-01-01 12:00:00', 333.2676928, 1.4706887],
            'Mercury 1990' => [Body::Mercury, '1990-01-01 12:00:00', 295.6728108, 0.6303142],
            'Venus 2010' => [Body::Venus, '2010-01-01 12:00:00', 278.4790898, -0.4555498],
            'Mars 1970' => [Body::Mars, '1970-01-01 12:00:00', 342.6091072, -0.8016836],
            'Mars 2010' => [Body::Mars, '2010-01-01 12:00:00', 138.7386057, 3.7778664],
            'Jupiter 1990' => [Body::Jupiter, '1990-01-01 12:00:00', 95.1487783, -0.1169982],
            'Saturn 1970' => [Body::Saturn, '1970-01-01 12:00:00', 32.0598112, -2.5221050],
            'Uranus 2010' => [Body::Uranus, '2010-01-01 12:00:00', 353.1027971, -0.7458502],
            'Neptune 1990' => [Body::Neptune, '1990-01-01 12:00:00', 282.0380932, 0.8473528],
            'Pluto 1970' => [Body::Pluto, '1970-01-01 12:00:00', 177.3916376, 15.8151708],
            'Pluto 2010' => [Body::Pluto, '2010-01-01 12:00:00', 273.3268741, 5.0984998],
            'Chiron 1990' => [Body::Chiron, '1990-01-01 12:00:00', 103.8132474, -7.3055470],
        ];
    }

    #[DataProvider('positions')]
    public function test_the_position_matches_jpl_horizons(Body $body, string $when, float $longitude, float $latitude): void
    {
        /* The date comes in as Universal Time and is converted here, which is how Horizons was
           asked: the values are for that instant of Terrestrial Time. Feeding the date straight
           in as TT shifts the Moon by the whole of delta T, some thirty arcseconds. */
        $jdTT = Time::tt(Time::julianDay(new \DateTimeImmutable($when, new \DateTimeZone('UTC'))));
        $position = Ephemeris::position($body, $jdTT);

        $difference = fmod($position->longitude - $longitude + 540.0, 360.0) - 180.0;

        $this->assertEqualsWithDelta(0.0, $difference, self::TOLERANCE, 'longitude');
        $this->assertEqualsWithDelta($latitude, $position->latitude, 0.12, 'latitude');
    }

    public function test_the_moon_moves_about_thirteen_degrees_a_day(): void
    {
        $speed = Ephemeris::position(Body::Moon, 2451545.0)->speed;

        $this->assertGreaterThan(11.0, $speed);
        $this->assertLessThan(15.5, $speed);
    }

    /**
     * Retrograde motion is carried by the sign of the speed and by nothing else: there is no
     * flag and no special case, which is what makes the applying-aspect computation work with
     * both bodies going backwards. Saturn on this date is moving backwards.
     */
    public function test_a_retrograde_body_carries_a_negative_speed(): void
    {
        $position = Ephemeris::position(Body::Saturn, 2444735.802681786);

        $this->assertLessThan(0.0, $position->speed);
        $this->assertTrue($position->isRetrograde());
    }

    public function test_the_sign_comes_out_of_the_longitude(): void
    {
        $this->assertSame(Sign::Aries, Sign::fromLongitude(0.0));
        $this->assertSame(Sign::Aries, Sign::fromLongitude(29.999));
        $this->assertSame(Sign::Taurus, Sign::fromLongitude(30.0));
        $this->assertSame(Sign::Pisces, Sign::fromLongitude(359.999));
        $this->assertSame(Sign::Aries, Sign::fromLongitude(360.0));
    }

    /**
     * Everything the engine returns is named in English, and there is no locale parameter and no
     * second table inside the class. An application that serves another language translates from
     * the enum value, which is the stable key and the thing that travels in stored data.
     */
    public function test_the_names_are_english_and_the_value_is_the_key(): void
    {
        $this->assertSame('Sun', Body::Sun->name());
        $this->assertSame('sun', Body::Sun->value);
        $this->assertSame('Taurus', Sign::Taurus->name());
        $this->assertSame('fire', Sign::Aries->element());
        $this->assertSame('cardinal', Sign::Aries->modality());
        $this->assertSame('active', Sign::Aries->polarity());
    }

    /**
     * The Earth exists only for the heliocentric frame: asking for it from the Earth is asking
     * where you are from where you are.
     */
    public function test_the_earth_has_no_geocentric_position(): void
    {
        $this->expectException(\LogicException::class);

        Ephemeris::position(Body::Earth, 2451545.0);
    }

    /**
     * The heliocentric frame does have an Earth, and it sits opposite the Sun seen from here.
     */
    public function test_the_heliocentric_earth_is_opposite_the_sun(): void
    {
        $earth = Ephemeris::heliocentric(Body::Earth, 2451545.0)->longitude;
        $sun = Ephemeris::position(Body::Sun, 2451545.0)->longitude;

        $difference = abs(fmod($earth - $sun + 540.0, 360.0) - 180.0);

        $this->assertEqualsWithDelta(180.0, $difference, 0.01);
    }
}
