<?php

namespace Astronomy;

/**
 * How much darkness it takes to see a body again: the arcus visionis.
 *
 * It is the number that decides a heliacal phenomenon, and it does not come out of geometry.
 * Geometry says where the planet is and at what time it rises; what you have to know on top
 * of that is **whether an eye can tell it apart with the sky still bright**, and that is a
 * visibility criterion. There are several competing ones and they do not give the same date,
 * so one has to be chosen, cited, and declared as chosen.
 *
 * **The classical one is used here: the arcus visionis, with Schoch's table (1924).** It is
 * defined like this, and the definition is literal from the paper (MNRAS 84, 731):
 *
 * > «The *arcus visionis* (in this paper called γ) of a planet or of Sirius is the
 * > depression of the Sun below the horizon, measured in the vertical circle for the
 * > moment when the star sets on the last evening when it is visible or rises on the first
 * > morning when it is visible, **refraction being disregarded in the case of both
 * > bodies**.»
 *
 * That is: a single angle per object and per event, measured at the instant when the body
 * crosses the GEOMETRIC horizon. That closing clause is not a drafting detail: it says that
 * neither the body nor the Sun carries refraction, and refraction at the horizon is 34
 * arcminutes, which on the date of the event show up as half a day. See `HeliacalPhenomena`,
 * which is what measures the arc.
 *
 * **The numbers come from observations, not from a model.** Schoch computed γ for some
 * seventy Babylonian observations recorded on tablets (30 of Mercury, 15 of Venus, 12 of
 * Jupiter, 9 of Saturn and only 4 of Mars, which is why he himself gives those as uncertain),
 * and he took Sirius's from the Babylonian tables. It is the table on page 734.
 *
 * ### The other criterion, and why it is not here
 *
 * The modern one is **Schaefer's (1993, 2000)**, which does not ask for an angle but models
 * contrast instead: atmospheric extinction, background sky brightness, the age and acuity of
 * the observer, and even whether they are looking through binoculars. It is the one Swiss
 * Ephemeris carries by default in `swe_heliacal_ut`, implemented by Reijs and Koch.
 *
 * It is not here for two reasons and neither one is that it is worse. The first is that **it
 * asks for parameters nobody is going to enter**: that day's atmospheric extinction
 * coefficient, the humidity, the age of whoever is looking. Left at their defaults, the result
 * is that of an invented observer, and yet it looks measured. The second is that **the
 * arcus visionis can be written down and checked**: it is a published number, the computation
 * that uses it fits on one page, and a test can verify that the day of the event meets it and
 * the day before does not. A contrast model is not verified against its own definition: it is
 * verified against another program that implements it the same way.
 *
 * How far apart they are, measured: with Swiss's default atmosphere in Madrid, its threshold
 * for Sirius comes out at 11.3 degrees and Schoch's is 7.8; for Aldebaran, 17.6 against 11.4.
 * That is two to six degrees, so three to seven days. **Most of that is not disagreement: it
 * is that Schoch measured in Babylon and in Athens**, and he says so himself at the head of
 * the star table: «for places with a very clear sky (such as Babylon and Athens)». Setting
 * Swiss to a clear sky (extinction coefficient 0.12), its thresholds for the APPEARANCES come
 * together with Schoch's: Sirius 7.95 against 7.80, Venus 6.09 against 5.70, Mercury 13.46
 * against 13.20, Jupiter 9.02 against 9.30.
 *
 * **And there is where the interesting thing shows up, which is the only one that does not
 * come together: the disappearances.** Schoch asks a heliacal setting for one to three degrees
 * LESS than a heliacal rising (6.5 against 7.8 in Sirius, 10.5 against 13.0 in Saturn), and
 * Schaefer's come out almost equal (7.86 against 7.95 in Sirius, 12.59 against 12.59 in
 * Saturn). It is not that one of the two is wrong: it is that **that asymmetry is not physical
 * and a contrast model cannot produce it**. Schoch says it on page 733: «The Babylonians were
 * persistent observers, and it was comparatively easy for them to find the place of a star,
 * known by the observation of the previous day (the case of the "lasts"). For a re-appearance
 * (the case of the "firsts") the place of a star was unknown». So it is knowing where to look,
 * not how much light there is. Measured over 475 cases with a clear sky: the appearances fall
 * within a day of Swiss 48 % of the time, with a median of minus one day, and the
 * disappearances come out with a median of THREE days later, which is exactly what those
 * missing degrees are worth.
 *
 * ### Traps of the table
 *
 * - **The table was copied from the original paper and not from a summary, and just as well.**
 *   The compilation going around the web (Victor Reijs's, at archaeocosmology.org, who is also
 *   the one who implemented this in Swiss) gives **9.3 for Sirius's heliacal rising**, which is
 *   Jupiter's value repeated one row further down; the paper says **7.8**. And in his star
 *   table the magnitude of the first row is shifted, so a magnitude -2 comes out with three
 *   values and there is no -3 at all. A degree and a half of error in Sirius is two days in the
 *   date of the heliacal rising of the star that dated the Egyptian year.
 * - **Sirius is not taken from the star table**, even though it has a magnitude. Schoch gives
 *   it its own entry (6.5 and 7.8) and explains why on page 732: γ also depends on the azimuth
 *   difference between the Sun and the body, and «A is always small for the planets, but in
 *   the case of Sirius it is very great, 51°. So γ for Sirius must be smaller than for a
 *   planet with the same value of m». The general table holds for stars near the ecliptic, «A
 *   not greater than 25°», and Sirius is at forty degrees of ecliptic latitude, which is where
 *   that azimuth comes from. Putting it into the general table would give almost a degree too
 *   much, and a degree is more than a day.
 * - **The «lasts» ask for less darkness than the «firsts»**, and it is not a whim of the
 *   numbers: it is that the observer who sees a body off knows where to look because they saw
 *   it yesterday, and the one who waits for its return does not. Schoch says it on page 733 and
 *   it holds in all sixteen entries.
 * - **Mercury and Venus have two values per side**, one for the inferior conjunction and
 *   another for the superior one, because their magnitude is not the same in the two: Mercury
 *   goes from -0.5 at the superior one to +1.2 at the inferior one.
 * - **Mars's is the weak one, and he says so himself.** The others come from between nine and
 *   thirty Babylonian observations; Mars's, from four, «which are quite insufficient» (page
 *   733). It is also the one that departs the most from Swiss with a clear sky, a degree and a
 *   half in the heliacal rising. It is left as it is because inventing another one for it would
 *   be worse than a published number with its warning.
 * - **Outside the table null is returned and nothing is estimated.** Uranus, Neptune, the
 *   asteroids and the catalogue's clusters (which have no V magnitude) have no arc here.
 *   An invented number for them would give a date with the same look as the good ones.
 * - **Planets are NOT looked up by magnitude.** It is the question that asks itself, because
 *   the engine does not compute planet magnitudes and does not have them. They are not needed:
 *   the classical table goes PER PLANET, precisely because whoever wrote it had no photometry.
 *   With the stars it is the other way round, and there magnitude is in the catalogue.
 */
final class ArcusVisionis
{
    /** Where the numbers come from, so it can be written wherever it is needed. */
    public const SOURCE = 'Schoch, C. (1924), «The "Arcus Visionis" of the planets in the Babylonian observations», MNRAS 84, 731-734';

    /**
     * The table of planets and of Sirius from page 734, in degrees.
     *
     * Each entry is [event => γ]. The outer planets only carry the two events they have.
     */
    private const TABLE = [
        // Venus: -3.2 at the inferior conjunction (elast and mfirst), -3.3 at the superior one.
        'venus' => ['elast' => 5.2, 'mfirst' => 5.7, 'mlast' => 5.8, 'efirst' => 5.8],
        // Mercury: +1.2 at the inferior one (elast and mfirst), -0.5 at the superior one.
        'mercury' => ['elast' => 11.1, 'mfirst' => 13.2, 'mlast' => 9.5, 'efirst' => 10.5],
        'jupiter' => ['elast' => 7.4, 'mfirst' => 9.3],
        'saturn' => ['elast' => 10.5, 'mfirst' => 13.0],
        'mars' => ['elast' => 14.2, 'mfirst' => 15.5],
    ];

    /**
     * Sirius, which goes apart from the star table because of its azimuth. See the docblock.
     */
    private const SIRIUS = ['elast' => 6.5, 'mfirst' => 7.8];

    /**
     * Stars near the ecliptic, in degrees: magnitude => [lasts, firsts].
     *
     * It is the second table on page 734, the one Schoch recommends «for places with a
     * very clear sky (such as Babylon and Athens)» and «for stars near the ecliptic (A not
     * greater than 25°)».
     */
    private const STARS = [
        [-3.0, 5.8, 6.5],
        [-2.0, 7.0, 8.0],
        [-1.0, 8.5, 9.5],
        [0.0, 9.5, 10.5],
        [1.0, 10.5, 11.5],
        [2.0, 12.5, 13.5],
        [3.0, 15.0, 16.0],
    ];

    /**
     * The arc to require of an object for an event, in degrees, or null if it is not in the
     * table.
     *
     * @param Body|Star $object
     * @param HeliacalEvent $event
     * @return float|null
     */
    public static function of(Body|Star $object, HeliacalEvent $event): ?float
    {
        if ($object instanceof Star) {
            if ($object->key === 'sirius') {
                return self::SIRIUS[$event->isFirst() ? 'mfirst' : 'elast'];
            }

            return $object->magnitude === null ? null : self::forMagnitude($object->magnitude, $event);
        }

        return self::TABLE[$object->value][$event->abbreviation()] ?? null;
    }

    /**
     * The reverse of `forMagnitude`: with the Sun at this depression, down to what magnitude
     * can be seen.
     *
     * It is the same table read from the other side, and that is why it can be written with
     * the same honesty: Schoch measured that a star of magnitude 2 needs 13.5 degrees of
     * depression of the Sun to reappear, so with 13.5 degrees of depression the faintest one
     * that reappears is the one of magnitude 2. There is no new model and no new parameter.
     *
     * **This is NOT Swiss Ephemeris's `swe_vis_limit_mag`, and the difference matters.** That
     * one answers a much wider question (what magnitude can be seen at any point of the sky,
     * at any altitude, with the Moon wherever it is) and it answers it with Schaefer's contrast
     * model, which asks for the atmospheric extinction coefficient, the humidity and the age of
     * the observer. Here it has been chosen not to have that, and the reason is written above:
     * left at their defaults, those parameters describe an invented observer and the result
     * looks measured. What can be given is this, which is what the table says:
     *
     * - it holds **at the horizon and at the moment of the pass**, which is where Schoch
     *   defined γ, not for a high star nor at midnight;
     * - it holds for **stars near the ecliptic** («A not greater than 25°»), which is the
     *   table's fine print, so it does not hold for Sirius nor for Vega;
     * - it holds for a **very clear sky**, that of Babylon and Athens, which is where it was
     *   measured;
     * - and **only between magnitude -3 and +3**, which is as far as it reaches. Outside, null
     *   is returned and the straight line is not stretched: prolonged it would give magnitude 4
     *   at 18.5 degrees and nobody has measured that. A null here is «the table does not reach»,
     *   not «nothing can be seen»: below the first arc not even the brightest one Schoch
     *   measured can be seen, and above the last one they can all be seen and some more.
     *
     * @param float $arc Depression of the Sun below the horizon, in degrees.
     * @param HeliacalEvent $event Of it only whether it is a first or a last is looked at: one
     *        to three degrees less are asked of a farewell, because whoever sees a body off
     *        knows where to look. See the class docblock.
     * @return float|null Visual magnitude of the faintest one that can be seen, or null outside the table.
     */
    public static function limitMagnitude(float $arc, HeliacalEvent $event): ?float
    {
        $column = $event->isFirst() ? 2 : 1;
        $table = self::STARS;
        $last = count($table) - 1;

        if ($arc < $table[0][$column] || $arc > $table[$last][$column]) {
            return null;
        }

        for ($i = 1; $i <= $last; $i++) {
            if ($arc > $table[$i][$column]) {
                continue;
            }

            [$m0, $a0] = [$table[$i - 1][0], $table[$i - 1][$column]];
            [$m1, $a1] = [$table[$i][0], $table[$i][$column]];

            return $m0 + ($m1 - $m0) * ($arc - $a0) / ($a1 - $a0);
        }

        return (float) $table[$last][0];
    }

    /**
     * How far the star table reaches, in degrees of arc, for that kind of event.
     *
     * It exists so that whoever gets a null from `limitMagnitude` can say which side it fell
     * outside on instead of having to guess it or copy the numbers.
     *
     * @param HeliacalEvent $event
     * @return array{0: float, 1: float} [minimum arc, maximum arc]
     */
    public static function range(HeliacalEvent $event): array
    {
        $column = $event->isFirst() ? 2 : 1;
        $table = self::STARS;

        return [$table[0][$column], $table[count($table) - 1][$column]];
    }

    /**
     * The arc of a star from its magnitude, interpolating in the table.
     *
     * Outside the ends the end itself is returned and nothing is extrapolated: the table
     * reaches magnitude 3 and from there on Schoch says nothing. A prolonged straight line
     * would give 18.5 degrees for one of magnitude 4, and nobody has measured that.
     *
     * @param float $magnitude
     * @param HeliacalEvent $event
     * @return float
     */
    public static function forMagnitude(float $magnitude, HeliacalEvent $event): float
    {
        $column = $event->isFirst() ? 2 : 1;
        $table = self::STARS;

        if ($magnitude <= $table[0][0]) {
            return $table[0][$column];
        }

        $last = count($table) - 1;

        if ($magnitude >= $table[$last][0]) {
            return $table[$last][$column];
        }

        for ($i = 1; $i <= $last; $i++) {
            if ($magnitude > $table[$i][0]) {
                continue;
            }

            [$m0, $a0] = [$table[$i - 1][0], $table[$i - 1][$column]];
            [$m1, $a1] = [$table[$i][0], $table[$i][$column]];

            return $a0 + ($a1 - $a0) * ($magnitude - $m0) / ($m1 - $m0);
        }

        return $table[$last][$column];
    }
}
