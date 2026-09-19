<?php

namespace Astronomy;

/**
 * A solar eclipse seen from the Earth as a whole: what it is, when, and where it passes.
 *
 * The instants are in UT. The three pairs of contacts are global, not for one place: the
 * first one is when the penumbra first touches the Earth somewhere, and likewise for the
 * umbra and for the central line. What is seen from a specific place is given by
 * `Eclipses::localSolar()`.
 *
 * **Gamma** is the distance from the shadow axis to the centre of the Earth at the moment
 * of maximum, in Earth radii and signed: positive if it passes to the north. It is the
 * number that defines an eclipse in any canon: with |gamma| smaller than one the umbra or
 * the antumbra touch the surface and the eclipse is central; up to 1.55 only the penumbra
 * arrives and it is partial.
 *
 * **The magnitude is that of the point of greatest eclipse**, which is the one NASA
 * publishes: for a partial one, the fraction of the Sun's diameter that is covered; for a
 * total or an annular one, the ratio of the apparent Moon/Sun diameters (greater than one
 * in total eclipses). And the ecliptic longitude of the Sun at maximum is what astrology
 * uses, which reads an eclipse by the degree of the zodiac it falls in.
 */
readonly class SolarEclipse
{
    /**
     * One solar eclipse, with its instants, its type and its greatest point.
     */
    public function __construct(
        public EclipseType $type,
        public bool $central,
        public UtInstant $maximum,
        public float $gamma,
        public float $magnitude,
        public float $diameterRatio,
        public float $obscuration,
        public float $geocentricMagnitude,
        public float $eclipticLongitude,
        public float $maximumLatitude,
        public float $maximumLongitude,
        public ?UtInstant $partialStart,
        public ?UtInstant $partialEnd,
        public ?UtInstant $centralStart,
        public ?UtInstant $centralEnd,
        public ?UtInstant $centralLineStart,
        public ?UtInstant $centralLineEnd,
    ) {}

    /**
     * The zodiac sign it falls in.
     *
     * @return Sign
     */
    public function sign(): Sign
    {
        return Sign::fromLongitude($this->eclipticLongitude);
    }

    /**
     * Degrees within the sign.
     *
     * @return float
     */
    public function degreesInSign(): float
    {
        return fmod($this->eclipticLongitude, 30.0);
    }
}
