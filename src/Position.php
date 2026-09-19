<?php

namespace Astronomy;

/**
 * Where a body is, seen from the Earth or from whatever frame was asked for.
 *
 * Ecliptic longitude and latitude in degrees, distance in astronomical units and speed in
 * degrees per day. The speed lives here and is not calculated separately because retrogradation
 * comes out of it, and in astrology a retrograde planet is read differently: it is not an
 * incidental detail of the position, it is part of it.
 *
 * What `Ephemeris` returns also brings the latitude speed and the distance speed, which come
 * out of the same central differences without costing a single extra ephemeris. A `Position`
 * built by hand may not bring them, and then they go to null: a zero there would be a body
 * that neither approaches nor recedes, which is a datum and not an absence.
 */
readonly class Position
{
    /**
     * A position of a body, with its velocity.
     */
    public function __construct(
        /** A body of the engine or one downloaded from the JPL (`DownloadableBody`). */
        public Body|DownloadableBody $body,
        public float $longitude,
        public float $latitude,
        public float $distance,
        public float $speed,
        /** Degrees per day. */
        public ?float $latitudeSpeed = null,
        /** Astronomical units per day. */
        public ?float $distanceSpeed = null,
    ) {}

    /**
     * The same position in the sidereal zodiac: the longitude minus the ayanamsa.
     *
     * Only the longitude changes. The latitude is measured from the same ecliptic, the distance
     * is what it is, and the speed is a difference of longitudes, from which the ayanamsa
     * cancels out (it changes fifty arcseconds a year, that is, nothing in a day).
     *
     * @param float $offset Degrees to subtract. With zero it returns the same instance.
     * @return self
     */
    public function shifted(float $offset): self
    {
        if ($offset === 0.0) {
            return $this;
        }

        return new self(
            body: $this->body,
            longitude: fmod(fmod($this->longitude - $offset, 360) + 360, 360),
            latitude: $this->latitude,
            distance: $this->distance,
            speed: $this->speed,
            latitudeSpeed: $this->latitudeSpeed,
            distanceSpeed: $this->distanceSpeed,
        );
    }

    /**
     * The same position in rectangular coordinates, in astronomical units: x towards the Aries
     * point of that ecliptic, z towards its north pole. It is Swiss's `SEFLG_XYZ`.
     *
     * They go in the ecliptic the position was asked for in, so there is nothing to choose
     * here. A node or a Lilith come out at the origin, because they have no distance: they are
     * a direction and not a point, and whoever wants the direction has the longitude.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function rectangular(): array
    {
        $l = deg2rad($this->longitude);
        $b = deg2rad($this->latitude);

        return [
            $this->distance * cos($b) * cos($l),
            $this->distance * cos($b) * sin($l),
            $this->distance * sin($b),
        ];
    }

    /**
     * The velocity in rectangular coordinates, in astronomical units per day. It is Swiss's
     * `SEFLG_XYZ | SEFLG_SPEED`.
     *
     * **The velocity is not carried over to rectangular coordinates as if it were another
     * position**, which is the trap already noted in `Horizon::equatorialWithSpeed`: it comes
     * out of differentiating the three formulas above, and that is why the three speeds are
     * needed and not just the longitude one. Without them it returns null instead of assuming
     * that the latitude and the distance do not change.
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public function rectangularVelocity(): ?array
    {
        if ($this->latitudeSpeed === null || $this->distanceSpeed === null) {
            return null;
        }

        $l = deg2rad($this->longitude);
        $b = deg2rad($this->latitude);
        $r = $this->distance;
        $dl = deg2rad($this->speed);
        $db = deg2rad($this->latitudeSpeed);
        $dr = $this->distanceSpeed;

        return [
            $dr * cos($b) * cos($l) - $r * sin($b) * cos($l) * $db - $r * cos($b) * sin($l) * $dl,
            $dr * cos($b) * sin($l) - $r * sin($b) * sin($l) * $db + $r * cos($b) * cos($l) * $dl,
            $dr * sin($b) + $r * cos($b) * $db,
        ];
    }

    /**
     * A planet goes backwards when its longitude decreases. It does not really go back: it is
     * that the Earth overtakes it on the inside.
     *
     * @return bool
     */
    public function isRetrograde(): bool
    {
        return $this->speed < 0;
    }

    /**
     * Which sign it falls in. Twelve thirty-degree sectors from the Aries point.
     *
     * @return Sign
     */
    public function sign(): Sign
    {
        return Sign::fromLongitude($this->longitude);
    }

    /**
     * How many degrees it has covered inside its sign.
     *
     * @return float
     */
    public function degreesInSign(): float
    {
        return fmod($this->longitude, 30);
    }

    /**
     * "12° 34' 56" Leo", which is how a position is written in a chart.
     *
     * The sign is named in English, like everything else the engine returns.
     *
     * @return string
     */
    public function formatted(): string
    {
        /* The WHOLE longitude is rounded to arcseconds first, and then split. The other way
           round, the carry climbs from the seconds to the minutes and from the minutes to the
           degrees, but it cannot reach the sign, which still comes from the unrounded longitude:
           29° 59' 59.6" of Leo came out as «30° 00' 00" Leo», a degree that does not exist next
           to a sign that is no longer the one. It is the same trap `StarPosition::formatted()`
           already has solved, and it is rare rather than impossible: it needs a longitude within
           half an arcsecond below a boundary, which is where an ingress happens. */
        $seconds = (((int) round($this->longitude * 3600.0)) % 1296000 + 1296000) % 1296000;
        $inSign = $seconds % 108000;

        return sprintf(
            '%d° %02d\' %02d" %s%s',
            intdiv($inSign, 3600),
            intdiv($inSign % 3600, 60),
            $inSign % 60,
            Sign::fromLongitude($seconds / 3600.0)->name(),
            $this->isRetrograde() ? ' R' : ''
        );
    }
}
