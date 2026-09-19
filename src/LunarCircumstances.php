<?php

namespace Astronomy;

/**
 * A lunar eclipse seen from one place: which phases catch the Moon above the horizon.
 *
 * There are no local contacts because there are none: the Moon enters the shadow at the
 * same time for everybody. What is local is only whether it is seen, and when the Moon
 * rises or sets if that happens partway through the eclipse.
 */
readonly class LunarCircumstances
{
    /**
     * @param array<string, bool> $visible By phase, in order: p1, u1, u2, maximum, u3, u4, p4.
     * Only the phases this eclipse has.
     * @param float $altitude True altitude of the Moon at maximum.
     * @param UtInstant|null $moonRise If it rises during the eclipse.
     * @param UtInstant|null $moonSet If it sets during the eclipse.
     */
    public function __construct(
        public array $visible,
        public float $altitude,
        public float $apparentAltitude,
        public float $azimuth,
        public ?UtInstant $moonRise,
        public ?UtInstant $moonSet,
    ) {}

    /**
     * @return bool
     */
    public function isVisible(): bool
    {
        return in_array(true, $this->visible, true);
    }

    /**
     * Whether it is seen from start to finish.
     *
     * @return bool
     */
    public function isFullyVisible(): bool
    {
        return ! in_array(false, $this->visible, true);
    }
}
