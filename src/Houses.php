<?php

namespace Astronomy;


use RuntimeException;

/**
 * The twelve houses and the angles of the chart.
 *
 * The planets say WHAT; the houses say WHERE in your life. And unlike the positions, which
 * are the same for everybody at that instant, the houses depend on the exact place and the
 * exact minute: they turn one degree every four minutes of clock time. That is why a chart
 * needs the birth time and why a badly noted hour ruins it.
 *
 * Everything here is published spherical trigonometry, part of it since the fifteenth
 * century. There is nothing to license: what Swiss Ephemeris sells is the ephemerides, not
 * the houses.
 *
 * The method is geometric and not a collection of closed formulas copied from a manual. Every
 * system defines a great circle (or a division of time) and here that circle is built with
 * vectors and cut with the ecliptic. It makes for more code, and in exchange every system is
 * written as what it IS, not as a formula nobody can check at a glance.
 *
 * Besides the cusps, it knows how to place a body in its house WITH its latitude
 * (`housePosition`). That is not the same as looking at which cusps its longitude falls
 * between: the Moon strays five degrees from the ecliptic and Pluto seventeen, and in a
 * system that divides the sky with circles (Placidus, Regiomontanus, Campanus...) a body with
 * latitude can be above the horizon while its ecliptic degree is below. The semantics per
 * system follow those of `swe_house_pos` in Swiss Ephemeris, so that the results agree with
 * astro.com.
 */
readonly class Houses
{
    /**
     * @param array<int, float> $cusps All twelve, from 1 to 12, in ecliptic degrees.
     * @param float|null $kochCoAscendant Walter Koch's co-ascendant: the ascendant there would
     *                                     be with the imum coeli on the meridian, seen from the
     *                                     other side.
     * @param float|null $munkaseyCoAscendant Munkasey's co-ascendant: the ascendant computed
     *                                         for the colatitude of the place.
     * @param float|null $polarAscendant Munkasey's polar ascendant, opposite Koch's
     *                                    co-ascendant.
     * @param float|null $geographicLatitude Degrees. Needed to place bodies with latitude.
     * @param float|null $obliquity Degrees. Same.
     * @param float|null $sunDeclination Degrees. Only Sunshine carries it, being the only
     *                                   system that depends on where the Sun is that day.
     */
    public function __construct(
        public HouseSystem $system,
        public array $cusps,
        public float $ascendant,
        public float $midheaven,
        public ?float $vertex,
        public float $eastPoint,
        public float $siderealTime,
        public ?float $kochCoAscendant = null,
        public ?float $munkaseyCoAscendant = null,
        public ?float $polarAscendant = null,
        public ?float $geographicLatitude = null,
        public ?float $obliquity = null,
        public ?float $sunDeclination = null,
        /**
         * The same houses in the tropical zodiac, when these are sidereal. The geometry
         * (semiarcs, circles, sidereal time) is written in tropical terms and knows nothing
         * about ayanamsas: anything that places a body is delegated there with the longitude
         * taken back to tropical. See `sidereal()`.
         */
        public ?Houses $tropical = null,
        /** What has been subtracted from every longitude to make them sidereal. */
        public float $offset = 0.0,
    ) {}

    /**
     * The same houses in the sidereal zodiac: every longitude minus the ayanamsa.
     *
     * The cusps and the angles are copied already shifted, so that whoever reads `cusps` or
     * `ascendant` sees the sidereal sign without knowing anything about ayanamsas. But the
     * geometry that places a body with its latitude (`housePosition`, the Gauquelin sectors) is
     * written in tropical terms, with sidereal time and obliquity, and shifting the cusps
     * without shifting the body too would leave it half a house out. That is why the copy keeps
     * the tropical ones and delegates to them, taking the longitude back to tropical before
     * asking.
     *
     * @param float $ayanamsa Degrees to subtract.
     * @return self
     */
    public function sidereal(float $ayanamsa): self
    {
        $moves = fn (?float $longitude) => $longitude === null ? null : self::normalise($longitude - $ayanamsa);

        $ascendant = $moves($this->ascendant);

        /* Whole signs are not shifted: they are rebuilt over the SIDEREAL signs. Their house
           one is the whole sign the ascendant falls in, that is to say it starts at zero
           degrees of a sign by definition; subtracting the ayanamsa from cusps that sat at zero
           degrees of a tropical sign leaves them six degrees away from any sign, and a whole
           house out on top of that: with the ascendant in tropical Leo and sidereal Cancer,
           cusp one came out in Gemini. And that is precisely the standard pairing in vedic
           astrology, sidereal with whole signs, so it was the most trodden case of all.

           Equal from Aries, for the same reason and even more plainly: its house one IS Aries,
           and on a sidereal chart that is sidereal Aries. Swiss does the same with both. */
        $cusps = $this->system->restsOnTheSigns()
            ? self::fromASeries(self::firstOverTheSigns($this->system, $ascendant))
            : array_map($moves, $this->cusps);

        return new self(
            system: $this->system,
            cusps: $cusps,
            ascendant: $ascendant,
            midheaven: $moves($this->midheaven),
            vertex: $moves($this->vertex),
            eastPoint: $moves($this->eastPoint),
            siderealTime: $this->siderealTime,
            kochCoAscendant: $moves($this->kochCoAscendant),
            munkaseyCoAscendant: $moves($this->munkaseyCoAscendant),
            polarAscendant: $moves($this->polarAscendant),
            geographicLatitude: $this->geographicLatitude,
            obliquity: $this->obliquity,
            sunDeclination: $this->sunDeclination,
            tropical: $this->tropical ?? $this,
            // Accumulated and not replaced: the cusps already carry the previous offset, and
            // keeping only the last one would leave `housePosition` returning the longitude to a
            // a tropical that is not. Today it is only called once, from the chart builder, so
            // this does not show: it shows the day somebody chains it.
            offset: $this->offset + $ayanamsa,
        );
    }

    /**
     * The descendant and the imum coeli are the exact opposites of the ascendant and the
     * midheaven, always and in every system: they are not computed, they are mirrored.
     */
    /**
     * The antivertex, opposite the vertex. Null where the vertex does not exist.
     *
     * @return float|null
     */
    public function antivertex(): ?float
    {
        return $this->vertex === null ? null : self::normalise($this->vertex + 180);
    }

    /**
     * The descendant, which is the ascendant half a turn away.
     *
     * @return float
     */
    public function descendant(): float
    {
        return self::normalise($this->ascendant + 180);
    }

    /**
     * The imum coeli, which is the midheaven half a turn away.
     *
     * @return float
     */
    public function imumCoeli(): float
    {
        return self::normalise($this->midheaven + 180);
    }

    /**
     * Which house a longitude falls in.
     *
     * It goes by the next cusp and not by the distance to the nearest cusp: the houses do not
     * all measure the same. On a high latitude chart with Placidus, one house can span eighty
     * degrees and its neighbour ten, and looking for «the nearest cusp» would put half the
     * planets in the wrong house.
     *
     * And it goes in whichever direction the wheel runs, which is almost always the usual one
     * (houses grow in longitude) and past the polar circle can be the opposite: see
     * `HouseSystem::turnsWithTheMidheaven`. Taking the usual one for granted, a thirty degree
     * house numbered the other way reads as a three hundred and thirty degree one and the whole
     * chart falls in house one.
     *
     * @param float $longitude
     * @return int
     */
    public function houseOf(float $longitude): int
    {
        $longitude = self::normalise($longitude);
        $direction = $this->direction();

        for ($house = 1; $house <= 12; $house++) {
            $from = $this->cusps[$house];
            $to = $this->cusps[$house === 12 ? 1 : $house + 1];

            $width = $direction > 0 ? self::normalise($to - $from) : self::normalise($from - $to);
            $inside = $direction > 0 ? self::normalise($longitude - $from) : self::normalise($from - $longitude);

            if ($inside < $width) {
                return $house;
            }
        }

        return 1;
    }

    /**
     * Which way the houses run: 1 when they grow in longitude (the usual case) and -1 when they
     * shrink.
     *
     * It is read off the cusps themselves and not stored, because that way it survives
     * `sidereal()` without anyone having to remember to carry it along: subtracting the ayanamsa
     * from all twelve does not change the direction they were laid out in.
     *
     * It is measured from cusp 1 to cusp 4, which is a whole quadrant in every system: between
     * neighbouring cusps there are houses of zero width (both Pullens inside the polar circle)
     * and there the sign would say nothing.
     *
     * @return int
     */
    private function direction(): int
    {
        return self::normalise($this->cusps[4] - $this->cusps[1]) < 180 ? 1 : -1;
    }

    /**
     * Which house a body falls in, taking its ecliptic latitude into account.
     *
     * @param float $longitude Ecliptic degrees.
     * @param float $latitude Ecliptic degrees, north positive.
     * @return int
     */
    public function houseWithLatitude(float $longitude, float $latitude): int
    {
        return (int) floor($this->housePosition($longitude, $latitude));
    }

    /**
     * The position of a body within its house, in [1, 13): the integer part is the house and the
     * fraction how much of it has been covered. It is `swe_house_pos` of Swiss Ephemeris.
     *
     * Every system is resolved with the geometry that defines it, not with the cusps:
     *
     * - The ECLIPTIC ones (Equal, Whole sign, Vehlow, Equal from the midheaven, Porphyry,
     *   Sripati, Pullen SD and SR, Morinus) go by longitude and the latitude of the body does
     *   not count, because the system only exists over the ecliptic. In Pullen SD and SR it is
     *   the proportion between cusps, which is what Swiss calls «simplified».
     * - The CIRCLE ones (Regiomontanus, Campanus, Meridian, Carter, Azimuthal, Krusinski) place
     *   the body on the circle that contains it and measure where that circle cuts the one the
     *   system divides: the equator, the prime vertical, the horizon, the circle through the
     *   ascendant and the zenith.
     * - The TIME ones (Placidus, Koch, Alcabitius, Topocentric, APC, Sunshine) measure what
     *   fraction of the corresponding arc the body has covered.
     *
     * The Swiss conventions that have to be known because they do not follow from the
     * definition:
     *
     * - **Placidus in the circumpolar region** uses Otto Ludwig's procedure (1930): a body that
     *   never rises starts its «nocturnal arc» at the lower culmination, and one that never sets
     *   its «diurnal arc» at the upper one. That way there is a position even where there are no
     *   cusps.
     * - **Koch measures time by the rising in the eastern half and by the setting in the western
     *   one**, always in units of the semiarc of the MIDHEAVEN. Since the body has its own
     *   semiarc, at the meridian the two measures do not join up: a body can be east of the
     *   meridian and in house nine. Swiss warns about it in its documentation and it is not a
     *   computation fault, it is the definition. A body whose fraction falls outside [0, 2] (it
     *   only happens with circumpolar bodies) has no position and here an exception is thrown,
     *   where Swiss returns zero.
     * - **Alcabitius divides the equator by the semiarcs of the ASCENDANT** and places the body
     *   by its right ascension, with its latitude. It is consistent with its cusps, which are
     *   hour circles.
     * - **Sripati is Porphyry plus half a house.** Swiss does exactly that and then, on passing
     *   12, writes `hpos = 1` instead of subtracting twelve: it loses the fraction and returns
     *   1.0 for the whole of house twelve. Here twelve is subtracted, which is what it means. It
     *   is the only deliberate divergence.
     * - **Past the polar circle nothing extra is needed**, and it is worth knowing why: the
     *   systems that place by geometry (Regiomontanus, Campanus, Azimuthal, Meridian...) know
     *   nothing about conventions and already return the right house, and the ones that look at
     *   the ascendant (Porphyry, Krusinski, Carter, Equal, APC...) take it from
     *   `$this->ascendant`, which arrives already straightened from `calculate`. If one day one
     *   of these computed the ascendant on its own, that is where the planet would come out six
     *   houses off again.
     * - **Morinus ignores the latitude of the body**, just as its cusps ignore the horizon: it
     *   projects perpendicular to the ecliptic and undoes the equator to ecliptic map that
     *   defines them. That inverse map is `tan a = tan λ / cos ε`, which has the same shape as
     *   getting the midheaven out of sidereal time and is NOT the right ascension of the ecliptic
     *   point (`tan α = cos ε tan λ`). They were confused once and Venus came out 0.16 houses off
     *   in Oslo.
     *
     * @param float $longitude Ecliptic degrees.
     * @param float $latitude Ecliptic degrees, north positive.
     * @return float
     */
    public function housePosition(float $longitude, float $latitude): float
    {
        /* It delegates to the tropical ones because the geometry is written there, BUT not when
           the system rests on the boundaries of the signs: that one is resolved in the zodiac of
           these houses, which is where its signs are. Taking it back to tropical would give the
           tropical house, which is another one. */
        if ($this->tropical !== null && ! $this->system->restsOnTheSigns()) {
            return $this->tropical->housePosition(self::normalise($longitude + $this->offset), $latitude);
        }

        if ($this->geographicLatitude === null || $this->obliquity === null) {
            throw new RuntimeException('These houses were built with no latitude and no obliquity, and without those a body cannot be placed with its latitude. Use Houses::calculate.');
        }

        $eps = deg2rad($this->obliquity);

        $degrees = match ($this->system) {
            HouseSystem::Equal => self::normalise($longitude - $this->ascendant),
            HouseSystem::Vehlow => self::normalise($longitude - $this->ascendant + 15),
            HouseSystem::WholeSign, HouseSystem::EqualAries => self::normalise($longitude - self::firstOverTheSigns($this->system, $this->ascendant)),
            HouseSystem::EqualMidheaven => self::normalise($longitude - $this->midheaven - 90),
            HouseSystem::Porphyry => $this->porphyryDegrees($longitude),
            HouseSystem::Sripati => self::normalise($this->porphyryDegrees($longitude) + 15),
            HouseSystem::PullenSD, HouseSystem::PullenSR => $this->degreesBetweenCusps($longitude),
            HouseSystem::Morinus => self::normalise(self::eclipticInRightAscension($longitude, $eps) - $this->siderealTime - 90),
            default => $this->degreesWithLatitude($longitude, $latitude, $eps),
        };

        return $degrees / 30 + 1;
    }

    /**
     * The thirty six cusps of the Gauquelin sectors.
     *
     * It is Placidus with the semiarc cut in nine instead of in three, and counted in the
     * direction of the diurnal motion: sector 1 starts at the ascendant and climbs, 10 starts at
     * the midheaven, 19 at the descendant and 28 at the imum coeli. The Gauquelins used them for
     * their statistics, and their «positive» zones are sectors 36 to 3, 9 to 12, 19 to 21 and 28
     * to 30: just after rising and just after culminating.
     *
     * It fails past the polar circle for the same reason Placidus does.
     *
     * @return array<int, float> From 1 to 36.
     */
    public function gauquelinSectors(): array
    {
        if ($this->tropical !== null) {
            return array_map(fn (float $l) => self::normalise($l - $this->offset), $this->tropical->gauquelinSectors());
        }

        [$latitude, $eps] = $this->geometry();
        $lst = $this->siderealTime;

        if (abs($latitude) >= 90 - rad2deg($eps)) {
            throw new RuntimeException(sprintf(
                'The Gauquelin sectors are not defined at latitude %.1f: past the polar circle there are degrees of the zodiac that neither rise nor set, and they divide diurnal arcs just as Placidus does.',
                $latitude
            ));
        }

        $sectors = [
            1 => $this->ascendant,
            10 => $this->midheaven,
            19 => self::normalise($this->ascendant + 180),
            28 => self::normalise($this->midheaven + 180),
        ];

        // From 2 to 9: the eastern quadrant above the horizon, at ninths of the diurnal
        // semiarc from the meridian. Sector k has covered (k - 1)/9 of its semiarc.
        for ($sector = 2; $sector <= 9; $sector++) {
            $ninths = 10 - $sector;
            $sectors[$sector] = self::iteratePlacidus($lst, $latitude, $eps, 0.0, $ninths / 9);
            $sectors[$sector + 18] = self::normalise($sectors[$sector] + 180);
        }

        // From 29 to 36: the eastern quadrant below the horizon, at ninths of the nocturnal
        // semiarc from the imum coeli. With the same recipe as cusps 2 and 3 of
        // Placidus: the fixed part makes up for counting from the other side.
        for ($sector = 29; $sector <= 36; $sector++) {
            $ninths = $sector - 28;
            $sectors[$sector] = self::iteratePlacidus($lst, $latitude, $eps, 180 - 20 * $ninths, $ninths / 9);
            $sectors[$sector - 18] = self::normalise($sectors[$sector] + 180);
        }

        ksort($sectors);

        return $sectors;
    }

    /**
     * The Gauquelin sector of a body, in [1, 37), with its latitude.
     *
     * It is the default method of `swe_gauquelin_sector`: the Placidus position with latitude,
     * counted backwards and in thirty six parts. In the circumpolar region the same Otto Ludwig
     * procedure as in Placidus comes in.
     *
     * @param float $longitude Ecliptic degrees.
     * @param float $latitude Ecliptic degrees.
     * @return float
     */
    public function gauquelinSector(float $longitude, float $latitude): float
    {
        if ($this->tropical !== null) {
            return $this->tropical->gauquelinSector(self::normalise($longitude + $this->offset), $latitude);
        }

        [$geographicLatitude, $eps] = $this->geometry();
        [$ra, $dec] = self::equatorial($longitude, $latitude, $eps);

        $placidus = self::placidusDegrees($ra, $dec, $this->siderealTime, $geographicLatitude);

        return self::normalise(360 - $placidus) / 10 + 1;
    }

    /**
     * Computes the houses.
     *
     * @param HouseSystem $system
     * @param float $jdUt Julian day in Universal Time. **UT and not TT**: the houses depend on
     *                    how far the Earth has turned, and that is what the civil clock
     *                    measures. With TT the ascendant shifts by almost a minute of arc.
     * @param float $latitude Degrees, north positive.
     * @param float $geographicLongitude Degrees, east positive.
     * @return self
     */
    public static function calculate(
        HouseSystem $system,
        float $jdUt,
        float $latitude,
        float $geographicLongitude
    ): self {
        $lst = self::normalise(Time::apparentSiderealTime($jdUt) + $geographicLongitude);
        $eps = Time::trueObliquity(Time::centuries(Time::tt($jdUt)));

        // Only Sunshine looks at the Sun, and it is the only reason the houses need an
        // ephemeris at all. The rest do not compute it: it is milliseconds, but milliseconds per
        // chart and by sampling.
        $sunDeclination = $system === HouseSystem::Sunshine ? self::sunDeclination($jdUt, $eps) : null;

        /* The obliquity is passed in RADIANS, which is what has just come out of `Time`, and
           not through the public door `fromArmc`, which takes it in degrees. Converting and
           converting back is not free: measured over 78,972 true obliquities spread from 1600
           to 2400, `deg2rad(rad2deg($eps))` returns a different number in **36 %** of cases.
           That is 1e-11 arcseconds, which is nothing in the sky and everything in a `diff`: it
           moves the last bit of the cusps and with it the fingerprint that checks that an engine
           change has not moved anybody's chart. */
        return self::build($system, $lst, $latitude, $eps, $sunDeclination);
    }

    /**
     * The houses from the ARMC, which is the door for whoever already has their sidereal time
     * and does not want it computed for them: `swe_houses_armc`.
     *
     * The ARMC (right ascension of the midheaven) is LOCAL sidereal time in degrees, that is to
     * say Greenwich's plus the geographic longitude. With it, the latitude and the obliquity,
     * everything the houses need has been said: **the date comes in through no other door**,
     * because a house is geometry of the turned sky and knows nothing about what day it is. That
     * is why this is the real entry point and `calculate()` is only this one with a clock in
     * front of it.
     *
     * **It works without ephemerides for twenty-two of the twenty-three systems.** The odd one out is
     * Sunshine, which divides the diurnal arc of the SUN of that day and therefore needs its
     * declination; here it is asked for as a parameter instead of computed, which is what allows
     * this class to be used without dragging the ephemeris engine behind it. Without it an
     * exception is thrown rather than carrying on: a Sun declination of zero is a Sun on the
     * equator, that is to say an invented equinox day, and that gives cusps that look perfectly
     * fine for the rest of the year.
     *
     * @param HouseSystem $system
     * @param float $armc Degrees. Apparent local sidereal time.
     * @param float $latitude Degrees, north positive.
     * @param float $obliquity Degrees. **Degrees and not radians**, like everything else that
     *                          comes in and out through here and like `swe_houses_armc`. The
     *                          loose statics of this class (`midheaven`, `ascendant`, `vertex`,
     *                          `eclipticPointUnderPole`) do take it in radians, which is their
     *                          working unit.
     * ### That the two doors are the same path, measured
     *
     * Behind both there is `build`, so they cannot diverge by construction. And it is measured:
     * the twenty-three systems in six places (Madrid, Oslo, Ushuaia, Singapore, Longyearbyen and
     * Quito) and five dates from 1655 to 2377, giving this one the ARMC and the obliquity of that
     * instant. Twenty of the 690 combinations throw, which are the four time systems at
     * Longyearbyen and is the point of them throwing, so 670 charts are compared: of their
     * **8,040 cusps, 7,943 come out identical to the bit** and the other 97 differ by at most
     * 4.09e-10 arcseconds.
     *
     * And those 97 are not the computation: they are the round trip of units, because this door
     * takes the obliquity in degrees and the engine works in radians. Of 610 true obliquities
     * spread over those dates, only 244 survive `deg2rad(rad2deg())` intact. That is ten orders of magnitude below the 0.18 arcseconds the engine differs from
     * the JPL by, and it is worth recording so that nobody chases it: there is nothing to fix
     * there.
     *
     * @param float|null $sunDeclination Degrees. Only Sunshine uses it.
     * @return self
     */
    public static function fromArmc(
        HouseSystem $system,
        float $armc,
        float $latitude,
        float $obliquity,
        ?float $sunDeclination = null,
    ): self {
        if ($system === HouseSystem::Sunshine && $sunDeclination === null) {
            throw new RuntimeException('Sunshine divides the diurnal arc of the Sun: with no declination for it there are no houses to compute.');
        }

        /* It is only folded when it needs to be, and this is not a micro-optimisation:
           `normalise` adds 360 and takes it away again, and that loses the low bits of a number
           that was already in range. Measured over 200,000 values in [0,360), **it changes 68 %**
           of them, by up to 5.7e-14 degrees. That is nothing in the sky and it is exactly what
           separates this door from giving the same cusps as `calculate()` DOWN TO THE LAST BIT,
           which is the check that the two really are the same path. */
        $lst = ($armc >= 0.0 && $armc < 360.0) ? $armc : self::normalise($armc);

        return self::build($system, $lst, $latitude, deg2rad($obliquity), $sunDeclination);
    }

    /**
     * The only path that builds a set of houses. `calculate()` and `fromArmc()` are the two doors
     * and this is what sits behind them, so that they cannot diverge.
     *
     * @param HouseSystem $system
     * @param float $lst Degrees, already normalised.
     * @param float $latitude Degrees.
     * @param float $eps Radians.
     * @param float|null $sunDeclination Degrees.
     * @return self
     */
    private static function build(
        HouseSystem $system,
        float $lst,
        float $latitude,
        float $eps,
        ?float $sunDeclination
    ): self {
        $midheaven = self::midheaven($lst, $eps);
        $ascendant = self::ascendant($lst, $latitude, $eps);

        /* The polar convention, and it is the only thing separating a chart from Tromsø from one
           anywhere else. See `midheavenBelowHorizon`: when the midheaven sinks, the point the
           ascendant formula returns is the one that SETS, so the real ascendant is the opposite
           one. That holds for all twenty-three systems.

           The midheaven only moves in the ones that turn as a whole, and there are five of them.
           Moving it in the rest would break exactly what it fixes: Porphyry and company divide
           the arc running from the midheaven to the ascendant, and with both turned that arc is
           still the wrong way round. */
        $underThePole = self::midheavenBelowHorizon($lst, $latitude, $eps);

        if ($underThePole) {
            $ascendant = self::normalise($ascendant + 180);

            if ($system->turnsWithTheMidheaven()) {
                $midheaven = self::normalise($midheaven + 180);
            }
        }

        $cusps = self::cuspsOf($system, $lst, $latitude, $eps, $ascendant, $midheaven, $sunDeclination, $underThePole);

        return new self(
            system: $system,
            cusps: $cusps,
            ascendant: $ascendant,
            midheaven: $midheaven,
            vertex: self::vertex($lst, $latitude, $eps),
            eastPoint: self::eclipticPointUnderPole($lst, 0.0, $eps),
            siderealTime: $lst,
            kochCoAscendant: self::normalise(self::eclipticPointUnderPole($lst - 180, $latitude, $eps) + 180),
            munkaseyCoAscendant: self::munkaseyCoAscendant($lst, $latitude, $eps),
            polarAscendant: self::eclipticPointUnderPole($lst - 180, $latitude, $eps),
            geographicLatitude: $latitude,
            obliquity: rad2deg($eps),
            sunDeclination: $sunDeclination,
        );
    }

    /**
     * The degree of the ecliptic that sits on the meridian.
     *
     * @param float $lst
     * @param float $eps
     * @return float
     */
    public static function midheaven(float $lst, float $eps): float
    {
        $ramc = deg2rad($lst);

        return self::normalise(rad2deg(atan2(sin($ramc), cos($ramc) * cos($eps))));
    }

    /**
     * Whether the degree of the ecliptic on the upper meridian sits BELOW the horizon. It can
     * only happen past the polar circle, and it is the trigger of the whole polar convention.
     *
     * The altitude of a point at culmination is `90 - |latitude - declination|`, so it is
     * negative when those two differ by more than ninety degrees. The declination of the
     * midheaven comes from its right ascension, which is sidereal time: `tan δ = sin(lst) · tan
     * ε`. Since δ never exceeds the obliquity, the condition cannot be met below `90 - ε`, that
     * is 66.5 degrees: **outside the polar circle this is always false and does not move a single
     * arcsecond**, which is what allowed it to be added without touching anything that already
     * worked.
     *
     * It is the same computation `fix_asc_polar` does in Swiss Ephemeris, and it is equivalent to
     * its other test (that the ascendant falls behind the midheaven in the zodiac): measured over
     * 21,600 instants in four polar places, they do not disagree once. This one is written
     * because it says what happens in the sky instead of a consequence of it.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps Radians.
     * @return bool
     */
    private static function midheavenBelowHorizon(float $lst, float $latitude, float $eps): bool
    {
        $declination = rad2deg(atan(sin(deg2rad($lst)) * tan($eps)));

        return abs($latitude - $declination) > 90;
    }

    /**
     * The degree of the ecliptic rising over the eastern horizon.
     *
     * It comes from imposing zero altitude: a point of the ecliptic at longitude L has a known
     * declination and right ascension, and asking for its altitude to be zero leaves an equation
     * in tan(L). Of the two solutions (the horizon is cut at two opposite points) the one to keep
     * is the one that is RISING, not the one going down, which is the descendant.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @return float
     */
    public static function ascendant(float $lst, float $latitude, float $eps): float
    {
        return self::eclipticPointUnderPole($lst, $latitude, $eps);
    }

    /**
     * The vertex: where the prime vertical cuts the ecliptic on the western side.
     *
     * It is the ascendant seen from the other side, so it comes out of the same formula with the
     * colatitude and the opposite meridian.
     *
     * But that formula gives ONE of the two cuts, and the two are opposite. The prime vertical is
     * the circle through the east point, the zenith and the west point, so all of its points lie
     * either east or west, never in between: the vertex is the western one, that is to say the
     * one whose hour angle is between zero and one hundred and eighty. The other one is the
     * antivertex.
     *
     * Keeping whichever one comes out raises no error: it returns a perfectly ordinary point, in
     * some sign or other, half a circle out of place. It was caught by comparing against Swiss
     * Ephemeris, which had it on the other side in seventy two charts out of eighty.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @return float
     */
    public static function vertex(float $lst, float $latitude, float $eps): ?float
    {
        $colatitude = ($latitude >= 0 ? 90 : -90) - $latitude;

        /* At the equator there is no vertex, and returning a number would be worse than
           returning nothing. The colatitude is exactly ninety degrees, which means the circle
           the ecliptic is cut with passes through the celestial poles and does not cut it at a
           point: the vertex runs off to infinity. It is not an awkward case worth avoiding, it is
           that the definition does not exist there. */
        if (abs($colatitude) > 89.9) {
            return null;
        }

        $cut = self::eclipticPointUnderPole(self::normalise($lst + 180), $colatitude, $eps);

        /* The two candidates sit exactly half a turn apart, so their hour angles do too:
           exactly one of the two falls in the western semicircle. */
        foreach ([$cut, self::normalise($cut + 180)] as $candidate) {
            $hourAngle = self::normalise($lst - self::rightAscensionOf($candidate, $eps));

            if ($hourAngle < 180) {
                return $candidate;
            }
        }

        return $cut;
    }

    /**
     * Munkasey's co-ascendant: the ascendant computed with the colatitude of the place.
     *
     * At the equator the colatitude is ninety and the ascendant formula does not exist (there the
     * horizon of the imaginary place coincides with the equator). Swiss then returns 0 Libra in
     * the northern hemisphere and 0 Aries in the southern one, and that is also the limit the
     * formula tends to on approach, so it is followed.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @return float
     */
    private static function munkaseyCoAscendant(float $lst, float $latitude, float $eps): float
    {
        $colatitude = ($latitude >= 0 ? 90 : -90) - $latitude;

        if (abs(abs($colatitude) - 90) < 1e-9) {
            return $colatitude > 0 ? 180.0 : 0.0;
        }

        return self::eclipticPointUnderPole($lst, $colatitude, $eps);
    }

    /**
     * The right ascension of the point of the ecliptic at that longitude.
     *
     * The inverse of `eclipticInRightAscension`.
     *
     * @param float $longitude
     * @param float $eps
     * @return float
     */
    private static function rightAscensionOf(float $longitude, float $eps): float
    {
        $l = deg2rad($longitude);

        return self::normalise(rad2deg(atan2(cos($eps) * sin($l), cos($l))));
    }

    /**
     * The declination of the Sun at that instant, for Sunshine.
     *
     * The houses come in in UT and the ephemerides run in TT: they are the two scales of always,
     * and here they cross. Seventy seconds of difference move the Sun less than three arcseconds,
     * but it is the same crossing that once shifted the ascendant by a minute.
     *
     * @param float $jdUt
     * @param float $eps Radians.
     * @return float Degrees.
     */
    private static function sunDeclination(float $jdUt, float $eps): float
    {
        $sun = Ephemeris::position(Body::Sun, Time::tt($jdUt));

        return Declinations::of($sun->longitude, $sun->latitude, $eps);
    }

    /**
     * The general formula the ascendant, the east point and the cusps of the systems that work
     * with poles all come out of.
     *
     * `$h` is the angle of the circle over the equator and `$pole` the inclination of that
     * circle. With the sidereal time and the latitude of the place it gives the ascendant; with a
     * pole of zero, the east point; with intermediate poles, the intermediate cusps.
     *
     * Of the two cuts of the circle with the ecliptic it returns the one falling in the half of
     * the circle centred on the point where it cuts the equator (`$h + 90`). For the horizon that
     * is the eastern half, that is to say the ascendant. It is exactly the branch `Asc1` of Swiss
     * picks, and it is worth knowing because the half centred on the cut with the equator is not
     * the half above the horizon: they coincide only when that cut is ninety degrees away from
     * the north and south points.
     *
     * @param float $h Degrees.
     * @param float $pole Degrees.
     * @param float $eps Radians.
     * @return float
     */
    public static function eclipticPointUnderPole(float $h, float $pole, float $eps): float
    {
        $hr = deg2rad($h);
        $pr = deg2rad($pole);

        // At the geographic pole the tangent runs off to infinity and there is no horizon that
        // cuts the ecliptic at a point: the horizon IS the celestial equator.
        if (abs(abs($pole) - 90) < 1e-9) {
            throw new RuntimeException('There is no ascendant at the geographic pole: there the horizon coincides with the equator.');
        }

        return self::normalise(rad2deg(atan2(
            cos($hr),
            -(sin($hr) * cos($eps) + tan($pr) * sin($eps))
        )));
    }

    /**
     * @param HouseSystem $system
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param float $ascendant
     * @param float $midheaven
     * @param float|null $sunDeclination
     * @param bool $underThePole Whether the midheaven is below the horizon. The ascendant and (in
     *                         the ones that turn) the midheaven arrive already straightened; this
     *                         is only needed where the DIRECTION the cusps chain in changes too.
     * @return array<int, float>
     */
    private static function cuspsOf(
        HouseSystem $system,
        float $lst,
        float $latitude,
        float $eps,
        float $ascendant,
        float $midheaven,
        ?float $sunDeclination = null,
        bool $underThePole = false
    ): array {
        // The systems that do not look at the horizon are resolved whole in one line, and the
        // ones whose opposite houses are not opposite (APC, Sunshine) or that do not turn around
        // the angles (Azimuthal, Carter) are built whole as well.
        $cusps = match ($system) {
            HouseSystem::WholeSign, HouseSystem::EqualAries => self::fromASeries(self::firstOverTheSigns($system, $ascendant)),
            HouseSystem::Equal => self::fromASeries($ascendant),
            HouseSystem::Vehlow => self::fromASeries($ascendant - 15),
            HouseSystem::EqualMidheaven => self::fromASeries($midheaven + 90),
            HouseSystem::Morinus => self::morinus($lst, $eps),
            HouseSystem::Sripati => self::sripati($ascendant, $midheaven),
            HouseSystem::Carter => self::carter($ascendant, $eps),
            HouseSystem::Azimuthal => self::azimuthal($lst, $latitude, $eps, $midheaven),
            HouseSystem::Apc => self::overParallel($lst, $latitude, $eps, rad2deg(asin(sin($eps) * sin(deg2rad($ascendant)))), $ascendant, $midheaven),
            HouseSystem::Sunshine => self::overParallel($lst, $latitude, $eps, $sunDeclination ?? 0.0, $ascendant, $midheaven),
            default => null,
        };

        if ($cusps !== null) {
            return $cusps;
        }

        if ($system->failsAtThePoles() && abs($latitude) >= 90 - rad2deg($eps)) {
            throw new RuntimeException(sprintf(
                'The %s system is not defined at latitude %.1f: past the polar circle there are degrees of the zodiac that neither rise nor set, and this system divides diurnal arcs.',
                $system->name(),
                $latitude
            ));
        }

        // Six are computed and the other six are their opposites. No house definition
        // breaks that symmetry, so computing them separately would be
        // doing the work twice and risking that they do not close.
        $half = match ($system) {
            HouseSystem::Porphyry => self::porphyry($ascendant, $midheaven),
            HouseSystem::Placidus => self::placidus($lst, $latitude, $eps, $ascendant, $midheaven),
            HouseSystem::Alcabitius => self::alcabitius($lst, $latitude, $eps, $ascendant),
            HouseSystem::Koch => self::koch($lst, $latitude, $eps, $ascendant),
            HouseSystem::Topocentric => self::withPoles($lst, $eps, self::topocentricPoles($latitude)),
            HouseSystem::Regiomontanus => self::withCircles($lst, $latitude, $eps, 'regiomontanus', $midheaven, $underThePole),
            HouseSystem::Campanus => self::withCircles($lst, $latitude, $eps, 'campanus', $midheaven, $underThePole),
            HouseSystem::SavardA => self::withCircles($lst, $latitude, $eps, 'savard', $midheaven, $underThePole),
            HouseSystem::Meridian => self::withCircles($lst, $latitude, $eps, 'meridian', $midheaven),
            HouseSystem::Krusinski => self::krusinski($lst, $latitude, $eps, $ascendant),
            HouseSystem::PullenSD => self::pullenSD($ascendant, $midheaven),
            HouseSystem::PullenSR => self::pullenSR($ascendant, $midheaven),
            default => throw new RuntimeException("House system not implemented: {$system->value}"),
        };

        return self::fromMidpoint($half);
    }

    /**
     * Where house one starts in the systems nailed to the signs: zero degrees of the sign of the
     * ascendant in Whole signs, and zero degrees of Aries in Equal from Aries.
     *
     * It lives in a single place because three of them ask for it (the cusps, the sidereal copy
     * and the house position), and with the computation written out in all three one day one of
     * them would stop agreeing without warning.
     *
     * @param HouseSystem $system
     * @param float $ascendant
     * @return float
     */
    private static function firstOverTheSigns(HouseSystem $system, float $ascendant): float
    {
        return $system === HouseSystem::EqualAries ? 0.0 : floor($ascendant / 30) * 30;
    }

    /**
     * The twelve cusps from the six on the midheaven side and the ascendant.
     *
     * @param list<float> $half Cusps 10, 11, 12, 1, 2 and 3, in that order.
     * @return array<int, float>
     */
    private static function fromMidpoint(array $half): array
    {
        $cusps = [];

        foreach ([10, 11, 12, 1, 2, 3] as $i => $house) {
            $cusps[$house] = self::normalise($half[$i]);
            $cusps[$house > 6 ? $house - 6 : $house + 6] = self::normalise($half[$i] + 180);
        }

        ksort($cusps);

        return $cusps;
    }

    /**
     * Twelve cusps every thirty degrees starting from one.
     *
     * @param float $first
     * @return array<int, float>
     */
    private static function fromASeries(float $first): array
    {
        $cusps = [];

        for ($house = 1; $house <= 12; $house++) {
            $cusps[$house] = self::normalise($first + 30 * ($house - 1));
        }

        return $cusps;
    }

    /**
     * Morinus: divides the equator and projects onto the ecliptic without going through the
     * horizon.
     *
     * That is why it is the only quadrant system in which house one is NOT the ascendant nor
     * house ten the midheaven. It is not a fault: Morinus did away with the horizon on purpose,
     * so that the houses would not be deformed by latitude.
     *
     * @param float $lst
     * @param float $eps
     * @return array<int, float>
     */
    private static function morinus(float $lst, float $eps): array
    {
        $cusps = [];

        for ($house = 1; $house <= 12; $house++) {
            $a = deg2rad(self::normalise($lst + 90 + 30 * ($house - 1)));

            $cusps[$house] = self::normalise(rad2deg(atan2(sin($a) * cos($eps), cos($a))));
        }

        return $cusps;
    }

    /**
     * Porphyry: it cuts each quadrant of the ecliptic in three.
     *
     * @param float $ascendant
     * @param float $midheaven
     * @return list<float>
     */
    private static function porphyry(float $ascendant, float $midheaven): array
    {
        $quadrant = self::normalise($ascendant - $midheaven) / 3;
        $next = (180 - self::normalise($ascendant - $midheaven)) / 3;

        return [
            $midheaven,
            $midheaven + $quadrant,
            $midheaven + 2 * $quadrant,
            $ascendant,
            $ascendant + $next,
            $ascendant + 2 * $next,
        ];
    }

    /**
     * Sripati: every Porphyry cusp becomes the CENTRE of its house.
     *
     * It is the Indian way of understanding a house (bhava): the ascendant does not begin it, it
     * presides over it from the middle, and the boundary with the next one (sandhi) falls halfway
     * between two Porphyry cusps. So cusp k here is the midpoint of Porphyry's house k - 1.
     *
     * @param float $ascendant
     * @param float $midheaven
     * @return array<int, float>
     */
    private static function sripati(float $ascendant, float $midheaven): array
    {
        $porphyry = self::fromMidpoint(self::porphyry($ascendant, $midheaven));
        $cusps = [];

        for ($house = 1; $house <= 12; $house++) {
            $previous = $porphyry[$house === 1 ? 12 : $house - 1];

            $cusps[$house] = self::normalise($previous + self::normalise($porphyry[$house] - $previous) / 2);
        }

        return $cusps;
    }

    /**
     * Pullen SD («sinusoidal delta», formerly «Neo-Porphyry»): like Porphyry, but the three houses
     * of the quadrant are not equal. Each starts at thirty degrees, the middle one takes half of
     * however far the quadrant departs from ninety, and the two on either side a quarter each. The
     * widths follow a wave around the wheel.
     *
     * When the quadrant drops below thirty degrees the middle house would come out negative, and
     * there it is left at zero and the other two share the quadrant out. That is what Treindl
     * pointed out to Pullen in 2016, and Pullen SR came out of that conversation.
     *
     * @param float $ascendant
     * @param float $midheaven
     * @return list<float>
     */
    private static function pullenSD(float $ascendant, float $midheaven): array
    {
        $quadrant = self::normalise($ascendant - $midheaven);

        [$ten, $eleven] = self::pullenSDWidths($quadrant);
        [$one, $two] = self::pullenSDWidths(180 - $quadrant);

        return [
            $midheaven,
            $midheaven + $ten,
            $midheaven + $ten + $eleven,
            $ascendant,
            $ascendant + $one,
            $ascendant + $one + $two,
        ];
    }

    /**
     * The widths of the three houses of a quadrant in Pullen SD: the first, the middle one and the
     * last (which measures the same as the first).
     *
     * @param float $quadrant
     * @return array{0: float, 1: float, 2: float}
     */
    private static function pullenSDWidths(float $quadrant): array
    {
        $deviation = $quadrant - 90;
        $mean = 30 + $deviation / 2;

        if ($mean <= 0) {
            return [$quadrant / 2, 0.0, $quadrant / 2];
        }

        return [30 + $deviation / 4, $mean, 30 + $deviation / 4];
    }

    /**
     * Pullen SR («sinusoidal ratio»): the houses of the quadrant keep a RATIO between them instead
     * of a difference. If x is the smallest house (the middle one of the narrow quadrant), the
     * ones on either side measure rx, and in the wide quadrant the three measure r³x, r⁴x and
     * r³x. With that, no house empties out however lopsided the chart is.
     *
     * The two equations (2rx + x = q, 2r³x + r⁴x = 180 - q) leave r as the root of a fourth degree
     * polynomial, r⁴ + 2r³ - 2cr - c = 0 with c = (180 - q)/q. Swiss solves it with Ferrari's
     * radicals; here the root is found by bisection, which is the equation written as it is and
     * not a twelve line formula nobody can check. For q = 90 the root is exactly 1 and the six
     * houses measure 30.
     *
     * @param float $ascendant
     * @param float $midheaven
     * @return list<float>
     */
    private static function pullenSR(float $ascendant, float $midheaven): array
    {
        $quadrant = self::normalise($ascendant - $midheaven);
        $narrow = min($quadrant, 180 - $quadrant);

        if ($narrow < 1e-9) {
            // A null quadrant (it only happens inside the polar circle): the large house takes
            // takes everything, as in Swiss.
            [$x, $rx, $r3x, $r4x] = [0.0, 0.0, 0.0, 180.0];
        } else {
            $c = (180 - $narrow) / $narrow;
            $r = self::pullenRoot($c);
            $x = $narrow / (2 * $r + 1);
            $rx = $r * $x;
            $r3x = $rx * $r * $r;
            $r4x = $r3x * $r;
        }

        // The narrow quadrant gets (rx, x, rx) and the wide one (r³x, r⁴x, r³x).
        [$ten, $eleven] = $quadrant > 90 ? [$r3x, $r4x] : [$rx, $x];
        [$one, $two] = $quadrant > 90 ? [$rx, $x] : [$r3x, $r4x];

        return [
            $midheaven,
            $midheaven + $ten,
            $midheaven + $ten + $eleven,
            $ascendant,
            $ascendant + $one,
            $ascendant + $one + $two,
        ];
    }

    /**
     * The positive root of r⁴ + 2r³ - 2cr - c, which for c ≥ 1 sits in [1, ∞) and is unique: the
     * polynomial is worth -c at zero, drops to a minimum and rises forever.
     *
     * @param float $c
     * @return float
     */
    private static function pullenRoot(float $c): float
    {
        $f = fn (float $r): float => $r ** 4 + 2 * $r ** 3 - 2 * $c * $r - $c;

        $below = 0.0;
        $height = 1.0;

        while ($f($height) < 0) {
            $height *= 2;
        }

        for ($turn = 0; $turn < 200; $turn++) {
            $mid = ($below + $height) / 2;

            if ($f($mid) < 0) {
                $below = $mid;
            } else {
                $height = $mid;
            }

            if ($height - $below < 1e-15) {
                break;
            }
        }

        return ($below + $height) / 2;
    }

    /**
     * Carter «poli-equatorial»: hour circles dividing the equator into twelve from the right
     * ascension of the ascendant.
     *
     * It is Meridian starting at the ascendant instead of at the midheaven, so here house one IS
     * the ascendant and house ten is NOT the midheaven. Carter defended it because it turns with
     * the sky and gives different cusps for every house, which equal houses do not.
     *
     * @param float $ascendant
     * @param float $eps
     * @return array<int, float>
     */
    private static function carter(float $ascendant, float $eps): array
    {
        $ascension = self::rightAscensionOf($ascendant, $eps);
        $cusps = [];

        for ($house = 1; $house <= 12; $house++) {
            $cusps[$house] = self::eclipticInRightAscension($ascension + 30 * ($house - 1), $eps);
        }

        return $cusps;
    }

    /**
     * Azimuthal (or horizontal): divides the HORIZON into twelve and draws through each point its
     * vertical, the circle passing through the zenith and the nadir.
     *
     * House one starts at due east (the antivertex) and house seven at due west (the vertex), not
     * at the ascendant: the ascendant is on the horizon but almost never due east. House ten is
     * the midheaven, because the vertical through north and south is the meridian.
     *
     * The houses run from the midheaven towards the east, and which way that is depends on where
     * the midheaven sits: south of the zenith in the northern hemisphere, north of it in the
     * southern one, and in the tropics it depends on the day. It is looked at, not assumed from
     * the latitude: with latitude alone, a chart from Singapore with the midheaven north of the
     * zenith would come out with houses eleven and twelve behind the midheaven.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param float $midheaven
     * @return array<int, float>
     */
    private static function azimuthal(float $lst, float $latitude, float $eps, float $midheaven): array
    {
        [$zenith, $north, $east] = self::frame($lst, $latitude);
        [$mcAzimuth, $direction] = self::azimuthalOrientation($midheaven, $eps, $north);

        $cusps = [];

        for ($house = 1; $house <= 12; $house++) {
            $point = self::combine($north, $east, deg2rad($mcAzimuth + $direction * 30 * ($house - 10)));

            $cusps[$house] = self::cutOnTheSide(self::product($zenith, $point), $eps, $point);
        }

        return $cusps;
    }

    /**
     * From which azimuth and in which direction the azimuthal houses are counted: that of the
     * midheaven (180 when it is south of the zenith, 0 when north) and towards the east.
     *
     * @param float $midheaven
     * @param float $eps
     * @param array{0: float, 1: float, 2: float} $north
     * @return array{0: float, 1: int}
     */
    private static function azimuthalOrientation(float $midheaven, float $eps, array $north): array
    {
        $toTheSouth = self::scale(self::equatorialVector($midheaven, 0.0, $eps), $north) < 0;

        return $toTheSouth ? [180.0, -1] : [0.0, 1];
    }

    /**
     * Krusinski (and Pisa, and Goelzer, who published it separately in 1994 and 1995): divides
     * into twelve the great circle through the ascendant and the zenith, and carries each point to
     * the ecliptic by its hour circle, that is at constant right ascension.
     *
     * Cusp one is the ascendant and cusp ten the zenith, which projected by its hour circle is the
     * midheaven. Since the ascendant is on the horizon, it is perpendicular to the zenith, and the
     * circle is defined by those two vectors and nothing else.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param float $ascendant
     * @return list<float>
     */
    private static function krusinski(float $lst, float $latitude, float $eps, float $ascendant): array
    {
        [$u, $v] = self::krusinskiCircle($lst, $latitude, $eps, $ascendant);
        $cusps = [];

        // From the zenith (house 10) towards the ascendant (house 1) and beyond, below the horizon.
        foreach ([10, 11, 12, 1, 2, 3] as $house) {
            $point = self::combine($u, $v, deg2rad(-30 * ($house - 1)));

            $cusps[] = self::eclipticInRightAscension(self::rightAscensionOfVector($point), $eps);
        }

        return $cusps;
    }

    /**
     * The base of the Krusinski circle: the ascendant and the direction of the zenith.
     *
     * @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}}
     */
    private static function krusinskiCircle(float $lst, float $latitude, float $eps, float $ascendant): array
    {
        [$zenith] = self::frame($lst, $latitude);
        $u = self::equatorialVector($ascendant, 0.0, $eps);

        return [$u, self::unit(self::perpendicularTo($zenith, $u))];
    }

    /**
     * APC and Sunshine: position circles through the points dividing a PARALLEL of declination.
     *
     * The two systems are the same construction over different parallels: APC takes that of the
     * ascendant and Sunshine that of the Sun of the day. The diurnal semiarc is cut in three and
     * the nocturnal one in three, through each dividing point the circle through the north and
     * south points of the horizon is drawn, and where that circle cuts the ecliptic is the cusp.
     *
     * That is why opposite houses are NOT opposite: the dividing point of the diurnal arc and that
     * of the nocturnal one are not antipodes (the antipodes of a parallel fall on the opposite
     * parallel), so cusps 11, 12, 2 and 3 are computed and so are 5, 6, 8 and 9, each with its own
     * circle. Only 1, 4, 7 and 10 are the ascendant and the midheaven with their opposites.
     *
     * Of the two cuts of the circle with the ecliptic the one taken is the one in the same
     * semicircle (from north to south) as the dividing point, which is what the definition says
     * and does not depend on the order things are computed in.
     *
     * When the parallel neither rises nor sets (the Sun inside the polar circle) Swiss gives by
     * convention an arc of 180 or of 0 degrees, and that way the intermediate cusps of that side
     * fall on the meridian. The convention is followed: it is what keeps the system from failing
     * there. But WHICH of the two points of the meridian has to be said separately, because with
     * the arc at zero the dividing point falls right on the meridian, the position circle IS the
     * meridian and its two cuts with the ecliptic are the midheaven and the imum coeli: the
     * semicircle rule has nothing to choose with and used to return the upper one every time.
     * With no night, the four nocturnal houses pile up on the IMUM COELI; with no day, the four
     * diurnal ones on the midheaven. It is what Swiss does and it is the only coherent thing,
     * because that way cusp 4 and its own say the same. Here the midheaven came out in all four
     * nocturnal ones, that is house 2 on the tip of house 10, and it only happens past the polar
     * circle and only with the Sun.
     *
     * It does not happen to APC, and that is not luck: its parallel is the ascendant's, which is
     * on the horizon, and a point of the horizon cannot be circumpolar. Its `tan φ · tan δ`
     * reaches one exactly at tangency and never passes it.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param float $declination That of the parallel, in degrees.
     * @param float $ascendant
     * @param float $midheaven
     * @return array<int, float>
     */
    private static function overParallel(
        float $lst,
        float $latitude,
        float $eps,
        float $declination,
        float $ascendant,
        float $midheaven
    ): array {
        [, $north] = self::frame($lst, $latitude);
        [$diurnal, $nocturnal] = self::semiArcs($declination, $latitude);

        $ascensions = [
            11 => $lst + $diurnal / 3,
            12 => $lst + 2 * $diurnal / 3,
            9 => $lst - $diurnal / 3,
            8 => $lst - 2 * $diurnal / 3,
            2 => $lst + 180 - 2 * $nocturnal / 3,
            3 => $lst + 180 - $nocturnal / 3,
            5 => $lst + 180 + $nocturnal / 3,
            6 => $lst + 180 + 2 * $nocturnal / 3,
        ];

        $cusps = [
            1 => self::normalise($ascendant),
            4 => self::normalise($midheaven + 180),
            7 => self::normalise($ascendant + 180),
            10 => self::normalise($midheaven),
        ];

        foreach ($ascensions as $house => $alpha) {
            $point = self::equatorialVectorFrom($alpha, $declination);

            $cusps[$house] = self::cutOnTheSide(
                self::product($north, $point),
                $eps,
                self::perpendicularTo($point, $north)
            );
        }

        // The arc that does not exist: its three houses pile up at the tip of the quadrant that
        // they have left. See the explanation above.
        foreach ([[$nocturnal, [2, 3, 5, 6], 4], [$diurnal, [8, 9, 11, 12], 10]] as [$arc, $houses, $tip]) {
            if ($arc > 0) {
                continue;
            }

            foreach ($houses as $house) {
                $cusps[$house] = $cusps[$tip];
            }
        }

        ksort($cusps);

        return $cusps;
    }

    /**
     * The diurnal and nocturnal semiarcs of a parallel of declination, saturated at 180 and 0 when
     * it neither rises nor sets (the Swiss convention for APC and Sunshine).
     *
     * @param float $declination Degrees.
     * @param float $latitude Degrees.
     * @return array{0: float, 1: float}
     */
    private static function semiArcs(float $declination, float $latitude): array
    {
        $sine = tan(deg2rad($latitude)) * tan(deg2rad($declination));
        $ascensionalDifference = rad2deg(asin(max(-1.0, min(1.0, $sine))));

        return [90 + $ascensionalDifference, 90 - $ascensionalDifference];
    }

    /**
     * Placidus: it divides TIME, not space.
     *
     * Every degree of the zodiac takes a while to climb from the horizon to the meridian, and that
     * while depends on its declination and on the latitude of the place: in Oslo a degree of
     * Cancer takes far longer than one of Capricorn. Placidus cuts that journey in three and puts
     * cusps eleven and twelve there.
     *
     * Since the arc of a degree depends on which degree it is, and which degree it is happens to
     * be exactly what is being looked for, there is no closed formula: it is iterated. It
     * converges in four or five rounds.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param float $ascendant
     * @param float $midheaven
     * @return list<float>
     */
    private static function placidus(
        float $lst,
        float $latitude,
        float $eps,
        float $ascendant,
        float $midheaven
    ): array {
        /* Cusps 10 and 1 are NOT iterated: they are the midheaven and the ascendant, and for those
           there is a closed formula.

           They came out of the iteration just as well at middle latitudes, and in Oslo the
           ascendant stayed seven arcseconds off. The reason is that the fraction of the ascendant
           is worth one whole, and there the fixed point loses contraction: near the polar circle
           the semiarc changes almost as fast as the angle it depends on, and thirty rounds are no
           longer enough. Using the exact value removes the problem instead of papering over it
           with more iterations. */
        $recipes = [
            [0.0, 1 / 3],      // 11
            [0.0, 2 / 3],      // 12
            [60.0, 2 / 3],     // 2
            [120.0, 1 / 3],    // 3
        ];

        $intermediate = [];

        foreach ($recipes as [$fixed, $fraction]) {
            $intermediate[] = self::iteratePlacidus($lst, $latitude, $eps, $fixed, $fraction);
        }

        return [
            $midheaven,
            $intermediate[0],
            $intermediate[1],
            $ascendant,
            $intermediate[2],
            $intermediate[3],
        ];
    }

    /**
     * The point of the ecliptic whose right ascension is worth `lst + fixed + fraction · its own
     * diurnal semiarc`. It is the Placidus iteration, and it is shared by its four intermediate
     * cusps and by the thirty two intermediate Gauquelin sectors.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param float $fixed Degrees.
     * @param float $fraction Of the diurnal semiarc.
     * @return float
     */
    private static function iteratePlacidus(float $lst, float $latitude, float $eps, float $fixed, float $fraction): float
    {
        $alpha = $lst + $fixed + 90 * $fraction;

        for ($turn = 0; $turn < 100; $turn++) {
            $previous = $alpha;
            $next = $lst + $fixed + $fraction * self::diurnalSemiArc($alpha, $latitude, $eps);

            // Halfway between the old and the new: it damps the iteration and makes it
            // converge even where the plain fixed point would only oscillate.
            $alpha = ($previous + $next) / 2;

            if (abs($alpha - $previous) < 1e-11) {
                break;
            }
        }

        return self::eclipticInRightAscension($alpha, $eps);
    }

    /**
     * Alcabitius: like Placidus but always using the arc of the ascendant.
     *
     * The degree that was rising at birth takes its own while to reach the meridian, and that
     * journey (its own, not that of each cusp) is the one cut in three. That is why there is no
     * need to iterate: the arc is known the moment the ascendant is known.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param float $ascendant
     * @return list<float>
     */
    private static function alcabitius(float $lst, float $latitude, float $eps, float $ascendant): array
    {
        $declination = asin(sin($eps) * sin(deg2rad($ascendant)));
        $cosine = -tan(deg2rad($latitude)) * tan($declination);

        if (abs($cosine) > 1) {
            throw new RuntimeException('The rising degree never sets at this latitude: Alcabitius has no arc to divide.');
        }

        $diurnal = rad2deg(acos($cosine));
        $nocturnal = 180 - $diurnal;

        $alphas = [
            $lst,
            $lst + $diurnal / 3,
            $lst + 2 * $diurnal / 3,
            $lst + $diurnal,
            $lst + $diurnal + $nocturnal / 3,
            $lst + $diurnal + 2 * $nocturnal / 3,
        ];

        return array_map(fn (float $alpha) => self::eclipticInRightAscension($alpha, $eps), $alphas);
    }

    /**
     * Koch: it shares out the ascensional difference of the ascendant.
     *
     * It starts from the same idea as Alcabitius (use the arc of the degree that was rising at
     * birth and not that of each cusp) and solves it by another route: instead of projecting with
     * hour circles, it computes every cusp as if it were an ascendant, advancing sidereal time
     * thirty degrees per house and sharing out in thirds however far the ascendant departs from
     * the equator.
     *
     * That departure is the ascensional difference: how much the rising of a point is early or
     * late with respect to the theoretical six o'clock. At the equator it is worth zero and Koch
     * falls exactly onto Placidus and Regiomontanus, which is the check that says it is properly
     * set up.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param float $ascendant
     * @return list<float>
     */
    private static function koch(float $lst, float $latitude, float $eps, float $ascendant): array
    {
        /* The ascensional difference is taken from the MIDHEAVEN, not from the ascendant.

           It used to be taken from the ascendant, which is the error that looks natural because
           Koch is explained as «the arc of the degree that was rising at birth». What it shares
           out in thirds, however, is the arc of the degree that was CULMINATING, and the
           difference is not small: at this latitude it gave +7.20 degrees where it should have
           been −3.10, and the eight intermediate cusps came out up to 35 degrees off. The four
           angles were right, so the wheel looked perfectly normal.

           It was caught by comparing against Swiss Ephemeris: eleven of the twelve systems agreed
           to better than a third of an arcsecond and this one went to 126,000. */
        $declination = asin(sin($eps) * sin(deg2rad(self::midheaven($lst, $eps))));
        $sine = tan(deg2rad($latitude)) * tan($declination);

        if (abs($sine) > 1) {
            throw new RuntimeException('The rising degree never sets at this latitude: Koch has no arc to share out.');
        }

        $third = rad2deg(asin($sine)) / 3;

        return [
            self::midheaven($lst, $eps),
            self::eclipticPointUnderPole($lst - 60 - 2 * $third, $latitude, $eps),
            self::eclipticPointUnderPole($lst - 30 - $third, $latitude, $eps),
            $ascendant,
            self::eclipticPointUnderPole($lst + 30 + $third, $latitude, $eps),
            self::eclipticPointUnderPole($lst + 60 + 2 * $third, $latitude, $eps),
        ];
    }

    /**
     * Topocentric: defined directly by poles growing in thirds.
     *
     * @param float $latitude
     * @return list<float>
     */
    private static function topocentricPoles(float $latitude): array
    {
        $tangent = tan(deg2rad($latitude));

        return [
            0.0,
            rad2deg(atan($tangent / 3)),
            rad2deg(atan(2 * $tangent / 3)),
            $latitude,
            rad2deg(atan(2 * $tangent / 3)),
            rad2deg(atan($tangent / 3)),
        ];
    }

    /**
     * @param float $lst
     * @param float $eps
     * @param list<float> $poles
     * @return list<float>
     */
    private static function withPoles(float $lst, float $eps, array $poles): array
    {
        $cusps = [];

        foreach ($poles as $i => $pole) {
            $cusps[] = self::eclipticPointUnderPole($lst - 90 + 30 * $i, $pole, $eps);
        }

        return $cusps;
    }

    /**
     * The systems that cut the ecliptic with great circles.
     *
     * The three share out twelve circles through the same two points and differ in WHAT circle
     * they divide into equal parts: Regiomontanus the equator, Campanus the prime vertical,
     * Meridian the equator again but from the celestial poles.
     *
     * They are built with vectors instead of with closed formulas because that way each one is
     * written as its own definition, and definitions can be checked.
     *
     * @param float $lst
     * @param float $latitude
     * @param float $eps
     * @param string $family
     * @param float $midheaven
     * @param bool $underThePole With the midheaven sunk, the wheel of Regiomontanus and Campanus is
     *                         numbered CLOCKWISE (see `HouseSystem::turnsWithTheMidheaven`), so
     *                         every cusp goes BEHIND the previous one and not ahead of it. Without
     *                         inverting the criterion, the chain picks the opposite branch on
     *                         alternate cusps and six come out right and six upside down, which is
     *                         the worst that can happen: it does not show.
     * @return list<float>
     */
    private static function withCircles(
        float $lst,
        float $latitude,
        float $eps,
        string $family,
        float $midheaven,
        bool $underThePole = false
    ): array {
        [$zenith, $north, $east] = self::frame($lst, $latitude);

        $cusps = [];
        $direction = $underThePole ? -1 : 1;

        /* The branch of the first one is chosen against the midheaven, and not blindly.

           A plane cuts the ecliptic at two opposite points and `atan2` returns one of the two
           with no criterion. For the other five the order does it (each one goes ahead of the
           previous), but the first has no previous. With no reference the whole chart came out
           turned half a turn: the houses in the right order, the symmetry perfect, and the
           ascendant in house seven. */
        $previous = self::normalise($midheaven - $direction * 30);

        for ($i = 0; $i < 6; $i++) {
            $normal = match ($family) {
                // Through the point of the equator thirty degrees per turn from the meridian.
                'regiomontanus' => self::product($north, self::equatorPoint($lst + 30 * $i)),

                // Through the point of the prime vertical coming down from the zenith to the east.
                'campanus' => self::product($north, self::combine($east, $zenith, deg2rad(90 - 30 * $i))),

                // Like Campanus, but where the declination of the prime vertical is worth one third
                // or two thirds of that of the zenith: see `savardAltitude`.
                'savard' => self::product($north, self::combine($east, $zenith, self::savardAltitude(90 - 30 * $i, $latitude))),

                // Hour circles: they pass through the celestial poles, not through the horizon.
                'meridian' => self::product([0.0, 0.0, 1.0], self::equatorPoint($lst + 30 * $i)),

                default => throw new RuntimeException("Unknown circle family: {$family}"),
            };

            $cusps[] = $previous = self::cutWithEcliptic($normal, $eps, $previous, $direction);
        }

        return $cusps;
    }

    /**
     * Where a great circle given by its normal cuts the ecliptic.
     *
     * A plane cuts the ecliptic at TWO opposite points, so one has to be chosen. The rule is the
     * order: the cusps grow from the midheaven and none of them strays more than half a turn from
     * the previous one. Choosing wrong leaves the houses turned round, which on a middle latitude
     * chart cannot be seen at a glance.
     *
     * @param array{0: float, 1: float, 2: float} $normal
     * @param float $eps
     * @param float|null $previous
     * @param int $direction 1 when the cusps grow in longitude, -1 when they shrink (the clockwise
     *                     wheel of the polar convention).
     * @return float
     */
    private static function cutWithEcliptic(array $normal, float $eps, ?float $previous, int $direction = 1): float
    {
        $eclipticNormal = [0.0, -sin($eps), cos($eps)];

        /* The AXIS of the cut, which is a vector. It gets a name of its own and not «direction»
           because the parameter next to it is the direction of the chain, a ±1: calling the two
           things the same left the parameter overwritten by the vector, and `$direction > 0` went
           on to compare an array against an integer, which in PHP always comes out greater. The
           polar cusps of Regiomontanus came out half a turn off and no middle latitude test saw
           it. */
        $axis = self::product($eclipticNormal, $normal);

        $candidate = self::eclipticLongitudeOf($axis, $eps);

        if ($previous === null) {
            return $candidate;
        }

        $advance = $direction > 0
            ? self::normalise($candidate - $previous)
            : self::normalise($previous - $candidate);

        return $advance < 180 ? $candidate : self::normalise($candidate + 180);
    }

    /**
     * Where a great circle cuts the ecliptic, keeping the cut that falls on the side of `$side`.
     *
     * It is the other way of choosing between the two opposite cuts: not by order, but by
     * definition. If the circle passes through the north and south points of the horizon and
     * through a point P, the cusp is the cut lying in the same semicircle as P, and that
     * semicircle is that of the points whose product with the component of P perpendicular to the
     * axis is positive. It serves the systems whose opposite houses are not opposite, where the
     * order does not chain.
     *
     * @param array{0: float, 1: float, 2: float} $normal
     * @param float $eps
     * @param array{0: float, 1: float, 2: float} $side A vector of the plane pointing at the right semicircle.
     * @return float
     */
    private static function cutOnTheSide(array $normal, float $eps, array $side): float
    {
        $eclipticNormal = [0.0, -sin($eps), cos($eps)];
        $axis = self::product($eclipticNormal, $normal);

        if (self::scale($axis, $side) < 0) {
            $axis = [-$axis[0], -$axis[1], -$axis[2]];
        }

        return self::eclipticLongitudeOf($axis, $eps);
    }

    /**
     * Diurnal semiarc of the point of the ecliptic at that right ascension: how many degrees of
     * rotation it has left from the meridian to the horizon.
     *
     * @param float $alpha
     * @param float $latitude
     * @param float $eps
     * @return float
     */
    private static function diurnalSemiArc(float $alpha, float $latitude, float $eps): float
    {
        $longitude = deg2rad(self::eclipticInRightAscension($alpha, $eps));
        $declination = asin(sin($eps) * sin($longitude));

        $cosine = -tan(deg2rad($latitude)) * tan($declination);

        // Outside the range there is circumpolarity: that degree neither rises nor sets, and its
        // semiarc does not exist. It is clamped to the edge so the iteration does not blow up; what
        // decides whether the system holds at that latitude is the check before this one.
        return rad2deg(acos(max(-1.0, min(1.0, $cosine))));
    }

    /**
     * The point of the ecliptic that has that right ascension.
     *
     * @param float $alpha
     * @param float $eps
     * @return float
     */
    private static function eclipticInRightAscension(float $alpha, float $eps): float
    {
        $a = deg2rad(self::normalise($alpha));

        return self::normalise(rad2deg(atan2(sin($a), cos($a) * cos($eps))));
    }

    /*
     |--------------------------------------------------------------------------
     | The position of a body with latitude, system by system
     |--------------------------------------------------------------------------
     |
     | They all return degrees of house in [0, 360): 0 is cusp 1, 30 is cusp 2, 270 is cusp 10.
     | `housePosition` turns them into [1, 13).
     */

    /**
     * Porphyry: linear within each quadrant, which is what the system shares out.
     *
     * @param float $longitude
     * @return float
     */
    private function porphyryDegrees(float $longitude): float
    {
        $quarter = self::normalise($this->ascendant - $this->midheaven);
        $first = 180 - $quarter;

        $from = self::normalise($longitude - $this->ascendant);
        $base = 0.0;

        if ($from >= 180) {
            $base = 180.0;
            $from -= 180;
        }

        return $base + ($from < $first
            ? $from * 90 / $first
            : 90 + ($from - $first) * 90 / $quarter);
    }

    /**
     * The proportion covered between the cusp of the house and the next one. It is what Swiss
     * calls the «simplified» position, and it uses it for both Pullens, which have no geometry
     * other than their cusps.
     *
     * @param float $longitude
     * @return float
     */
    private function degreesBetweenCusps(float $longitude): float
    {
        $house = $this->houseOf($longitude);
        $from = $this->cusps[$house];
        $width = self::normalise($this->cusps[$house === 12 ? 1 : $house + 1] - $from);

        $fraction = $width < 1e-12 ? 0.0 : self::normalise($longitude - $from) / $width;

        return 30 * ($house - 1 + $fraction);
    }

    /**
     * The systems that place the body with its real equatorial coordinates.
     *
     * @param float $longitude
     * @param float $latitude
     * @param float $eps Radians.
     * @return float
     */
    private function degreesWithLatitude(float $longitude, float $latitude, float $eps): float
    {
        $lst = $this->siderealTime;
        $geographicLatitude = $this->geographicLatitude;
        [$ra, $dec, $body] = self::equatorial($longitude, $latitude, $eps);

        return match ($this->system) {
            HouseSystem::Placidus => self::placidusDegrees($ra, $dec, $lst, $geographicLatitude),
            HouseSystem::Koch => self::kochDegrees($ra, $dec, $lst, $geographicLatitude, $eps),
            HouseSystem::Alcabitius => self::alcabitiusDegrees($ra, $lst, $geographicLatitude, $this->ascendant, $eps),
            HouseSystem::Topocentric => self::topocentricDegrees($ra, $dec, $lst, $geographicLatitude),
            HouseSystem::Meridian => self::normalise($ra - $lst - 90),
            HouseSystem::Carter => self::normalise($ra - self::rightAscensionOf($this->ascendant, $eps)),
            HouseSystem::Regiomontanus => self::regiomontanusDegrees($body, $lst, $geographicLatitude),
            HouseSystem::Campanus => self::campanusDegrees($body, $lst, $geographicLatitude),
            HouseSystem::SavardA => self::savardDegrees(self::campanusDegrees($body, $lst, $geographicLatitude), $geographicLatitude),
            HouseSystem::Azimuthal => self::azimuthalDegrees($body, $lst, $geographicLatitude, $this->midheaven, $eps),
            HouseSystem::Krusinski => self::krusinskiDegrees($ra, $lst, $geographicLatitude, $this->ascendant, $eps),
            HouseSystem::Apc => self::degreesOverParallel($body, $lst, $geographicLatitude, rad2deg(asin(sin($eps) * sin(deg2rad($this->ascendant))))),
            HouseSystem::Sunshine => self::degreesOverParallel($body, $lst, $geographicLatitude, $this->sunDeclination ?? 0.0),
            default => throw new RuntimeException("No house position for the {$this->system->value} system"),
        };
    }

    /**
     * Placidus: the fraction of the semiarc (diurnal if it is up, nocturnal if down) the body has
     * covered, with Otto Ludwig's patch for the circumpolar ones.
     *
     * @param float $ra Degrees.
     * @param float $dec Degrees.
     * @param float $lst
     * @param float $latitude Geographic, degrees.
     * @return float
     */
    private static function placidusDegrees(float $ra, float $dec, float $lst, float $latitude): float
    {
        $mdd = self::meridianDistance($ra, $lst);
        $mdn = self::meridianDistance($ra + 180, $lst);

        // Circumpolar: it neither rises nor sets, and its «arc» starts at the culmination
        // opposite. The one that never rises goes between 1 and 7 by its distance to the imum
        // coeli; the one that never sets between 7 and 1 by its distance to the midheaven.
        if (90 - abs($dec) <= abs($latitude)) {
            return $dec * $latitude < 0
                ? self::normalise(90 + $mdn / 2)
                : self::normalise(270 + $mdd / 2);
        }

        $sine = tan(deg2rad($dec)) * tan(deg2rad($latitude));
        $ascensionalDifference = rad2deg(asin($sine));
        $top = $sine + cos(deg2rad($mdd)) >= 0;

        return $top
            ? self::normalise(($mdd / (90 + $ascensionalDifference) + 3) * 90)
            : self::normalise(($mdn / (90 - $ascensionalDifference) + 1) * 90);
    }

    /**
     * Koch: how long ago the body rose (east of the meridian) or how long until it sets (west), in
     * units of the semiarc of the midheaven. See the convention in `housePosition`.
     *
     * @param float $ra
     * @param float $dec
     * @param float $lst
     * @param float $latitude
     * @param float $eps Radians.
     * @return float
     */
    private static function kochDegrees(float $ra, float $dec, float $lst, float $latitude, float $eps): float
    {
        $mdd = self::meridianDistance($ra, $lst);

        // The body's ascensional difference, clamped to ±90 if it is circumpolar.
        $bodyAscensionalDifference = rad2deg(asin(max(-1.0, min(1.0, tan(deg2rad($latitude)) * tan(deg2rad($dec))))));

        // And that of the midheaven, which is the unit of measure.
        $mcAscensionalDifference = rad2deg(asin(max(-1.0, min(1.0, tan($eps) * tan(deg2rad($latitude)) * sin(deg2rad($lst))))));
        $semiArc = 90 + $mcAscensionalDifference;

        if ($semiArc <= 0) {
            throw new RuntimeException('Koch places nothing here: the midheaven never rises.');
        }

        if ($mdd >= 0) {
            $fraction = ($mdd - $bodyAscensionalDifference + $mcAscensionalDifference) / $semiArc;
            $degrees = ($fraction - 1) * 90;
        } else {
            $fraction = ($mdd + 180 + $bodyAscensionalDifference + $mcAscensionalDifference) / $semiArc;
            $degrees = ($fraction + 1) * 90;
        }

        // With tolerance: the midheaven itself gives a fraction of zero, and its two ascensional
        // differences (that of the body and that of the midheaven) come from different formulas that
        // carry a rounding. Without it, cusp 10 came out flagged as circumpolar.
        if ($fraction < -1e-9 || $fraction > 2 + 1e-9) {
            throw new RuntimeException('Koch does not place this body: it is circumpolar and its fraction falls outside the semiarc of the midheaven.');
        }

        return self::normalise($degrees);
    }

    /**
     * Alcabitius: the equator shared out by the semiarcs of the ascendant, and the body placed by
     * its right ascension.
     *
     * @param float $ra
     * @param float $lst
     * @param float $latitude
     * @param float $ascendant
     * @param float $eps Radians.
     * @return float
     */
    private static function alcabitiusDegrees(float $ra, float $lst, float $latitude, float $ascendant, float $eps): float
    {
        $mdd = self::meridianDistance($ra, $lst);

        $declination = asin(sin($eps) * sin(deg2rad($ascendant)));
        $cosine = max(-1.0, min(1.0, -tan(deg2rad($latitude)) * tan($declination)));
        $diurnal = rad2deg(acos($cosine));
        $nocturnal = 180 - $diurnal;

        if ($mdd > 0) {
            $degrees = $mdd < $diurnal
                ? $mdd * 90 / $diurnal
                : 90 + ($mdd - $diurnal) * 90 / $nocturnal;
        } else {
            $degrees = $mdd > -$nocturnal
                ? 360 + $mdd * 90 / $nocturnal
                : 270 + ($mdd + $nocturnal) * 90 / $diurnal;
        }

        return self::normalise($degrees - 90);
    }

    /**
     * Topocentric: the circles of the system have pole `atan(tan φ · x/90)` and cut the equator at
     * `x` degrees from the meridian, with x from 0 (meridian) to 90 (horizon). The x of the circle
     * through the body is found by bisection, after reflecting the body into the upper eastern
     * quadrant, which is where the system is defined. It is what Swiss does.
     *
     * @param float $ra
     * @param float $dec
     * @param float $lst
     * @param float $latitude
     * @return float
     */
    private static function topocentricDegrees(float $ra, float $dec, float $lst, float $latitude): float
    {
        $latitude = max(-89.999, min(89.999, $latitude));
        $dec = max(-90 + 1e-9, min(90 - 1e-9, $dec));
        $mdd = self::normalise($ra - $lst);

        $top = max(-1.0, min(1.0, tan(deg2rad($dec)) * tan(deg2rad($latitude)))) + cos(deg2rad($mdd)) >= 0;

        if (! $top) {
            $ra = self::normalise($ra + 180);
            $dec = -$dec;
            $mdd = self::normalise($mdd + 180);
        }

        $west = $mdd > 180;

        if ($west) {
            $ra = self::normalise($lst - $mdd);
        }

        $body = self::equatorialVectorFrom($ra, $dec);
        $tangent = tan(deg2rad($latitude));

        // The circle of parameter x: it cuts the equator at lst + x and its pole sits 90 degrees
        // earlier, at whatever altitude it gets. The body is on it when its product with that
        // pole is zero; above (positive) x has to come down, below it has to go up.
        $overThePole = function (float $x) use ($body, $lst, $tangent): float {
            $pole = self::equatorialVectorFrom($lst + $x - 90, rad2deg(atan($tangent * $x / 90)));

            return self::scale($body, $pole);
        };

        $below = 0.0;
        $height = 90.0;

        for ($turn = 0; $turn < 60; $turn++) {
            $mid = ($below + $height) / 2;

            if ($overThePole($mid) > 0) {
                $height = $mid;
            } else {
                $below = $mid;
            }
        }

        $x = ($below + $height) / 2;

        if ($west) {
            $x = -$x;
        }

        if (! $top) {
            $x += 180;
        }

        return self::normalise($x - 90);
    }

    /**
     * Regiomontanus: the position circle of the body (through north and south) cuts the equator at
     * a right ascension, and that ascension measured from the east point is the position.
     *
     * @param array{0: float, 1: float, 2: float} $body
     * @param float $lst
     * @param float $latitude
     * @return float
     */
    private static function regiomontanusDegrees(array $body, float $lst, float $latitude): float
    {
        [, $north] = self::frame($lst, $latitude);

        $cut = self::orient(
            self::product(self::product($north, $body), [0.0, 0.0, 1.0]),
            self::perpendicularTo($body, $north)
        );

        return self::normalise(self::rightAscensionOfVector($cut) - $lst - 90);
    }

    /**
     * Campanus: the same position circle, cut with the prime vertical, and the arc measured from
     * the east towards the nadir (0 east, 90 nadir, 180 west, 270 zenith).
     *
     * @param array{0: float, 1: float, 2: float} $body
     * @param float $lst
     * @param float $latitude
     * @return float
     */
    private static function campanusDegrees(array $body, float $lst, float $latitude): float
    {
        [$zenith, $north, $east] = self::frame($lst, $latitude);

        $cut = self::orient(
            self::product(self::product($north, $body), $north),
            self::perpendicularTo($body, $north)
        );

        return self::normalise(rad2deg(atan2(-self::scale($cut, $zenith), self::scale($cut, $east))));
    }

    /**
     * The altitude over the prime vertical of a Savard-A cut, in radians.
     *
     * John Savard describes it on quadibloc.com («Astrological House Systems») as the houses of
     * Albategnius: the parallels of declination cut the prime vertical by dividing into thirds the
     * difference in declination between the east and west points, which are on the equator, and
     * the zenith, which has the declination of the latitude; and circles through the north and
     * south points of the horizon carry those cuts to the ecliptic. Albategnius never wrote it
     * down, and that is why Swiss names it after Savard.
     *
     * A point of the prime vertical at altitude h has declination `asin(sin φ · sin h)`, so the
     * one with k thirds of the zenith's sits at `sin h = sin(kφ/3) / sin φ`. Measured against
     * Swiss, at zero in Madrid, at the equator, in Oslo, in Ushuaia and in Tromsø; the other
     * possible reading, dividing into thirds the SINE of the declination, goes degrees off.
     *
     * **At the equator the computation is zero over zero**: the zenith is on the equator and the
     * whole prime vertical has zero declination. Its limit is `sin h = k/3`, and that is what
     * Swiss gives there, with the cusps at 48.19 and 70.53 degrees from the meridian in right
     * ascension, which are the arccosine of two thirds and that of one third.
     *
     * @param float $campanus The altitude it would have in Campanus, in degrees: from 90 to -60 in steps of thirty.
     * @param float $latitude
     * @return float
     */
    private static function savardAltitude(float $campanus, float $latitude): float
    {
        $thirds = abs($campanus) / 30;
        $phi = deg2rad($latitude);
        $sine = abs(sin($phi)) < 1e-12 ? $thirds / 3 : sin($thirds * $phi / 3) / sin($phi);

        return ($campanus < 0 ? -1 : 1) * asin(min(1.0, $sine));
    }

    /**
     * Savard-A: the Campanus arc over the prime vertical, shared out between the Savard cuts
     * instead of every thirty degrees.
     *
     * Within each house the position advances in proportion to that arc, which is what
     * `swe_house_pos` does with the `j`. Measured: the fraction Swiss gives is linear in the arc of
     * the prime vertical between the two cusp circles, and not in declination nor in longitude.
     *
     * @param float $campanus Degrees from the east towards the nadir, as `campanusDegrees` gives them.
     * @param float $latitude
     * @return float Thirty per house.
     */
    private static function savardDegrees(float $campanus, float $latitude): float
    {
        $h1 = rad2deg(self::savardAltitude(30, $latitude));
        $h2 = rad2deg(self::savardAltitude(60, $latitude));

        $cuts = [0.0, $h1, $h2, 90.0, 180 - $h2, 180 - $h1, 180.0, 180 + $h1, 180 + $h2, 270.0, 360 - $h2, 360 - $h1, 360.0];

        $house = 0;

        while ($house < 11 && $campanus >= $cuts[$house + 1]) {
            $house++;
        }

        return 30 * ($house + ($campanus - $cuts[$house]) / ($cuts[$house + 1] - $cuts[$house]));
    }

    /**
     * Azimuthal: only the azimuth of the body counts, measured from that of the midheaven
     * towards the east.
     *
     * @param array{0: float, 1: float, 2: float} $body
     * @param float $lst
     * @param float $latitude
     * @param float $midheaven
     * @param float $eps
     * @return float
     */
    private static function azimuthalDegrees(array $body, float $lst, float $latitude, float $midheaven, float $eps): float
    {
        [, $north, $east] = self::frame($lst, $latitude);
        [$mcAzimuth, $direction] = self::azimuthalOrientation($midheaven, $eps, $north);

        $azimuth = rad2deg(atan2(self::scale($body, $east), self::scale($body, $north)));

        return self::normalise(270 + $direction * ($azimuth - $mcAzimuth));
    }

    /**
     * Krusinski: the body is carried by its hour circle to the circle through the ascendant and
     * the zenith, and the arc is measured there from the ascendant downwards.
     *
     * @param float $ra
     * @param float $lst
     * @param float $latitude
     * @param float $ascendant
     * @param float $eps
     * @return float
     */
    private static function krusinskiDegrees(float $ra, float $lst, float $latitude, float $ascendant, float $eps): float
    {
        [$u, $v] = self::krusinskiCircle($lst, $latitude, $eps, $ascendant);
        $normal = self::product($u, $v);

        // The hour circle of the body is the plane through the poles and its right ascension; its
        // cut with the circle of the system is solved in declination. Of the two
        // opposite ones the one taken is that of the half plane of that right ascension, the one with
        // positivo.
        $a = deg2rad($ra);
        $declination = atan2(-($normal[0] * cos($a) + $normal[1] * sin($a)), $normal[2]);

        if (cos($declination) < 0) {
            $declination += M_PI;
        }

        $point = [cos($declination) * cos($a), cos($declination) * sin($a), sin($declination)];
        $arc = rad2deg(atan2(self::scale($point, $v), self::scale($point, $u)));

        return self::normalise(-$arc);
    }

    /**
     * APC and Sunshine: the position circle of the body cuts the parallel (of the ascendant or of
     * the Sun) at a point, and how much of its semiarc that point has covered is the position. A
     * body above the horizon falls in the diurnal arc of the parallel and one below it in the
     * nocturnal one, because the semicircle from north to south passing through the body lies
     * entirely on one side of the horizon.
     *
     * @param array{0: float, 1: float, 2: float} $body
     * @param float $lst
     * @param float $latitude
     * @param float $declination That of the parallel, in degrees.
     * @return float
     */
    private static function degreesOverParallel(array $body, float $lst, float $latitude, float $declination): float
    {
        [$zenith, $north] = self::frame($lst, $latitude);
        [$diurnal, $nocturnal] = self::semiArcs($declination, $latitude);
        $top = self::scale($body, $zenith) >= 0;

        // The Swiss conventions for a circumpolar Sun: with no diurnal arc everything above
        // is house 10, with no nocturnal arc everything below is house 4.
        if ($top && $diurnal <= 0) {
            return 270.0;
        }

        if (! $top && $nocturnal <= 0) {
            return 90.0;
        }

        $normal = self::product($north, $body);
        $side = self::perpendicularTo($body, $north);

        // The points of the parallel are (cos δ cos α, cos δ sin α, sin δ); asking that they be on
        // the plane leaves A cos α + B sin α = C, which has two opposite solutions.
        $d = deg2rad($declination);
        $a = $normal[0] * cos($d);
        $b = $normal[1] * cos($d);
        $c = -$normal[2] * sin($d);
        $radius = hypot($a, $b);
        $centre = atan2($b, $a);
        $aperture = acos(max(-1.0, min(1.0, $radius < 1e-15 ? 0.0 : $c / $radius)));

        $alpha = null;

        foreach ([$centre + $aperture, $centre - $aperture] as $candidate) {
            $point = [cos($d) * cos($candidate), cos($d) * sin($candidate), sin($d)];

            if (self::scale($point, $side) >= 0) {
                $alpha = rad2deg($candidate);
                break;
            }
        }

        $alpha ??= rad2deg($centre + $aperture);

        // Hour angle of the cut, positive towards the west.
        $clockwise = self::meridianDistance($lst, $alpha);

        if ($top) {
            return self::normalise(270 - 90 * $clockwise / $diurnal);
        }

        $fromTheBottom = self::meridianDistance($lst, $alpha + 180);

        return self::normalise(90 - 90 * $fromTheBottom / $nocturnal);
    }

    /*
     |--------------------------------------------------------------------------
     | Vectores
     |--------------------------------------------------------------------------
     */

    /**
     * The latitude and the obliquity (in radians) these houses were computed with.
     *
     * @return array{0: float, 1: float}
     */
    private function geometry(): array
    {
        if ($this->geographicLatitude === null || $this->obliquity === null) {
            throw new RuntimeException('These houses were built with no latitude and no obliquity. Use Houses::calculate.');
        }

        return [$this->geographicLatitude, deg2rad($this->obliquity)];
    }

    /**
     * The zenith, the north point of the horizon and the east point, in equatorial coordinates.
     *
     * @param float $lst
     * @param float $latitude
     * @return array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}, 2: array{0: float, 1: float, 2: float}}
     */
    private static function frame(float $lst, float $latitude): array
    {
        $t = deg2rad($lst);
        $phi = deg2rad($latitude);

        $zenith = [cos($phi) * cos($t), cos($phi) * sin($t), sin($phi)];
        $north = [-sin($phi) * cos($t), -sin($phi) * sin($t), cos($phi)];

        return [$zenith, $north, self::product($north, $zenith)];
    }

    /**
     * Right ascension and declination of a point given in ecliptic coordinates, and its vector.
     *
     * @param float $longitude Degrees.
     * @param float $latitude Degrees.
     * @param float $eps Radians.
     * @return array{0: float, 1: float, 2: array{0: float, 1: float, 2: float}}
     */
    private static function equatorial(float $longitude, float $latitude, float $eps): array
    {
        $vector = self::equatorialVector($longitude, $latitude, $eps);

        return [
            self::rightAscensionOfVector($vector),
            rad2deg(asin(max(-1.0, min(1.0, $vector[2])))),
            $vector,
        ];
    }

    /**
     * The equatorial unit vector of a point given in ecliptic coordinates: a rotation by the
     * obliquity about the x axis, which is the equinox.
     *
     * @param float $longitude Degrees.
     * @param float $latitude Degrees.
     * @param float $eps Radians.
     * @return array{0: float, 1: float, 2: float}
     */
    private static function equatorialVector(float $longitude, float $latitude, float $eps): array
    {
        $l = deg2rad($longitude);
        $b = deg2rad($latitude);

        $x = cos($b) * cos($l);
        $y = cos($b) * sin($l);
        $z = sin($b);

        return [$x, $y * cos($eps) - $z * sin($eps), $y * sin($eps) + $z * cos($eps)];
    }

    /**
     * The unit vector of a right ascension and a declination, in degrees.
     *
     * @param float $ascension
     * @param float $declination
     * @return array{0: float, 1: float, 2: float}
     */
    private static function equatorialVectorFrom(float $ascension, float $declination): array
    {
        $a = deg2rad($ascension);
        $d = deg2rad($declination);

        return [cos($d) * cos($a), cos($d) * sin($a), sin($d)];
    }

    /**
     * The ecliptic longitude of an equatorial vector.
     *
     * @param array{0: float, 1: float, 2: float} $v
     * @param float $eps
     * @return float
     */
    private static function eclipticLongitudeOf(array $v, float $eps): float
    {
        // From equatorial to ecliptic: a rotation by the obliquity about the x axis.
        $y = $v[1] * cos($eps) + $v[2] * sin($eps);

        return self::normalise(rad2deg(atan2($y, $v[0])));
    }

    /**
     * @param array{0: float, 1: float, 2: float} $v
     * @return float
     */
    private static function rightAscensionOfVector(array $v): float
    {
        return self::normalise(rad2deg(atan2($v[1], $v[0])));
    }

    /**
     * Distance to the meridian in (-180, 180]: positive to the east (it has not culminated yet).
     *
     * @param float $ascension
     * @param float $lst
     * @return float
     */
    private static function meridianDistance(float $ascension, float $lst): float
    {
        $distance = self::normalise($ascension - $lst);

        return $distance >= 180 ? $distance - 360 : $distance;
    }

    /**
     * The vector, or its opposite, whichever points towards `$side`.
     *
     * @param array{0: float, 1: float, 2: float} $v
     * @param array{0: float, 1: float, 2: float} $side
     * @return array{0: float, 1: float, 2: float}
     */
    private static function orient(array $v, array $side): array
    {
        return self::scale($v, $side) < 0 ? [-$v[0], -$v[1], -$v[2]] : $v;
    }

    /**
     * The component of `$v` perpendicular to the unit axis `$axis`.
     *
     * @param array{0: float, 1: float, 2: float} $v
     * @param array{0: float, 1: float, 2: float} $axis
     * @return array{0: float, 1: float, 2: float}
     */
    private static function perpendicularTo(array $v, array $axis): array
    {
        $projection = self::scale($v, $axis);

        return [$v[0] - $projection * $axis[0], $v[1] - $projection * $axis[1], $v[2] - $projection * $axis[2]];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $v
     * @return array{0: float, 1: float, 2: float}
     */
    private static function unit(array $v): array
    {
        $modulus = sqrt(self::scale($v, $v));

        return [$v[0] / $modulus, $v[1] / $modulus, $v[2] / $modulus];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return float
     */
    private static function scale(array $a, array $b): float
    {
        return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
    }

    /**
     * @param float $alpha
     * @return array{0: float, 1: float, 2: float}
     */
    private static function equatorPoint(float $alpha): array
    {
        $a = deg2rad($alpha);

        return [cos($a), sin($a), 0.0];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @param float $angle
     * @return array{0: float, 1: float, 2: float}
     */
    private static function combine(array $a, array $b, float $angle): array
    {
        return [
            cos($angle) * $a[0] + sin($angle) * $b[0],
            cos($angle) * $a[1] + sin($angle) * $b[1],
            cos($angle) * $a[2] + sin($angle) * $b[2],
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return array{0: float, 1: float, 2: float}
     */
    private static function product(array $a, array $b): array
    {
        return [
            $a[1] * $b[2] - $a[2] * $b[1],
            $a[2] * $b[0] - $a[0] * $b[2],
            $a[0] * $b[1] - $a[1] * $b[0],
        ];
    }

    /**
     * @param float $degrees
     * @return float
     */
    private static function normalise(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }
}
