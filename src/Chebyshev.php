<?php

namespace Astronomy;

use RuntimeException;

/**
 * Chebyshev polynomials: fit a series to a set of points and evaluate it.
 *
 * This is how the JPL stores its own ephemerides, and not by chance: for a smooth function,
 * the degree-N Chebyshev polynomial is practically the best degree-N polynomial there is,
 * and its error falls geometrically as the degree goes up. A table of points with Lagrange
 * interpolation, which is what `EphemerisPositions` uses, needs far more room for the same
 * accuracy: the coefficients describe the whole block and the points only describe a single
 * instant.
 *
 * Here it is used to store the CORRECTION of our analytical series towards the JPL, not the
 * position: see `CorrectionTable`.
 */
final class Chebyshev
{
    /**
     * Evaluates the series at a point, by Clenshaw's algorithm.
     *
     * Clenshaw and not the direct sum of `T_j(x)`: it costs the same as a Horner, it does not
     * need to compute the polynomials and it is stable. The convention is the usual one, with
     * no half on the first coefficient: f(x) = c0 + c1·T1(x) + c2·T2(x) + ...
     *
     * @param list<float> $coefficients
     * @param float $x In [-1, 1].
     * @return float
     */
    public static function evaluate(array $coefficients, float $x): float
    {
        $n = count($coefficients);

        if ($n === 0) {
            return 0.0;
        }

        $b1 = 0.0;
        $b2 = 0.0;

        for ($j = $n - 1; $j >= 1; $j--) {
            $b0 = 2.0 * $x * $b1 - $b2 + $coefficients[$j];
            $b2 = $b1;
            $b1 = $b0;
        }

        return $x * $b1 - $b2 + $coefficients[0];
    }

    /**
     * Fits a series of the given degree to a set of points, by least squares.
     *
     * The points do NOT have to fall on the Chebyshev nodes, which is what the exact formula
     * asks for: that would mean asking the JPL for individual instants instead of a series at
     * a fixed step, and there are hundreds of thousands of instants. With uniform sampling the
     * basis stops being orthogonal and the normal system has to be solved, which at these
     * degrees is well conditioned.
     *
     * At least three times as many points as coefficients are required: with just the bare
     * minimum, the fit passes through all of them and says nothing about what lies between
     * them, which is exactly what has to be measured.
     *
     * @param list<float> $x In [-1, 1].
     * @param list<float> $y
     * @param int $degree
     * @return list<float> The degree+1 coefficients.
     */
    public static function fit(array $x, array $y, int $degree): array
    {
        $n = $degree + 1;
        $pointCount = count($x);

        if ($pointCount < $n) {
            throw new RuntimeException("At least {$n} points are needed for degree {$degree}, and there are {$pointCount}.");
        }

        // Normal system: (Bᵀ B) c = Bᵀ y, with B the matrix of the basis evaluated at the points.
        $matrix = array_fill(0, $n, array_fill(0, $n + 1, 0.0));
        $base = array_fill(0, $n, 0.0);

        for ($p = 0; $p < $pointCount; $p++) {
            $xp = $x[$p];
            $base[0] = 1.0;

            if ($n > 1) {
                $base[1] = $xp;
            }

            for ($j = 2; $j < $n; $j++) {
                $base[$j] = 2.0 * $xp * $base[$j - 1] - $base[$j - 2];
            }

            for ($i = 0; $i < $n; $i++) {
                for ($j = $i; $j < $n; $j++) {
                    $matrix[$i][$j] += $base[$i] * $base[$j];
                }

                $matrix[$i][$n] += $base[$i] * $y[$p];
            }
        }

        // It is symmetric: only the upper half was filled in.
        for ($i = 1; $i < $n; $i++) {
            for ($j = 0; $j < $i; $j++) {
                $matrix[$i][$j] = $matrix[$j][$i];
            }
        }

        return self::solve($matrix, $n);
    }

    /**
     * Gauss with partial pivoting. Without pivoting, a zero on the diagonal blows up a system
     * that has a perfectly good solution.
     *
     * @param list<list<float>> $matrix Augmented: n rows of n+1.
     * @param int $n
     * @return list<float>
     */
    private static function solve(array $matrix, int $n): array
    {
        for ($col = 0; $col < $n; $col++) {
            $pivot = $col;

            for ($row = $col + 1; $row < $n; $row++) {
                if (abs($matrix[$row][$col]) > abs($matrix[$pivot][$col])) {
                    $pivot = $row;
                }
            }

            if (abs($matrix[$pivot][$col]) < 1e-300) {
                throw new RuntimeException('The fit system is singular: repeated points or an impossible degree.');
            }

            [$matrix[$col], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$col]];

            for ($row = $col + 1; $row < $n; $row++) {
                $factor = $matrix[$row][$col] / $matrix[$col][$col];

                if ($factor === 0.0) {
                    continue;
                }

                for ($k = $col; $k <= $n; $k++) {
                    $matrix[$row][$k] -= $factor * $matrix[$col][$k];
                }
            }
        }

        $solution = array_fill(0, $n, 0.0);

        for ($row = $n - 1; $row >= 0; $row--) {
            $sum = $matrix[$row][$n];

            for ($k = $row + 1; $k < $n; $k++) {
                $sum -= $matrix[$row][$k] * $solution[$k];
            }

            $solution[$row] = $sum / $matrix[$row][$row];
        }

        return $solution;
    }
}
