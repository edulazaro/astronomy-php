<?php

namespace Astronomy;

/**
 * Evaluates the VSOP87 series.
 *
 * Every coordinate is a sum of sums: for each power of tau there is a list of
 * `A·cos(B + C·tau)` terms, and the total for that power is multiplied by tau raised to
 * it. That is all this class does.
 *
 * The tables are written by `astro:vsop87` from the original series. Here they
 * are only read, and they are read once per process: there are five thousand terms and a
 * birth chart asks for ten positions, so re-reading the file for each one multiplies by ten
 * the only expensive work there is.
 *
 * Variant D only, heliocentric and of date. There was an E series (the Sun with respect to
 * the barycentre) and it was withdrawn: it put the Sun a thousand kilometres away from where
 * DE440 puts the barycentre, and summing the masses over these very series
 * (`Ephemeris::barycentricSun`) leaves it at a hundred. A file nobody reads any more is a
 * dead file.
 */
class Vsop87
{
    /** @var array<string, array<string, array<int, list<array{float, float, float}>>>> */
    private static array $tables = [];

    /**
     * Heliocentric spherical coordinates, in the ecliptic and equinox of date.
     *
     * @param string $body
     * @param float $tau Julian millennia since J2000, in TT.
     * @return array{0: float, 1: float, 2: float} [longitude rad, latitude rad, radius AU]
     */
    public static function spherical(string $body, float $tau): array
    {
        $table = self::table($body);

        $l = self::evaluateSeries($table['L'] ?? [], $tau);
        $b = self::evaluateSeries($table['B'] ?? [], $tau);
        $r = self::evaluateSeries($table['R'] ?? [], $tau);

        return [self::normalize($l), $b, $r];
    }

    /**
     * The same coordinates, in rectangular form. This is what is needed in order to subtract
     * two positions, which is how heliocentric becomes geocentric.
     *
     * @param string $body
     * @param float $tau
     * @return array{0: float, 1: float, 2: float}
     */
    public static function rectangular(string $body, float $tau): array
    {
        [$l, $b, $r] = self::spherical($body, $tau);

        return [
            $r * cos($b) * cos($l),
            $r * cos($b) * sin($l),
            $r * sin($b),
        ];
    }

    /**
     * @param array<int, list<array{float, float, float}>> $powers
     * @param float $tau
     * @return float
     */
    private static function evaluateSeries(array $powers, float $tau): float
    {
        $total = 0.0;

        foreach ($powers as $power => $terms) {
            $sum = 0.0;

            foreach ($terms as [$a, $b, $c]) {
                $sum += $a * cos($b + $c * $tau);
            }

            $total += $sum * $tau ** $power;
        }

        return $total;
    }

    /**
     * @param string $body
     * @return array<string, array<int, list<array{float, float, float}>>>
     */
    private static function table(string $body): array
    {
        return self::$tables[$body] ??= require DataFolder::path("vsop87/{$body}.php");
    }

    /**
     * @param float $radians
     * @return float
     */
    private static function normalize(float $radians): float
    {
        $fullTurn = 2 * M_PI;

        return fmod(fmod($radians, $fullTurn) + $fullTurn, $fullTurn);
    }
}
