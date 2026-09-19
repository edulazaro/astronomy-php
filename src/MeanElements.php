<?php

namespace Astronomy;

/**
 * The MEAN elements of the eight planets: the averaged table the mean node and the mean perihelion
 * come out of.
 *
 * For a long time this was not here, and the reason written down was a good one: this repository has
 * VSOP87 (which publishes positions, not elements), ELP and JPL tables, and none of them carries
 * mean planetary elements; copying them from the Swiss source is ruled out by licence, and writing
 * them from memory is what this engine does not do. What changed is that the source turned up,
 * which was the condition that was missing: Simon, Bretagnon, Chapront, Chapront-Touzé, Francou and
 * Laskar (1994), *Astronomy and Astrophysics* 282, 663-683.
 *
 * And it does not have to be typed in, which is what makes it acceptable here: ERFA publishes
 * it. `plan94.c` is the reference reimplementation of that same paper, BSD licensed, and it is the
 * same place the IAU 2000B nutation series is already downloaded from. So the table is written by
 * `astronomy mean-elements`, parsing the C source and checking the count, just like
 * `astronomy nutation`, and there is not a single hand-written coefficient here.
 *
 * What it brings and what it does not
 *
 * Six polynomials per planet, capped at the t² term the way ERFA caps them: semi-major axis, mean
 * longitude, eccentricity, longitude of perihelion, inclination and longitude of the node. **The
 * trigonometric terms of `plan94.c` are NOT here, and that is on purpose**: they are the periodic
 * corrections that function adds to the semi-major axis and to the mean longitude in order to
 * compute a POSITION, that is, exactly the perturbations a mean element exists in order not to have.
 * That they are superfluous is measured and not assumed: without them the mean node and the mean
 * perihelion agree with Swiss's (see below); with them they would stop being mean.
 *
 * Time goes in Julian MILLENNIA from J2000, not in centuries, which is the unit of the paper and
 * the one ERFA uses. It is the only place in the engine where that happens, and confusing it with
 * `Time::centuries` gives a factor-of-ten error that in the year 2000 is exactly zero and in 1700 is
 * three thousand years of motion: the class of failure that passes any test done at J2000. That is
 * why the conversion lives here and is not done by the caller.
 *
 * And the angles are referred to the ecliptic and equinox of J2000, which is fixed. It shows in
 * the Earth's row alone: its inclination is exactly zero at J2000 and grows 470 arcseconds per
 * millennium, something that could not happen if the reference plane were the ecliptic of date,
 * because the ecliptic IS the Earth's orbit. Taking them to the ecliptic of date is
 * `NodesAndApsides`'s job, which rotates the vectors and intersects again, not the angles.
 *
 * Against Swiss
 *
 * The seven planets with a node (the Earth has none, see `NodesAndApsides`) in 1700, 2000 and 2300,
 * comparing the mean node and the mean perihelion already rotated to the ecliptic of date: node
 * 0.98 arcseconds worst case, latitude of the perihelion 0.09 and perihelion 12.45. The first
 * three are noise; the last one has an owner and it is worth saying which.
 *
 * That 12.45 is Neptune's and it is a CONSTANT, not a drift: it is 12.45 in 1700, 12.30 in 2000
 * and 11.84 in 2300, so it does not grow with time and therefore it is not ERFA's truncation at t²,
 * which at J2000 would be zero. It is that Swiss's table and Simon's do not carry the same constant
 * term for Neptune. The same happens, and also flat, to the perihelion distance: Neptune is off by
 * 0.0141 astronomical units, Uranus 0.0016, Saturn 0.0004 and Jupiter 0.000016, always the same ones
 * at the three epochs. The four inner ones agree to better than one arcsecond and better than 5e-7
 * astronomical units.
 *
 * Here the published source is chosen over resembling Swiss more closely, which is the same rule by
 * which the 1976 precession model was chosen: where every number comes from can be cited.
 *
 * @see resources/astro/mean-elements.php
 */
final class MeanElements
{
    /** Days in a Julian millennium, which is the time unit of the paper and ERFA's. */
    private const MILLENNIUM = 365250.0;

    /** @var array<string, mixed>|null */
    private static ?array $table = null;

    /**
     * Whether this body has published mean elements.
     *
     * It is the eight planets and nobody else. Not Pluto (which in 1994 was still one, and even so
     * Simon does not include it), nor the asteroids, nor the fictitious bodies, nor the Moon, whose
     * mean elements do exist and are where they have to be, in `LunarPoints`, because ELP publishes
     * them. Swiss does the same: asking it for Pluto's mean elements returns the osculating ones
     * without warning, which is measured and is exactly what is not going to be done here.
     *
     * @param Body $body
     * @return bool
     */
    public static function has(Body $body): bool
    {
        return isset(self::table()['bodies'][$body->value]);
    }

    /**
     * The six mean elements of a body at an instant, or null if it has none.
     *
     * Angles in degrees, semi-major axis in astronomical units, referred to the ecliptic and equinox
     * of J2000. `pi` is ϖ, the longitude of perihelion, which is the broken angle of the textbooks
     * and not the ecliptic longitude of the point; `l` is the mean longitude.
     *
     * @param Body $body
     * @param float $jdTT Julian day in Terrestrial Time.
     * @return array{a: float, e: float, i: float, om: float, pi: float, l: float, n: float}|null
     */
    public static function of(Body $body, float $jdTT): ?array
    {
        $row = self::table()['bodies'][$body->value] ?? null;

        if ($row === null) {
            return null;
        }

        $t = ($jdTT - Time::J2000) / self::MILLENNIUM;

        /* The angles come as whole degrees plus arcseconds, which is how the paper writes them and
           how ERFA stores them: `3600·c0 + (c1 + c2·t)·t` arcseconds. The semi-major axis and the
           eccentricity are ordinary polynomials. Mixing them up gives a semi-major axis of 1,393,554
           AU, so that one does show; the angle treated as an ordinary polynomial lands on degree 77
           instead of 77.4, and that one does not. */
        $angle = fn (array $c): float => (3600.0 * $c[0] + ($c[1] + $c[2] * $t) * $t) / 3600.0;
        $polynomial = fn (array $c): float => $c[0] + ($c[1] + $c[2] * $t) * $t;

        /* The mean motion comes from DIFFERENTIATING the mean longitude, not from Kepler's third law
           on the semi-major axis. It is the linear term of the table taken from arcseconds per
           millennium to degrees per day, plus what the quadratic one contributes at this instant.
           That way the mean motion and the mean longitude really are the same series, which is what
           makes the mean anomaly close; taking it from Kepler would be two different computations
           that look alike. */
        $meanMotion = ($row['l'][1] + 2.0 * $row['l'][2] * $t) / 3600.0 / self::MILLENNIUM;

        return [
            'a' => $polynomial($row['a']),
            'e' => $polynomial($row['e']),
            'i' => $angle($row['i']),
            'om' => $angle($row['om']),
            'pi' => $angle($row['pi']),
            'l' => $angle($row['l']),
            'n' => $meanMotion,
        ];
    }

    /**
     * Where they come from, to write it next to a number.
     *
     * @return string
     */
    public static function source(): string
    {
        return self::table()['source'] ?? 'no mean-elements table';
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(): array
    {
        if (self::$table === null) {
            $file = DataFolder::path('mean-elements.php');

            /* If the file is not there, there are no mean elements and `has()` says no: the engine
               goes on casting charts and giving osculating orbits exactly as before. It is the same
               rule as the correction towards the JPL. */
            self::$table = is_file($file) ? require $file : ['bodies' => []];
        }

        return self::$table;
    }
}
