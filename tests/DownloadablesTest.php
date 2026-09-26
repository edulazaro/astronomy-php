<?php

namespace Astronomy\Tests;

use Astronomy\Asteroid;
use Astronomy\Comet;
use Astronomy\Body;
use Astronomy\DataFolder;
use Astronomy\Downloadables;
use Astronomy\MissingData;
use Astronomy\DownloadableGroup;
use Astronomy\SatelliteList;
use Astronomy\Satellite;
use Astronomy\Time;
use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * The bodies that get downloaded from the JPL: what is asked of it, what file it lands in
 * and who decides what can be downloaded. None of this goes to the network.
 */
class DownloadablesTest extends TestCase
{
    protected function tearDown(): void
    {
        /* The split into files is static, so a test that changes it leaves it set for
           the next one, and the next one looks for a filename nobody wrote. It is reset
           to each group's default values. */
        Downloadables::useYearsPerFile([]);

        parent::tearDown();
    }

    public function test_by_default_nothing_is_downloaded_and_the_split_is_each_groups_own(): void
    {
        /* With nothing downloaded, asking for any of the three says what is missing.
           There is no allowlist to consult: the package has no opinion on what can be
           downloaded. */
        $june1985 = Time::civilJulianDay(1985, 6, 10);

        foreach ([Satellite::Io, Asteroid::number(136199), Comet::designation('1P')] as $body) {
            $missing = $this->missing(fn () => Downloadables::path($body, $june1985));
            $this->assertSame($body, $missing->body);
        }

        $this->assertNull(Downloadables::yearsPerFile(DownloadableGroup::Asteroids));
        $this->assertSame(1, Downloadables::yearsPerFile(DownloadableGroup::Satellites));
        $this->assertSame(1, Downloadables::yearsPerFile(DownloadableGroup::Comets));
    }

    /**
     * The count is that of the JPL's list of 14 September 2026. Regenerating with
     * `astro:satelites` can change it, and then it is changed here knowingly.
     */
    public function test_the_satellites_are_the_ones_from_the_jpl_list_and_each_one_knows_whose_it_is(): void
    {
        $systems = [Body::Mars, Body::Jupiter, Body::Saturn, Body::Uranus, Body::Neptune, Body::Pluto];

        $this->assertCount(458, Satellite::cases());
        $this->assertSame([2, 115, 292, 28, 16, 5], array_map(fn (Body $planet) => count(Satellite::of($planet)), $systems));

        $this->assertSame([Satellite::Phobos, Satellite::Deimos], Satellite::of(Body::Mars));
        $this->assertSame([Satellite::Charon, Satellite::Nix, Satellite::Hydra, Satellite::Kerberos, Satellite::Styx], Satellite::of(Body::Pluto));
        $this->assertSame(Body::Jupiter, Satellite::Io->planet());
        $this->assertSame(Body::Jupiter, Satellite::S2003_J2->planet());
        $this->assertSame(Body::Saturn, Satellite::Titan->planet());
        $this->assertSame(Body::Uranus, Satellite::S2023_U1->planet());
        $this->assertSame(Body::Neptune, Satellite::Triton->planet());

        // «It ends in 99, it is the planet» only holds with three digits: 65199 is S/2019 S 42.
        foreach (Satellite::cases() as $satellite) {
            $this->assertContains($satellite->planet(), $systems);
            $this->assertFalse($satellite->value < 1000 && $satellite->value % 100 === 99, "{$satellite->name} is a planet, not a satellite");
        }

        $this->assertSame(65199, Satellite::S2019_S42->value);
    }

    /**
     * The centre is the barycentre of the system, and the same one `Body` uses for the
     * planet.
     */
    public function test_a_satellite_is_asked_for_from_the_barycentre_of_its_system(): void
    {
        $this->assertSame('501', Satellite::Io->horizonsId());
        $this->assertSame('500@5', Satellite::Io->horizonsCenter());
        $this->assertSame('500@5', Satellite::S2003_J2->horizonsCenter());
        $this->assertSame('500@9', Satellite::Charon->horizonsCenter());

        foreach (Satellite::cases() as $satellite) {
            $this->assertSame('500@'.$satellite->planet()->horizonsId(), $satellite->horizonsCenter());
        }
    }

    public function test_the_provisionals_are_written_the_way_the_iau_writes_them(): void
    {
        $this->assertSame('Io', Satellite::Io->name());
        $this->assertSame('Ganymede', Satellite::Ganymede->name());
        $this->assertSame('S/2003 J 2', Satellite::S2003_J2->name());
        $this->assertSame('S/2004 S 34', Satellite::S2004_S34->name());
        $this->assertSame('S/2023 U 1', Satellite::S2023_U1->name());
    }

    /**
     * Rows copied from the real response, with their widths: satellites with a name,
     * with no name and with the designation in the wrong column, planets, our own
     * Moon, barycentres and spacecraft.
     */
    public function test_the_jpl_list_is_read_by_columns(): void
    {
        $this->assertSame([
            ['id' => 401, 'case' => 'Phobos'],
            ['id' => 501, 'case' => 'Io'],
            ['id' => 517, 'case' => 'Callirrhoe'],
            ['id' => 55501, 'case' => 'S2003_J2'],
            ['id' => 664, 'case' => 'S2004_S34'],
            ['id' => 75051, 'case' => 'S2023_U1'],
            ['id' => 85051, 'case' => 'S2002_N5'],
            ['id' => 901, 'case' => 'Charon'],
        ], SatelliteList::read(self::listing(self::ROWS)));
    }

    /**
     * What does not give an error but a shorter enum: a table cut short, a system left
     * with no satellites, or a repeated name.
     *
     * **And WHICH guard trips is checked, not just that one of them does.** Four guards
     * and four cases: asking only for an exception, a case can be slipping through its
     * neighbour's guard while its own is switched off with nothing saying so. That is
     * exactly what happened here: the one for the repeated name looked for duplicates
     * with `array_column($satellites, 'caso')` when the key is `'case'`, and
     * `array_column` over a key that does not exist gives no error, it returns an
     * empty array. So it **never found a duplicate**, and the test still stayed green.
     */
    public function test_a_cut_or_mismatched_list_throws(): void
    {
        $noCharon = array_values(array_filter(self::ROWS, fn (string $row) => ! str_contains($row, 'Charon')));
        $withIoRepeated = [...self::ROWS, '      518  Io                                              JXVIII'];

        foreach ([
            'mismatched count' => [self::listing(self::ROWS, count(self::ROWS) + 1), 'the table is cut short'],
            'Pluto with no satellites' => [self::listing($noCharon), 'System 9 has been left without satellites'],
            'repeated name' => [self::listing($withIoRepeated), 'The name «Io» appears twice'],
            'no table' => ['No matches found.', 'does not carry the Horizons major bodies table'],
        ] as $case => [$text, $message]) {
            try {
                SatelliteList::read($text);
                $this->fail("Should have thrown: {$case}");
            } catch (RuntimeException $error) {
                $this->assertStringContainsString(
                    $message,
                    $error->getMessage(),
                    "«{$case}» throws, but through a different guard: its own might be switched off"
                );
            }
        }
    }

    /**
     * Regenerating with the same list gives the same file byte for byte, and that also
     * checks that nobody has touched by hand what is between the markers.
     */
    public function test_the_cases_go_between_the_markers_and_regenerating_changes_nothing(): void
    {
        $code = (string) file_get_contents((string) (new ReflectionClass(Satellite::class))->getFileName());
        $satellites = array_map(fn (Satellite $satellite) => ['id' => $satellite->value, 'case' => $satellite->name], Satellite::cases());

        $this->assertSame($code, SatelliteList::withCases($code, $satellites));

        $this->expectException(RuntimeException::class);
        SatelliteList::withCases('<?php enum Satellite: int {}', $satellites);
    }

    public function test_an_asteroid_goes_by_number_or_by_designation(): void
    {
        $eris = Asteroid::number(136199);

        $this->assertSame('136199;', $eris->horizonsId());
        $this->assertSame('(136199)', $eris->name());
        $this->assertSame('downloads/asteroids/136199.bin', $eris->file(2451545.0));

        $yr4 = Asteroid::designation('  2024   yr4 ');

        $this->assertSame('2024 YR4', $yr4->designation);
        $this->assertNull($yr4->number);
        $this->assertSame('DES=2024 YR4;', $yr4->horizonsId());
        $this->assertSame('2024 YR4', $yr4->name());
        $this->assertSame('downloads/asteroids/2024_YR4.bin', $yr4->file(2451545.0));

        $this->assertSame('2040 P-L', Asteroid::designation('2040 p-l')->designation);

        $this->assertThrows(fn () => Asteroid::number(0));
        $this->assertThrows(fn () => Asteroid::designation('Eris'));
        $this->assertThrows(fn () => Asteroid::designation('136199'));
        $this->assertThrows(fn () => Asteroid::designation('../../etc'));
    }

    public function test_a_comet_goes_by_its_designation(): void
    {
        $halley = Comet::designation('1p/Halley');

        $this->assertSame('1P', $halley->designation);
        $this->assertSame('DES=1P;CAP;NOFRAG;', $halley->horizonsId());
        $this->assertSame('downloads/comets/1P/1986.bin', $halley->file(Time::civilJulianDay(1986, 2, 9)));

        $haleBopp = Comet::designation('C/1995 O1');

        $this->assertSame('DES=C/1995 O1;CAP;NOFRAG;', $haleBopp->horizonsId());
        $this->assertSame('downloads/comets/C_1995_O1/1997.bin', $haleBopp->file(Time::civilJulianDay(1997, 4, 1)));

        $this->assertSame('73P-B', Comet::designation('73P-B')->designation);
        $this->assertSame('C/2019 Y4-B', Comet::designation('c/2019 y4-b')->designation);

        $this->assertThrows(fn () => Comet::designation('Halley'));
        $this->assertThrows(fn () => Comet::designation('C/1995'));
        $this->assertThrows(fn () => Comet::designation('../1P'));
    }

    /**
     * The chunks are aligned to the calendar, and null means the whole table even in a
     * group that by default goes by years: with `??` it would silently fall back to
     * one year.
     */
    public function test_the_files_split_by_years_aligned_to_the_calendar(): void
    {
        $june1985 = Time::civilJulianDay(1985, 6, 10);

        $this->assertSame('downloads/satellites/501/1985.bin', Satellite::Io->file($june1985));

        Downloadables::useYearsPerFile(['satellites' => 10, 'asteroids' => '100']);

        $this->assertSame('downloads/satellites/501/1980-1989.bin', Satellite::Io->file($june1985));
        $this->assertSame('downloads/satellites/501/1980-1989.bin', Satellite::Io->file(Time::civilJulianDay(1989, 12, 31.9)));
        $this->assertSame('downloads/satellites/501/1990-1999.bin', Satellite::Io->file(Time::civilJulianDay(1990, 1, 1)));
        $this->assertSame('downloads/asteroids/136199/1900-1999.bin', Asteroid::number(136199)->file($june1985));
        $this->assertSame(1, Downloadables::yearsPerFile(DownloadableGroup::Comets));

        Downloadables::useYearsPerFile(['satellites' => null]);

        $this->assertSame('downloads/satellites/501.bin', Satellite::Io->file($june1985));
    }

    /**
     * The split into files is validated, which is the only thing left configurable.
     *
     * And it is validated because it is format: a misspelled group or a zero for
     * years would leave the downloader and the reader looking for different
     * filenames, with no error at all.
     */
    public function test_the_split_into_files_is_validated(): void
    {
        Downloadables::useYearsPerFile(['satellites' => 10]);
        $this->assertSame(10, Downloadables::yearsPerFile(DownloadableGroup::Satellites));

        foreach ([
            ['moons' => 1],
            ['satellites' => 0],
            ['satellites' => -5],
        ] as $bad) {
            try {
                Downloadables::useYearsPerFile($bad);
                $this->fail('Should have thrown: '.var_export($bad, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * And the package has NO allowlist and downloads nothing on its own.
     *
     * The two things used to be inside and were taken out: they are decisions of the
     * application, because they depend on something only it knows, whether it is
     * serving a page or running a script. What the package provides is the tool and
     * the notice of what is missing. This fixes that so nobody brings them back out
     * of convenience.
     */
    public function test_the_package_does_not_decide_what_gets_downloaded_or_when(): void
    {
        foreach (['configure', 'chosen', 'allows', 'onTheFly'] as $gone) {
            $this->assertFalse(
                method_exists(Downloadables::class, $gone),
                "«{$gone}» is a decision of the application and cannot come back to the package"
            );
        }

        // A body nobody has listed anywhere: what is missing is the file, not the permission.
        $missing = $this->missing(fn () => Downloadables::path(Satellite::Europa, Time::civilJulianDay(1985, 6, 10)));

        $this->assertSame(Satellite::Europa, $missing->body);
        $this->assertStringContainsString('Downloader', $missing->getMessage());
        $this->assertStringNotContainsString('configuration', $missing->getMessage());
    }

    /**
     * The message distinguishes what is missing to download from what cannot even be
     * downloaded.
     */
    public function test_if_the_file_is_missing_it_says_how_to_get_it(): void
    {
        $june1985 = Time::civilJulianDay(1985, 6, 10);

        $missing = $this->missing(fn () => Downloadables::path(Satellite::Io, $june1985));

        $this->assertSame(Satellite::Io, $missing->body);
        $this->assertSame('downloads/satellites/501/1985.bin', $missing->dataFile);
        $this->assertStringContainsString('The data for Io in 1985 is missing', $missing->getMessage());
        $this->assertStringContainsString('Downloader', $missing->getMessage());
        $this->assertStringNotContainsString('configuration', $missing->getMessage());
    }

    /**
     * The list grants permission to go to the JPL, not to read: what is already on
     * disk gets used.
     */
    public function test_a_file_that_is_already_there_is_used_without_asking_anybody(): void
    {
        $folder = self::temporaryFolder();
        mkdir("{$folder}/downloads/asteroids", 0755, true);
        file_put_contents("{$folder}/downloads/asteroids/136199.bin", 'x');

        $class = new ReflectionClass(DataFolder::class);
        $fixed = $class->getProperty('folder');
        $used = $class->getProperty('used');
        [$beforeFixed, $beforeUsed] = [$fixed->getValue(), $used->getValue()];

        try {
            $used->setValue(null, false);
            DataFolder::useFolder($folder);

            /* It is on disk, so it gets used. There is no permission to consult and
               no download to trigger: reading is reading, and what cost something and
               could be abused was going to the JPL. */
            $this->assertSame("{$folder}/downloads/asteroids/136199.bin", Downloadables::path(Asteroid::number(136199), 2451545.0));
        } finally {
            $fixed->setValue(null, $beforeFixed);
            $used->setValue(null, $beforeUsed);
            self::delete($folder);
        }
    }

    /**
     * Downloaded data lives deeper than the fingerprint looks, so downloading a body
     * does not invalidate the charts' caches. And the fingerprint does still see a new
     * table, which is what it has to see. It runs in a separate PHP process because
     * the fingerprint is remembered in a static per process.
     */
    public function test_downloaded_data_does_not_change_the_data_fingerprint(): void
    {
        $folder = self::temporaryFolder();
        file_put_contents("{$folder}/nutation.php", '<?php return [];');

        $fingerprint = fn () => trim((string) shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
            'require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';'
            .'Astronomy\DataFolder::useFolder('.var_export($folder, true).');'
            .'echo Astronomy\Ephemeris::dataFingerprint();'
        ).' 2>&1'));

        try {
            $before = $fingerprint();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $before);

            mkdir("{$folder}/downloads/asteroids", 0755, true);
            mkdir("{$folder}/downloads/satellites/501", 0755, true);
            file_put_contents("{$folder}/downloads/asteroids/136199.bin", 'x');
            file_put_contents("{$folder}/downloads/satellites/501/1985.bin", 'x');

            $this->assertSame($before, $fingerprint());

            mkdir("{$folder}/tables");
            file_put_contents("{$folder}/tables/eris.php", '<?php return [];');

            $this->assertNotSame($before, $fingerprint());
        } finally {
            self::delete($folder);
        }
    }

    /** Real rows from Horizons' response to `COMMAND='MB'`. */
    private const ROWS = [
        '        0  Solar System Barycenter                         SSB',
        '       10  Sun                                             Sol',
        '      301  Moon                                            Luna',
        '      401  Phobos                                          MI',
        '      499  Mars',
        '      501  Io                                              JI',
        '      517  Callirrhoe                         S1999_J1     JXVII',
        '    55501                                     S2003_J2     2003J2 55060',
        '      664                                     S2004_S34    65076 2004S34',
        '    75051  2023U1',
        '    85051  S2002_N5                                        2002N5',
        '      901  Charon                                          PI',
        '120612095  1999 OJ4 1',
        '-54054450  Surveyor-2 Centaur RB              1966-084B    2020 SO',
    ];

    /**
     * @param list<string> $rows
     * @param int|null $count What Horizons says at the end; by default, however many rows there are.
     * @return string
     */
    private static function listing(array $rows, ?int $count = null): string
    {
        return implode("\n", [
            '*******************************************************************************',
            ' Multiple major-bodies match string "*"',
            '',
            '  ID#      Name                               Designation  IAU/aliases/other   ',
            '  -------  ---------------------------------- -----------  ------------------- ',
            ...$rows,
            ' ',
            sprintf('   Number of matches = %11d . Use ID# to make unique selection.', $count ?? count($rows)),
            '*******************************************************************************',
        ]);
    }

    /**
     * @param Closure $call
     * @return void
     */
    private function assertThrows(Closure $call): void
    {
        try {
            $call();
            $this->fail('Should have thrown');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @param Closure $call
     * @return MissingData
     */
    private function missing(Closure $call): MissingData
    {
        try {
            $call();
        } catch (MissingData $missing) {
            return $missing;
        }

        $this->fail('The file should be missing');
    }

    /**
     * @return string
     */
    private static function temporaryFolder(): string
    {
        $folder = sys_get_temp_dir().'/astro-downloadables-'.bin2hex(random_bytes(4));
        mkdir($folder);

        return $folder;
    }

    /**
     * @param string $folder
     * @return void
     */
    private static function delete(string $folder): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($folder);
    }
}
