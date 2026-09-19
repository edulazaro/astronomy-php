<?php

namespace Astronomy;

use DateTimeInterface;

/**
 * The fixed stars: where they fall on a date and what they touch in a chart.
 *
 * A «fixed» star is not fixed: what moves it is above all the reference frame. The Aries point
 * goes back fifty arcseconds a year, so Regulus, which was at 29° of Leo in 2000, has been
 * in Virgo since the end of 2011. On top of that goes the proper motion of the star, which
 * in Arcturus or Sirius is a couple of arcseconds a year and in almost all the rest is
 * nothing. And on top of that the same as the planets: annual aberration, which is up to
 * twenty arcseconds, and nutation, which is seventeen.
 *
 * The path, from the catalogue entry to the chart:
 *
 * 1. **Proper motion** from J2000 to the date, in right ascension and declination.
 * 2. **From ICRS equatorial to J2000 ecliptic**, rotating by the obliquity of J2000.
 * 3. **Precession** to the ecliptic and the equinox of the date, with `Precession::toDate`,
 *    the same rotation that is applied to the Moon and to Pluto. Two precession models in
 *    the same engine would leave a systematic difference between the star and the planet it
 *    touches.
 * 4. **Annual aberration**, which the stars get too: the light is tilted because the Earth
 *    is moving, and it makes no difference where it comes from.
 * 5. **Nutation** in longitude, like everything else.
 *
 * What is NOT applied, and why:
 *
 * - **Annual parallax.** The nearest star in the catalogue is Toliman (α Centauri), with
 *   0.75 arcseconds; Sirius has 0.38 and the rest less than 0.15. It is smaller than the
 *   engine's own error against the JPL, and only in two stars.
 * - **Radial velocity.** It changes the proper motion over time because the star is coming
 *   closer or moving away. In Sirius, which is the worst case, it is two hundredths of an
 *   arcsecond per century. Both are kept in the catalogue in case they are ever needed.
 * - **The FK5 correction** that `Ephemeris` applies to the planets. That one corrects the
 *   frame proper to VSOP87, and a star from SIMBAD is not in that frame: it is in ICRS. The
 *   difference between ICRS and the mean equator of J2000 is two hundredths of an
 *   arcsecond, which is not corrected either.
 * - **Gravitational deflection** by the Sun: thousandths of an arcsecond except within a degree
 *   of the Sun.
 *
 * With all that, the position agrees with Swiss Ephemeris to better than two arcseconds
 * between 1900 and 2100, and what is left is the engine's own (nutation truncated to four
 * terms and precession in longitude without the quadratic term), not the stars'.
 */
class Stars
{
    /**
     * Mean obliquity of the ecliptic at J2000.0, in arcseconds.
     *
     * It is the IAU 2006 constant (Capitaine, Wallace and Chapront, 2003, «Expressions for
     * IAU 2000 precession quantities», A&A 412, 567), and it is the one that separates the
     * ICRS equator from the reference ecliptic that VSOP87 and ELP work in. It does not come
     * from `Time` because `meanObliquity(0)` is Laskar's series evaluated at zero, which
     * gives 84381.448: that is the six hundredths of a second separating the 1976 value from
     * the 2006 one, and the one wanted here is the one that matches ICRS.
     */
    private const OBLIQUITY_J2000 = 84381.406;

    /** Orb for the conjunction by longitude, in degrees. One is what almost everybody uses. */
    public const ORB = 1.0;

    /**
     * Orb for the declination parallel, in degrees.
     *
     * Narrower than the longitude one because declination is more crowded: almost the whole
     * zodiac falls between +24 and -24 degrees, so any planet always has some star within a
     * degree of its declination. With half a degree the parallel says something again.
     */
    public const PARALLEL_ORB = 0.5;

    /** @var array<string, Star>|null */
    private static ?array $catalogue = null;

    /** @var array<string, Star>|null Names and designations that are not keys, for `find`. */
    private static ?array $byName = null;

    /**
     * The whole catalogue, by key.
     *
     * @return array<string, Star>
     */
    public static function catalog(): array
    {
        if (self::$catalogue === null) {
            self::$catalogue = [];

            foreach (require DataFolder::path('stars.php') as $key => $data) {
                self::$catalogue[$key] = Star::fromArray($key, $data);
            }
        }

        return self::$catalogue;
    }

    /**
     * A star by its key, its name, its designation or any of its other names, without looking at
     * case or accents.
     *
     * **A name is not a key and that is the whole reason this is more than one lookup.** Ten
     * names of the catalogue belong to two stars each: β Cap and β¹ Cap are both Dabih, π³ and
     * π⁴ Ori are both Tabit. Those key by their designation, so `find('Dabih')` would find
     * nothing if this only looked at keys, and `Ayanamsa::TrueRevati` asks for Revati by name.
     *
     * And the catalogue calls a star whatever Swiss's list calls it, which is not always what
     * anyone writes: M 44 is «Praesepe Cluster» there and alpha Centauri is «Rigil Kentaurus»,
     * so Praesepe and Toliman are aliases and not names. They are looked up too.
     *
     * A shared name resolves to **the brighter of the two**, which is what anyone writing it
     * means and is measured rather than chosen. A star with no published magnitude never wins a
     * name it shares: unknown is not bright.
     *
     * @param string $name
     * @return Star|null
     */
    public static function find(string $name): ?Star
    {
        $key = self::asKey($name);
        $catalog = self::catalog();

        if (isset($catalog[$key])) {
            return $catalog[$key];
        }

        if (self::$byName === null) {
            self::$byName = [];

            foreach ($catalog as $star) {
                /* **The written designation is not indexed, and that one is a trap.** A key
                   drops anything that is not a letter or a digit, so «α Leo» reduces to `leo`
                   and «δ And» to `and`: indexing those, `find('leo')` answered Regulus with
                   complete self-assurance and the 594 stars that have no name but their
                   designation all piled onto the key of their constellation. They are found by
                   their key, which is Swiss's own designation (`alLeo`, `deAnd`) and survives
                   that. So what goes in here is what a person writes: the name and the aliases,
                   and the name only where it IS a name and not the designation again. */
                $written = [...$star->aliases];

                if ($star->name !== $star->designation) {
                    $written[] = $star->name;
                }

                foreach ($written as $one) {
                    $one = self::asKey($one);

                    if ($one === '') {
                        continue;
                    }

                    $standing = self::$byName[$one] ?? null;

                    if ($standing === null || ($star->magnitude ?? INF) < ($standing->magnitude ?? INF)) {
                        self::$byName[$one] = $star;
                    }
                }
            }
        }

        return self::$byName[$key] ?? null;
    }

    /**
     * Where the star is seen at an instant.
     *
     * @param Star $star
     * @param float $jdTT Julian day in Terrestrial Time.
     * @param bool $apparent With aberration and nutation (what is seen). False gives the mean
     *                       position: proper motion and precession only. That is the one that
     *                       works for dating an ingress, because the apparent one is not
     *                       monotonic in time: aberration comes and goes twenty arcseconds
     *                       over the year, more than precession advances, so a star crosses
     *                       the same degree three times within a few months.
     * @return StarPosition
     */
    public static function position(Star $star, float $jdTT, bool $apparent = true): StarPosition
    {
        $t = Time::centuries($jdTT);

        $vector = Precession::toDate(self::eclipticJ2000($star, $jdTT), $t);

        if ($apparent) {
            $vector = Ephemeris::unitAberration($vector, $jdTT);
        }

        $longitude = rad2deg(atan2($vector[1], $vector[0]));
        $latitude = rad2deg(atan2($vector[2], hypot($vector[0], $vector[1])));

        if ($apparent) {
            $longitude += rad2deg(Time::nutation($t)[0]);
        }

        $longitude = self::normalize($longitude);

        // Back to equatorial, now of the date, for the parallel. With the true obliquity if
        // the position is apparent, because nutation in obliquity moves the equator even
        // though it does not move the ecliptic.
        $obliquity = $apparent ? Time::trueObliquity($t) : Time::meanObliquity($t);
        $lambda = deg2rad($longitude);
        $beta = deg2rad($latitude);

        $ascension = rad2deg(atan2(
            sin($lambda) * cos($obliquity) - tan($beta) * sin($obliquity),
            cos($lambda)
        ));

        return new StarPosition(
            star: $star,
            longitude: $longitude,
            latitude: $latitude,
            rightAscension: self::normalize($ascension),
            declination: Declinations::of($longitude, $latitude, $obliquity),
            apparent: $apparent,
        );
    }

    /**
     * Rising, setting and meridian passages of a star on a day and at a place.
     *
     * It is `RiseSet::ofTheDay` with the star and with no disc: for a point the limb means
     * nothing and only refraction counts, which can be turned off. Circumpolar or invisible
     * from there, rising and setting to null and the culminations always.
     *
     * **Parans do not go through here.** A paran is a star and a planet on an angle at the
     * same time (the star rising when the planet culminates, for example), and comparing the
     * `RiseSet` of each star with that of each planet would be one sweep of the day per star:
     * seconds. A paran finder does the same calculation in closed form, with `Limb::Center` and
     * without refraction so that it is geometry and not atmosphere, and its own pass finder
     * gives the same as this for a star in microseconds. This stays for whoever wants the
     * passage with refraction, which is what an almanac publishes.
     *
     * @param Star $star
     * @param Place $place
     * @param DateTimeInterface $day Only the date counts, in the time zone of the place.
     * @param bool $refraction
     * @param float $heightMetres
     * @return RiseSet
     */
    public static function passes(Star $star, Place $place, DateTimeInterface $day, bool $refraction = true, float $heightMetres = 0.0): RiseSet
    {
        return RiseSet::ofTheDay($star, $place, $day, Limb::Center, $refraction, $heightMetres);
    }

    /**
     * The occultations of a star by the Moon between two instants, in order. Empty straight
     * away if the star is more than seven degrees from the ecliptic, which is where the Moon
     * does not reach.
     *
     * @param Star $star
     * @param float $jdUtFrom
     * @param float $jdUtTo
     * @return list<Occultation>
     */
    public static function occultations(Star $star, float $jdUtFrom, float $jdUtTo): array
    {
        return Occultations::between($star, $jdUtFrom, $jdUtTo);
    }

    /**
     * The star on the date, in rectangular coordinates on the ecliptic of J2000.
     *
     * Proper motion is applied in right ascension and declination, which is how the
     * catalogue publishes it. `pmRa` carries the cosine of the declination inside (it is
     * μα*, a real displacement on the sky), so to turn it into an increment of right
     * ascension it has to be DIVIDED by that cosine. In Polaris, less than a degree from the
     * pole, not dividing leaves the motion seventy times smaller than it is.
     *
     * @param Star $star
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    private static function eclipticJ2000(Star $star, float $jdTT): array
    {
        $years = ($jdTT - Time::J2000) / 365.25;
        $masToDegrees = 1 / 3.6e6;

        $dec = deg2rad($star->declination + $star->pmDec * $years * $masToDegrees);
        $ra = deg2rad($star->rightAscension + $star->pmRa / cos(deg2rad($star->declination)) * $years * $masToDegrees);

        $x = cos($dec) * cos($ra);
        $y = cos($dec) * sin($ra);
        $z = sin($dec);

        // From the equator to the ecliptic: a rotation about the x axis, which points at the
        // Aries point and is common to both planes.
        $eps = deg2rad(self::OBLIQUITY_J2000 / 3600);

        return [
            $x,
            cos($eps) * $y + sin($eps) * $z,
            -sin($eps) * $y + cos($eps) * $z,
        ];
    }


    /**
     * @param float $degrees
     * @return float
     */
    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }

    /**
     * A name reduced to a key: without accents, in lowercase and with hyphens.
     *
     * Written here and not with a framework helper, which is what was here, **because this
     * engine depends on no framework and that was the only line that did**. Taking it out
     * to a pure PHP package is a matter of when and not of how, and a whole framework
     * dependency for a twenty-character transliteration is not worth it.
     *
     * @param string $name
     * @return string
     */
    private static function asKey(string $name): string
    {
        $withoutAccents = strtr(
            mb_strtolower(trim($name)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']
        );

        return trim(preg_replace('/[^a-z0-9]+/', '-', $withoutAccents) ?? '', '-');
    }
}
