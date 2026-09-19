<?php

namespace Astronomy;

/**
 * A point of the band of a solar eclipse: where the shadow is at a given instant.
 *
 * The latitude and longitude are those of the AXIS of the shadow on the ellipsoid, that is,
 * the central line, which is where the eclipse lasts longest and where everyone stops to
 * watch it. The two limits are the edges of the band at that instant, and the width is what
 * lies from one to the other, measured perpendicular to the direction of travel. The
 * duration is that of the central phase for someone standing still on the central line:
 * from second to third contact.
 *
 * **The three numbers of the band can be missing even when there is a central line**, and
 * that is why they go as null and not as zero. At the two ends of the path the axis arrives
 * grazing: the Sun is on the horizon, the shadow stretches over the ground until it runs off
 * the globe and the edge stops being a closed curve. A zero there would be a band of zero
 * width, which is exactly the opposite of what happens (that is where it looks widest).
 *
 * The Sun's altitude is the geometric one, without refraction, above the horizon of the point
 * on the central line. It is the number that tells you at a glance whether that stretch of the
 * path is seen high in the sky or hugging the horizon, and it is also what the NASA catalogue
 * publishes.
 */
readonly class PathPoint
{
    /**
     * One point of the central path of a solar eclipse.
     */
    public function __construct(
        public UtInstant $observation,
        public float $latitude,
        public float $longitude,
        public float $sunAltitude,
        public ?float $widthKm,
        public ?float $durationSeconds,
        public ?float $northLatitude,
        public ?float $northLongitude,
        public ?float $southLatitude,
        public ?float $southLongitude,
    ) {}

    /**
     * Whether at this instant the band has edges and its width can be measured. False at the
     * ends of the path, where the shadow arrives grazing and runs off the globe.
     *
     * @return bool
     */
    public function hasEdges(): bool
    {
        return $this->widthKm !== null;
    }
}
