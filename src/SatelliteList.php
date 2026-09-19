<?php

namespace Astronomy;

use ReflectionClass;
use RuntimeException;

/**
 * Writes the `Satellite` cases from the JPL Horizons major bodies list. Pure PHP: in a framework application it is
 * a console command that calls it, and on its own `SatelliteList::regenerate()` is enough.
 *
 * It is what Horizons answers to `COMMAND='MB'`: a fixed-width table with the identifier, the name,
 * the designation and the aliases of everything it treats as a major body, which is planets,
 * satellites, barycentres and the odd spacecraft. Out of that the satellites are kept, and they are
 * recognised by the identifier: three digits from 401 to 998 leaving out the ones ending in 99, which
 * are the planets themselves, plus the five-digit provisional ones with a 5 after the system digit
 * (55501 is Jupiter's). Our Moon, 301, does not go in: it is `Body::Moon`. And careful, «ends in 99, it
 * is the planet» only holds with three digits: 65199 is S/2019 S 42.
 *
 * The columns are read by the position of the header and not by splitting on spaces, because there
 * are satellites with no name and their designation would fall into the name column. And even so the
 * list is not uniform: some provisional ones carry the designation in its own column (`S2004_S34`),
 * others in the name one (`S2002_N5`) and one carries it in the name column without the S or the
 * underscore (`2023U1`). The name is taken if there is one and the designation if not, and the form
 * without the S is completed.
 *
 * Before writing, what would give no error but a shorter enum is checked: that the number of rows read
 * is the «Number of matches» Horizons writes at the end, that every system from Mars to Pluto has some
 * satellite, and that no name is repeated.
 */
final class SatelliteList
{
    /**
     * Downloads the list, rewrites the `Satellite` cases and says what has changed.
     *
     * @param HttpClient|null $http
     * @return array{satellites: list<array{id: int, case: string}>, added: list<string>, removed: list<string>}
     *
     * @throws RuntimeException
     */
    public static function regenerate(?HttpClient $http = null): array
    {
        $satellites = self::read((new Horizons($http ?? new NativeHttpClient()))->majorBodies());
        $file = (string) (new ReflectionClass(Satellite::class))->getFileName();
        $before = array_map(fn (Satellite $satellite) => $satellite->name, Satellite::cases());

        file_put_contents($file, self::withCases((string) file_get_contents($file), $satellites));

        $after = array_column($satellites, 'case');

        return [
            'satellites' => $satellites,
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ];
    }

    /**
     * The satellites from the major bodies list, sorted by system and by identifier.
     *
     * @param string $text The Horizons response to `COMMAND='MB'`.
     * @return list<array{id: int, case: string}>
     *
     * @throws RuntimeException If the table is not there, comes cut short or something does not add up.
     */
    public static function read(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $header = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, 'ID#') && str_contains($line, 'Designation')) {
                $header = $index;
                break;
            }
        }

        $nameColumn = $header === null ? false : strpos($lines[$header], 'Name');
        $designationColumn = $header === null ? false : strpos($lines[$header], 'Designation');
        $aliasColumn = $header === null ? false : strpos($lines[$header], 'IAU');

        if ($nameColumn === false || $designationColumn === false || $aliasColumn === false
            || ! preg_match('/Number of matches =\s*(\d+)/', $text, $countMatch)) {
            throw new RuntimeException('The response does not carry the Horizons major bodies table, or it has changed shape.');
        }

        $rows = 0;
        $satellites = [];

        // After the header comes a line of dashes.
        for ($index = $header + 2; $index < count($lines); $index++) {
            $line = $lines[$index];

            if (str_contains($line, 'Number of matches')) {
                break;
            }

            $id = trim(substr($line, 0, $nameColumn));

            if ($id === '') {
                continue;
            }

            if (! preg_match('/^-?\d+$/', $id)) {
                throw new RuntimeException("There is a row without an identifier in the table: «{$line}».");
            }

            $rows++;

            if (! preg_match('/^[4-9](0[1-9]|[1-8]\d|9[0-8]|5\d{3})$/', $id)) {
                continue;
            }

            $case = trim(substr($line, $nameColumn, $designationColumn - $nameColumn))
                ?: trim(substr($line, $designationColumn, $aliasColumn - $designationColumn));

            if (preg_match('/^(\d{4})([A-Z]\d+)$/', $case, $parts)) {
                $case = "S{$parts[1]}_{$parts[2]}";
            }

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $case)) {
                throw new RuntimeException("Satellite {$id} is called «{$case}», which does not work as a case name.");
            }

            if (in_array($case, array_column($satellites, 'case'), true)) {
                throw new RuntimeException("The name «{$case}» appears twice in the list.");
            }

            $satellites[] = ['id' => (int) $id, 'case' => $case];
        }

        if ($rows !== (int) $countMatch[1]) {
            throw new RuntimeException(sprintf(
                '%d rows were read and Horizons says there are %d: the table is cut short or has changed shape.',
                $rows, $countMatch[1]
            ));
        }

        foreach (range(4, 9) as $system) {
            $has = array_filter($satellites, fn (array $satellite) => ((string) $satellite['id'])[0] === (string) $system);

            if ($has === []) {
                throw new RuntimeException("System {$system} has been left without satellites, and every one from Mars to Pluto has some.");
            }
        }

        usort($satellites, fn (array $a, array $b) => [((string) $a['id'])[0], $a['id']] <=> [((string) $b['id'])[0], $b['id']]);

        return $satellites;
    }

    /**
     * The `Satellite` code with the cases put between its two markers.
     *
     * The markers are looked up with `strpos` and not with a lazy regular expression, for the reason
     * already recorded in `GenerarTablaEfemerides`. Regenerating with no changes in the list gives the
     * same bytes.
     *
     * @param string $code
     * @param list<array{id: int, case: string}> $satellites
     * @return string
     *
     * @throws RuntimeException If the markers are missing.
     */
    public static function withCases(string $code, array $satellites): string
    {
        $opening = strpos($code, '// <cases>');
        $closing = strpos($code, '// </cases>');

        if ($opening === false || $closing === false || $closing < $opening) {
            throw new RuntimeException('The // <cases> and // </cases> markers are not in the Satellite file.');
        }

        $openingEnd = strpos($code, "\n", $opening);
        $closingStart = strrpos(substr($code, 0, $closing), "\n");

        $cases = '';
        $previousPlanet = null;

        foreach ($satellites as $satellite) {
            $planet = self::planet($satellite['id']);

            if ($planet !== $previousPlanet) {
                $cases .= ($previousPlanet === null ? '' : "\n")."    // {$planet->name()}\n";
                $previousPlanet = $planet;
            }

            $cases .= "    case {$satellite['case']} = {$satellite['id']};\n";
        }

        return substr($code, 0, $openingEnd + 1).$cases.substr($code, $closingStart + 1);
    }

    /**
     * The planet of an identifier, from its first digit, which is the same thing `Satellite::planet` does.
     *
     * @param int $id
     * @return Body
     */
    public static function planet(int $id): Body
    {
        foreach (Body::cases() as $body) {
            if ($body->horizonsId() === ((string) $id)[0]) {
                return $body;
            }
        }

        throw new RuntimeException("Identifier {$id} does not belong to any system the engine knows.");
    }
}
