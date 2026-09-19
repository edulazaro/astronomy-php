<?php

namespace Astronomy;

/**
 * Which point of the disc decides the rise or the set.
 *
 * For the Sun and the Moon what is seen coming up is the upper edge, and that is what
 * the almanacs publish: the upper limb. The centre is what you use when you want a
 * geometric instant with no disc size, and the lower one tells you when the whole disc
 * is already above the horizon.
 *
 * For a planet or a star the three coincide, because their disc has no size.
 */
enum Limb implements Translatable
{
    case Superior;
    case Center;
    case Inferior;

    /**
     * The stable key.
     *
     * It is written out because this enum carries no value, so the only other identifier would be
     * the name of the case, and that moves the day somebody renames it.
     *
     * @return string
     */
    public function key(): string
    {
        return match ($this) {
            self::Superior => 'upper',
            self::Center => 'center',
            self::Inferior => 'lower',
        };
    }

    /**
     * The name in English, for whoever does not translate.
     *
     * @return string
     */
    public function name(): string
    {
        return match ($this) {
            self::Superior => 'Upper limb',
            self::Center => 'Centre',
            self::Inferior => 'Lower limb',
        };
    }

    /**
     * How much has to be added to the altitude of the centre for that point of the disc
     * to be on the horizon, in units of the semidiameter.
     *
     * @return float
     */
    public function factor(): float
    {
        return match ($this) {
            self::Superior => 1.0,
            self::Center => 0.0,
            self::Inferior => -1.0,
        };
    }
}
