<?php

namespace Astronomy;

/**
 * The four passes of a body across the sky of a place over the course of the day.
 *
 * The culminations are the meridian passes: the upper one is when it crosses the
 * meridian on its high side (noon, for the Sun) and the lower one on its low side,
 * which for almost everything falls below the horizon and for a circumpolar body is
 * the lowest point of its turn.
 */
enum Pass implements Translatable
{
    case Rise;
    case Set;
    case UpperCulmination;
    case LowerCulmination;

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
            self::Rise => 'rise',
            self::Set => 'set',
            self::UpperCulmination => 'upper-culmination',
            self::LowerCulmination => 'lower-culmination',
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
            self::Rise => 'Rise',
            self::Set => 'Set',
            self::UpperCulmination => 'Upper culmination',
            self::LowerCulmination => 'Lower culmination',
        };
    }

    /**
     * @return bool
     */
    public function isMeridianPass(): bool
    {
        return $this === self::UpperCulmination || $this === self::LowerCulmination;
    }
}
