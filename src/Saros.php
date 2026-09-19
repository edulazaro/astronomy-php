<?php

namespace Astronomy;

/**
 * The saros series number of an eclipse.
 *
 * A saros is 223 lunations, about 6,585.32 days, after which the Sun-Moon-Earth geometry
 * repeats almost exactly and a similar eclipse happens again. Eclipses are thus spread out
 * into numbered series, and that number is what NASA prints next to every eclipse in its
 * canon.
 *
 * This is NOT geometry that can be derived: it is a numbering CONVENTION with a chosen
 * origin**, van den Bergh's (1955), who gave number 1 to a series that was running during
 * the second millennium BC by extrapolating von Oppolzer's canon. It does not come from
 * looking at where the Moon is. The published convention has to be reproduced and checked
 * against the catalogue, and that is why this class does not have a single number set by
 * eye: the rule is NASA's and the anchors are measured against their tables.
 *
 * The rule is in NASA's own catalogue (Espenak, "Periodicity of Solar Eclipses", the table
 * that translates intervals between eclipses into series jumps): one lunation further
 * on, the series goes up by 38, counting modulo 223. That single constant reproduces the
 * eight rows of that table, and that is what proves it is the right one and not a number
 * tuned to make a handful of cases fit:
 *
 * 38 ·   5 ≡ -33 (short semester)   38 · 223 ≡  0 (saros)
 * 38 ·   6 ≡   5 (semester)         38 · 235 ≡ 10 (metonic cycle)
 * 38 · 135 ≡   1 (tritos)           38 · 358 ≡  1 (inex)
 * 38 · 669 ≡  0 (exeligmos)
 *
 * Solar and lunar eclipses are numbered separately, so each family carries its own anchor.
 * Both are the same lunation, number 37, and they are exactly 12 series apart.
 *
 * **The modulo representative goes in [0, 222] and NOT in [1, 223], and that difference is
 * one eclipse in every two hundred. The popularised rule says "if it goes past 223,
 * subtract 223", that is, it numbers from 1 to 223. It is wrong in one place: series 0
 * exists** in NASA's canon and with that window it comes out as 223. Measured over the
 * 3,633 eclipses of the fourteen centuries downloaded from the canon, the [1, 223] window
 * fails 16 times and all 16 are that case; the [0, 222] window never fails. The numbers
 * published across the five millennia run from 0 to 190, so 223 never appears and 0 does.
 *
 * The member within the series is always returned null, and that is deliberate. A
 * series does not begin where the arithmetic would say: it lasts between 69 and 87
 * eclipses depending on the case, and where each one starts depends on the geometry and on
 * the criterion by which NASA decides that a grazing already counts as an eclipse of the
 * series. Having tried the obvious linear model (deriving the member from the lunation and
 * the series), the eclipse of 8 April 2024 comes out as member 32 and NASA publishes 30.
 * The only way to get it right would be to hand-copy the starting lunation of the 181
 * series, that is, 181 typed numbers that nobody can review, and an invented member reads
 * exactly as well as the good one. Better null than plausible.
 *
 * @see https://eclipse.gsfc.nasa.gov/SEsaros/SEperiodicity.html
 * @see https://eclipse.gsfc.nasa.gov/SEcat5/SEcatalog.html
 */
final class Saros
{
    /**
     * Meeus's new moon number zero, 6 January 2000, and the mean synodic month.
     *
     * The MEAN lunation is enough because the lunation number only has to be rounded: the
     * true syzygy departs from the mean by 0.0274 lunations at most, measured over the
     * 3,633 eclipses of the canon (0.0199 within 1600 to 2400). Up to the ambiguity, which
     * sits at half a lunation, there is a factor of 18.
     */
    private const ZERO_LUNATION = 2451550.09766;

    private const SYNODIC_MONTH = 29.530588853;

    /** How much the series goes up per lunation, and how many series there are before it repeats. */
    private const PER_LUNATION = 38;

    private const SERIES = 223;

    /**
     * The anchor: lunation 37, the first new moon and the first full moon of 2003.
     *
     * It comes from the Astronomy Answers write-up ("Eclipses and the Saros", aa.quae.nl),
     * which gives the new moon of 3 January 2003 as series 180 and the full moon of 17
     * January as series 192. Neither of the two is an eclipse, and that does not matter:
     * the numbering runs through every lunation and only some of them bring an eclipse.
     * Both verified against NASA's canon, which is what turns them into data.
     */
    private const LUNATION_ANCHOR = 37;

    private const SOLAR_ANCHOR = 180;

    private const LUNAR_ANCHOR = 192;

    /**
     * How far it answers, in lunation number: the five-millennium canon.
     *
     * It runs from -1999 to 3000, which is what NASA publishes and what it has been
     * possible to check. Outside that the arithmetic still works out, but it stops being
     * verified: what breaks first is not the formula, which is exact, but which modulo
     * representative applies, because the active series drift with the epoch and end up
     * falling outside the window. There it returns null instead of a good-looking number.
     */
    private const MIN_LUNATION = -49461;

    private const MAX_LUNATION = 12380;

    /**
     * How far from syzygy is accepted before calling the instant invalid.
     *
     * Three and a half times the worst departure measured in the canon. It does not detect
     * eclipses, that is `Eclipses`' job: it only rejects being handed an instant that is
     * nowhere near a new moon or a full moon, where the lunation number would no longer
     * mean anything.
     */
    private const SYZYGY_TOLERANCE = 0.10;

    /**
     * The series of a solar eclipse, given the instant of its maximum.
     *
     * @return array{serie: int, miembro: int|null}|null
     */
    public static function forSolar(float $jdTT): ?array
    {
        return self::series($jdTT, false, self::SOLAR_ANCHOR);
    }

    /**
     * The series of a lunar eclipse, given the instant of its maximum.
     *
     * @return array{serie: int, miembro: int|null}|null
     */
    public static function forLunar(float $jdTT): ?array
    {
        return self::series($jdTT, true, self::LUNAR_ANCHOR);
    }

    /**
     * Meeus's lunation number, which is the one NASA prints as "Luna Num".
     *
     * It is the NEW moon's in both cases: a lunar eclipse is numbered with the new moon
     * that precedes it, not with the full moon it happens at. Checked against the 3,633
     * rows of the downloaded canon, without a single difference.
     *
     * The instant may be in TT or in UT and the result is the same. Delta T is minutes and
     * here it is rounded to the nearest lunation, with almost fifteen days of margin on
     * each side, so the time scale does not even come close to touching the result. It is
     * asked for in TT only because that is what the engine handles internally.
     */
    public static function lunation(float $jd, bool $fullMoon = false): ?int
    {
        $exact = ($jd - self::ZERO_LUNATION) / self::SYNODIC_MONTH - ($fullMoon ? 0.5 : 0.0);
        $k = round($exact);

        if (abs($exact - $k) > self::SYZYGY_TOLERANCE) {
            return null;
        }

        return (int) $k;
    }

    /**
     * @return array{serie: int, miembro: int|null}|null
     */
    private static function series(float $jdTT, bool $fullMoon, int $anchor): ?array
    {
        $k = self::lunation($jdTT, $fullMoon);

        if ($k === null || $k < self::MIN_LUNATION || $k > self::MAX_LUNATION) {
            return null;
        }

        // PHP's remainder carries the sign of the dividend, so it has to be straightened
        // out: without this, any eclipse before 2003 would come out with a negative series.
        $series = ($anchor + self::PER_LUNATION * ($k - self::LUNATION_ANCHOR)) % self::SERIES;

        if ($series < 0) {
            $series += self::SERIES;
        }

        return ['series' => $series, 'member' => null];
    }
}
