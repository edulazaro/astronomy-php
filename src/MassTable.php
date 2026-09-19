<?php

namespace Astronomy;

use RuntimeException;

/**
 * Downloads the solar system masses from the DE440 header and writes them as a PHP table.
 *
 * They are needed for ONE thing: placing the Sun with respect to the solar system barycentre,
 * which is what separates the heliocentric frame from the barycentric one. The barycentre is the
 * mean of the positions weighted by the masses, so with the heliocentric ones the engine already
 * gives and these ten masses the barycentric Sun is a sum. It used to come out of VSOP87's E
 * series, and that series puts the Sun a thousand kilometres away from where DE440 puts the
 * barycentre; the sum of masses over our own series falls to a tenth of that.
 *
 * They are ten numbers and they could be typed in by hand, and they are not, by the rule that
 * holds for everything in `resources/astro`: there is not a single coefficient here transcribed
 * by a person. A GM copied wrong gives no error, it gives a shifted barycentre that nobody sees.
 * They are downloaded from the ASCII header of DE440 that the JPL publishes, which is the
 * ephemeris the positions are compared against, and they are written with all their digits
 * exactly as they come.
 *
 * The header is a Fortran file: group 1040 lists the NAMES of the constants and group 1041 their
 * VALUES, in the same order and in `D` notation (0.4912D-10). They are matched by position, so the
 * count of each group has to agree with the one it declares, and that is checked before anything
 * is written.
 *
 * What gets saved: the GM of the eight planets, of the Earth-Moon system (GMB) and of Pluto (GM9,
 * which in DE440 is the Pluto-Charon system), the Sun's (GMS), and EMRAT, Earth's mass divided by
 * the Moon's, which is what separates the Earth from its barycentre with the Moon. The GM go in
 * AU³/day², the header's unit; what gets used are ratios between them, so the unit never enters
 * any calculation.
 */
final class MassTable
{
    /** The ASCII header of the ephemeris, where every figure in the table comes from. */
    public const HEADER = 'https://ssd.jpl.nasa.gov/ftp/eph/planets/ascii/de440/header.440';

    /**
     * Which header constant each body is, with the name the engine calls it by.
     *
     * `earth-moon` and not `earth`: GMB is the whole system's, and the Earth alone comes out of it
     * with EMRAT. Pluto is its system's barycentre, the same as the position table that is asked
     * of Horizons (`Body::horizonsId`).
     */
    private const BODIES = [
        'sun' => 'GMS',
        'mercury' => 'GM1',
        'venus' => 'GM2',
        'earth-moon' => 'GMB',
        'mars' => 'GM4',
        'jupiter' => 'GM5',
        'saturn' => 'GM6',
        'uranus' => 'GM7',
        'neptune' => 'GM8',
        'pluto' => 'GM9',
    ];

    /**
     * Downloads the header, writes `masses.php` and says what went into it.
     *
     * The values come back as they are written in the header, as strings: that is what goes into
     * the file, with all its digits and not one rounded. The `sun_ratio` of each body is the only
     * thing computed here, and it is there to be looked at: the Sun over Jupiter is 1047, and a
     * constant that landed in the wrong row shows up in that number at a glance.
     *
     * @param HttpClient|null $http
     * @param string $source The ephemeris header.
     * @return array{ephemeris: string, path: string, au: string, emrat: string, bodies: array<string, array{constant: string, value: string, sun_ratio: float}>}
     *
     * @throws RuntimeException If the header does not arrive, or does not carry the two groups
     *                          with the count they declare, in which case nothing is written.
     */
    public static function regenerate(?HttpClient $http = null, string $source = self::HEADER): array
    {
        $http ??= new NativeHttpClient();

        $raw = $http->get($source);

        $names = self::group($raw, '1040');
        $values = self::group($raw, '1041');

        if ($names === null || $values === null) {
            throw new RuntimeException('The header does not carry groups 1040 (names) and 1041 (values) with the count they declare.');
        }

        if (count($names) !== count($values)) {
            throw new RuntimeException(sprintf('There are %d names and %d values: they cannot be matched by position.', count($names), count($values)));
        }

        $constants = array_combine($names, $values);

        foreach (array_merge(array_values(self::BODIES), ['EMRAT', 'AU', 'DENUM']) as $name) {
            if (! isset($constants[$name])) {
                throw new RuntimeException("The header does not carry {$name}.");
            }
        }

        $path = DataFolder::path('masses.php');
        file_put_contents($path, self::render($constants));

        $bodies = [];

        foreach (self::BODIES as $body => $name) {
            $bodies[$body] = [
                'constant' => $name,
                'value' => $constants[$name],
                'sun_ratio' => (float) $constants['GMS'] / (float) $constants[$name],
            ];
        }

        return [
            'ephemeris' => sprintf('DE%d', (int) (float) $constants['DENUM']),
            'path' => $path,
            'au' => $constants['AU'],
            'emrat' => $constants['EMRAT'],
            'bodies' => $bodies,
        ];
    }

    /**
     * The entries of one group of the header, checked against the count it declares.
     *
     * A group is «GROUP   1040», a line saying how many entries it carries, and then the entries
     * several to a line until the next «GROUP». The values come back already in `E` notation,
     * which is the one PHP understands, and with not a digit touched.
     *
     * @param string $raw
     * @param string $number
     * @return list<string>|null
     */
    private static function group(string $raw, string $number): ?array
    {
        $start = strpos($raw, "GROUP   {$number}");

        if ($start === false) {
            return null;
        }

        $end = strpos($raw, 'GROUP', $start + 5);
        $block = substr($raw, $start, $end === false ? null : $end - $start);

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block)), fn (string $l) => $l !== ''));

        // The first line is the «GROUP» and the second one the count.
        $declared = (int) ($lines[1] ?? 0);
        $entries = [];

        foreach (array_slice($lines, 2) as $line) {
            foreach (preg_split('/\s+/', $line) as $entry) {
                // Only the numbers change letter: in the names group a D is a D, and converting it
                // left DENUM as EENUM and the check further up with nothing to find.
                $entries[] = preg_match('/^[-+]?\d*\.\d+D[-+]\d+$/', $entry)
                    ? str_replace('D', 'E', $entry)
                    : $entry;
            }
        }

        if ($declared === 0 || count($entries) !== $declared) {
            return null;
        }

        return $entries;
    }

    /**
     * @param array<string, string> $constants
     * @return string
     */
    private static function render(array $constants): string
    {
        $php = "<?php\n\n";
        $php .= "/*\n";
        $php .= " * Masses of the solar system · DE".(int) (float) $constants['DENUM']."\n";
        $php .= " *\n";
        $php .= " * GENERATED. Do not edit by hand: written by `astronomy masses`\n";
        $php .= " * in this package from the ASCII header of the ephemeris the JPL publishes, and the figures\n";
        $php .= " * go in exactly as they appear there, with all their digits. Next to each one, the name\n";
        $php .= " * the header calls it by, so it can be checked at a glance.\n";
        $php .= " *\n";
        $php .= " * `gm`: the gravitational parameters GM in AU³/day², the header's unit. What actually\n";
        $php .= " * gets used are ratios between them, so the unit never enters any calculation. The\n";
        $php .= " * `earth-moon` one is the SYSTEM's, not Earth's alone: to split the two there is\n";
        $php .= " * `emrat`, Earth's mass divided by the Moon's. Pluto is the Pluto-Charon system.\n";
        $php .= " * `au`: kilometers in one astronomical unit, from the same header.\n";
        $php .= " */\n\n";
        $php .= "return [\n";
        $php .= sprintf("    'ephemeris' => 'DE%d',\n", (int) (float) $constants['DENUM']);
        $php .= sprintf("    'au' => %s, // AU\n", $constants['AU']);
        $php .= sprintf("    'emrat' => %s, // EMRAT\n", $constants['EMRAT']);
        $php .= "    'gm' => [\n";

        foreach (self::BODIES as $body => $name) {
            $php .= sprintf("        '%s' => %s, // %s\n", $body, $constants[$name], $name);
        }

        $php .= "    ],\n];\n";

        return $php;
    }
}
