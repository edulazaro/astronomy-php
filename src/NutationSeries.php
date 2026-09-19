<?php

namespace Astronomy;

use RuntimeException;

/**
 * Turns the IAU 2000B nutation series, as ERFA publishes it, into a PHP table.
 *
 * Nutation is the nodding of the Earth's axis: some 17 arcseconds in longitude with a period of
 * 18.6 years, and it comes into every position, into the true obliquity and into apparent
 * sidereal time, that is, into the houses. With the four big terms written by hand there was half
 * an arcsecond of error left, and once everything else is down to tenths, that was what dominated
 * the residual against Swiss.
 *
 * The source is ERFA, the IAU's reference implementation (derived from SOFA, BSD licensed):
 * `nut00b.c` carries the 77 luni-solar terms of the series with their six coefficients and the
 * two fixed planetary biases, and `fal03.c`, `falp03.c`, `faf03.c`, `fad03.c` and `faom03.c` the
 * complete polynomials of the five Delaunay arguments. As with VSOP87 and ELP, not a single
 * coefficient is written here: the C source is parsed and the count is checked, because a lost
 * term gives no error, it gives a slightly wrong nutation that nobody sees looking at a wheel.
 *
 * Why the arguments are NOT taken from `nut00b.c` itself. That function evaluates them with the
 * linear term only, which is enough for the IAU because 2000B is declared for 1995-2050. Here
 * charts are cast from 1600 to 2400, and at four centuries the quadratic term of Ω is 120
 * arcseconds of argument, which over the 17 seconds of the main term leave a hundredth of a
 * second: measured against Swiss, 0.0102" in 1606. With the complete polynomials of Simon et al.
 * (1994), which are the ones Swiss uses and the ones ERFA uses for 2000A, the difference drops to
 * zero. It is the same ERFA and the same model; only the degree the argument is evaluated to
 * changes.
 *
 * The ERFA files are downloaded from GitHub. If the repository moved, the `$source` parameter
 * changes, and if the shape of the C changed, the count catches it.
 *
 * Pure PHP: in a framework application it is a console command that calls it, and on its own
 * `NutationSeries::regenerate()` is enough.
 */
final class NutationSeries
{
    /** Where ERFA's sources are downloaded from. */
    public const ERFA = 'https://raw.githubusercontent.com/liberfa/erfa/master/src';

    /**
     * Luni-solar terms of the IAU 2000B series. It is the count that has to be found in the
     * source before anything is written.
     */
    private const TERMS = 77;

    /** Coefficients of each argument polynomial: from the constant term to the fourth degree. */
    private const DEGREES = 5;

    /**
     * The five Delaunay arguments, in the order the table writes their multipliers in, and the
     * ERFA file that carries the polynomial of each one.
     */
    private const ARGUMENTS = [
        'l' => 'fal03.c',
        'lp' => 'falp03.c',
        'f' => 'faf03.c',
        'd' => 'fad03.c',
        'om' => 'faom03.c',
    ];

    /**
     * Downloads ERFA's sources and writes `nutation.php` into the data folder.
     *
     * Nothing is written until the six files have arrived and every count adds up: the 77 terms,
     * the first row of the series and the five coefficients of each of the five arguments.
     *
     * @param HttpClient|null $http
     * @param string $source Where ERFA's sources are downloaded from.
     * @return array{terms: int, arguments: int, bias: array{0: float, 1: float}, downloaded: list<string>, path: string}
     *
     * @throws RuntimeException If a file does not arrive, or if what it carries is not what it was.
     */
    public static function regenerate(?HttpClient $http = null, string $source = self::ERFA): array
    {
        $http ??= new NativeHttpClient();
        $source = rtrim($source, '/');
        $downloaded = [];

        $url = "{$source}/nut00b.c";
        $downloaded[] = $url;
        $series = $http->get($url);

        $terms = self::parseTerms($series);

        if (count($terms) !== self::TERMS) {
            throw new RuntimeException(sprintf(
                'The series has %d terms and there had to be %d: the shape has changed or one is missing.',
                count($terms), self::TERMS
            ));
        }

        /* The first term is the lunar node's, with 17.2 arcseconds of amplitude. If the biggest
           row of all is not whole, the count can add up and the table still be wrong. */
        if ($terms[0][4] !== '1' || $terms[0][5] !== '-172064161.0') {
            throw new RuntimeException('The first term is not Ω\'s with -172064161.0: the parse has read something else.');
        }

        $bias = self::parseBias($series);

        if ($bias === null) {
            throw new RuntimeException('DPPLAN and DEPLAN, the fixed planetary biases, are not to be found.');
        }

        $arguments = [];

        foreach (self::ARGUMENTS as $key => $file) {
            $url = "{$source}/{$file}";
            $downloaded[] = $url;
            $coefficients = self::parseArgument($http->get($url));

            if (count($coefficients) !== self::DEGREES) {
                throw new RuntimeException(sprintf(
                    '%s: %d coefficients and there had to be %d.',
                    $file, count($coefficients), self::DEGREES
                ));
            }

            $arguments[$key] = $coefficients;
        }

        $path = DataFolder::path('nutation.php');

        file_put_contents($path, self::render($terms, $arguments, $bias));

        return [
            'terms' => count($terms),
            'arguments' => count($arguments),
            'bias' => [(float) $bias[0], (float) $bias[1]],
            'downloaded' => $downloaded,
            'path' => $path,
        ];
    }

    /**
     * The `x[]` table of `eraNut00b`: each term is five integer multipliers (l, l', F, D, Ω) and
     * six coefficients with decimals (sine, sine·t and cosine for the longitude; cosine, cosine·t
     * and sine for the obliquity).
     *
     * The block between the opening of the array and its closing is cut out before searching, so
     * that a brace with eleven numbers anywhere else in the file does not count as a term. The
     * numbers are kept as text, exactly as they are written, so that every row of the generated
     * table can be checked against the source at a glance.
     *
     * @param string $raw
     * @return list<list<string>>
     */
    private static function parseTerms(string $raw): array
    {
        $start = strpos($raw, '} x[] = {');
        $end = $start === false ? false : strpos($raw, '};', $start);

        if ($start === false || $end === false) {
            return [];
        }

        $block = substr($raw, $start, $end - $start);

        $integer = '(-?\d+)';
        $decimal = '(-?\d+\.\d+)';
        $pattern = '/\{\s*'.implode('\s*,\s*', array_merge(array_fill(0, 5, $integer), array_fill(0, 6, $decimal))).'\s*\}/';

        preg_match_all($pattern, $block, $rows, PREG_SET_ORDER);

        return array_map(fn (array $row) => array_slice($row, 1), $rows);
    }

    /**
     * The two fixed offsets that 2000B puts in place of the planetary nutation, in
     * milliarcseconds. They are Luzum's (2001), not McCarthy and Luzum's (2003), and the
     * difference matters: the first ones hold when the nutation is applied separately from the
     * precession, which is what is done here.
     *
     * @param string $raw
     * @return array{0: string, 1: string}|null
     */
    private static function parseBias(string $raw): ?array
    {
        if (! preg_match('/DPPLAN\s*=\s*(-?\d+\.\d+)\s*\*\s*ERFA_DMAS2R/', $raw, $dpsi)
            || ! preg_match('/DEPLAN\s*=\s*(-?\d+\.\d+)\s*\*\s*ERFA_DMAS2R/', $raw, $deps)) {
            return null;
        }

        return [$dpsi[1], $deps[1]];
    }

    /**
     * The polynomial of a fundamental argument, from `fal03.c` and company. In the C it is
     * written in Horner form, `a0 + t * (a1 + t * (a2 + ...))`, inside an
     * `fmod(..., ERFA_TURNAS)`, and the minus signs are separated from the number by a space.
     * That `fmod` is cut out and the numbers are read in order, which is that of increasing
     * degree.
     *
     * @param string $raw
     * @return list<string>
     */
    private static function parseArgument(string $raw): array
    {
        $start = strpos($raw, 'fmod(');
        $end = $start === false ? false : strpos($raw, 'ERFA_TURNAS', $start);

        if ($start === false || $end === false) {
            return [];
        }

        preg_match_all('/([-+]?)\s*(\d+\.\d+)/', substr($raw, $start, $end - $start), $numbers, PREG_SET_ORDER);

        return array_map(fn (array $n) => ($n[1] === '-' ? '-' : '').$n[2], $numbers);
    }

    /**
     * The table as it is written to disk. `Time::nutation` is what reads it back.
     *
     * @param list<list<string>> $terms
     * @param array<string, list<string>> $arguments
     * @param array{0: string, 1: string} $bias
     * @return string
     */
    private static function render(array $terms, array $arguments, array $bias): string
    {
        $php = "<?php\n\n";
        $php .= "/*\n";
        $php .= " * Nutation IAU 2000B\n";
        $php .= " *\n";
        $php .= " * GENERATED. Do not edit by hand: written by `astronomy nutation`\n";
        $php .= " * in this package from ERFA's source (nut00b.c for the series and the biases;\n";
        $php .= " * fal03.c, falp03.c, faf03.c, fad03.c and faom03.c for the arguments), the IAU's\n";
        $php .= " * reference implementation.\n";
        $php .= " *\n";
        $php .= " * `arguments`: the five Delaunay arguments (l, l', F, D, Ω) as polynomials in Julian\n";
        $php .= " * centuries TT from J2000, in arc seconds, from the constant term up to the fourth\n";
        $php .= " * degree. They are the ones from Simon et al. (1994) as fixed by IERS 2003.\n";
        $php .= " *\n";
        $php .= " * `terms`: [l, l', F, D, Ω, ps, pst, pc, ec, ect, es]. The first five are the\n";
        $php .= " * argument's integer multipliers. The six coefficients are in tenths of a\n";
        $php .= " * microarcsecond, and the two `t` ones in the same unit per century, the source's\n";
        $php .= " * own unit: so each row can be checked against it at a glance.\n";
        $php .= " *   Δψ += (ps + pst·t)·sin(arg) + pc·cos(arg)\n";
        $php .= " *   Δε += (ec + ect·t)·cos(arg) + es·sin(arg)\n";
        $php .= " *\n";
        $php .= " * `bias`: the two fixed offsets that stand in for the planetary nutation, in\n";
        $php .= " * milliarcseconds, for Δψ and Δε.\n";
        $php .= " *\n";
        $php .= sprintf(" * %d terms.\n", count($terms));
        $php .= " */\n\n";
        $php .= "return [\n";
        $php .= "    'model' => 'IAU 2000B',\n";
        $php .= "    'arguments' => [\n";

        foreach ($arguments as $key => $coefficients) {
            $php .= sprintf("        '%s' => [%s],\n", $key, implode(', ', $coefficients));
        }

        $php .= "    ],\n";
        $php .= sprintf("    'bias' => [%s, %s],\n", $bias[0], $bias[1]);
        $php .= "    'terms' => [\n";

        foreach ($terms as $term) {
            $php .= '        ['.implode(', ', $term)."],\n";
        }

        $php .= "    ],\n";

        return $php."];\n";
    }
}
