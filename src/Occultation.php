<?php

namespace Astronomy;

use Closure;

/**
 * The Moon passing in front of a planet or a star, seen from the Earth as a whole.
 *
 * It is a solar eclipse with another body in front, and that is why it is described the
 * same way: the maximum, the type (total if somewhere on Earth the disc is covered
 * entirely, partial if there is only a graze), whether it is central (the axis passes
 * through the surface) and when it begins and ends somewhere on the planet. What is seen
 * from one place is given by `Occultations::local()`.
 *
 * A star has no disc, so for it partial does not exist: either it is covered or it is not.
 *
 * `name` says what has been covered exactly as it is written: «Mars», «Antares»,
 * «Aldebaran». `target` is the same thing as an object, so that calculation can continue
 * with it; a star from the catalogue goes as a `Star`, and `star()` returns it without
 * having to ask for the type.
 */
readonly class Occultation
{
    /**
     * @param Body|Star|Closure(float): Equatorial $target What was occulted, so that the
     *        local circumstances can be computed afterwards without searching again.
     */
    public function __construct(
        public string $name,
        public Body|Star|Closure $target,
        public EclipseType $type,
        public bool $central,
        public UtInstant $maximum,
        public float $gamma,
        public float $minimumSeparation,
        public ?UtInstant $start,
        public ?UtInstant $end,
        public float $maximumLatitude,
        public float $maximumLongitude,
    ) {}

    /**
     * The occulted star, or null if what was covered is a body or a bare direction.
     *
     * @return Star|null
     */
    public function star(): ?Star
    {
        return $this->target instanceof Star ? $this->target : null;
    }
}
