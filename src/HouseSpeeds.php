<?php

namespace Astronomy;

/**
 * How fast the twelve cusps and the eight points of a chart are moving, in degrees per day.
 *
 * It is what `swe_houses_ex2` and `swe_houses_armc_ex2` return alongside the cusps, and it comes
 * out of `Houses::speeds()`. What it is good for is saying how sharp a cusp is: an ascendant
 * running at 283 degrees a day (Madrid, its slowest) moves a degree in five minutes of clock
 * time, and one running at 624 (Madrid, its fastest) moves a degree in two and a half. That is
 * how much a badly noted birth hour costs, said as a number instead of as a warning.
 *
 * **The midheaven is the boring one and that is the point**: it runs between 331 and 393 degrees
 * a day at every latitude on Earth, because it only depends on the obliquity. Everything that
 * varies from place to place varies through the horizon.
 *
 * A null speed means the point itself is null: the vertex does not exist at the equator, and
 * something that is not there has no motion.
 */
readonly class HouseSpeeds
{
    /**
     * @param array<int, float> $cusps All twelve, from 1 to 12, in degrees per day.
     * @param float $ascendant
     * @param float $midheaven
     * @param float $armc How fast local sidereal time itself runs, which is the rotation of the
     *                    Earth and the same number for every chart.
     * @param float|null $vertex Null at the equator, where there is no vertex.
     * @param float $eastPoint
     * @param float|null $kochCoAscendant
     * @param float|null $munkaseyCoAscendant
     * @param float|null $polarAscendant
     */
    public function __construct(
        public array $cusps,
        public float $ascendant,
        public float $midheaven,
        public float $armc,
        public ?float $vertex,
        public float $eastPoint,
        public ?float $kochCoAscendant = null,
        public ?float $munkaseyCoAscendant = null,
        public ?float $polarAscendant = null,
    ) {}

    /**
     * The eight points in the order `swe_houses_ex2` puts them in its `ascmc` array, for whoever
     * is comparing against it: ascendant, midheaven, ARMC, vertex, east point, Koch's
     * co-ascendant, Munkasey's co-ascendant and the polar ascendant.
     *
     * @return array<int, float|null>
     */
    public function points(): array
    {
        return [
            $this->ascendant,
            $this->midheaven,
            $this->armc,
            $this->vertex,
            $this->eastPoint,
            $this->kochCoAscendant,
            $this->munkaseyCoAscendant,
            $this->polarAscendant,
        ];
    }
}
