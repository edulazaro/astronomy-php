<?php

namespace Astronomy;

/**
 * A direction in the sky referred to the equator: right ascension and declination, in degrees,
 * and the distance in kilometres if the object has one.
 *
 * The distance is `null` for whatever is so far away that it cannot be measured from two
 * places on Earth: a star. That null is not a missing datum, it is a property: without
 * distance there is no parallax, so the topocentric direction is the geocentric one, full
 * stop. The Moon, on the other hand, is so close that seen from the ground it shifts up to
 * a degree with respect to where the centre of the Earth puts it, and that is why it carries
 * the distance.
 */
readonly class Equatorial
{
    /**
     * Kilometres in one astronomical unit: the exact IAU 2012 definition, which is also the
     * `AU` constant from the DE440 header. This is the ONLY place where it is written down:
     * `Moon` uses it to turn ELP's kilometres into AU, `Horizon` for the opposite, and the
     * tests to subtract in kilometres. Two copies of the same number are two places to fix
     * it the day it needs fixing and one place to forget it.
     */
    public const AU_KM = 149597870.7;

    /**
     * A position in equatorial coordinates of date.
     */
    public function __construct(
        public float $rightAscension,
        public float $declination,
        public ?float $distanceKm = null,
    ) {}

    /**
     * From a rectangular vector in kilometres, axes of the true equator of date.
     *
     * @param array{0: float, 1: float, 2: float} $vector
     * @return self
     */
    public static function fromVector(array $vector): self
    {
        [$x, $y, $z] = $vector;
        $distance = sqrt($x * $x + $y * $y + $z * $z);

        return new self(
            rightAscension: fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0),
            declination: rad2deg(asin($z / $distance)),
            distanceKm: $distance,
        );
    }

    /**
     * A star: a direction without distance.
     *
     * @param float $rightAscension
     * @param float $declination
     * @return self
     */
    public static function direction(float $rightAscension, float $declination): self
    {
        return new self($rightAscension, $declination, null);
    }

    /**
     * Unit vector in the same axes.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function unit(): array
    {
        $a = deg2rad($this->rightAscension);
        $d = deg2rad($this->declination);

        return [cos($d) * cos($a), cos($d) * sin($a), sin($d)];
    }

    /**
     * Vector in kilometres. Without distance there is no vector, and asking for it is a
     * mistake by the caller, not a case worth papering over with a huge number.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function vector(): array
    {
        if ($this->distanceKm === null) {
            throw new \LogicException('A direction without distance has no vector in kilometres.');
        }

        [$x, $y, $z] = $this->unit();

        return [$x * $this->distanceKm, $y * $this->distanceKm, $z * $this->distanceKm];
    }

    /**
     * Angle between two directions, in degrees. Through the dot product with `atan2`, which
     * does not lose precision near zero the way `acos` does: two bodies one arcsecond apart
     * give a cosine of 0.99999999999 and there `acos` already sees nothing but zeros.
     *
     * @param Equatorial $other
     * @return float
     */
    public function separation(Equatorial $other): float
    {
        [$ax, $ay, $az] = $this->unit();
        [$bx, $by, $bz] = $other->unit();

        $cross = sqrt(
            ($ay * $bz - $az * $by) ** 2
            + ($az * $bx - $ax * $bz) ** 2
            + ($ax * $by - $ay * $bx) ** 2
        );
        $dot = $ax * $bx + $ay * $by + $az * $bz;

        return rad2deg(atan2($cross, $dot));
    }
}
