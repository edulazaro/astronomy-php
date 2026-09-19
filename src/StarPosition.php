<?php

namespace Astronomy;

/**
 * Where a fixed star is at a given instant, in the two systems that get read.
 *
 * `Position` will not do, because it requires a `Body`, and a star is not one: it has no
 * distance that matters and no speed to read. What it does have, and a planet does not, is
 * that it is looked up in BOTH coordinates: in ecliptic longitude for the conjunction,
 * which is how it has been read since Ptolemy, and in declination for the parallel, which
 * is the modern reading. That is why all four are stored.
 *
 * "Of the date" means referred to the equinox and the ecliptic of the instant, which is
 * the system the chart is in. The star "does not move", but the system does: fifty
 * arcseconds a year, one degree every seventy-two years.
 */
readonly class StarPosition
{
    /**
     * The position of a fixed star at an instant.
     */
    public function __construct(
        public Star $star,
        /** Ecliptic longitude of the date, in degrees. */
        public float $longitude,
        /** Ecliptic latitude, in degrees. */
        public float $latitude,
        /** Right ascension of the date, in degrees. */
        public float $rightAscension,
        /** Declination of the date, in degrees. */
        public float $declination,
        /** True if it carries aberration and nutation; false if it is the mean position. */
        public bool $apparent,
    ) {}

    /**
     * @return Sign
     */
    /**
     * The same position in the sidereal zodiac: the longitude minus the ayanamsa.
     *
     * Only the longitude changes. The declination and the right ascension are equatorial
     * and know nothing of zodiacs, and the latitude is measured from the ecliptic, which is
     * the same one.
     *
     * @param float $offset Degrees to subtract.
     * @return self
     */
    public function shifted(float $offset): self
    {
        return new self(
            star: $this->star,
            longitude: fmod(fmod($this->longitude - $offset, 360) + 360, 360),
            latitude: $this->latitude,
            rightAscension: $this->rightAscension,
            declination: $this->declination,
            apparent: $this->apparent,
        );
    }

    /**
     * The sign the star falls in.
     *
     * @return Sign
     */
    public function sign(): Sign
    {
        return Sign::fromLongitude($this->longitude);
    }

    /**
     * @return float
     */
    public function degreesInSign(): float
    {
        return fmod($this->longitude, 30);
    }

    /**
     * "29° 50' Leo", the way it is written on a chart. No seconds: a star is read to the
     * minute, like everything else.
     *
     * The WHOLE longitude is rounded to minutes before splitting it into sign and degrees.
     * If only the minutes are rounded, 29° 59.6' of Leo goes up to 60 minutes, the carry
     * takes it to "30° 00' Leo" and that degree does not exist: it is 0° 00' of Virgo. It
     * happened to Regulus in the very month of its ingress, which is exactly when someone
     * looks.
     *
     * @return string
     */
    public function formatted(): string
    {
        $minutes = ((int) round($this->longitude * 60)) % 21600;

        return sprintf(
            '%d° %02d\' %s',
            intdiv($minutes % 1800, 60),
            $minutes % 60,
            Sign::fromLongitude($minutes / 60)->name()
        );
    }

    /**
     * The declination written out, with its sign.
     *
     * @return string
     */
    public function formattedDeclination(): string
    {
        $absolute = abs($this->declination);
        $degrees = (int) floor($absolute);
        $minutes = (int) round(($absolute - $degrees) * 60);

        if ($minutes === 60) {
            $minutes = 0;
            $degrees++;
        }

        return sprintf('%s%d° %02d\'', $this->declination < 0 ? '-' : '+', $degrees, $minutes);
    }
}
