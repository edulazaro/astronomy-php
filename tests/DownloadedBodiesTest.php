<?php

namespace Astronomy\Tests;

use Astronomy\Asteroid;
use Astronomy\Comet;
use Astronomy\Crossings;
use Astronomy\DownloadableBody;
use Astronomy\DataFolder;
use Astronomy\Downloadables;
use Astronomy\Ephemeris;
use Astronomy\MissingData;
use Astronomy\Phenomena;
use Astronomy\NodesAndApsides;
use Astronomy\Retrogrades;
use Astronomy\Satellite;
use Astronomy\Time;
use FilesystemIterator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use PHPUnit\Framework\TestCase;

/**
 * Asteroids, satellites and comets actually downloaded, against the apparent position JPL
 * Horizons publishes.
 *
 * The files under `tests/datos/descargas` were downloaded by `Downloader` on 2026 September 14:
 * Eris from 1980 to 1989, Io and Titan in 1985 and Halley in 1986. The JPL positions are its
 * `ObsEcLon` and `ObsEcLat` from the geocenter, in TT, copied by hand as in `EphemerisTest`, so
 * this runs with no network.
 */
class DownloadedBodiesTest extends TestCase
{
    /**
     * Arcseconds. The engine's floor against Horizons is 0.13, and it is of frame, not of the
     * tables: Eris, the farthest, stays at 0.076 and the satellites and Halley below 0.011.
     */
    private const TOLERANCE = 0.1;

    private string $folder;

    private mixed $previousFolder;

    private mixed $previousUsed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().'/astro-downloaded-'.bin2hex(random_bytes(4));
        mkdir($this->folder);

        foreach (glob(DataFolder::folder().'/*') as $entry) {
            symlink($entry, $this->folder.'/'.basename($entry));
        }

        symlink(__DIR__.'/data/downloads', $this->folder.'/downloads');

        $reflection = new ReflectionClass(DataFolder::class);
        $this->previousFolder = $reflection->getProperty('folder')->getValue();
        $this->previousUsed = $reflection->getProperty('used')->getValue();
        $reflection->getProperty('used')->setValue(null, false);

        DataFolder::useFolder($this->folder);
        Downloadables::useYearsPerFile(['asteroids' => 10]);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(DataFolder::class);
        $reflection->getProperty('folder')->setValue(null, $this->previousFolder);
        $reflection->getProperty('used')->setValue(null, $this->previousUsed);

        Downloadables::useYearsPerFile([]);

        // Symlinks only: they point at the real data and none of them is followed.
        foreach (new FilesystemIterator($this->folder) as $entry) {
            unlink($entry->getPathname());
        }

        rmdir($this->folder);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: DownloadableBody, 1: array{int, int, float}, 2: float, 3: float}>
     */
    public static function jplPositions(): array
    {
        return [
            'Eris 1985' => [Asteroid::number(136199), [1985, 6, 10.0], 16.31296150, -18.19352380],
            'Eris 1987' => [Asteroid::number(136199), [1987, 3, 1.25], 15.80864630, -17.81987000],
            'Eris 1989' => [Asteroid::number(136199), [1989, 12, 31.75], 16.24716770, -17.46275870],
            'Io in March' => [Satellite::Io, [1985, 3, 15.0], 307.79695120, -0.38691640],
            'Io in July' => [Satellite::Io, [1985, 7, 1.5], 315.87371340, -0.75388700],
            'Io on New Year\'s Eve' => [Satellite::Io, [1985, 12, 31 + 23 / 24], 318.21782950, -0.80340330],
            'Halley at perihelion' => [Comet::designation('1P'), [1986, 2, 9.0], 315.35251440, 6.21743070],
            'Halley in its close pass' => [Comet::designation('1P'), [1986, 4, 11.0], 236.34292930, -28.89675280],
            'Halley moving away' => [Comet::designation('1P'), [1986, 11, 1.0], 180.39915720, -13.90792230],
            'Titan in May' => [Satellite::Titan, [1985, 5, 1.0], 235.86956810, 2.34857910],
            'Titan in October' => [Satellite::Titan, [1985, 10, 1.0], 234.89951380, 1.90458110],
        ];
    }

    #[DataProvider('jplPositions')]
    public function test_the_apparent_position_matches_the_jpl(DownloadableBody $body, array $date, float $longitude, float $latitude): void
    {
        $position = Ephemeris::position($body, Time::civilJulianDay(...$date));

        $difference = fmod($position->longitude - $longitude + 540, 360) - 180;

        $this->assertLessThan(self::TOLERANCE, abs($difference * 3600 * cos(deg2rad($latitude))), 'longitude');
        $this->assertLessThan(self::TOLERANCE, abs(($position->latitude - $latitude) * 3600), 'latitude');
    }

    /**
     * Outside what was downloaded nothing is invented: it says what is missing.
     */
    public function test_outside_what_was_downloaded_data_is_missing(): void
    {
        $this->expectException(MissingData::class);
        $this->expectExceptionMessage('The data for Io in 1990 is missing');

        Ephemeris::position(Satellite::Io, Time::civilJulianDay(1990, 6, 1));
    }

    /**
     * Sign ingresses work the same for a downloaded body.
     *
     * Halley crosses seven boundaries in 1986, because near perihelion it runs through the zodiac
     * in weeks. **And Eris crosses none in ten years, which is the correct answer and not a sweep
     * missing them**: measured with the engine itself, it spends the whole of the eighties between
     * 13.9 and 17.3 degrees, inside Aries from start to finish.
     */
    public function test_ingresses_work_for_a_downloaded_body(): void
    {
        $ingresses = Crossings::ingresses(Comet::designation('1P'), Time::civilJulianDay(1986, 1, 10), Time::civilJulianDay(1986, 12, 20));

        $this->assertCount(7, $ingresses);

        $this->assertSame([], Crossings::ingresses(
            Asteroid::number(136199),
            Time::civilJulianDay(1980, 1, 15),
            Time::civilJulianDay(1989, 12, 15)
        ));
    }

    /**
     * And retrogradations. Eris has two periods touching 1985, one coming from July 1984 and
     * another starting in summer.
     */
    public function test_retrogradations_work_for_a_downloaded_body(): void
    {
        $eris = Asteroid::number(136199);

        $this->assertCount(2, Retrogrades::periods($eris, Time::civilJulianDay(1985, 1, 1), Time::civilJulianDay(1985, 12, 31)));
        $this->assertTrue(Retrogrades::retrogradeAt($eris, Time::civilJulianDay(1985, 9, 1)));
        $this->assertFalse(Retrogrades::retrogradeAt($eris, Time::civilJulianDay(1985, 6, 10)));
    }

    /**
     * For a downloaded body, what can be computed is given and what cannot is kept quiet.
     *
     * The phase and the elongation come from the geometry, which is the same for everyone. The
     * disk is not: for any given asteroid the radius is unknown, so zero is returned instead of
     * making it up. And the brightness is not either: `Magnitudes` is fitted body by body against
     * the JPL itself and there is no fit for this one, so it goes to null, the same as already
     * happens with Uranus outside its range of phases.
     */
    public function test_a_downloaded_body_gives_the_phase_but_not_the_disk_or_the_brightness(): void
    {
        $phenomenon = Phenomena::of(Asteroid::number(136199), Time::civilJulianDay(1985, 6, 10));

        $this->assertEqualsWithDelta(0.54, $phenomenon->phaseAngle, 0.05);
        $this->assertEqualsWithDelta(64.22, $phenomenon->elongation, 0.1);
        $this->assertSame(0.0, $phenomenon->apparentDiameter);
        $this->assertNull($phenomenon->magnitude);

        $ofIo = Phenomena::of(Satellite::Io, Time::civilJulianDay(1985, 6, 10));

        $this->assertSame(0.0, $ofIo->apparentDiameter);
        $this->assertNull($ofIo->magnitude);
    }

    /**
     * Eris's osculating orbit against the elements the JPL publishes for that same instant
     * (`EPHEM_TYPE=ELEMENTS`, TDB, which is how it gives them).
     *
     * The node is compared with more slack: ours is on the ecliptic OF THE DATE and the JPL's
     * on that of J2000, and between 1985 and 2000 precession is two tenths of a degree. It is
     * the same thing already noted for the planets.
     */
    public function test_a_downloaded_asteroids_orbit_matches_the_jpl(): void
    {
        $orbit = NodesAndApsides::of(Asteroid::number(136199), Time::civilJulianDay(1985, 6, 10));

        $this->assertEqualsWithDelta(0.4351996, $orbit->eccentricity, 1e-4);
        $this->assertEqualsWithDelta(44.018929, $orbit->inclination, 0.01);
        $this->assertEqualsWithDelta(67.975303, $orbit->semiMajorAxis, 0.05);
        $this->assertEqualsWithDelta(38.392476, $orbit->perihelionDistance, 0.05);
        $this->assertEqualsWithDelta(35.952439, $orbit->ascendingNode, 0.25);
    }

    /**
     * A satellite orbits its planet, not the Sun, so its nodes and apsides do not come from
     * here. It is the same case as the Moon.
     */
    public function test_a_satellite_has_no_orbit_around_the_sun(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('around Jupiter');

        NodesAndApsides::of(Satellite::Io, Time::civilJulianDay(1985, 6, 10));
    }
}
