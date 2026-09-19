<?php

namespace Astronomy;

/**
 * The eclipse types, solar and lunar, in a single list because they share three names
 * and those names do not mean the same thing in each case.
 *
 * Solar: partial when the Moon covers part of the disc as seen from anywhere;
 * annular when a ring of Sun is left around it, because the Moon is far away and looks
 * small; total when it covers it whole; and hybrid when it is annular along some
 * stretches of the path and total along others, because the curvature of the Earth brings
 * the observer closer by exactly what was missing.
 *
 * Lunar: penumbral when it only enters the penumbra, which is barely noticeable;
 * partial when part of the disc enters the umbra; total when it enters whole.
 */
enum EclipseType: string implements Translatable
{
    case Partial = 'partial';
    case Annular = 'annular';
    case Total = 'total';
    case Hybrid = 'hybrid';
    case Penumbral = 'penumbral';

    /**
     * The stable key, which here is the value: it is already a frozen slug.
     *
     * @return string
     */
    public function key(): string
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function name(): string
    {
return match ($this) {
            self::Partial => 'Partial',
            self::Annular => 'Annular',
            self::Total => 'Total',
            self::Hybrid => 'Hybrid',
            self::Penumbral => 'Penumbral',
        };
    }

    /**
     * Whether there is a phase in which the disc ends up covered entirely or ringed: the
     * ones that have a second and a third contact.
     *
     * @return bool
     */
    public function hasCentralPhase(): bool
    {
        return $this === self::Total || $this === self::Annular || $this === self::Hybrid;
    }
}
