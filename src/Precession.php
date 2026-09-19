<?php

namespace Astronomy;

/**
 * Rotates coordinates between the J2000 ecliptic and the ecliptic of date.
 *
 * It is needed because the two sources of positions speak different systems. VSOP87 and ELP
 * give coordinates referred to the ecliptic of date, which is the one astrology uses; the
 * JPL tabulated ephemerides come in J2000, which is a still photograph of how the sky stood
 * on 1 January 2000. Between the two there are two motions: the plane of the ecliptic tilts
 * slowly, and the vernal point moves backwards fifty arcseconds a year.
 *
 * The coefficients of the matrix are the ones Chapront-Touzé and Chapront published with the
 * lunar theory itself, in the `elp82b.f` subroutine. The same ones are used here, and not
 * another version of precession, so that the Moon and the tabulated bodies are rotated
 * exactly alike: two different precession models in the same engine leave a systematic
 * difference between some bodies and others that afterwards nobody can trace back to its
 * source.
 *
 * The matrix only deals with the plane. The backwards motion of the vernal point goes
 * separately, in `PRECESSION_IN_LONGITUDE`, because the system the series are written in uses an
 * inertial origin that does not move backwards.
 */
class Precession
{
    /** General precession in longitude, in arcseconds per Julian century. */
    /**
     * The linear constant of general precession, which is what the ELP Fortran uses. It is
     * kept as documentation: the real rotation is given by `Time::generalPrecession`, which
     * also carries the quadratic term. Without it, Pluto came out 14 arcseconds off in 1650
     * and in 2350.
     */
    public const PRECESSION_IN_LONGITUDE = 5029.0966;

    /** @var list<float> */
    private const P = [0.10180391e-4, 0.47020439e-6, -0.5417367e-9, -0.2507948e-11, 0.463486e-14];

    /** @var list<float> */
    private const Q = [-0.113469002e-3, 0.12372674e-6, 0.1265417e-8, -0.1371808e-11, -0.320334e-14];

    /**
     * From the J2000 ecliptic to the classical ecliptic of date.
     *
     * @param array{0: float, 1: float, 2: float} $j2000 Rectangular.
     * @param float $t Julian centuries since J2000.
     * @return array{0: float, 1: float, 2: float} Rectangular in the ecliptic of date.
     */
    public static function toDate(array $j2000, float $t): array
    {
        [$pw2, $qw2, $pwqw, $pw, $qw] = self::matrix($t);

        // The transpose of the matrix that goes from date to J2000. A rotation is
        // orthogonal, so its inverse is its transpose and there is nothing to invert.
        $inertial = [
            $pw2 * $j2000[0] + $pwqw * $j2000[1] - $pw * $j2000[2],
            $pwqw * $j2000[0] + $qw2 * $j2000[1] + $qw * $j2000[2],
            $pw * $j2000[0] - $qw * $j2000[1] + ($pw2 + $qw2 - 1) * $j2000[2],
        ];

        // From the inertial origin to the vernal point of date: just a rotation in longitude.
        return self::rotateInLongitude($inertial, -deg2rad(Time::generalPrecession($t) / 3600));
    }

    /**
     * From the ecliptic of date to the J2000 one. The way back.
     *
     * @param array{0: float, 1: float, 2: float} $date
     * @param float $t
     * @return array{0: float, 1: float, 2: float}
     */
    public static function toJ2000(array $date, float $t): array
    {
        [$pw2, $qw2, $pwqw, $pw, $qw] = self::matrix($t);

        $inertial = self::rotateInLongitude($date, deg2rad(Time::generalPrecession($t) / 3600));

        return [
            $pw2 * $inertial[0] + $pwqw * $inertial[1] + $pw * $inertial[2],
            $pwqw * $inertial[0] + $qw2 * $inertial[1] - $qw * $inertial[2],
            -$pw * $inertial[0] + $qw * $inertial[1] + ($pw2 + $qw2 - 1) * $inertial[2],
        ];
    }

    /**
     * The five numbers that define the rotation at one instant.
     *
     * @param float $t
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float}
     */
    private static function matrix(float $t): array
    {
        $pw = self::polynomial(self::P, $t) * $t;
        $qw = self::polynomial(self::Q, $t) * $t;

        $ra = 2 * sqrt(1 - $pw * $pw - $qw * $qw);

        return [
            1 - 2 * $pw * $pw,
            1 - 2 * $qw * $qw,
            2 * $pw * $qw,
            $pw * $ra,
            $qw * $ra,
        ];
    }

    /**
     * Rotates the REFERENCE FRAME in longitude, not the point.
     *
     * Hence the sign: rotating the axes by a positive angle SUBTRACTS that angle from the
     * longitude of any fixed point. Getting it backwards does not give a small error, it
     * gives twice the error you meant to correct, which in precession is almost three
     * degrees in a century.
     *
     * @param array{0: float, 1: float, 2: float} $vector
     * @param float $angle
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rotateInLongitude(array $vector, float $angle): array
    {
        $c = cos($angle);
        $s = sin($angle);

        return [
            $c * $vector[0] + $s * $vector[1],
            -$s * $vector[0] + $c * $vector[1],
            $vector[2],
        ];
    }

    /**
     * @param list<float> $coefficients
     * @param float $t
     * @return float
     */
    private static function polynomial(array $coefficients, float $t): float
    {
        $value = 0.0;

        foreach (array_reverse($coefficients) as $coefficient) {
            $value = $value * $t + $coefficient;
        }

        return $value;
    }
}
