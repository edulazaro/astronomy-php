<?php

namespace Astronomy;

/**
 * The closest and the farthest two bodies can ever get from each other, and how far apart they
 * are right now. This is Swiss Ephemeris' `swe_orbit_max_min_true_distance`.
 *
 * It provides what a lone number does not say: that Mars is 0.52 astronomical units away today
 * means nothing until you know it can come as close as 0.37 and go as far as 2.68. It is the
 * same idea as the Moon's illuminated fraction next to its apparent size, or as the "expected"
 * stellium of the birth chart: the raw figure and the reference it is read against.
 *
 * The extremes belong to the two ORBITS, not to the body's lifetime. They come from each
 * one's osculating ellipse at this instant, so they state the closest and the farthest the two
 * could be if both orbits stayed as they are and both bodies were placed at the worst possible
 * spot on each. That configuration may take centuries to happen, or may never happen at all:
 * the minimum for Mars with the Earth is a perfect opposition at its perihelion, which occurs
 * every fifteen or seventeen years and not always equally well.
 */
readonly class ExtremeDistances
{
    /**
     * The closest and furthest a body can get, and where it is today.
     */
    public function __construct(
        public Body|DownloadableBody $body,
        /** The greatest possible distance between the two orbits, in astronomical units. */
        public float $maximum,
        /** The smallest one, in astronomical units. */
        public float $minimum,
        /** The one right now, in astronomical units. */
        public float $current,
    ) {}

    /**
     * Where today's distance falls between the two, from 0 (as close as possible) to 1 (as far
     * as possible). This is what turns the three numbers into a sentence.
     *
     * @return float
     */
    public function fraction(): float
    {
        $span = $this->maximum - $this->minimum;

        return $span > 0 ? ($this->current - $this->minimum) / $span : 0.0;
    }
}
