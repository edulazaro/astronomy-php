<?php

namespace Astronomy;

use RuntimeException;

/**
 * The files of the downloadable bodies: how they are written, how they are read and how they are
 * interpolated. The whole format lives here so that whoever writes and whoever reads cannot disagree.
 *
 * Binary and not PHP, for the same reason as the correction towards the JPL: a number in a PHP
 * array is some twenty bytes of text that have to be compiled on load, and here it is eight that are
 * only unpacked when they are read. Eight and not four, the other way round from the
 * correction: that one is a small difference and this one is the whole position, and with four bytes
 * Eris, at a hundred astronomical units, would lose six millionths of a unit, which, seen from here,
 * are the 0.01″ that is meant to be guaranteed.
 *
 * bytes               what
 * 8                   `ASTRODES`
 * 4                   format version
 * 4                   number of points
 * 8                   TT Julian day of the first point
 * 8                   step in days
 * 4                   length of the metadata
 * as many as it says  metadata in JSON: the body, where it was downloaded from, the range, the measured error
 * 24 per point        x, y, z in AU, in the J2000 ecliptic
 *
 * Everything little-endian. The positions are relative to the centre they were downloaded from: the
 * Sun for asteroids and comets, and the barycentre of the system for a satellite.
 */
final class DownloadedPositions
{
    /**
     * How many points go into the interpolation, and how they are placed, is the same as in
     * `EphemerisPositions`, where the nine is measured. Here it matters for one more reason: the
     * `Downloader` chooses the step by measuring the error of THIS interpolation, so the two have to
     * be the same computation, and that is why both ask `weights()` for their weights.
     */
    public const WINDOW = 9;

    private const MAGIC = 'ASTRODES';

    private const VERSION = 1;

    /** Bytes of the fixed header, up to the metadata. */
    private const HEADER = 36;

    /** How many tables are remembered at once. A centuries-long sweep of a satellite opens one file per year. */
    private const REMEMBERED = 64;

    /** @var array<string, array{jd: float, step: float, points: int, start: int, data: string}> By path. */
    private static array $tables = [];

    /** @var array<string, array{jd: float, step: float, points: int, start: int, data: string}> The latest one of each body. */
    private static array $latest = [];

    /**
     * The position of a body in rectangular coordinates of the J2000 ecliptic, in AU, relative to the
     * centre it was downloaded from.
     *
     * It looks for the file of the instant and, if it is not there or does not reach far enough, the
     * one of the neighbouring range: each file carries `Downloader::MARGIN_DAYS` extra on each side,
     * so the light time of a date of 1 January falls inside that year's file even if the previous
     * year's one has not been downloaded.
     *
     * @param DownloadableBody $body
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     *
     * @throws MissingData If the file is not there.
     * @throws RuntimeException If it is there and the date falls outside what it carries.
     */
    public static function j2000(DownloadableBody $body, float $jdTT): array
    {
        // With the folder in the key: the same body in another data folder is another file.
        $key = DataFolder::folder().':'.$body->group()->value.':'.$body->horizonsId();
        $table = self::$latest[$key] ?? null;

        if ($table === null || ! self::covers($table, $jdTT)) {
            $table = self::$latest[$key] = self::find($body, $jdTT);
        }

        $position = ($jdTT - $table['jd']) / $table['step'];
        $first = max(0, min((int) round($position) - intdiv(self::WINDOW, 2), $table['points'] - self::WINDOW));
        $points = unpack('e'.(3 * self::WINDOW), $table['data'], $table['start'] + 24 * $first);
        $result = [0.0, 0.0, 0.0];

        foreach (self::weights($position - $first) as $i => $weight) {
            $result[0] += $weight * $points[3 * $i + 1];
            $result[1] += $weight * $points[3 * $i + 2];
            $result[2] += $weight * $points[3 * $i + 3];
        }

        return $result;
    }

    /**
     * The Lagrange weights of the window for a point `$u` steps away from the first one of it.
     *
     * @param float $u
     * @return list<float>
     */
    public static function weights(float $u): array
    {
        $weights = [];

        for ($i = 0; $i < self::WINDOW; $i++) {
            $weight = 1.0;

            for ($j = 0; $j < self::WINDOW; $j++) {
                if ($j !== $i) {
                    $weight *= ($u - $j) / ($i - $j);
                }
            }

            $weights[] = $weight;
        }

        return $weights;
    }

    /**
     * Writes a file, whole or not at all.
     *
     * It is written to a temporary file and renamed at the end, which on the same disk is atomic:
     * whoever reads at the same time sees the complete file or does not see it, never a half-written
     * one.
     *
     * @param string $path
     * @param float $jdStart
     * @param float $step Days.
     * @param list<float> $x
     * @param list<float> $y
     * @param list<float> $z
     * @param array<string, mixed> $metadata
     * @return void
     *
     * @throws RuntimeException
     */
    public static function write(string $path, float $jdStart, float $step, array $x, array $y, array $z, array $metadata): void
    {
        $points = count($x);

        if ($points < self::WINDOW || count($y) !== $points || count($z) !== $points) {
            throw new RuntimeException("Cannot write {$path}: at least ".self::WINDOW.' points with their three coordinates are needed.');
        }

        $meta = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $folder = dirname($path);

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new RuntimeException("Cannot create the folder {$folder}.");
        }

        $temporary = $path.'.'.getmypid().'-'.bin2hex(random_bytes(4)).'.tmp';
        $file = fopen($temporary, 'wb');

        if ($file === false) {
            throw new RuntimeException("Cannot write in {$folder}.");
        }

        fwrite($file, self::MAGIC.pack('VVeeV', self::VERSION, $points, $jdStart, $step, strlen($meta)).$meta);

        for ($i = 0; $i < $points; $i += 4096) {
            $chunk = '';

            for ($k = $i, $end = min($points, $i + 4096); $k < $end; $k++) {
                $chunk .= pack('eee', $x[$k], $y[$k], $z[$k]);
            }

            fwrite($file, $chunk);
        }

        fclose($file);

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Could not move {$path} into place.");
        }

        unset(self::$tables[$path]);
        self::$latest = [];
    }

    /**
     * The metadata of a file: where it was downloaded from, what range it covers and what error was
     * measured.
     *
     * @param string $path
     * @return array<string, mixed>
     */
    public static function metadata(string $path): array
    {
        $table = self::load($path);

        return (array) json_decode(substr($table['data'], self::HEADER, $table['start'] - self::HEADER), true);
    }

    /**
     * @param DownloadableBody $body
     * @param float $jdTT
     * @return array{jd: float, step: float, points: int, start: int, data: string}
     */
    private static function find(DownloadableBody $body, float $jdTT): array
    {
        $files = array_unique([
            $body->file($jdTT),
            $body->file($jdTT - Downloader::MARGIN_DAYS),
            $body->file($jdTT + Downloader::MARGIN_DAYS),
        ]);

        foreach ($files as $file) {
            $path = DataFolder::path($file);
            $table = self::$tables[$path] ?? null;

            if ($table === null && is_file($path)) {
                if (count(self::$tables) >= self::REMEMBERED) {
                    array_shift(self::$tables);
                }

                $table = self::$tables[$path] = self::load($path);
            }

            if ($table !== null && self::covers($table, $jdTT)) {
                return $table;
            }
        }

        /* If the file of the instant is not there, this throws `MissingData` with the message about
           how to get it. And with "download on the fly" switched on it does not throw: it downloads
           it and returns its path, so whether it covers has to be checked again before giving up.
           Taking for granted that it did not cover, the first read of a just-downloaded file died
           with a "the data runs from day such to such" that carried the instant asked for right in
           the middle. The test caught it when the option was switched on. */
        $path = Downloadables::path($body, $jdTT);
        $table = self::$tables[$path] = self::load($path);

        if (self::covers($table, $jdTT)) {
            return $table;
        }

        throw new RuntimeException(sprintf(
            'The data for %s runs from Julian day %.1F to %.1F, and %.1F was asked for.',
            $body->name(), $table['jd'], $table['jd'] + ($table['points'] - 1) * $table['step'], $jdTT
        ));
    }

    /**
     * @param array{jd: float, step: float, points: int, start: int, data: string} $table
     * @param float $jdTT
     * @return bool
     */
    private static function covers(array $table, float $jdTT): bool
    {
        return $jdTT >= $table['jd'] && $jdTT <= $table['jd'] + ($table['points'] - 1) * $table['step'];
    }

    /**
     * Reads a file and checks that it is whole.
     *
     * A truncated file does not give an error when interpolating: it gives zeros, that is, a body at
     * the centre of the frame looking exactly like a position. That is why the size is counted against
     * the header.
     *
     * @param string $path
     * @return array{jd: float, step: float, points: int, start: int, data: string}
     */
    private static function load(string $path): array
    {
        $data = (string) @file_get_contents($path);

        if (strlen($data) < self::HEADER || ! str_starts_with($data, self::MAGIC)) {
            throw new RuntimeException("{$path} is not a downloaded data file.");
        }

        $header = unpack('Vversion/Vpoints/ejd/estep/Vmeta', $data, strlen(self::MAGIC));

        if ($header['version'] !== self::VERSION) {
            throw new RuntimeException("{$path} is version {$header['version']} of the format and this reads version ".self::VERSION.'.');
        }

        $start = self::HEADER + $header['meta'];

        if ($header['points'] < self::WINDOW || $header['step'] <= 0 || strlen($data) !== $start + 24 * $header['points']) {
            throw new RuntimeException("{$path} is incomplete or broken: its size does not match its header. It has to be downloaded again.");
        }

        return [
            'jd' => $header['jd'],
            'step' => $header['step'],
            'points' => $header['points'],
            'start' => $start,
            'data' => $data,
        ];
    }
}
