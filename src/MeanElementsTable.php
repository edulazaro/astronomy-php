<?php

namespace Astronomy;

use RuntimeException;

/**
 * Turns the table of mean planetary elements of Simon et al. (1994), as ERFA publishes it, into a
 * PHP table. Pure PHP: in a framework application it is a console command that calls it, and on its
 * own `MeanElementsTable::regenerate()` is enough.
 *
 * The mean elements are each planet's averaged ellipse, without the periodic perturbations. The
 * mean node and the mean perihelion come out of there, which is what Swiss returns with
 * `SE_NODBIT_MEAN` and the only thing `NodesAndApsides` was missing. Why it was missing is written
 * down in `MeanElements`: there was no source in the repository, and copying it from the Swiss code
 * is ruled out by licence.
 *
 * The source is ERFA, the same one the nutation is already downloaded from: `plan94.c` is its
 * BSD-licensed reimplementation of the paper by Simon, Bretagnon, Chapront, Chapront-Touzé, Francou
 * and Laskar, *Astronomy and Astrophysics* 282, 663-683 (1994), and it carries inside the six
 * tables of mean elements with their three coefficients each.
 *
 * The C is parsed and the count is checked, as with the nutation and for the same reason: a
 * lost row gives no error, it gives a planet with its node somewhere else. And two specific numbers
 * are checked as well, because the count can add up with rows read half way.
 *
 * What is NOT downloaded are the trigonometric terms (`kp`, `ca`, `sa`, `kq`, `cl`, `sl`), and
 * it is worth saying so in order that nobody misses them: they are the periodic corrections
 * `plan94` adds to the semi-major axis and to the mean longitude in order to compute a position,
 * that is, exactly the perturbations a MEAN element exists in order not to carry. Measured: without
 * them the mean node agrees with Swiss's to 0.98 arcseconds.
 */
final class MeanElementsTable
{
    /** Where ERFA's source is downloaded from. */
    public const ERFA = 'https://raw.githubusercontent.com/liberfa/erfa/master/src';

    /** The eight bodies of the table, in the order ERFA writes them. The third one is the Earth-Moon barycentre. */
    private const BODIES = ['mercury', 'venus', 'earth', 'mars', 'jupiter', 'saturn', 'uranus', 'neptune'];

    /**
     * The six tables, with the name they have in the C and the one they carry in the generated file.
     *
     * The names on the inside are the engine's and the ones on the outside are ERFA's; both stay in
     * sight so that the file can be checked against the source without translating anything from
     * memory.
     */
    private const TABLES = [
        'a' => 'a',
        'l' => 'dlm',
        'e' => 'e',
        'pi' => 'pi',
        'i' => 'dinc',
        'om' => 'omega',
    ];

    /**
     * Two numbers of the table that have to come out just as they are, one of each family: a
     * semi-major axis, which is an ordinary polynomial, and a linear term of mean longitude, which
     * goes in arcseconds per millennium and is the biggest number in the file. If the parse reads
     * something else, it blows up here.
     */
    private const SENTINELS = [
        ['a', 0, 0, '0.3870983098'],
        ['l', 0, 1, '5381016286.88982'],
        ['om', 7, 0, '131.78405702'],
    ];

    /**
     * Downloads `plan94.c`, parses the six tables and writes `mean-elements.php` into the data
     * folder.
     *
     * @param HttpClient|null $http
     * @param string $source Where ERFA's source is downloaded from, without the file name.
     * @return array{bodies: int, elements: int, source: string, path: string}
     *
     * @throws RuntimeException If the download fails, the format of the source has changed or a
     * sentinel does not match, in which case nothing is written.
     */
    public static function regenerate(?HttpClient $http = null, string $source = self::ERFA): array
    {
        $http ??= new NativeHttpClient();
        $url = self::url($source);
        $raw = $http->get($url);

        $tables = [];

        foreach (self::TABLES as $key => $inTheC) {
            $rows = self::parseTable($raw, $inTheC);

            if (count($rows) !== count(self::BODIES)) {
                throw new RuntimeException(sprintf(
                    'Table %s has %d rows and there had to be %d: the format of the source has changed.',
                    $inTheC,
                    count($rows),
                    count(self::BODIES)
                ));
            }

            foreach ($rows as $index => $row) {
                if (count($row) !== 3) {
                    throw new RuntimeException(sprintf(
                        'Row %d of %s has %d coefficients and there had to be 3.',
                        $index,
                        $inTheC,
                        count($row)
                    ));
                }
            }

            $tables[$key] = $rows;
        }

        foreach (self::SENTINELS as [$key, $row, $column, $expected]) {
            if (($tables[$key][$row][$column] ?? null) !== $expected) {
                throw new RuntimeException(sprintf(
                    'Sentinel %s[%d][%d] had to be %s and %s was read: the parse is picking up another table.',
                    $key,
                    $row,
                    $column,
                    $expected,
                    $tables[$key][$row][$column] ?? 'nothing'
                ));
            }
        }

        $path = DataFolder::path('mean-elements.php');

        file_put_contents($path, self::render($tables));

        return [
            'bodies' => count(self::BODIES),
            'elements' => count(self::TABLES),
            'source' => $url,
            'path' => $path,
        ];
    }

    /**
     * The address `plan94.c` is downloaded from.
     *
     * It is public so that whoever calls this can say what is about to be downloaded before the
     * download starts, without building the address a second time somewhere else.
     *
     * @param string $source Where ERFA's source is, without the file name.
     * @return string
     */
    public static function url(string $source = self::ERFA): string
    {
        return rtrim($source, '/').'/plan94.c';
    }

    /**
     * One of the `static const double NAME[][3] = { ... };` tables of the source.
     *
     * The block is cut out by its own declaration before rows are looked for, which is what stops a
     * brace with three numbers from anywhere else in the file counting as a row: further down are
     * the tables of trigonometric terms, which have nine and ten columns but share the same shape
     * of braces.
     *
     * The numbers are kept as TEXT, written just as they are, so that every row of the generated
     * file can be checked against the source at a glance. There are three forms in this table and
     * all three have to get through: `0.3870983098`, `19132e-10` and `-0.0000213896`.
     *
     * @param string $raw
     * @param string $name
     * @return list<list<string>>
     */
    private static function parseTable(string $raw, string $name): array
    {
        $start = strpos($raw, "double {$name}[][3] = {");
        $end = $start === false ? false : strpos($raw, '};', $start);

        if ($start === false || $end === false) {
            return [];
        }

        $block = substr($raw, $start, $end - $start);

        preg_match_all('/\{([^{}]*)\}/', $block, $braces);

        $rows = [];

        foreach ($braces[1] as $contents) {
            preg_match_all('/[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?/', $contents, $numbers);

            if ($numbers[0] !== []) {
                $rows[] = array_map(fn (string $n) => ltrim($n, '+'), $numbers[0]);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, list<list<string>>> $tables
     * @return string
     */
    private static function render(array $tables): string
    {
        $php = "<?php\n\n";
        $php .= "/*\n";
        $php .= " * Mean elements of the eight planets · Simon et al. (1994)\n";
        $php .= " *\n";
        $php .= " * GENERATED. Do not edit by hand: written by `astronomy mean-elements`\n";
        $php .= " * in this package from ERFA's plan94.c, the reference implementation (BSD license)\n";
        $php .= " * of the paper by Simon, Bretagnon, Chapront, Chapront-Touzé, Francou and Laskar,\n";
        $php .= " * A&A 282, 663-683 (1994).\n";
        $php .= " *\n";
        $php .= " * Time `t` is in Julian MILLENNIA TT from J2000, the paper's unit and not the rest of\n";
        $php .= " * the engine's. The angles are referred to the J2000 ecliptic and equinox, a FIXED\n";
        $php .= " * plane: that is why Earth's inclination is zero at J2000 and grows from there.\n";
        $php .= " *\n";
        $php .= " * `a` (AU) and `e` are ordinary polynomials:        a0 + (a1 + a2·t)·t\n";
        $php .= " * `l`, `pi`, `i` and `om` are degrees plus seconds: (3600·a0 + (a1 + a2·t)·t) / 3600 degrees\n";
        $php .= " *\n";
        $php .= " * `l` is the mean longitude, `pi` the longitude of perihelion ϖ (the broken angle from\n";
        $php .= " * the textbooks, not the ecliptic longitude of the point) and `om` the ascending node's.\n";
        $php .= " *\n";
        $php .= " * The trigonometric terms from plan94.c are NOT here: those are the periodic corrections\n";
        $php .= " * that function adds to compute a POSITION, which is exactly what a mean element lacks.\n";
        $php .= " */\n\n";
        $php .= "return [\n";
        /* The keys are in ENGLISH because the engine reads them: `MeanElements` asks for
           `self::table()['bodies'][...]`, and with the Spanish word written here instead that
           class's `??` turns «the key is missing» into «this body has no mean elements», with
           no warning and no error. And `source` goes in ONCE: it was written twice in a row, inherited from
           translating the file with a replacement instead of the generator that writes it, and
           the second one ate the first one in silence. */
        $php .= "    'source' => 'Simon, Bretagnon, Chapront, Chapront-Touzé, Francou and Laskar (1994), A&A 282, 663-683, through ERFA plan94.c',\n";
        $php .= "    'bodies' => [\n";

        foreach (self::BODIES as $index => $body) {
            $php .= sprintf("        '%s' => [\n", $body);

            foreach (self::TABLES as $key => $inTheC) {
                $php .= sprintf(
                    "            '%s' => [%s],%s\n",
                    $key,
                    implode(', ', $tables[$key][$index]),
                    $key === 'a' ? ' // '.$inTheC : ''
                );
            }

            $php .= "        ],\n";
        }

        $php .= "    ],\n";

        return $php."];\n";
    }
}
