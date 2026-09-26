<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Sign;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The ephemeris engine, against real JPL positions.
 *
 * These figures do NOT come from our own code: they are what JPL Horizons returns, the
 * ephemeris system used to navigate space probes, and they are copied here by hand so the test
 * runs with no network. Comparing something against itself proves nothing; this proves that the
 * engine matches the industry reference.
 *
 * To regenerate them or check more dates: `php artisan astro:verificar`, which asks Horizons
 * live.
 *
 * The tolerance used to be two arcseconds and is now **four tenths**, because the analytic
 * series carry a correction table towards DE440
 * (`resources/astro/correccion/*.bin`, see `PlanetCorrectionTest`). Measured on these same
 * fifteen dates, the worst error of each body went from around a second to this:
 *
 * | | | | |
 * |---|---|---|---|
 * | Sun 0.141″ | Moon 0.174″ | Mercury 0.141″ | Venus 0.106″ |
 * | Mars 0.144″ | Jupiter 0.133″ | Saturn 0.197″ | Uranus 0.121″ |
 * | Neptune 0.357″ | Pluto 0.146″ | Chiron 0.069″ | Pholus and the asteroids ≤ 0.082″ |
 *
 * **Why four tenths and not five hundredths**, which is what the table gives on its own. What
 * is left is not from the ephemerides and is measured body by body in
 * `PlanetCorrectionTest`: a full tenth of difference between our ecliptic of the date and
 * Horizons's (we use the 1976 precession, which is the one VSOP87 and the JPL tables use), nine
 * hundredths from the FK5 correction the engine keeps applying to planets that are already JPL
 * positions, and the gravitational light deflection, which Horizons carries and the engine does
 * not: it is thousandths except near conjunction with the Sun, where it reaches a second.
 * Neptune on 1990 January 1 was 1.2 degrees from the Sun, and that is why it is the worst of the
 * list.
 *
 * To get a feel for what a tenth of an arcsecond is: a birth chart is read in degrees and
 * minutes, and a minute is sixty seconds.
 */
class JplPositionsTest extends TestCase
{
    /**
     * The margin, in arcseconds, the same for the fifteen bodies on purpose.
     *
     * It comes from measuring, and it has come down three times: it was 2.0 while the analytic
     * series ran on their own, 0.4 once they were corrected towards the JPL, and this since the
     * engine bends light with the Sun's gravity, which was what left a planet stuck to the Sun
     * nine times worse than the rest. Worst real case: the Moon with 0.174 in 1990, and
     * Pluto's latitude with 0.083.
     */
    private const TOLERANCE = 0.25;

    /** The same for the latitude, which always comes out better: the frame's rotation does not touch it. */
    private const LATITUDE_TOLERANCE = 0.12;

    /**
     * Apparent ecliptic longitude and latitude per JPL Horizons, geocentric.
     *
     * @return array<string, list<array{string, float, float}>>
     */
    public static function jplPositions(): array
    {
        return [
        'sun' => [
            ['1970-01-01 12:00:00', 280.6659107, -0.0000160],
            ['1990-01-01 12:00:00', 280.8142513, 0.0000235],
            ['2010-01-01 12:00:00', 280.9605709, -0.0000042],
        ],
        'moon' => [
            ['1970-01-01 12:00:00', 197.0194903, -2.7752888],
            ['1990-01-01 12:00:00', 333.2676928, 1.4706887],
            ['2010-01-01 12:00:00', 110.7738207, 0.0282694],
        ],
        'mercury' => [
            ['1970-01-01 12:00:00', 299.2841647, -0.3849994],
            ['1990-01-01 12:00:00', 295.6728108, 0.6303142],
            ['2010-01-01 12:00:00', 288.4689185, 1.7934625],
        ],
        'venus' => [
            ['1970-01-01 12:00:00', 275.0824166, -0.2802567],
            ['1990-01-01 12:00:00', 306.2219469, 1.9011725],
            ['2010-01-01 12:00:00', 278.4790898, -0.4555498],
        ],
        'mars' => [
            ['1970-01-01 12:00:00', 342.6091072, -0.8016836],
            ['1990-01-01 12:00:00', 250.0000748, -0.0329299],
            ['2010-01-01 12:00:00', 138.7386057, 3.7778664],
        ],
        'jupiter' => [
            ['1970-01-01 12:00:00', 212.3931247, 1.2106549],
            ['1990-01-01 12:00:00', 95.1487783, -0.1169982],
            ['2010-01-01 12:00:00', 326.4601571, -0.9341904],
        ],
        'saturn' => [
            ['1970-01-01 12:00:00', 32.0598112, -2.5221050],
            ['1990-01-01 12:00:00', 285.6574897, 0.2933231],
            ['2010-01-01 12:00:00', 184.5178423, 2.2902029],
        ],
        'uranus' => [
            ['1970-01-01 12:00:00', 188.7263056, 0.7199837],
            ['1990-01-01 12:00:00', 275.7854261, -0.2700914],
            ['2010-01-01 12:00:00', 353.1027971, -0.7458502],
        ],
        'neptune' => [
            ['1970-01-01 12:00:00', 239.9010371, 1.6526704],
            ['1990-01-01 12:00:00', 282.0380932, 0.8473528],
            ['2010-01-01 12:00:00', 324.5967848, -0.4182671],
        ],
        'pluto' => [
            ['1970-01-01 12:00:00', 177.3916376, 15.8151708],
            ['1990-01-01 12:00:00', 227.0931080, 15.2844183],
            ['2010-01-01 12:00:00', 273.3268741, 5.0984998],
        ],
        'chiron' => [
            ['1970-01-01 12:00:00', 2.5286620, 2.7598608],
            ['1990-01-01 12:00:00', 103.8132474, -7.3055470],
            ['2010-01-01 12:00:00', 323.1437301, 5.9684964],
        ],
        /* Pholus, the other centaur, by the same tabulated path as Chiron. Its table reaches
           just as far, from 1600 to 2400, even though its uncertainty as declared by the JPL is
           much worse than Chiron's: 14.1 arcseconds in right ascension in 1600 and 4.4 in 2400,
           against Chiron's 0.93 and 0.40. It was discovered in 1992, fifteen years later, and on
           top of that it is a centaur that crosses the orbits of the giants, so its orbit going
           backwards is chaotic. Here, within the twentieth century, that uncertainty is two
           tenths and what is being measured is the engine. */
        'pholus' => [
            ['1970-01-01 12:00:00', 326.0576484, -12.1215382],
            ['1990-01-01 12:00:00', 85.3409827, -15.3505118],
            ['2010-01-01 12:00:00', 253.6773030, 17.9146672],
        ],
        'ceres' => [
            ['1970-01-01 12:00:00', 321.2982637, -8.2904916],
            ['1990-01-01 12:00:00', 85.5537367, 3.0914530],
            ['2010-01-01 12:00:00', 244.7624566, 3.9756999],
        ],
        'pallas' => [
            ['1970-01-01 12:00:00', 298.2463081, 21.9890371],
            ['1990-01-01 12:00:00', 1.5595868, -20.1160252],
            ['2010-01-01 12:00:00', 217.9580950, 13.8862188],
        ],
        'juno' => [
            ['1970-01-01 12:00:00', 304.8854964, 5.9167592],
            ['1990-01-01 12:00:00', 223.9917108, 7.2405889],
            ['2010-01-01 12:00:00', 4.6266063, -9.7810766],
        ],
        'vesta' => [
            ['1970-01-01 12:00:00', 146.0896311, 4.5726883],
            ['1990-01-01 12:00:00', 317.4428510, -4.0863089],
            ['2010-01-01 12:00:00', 156.6387114, 5.4608255],
        ],
        ];
    }

    public function test_the_positions_match_the_jpl(): void
    {
        foreach (self::jplPositions() as $name => $cases) {
            $body = Body::from($name);

            foreach ($cases as [$date, $expectedLongitude, $expectedLatitude]) {
                $instant = new DateTimeImmutable($date, new DateTimeZone('UTC'));
                $position = Ephemeris::position($body, Time::tt(Time::julianDay($instant)));

                // Subtracting longitudes with no more care fails between 359 and 1 degree,
                // which are two degrees apart and not three hundred and fifty eight.
                $longitudeError = abs(fmod($position->longitude - $expectedLongitude + 540, 360) - 180) * 3600;
                $latitudeError = abs($position->latitude - $expectedLatitude) * 3600;

                $this->assertLessThan(
                    self::TOLERANCE,
                    $longitudeError,
                    sprintf('%s on %s: longitude %.6f, the JPL says %.6f (%.2f arcseconds)',
                        $body->name(), $date, $position->longitude, $expectedLongitude, $longitudeError)
                );

                $this->assertLessThan(
                    self::LATITUDE_TOLERANCE,
                    $latitudeError,
                    sprintf('%s on %s: latitude off by %.2f arcseconds',
                        $body->name(), $date, $latitudeError)
                );
            }
        }
    }

    /**
     * Planets are computed in Terrestrial Time and houses in Universal Time. Between the two
     * scales there are today about seventy seconds, and confusing them is the classic mistake in
     * a birth chart: it shifts the Moon by almost an arcminute and the ascendant by a similar
     * amount.
     */
    /**
     * Chiron and the asteroids have no analytic theory: their position comes from a table
     * requested from the JPL and interpolated between points. It is a different path from the
     * planets', and it is worth stating that precision does not suffer for it.
     */
    public function test_tabulated_bodies_take_their_own_path_and_are_just_as_accurate(): void
    {
        foreach (Body::asteroids() as $body) {
            $this->assertTrue($body->isTabulated(), $body->name().' should come from a table');
            $this->assertNotNull($body->horizonsId());
        }

        // Pluto too, and the planets not.
        $this->assertTrue(Body::Pluto->isTabulated());
        $this->assertFalse(Body::Mars->isTabulated());
        $this->assertFalse(Body::Moon->isTabulated());

        // The sixteen bodies have a position on any date within the range.
        $jd = Time::tt(Time::julianDay(new DateTimeImmutable('1985-06-15 12:00', new DateTimeZone('UTC'))));

        foreach (Body::all() as $body) {
            $position = Ephemeris::position($body, $jd);

            $this->assertGreaterThanOrEqual(0.0, $position->longitude, $body->name());
            $this->assertLessThan(360.0, $position->longitude, $body->name());
        }
    }

    /**
     * Outside the tabulated range there is no position, and that has to hurt instead of
     * returning a number: extrapolating an orbit fitted by observation is inventing where the
     * body was.
     */
    public function test_outside_the_tables_range_it_warns(): void
    {
        $outside = Time::tt(Time::julianDay(new DateTimeImmutable('1500-01-01', new DateTimeZone('UTC'))));

        $this->expectException(\RuntimeException::class);

        Ephemeris::position(Body::Chiron, $outside);
    }

    public function test_delta_t_has_known_values(): void
    {
        $known = [
            // Year, observed delta T in seconds, margin given to it.
            [1900, -2.7, 1.0],
            [1950, 29.1, 1.0],
            [2000, 63.8, 1.0],
        ];

        foreach ($known as [$year, $expected, $margin]) {
            $jd = Time::julianDay(new DateTimeImmutable("{$year}-01-01 12:00", new DateTimeZone('UTC')));

            $this->assertEqualsWithDelta($expected, Time::deltaT($jd), $margin, "delta T in {$year}");
        }
    }

    public function test_the_julian_day_is_the_reference_one(): void
    {
        // J2000.0: noon on 2000 January 1. It is the date everything else is counted from, so
        // if this fails, the whole engine fails.
        $j2000 = new DateTimeImmutable('2000-01-01 12:00:00', new DateTimeZone('UTC'));

        $this->assertEqualsWithDelta(2451545.0, Time::julianDay($j2000), 1e-6);

        // And a date before the Gregorian reform, which follows a different leap-year count.
        $julian = new DateTimeImmutable('1500-01-01 00:00:00', new DateTimeZone('UTC'));

        $this->assertEqualsWithDelta(2268923.5, Time::julianDay($julian), 1e-6);
    }

    public function test_retrogrades_are_detected(): void
    {
        // Mercury retrograde from 2021 January 30 to February 21, one of the best known
        // stretches there is.
        $inside = new DateTimeImmutable('2021-02-10 12:00', new DateTimeZone('UTC'));
        $outside = new DateTimeImmutable('2021-03-15 12:00', new DateTimeZone('UTC'));

        $this->assertTrue(
            Ephemeris::position(Body::Mercury, Time::tt(Time::julianDay($inside)))->isRetrograde(),
            'Mercury was retrograde on 2021 February 10'
        );

        $this->assertFalse(
            Ephemeris::position(Body::Mercury, Time::tt(Time::julianDay($outside)))->isRetrograde(),
            'Mercury was already direct on 2021 March 15'
        );

        // The Sun and the Moon never retrograde: it is the Earth that overtakes the planets
        // from the inside, and with these two that cannot happen.
        foreach ([Body::Sun, Body::Moon] as $body) {
            $this->assertFalse(
                Ephemeris::position($body, Time::tt(Time::julianDay($inside)))->isRetrograde(),
                $body->name().' cannot go retrograde'
            );
        }
    }

    public function test_the_sign_comes_out_of_the_longitude(): void
    {
        $this->assertSame(Sign::Aries, Sign::fromLongitude(0.0));
        $this->assertSame(Sign::Aries, Sign::fromLongitude(29.99));
        $this->assertSame(Sign::Taurus, Sign::fromLongitude(30.0));
        $this->assertSame(Sign::Pisces, Sign::fromLongitude(359.99));

        // And the full turn: 360 is Aries again, not a thirteenth sign.
        $this->assertSame(Sign::Aries, Sign::fromLongitude(360.0));
        $this->assertSame(Sign::Pisces, Sign::fromLongitude(-1.0));
    }

    public function test_the_position_is_written_as_in_a_chart(): void
    {
        // On 2010 January 1 the Sun was a bit past 10 degrees of Capricorn.
        $instant = new DateTimeImmutable('2010-01-01 12:00', new DateTimeZone('UTC'));
        $sun = Ephemeris::position(Body::Sun, Time::tt(Time::julianDay($instant)));

        $this->assertSame(Sign::Capricorn, $sun->sign());
        $this->assertStringContainsString('Capricorn', $sun->formatted());
        $this->assertMatchesRegularExpression("/^\\d+° \\d{2}' \\d{2}\"/u", $sun->formatted());
    }
}
