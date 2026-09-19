<?php

namespace Astronomy;

/**
 * Which ecliptic a longitude is measured on, and from which equinox.
 *
 * These are Swiss's `SEFLG_NONUT` and `SEFLG_J2000`. The chart uses the true ecliptic of date:
 * the plane and the origin of that same day with nutation applied, which is where the sky is seen.
 *
 * The mean one and the true one are the same plane, and all they differ by is the nutation in
 * longitude, up to seventeen arcseconds: the nutation in obliquity tilts the equator, not the
 * ecliptic. The J2000 one is another plane and another origin, and what it differs from the one of
 * date by is the whole precession: 4.19 degrees over three hundred years.
 *
 * In J2000 there is no nutation, the same as in Swiss, and it is measured: `SEFLG_J2000` and
 * `SEFLG_J2000 | SEFLG_NONUT` give the same thing down to the last decimal. Nutation is a wobble
 * of the axis at the date, and J2000 is a fixed snapshot of another day.
 *
 * It is deliberately not called `Equinox`: this project has an equinoxes-and-solstices tool, and
 * those are instants and not frames, and two things called the same without having anything to do
 * with each other is the confusion already noted with `Vulcano` and `Vulkanus`.
 */
enum ReferenceEcliptic implements Translatable
{
    /** The one of date with nutation: the chart's. */
    case TrueOfDate;

    /** The one of date without nutation: `SEFLG_NONUT`. */
    case MeanOfDate;

    /** The one of 1 January 2000 at noon: `SEFLG_J2000`. */
    case J2000;

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
            self::TrueOfDate => 'true-of-date',
            self::MeanOfDate => 'mean-of-date',
            self::J2000 => 'j2000',
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
            self::TrueOfDate => 'True ecliptic of date',
            self::MeanOfDate => 'Mean ecliptic of date',
            self::J2000 => 'Ecliptic of J2000',
        };
    }
}
