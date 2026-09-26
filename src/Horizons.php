<?php

namespace Astronomy;

use RuntimeException;
use Throwable;

/**
 * What the engine asks JPL Horizons for when it downloads data: a body's positions every so often
 * and the list of major bodies.
 *
 * Plain PHP. The network goes through an `HttpClient`, which by default is `NativeHttpClient`. The
 * `astro:*` commands that were already there, the ones that generate the repository's tables, still
 * go through a framework client; this is what everything that has to work outside an
 * application uses.
 */
final class Horizons
{
    private const URL = 'https://ssd.jpl.nasa.gov/api/horizons.api';

    /**
     * How many rows are asked for at most in one go.
     *
     * Horizons once cut off at ten thousand rows without warning (recorded in
     * `PositionTables`), and on 14 September 2026 it sent 87,673 in a single response. It is
     * not known what changed, so it is asked for in chunks and, above all, each chunk starts at the
     * row after the last one that arrived: if Horizons cuts off, the next request carries on where
     * it left off instead of leaving a hole.
     */
    private const ROWS_PER_REQUEST = 50000;

    /**
     * How many times the same thing is asked for again before giving up, and how long is waited
     * between attempts.
     *
     * It is measured: asking many times in a row, JPL stops answering for a few minutes and does it
     * with a 200 and an empty body, not with an error. Without retries that kills a file's
     * download halfway through, and what Horizons really says when it has no data (that the body
     * does not exist, that it does not reach those dates) comes as text and is not retried, which
     * would be waiting for something that is not going to change.
     */
    private const ATTEMPTS = 3;

    public const WAIT_SECONDS = 15;

    /**
     * @param HttpClient $http
     * @param int $timeoutSeconds How long is waited between attempts. Zero in the tests, which have
     * nobody at the other end to let breathe.
     */
    public function __construct(
        private readonly HttpClient $http = new NativeHttpClient(),
        private readonly int $timeoutSeconds = self::WAIT_SECONDS,
    ) {}

    /**
     * A body's geometric positions, rectangular in the J2000 ecliptic and in AU, every
     * `$stepMinutes` minutes of Terrestrial Time from `$fromTT` to `$toTT`, both included.
     *
     * Geometric (`VEC_CORR = NONE`) and in TT, like the tables and the correction the engine already
     * carries: with the light time inside, the path of the light would be stored in the table, and
     * in UT the difference between two delta T would be stored.
     *
     * The coordinates come back as three lists and not as a list of triples: four hundred thousand
     * PHP triples are more than a hundred megabytes of memory, and three lists of numbers, about
     * twenty.
     *
     * @param string $command What goes in `COMMAND`.
     * @param string $centre What goes in `CENTER`, such as `500@10`.
     * @param float $fromTT
     * @param float $toTT
     * @param int $stepMinutes
     * @return array{x: list<float>, y: list<float>, z: list<float>, fuente: string}
     *
     * @throws RuntimeException If Horizons gives no positions or the series does not add up.
     */
    public function vectors(string $command, string $centre, float $fromTT, float $toTT, int $stepMinutes): array
    {
        $step = $stepMinutes / 1440;
        $total = (int) round(($toTT - $fromTT) / $step) + 1;
        $x = [];
        $y = [];
        $z = [];
        $source = '';

        while (count($x) < $total) {
            $first = count($x);
            $last = min($total, $first + self::ROWS_PER_REQUEST) - 1;

            $response = $this->request([
                'format' => 'text',
                'COMMAND' => "'{$command}'",
                'EPHEM_TYPE' => 'VECTORS',
                'CENTER' => "'{$centre}'",
                'REF_PLANE' => 'ECLIPTIC',
                'REF_SYSTEM' => 'J2000',
                'VEC_TABLE' => '1',
                'VEC_CORR' => 'NONE',
                'OUT_UNITS' => 'AU-D',
                'TIME_TYPE' => 'TT',
                'CSV_FORMAT' => 'YES',
                'OBJ_DATA' => 'NO',
                'START_TIME' => sprintf("'JD%.9F'", $fromTT + $first * $step),
                'STOP_TIME' => sprintf("'JD%.9F'", $fromTT + $last * $step),
                'STEP_SIZE' => "'{$stepMinutes} m'",
            ]);

            [$rows, $source] = self::rows($response, $command);

            if (count($rows) > $last - $first + 1) {
                throw new RuntimeException("Horizons has sent more rows of {$command} than it was asked for.");
            }

            foreach ($rows as $i => [$jd, $fx, $fy, $fz]) {
                $expected = $fromTT + ($first + $i) * $step;

                // A row that does not fall where it should is either a hole or a step Horizons has
                // understood in another way, and both would give a table that looks fine and is wrong.
                if (abs($jd - $expected) > 1e-6) {
                    throw new RuntimeException(sprintf(
                        'The series for %s is broken: Julian day %.6F was expected and %.6F arrived.',
                        $command, $expected, $jd
                    ));
                }

                $x[] = $fx;
                $y[] = $fy;
                $z[] = $fz;
            }
        }

        return ['x' => $x, 'y' => $y, 'z' => $z, 'source' => $source];
    }

    /**
     * The uncertainty JPL declares for a body: the worst sigma of the ones it publishes over the
     * interval, in arcseconds, or null if it does not give it.
     *
     * It is the `QUANTITIES='36'` with which it was already decided whether Chiron and Pholus were
     * trustworthy, and here it matters more: anyone can ask for any asteroid, and a body discovered
     * the day before yesterday has an orbit that going backwards is worth nothing. Only small
     * bodies with a fitted covariance declare it: satellites and comets answer `n.a.`, just like
     * the planets, because their ephemerides publish no covariance.
     *
     * It never throws, and that is on purpose. It is one extra piece of data about data that is
     * already downloaded, so if Horizons does not answer, or answers with an error of its own, null
     * is returned and the download carries on. Measured: for some bodies Horizons itself returns
     * "unexpected error: please notify the webmaster" instead of a table.
     *
     * @param string $command
     * @param float $fromTT
     * @param float $toTT
     * @param int $samples How many times it is looked at inside the interval.
     * @return float|null Arcseconds.
     */
    public function uncertainty(string $command, float $fromTT, float $toTT, int $samples = 9): ?float
    {
        $step = max(1.0, ($toTT - $fromTT) / max(1, $samples - 1));

        try {
            $response = $this->http->get(self::URL.'?'.http_build_query([
                'format' => 'text',
                'COMMAND' => "'{$command}'",
                'EPHEM_TYPE' => 'OBSERVER',
                'CENTER' => "'500@399'",
                'QUANTITIES' => "'36'",
                'ANG_FORMAT' => 'DEG',
                'CSV_FORMAT' => 'YES',
                'OBJ_DATA' => 'NO',
                'TIME_TYPE' => 'TT',
                'START_TIME' => sprintf("'JD%.6F'", $fromTT),
                'STOP_TIME' => sprintf("'JD%.6F'", $toTT),
                'STEP_SIZE' => sprintf("'%d d'", (int) round($step)),
            ]));
        } catch (Throwable) {
            return null;
        }

        $start = strpos($response, '$$SOE');
        $end = strpos($response, '$$EOE');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $worst = null;

        foreach (explode("\n", trim(substr($response, $start + 5, $end - $start - 5))) as $line) {
            $fields = array_map('trim', explode(',', $line));

            // The two columns are sigma in right ascension and in declination. Where there is no
            // covariance, Horizons writes "n.a." and there is nothing here to compare.
            if (count($fields) >= 5 && is_numeric($fields[3]) && is_numeric($fields[4])) {
                $worst = max($worst ?? 0.0, (float) $fields[3], (float) $fields[4]);
            }
        }

        return $worst;
    }

    /**
     * The list of major bodies (`COMMAND='MB'`), just as Horizons gives it.
     *
     * @return string
     */
    public function majorBodies(): string
    {
        return $this->http->get(self::URL.'?'.http_build_query(['format' => 'text', 'COMMAND' => "'MB'"]));
    }

    /**
     * One request, retried as long as Horizons answers empty or does not answer.
     *
     * @param array<string, string> $query
     * @return string
     *
     * @throws RuntimeException
     */
    private function request(array $query): string
    {
        $url = self::URL.'?'.http_build_query($query);
        $lastError = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $response = $this->http->get($url);

                if (trim($response) !== '') {
                    return $response;
                }

                $lastError = new RuntimeException('answered empty');
            } catch (RuntimeException $error) {
                $lastError = $error;
            }

            if ($attempt < self::ATTEMPTS && $this->timeoutSeconds > 0) {
                sleep($this->timeoutSeconds);
            }
        }

        throw new RuntimeException(
            sprintf('Horizons has not answered in %d attempts: %s.', self::ATTEMPTS, $lastError->getMessage()),
            0,
            $lastError
        );
    }

    /**
     * The rows of a vectors response and the name with which Horizons identifies the body.
     *
     * When it does not find the body or has no data for those dates, Horizons answers 200 with the
     * explanation as text and with no data block. That explanation is what goes into the exception.
     *
     * @param string $response
     * @param string $command
     * @return array{0: list<array{0: float, 1: float, 2: float, 3: float}>, 1: string}
     */
    private static function rows(string $response, string $command): array
    {
        // With `strpos` and not with a lazy regular expression: over more than a megabyte,
        // `preg_match` returns false without throwing anything (recorded in `PositionTables`).
        $start = strpos($response, '$$SOE');
        $end = strpos($response, '$$EOE');

        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException("Horizons gave no positions for {$command}: ".self::reason($response));
        }

        $source = preg_match('/^Target body name:\s*(.*?)\s*$/m', substr($response, 0, $start), $parts)
            ? (string) preg_replace('/\s+/', ' ', $parts[1])
            : '';

        $rows = [];

        foreach (explode("\n", trim(substr($response, $start + 5, $end - $start - 5))) as $line) {
            $fields = explode(',', $line);

            if (count($fields) >= 5) {
                $rows[] = [(float) $fields[0], (float) $fields[2], (float) $fields[3], (float) $fields[4]];
            }
        }

        if ($rows === []) {
            throw new RuntimeException("Horizons returned the data block for {$command} empty.");
        }

        return [$rows, $source];
    }

    /**
     * The last lines with text of a response with no data, which is where Horizons explains why.
     *
     * @param string $response
     * @return string
     */
    private static function reason(string $response): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $response)),
            fn (string $line) => $line !== '' && ! str_starts_with($line, '***')
        ));

        return substr(implode(' ', array_slice($lines, -6)), 0, 600);
    }
}
