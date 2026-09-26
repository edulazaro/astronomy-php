<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\DataFolder;
use Astronomy\Ephemeris;
use Astronomy\Time;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use PHPUnit\Framework\TestCase;

/**
 * The engine's data folder: where it reads its series and tables from, and that this does not
 * need Laravel.
 */
class DataFolderTest extends TestCase
{
    /**
     * With nothing configured it is the project's `resources/astro`, and the application sets it
     * to that same place on boot.
     */
    public function test_by_default_it_is_the_repositorys(): void
    {
        /* The default folder is the one the PACKAGE ships, deduced from where the class itself
           lives. In Tarotian there is no longer a copy of the data: there used to be, and it was
           nine megabytes duplicated that could drift apart. */
        /* The check is made by a path that is NOT the method: where the class's own file sits,
           which is how `DataFolder` deduces it, but written here separately. Asking it for the
           folder and comparing it against itself would be an assertion that cannot fail. */
        $fromThePackage = dirname((string) (new ReflectionClass(DataFolder::class))->getFileName(), 2).'/resources/astro';

        $this->assertSame(realpath($fromThePackage), realpath(DataFolder::folder()));
        $this->assertSame(DataFolder::folder().'/positions/pluto.php', DataFolder::path('positions/pluto.php'));
        $this->assertFileExists(DataFolder::path('positions/pluto.php'));
    }

    public function test_a_folder_that_does_not_exist_blows_up(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DataFolder::useFolder('/this/folder/does/not/exist');
    }

    /**
     * Changing folder after something has already been read blows up, because the classes
     * remember what they read and data from both would get mixed. Setting the same one again is
     * allowed: it is what happens every time the application boots again within the same
     * process.
     */
    public function test_it_does_not_change_mid_process(): void
    {
        Ephemeris::position(Body::Pluto, 2451545.0);

        // The one there is BEFORE trying to change it, which is what is compared against
        // afterwards.
        $before = realpath(DataFolder::folder());

        /* The same one already in use, which is the package's: that is allowed and is what
           happens every time the application boots again within the same process. */
        DataFolder::useFolder(DataFolder::folder());
        $this->addToAssertionCount(1);

        $another = sys_get_temp_dir();

        try {
            DataFolder::useFolder($another);
            $this->fail('Changing folder after reading data should have blown up');
        } catch (LogicException) {
            // And the one there was is still the one there was: blowing up cannot leave it
            // half-changed.
            $this->assertSame($before, realpath(DataFolder::folder()));
        }
    }

    /**
     * And a folder set before reading anything is the one that gets used. Checked with the
     * state set by hand and put back afterwards, so as not to leave the rest of the suite
     * reading from somewhere else.
     */
    public function test_the_folder_set_before_reading_is_the_one_used(): void
    {
        $reflection = new ReflectionClass(DataFolder::class);
        $folderProperty = $reflection->getProperty('folder');
        $usedProperty = $reflection->getProperty('used');
        [$previousFolder, $previousUsed] = [$folderProperty->getValue(), $usedProperty->getValue()];

        try {
            $usedProperty->setValue(null, false);
            DataFolder::useFolder(sys_get_temp_dir());

            $this->assertSame(rtrim(sys_get_temp_dir(), '/').'/positions/pluto.php', DataFolder::path('positions/pluto.php'));
        } finally {
            $folderProperty->setValue(null, $previousFolder);
            $usedProperty->setValue(null, $previousUsed);
        }
    }

    /**
     * The proof that the engine no longer needs Laravel to read its data: a separate PHP
     * process, with only Composer's autoload and without booting the application, computes
     * Pluto (JPL table), the Moon (ELP with its correction) and delta T, and it has to give the
     * same numbers, bit for bit, as inside.
     */
    public function test_the_engine_reads_its_data_without_laravel(): void
    {
        $jd = 2451545.0;

        $code = 'require '.var_export(dirname(__DIR__).'/vendor/autoload.php', true).';'
            .'use Astronomy\{Body, Ephemeris, Time};'
            .'echo json_encode([Ephemeris::position(Body::Pluto, '.$jd.')->longitude, Ephemeris::position(Body::Moon, '.$jd.')->longitude, Time::deltaT('.$jd.')]);';

        $output = shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code).' 2>&1');
        $result = json_decode((string) $output, true);

        $this->assertIsArray($result, "The PHP without Laravel did not return numbers: {$output}");
        $this->assertSame(
            [Ephemeris::position(Body::Pluto, $jd)->longitude, Ephemeris::position(Body::Moon, $jd)->longitude, Time::deltaT($jd)],
            $result
        );
    }
}
