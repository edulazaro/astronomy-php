<?php

namespace Astronomy;

/**
 * The visual brightness of a body.
 *
 * The model is the classical one, the same one everybody uses:
 *
 *     V = 5 · log10(r · Δ) + f(α)
 *
 * The first two terms are pure geometry, how much a body dims for being far from the Sun and
 * for being far from us, and there is nothing to fit in them. Everything that is known about
 * each planet (how much it reflects, how it darkens when seen from the side) lives in `f(α)`, a
 * function of the phase angle, and that is the only thing that gets stored.
 *
 * **The coefficients are NOT written by hand: they are fitted against the JPL itself** with
 * `astro:magnitudes`, which asks Horizons for the published magnitude across centuries and
 * strips the geometry out of it. It is the same decision as the ephemeris correction: instead
 * of copying the polynomials out of a paper and hoping they were transcribed right, the fit is
 * made against the source and the residual is measured, which is a number that can be shown.
 *
 * ## Saturn does not fit in a function of the phase angle, and that is why it takes two variables
 *
 * The rings contribute a good part of Saturn's light and they look more or less open depending
 * on where we are: every fifteen years they turn edge-on and disappear. Between the fully open
 * ring and the edge-on ring there is almost **a whole magnitude**, which means that a fit on the
 * phase angle alone leaves Saturn with an error larger than the brightness of many stars. That
 * is why its `f` has two inputs, the phase angle and the ring opening, and that opening is the
 * latitude of the observer as seen from Saturn, which comes from the planet's pole.
 *
 * @see resources/astro/magnitudes.php
 */
class Magnitudes
{
    /**
     * The visual magnitude, or null if there is no model for this body.
     *
     * @param Body $body
     * @param float $r Distance from the body to the Sun, in AU.
     * @param float $delta Distance from the body to the Earth, in AU.
     * @param float $alpha Phase angle, in degrees.
     * @param float|null $ringOpening Saturn only, in degrees.
     * @return float|null
     */
    public static function of(
        Body $body,
        float $r,
        float $delta,
        float $alpha,
        ?float $ringOpening = null,
    ): ?float {
        $model = self::table()[$body->value] ?? null;

        if ($model === null || $r <= 0.0 || $delta <= 0.0) {
            return null;
        }

        /* Outside the range of phases it was fitted with, nothing is made up. An extrapolated
           polynomial blows up, and a blown-up magnitude does not raise an error: it returns a
           number. It really does happen to Mercury and to Venus, because their phase reaches
           almost 180 degrees and there the curve shoots upwards. */
        if ($alpha < $model['alpha'][0] - 1e-9 || $alpha > $model['alpha'][1] + 1e-9) {
            return null;
        }

        $x = self::normalize($alpha, $model['alpha'][0], $model['alpha'][1]);
        $f = Chebyshev::evaluate($model['coefficients'], $x);

        if (isset($model['ring']) && $ringOpening !== null) {
            $b = abs($ringOpening);
            $y = self::normalize(
                min(max($b, $model['ring']['range'][0]), $model['ring']['range'][1]),
                $model['ring']['range'][0],
                $model['ring']['range'][1],
            );

            $f += Chebyshev::evaluate($model['ring']['coefficients'], $y);
        }

        return 5.0 * log10($r * $delta) + $f;
    }

    /**
     * The body's pole in the J2000 ecliptic, for the ones that carry a ring term.
     *
     * @param Body $body
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function pole(Body $body): ?array
    {
        return self::table()[$body->value]['ring']['pole'] ?? null;
    }

    /**
     * The residual each body was fitted with, in magnitudes, so that whoever reads a brightness
     * knows what it is worth.
     *
     * @param Body $body
     * @return float|null
     */
    public static function residual(Body $body): ?float
    {
        return self::table()[$body->value]['residual'] ?? null;
    }

    /**
     * @param float $value
     * @param float $min
     * @param float $max
     * @return float
     */
    private static function normalize(float $value, float $min, float $max): float
    {
        if ($max <= $min) {
            return 0.0;
        }

        return max(-1.0, min(1.0, 2.0 * ($value - $min) / ($max - $min) - 1.0));
    }

    /**
     * @return array<string, array{alpha: array{0: float, 1: float}, coefficients: list<float>, residual: float, ring?: array{range: array{0: float, 1: float}, coefficients: list<float>}}>
     */
    private static function table(): array
    {
        static $table = null;

        if ($table === null) {
            $file = DataFolder::path('magnitudes.php');
            $table = is_file($file) ? require $file : [];
        }

        return $table;
    }
}
