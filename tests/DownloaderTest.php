<?php

namespace Astronomy\Tests;

use Astronomy\Asteroid;
use Astronomy\HttpClient;
use Astronomy\Comet;
use Astronomy\Body;
use Astronomy\DataFolder;
use Astronomy\Downloadables;
use Astronomy\Downloader;
use Astronomy\ReferenceEcliptic;
use Astronomy\Ephemeris;
use Astronomy\MissingData;
use Astronomy\Satellite;
use Astronomy\DownloadedPositions;
use Astronomy\Time;
use Astronomy\PositionType;
use Closure;
use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * The downloader, against a fake Horizons that answers with known orbits: this way it can be
 * checked with no network that the chosen step meets the real tolerance, that it gets finer when
 * it falls short, that a truncated response leaves no gaps, that an empty one gets retried, and
 * that the engine reads back what was written.
 */
class DownloaderTest extends TestCase
{
    private string $folder;

    private mixed $folderBefore;

    private mixed $usedBefore;

    protected function setUp(): void
    {
        parent::setUp();

        // A data folder of its own, with the repository's series symlinked in and a real
        // `downloads` folder, so that whatever gets downloaded does not land in `resources/astro`.
        $this->folder = sys_get_temp_dir().'/astro-downloader-'.bin2hex(random_bytes(4));
        mkdir($this->folder);

        foreach (glob(DataFolder::folder().'/*') as $entry) {
            symlink($entry, $this->folder.'/'.basename($entry));
        }

        $class = new ReflectionClass(DataFolder::class);
        $this->folderBefore = $class->getProperty('folder')->getValue();
        $this->usedBefore = $class->getProperty('used')->getValue();
        $class->getProperty('used')->setValue(null, false);

        DataFolder::useFolder($this->folder);
    }

    protected function tearDown(): void
    {
        $class = new ReflectionClass(DataFolder::class);
        $class->getProperty('folder')->setValue(null, $this->folderBefore);
        $class->getProperty('used')->setValue(null, $this->usedBefore);

        Downloadables::useYearsPerFile([]);
        self::deleteFolder($this->folder);

        parent::tearDown();
    }

    /**
     * The saved step meets the tolerance against the real orbit, and not only at the points it
     * was measured at, and the engine computes the body from what was written.
     */
    public function test_the_chosen_step_meets_the_tolerance_and_the_engine_reads_it(): void
    {
        Downloadables::useYearsPerFile(['asteroids' => 10]);
        $horizons = new FakeHorizons(self::ellipse(...));
        $jd = Time::civilJulianDay(1985, 6, 10);

        $path = Downloader::download(Asteroid::number(1), $jd, http: $horizons, waitSeconds: 0);
        $meta = DownloadedPositions::metadata($path);

        $this->assertSame($this->folder.'/downloads/asteroids/1/1980-1989.bin', $path);
        $this->assertSame(1, $horizons->vectors);
        $this->assertLessThanOrEqual($meta['toleranceAu'], $meta['errorAu']);
        $this->assertGreaterThan(5 * 1440, $meta['stepMinutes'], 'An orbit this smooth should be saved with fewer points than the ones downloaded');
        $this->assertSame('Test (1) {source: test}', $meta['source']);

        [$from, $to] = Downloader::span(Asteroid::number(1), $jd);

        for ($i = 0; $i <= 200; $i++) {
            $instant = $from + ($to - $from) * $i / 200 + 0.37;
            $read = DownloadedPositions::j2000(Asteroid::number(1), $instant);
            $real = self::ellipse($instant);

            $this->assertLessThan(2 * $meta['toleranceAu'], sqrt(($read[0] - $real[0]) ** 2 + ($read[1] - $real[1]) ** 2 + ($read[2] - $real[2]) ** 2));
        }

        $position = Ephemeris::position(Asteroid::number(1), $jd);

        $this->assertEquals(Asteroid::number(1), $position->body);
        $this->assertGreaterThan(1.5, $position->distance);
        $this->assertLessThan(3.8, $position->distance);
        $this->assertNotNull($position->distanceSpeed);
    }

    /**
     * A satellite that goes around in two and a half hours does not fit in the step the download
     * starts with: it is measured that the step falls short, it gets refined, and it is
     * downloaded again. And in the engine it comes back added to its planet.
     */
    public function test_if_the_step_falls_short_it_is_refined_and_a_satellite_adds_to_its_planet(): void
    {
        Downloadables::useYearsPerFile([]);
        $horizons = new FakeHorizons(self::fastSatellite(...));
        $jd = Time::civilJulianDay(1985, 6, 10.3);

        $path = Downloader::download(Satellite::Io, $jd, http: $horizons, waitSeconds: 0);
        $meta = DownloadedPositions::metadata($path);

        $this->assertGreaterThan(1, $horizons->vectors);
        $this->assertLessThan(60, $meta['stepMinutes']);
        $this->assertLessThanOrEqual($meta['toleranceAu'], $meta['errorAu']);
        $this->assertSame('500@5', $horizons->centers[0]);

        $satellite = Ephemeris::heliocentric(Satellite::Io, $jd, PositionType::Geometric, ReferenceEcliptic::J2000)->rectangular();
        $planet = Ephemeris::heliocentric(Body::Jupiter, $jd, PositionType::Geometric, ReferenceEcliptic::J2000)->rectangular();
        $vector = DownloadedPositions::j2000(Satellite::Io, $jd);

        foreach ([0, 1, 2] as $axis) {
            $this->assertEqualsWithDelta($vector[$axis], $satellite[$axis] - $planet[$axis], 1e-9);
        }
    }

    /**
     * Horizons has been known to truncate responses without saying so. With a Horizons that cuts
     * off at a hundred rows, the download picks up where it left off and the series arrives
     * whole.
     */
    public function test_a_truncated_response_is_continued_where_it_left_off(): void
    {
        Downloadables::useYearsPerFile([]);
        $horizons = new FakeHorizons(self::ellipse(...), cap: 100);
        $jd = Time::civilJulianDay(1986, 2, 9);

        $meta = DownloadedPositions::metadata(Downloader::download(Comet::designation('1P'), $jd, http: $horizons, waitSeconds: 0));

        $this->assertGreaterThan(10, $horizons->vectors);
        $this->assertLessThanOrEqual($meta['toleranceAu'], $meta['errorAu']);

        $read = DownloadedPositions::j2000(Comet::designation('1P'), $jd);
        $real = self::ellipse($jd);

        $this->assertEqualsWithDelta($real[0], $read[0], 2 * $meta['toleranceAu']);
        $this->assertEqualsWithDelta($real[1], $read[1], 2 * $meta['toleranceAu']);
    }

    /**
     * Asked many times in a row, the JPL stops answering for a few minutes and does it with a 200
     * and an empty body. That gets retried; what it says with text does not.
     */
    public function test_an_empty_response_is_retried(): void
    {
        Downloadables::useYearsPerFile(['asteroids' => 10]);
        $horizons = new FakeHorizons(self::ellipse(...), empties: 2);

        $meta = DownloadedPositions::metadata(Downloader::download(Asteroid::number(1), 2446226.5, http: $horizons, waitSeconds: 0));

        $this->assertSame(3, $horizons->vectors);
        $this->assertLessThanOrEqual($meta['toleranceAu'], $meta['errorAu']);
    }

    public function test_if_it_never_answers_it_says_so(): void
    {
        Downloadables::useYearsPerFile(['asteroids' => 10]);
        $horizons = new FakeHorizons(self::ellipse(...), empties: 9);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has not answered in 3 attempts');

        Downloader::download(Asteroid::number(1), 2446226.5, http: $horizons, waitSeconds: 0);
    }

    public function test_if_horizons_has_no_data_it_says_what_it_answered(): void
    {
        Downloadables::useYearsPerFile([]);
        $horizons = new FakeHorizons(self::ellipse(...), response: "API VERSION: 1.2\nNo ephemeris for target \"1P/Halley\" prior to A.D. 1600-JAN-01 00:00:00.0000 TT\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No ephemeris for target');

        Downloader::download(Comet::designation('1P'), Time::civilJulianDay(1500, 1, 1), http: $horizons, waitSeconds: 0);
    }

    /**
     * The uncertainty the JPL declares goes inside the file, and it is only asked for asteroids:
     * satellites and comets answer that they do not know it.
     */
    public function test_the_jpls_uncertainty_goes_inside_the_file(): void
    {
        Downloadables::useYearsPerFile(['asteroids' => 10]);

        $ofTheAsteroid = new FakeHorizons(self::ellipse(...), uncertainty: 12.5);
        $meta = DownloadedPositions::metadata(Downloader::download(Asteroid::number(1), 2446226.5, http: $ofTheAsteroid, waitSeconds: 0));

        $this->assertSame(1, $ofTheAsteroid->uncertainties);
        $this->assertSame(12.5, $meta['uncertaintySeconds']);

        $ofTheSatellite = new FakeHorizons(self::fastSatellite(...));
        $meta = DownloadedPositions::metadata(Downloader::download(Satellite::Io, 2446226.5, http: $ofTheSatellite, waitSeconds: 0));

        $this->assertSame(0, $ofTheSatellite->uncertainties);
        $this->assertNull($meta['uncertaintySeconds']);
    }

    /**
     * It is one extra fact about data that is already downloaded: if the JPL blows up computing
     * it, as happens with Apophis at eight hundred years, the download still goes through and
     * the uncertainty comes back null.
     */
    public function test_if_the_uncertainty_fails_the_download_still_goes_through(): void
    {
        Downloadables::useYearsPerFile(['asteroids' => 10]);
        $horizons = new FakeHorizons(self::ellipse(...), uncertaintyFails: true);

        $meta = DownloadedPositions::metadata(Downloader::download(Asteroid::number(1), 2446226.5, http: $horizons, waitSeconds: 0));

        $this->assertSame(1, $horizons->uncertainties);
        $this->assertNull($meta['uncertaintySeconds']);
        $this->assertLessThanOrEqual($meta['toleranceAu'], $meta['errorAu']);
    }

    /**
     * What is already there does not get asked for again unless told to, and no temp files or
     * locks are left behind.
     */
    public function test_it_does_not_download_twice_or_leave_leftovers(): void
    {
        Downloadables::useYearsPerFile(['asteroids' => 10]);
        $horizons = new FakeHorizons(self::ellipse(...));

        $path = Downloader::download(Asteroid::number(1), 2446226.5, http: $horizons, waitSeconds: 0);
        $this->assertSame($path, Downloader::download(Asteroid::number(1), 2446226.5, http: $horizons, waitSeconds: 0));
        $this->assertSame(1, $horizons->vectors);

        Downloader::download(Asteroid::number(1), 2446226.5, refresh: true, http: $horizons, waitSeconds: 0);
        $this->assertSame(2, $horizons->vectors);

        $this->assertSame(['1980-1989.bin'], array_map('basename', glob(dirname($path).'/*')));
    }

    /**
     * A span that would ask for too many rows is rejected before asking for anything, and it says
     * how to split it.
     */
    public function test_a_span_that_is_too_big_is_rejected_without_asking_for_anything(): void
    {
        Downloadables::useYearsPerFile(['satellites' => null]);
        $horizons = new FakeHorizons(self::fastSatellite(...));

        try {
            Downloader::download(Satellite::Io, 2451545.0, http: $horizons, waitSeconds: 0);
            $this->fail('This should have been rejected');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('yearsPerFile', $error->getMessage());
        }

        $this->assertSame(0, $horizons->requests);
    }

    /**
     * A truncated file does not give back zeros: it gives an error saying it has to be
     * downloaded again.
     */
    public function test_a_truncated_file_is_rejected(): void
    {
        Downloadables::useYearsPerFile(['asteroids' => 10]);
        $path = Downloader::download(Asteroid::number(1), 2446226.5, http: new FakeHorizons(self::ellipse(...)), waitSeconds: 0);

        file_put_contents($path, substr((string) file_get_contents($path), 0, -10));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('incomplete');

        DownloadedPositions::metadata($path);
    }

    /**
     * Every file carries a margin, so the start of a year is computed with the previous year's
     * file even though its own is not there yet. Past the margin, data is missing.
     */
    public function test_the_margin_covers_the_change_of_year(): void
    {
        Downloadables::useYearsPerFile([]);
        Downloader::download(Satellite::Io, Time::civilJulianDay(1985, 7, 1), http: new FakeHorizons(self::fastSatellite(...)), waitSeconds: 0);

        $real = self::fastSatellite(Time::civilJulianDay(1986, 1, 1.5));
        $read = DownloadedPositions::j2000(Satellite::Io, Time::civilJulianDay(1986, 1, 1.5));

        $this->assertEqualsWithDelta($real[0], $read[0], 1e-6);

        $this->expectException(MissingData::class);
        DownloadedPositions::j2000(Satellite::Io, Time::civilJulianDay(1986, 1, 20));
    }

    /**
     * What is missing stays missing: the package does NOT go to the JPL on its own.
     *
     * There used to be three tests here for downloading on the fly and for an allow list, and
     * both have left the package: who is allowed to download, and when, is application policy,
     * not the engine's. What is still the engine's, and what this checks, is that **asking for a
     * body that is not on disk throws `MissingData` without making a single request**.
     */
    public function test_what_is_missing_does_not_download_itself(): void
    {
        Downloadables::useYearsPerFile(['asteroids' => 10]);
        $horizons = new FakeHorizons(self::ellipse(...));

        try {
            Ephemeris::position(Asteroid::number(1), 2446226.5);
            $this->fail('The data should be missing');
        } catch (MissingData $missing) {
            $this->assertSame('downloads/asteroids/1/1980-1989.bin', $missing->dataFile);
        }

        $this->assertSame(0, $horizons->requests);
    }

    /**
     * A smooth heliocentric ellipse, with a period of four and a half years.
     *
     * @param float $jd
     * @return array{0: float, 1: float, 2: float}
     */
    private static function ellipse(float $jd): array
    {
        $angle = 2 * M_PI * ($jd - 2451545.0) / 1600;

        return [2.7 * cos($angle), 2.6 * sin($angle), 0.1 * sin($angle + 1)];
    }

    /**
     * A fake satellite that goes around its planet in two and a half hours.
     *
     * @param float $jd
     * @return array{0: float, 1: float, 2: float}
     */
    private static function fastSatellite(float $jd): array
    {
        $angle = 2 * M_PI * ($jd - 2451545.0) / 0.1;

        return [1e-4 * cos($angle), 1e-4 * sin($angle), 0.0];
    }

    /**
     * Deletes the folder without following the symlinks, which point at the repository's real
     * data.
     *
     * @param string $folder
     * @return void
     */
    private static function deleteFolder(string $folder): void
    {
        if (! is_dir($folder)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            if ($entry->isLink() || ! $entry->isDir()) {
                unlink($entry->getPathname());
            } else {
                rmdir($entry->getPathname());
            }
        }

        rmdir($folder);
    }
}

/**
 * A Horizons that answers with a known function in the same format as the real one, and that
 * knows how to imitate what the real one does badly: truncating a response, answering empty, and
 * blowing up when computing an uncertainty.
 */
final class FakeHorizons implements HttpClient
{
    public int $requests = 0;

    public int $vectors = 0;

    public int $uncertainties = 0;

    /** @var list<string> */
    public array $centers = [];

    /**
     * @param Closure(float): array{0: float, 1: float, 2: float} $position
     * @param int|null $cap Rows at most per response, to imitate a truncation.
     * @param string|null $response A fixed response, to imitate an error with text.
     * @param float|null $uncertainty The arcseconds it declares; without it, "n.a.".
     * @param bool $uncertaintyFails Whether it blows up when asked for it, as happens with Apophis.
     * @param int $empties How many empty responses it gives before answering, as when it truncates by burst.
     */
    public function __construct(
        private Closure $position,
        private ?int $cap = null,
        private ?string $response = null,
        private ?float $uncertainty = null,
        private bool $uncertaintyFails = false,
        private int $empties = 0,
    ) {}

    public function get(string $url): string
    {
        $this->requests++;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (($query['EPHEM_TYPE'] ?? '') === 'OBSERVER') {
            $this->uncertainties++;

            if ($this->uncertaintyFails) {
                throw new RuntimeException('answered with a 500.');
            }

            $sigma = $this->uncertainty === null ? '     n.a.,      n.a.,' : sprintf('%10.3f, %10.3f,', $this->uncertainty, $this->uncertainty / 2);

            return "\$\$SOE\n 1985-Jan-01 00:00, , , {$sigma}\n\$\$EOE\n";
        }

        $this->vectors++;
        $this->centers[] = trim($query['CENTER'], "'");

        if ($this->empties > 0) {
            $this->empties--;

            return '';
        }

        if ($this->response !== null) {
            return $this->response;
        }

        $from = (float) substr(trim($query['START_TIME'], "'"), 2);
        $to = (float) substr(trim($query['STOP_TIME'], "'"), 2);
        $step = (int) trim($query['STEP_SIZE'], "' m") / 1440;
        $rows = (int) round(($to - $from) / $step) + 1;
        $body = '';

        for ($i = 0, $n = min($rows, $this->cap ?? $rows); $i < $n; $i++) {
            $jd = $from + $i * $step;
            [$x, $y, $z] = ($this->position)($jd);
            $body .= sprintf("%.9F, A.D. 2000-Jan-01 00:00:00.0000, %.16E, %.16E, %.16E,\n", $jd, $x, $y, $z);
        }

        return "Target body name: Test (1)                {source: test}\n\$\$SOE\n{$body}\$\$EOE\n";
    }
}
