<?php

namespace Astronomy;

/**
 * What is seen from one place when the Moon passes in front of something: the Sun in an
 * eclipse, a planet or a star in an occultation.
 *
 * It is the same geometry in both cases, and that is why it is the same class: two discs
 * in the sky, four contacts where their edges touch, and a maximum. The second and third
 * contacts only exist when one disc gets entirely inside the other (or the small one
 * entirely inside the big one); for a partial they are null.
 *
 * The instants are given in the time zone of the place. **Everything is computed even if the
 * body is below the horizon** at some of the contacts, and which one is seen and which is not
 * is told in `visible`: an eclipse that starts before the Sun rises has its first contact all
 * the same, only nobody sees it from there.
 */
readonly class LocalCircumstances
{
    /**
     * @param EclipseType $type What is seen from HERE: a total eclipse within its path is
     *        partial a thousand kilometres to the side.
     * @param float $magnitude Fraction of the body's diameter covered at maximum, capped
     *        at one. For the magnitude of a total one as NASA publishes it, which is the
     *        ratio of diameters and goes past one, there is `nasaMagnitude()`.
     * @param float $diameterRatio Apparent diameter of the Moon divided by that of the body.
     * @param float $obscuration Fraction of the DISC covered, which is not the same thing:
     *        at 50% magnitude, 40% of the surface is covered.
     * @param float $altitude True altitude of the body at maximum, in degrees.
     * @param array<string, bool> $visible By phase: maximum, contact1 to contact4.
     * @param UtInstant|null $rise Rise of the body if it falls within the eclipse.
     * @param UtInstant|null $set Setting of the body if it falls within the eclipse.
     */
    public function __construct(
        public EclipseType $type,
        public UtInstant $maximum,
        public UtInstant $contact1,
        public ?UtInstant $contact2,
        public ?UtInstant $contact3,
        public UtInstant $contact4,
        public float $magnitude,
        public float $diameterRatio,
        public float $obscuration,
        public float $minimumSeparation,
        public float $altitude,
        public float $apparentAltitude,
        public float $azimuth,
        public array $visible,
        public ?UtInstant $rise,
        public ?UtInstant $set,
    ) {}

    /**
     * The magnitude as NASA publishes it: in a partial one, the fraction of the diameter
     * covered; in a total or an annular one, the Moon/body ratio of diameters.
     *
     * @return float
     */
    public function nasaMagnitude(): float
    {
        return $this->type->hasCentralPhase() ? $this->diameterRatio : $this->magnitude;
    }

    /**
     * Whether any phase is seen from the place.
     *
     * @return bool
     */
    public function isVisible(): bool
    {
        return in_array(true, $this->visible, true);
    }

    /**
     * Duration from start to end, in seconds.
     *
     * @return float
     */
    public function duration(): float
    {
        return $this->contact1->secondsTo($this->contact4);
    }

    /**
     * Duration of the central phase (totality or annularity), in seconds. Null if there is none.
     *
     * @return float|null
     */
    public function centralDuration(): ?float
    {
        if ($this->contact2 === null || $this->contact3 === null) {
            return null;
        }

        return $this->contact2->secondsTo($this->contact3);
    }
}
