<?php

namespace Astronomy;

use RuntimeException;

/**
 * Turns the original VSOP87 series into the PHP tables `Vsop87` evaluates.
 *
 * The positions of the planets are neither invented nor copied by hand: they are computed with
 * VSOP87, the planetary theory of Bretagnon and Francou of the Bureau des Longitudes, and the
 * coefficients are downloaded from their publication at the CDS in Strasbourg. There are
 * thirty-one thousand numbers; mistyping one gives a wrong position that nobody catches looking
 * at a wheel, so no person touches them.
 *
 * This exists so that the repository records WHERE they come from. The generated files are
 * committed, because a birth chart cannot depend on Strasbourg being up on the day somebody
 * deploys.
 *
 * Variant D is what is used: heliocentric spherical coordinates (longitude, latitude, radius)
 * referred to the ecliptic and equinox OF DATE, which is exactly the system astrology works in.
 * The other variants force a rotation from J2000 to the date by hand.
 *
 * Only D. The Sun's E series (its position with respect to the barycentre) was downloaded here
 * too and withdrawn: against the JPL vectors that series leaves the Sun a thousand kilometres
 * from DE440's barycentre, and the sum of masses over the D series (`Ephemeris::barycentricSun`,
 * with the masses of `resources/astro/masses.php`) leaves it at a hundred.
 */
final class Vsop87Series
{
    /** Where the series are published: catalogue VI/81 at the CDS in Strasbourg. */
    public const CDS = 'https://cdsarc.cds.unistra.fr/ftp/cats/VI/81';

    /**
     * The name of each file at the CDS and the body it belongs to.
     *
     * The key is the name of the file that gets written, which is `Body`'s own value: that is
     * what `Vsop87::spherical` is handed and what it looks for in the folder.
     *
     * Pluto is not here: VSOP87 does not include it, because it is not one of the planets whose
     * motion they solved. It goes another way.
     */
    private const BODIES = [
        'mercury' => 'mer',
        'venus' => 'ven',
        'earth' => 'ear',
        'mars' => 'mar',
        'jupiter' => 'jup',
        'saturn' => 'sat',
        'uranus' => 'ura',
        'neptune' => 'nep',
    ];

    /** Which variable each number in the header of a spherical series is (variant D). */
    private const VARIABLES = [1 => 'L', 2 => 'B', 3 => 'R'];

    /**
     * Amplitude below which a term is dropped, in radians (and in AU for the radius).
     *
     * It is 1e-8 because that is what the series that ship were built with, and a default
     * that does not reproduce them is a command that quietly makes the engine worse: the console
     * command this came from defaulted to 1e-7 and the committed tables were written by passing
     * 1e-8 by hand, so running it with no options rewrote Earth with 213 terms where it has 621,
     * discarded 2,212 instead of 1,804, and failed at nothing. A chart drawn from that is not
     * wrong in a way anybody sees; it is just less true.
     */
    public const THRESHOLD = 1e-8;

    /**
     * Downloads the eight series, writes `vsop87/*.php` into the data folder and says what went in.
     *
     * The bodies are downloaded, parsed and written one at a time and in order, so that a
     * download that fails halfway leaves the ones already done written, which is what it did
     * before.
     *
     * @param HttpClient|null $http
     * @param float $threshold Smallest amplitude kept, in radians (and in AU for the radius).
     * @param string $source Where the series are downloaded from.
     * @return array{bodies: list<array{body: string, url: string, terms: int, discarded: int, path: string}>, terms: int, files: int, folder: string}
     *
     * @throws RuntimeException If a series cannot be downloaded.
     */
    public static function regenerate(?HttpClient $http = null, float $threshold = self::THRESHOLD, string $source = self::CDS): array
    {
        $http ??= new NativeHttpClient();
        $source = rtrim($source, '/');
        $folder = DataFolder::path('vsop87');

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $bodies = [];
        $total = 0;

        foreach (self::BODIES as $body => $suffix) {
            $url = "{$source}/VSOP87D.{$suffix}";
            $parsed = self::parse($http->get($url), $threshold);
            $series = $parsed['series'];
            $terms = array_sum(array_map(fn (array $powers) => array_sum(array_map('count', $powers)), $series));
            $total += $terms;
            $path = "{$folder}/{$body}.php";

            file_put_contents($path, self::render($body, $series, $threshold, $terms, $parsed['discarded']));

            $bodies[] = [
                'body' => $body,
                'url' => $url,
                'terms' => $terms,
                'discarded' => $parsed['discarded'],
                'path' => $path,
            ];
        }

        return [
            'bodies' => $bodies,
            'terms' => $total,
            'files' => count($bodies),
            'folder' => $folder,
        ];
    }

    /**
     * Checks a block brought as many terms as its own header announced.
     *
     * A cut-off download does not fail: it parses fewer terms and writes a perfectly
     * well-formed file** whose header announces the smaller count, with no error anywhere and a
     * chart that answers a slightly different sky. There is nothing to invent here, which is the
     * whole point: every VSOP87 block says how many terms it has («367 TERMS») right in its own
     * header, so the count to check against is published and not a threshold somebody chose.
     *
     * It is the same rule the star catalogue already runs on: if one star does not resolve,
     * nothing is written.
     *
     * @param array{0: string, 1: int}|null $current
     * @param int $read
     * @param int $announced
     * @return void
     *
     * @throws RuntimeException
     */
    private static function close(?array $current, int $read, int $announced): void
    {
        if ($current !== null && $read !== $announced) {
            throw new RuntimeException(sprintf(
                'The %s series of T**%d brought %d terms and its header announces %d: the download came short.',
                $current[0], $current[1], $read, $announced
            ));
        }
    }

    /**
     * Reads the CDS format.
     *
     * Each series starts with a header saying which variable it is and which power of tau it is
     * multiplied by, and its terms follow. Of each term only the LAST THREE numbers of the line
     * matter, which are A, B and C of `A·cos(B + C·tau)`. Everything in front of them is the
     * integer multipliers of the arguments, which serve to rebuild the theory but not to evaluate
     * it.
     *
     * @param string $raw The body of a `VSOP87D.*` file.
     * @param float $threshold
     * @return array{series: array<string, array<int, list<array{float, float, float}>>>, discarded: int}
     */
    public static function parse(string $raw, float $threshold): array
    {
        $series = [];
        $discarded = 0;
        $current = null;
        $announced = 0;
        $read = 0;

        foreach (preg_split('/\R/', $raw) as $line) {
            if (str_contains($line, 'VSOP87') && str_contains($line, 'VARIABLE')) {
                /* The header is read with its count checked and not just matched. A header whose
                   shape ever changed at the CDS would otherwise come out as an undefined index
                   rather than as a line saying what changed. */
                if (preg_match('/VARIABLE (\d)/', $line, $v) !== 1
                    || preg_match('/\*T\*\*(\d)/', $line, $p) !== 1
                    || preg_match('/(\d+) TERMS?\b/', $line, $n) !== 1
                    || ! isset(self::VARIABLES[(int) $v[1]])) {
                    throw new RuntimeException("This VSOP87 header cannot be read: «{$line}»");
                }

                self::close($current, $read, $announced);

                $current = [self::VARIABLES[(int) $v[1]], (int) $p[1]];
                $announced = (int) $n[1];
                $read = 0;
                $series[$current[0]][$current[1]] ??= [];

                continue;
            }

            if ($current === null || trim($line) === '') {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line));

            if (count($parts) < 3) {
                continue;
            }

            [$a, $b, $c] = array_map('floatval', array_slice($parts, -3));
            $read++;

            // The truncation. A term contributes at most its amplitude, so below the threshold it
            // does not change the result to the precision that matters here. Without this there
            // are thirty-one thousand numbers and most of them do not move a thousandth of an
            // arcsecond.
            if ($a < $threshold) {
                $discarded++;

                continue;
            }

            $series[$current[0]][$current[1]][] = [$a, $b, $c];
        }

        self::close($current, $read, $announced);

        // A series left with no terms after the truncation goes: evaluating it is adding zero at
        // the cost of walking it.
        foreach ($series as $variable => $powers) {
            $series[$variable] = array_filter($powers);
            ksort($series[$variable]);
        }

        return ['series' => array_filter($series), 'discarded' => $discarded];
    }

    /**
     * One body's table, as the PHP file that gets written.
     *
     * @param string $body
     * @param array<string, array<int, list<array{float, float, float}>>> $series
     * @param float $threshold
     * @param int $terms
     * @param int $discarded
     * @return string
     */
    private static function render(string $body, array $series, float $threshold, int $terms, int $discarded): string
    {
        $php = "<?php\n\n";
        $php .= "/*\n";
        $php .= " * VSOP87D · ".ucfirst($body)."\n";
        $php .= " *\n";
        $php .= " * GENERATED. Do not edit by hand: written by `astronomy vsop87`\n";
        $php .= " * in this package, from the original series of Bretagnon and Francou published at the CDS\n";
        $php .= " * in Strasbourg.\n";
        $php .= " *\n";
        $php .= " * Heliocentric spherical coordinates referred to the ecliptic and equinox of date. L and\n";
        $php .= " * B in radians, R in astronomical units.\n";
        $php .= " *\n";
        $php .= " * Each term is [A, B, C] and equals A·cos(B + C·tau), with tau in Julian millennia\n";
        $php .= " * since J2000. The sum for each power is multiplied by tau raised to that power.\n";
        $php .= " *\n";
        $php .= " * {$terms} terms, {$discarded} discarded below {$threshold} rad.\n";
        $php .= " */\n\n";
        $php .= "return [\n";

        foreach ($series as $variable => $powers) {
            $php .= "    '{$variable}' => [\n";

            foreach ($powers as $power => $powerTerms) {
                $php .= "        {$power} => [\n";

                foreach ($powerTerms as [$a, $b, $c]) {
                    $php .= sprintf("            [%.11F, %.11F, %.11F],\n", $a, $b, $c);
                }

                $php .= "        ],\n";
            }

            $php .= "    ],\n";
        }

        return $php."];\n";
    }
}
