<?php

namespace Astronomy;

/**
 * Where a body is seen from one particular place: altitude above the horizon and azimuth.
 *
 * It carries two altitudes because there are two: the geometric one, which is where the
 * body is, and the apparent one, which is where it is seen. The atmosphere bends light and
 * lifts everything that is low; at the horizon that is 34 arcminutes, more than the
 * diameter of the Sun. When the limb of the Sun touches the horizon, the whole Sun is
 * already geometrically below it.
 *
 * The azimuth is measured from the NORTH towards the EAST: north 0, east 90, south
 * 180, west 270. It is the convention of navigation and of field astronomy, and the same
 * one used by the vertex check in `HousePositionTest`. Swiss Ephemeris measures it from the south
 * towards the west: to compare against it, add 180 degrees.
 */
readonly class Horizontal
{
    /**
     * A position in horizontal coordinates: azimuth and altitude.
     */
    public function __construct(
        public float $altitude,
        public float $apparentAltitude,
        public float $azimuth,
    ) {}

    /**
     * The same azimuth as Swiss Ephemeris gives it: from the south towards the west.
     *
     * @return float
     */
    public function azimuthFromSouth(): float
    {
        return fmod($this->azimuth + 180.0, 360.0);
    }

    /**
     * Whether it is above the visible horizon, that is, counting refraction.
     *
     * @return bool
     */
    public function isVisible(): bool
    {
        return $this->apparentAltitude > 0.0;
    }
}
