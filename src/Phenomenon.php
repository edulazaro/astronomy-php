<?php

namespace Astronomy;

/**
 * What can be seen of a body from here: its phase, its size and its brightness.
 *
 * These are the five numbers that Swiss's `swe_pheno` returns, and they answer questions that
 * longitude does not: whether Venus is at first quarter, how much of the sky Jupiter takes up,
 * or whether Mercury can be seen with the naked eye this week.
 */
class Phenomenon
{
    /**
     * @param Body|DownloadableBody $body
     * @param float $phaseAngle Sun-body-Earth, in degrees. Zero is the body right
     * opposite the Sun, that is, full; 180 is between the Sun and
     * us, that is, in the dark.
     * @param float $illuminatedFraction From 0 to 1, the part of the disc that is seen lit.
     * @param float $elongation Sun-Earth-body, in degrees: how far it gets from the Sun in the
     * sky. It is what decides whether a planet can be looked at.
     * @param float $apparentDiameter Degrees taken up by the whole disc.
     * @param float|null $magnitude Visual brightness. Null if there is no model for this body.
     */
    public function __construct(
        public Body|DownloadableBody $body,
        public float $phaseAngle,
        public float $illuminatedFraction,
        public float $elongation,
        public float $apparentDiameter,
        public ?float $magnitude = null,
    ) {}

    /**
     * The apparent diameter in arcseconds, which is how it is always published.
     *
     * @return float
     */
    public function diameterInArcseconds(): float
    {
        return $this->apparentDiameter * 3600.0;
    }

    /**
     * The illuminated percentage, rounded, which is how it is said out loud.
     *
     * @return int
     */
    public function percentIlluminated(): int
    {
        return (int) round($this->illuminatedFraction * 100.0);
    }

    /**
     * Whether the body is waxing or waning.
     *
     * This is decided by the elongation measured from 0 to 360, not by the phase angle,
     * which is symmetric: the first quarter and the last quarter both have 90 degrees of phase
     * and half the disc lit, and the only thing that tells them apart is which side of the Sun
     * the body is on. It is the same trap already noted in `MoonPhase` with the lunar
     * elongation.
     *
     * @param float $orientedElongation The elongation from 0 to 360, from the Sun to the body.
     * @return bool
     */
    public static function isWaxing(float $orientedElongation): bool
    {
        return fmod($orientedElongation + 360.0, 360.0) < 180.0;
    }
}
