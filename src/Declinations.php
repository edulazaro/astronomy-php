<?php

namespace Astronomy;

/**
 * Aspects measured in the OTHER coordinate.
 *
 * Everything in an ordinary chart is measured in ecliptic longitude, that is, along the zodiac.
 * But a planet is also further north or further south of the celestial equator, and that is its
 * declination. Two bodies can be ninety degrees apart in longitude, in square, and at the same
 * time at the same height above the equator.
 *
 * When they match in height, tradition reads it as a hidden conjunction: it is called a
 * **parallel** if they are on the same side and a **contraparallel** if they are on opposite
 * sides and at the same distance, which is read as an opposition.
 *
 * It is the layer that serious programs have and almost no free website does, and not because
 * it is hard: declination is a rotation by the obliquity applied to what is already computed.
 */
class Declinations
{
    /**
     * Orb, in degrees. One is what almost all the literature uses.
     *
     * And here it is not widened for the Sun and the Moon as it is in longitude aspects. That
     * widening comes from their being seen as discs and tradition giving them more room; in
     * declination what is compared is a height, and one degree is one degree for everyone.
     */
    public const ORB = 1.0;

    /**
     * The declination of a point of the ecliptic.
     *
     * It is the standard formula for going from ecliptic to equatorial coordinates:
     *
     *     sin δ = sin β · cos ε + cos β · sin ε · sin λ
     *
     * **Ecliptic latitude CANNOT be taken as zero.** That is the temptation, because for the Sun
     * it really is zero and the formula reduces to `asin(sin ε · sin λ)`. But the Moon departs
     * from the ecliptic by up to five degrees and Pluto by up to seventeen: with β at zero,
     * Pluto's declination comes out with degrees of error and its parallels are made up.
     *
     * @param float $longitude Degrees of ecliptic longitude.
     * @param float $latitude Degrees of ecliptic latitude.
     * @param float $obliquity Radians.
     * @return float Degrees, positive north of the equator.
     */
    public static function of(float $longitude, float $latitude, float $obliquity): float
    {
        $lambda = deg2rad($longitude);
        $beta = deg2rad($latitude);

        return rad2deg(asin(
            sin($beta) * cos($obliquity)
            + cos($beta) * sin($obliquity) * sin($lambda)
        ));
    }

    /**
     * The declinations of a chart, by body key.
     *
     * @param array<string, Position> $positions
     * @param float $jdTT
     * @return array<string, float>
     */
    public static function all(array $positions, float $jdTT): array
    {
        $obliquity = Time::trueObliquity(Time::centuries($jdTT));
        $declinations = [];

        foreach ($positions as $key => $position) {
            $declinations[$key] = self::of($position->longitude, $position->latitude, $obliquity);
        }

        return $declinations;
    }

    /**
     * The parallels and contraparallels of a chart.
     *
     * @param array<string, Position> $positions
     * @param float $jdTT
     * @return list<FoundParallel>
     */
    public static function between(array $positions, float $jdTT): array
    {
        $declinations = self::all($positions, $jdTT);
        $keys = array_keys($declinations);
        $found = [];

        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                $a = $positions[$keys[$i]];
                $b = $positions[$keys[$j]];
                $da = $declinations[$keys[$i]];
                $db = $declinations[$keys[$j]];

                // Same side of the equator: parallel. Opposite sides: contraparallel. And in
                // both cases what is compared is the HEIGHT, so the contraparallel looks at the
                // absolute values.
                $sameSide = ($da >= 0) === ($db >= 0);
                $deviation = $sameSide ? abs($da - $db) : abs(abs($da) - abs($db));

                if ($deviation > self::ORB) {
                    continue;
                }

                $found[] = new FoundParallel(
                    a: $a,
                    b: $b,
                    declinationA: $da,
                    declinationB: $db,
                    orb: $deviation,
                    parallel: $sameSide,
                );
            }
        }

        usort($found, fn (FoundParallel $x, FoundParallel $y) => $x->orb <=> $y->orb);

        return $found;
    }

    /**
     * The bodies that are «out of bounds»: further north or south than the Sun ever reaches.
     *
     * The Sun never goes beyond the obliquity, some 23 degrees and 26 minutes, because the
     * ecliptic is its path. A planet that does go beyond it is in territory the Sun never
     * visits, and modern tradition reads that as stepping outside the norm. It happens above
     * all to the Moon, which can reach 28 degrees.
     *
     * @param array<string, Position> $positions
     * @param float $jdTT
     * @return list<array{body: Body, declination: float}>
     */
    public static function outOfBounds(array $positions, float $jdTT): array
    {
        $limit = rad2deg(Time::trueObliquity(Time::centuries($jdTT)));
        $outside = [];

        foreach (self::all($positions, $jdTT) as $key => $declination) {
            if (abs($declination) > $limit) {
                $outside[] = [
                    'body' => $positions[$key]->body,
                    'declination' => $declination,
                ];
            }
        }

        return $outside;
    }
}
