<?php

namespace Astronomy;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Checks the engine's own positions against JPL Horizons, live.
 *
 * **A badly computed chart does not look wrong.** The wheel comes out just as pretty with Mars
 * three degrees off, and nobody looking at it is going to notice. So this is not a luxury: it is
 * the only way to know the engine is right, and it has to be repeatable every time anything in
 * the calculation is touched.
 *
 * Horizons is the Jet Propulsion Laboratory's ephemeris system, which is to say the same
 * positions spacecraft are navigated with. Agreeing with that to the arcsecond is agreeing with
 * every astrology program on the market.
 *
 * **It lives in the package because it is the proof of what the package claims.** The number in
 * the README, «verified against JPL Horizons below two tenths of an arcsecond between 1600 and
 * 2400», comes out of here: a library that makes that claim has to ship the thing that
 * demonstrates it, or the claim is a sentence somebody wrote once.
 *
 * It asks over the network, so it is not part of the test suite: the tests fix values that came
 * out of here and run with no internet.
 */
final class HorizonsCheck
{
    /** Horizons' own endpoint. */
    public const HORIZONS = 'https://ssd.jpl.nasa.gov/api/horizons.api';

    /**
     * Compares every body against Horizons and says how far apart they are.
     *
     * @param HttpClient|null $http
     * @param list<Body>|null $bodies By default every one the engine knows.
     * @param string $from First date.
     * @param string $to Last date.
     * @param string $step Between dates, in Horizons' own format.
     * @param float $tolerance Largest acceptable difference, in arcseconds.
     * @return array{rows: list<array{body: string, dates: int, longitude: float, latitude: float,
     *                status: 'ok'|'out'|'absent'|'silent'}>, worst: float, failures: int,
     *                tolerance: float}
     */
    public static function run(
        ?HttpClient $http = null,
        ?array $bodies = null,
        string $from = '1900-01-01',
        string $to = '2050-01-01',
        string $step = '10 y',
        float $tolerance = 1.0,
    ): array {
        $http ??= new NativeHttpClient();
        $bodies ??= Body::all();
        $rows = [];
        $worst = 0.0;
        $failures = 0;

        foreach ($bodies as $body) {
            /* What does not exist cannot be asked of Horizons. The nodes and Lilith are
               constructions on the Moon's orbit, and the eight of the Hamburg school are bodies
               nobody has ever seen: all three cases return a null identifier. Without this way
               out, `Body::from('cupido')` ended up asking Horizons for an empty string, and on
               top of that there is an asteroid 763 Cupido and a 5731 Zeus that have nothing to do
               with any of it and would have answered perfectly naturally. */
            $id = $body->horizonsId();

            if ($id === null) {
                $rows[] = ['body' => $body->value, 'dates' => 0, 'longitude' => 0.0, 'latitude' => 0.0, 'status' => 'absent'];

                continue;
            }

            $reference = self::ask($http, $id, $from, $to, $step);

            if ($reference === null) {
                $rows[] = ['body' => $body->value, 'dates' => 0, 'longitude' => 0.0, 'latitude' => 0.0, 'status' => 'silent'];
                $failures++;

                continue;
            }

            $worstLongitude = 0.0;
            $worstLatitude = 0.0;

            foreach ($reference as [$date, $referenceLongitude, $referenceLatitude]) {
                /* Asked for and compared in Terrestrial Time, without going through delta T.
                   Asking in UT, Horizons converts with ITS delta T and we convert with ours, and
                   outside 1900 to 2050 the comparison measures the difference between the two
                   delta T and not the engine: Ceres gave 11 arcseconds in 1700 in UT and 0.27
                   in TT. */
                $position = Ephemeris::position($body, Time::julianDay($date));

                $worstLongitude = max($worstLongitude, abs(self::apart($position->longitude, $referenceLongitude)) * 3600);
                $worstLatitude = max($worstLatitude, abs($position->latitude - $referenceLatitude) * 3600);
            }

            $worst = max($worst, $worstLongitude, $worstLatitude);
            $within = $worstLongitude <= $tolerance && $worstLatitude <= $tolerance;
            $failures += $within ? 0 : 1;

            $rows[] = [
                'body' => $body->value,
                'dates' => count($reference),
                'longitude' => $worstLongitude,
                'latitude' => $worstLatitude,
                'status' => $within ? 'ok' : 'out',
            ];
        }

        return ['rows' => $rows, 'worst' => $worst, 'failures' => $failures, 'tolerance' => $tolerance];
    }

    /**
     * The apparent ecliptic longitude and latitude Horizons publishes.
     *
     * @param HttpClient $http
     * @param string $id
     * @param string $from
     * @param string $to
     * @param string $step
     * @return list<array{0: DateTimeImmutable, 1: float, 2: float}>|null
     */
    private static function ask(HttpClient $http, string $id, string $from, string $to, string $step): ?array
    {
        $body = $http->get(self::HORIZONS.'?'.http_build_query([
            'format' => 'text',
            'COMMAND' => "'{$id}'",
            'EPHEM_TYPE' => 'OBSERVER',
            /* Geocentric: the centre of the Earth, not an observatory. A birth chart is drawn
               from the centre of the planet and not from its surface, and for the Moon the
               difference reaches a whole degree. */
            'CENTER' => "'500@399'",
            'START_TIME' => "'{$from}'",
            'STOP_TIME' => "'{$to}'",
            'STEP_SIZE' => "'{$step}'",
            'TIME_TYPE' => "'TT'",
            'QUANTITIES' => "'31'",
            'ANG_FORMAT' => 'DEG',
            'CSV_FORMAT' => 'YES',
        ]));

        /* With `strpos` and not with a regular expression: a lazy quantifier over a response of
           more than a megabyte goes past PCRE's backtracking limit and returns false without
           throwing anything. The responses here are short, but the pattern repeats. */
        $start = strpos($body, '$$SOE');
        $end = strpos($body, '$$EOE');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $rows = [];

        foreach (preg_split('/\R/', trim(substr($body, $start + 5, $end - $start - 5))) ?: [] as $line) {
            $fields = array_map('trim', explode(',', $line));

            if (count($fields) < 3) {
                continue;
            }

            $date = DateTimeImmutable::createFromFormat('Y-M-d H:i', $fields[0], new DateTimeZone('UTC'));

            if ($date === false) {
                continue;
            }

            /* The last two numbers of the row, not columns 3 and 4. Horizons interleaves columns
               of empty flags (one for whether the object is lit, another for whether it is above
               the horizon) and how many there are changes with what is asked for. Reading by
               fixed position, one day it returns flags where there were degrees and the
               comparison starts giving hundreds of degrees of difference without anything in the
               calculation having changed. */
            $numbers = array_values(array_filter(
                array_slice($fields, 1),
                static fn (string $field): bool => $field !== '' && is_numeric($field)
            ));

            if (count($numbers) < 2) {
                continue;
            }

            $rows[] = [$date, (float) $numbers[count($numbers) - 2], (float) $numbers[count($numbers) - 1]];
        }

        return $rows ?: null;
    }

    /**
     * How far apart two longitudes are, bearing in mind that 359 and 1 are two degrees apart.
     *
     * @param float $a
     * @param float $b
     * @return float
     */
    private static function apart(float $a, float $b): float
    {
        return fmod($a - $b + 540, 360) - 180;
    }
}
