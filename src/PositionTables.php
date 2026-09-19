<?php

namespace Astronomy;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Tabulates a body's positions by asking JPL Horizons for them.
 *
 * It is the way in for everything with no published analytical theory. Pluto is not in VSOP87
 * because it is not one of the planets whose motion they solved, and Chiron and the asteroids
 * are in no theory at all: they are small bodies whose orbit is known only through numerical
 * integration. For those, either you tabulate them or you do not have them.
 *
 * **HELIOCENTRIC coordinates are stored, not what is seen from the Earth.** Seen from the Sun the
 * orbits are smooth and very nearly straight from one month to the next, so one point every thirty
 * days is enough to interpolate with no appreciable error. Seen from the Earth they carry the
 * retrograde loops our own motion lays on top of them, and reproducing those would need a far finer
 * sampling. And tabulating heliocentrically, the body comes in through the same door as the VSOP87
 * planets and gets light time, aberration and nutation applied exactly like the rest.
 *
 * The JPL's data are public and a position is a fact, not a work. This is what makes it possible to
 * have Pluto without licensing Swiss Ephemeris.
 *
 * **The range runs from 1600 to 2400**, 9740 points and some 580 KB per body. It used to run from
 * 1800 to 2150, and widening it took two checks that are worth not doing again:
 *
 * - Horizons was asked for its OWN uncertainty (`QUANTITIES='36'`, sigma in right ascension and in
 *   declination) at both ends. Chiron: 0.93 and 0.23 arcseconds in 1600, 0.40 and 0.10 in 2400. For
 *   the asteroids it is smaller. All of it well below the arcminute that would have forced starting
 *   them later.
 * - Pluto is asked for as `9`, the barycentre of its system, and not as `999`, the centre of the
 *   body. The 999 comes out of the solution of the satellites (PLU060) and Horizons only serves it
 *   from 1800 to 2199; the 9 comes out of the DE440 planetary ephemeris and reaches from 1550 to
 *   2650. Between the two there are some 2100 kilometres, which at thirty astronomical units is a
 *   tenth of an arcsecond, and Swiss uses the barycentre too. For the 9 Horizons gives no
 *   uncertainty (`n.a.`): a planetary ephemeris publishes no covariance.
 *
 * What it writes is read by `EphemerisPositions`, which asks for `jd`, `step`, `points` and `xyz`.
 */
final class PositionTables
{
    /** Horizons' API, which is what every table in `positions/` comes from. */
    public const HORIZONS = 'https://ssd.jpl.nasa.gov/api/horizons.api';

    /**
     * How many points are asked for at most in one go.
     *
     * Horizons cuts off at ten thousand and does not say so: it is asked for twelve thousand and it
     * sends ten thousand, with the table ending halfway through the range and not a word about it.
     * Nine thousand at a time leaves room, and the range is chopped up when it has to be.
     */
    private const POINTS_PER_REQUEST = 9000;

    /**
     * Downloads a body's positions, writes `positions/{$body}.php` and says what went into it.
     *
     * Nothing is written until the whole series has arrived and has been checked, so a download
     * that is cut short leaves the table that was already there untouched.
     *
     * @param HttpClient|null $http
     * @param string $body The body's name, which is also what the file is called.
     * @param string|null $horizonsId Its identifier in Horizons. By default the one `Body` carries,
     *                                which is where it lives next to everything else that is known
     *                                about the body: having it here as well would be having it
     *                                wrong in one of the two places the day a new one is added.
     * @param string $from First date.
     * @param string $to Last date.
     * @param string $step Separation between points, as Horizons writes it: a whole number of days.
     * @return array{body: string, horizons_id: string, estimated_points: int, requests: int, chunks: list<array{through: string, points: int}>, points: int, step: int, first_jd: float, last_jd: float, path: string}
     *
     * @throws RuntimeException If the identifier, the step or the dates do not add up, if Horizons
     *                          answers without a data block, or if the series arrives with a hole
     *                          or falls short of the range that was asked for.
     */
    public static function regenerate(
        ?HttpClient $http = null,
        string $body = '',
        ?string $horizonsId = null,
        string $from = '1600-01-01',
        string $to = '2400-01-01',
        string $step = '30 d',
    ): array {
        $http ??= new NativeHttpClient();

        if (trim($body) === '') {
            throw new RuntimeException('No body was named: there is nothing to tabulate.');
        }

        // The identifier comes out of the enum, which is where it lives alongside the rest of what
        // is known about the body. Having it here too would be having it wrong in one of the two
        // places the day a new one is added.
        $id = (string) ($horizonsId ?: (Body::tryFrom($body)?->horizonsId() ?? ''));

        if ($id === '') {
            throw new RuntimeException("I do not know what identifier «{$body}» has in Horizons: it has to be given.");
        }

        $stepDays = self::stepInDays($step);

        if ($stepDays === null) {
            throw new RuntimeException('The step has to be a whole number of days, for example «30 d».');
        }

        $first = self::date($from);
        $last = self::date($to);

        if ($first === null || $last === null || $first >= $last) {
            throw new RuntimeException('The dates are not valid, or they run backwards.');
        }

        $estimated = (int) floor(($last->getTimestamp() - $first->getTimestamp()) / 86400 / $stepDays) + 1;
        $requests = (int) ceil($estimated / self::POINTS_PER_REQUEST);

        $points = [];
        $chunks = [];
        $cursor = $first;

        while ($cursor <= $last) {
            $end = $cursor->modify('+'.((self::POINTS_PER_REQUEST - 1) * $stepDays).' days');

            if ($end > $last) {
                $end = $last;
            }

            $points = array_merge($points, self::ask($http, $id, $cursor, $end, $stepDays));

            // The next chunk starts one step after the last point that arrived, not after the date
            // that was asked for: if Horizons has come up short, the seam gives it away in the
            // continuity check instead of leaving a silent hole.
            $cursor = $end->modify('+'.$stepDays.' days');

            $chunks[] = ['through' => $end->format('Y-m-d'), 'points' => count($points)];
        }

        if (count($points) < 8) {
            throw new RuntimeException(count($points).' points have arrived: not even enough to interpolate.');
        }

        /* That the series has no holes and no jumps.

           It is not paranoia: Horizons cuts the answer off at ten thousand points and DOES NOT SAY
           SO. It is asked for twelve thousand and it sends ten thousand, with the table ending
           halfway through the range that was asked for and looking perfectly fine. This check and
           the one below are what turn that silence into an error. */
        foreach ($points as $i => $point) {
            $expected = $points[0][0] + $i * $stepDays;

            if (abs($point[0] - $expected) > 1e-6) {
                throw new RuntimeException(sprintf(
                    'The series breaks at point %d: Julian day %.1f was expected and %.1f arrived.',
                    $i, $expected, $point[0]
                ));
            }
        }

        $lastJd = (float) end($points)[0];
        $asked = Time::julianDay($last);

        if ($asked - $lastJd > $stepDays + 1e-6) {
            throw new RuntimeException(sprintf(
                'The table falls short: it reaches Julian day %.1f and it was asked for up to %.1f.',
                $lastJd, $asked
            ));
        }

        $path = self::write($body, $id, $points, (float) $stepDays);

        return [
            'body' => $body,
            'horizons_id' => $id,
            'estimated_points' => $estimated,
            'requests' => $requests,
            'chunks' => $chunks,
            'points' => count($points),
            'step' => $stepDays,
            'first_jd' => (float) $points[0][0],
            'last_jd' => $lastJd,
            'path' => $path,
        ];
    }

    /**
     * One chunk of the series: Julian day and the three coordinates of each point.
     *
     * Centred on the Sun (`500@10`) and not on the barycentre of the solar system: it is the same
     * origin VSOP87 uses, and mixing them leaves an error of up to two solar radii.
     *
     * @param HttpClient $http
     * @param string $id
     * @param DateTimeImmutable $from
     * @param DateTimeImmutable $to
     * @param int $stepDays
     * @return list<array{float, float, float, float}>
     *
     * @throws RuntimeException If the answer carries no data block.
     */
    private static function ask(HttpClient $http, string $id, DateTimeImmutable $from, DateTimeImmutable $to, int $stepDays): array
    {
        $response = $http->get(self::HORIZONS.'?'.http_build_query([
            'format' => 'text',
            'COMMAND' => "'{$id}'",
            'EPHEM_TYPE' => 'VECTORS',
            // Centred on the Sun, not on the barycentre of the solar system: it is the same origin
            // VSOP87 uses, and mixing them leaves an error of up to two solar radii.
            'CENTER' => "'500@10'",
            'REF_PLANE' => 'ECLIPTIC',
            'REF_SYSTEM' => 'J2000',
            'VEC_TABLE' => '1',
            'OUT_UNITS' => 'AU-D',
            'START_TIME' => "'".$from->format('Y-m-d')."'",
            'STOP_TIME' => "'".$to->format('Y-m-d')."'",
            'STEP_SIZE' => "'{$stepDays} d'",
            'CSV_FORMAT' => 'YES',
        ]));

        /* The data block is cut out with `strpos`, and NOT with a regular expression.

           With `preg_match('/\$\$SOE(.*?)\$\$EOE/s', ...)` this worked with Pluto and stopped
           working with Ceres, without a line of the code changing. The reason: Ceres's answer is
           over a megabyte, and a lazy quantifier over that length blows PCRE's backtracking limit.
           When that happens, `preg_match` returns FALSE rather than zero and throws nothing: the
           failure shows up as «the answer carries no data block» with the whole body of it sitting
           there and correct.

           `strpos` has no such limit and is faster besides. */
        $start = strpos($response, '$$SOE');
        $end = strpos($response, '$$EOE');

        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException(
                'The answer carries no data block. Is the identifier right? '.mb_substr($response, -600)
            );
        }

        $data = substr($response, $start + 5, $end - $start - 5);

        $points = [];

        foreach (preg_split('/\R/', trim($data)) as $line) {
            $fields = array_map('trim', explode(',', $line));

            if (count($fields) < 5) {
                continue;
            }

            $points[] = [(float) $fields[0], (float) $fields[2], (float) $fields[3], (float) $fields[4]];
        }

        return $points;
    }

    /**
     * The step as a whole number of days, or null if it is not written like one.
     *
     * @param string $step
     * @return int|null
     */
    private static function stepInDays(string $step): ?int
    {
        if (! preg_match('/^\s*(\d+)\s*d\s*$/i', $step, $parts)) {
            return null;
        }

        return ((int) $parts[1]) ?: null;
    }

    /**
     * A date at midnight UTC, or null if it is not a date.
     *
     * @param string $text
     * @return DateTimeImmutable|null
     */
    private static function date(string $text): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($text.' 00:00:00', new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Writes the table and answers with the path it went to.
     *
     * @param string $body
     * @param string $id
     * @param list<array{float, float, float, float}> $points
     * @param float $step
     * @return string
     */
    private static function write(string $body, string $id, array $points, float $step): string
    {
        $folder = DataFolder::path('positions');

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        /* The body is named by its `Body` value, which is English and is also the file name the
           engine reads: `EphemerisPositions` asks for `positions/{$body}.php`. There used to be
           four Spanish spellings accepted here (`pluton`, `quiron`, `palas`, `folo`), left over
           from when the engine was in Spanish, and they were dead the moment the values became
           English: `Body::tryFrom('pluton')` is null, so the call never reached this far. Worse
           than dead, had they reached it: they fixed the TITLE of the header and not the file
           name, so the table would have been written as `pluton.php` and never read. */
        $title = ucfirst($body);

        $php = "<?php\n\n";
        $php .= "/*\n";
        $php .= " * {$title} · heliocentric tabulated positions\n";
        $php .= " *\n";
        $php .= " * GENERATED. Do not edit by hand: written by `astronomy tables {$body}` in this\n";
        $php .= " * in this package, requesting them from JPL Horizons (object {$id}).\n";
        $php .= " *\n";
        $php .= " * Rectangular coordinates on the J2000 ecliptic, in astronomical units. Only x, y, z:\n";
        $php .= " * each point's date comes from the first Julian day and the step, which is constant.\n";
        $php .= " *\n";
        $php .= " * Outside the tabulated range there is no position, and that is deliberate:\n";
        $php .= " * extrapolating an orbit fitted from observation is inventing where the body was.\n";
        $php .= " */\n\n";
        $php .= "return [\n";
        /* The three keys are in ENGLISH because the package reads them: `EphemerisPositions::j2000`
           asks for `$table['jd']`, `$table['step']` and `$table['points']`. Written in Spanish, a
           freshly generated file makes the first read die with «Undefined array key», which is to
           say the generator leaves the repository broken without anything failing while writing. */
        $php .= sprintf("    'jd' => %.1F,\n", $points[0][0]);
        $php .= sprintf("    'step' => %.10G,\n", $step);
        $php .= sprintf("    'points' => %d,\n\n", count($points));
        $php .= "    'xyz' => [\n";

        foreach ($points as $point) {
            $php .= sprintf("        [%.12F, %.12F, %.12F],\n", $point[1], $point[2], $point[3]);
        }

        $php .= "    ],\n];\n";

        $path = "{$folder}/{$body}.php";

        file_put_contents($path, $php);

        return $path;
    }
}
