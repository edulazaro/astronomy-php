<?php

namespace Astronomy;

/**
 * A lunar eclipse.
 *
 * Unlike a solar one, it looks the same from anywhere the Moon is above the horizon: the
 * Moon enters the Earth's shadow and that happens once, not once per observer. That is why
 * the contacts are the same everywhere, and the only local thing is whether the Moon is
 * above the horizon when they occur (`Eclipses::localLunar()`).
 *
 * The contacts, in the order they happen: P1 enters the penumbra, U1 touches the umbra, U2
 * is entirely inside (totality begins), maximum, U3 starts to leave, U4 leaves the umbra,
 * P4 leaves the penumbra. A partial one has no U2 or U3, and a penumbral one only has P1
 * and P4.
 *
 * **The magnitudes are NASA's**: the umbral one, how much of the Moon's diameter is inside
 * the umbra at maximum; the penumbral one, the same with the penumbra. They go above one
 * when the disc is inside with room to spare.
 */
readonly class LunarEclipse
{
    /**
     * One lunar eclipse, with its instants and its magnitudes.
     */
    public function __construct(
        public EclipseType $type,
        public UtInstant $maximum,
        public float $gamma,
        public float $umbralMagnitude,
        public float $penumbralMagnitude,
        public float $eclipticLongitude,
        public UtInstant $p1,
        public ?UtInstant $u1,
        public ?UtInstant $u2,
        public ?UtInstant $u3,
        public ?UtInstant $u4,
        public UtInstant $p4,
    ) {}

    /**
     * The zodiac sign the Moon falls in.
     *
     * @return Sign
     */
    public function sign(): Sign
    {
        return Sign::fromLongitude($this->eclipticLongitude);
    }

    /**
     * Degrees within the sign.
     *
     * @return float
     */
    public function degreesInSign(): float
    {
        return fmod($this->eclipticLongitude, 30.0);
    }

    /**
     * The contacts it has, by name and in order.
     *
     * @return array<string, UtInstant>
     */
    public function contacts(): array
    {
        return array_filter([
            'p1' => $this->p1,
            'u1' => $this->u1,
            'u2' => $this->u2,
            'maximum' => $this->maximum,
            'u3' => $this->u3,
            'u4' => $this->u4,
            'p4' => $this->p4,
        ]);
    }
}
