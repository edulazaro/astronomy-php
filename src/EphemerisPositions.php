<?php

namespace Astronomy;

use RuntimeException;

/**
 * Reads a position table and interpolates between its points.
 *
 * The tables store one point every thirty days. Between two points the position comes out of
 * a five-point Lagrange interpolation, which for a heliocentric orbit is more than enough:
 * in a month Pluto travels an eighth of a degree and its path over that stretch is
 * practically a straight line. The interpolation error stays well below the error of the
 * ephemeris itself.
 *
 * Several points and not two: a straight line between two points leaves an error that shows,
 * and on top of that it introduces a spike in the velocity every time a table point is
 * crossed, which would make a body look as if it turned from retrograde to direct when it
 * did not.
 */
class EphemerisPositions
{
    /**
     * How many points go into the interpolation. Odd, so that it stays centred.
     *
     * Nine, and the number is measured, not eyeballed. With five, Pallas and Juno came out two
     * and a half arcseconds away from the JPL while Ceres stayed below one, and what separates
     * them is the ECCENTRICITY: Ceres has 0.08 and Juno 0.26, so Juno runs near perihelion and
     * a fixed step undersamples it right there.
     *
     * The obvious way out was to store the points closer together, and it worked: with ten-day
     * steps Pallas dropped to 1.1 arcseconds. But it tripled the weight of the tables. Raising
     * the window is free and gives the same: 2.41 with five points, 1.14 with seven, 1.10 with
     * nine, and with eleven it no longer drops. That is where the interpolation stops being
     * what rules and the engine's own floor starts.
     */
    private const WINDOW = 9;

    /** @var array<string, array{jd: float, step: float, points: int, xyz: list<array{float, float, float}>}> */
    private static array $tables = [];

    /**
     * Rectangular heliocentric position in the J2000 ecliptic, in AU.
     *
     * @param string $body
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    public static function j2000(string $body, float $jdTT): array
    {
        $table = self::table($body);

        $position = ($jdTT - $table['jd']) / $table['step'];

        if ($position < 0 || $position > $table['points'] - 1) {
            throw new RuntimeException(sprintf(
                'The table for %s does not reach that date: it runs from Julian day %.1f to %.1f and %.1f was asked for.',
                $body,
                $table['jd'],
                $table['jd'] + ($table['points'] - 1) * $table['step'],
                $jdTT
            ));
        }

        // The window is centred on the requested point and hugs the edges when it does not
        // fit, which is what happens at the first and the last date of the table.
        $first = (int) round($position) - intdiv(self::WINDOW, 2);
        $first = max(0, min($first, $table['points'] - self::WINDOW));

        $result = [0.0, 0.0, 0.0];

        for ($i = 0; $i < self::WINDOW; $i++) {
            $weight = 1.0;

            for ($j = 0; $j < self::WINDOW; $j++) {
                if ($i !== $j) {
                    $weight *= ($position - ($first + $j)) / (($first + $i) - ($first + $j));
                }
            }

            $point = $table['xyz'][$first + $i];

            $result[0] += $weight * $point[0];
            $result[1] += $weight * $point[1];
            $result[2] += $weight * $point[2];
        }

        return $result;
    }

    /**
     * Rectangular heliocentric position in the ecliptic OF THE DATE, which is the frame the
     * rest of the engine works in.
     *
     * The tables are stored in J2000 because that is the frame the JPL gives them in and
     * because it does not age. The rotation to the date is done here, when they are read.
     *
     * @param string $body
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    public static function atDate(string $body, float $jdTT): array
    {
        return Precession::toDate(self::j2000($body, $jdTT), Time::centuries($jdTT));
    }

    /**
     * The first and the last Julian day a table covers.
     *
     * @param string $body
     * @return array{0: float, 1: float}
     */
    public static function range(string $body): array
    {
        $table = self::table($body);

        return [
            $table['jd'],
            $table['jd'] + ($table['points'] - 1) * $table['step'],
        ];
    }

    /**
     * @param string $body
     * @return array{jd: float, step: float, points: int, xyz: list<array{float, float, float}>}
     */
    private static function table(string $body): array
    {
        return self::$tables[$body] ??= require DataFolder::path("positions/{$body}.php");
    }
}
