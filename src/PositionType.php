<?php

namespace Astronomy;

/**
 * Which light corrections a position carries.
 *
 * These are the Swiss flags that change WHAT is computed without changing where it is looked at
 * from: `SEFLG_NOABERR`, `SEFLG_NOGDEFL`, `SEFLG_ASTROMETRIC` and `SEFLG_TRUEPOS`. The light that
 * arrives from a body goes through three things, and each case says which ones are accounted for:
 *
 * - **Light time**: what is seen is where the body was when the light left it, not where it is.
 * - **Deflection**: the mass of the Sun bends it as it passes close by.
 * - **Aberration**: the motion of the observer tilts it.
 *
 * **There is no case without light time and with aberration, and that is on purpose.** Swiss does
 * not have one either: measured, `SEFLG_TRUEPOS` gives exactly the same as `SEFLG_TRUEPOS |
 * SEFLG_NOABERR | SEFLG_NOGDEFL`. And it makes sense, because aberration and light time are the two
 * halves of the same computation (the observer moves while the light travels), and keeping just one
 * of them is not the position of anything.
 *
 * What each case changes depends on the frame: from the Sun or from the barycentre there is no
 * aberration and no deflection to remove, because the origin does not move, so there only the
 * geometric one gives something different.
 */
enum PositionType implements Translatable
{
    /** Where it is seen: light time, deflection and aberration. This is the one the chart uses. */
    case Apparent;

    /** With light time and deflection, without aberration: `SEFLG_NOABERR`. */
    case NoAberration;

    /** With light time and aberration, without deflection: `SEFLG_NOGDEFL`. */
    case NoDeflection;

    /** Light time only: `SEFLG_ASTROMETRIC`, the position of the catalogues. */
    case Astrometric;

    /** Where the body is at that instant, with nothing applied: `SEFLG_TRUEPOS`. */
    case Geometric;

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
            self::Apparent => 'apparent',
            self::NoAberration => 'no-aberration',
            self::NoDeflection => 'no-deflection',
            self::Astrometric => 'astrometric',
            self::Geometric => 'geometric',
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
            self::Apparent => 'Apparent',
            self::NoAberration => 'Without aberration',
            self::NoDeflection => 'Without deflection',
            self::Astrometric => 'Astrometric',
            self::Geometric => 'Geometric',
        };
    }

    /**
     * @return bool
     */
    public function lightTime(): bool
    {
        return $this !== self::Geometric;
    }

    /**
     * @return bool
     */
    public function deflection(): bool
    {
        return $this === self::Apparent || $this === self::NoAberration;
    }

    /**
     * @return bool
     */
    public function aberration(): bool
    {
        return $this === self::Apparent || $this === self::NoDeflection;
    }
}
