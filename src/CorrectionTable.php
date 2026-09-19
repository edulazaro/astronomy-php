<?php

namespace Astronomy;

use RuntimeException;

/**
 * The correction of our analytical series towards the JPL ephemerides.
 *
 * **The position is not stored: what the position is missing is stored.** VSOP87 and ELP are
 * series fitted forty years ago to DE200, and against DE440 they drift away by tenths of an
 * arcsecond (the inner planets, in the 20th century) up to several arcseconds (Uranus and
 * Neptune three centuries out, the Moon in 1600 because of the tidal acceleration). Storing
 * the JPL's whole position would cost tens of megabytes; storing only the difference costs a
 * fraction of that, because it is a number a thousand times smaller and therefore needs far
 * fewer coefficients for the same ABSOLUTE accuracy, which is the one that matters.
 *
 * And it has a property the other one does not: **if the file is missing or the date falls
 * outside the range, the engine keeps working** the way it did before. The correction is a
 * layer on top, not a replacement.
 *
 * The coefficients are Chebyshev, in blocks of equal days, one per rectangular coordinate, in
 * the J2000 ecliptic, which is the frame the JPL serves its vectors in.
 *
 * ## The file
 *
 * Binary and not PHP, and that is the only reason it fits. A `float` in a PHP array takes
 * some twenty bytes of text and has to be compiled at boot; here it is four bytes, and the
 * file is read in one go into a string that is never parsed. Of a block, only its own
 * coefficients are unpacked.
 *
 * A 32-byte header, all little endian:
 *
 * ```
 *  0  "TCOR"          4 bytes
 *  4  version         u8
 *  5  bytes per datum u8 (4 = float32)
 *  6  degree          u8
 *  7  reserved        u8
 *  8  first jd        float64
 * 16  days per block  float64
 * 24  blocks          u32
 * 28  reserved        u32
 * 32  coefficients    blocks × 3 × (degree+1) floats, the whole x, then y, then z
 * ```
 *
 * **Four-byte floats and not eight**, and it is measured: the correction of Uranus reaches
 * 7e-4 AU and the relative precision of a four-byte float is 1e-7, that is 1e-10 AU of
 * rounding error, four orders of magnitude below what is being corrected. Storing the whole
 * position with this precision would indeed be madness; storing a correction, it is not.
 */
final class CorrectionTable
{
    private const MAGIC = 'TCOR';

    private const VERSION = 1;

    private const HEADER = 32;

    /** @var array<string, array{jd: float, dias: float, bloques: int, grado: int, bytes: int, datos: string}|false> */
    private static array $tables = [];

    /**
     * The correction for a body at an instant, in AU and in the J2000 ecliptic.
     *
     * Returns null if there is no table for that body or if the date falls outside it, which
     * is what lets the engine carry on with its analytical series none the wiser.
     *
     * @param string $body
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function vector(string $body, float $jdTT): ?array
    {
        $table = self::table($body);

        if ($table === false) {
            return null;
        }

        $position = ($jdTT - $table['jd']) / $table['days'];

        if ($position < 0 || $position > $table['blocks']) {
            return null;
        }

        $block = (int) $position;

        // The last exact instant falls in the next block, which does not exist: it is the
        // last one's.
        if ($block >= $table['blocks']) {
            $block = $table['blocks'] - 1;
        }

        $x = 2.0 * ($position - $block) - 1.0;
        $perCoordinate = $table['degree'] + 1;
        $start = self::HEADER + $block * 3 * $perCoordinate * $table['bytes'];

        // The format code comes from the header, just like the offsets: written by hand as
        // 'g' while the size was read from the file, an eight-byte format would be read with
        // the four-byte code and nonsense numbers would come out instead of an error.
        $raw = unpack(
            ($table['bytes'] === 8 ? 'e' : 'g').(3 * $perCoordinate),
            substr($table['data'], $start, 3 * $perCoordinate * $table['bytes'])
        );

        if ($raw === false) {
            throw new RuntimeException("The correction table for {$body} is cut short at block {$block}.");
        }

        $raw = array_values($raw);

        $vector = [];

        foreach ([0, 1, 2] as $axis) {
            $vector[$axis] = Chebyshev::evaluate(
                array_slice($raw, $axis * $perCoordinate, $perCoordinate),
                $x
            );
        }

        return $vector;
    }

    /**
     * The first and the last Julian day the table covers, or null if there is no table.
     *
     * @param string $body
     * @return array{0: float, 1: float}|null
     */
    public static function range(string $body): ?array
    {
        $table = self::table($body);

        return $table === false ? null : [$table['jd'], $table['jd'] + $table['blocks'] * $table['days']];
    }

    /**
     * @param string $body
     * @return bool
     */
    public static function exists(string $body): bool
    {
        return self::table($body) !== false;
    }

    /**
     * Writes a table in the file format. Used by the commands that generate it.
     *
     * @param string $path
     * @param float $firstJd
     * @param float $daysPerBlock
     * @param int $degree
     * @param list<list<list<float>>> $blocks Per block, three lists of degree+1 coefficients.
     * @return int Bytes written.
     */
    public static function write(string $path, float $firstJd, float $daysPerBlock, int $degree, array $blocks): int
    {
        $data = self::MAGIC
            .pack('CCCC', self::VERSION, 4, $degree, 0)
            .pack('ee', $firstJd, $daysPerBlock)
            .pack('VV', count($blocks), 0);

        foreach ($blocks as $index => $block) {
            if (count($block) !== 3) {
                throw new RuntimeException("Block {$index} does not have three coordinates.");
            }

            foreach ($block as $coordinate) {
                if (count($coordinate) !== $degree + 1) {
                    throw new RuntimeException("Block {$index} does not have ".($degree + 1).' coefficients per coordinate.');
                }

                $data .= pack('g*', ...$coordinate);
            }
        }

        if (file_put_contents($path, $data) === false) {
            throw new RuntimeException("Could not write {$path}.");
        }

        return strlen($data);
    }

    /**
     * Clears the memory of the tables already read. For the tests that regenerate files.
     */
    public static function forget(): void
    {
        self::$tables = [];
    }

    /**
     * @param string $body
     * @return array{jd: float, dias: float, bloques: int, grado: int, bytes: int, datos: string}|false
     */
    private static function table(string $body)
    {
        if (array_key_exists($body, self::$tables)) {
            return self::$tables[$body];
        }

        $path = DataFolder::path("correction/{$body}.bin");

        if (! is_file($path)) {
            return self::$tables[$body] = false;
        }

        $data = file_get_contents($path);

        if ($data === false || strlen($data) < self::HEADER || substr($data, 0, 4) !== self::MAGIC) {
            throw new RuntimeException("The correction table for {$body} does not have the header it should.");
        }

        $header = unpack('Cversion/Cbytes/Cdegree/Creserved/ejd/edays/Vblocks/Vreserved2', substr($data, 4, self::HEADER - 4));

        if ($header === false || $header['version'] !== self::VERSION) {
            throw new RuntimeException("The correction table for {$body} is from another version of the format.");
        }

        if (! in_array($header['bytes'], [4, 8], true)) {
            throw new RuntimeException("The correction table for {$body} claims data of {$header['bytes']} bytes, and only 4 and 8 exist.");
        }

        $expected = self::HEADER + $header['blocks'] * 3 * ($header['degree'] + 1) * $header['bytes'];

        // A file cut short does not give an error when read: it gives zero correction in the
        // part that is missing, that is, a worse position that looks perfectly fine.
        if (strlen($data) !== $expected) {
            throw new RuntimeException(sprintf(
                'The correction table for %s is %d bytes and its header announces %d.',
                $body, strlen($data), $expected
            ));
        }

        return self::$tables[$body] = [
            'jd' => $header['jd'],
            'days' => $header['days'],
            'blocks' => $header['blocks'],
            'degree' => $header['degree'],
            'bytes' => $header['bytes'],
            'data' => $data,
        ];
    }
}
