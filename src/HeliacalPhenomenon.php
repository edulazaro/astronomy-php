<?php

namespace Astronomy;

/**
 * A heliacal phenomenon that has been found: the day an object becomes visible again or
 * stops being visible.
 *
 * It carries two instants, and both are needed. `pass` is when the object crosses the
 * geometric horizon, which is where the arc is MEASURED because that is where Schoch defines
 * it; there its altitude is zero and says nothing. `observation` is the moment it is SEEN:
 * when the sky reaches the required darkness, that is, when the Sun is
 * `arcusVisionisRequired` below the horizon. There the object is already (or still) above the
 * horizon, and `objectAltitude` is by how much: on the day of the event it is a small
 * positive number, and the day before it would have come out negative, which is exactly how
 * the criterion is checked.
 *
 * In a morning phenomenon the object rises first and the sky brightens afterwards, so
 * `observation` comes AFTER `pass`; in an evening one, the other way round.
 */
readonly class HeliacalPhenomenon
{
    /**
     * One heliacal event: what was seen, when, and how high.
     */
    public function __construct(
        /** What the thing that appears or disappears is called: «Sirio», «Venus». */
        public string $name,
        public Body|Star $target,
        public HeliacalEvent $event,
        /** The moment of the observation: the Sun at the required depression. */
        public UtInstant $observation,
        /** The object's crossing of the geometric horizon that day, where the arc is measured. */
        public UtInstant $pass,
        /** The Sun's depression at `pass`, in degrees: that day's arcus visionis. */
        public float $arcusVisionis,
        /** The arc the criterion requires, in degrees. The Sun's depression at `observation`. */
        public float $arcusVisionisRequired,
        /**
         * Altitude of the object at `observation`, in degrees, GEOMETRIC and not apparent: it
         * is the one that decides, because the criterion disregards refraction in both
         * bodies. With refraction it would be seen half a degree higher, and that half a
         * degree is half a day of date.
         */
        public float $objectAltitude,
        /**
         * Azimuth difference between the object and the Sun at `observation`, in degrees and
         * unsigned. It is Schoch's «A», and it tells whether his star table applies: he wrote
         * it for stars with A smaller than 25 degrees.
         */
        public float $azimuthDifference,
        /** Angular separation between the object and the Sun at `observation`, in degrees. */
        public float $elongation,
    ) {}

    /**
     * How much is left over with respect to the criterion, in degrees. Zero is the exact day;
     * the larger it is, the deeper inside the event the day that was found lies.
     *
     * @return float
     */
    public function margin(): float
    {
        return $this->arcusVisionis - $this->arcusVisionisRequired;
    }

    /**
     * A written line, to paste into a text or a table:
     * «Heliacal rising of Sirius: 2000-08-15, at 4° 20' of altitude, with the Sun 7° 48'
     * below the horizon».
     *
     * The date is formatted by whoever composes it; here it is written with the
     * time zone of the place, which is the one `UtInstant` carries.
     *
     * @return string
     */
    public function summary(): string
    {
        return sprintf(
            '%s of %s: %s, at %s of altitude, with the Sun %s below the horizon',
            $this->event->name(),
            $this->name,
            $this->observation->date->format('Y-m-d'),
            self::inDegrees($this->objectAltitude),
            self::inDegrees($this->arcusVisionisRequired)
        );
    }

    /**
     * @param float $degrees
     * @return string
     */
    private static function inDegrees(float $degrees): string
    {
        $wholeDegrees = (int) floor(abs($degrees));
        $minutes = (int) round((abs($degrees) - $wholeDegrees) * 60);

        if ($minutes === 60) {
            $minutes = 0;
            $wholeDegrees++;
        }

        return sprintf("%s%d° %02d'", $degrees < 0 ? '-' : '', $wholeDegrees, $minutes);
    }
}
