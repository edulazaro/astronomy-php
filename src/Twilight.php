<?php

namespace Astronomy;

/**
 * The three twilights, defined by how far the centre of the Sun is below the horizon.
 *
 * They do not depend on refraction or on the size of the disc: they are geometric angles
 * fixed by convention. Civil twilight is when you can still read in the street, nautical
 * twilight when the sea horizon is still discernible to take an altitude with the sextant,
 * and astronomical twilight when the sky is already completely black.
 */
enum Twilight: int implements Translatable
{
    case Civil = 6;
    case Nautical = 12;
    case Astronomical = 18;

    /**
     * The stable key.
     *
     * It is written out and is not the value, because the value is a number that means something
     * else and would be a poor thing to index a translation by.
     *
     * @return string
     */
    public function key(): string
    {
        return match ($this) {
            self::Civil => 'civil',
            self::Nautical => 'nautical',
            self::Astronomical => 'astronomical',
        };
    }

    /**
     * @return string
     */
    public function name(): string
    {
return match ($this) {
            self::Civil => 'Civil twilight',
            self::Nautical => 'Nautical twilight',
            self::Astronomical => 'Astronomical twilight',
        };
    }
}
