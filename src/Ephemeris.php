<?php

namespace Astronomy;

use LogicException;

/**
 * Where each body is seen from the Earth at a given instant.
 *
 * From heliocentric to what gets drawn on a chart there are four steps, and not one of them is
 * optional if you want a real apparent position:
 *
 * 1. Light time. We do not see where Jupiter is: we see where it was when the light that
 * reaches us left, up to fifty minutes earlier. It is solved by iterating, because the
 * distance depends on the position and the position on the delay.
 * 2. FK5 frame. VSOP87 works in its own dynamical frame and it has to be rotated into the
 * one modern ephemerides use. It is nine hundredths of an arcsecond. Only for what comes
 * from VSOP87: the JPL tables are already in ICRF and the ELP Moon in its own fit, and for
 * those the correction takes them out of where they already were right.
 * 3. Aberration. The Earth runs at thirty kilometres per second and that tilts the
 * direction of the light it receives, the way rain tilts if you walk. It is twenty
 * arcseconds: it shows.
 * 4. Nutation. The reference frame itself nods. Another seventeen arcseconds.
 *
 * Without steps 3 and 4 the chart comes out displaced by almost forty arcseconds, which is a
 * hundredth of a degree. It does not change a sign, but it does change an exact degree, and
 * there are people who read exact degrees.
 *
 * That is `position()`, the geocentric one, which is the chart's. Beside it go the other three
 * frames Swiss gives for any body: `heliocentric()` (from the Sun),
 * `barycentric()` (from the centre of mass of the solar system, where the Sun moves
 * too) and `topocentric()` (from a point on the surface, with parallax). All three
 * follow the Swiss criterion in order to match it, and that criterion is read in its
 * source and checked by measuring, not assumed: each method says which one it is.
 *
 * And the five frame methods accept the same two options, which are the Swiss flags that
 * change WHICH position is asked for without changing where it is looked at from: `PositionType`
 * (apparent, astrometric, geometric, without aberration or without deflection) and
 * `ReferenceEcliptic` (true of date, mean of date or J2000). The rectangular ones are
 * given by `Position` itself. With both options at their defaults the path is the usual one, bit
 * for bit: the apparent of date does not go through the general chain with everything turned on,
 * the same function as before is called.
 *
 * Besides the `Body` cases, the five frames accept a `DownloadableBody`: an asteroid, a satellite
 * or a comet downloaded from the JPL with `Downloader`. They come in through `geometricHeliocentric`
 * and from there follow the same chain as any planet. If their file is missing, they throw `MissingData`.
 */
class Ephemeris
{
    /**
     * Twice the Schwarzschild radius of the Sun divided by the astronomical unit, with no
     * units. It is the constant of the gravitational deflection of light.
     */
    private const SOLAR_DEFLECTION = 1.974e-8;

    /** Days light takes to travel one astronomical unit. */
    private const LIGHT_PER_AU = 0.005775518331;

    /** Speed of light in astronomical units per day. */
    private const C = 173.144632674;

    /** And in kilometres per second, for the diurnal aberration, which goes in km/s. */
    private const C_KM_S = 299792.458;

    /** Step for deriving the speed, in days. */
    private const STEP = 0.25;

    /**
     * Step for deriving the TOPOCENTRIC speed, in days: five minutes.
     *
     * Parallax oscillates with the rotation of the Earth, so the topocentric longitude of the
     * Moon carries on top of it a wave of almost one degree with a period of one day. A centred
     * difference at six hours of that wave gives 64% of its slope and the speed came out two
     * degrees per day off. With five minutes, the error of the difference is one thousandth.
     */
    private const DIURNAL_STEP = 1 / 288;

    /**
     * The position of a body, with its speed.
     *
     * With no further arguments it is the chart's: apparent and in the true ecliptic of date.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT Julian day in Terrestrial Time.
     * @param PositionType $type Which corrections of the light it carries.
     * @param ReferenceEcliptic $ecliptic In which ecliptic and from which equinox it is measured.
     * @return Position
     */
    public static function position(
        Body|DownloadableBody $body,
        float $jdTT,
        PositionType $type = PositionType::Apparent,
        ReferenceEcliptic $ecliptic = ReferenceEcliptic::TrueOfDate,
    ): Position {
        // The Earth is what you look from. Its geocentric position is not zero: it does not
        // exist, and a zero here would be 0° of Aries looking perfectly fine.
        if ($body === Body::Earth) {
            throw new LogicException('The Earth has no geocentric position: it is the observer. Ask for it heliocentric or barycentric.');
        }

        /* The nodes and Lilith step out of the whole path, and rightly so: they are not bodies
           that can be seen, they are geometric constructions on the orbit of the Moon. Light
           time and aberration correct where something IS SEEN with respect to where it is, and
           a node is not seen. Applying them to it would be correcting the position of an
           imaginary point for the delay of a light it does not emit. */
        if ($body instanceof Body && $body->isLunarPoint()) {
            return self::lunarPoint($body, $jdTT, $ecliptic);
        }

        return self::build(
            $body,
            fn (float $jd): array => self::inEcliptic(self::geocentricEcliptic($body, $jd, $type), $jd, $ecliptic),
            $jdTT,
            self::STEP
        );
    }

    /**
     * The position seen from the Sun, with its speed.
     *
     * It is the `SEFLG_HELCTR` of Swiss, and its criterion is followed in order to match. It is
     * read in its source and checked by measuring, because all three things can be done another
     * way and none of them warns:
     *
     * - Light time from the Sun. The planet where it was when the light that reaches the
     * origin left (`dx = xx`, without subtracting the observer, in `app_pos_etc_plan`). Without
     * it, Mars comes out 18 arcseconds off with respect to Swiss.
     * - Without aberration or deflection. The origin does not move (`plaus_iflag` turns on
     * `SEFLG_NOABERR` and `SEFLG_NOGDEFL` with the heliocentric bit).
     * - With nutation. It is not of the body but of the reference frame, and the true
     * equinox of date is the same one looked at from wherever. Without it, 14 arcseconds.
     *
     * The series are already heliocentric (VSOP87 and the JPL tables), so this is exposing
     * what was already there. The Moon is the Earth plus the geocentric Moon, and the Earth is
     * its own VSOP87 series: in this frame it takes the place the Sun has in the geocentric one.
     *
     * The Sun has no heliocentric position: it is the origin. Swiss returns zeros, which is
     * a 0° of Aries looking perfectly fine; here it throws. Nor do the nodes and Lilith:
     * they are elements of the orbit of the Moon AROUND THE EARTH and from the Sun they
     * mean nothing.
     *
     * Of `PositionType` only the geometric one counts here, which removes light time: from the
     * origin there is no aberration or deflection to remove, so the other four give the same.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param PositionType $type
     * @param ReferenceEcliptic $ecliptic
     * @return Position
     */
    public static function heliocentric(
        Body|DownloadableBody $body,
        float $jdTT,
        PositionType $type = PositionType::Apparent,
        ReferenceEcliptic $ecliptic = ReferenceEcliptic::TrueOfDate,
    ): Position {
        if ($body === Body::Sun) {
            throw new LogicException('The Sun has no heliocentric position: it is the origin of that frame.');
        }

        self::requireBodyWithOrbit($body);

        return self::fromOrigin($body, $jdTT, false, $type, $ecliptic);
    }

    /**
     * The position seen from the barycentre of the solar system, with its speed. Here the Sun
     * does move: up to a hundredth of an AU, two solar radii, pulled by Jupiter and Saturn.
     *
     * It is the heliocentric one plus the barycentric Sun, and the barycentric Sun is the
     * definition of barycentre turned into arithmetic: the heliocentric positions the engine
     * already gives, weighted by the DE440 masses (`barycentricSun`, with the masses downloaded
     * by `astronomy masses`). Same criterion as the heliocentric one, with the origin at the
     * barycentre (`SEFLG_BARYCTR`): light time from there, without aberration and with nutation.
     *
     * Careful with the reference: Swiss with the Moshier ephemerides does not give barycentric
     * positions ("barycentric Moshier positions are not supported"), so this frame is verified
     * against the JPL Horizons vectors from `500@0`, not against Swiss.
     *
     * With `PositionType` the same happens as in the heliocentric one: only the geometric one changes anything.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param PositionType $type
     * @param ReferenceEcliptic $ecliptic
     * @return Position
     */
    public static function barycentric(
        Body|DownloadableBody $body,
        float $jdTT,
        PositionType $type = PositionType::Apparent,
        ReferenceEcliptic $ecliptic = ReferenceEcliptic::TrueOfDate,
    ): Position {
        self::requireBodyWithOrbit($body);

        return self::fromOrigin($body, $jdTT, true, $type, $ecliptic);
    }

    /**
     * The apparent position seen from a point on the surface instead of from the centre
     * of the Earth (`SEFLG_TOPOCTR`), with its speed.
     *
     * It is the apparent geocentric position minus the observer's vector, in equatorial
     * coordinates and in kilometres: the same subtraction `Horizon` does for risings and
     * eclipses, and it is done there. It moves the Moon up to one degree and Mars at opposition
     * some twenty arcseconds; Pluto, nothing that can be seen. It carries besides the DIURNAL
     * aberration, a third of an arcsecond at the equator: the same tilt of the light as the
     * annual one, by the speed of the rotation instead of by that of the orbit. Swiss carries
     * it, and without it the comparison drags that third along as if it were error.
     *
     * The two time scales, again: the ephemeris goes in TT and the rotation of the Earth,
     * which is what says where the observer is, in UT. The `$jdTT` that comes in is converted
     * to UT for that alone.
     *
     * The nodes and Lilith have no parallax: they are directions without distance, and from the
     * ground they are seen exactly the same as from the centre. Their geocentric position is
     * returned as it is, which is also what Swiss does.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param float $latitude Geographic, in degrees, north positive.
     * @param float $geographicLongitude Degrees, east positive.
     * @param float $heightMetres Above sea level.
     * @param PositionType $type The diurnal aberration goes with the annual one: they are removed together.
     * @param ReferenceEcliptic $ecliptic
     * @return Position
     */
    public static function topocentric(
        Body|DownloadableBody $body,
        float $jdTT,
        float $latitude,
        float $geographicLongitude,
        float $heightMetres = 0.0,
        PositionType $type = PositionType::Apparent,
        ReferenceEcliptic $ecliptic = ReferenceEcliptic::TrueOfDate,
    ): Position {
        if ($body === Body::Earth) {
            throw new LogicException('The Earth has no topocentric position: it is where you look from.');
        }

        if ($body instanceof Body && $body->isLunarPoint()) {
            return self::position($body, $jdTT, $type, $ecliptic);
        }

        return self::build(
            $body,
            fn (float $jd): array => self::inEcliptic(
                self::topocentricEcliptic($body, $jd, $latitude, $geographicLongitude, $heightMetres, $type),
                $jd,
                $ecliptic
            ),
            $jdTT,
            self::DIURNAL_STEP
        );
    }

    /**
     * The position of a body seen from ANOTHER body, with its speed. It is the
     * `swe_calc_pctr` of Swiss.
     *
     * Mars seen from Jupiter, the Earth seen from Mars. It sounds like a curiosity and it is the
     * same calculation as always with the observer moved elsewhere, so what is here is not new
     * astronomy: it is taking away from the chain the assumption that the observer is the
     * Earth**, which was buried inside the deflection and the aberration.
     *
     * Both corrections depend on where the one who looks is, and that is the whole point:
     *
     * - Light time is measured from the body to the new origin, not to the Earth. From
     * Jupiter to Mars there are four astronomical units of path at the worst moment and half
     * an hour of light.
     * - Aberration is the observer's. Jupiter goes at 13 kilometres per second where the
     * Earth goes at 30, so from there the light tilts less than half as much and towards
     * another place. Leaving the velocity of the Earth in place gives no error at all and
     * shifts the result by up to 29.8 arcseconds, measured over fifteen cases, where the
     * worst is the Earth seen from Mars in the year 2000. In none of the fifteen is it below 1.7.
     * - The deflection carries the three vertices inside it. What bends the light is passing
     * close to the Sun ON THE WAY TO THE OBSERVER, and from Mars the Sun is seen in another
     * direction and what is left almost behind it is something else. In those fifteen cases it
     * does not reach four thousandths of an arcsecond, because in none of them is anything
     * right up against the Sun; with Jupiter at 0.45 degrees from the Sun seen from Mars it is
     * 0.57 arcseconds, which is exactly what it is there for, just as in the geocentric case.
     *
     * Against JPL Horizons, fifteen combinations in 1700, 2000 and 2300: 0.0097 arcseconds
     * worst case in longitude, 0.0049 in latitude and 4.6e-7 astronomical units in distance.
     * That is ten times better than the engine's floor in geocentric, and it is not that this is
     * better done: it is that to compare you have to rotate our result to the ecliptic of J2000
     * (see below), and that rotation takes with it the difference in precession model, which is
     * exactly what forms the floor of 0.13 arcseconds. What this figure measures is the
     * planetocentric geometry: light time, aberration, deflection and where the observer is.
     *
     * And to compare it you have to know this, which does not warn: Horizons changes frame
     * according to the centre. Its `ObsEcLon` from the Earth goes in the TRUE ECLIPTIC OF DATE
     * (which is why `astro:verificar` matches to 0.18 arcseconds over eight hundred years), and
     * from any other body it goes in the J2000 one. Measured: in the year 2000 the difference
     * is the 13.93 arcseconds of nutation exactly, and in 1700 and in 2300 it is 4.19 degrees,
     * that is three hundred years of precession. Comparing them raw it looks like a huge bug and
     * it is a change of frame.
     *
     * Here the nutation is applied, which is what Swiss does and the same thing the heliocentric
     * and barycentric positions already do: it is not of the body nor of the observer, it moves
     * the whole reference frame, and the true equinox of date is the same one looked at from
     * wherever.
     *
     * And one that follows by itself: from another planet, the Moon stops being a special
     * case. In geocentric it carries no annual aberration because it orbits WITH us and we
     * share velocity; from Mars it shares nothing, so it comes in through everyone's path.
     *
     * The two centres that already had a path of their own still have it. From the Earth this
     * returns the usual geocentric position and from the Sun the heliocentric one, delegating to
     * them instead of recalculating it: two definitions of the same thing is a place where they
     * can diverge, and the geocentric one is what casts the charts.
     *
     * @param Body|DownloadableBody $body The one that is looked at.
     * @param Body $centre Where it is looked at from.
     * @param float $jdTT
     * @param PositionType $type The aberration that is removed is the observer's, not the Earth's.
     * @param ReferenceEcliptic $ecliptic
     * @return Position
     */
    public static function planetocentric(
        Body|DownloadableBody $body,
        Body $centre,
        float $jdTT,
        PositionType $type = PositionType::Apparent,
        ReferenceEcliptic $ecliptic = ReferenceEcliptic::TrueOfDate,
    ): Position {
        if ($body === $centre) {
            throw new LogicException(sprintf(
                '%s cannot be seen from itself: the observer has no position in its own frame.',
                $body->name()
            ));
        }

        if ($centre === Body::Earth) {
            return self::position($body, $jdTT, $type, $ecliptic);
        }

        if ($centre === Body::Sun) {
            return self::heliocentric($body, $jdTT, $type, $ecliptic);
        }

        self::requireBodyWithOrbit($centre);
        self::requireBodyWithOrbit($body);

        return self::build(
            $body,
            fn (float $jd): array => self::inEcliptic(self::planetocentricEcliptic($body, $centre, $jd, $type), $jd, $ecliptic),
            $jdTT,
            self::STEP
        );
    }

    /**
     * Apparent longitude, latitude and distance from another body. It is `apparentEcliptic` with
     * the observer moved, and it is written separately instead of being put in there with a
     * parameter because that one has two exceptions for the Moon that do not hold here.
     *
     * @param Body|DownloadableBody $body
     * @param Body $centre
     * @param float $jdTT
     * @param PositionType $type
     * @return array{0: float, 1: float, 2: float}
     */
    private static function planetocentricEcliptic(Body|DownloadableBody $body, Body $centre, float $jdTT, PositionType $type): array
    {
        $t = Time::centuries($jdTT);
        $observer = self::geometricHeliocentric($centre, $jdTT);

        /* The Sun is at the origin of the heliocentric frame, so seeing it from any body is
           looking towards where that body is not. Light time does not move it: by definition
           it does not depart from the origin. It is the same reasoning as in the geocentric case. */
        $vector = match (true) {
            $body === Body::Sun => [-$observer[0], -$observer[1], -$observer[2]],
            $type->lightTime() => self::withLightTime(
                fn (float $jd) => self::geometricHeliocentric($body, $jd),
                $observer,
                $jdTT
            ),
            default => self::subtract(self::geometricHeliocentric($body, $jdTT), $observer),
        };

        $distance = sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);

        // The order is not free, just as in the geocentric case: first the light arrives curved
        // by the Sun and then the movement of the observer tilts it.
        if ($body !== Body::Sun && $type->deflection()) {
            $vector = self::deflectionFrom($vector, $distance, $observer);
        }

        if ($type->aberration()) {
            $vector = self::aberrationFrom($vector, $distance, self::heliocentricVelocity($centre, $jdTT));
        }

        $longitude = rad2deg(atan2($vector[1], $vector[0]));
        $latitude = rad2deg(atan2($vector[2], sqrt($vector[0] ** 2 + $vector[1] ** 2)));

        if (self::needsFk5($body, $jdTT)) {
            [$longitude, $latitude] = self::fk5Correction($longitude, $latitude, $t);
        }

        // The nutation is not of the body nor of the observer: it moves the whole reference
        // frame, and the true equinox of date is the same one looked at from wherever.
        $longitude += rad2deg(Time::nutation($t)[0]);

        return [self::normalize($longitude), $latitude, $distance];
    }

    /**
     * The heliocentric velocity of a body, in astronomical units per day.
     *
     * It comes from centred differences over the same position everything else uses, with the
     * same step of 0.05 days the aberration of the Earth already used. What is differentiated
     * goes in the ecliptic of date, which rotates, so the velocity carries the rotation of the
     * frame inside it: it is fifty arcseconds per year over one astronomical unit, that is four
     * parts in a hundred million of the orbital velocity, which in the aberration are worth one
     * millionth of an arcsecond. The velocity of the Earth has been differentiated the same way
     * from the start.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    private static function heliocentricVelocity(Body|DownloadableBody $body, float $jdTT): array
    {
        $h = 0.05;

        $before = self::geometricHeliocentric($body, $jdTT - $h);
        $after = self::geometricHeliocentric($body, $jdTT + $h);

        $velocity = [];

        foreach ([0, 1, 2] as $axis) {
            $velocity[$axis] = ($after[$axis] - $before[$axis]) / (2 * $h);
        }

        return $velocity;
    }

    /**
     * A node or a Lilith.
     *
     * The latitude is zero by definition in the nodes (they are exactly where the orbit crosses
     * the ecliptic) and it is left at zero for Lilith too, which is how it is read on a chart: by
     * its longitude. The distance means nothing here and goes to zero. Taken to J2000 the
     * latitude stops being zero, because that is another plane: it is the ecliptic of date seen
     * from it.
     *
     * Here the nutation is added to them, and it went unadded. `LunarPoints` gives them in
     * the MEAN ecliptic of date, which is where the mean longitudes of ELP are written, and that
     * is how they reached the chart, while the planets arrive in the true one: they carried the
     * nutation in longitude, up to seventeen arcseconds, in every aspect of the node with a
     * planet. Swiss adds it to all six (measured: with and without `SEFLG_NONUT` they differ by
     * exactly the nutation in all six). Against Swiss on 200 dates from 1600 to 2400, the mean
     * node went down from 12.17 arcseconds median and 20.11 worst case to 0.94 and 2.20, and the
     * true one from 12.27 and 43.74 to 7.66 and 31.15. In the two Liliths and in the interpolated
     * apsides it does not show, because what they differ from Swiss by, by definition, is much
     * more than that.
     *
     * `LunarPoints` stays in the mean one on purpose: its checks against the definition measure
     * against `Moon::spherical`, which also goes in the mean one, and there adding the nutation
     * on one side alone would only be introducing a difference that does not exist.
     *
     * @param Body $body
     * @param float $jdTT
     * @param ReferenceEcliptic $ecliptic
     * @return Position
     */
    private static function lunarPoint(
        Body $body,
        float $jdTT,
        ReferenceEcliptic $ecliptic = ReferenceEcliptic::TrueOfDate,
    ): Position {
        $meanLongitude = fn (float $jd): float => match ($body) {
            Body::MeanNode => LunarPoints::meanNode($jd),
            Body::TrueNode => LunarPoints::trueNode($jd),
            Body::MeanLilith => LunarPoints::meanLilith($jd),
            Body::TrueLilith => LunarPoints::trueLilith($jd),
            Body::InterpolatedLilith => LunarPoints::interpolatedLilith($jd),
            Body::Priapus => LunarPoints::interpolatedPriapus($jd),
            default => 0.0,
        };

        $at = fn (float $jd): array => self::inEcliptic(
            [self::normalize($meanLongitude($jd) + rad2deg(Time::nutation(Time::centuries($jd))[0])), 0.0, 0.0],
            $jd,
            $ecliptic
        );

        return self::build($body, $at, $jdTT, self::STEP);
    }

    /**
     * The nodes and Lilith only exist looked at from the Earth. Whoever asks for them in another
     * frame finds out here and not with a null vector.
     *
     * @param Body|DownloadableBody $body
     * @return void
     */
    private static function requireBodyWithOrbit(Body|DownloadableBody $body): void
    {
        if ($body instanceof Body && $body->isLunarPoint()) {
            throw new LogicException(sprintf(
                '%s is an element of the Moon orbit around the Earth and has no position outside the geocentric frame.',
                $body->name()
            ));
        }
    }

    /**
     * Heliocentric or barycentric: the only thing that changes is where the origin is.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param bool $barycentric
     * @param PositionType $type
     * @param ReferenceEcliptic $ecliptic
     * @return Position
     */
    private static function fromOrigin(
        Body|DownloadableBody $body,
        float $jdTT,
        bool $barycentric,
        PositionType $type,
        ReferenceEcliptic $ecliptic,
    ): Position {
        return self::build(
            $body,
            fn (float $jd): array => self::inEcliptic(
                self::eclipticFromOrigin($body, $jd, $barycentric, $type->lightTime()),
                $jd,
                $ecliptic
            ),
            $jdTT,
            self::STEP
        );
    }

    /**
     * Assembles the `Position` of an instant, with its three speeds by centred differences, in
     * degrees per day and in astronomical units per day.
     *
     * And not analytically: differentiating the whole series is a lot more code for a number
     * that is only used to say whether it goes retrograde and at what rate. If the interval
     * crosses 360 degrees, the subtraction gives some 360 degrees per day of speed: the jump is
     * undone before dividing.
     *
     * The speed in latitude and in distance come for free: the function being differentiated
     * already returned the three coordinates at each instant and here two of them were thrown
     * away. They are the ones needed for the speed in rectangular coordinates
     * (`Position::rectangularVelocity`), which is the `SEFLG_XYZ | SEFLG_SPEED` of Swiss, and
     * they do not cost one more ephemeris. The longitude one comes out with the same arithmetic,
     * in the same order, as when it was the only one.
     *
     * @param Body|DownloadableBody $body
     * @param callable(float): array{0: float, 1: float, 2: float} $at
     * @param float $jdTT
     * @param float $step Days on each side.
     * @return Position
     */
    private static function build(Body|DownloadableBody $body, callable $at, float $jdTT, float $step): Position
    {
        [$longitude, $latitude, $distance] = $at($jdTT);

        $after = $at($jdTT + $step);
        $before = $at($jdTT - $step);

        $delta = $after[0] - $before[0];

        if ($delta > 180) {
            $delta -= 360;
        } elseif ($delta < -180) {
            $delta += 360;
        }

        return new Position(
            body: $body,
            longitude: $longitude,
            latitude: $latitude,
            distance: $distance,
            speed: $delta / (2 * $step),
            latitudeSpeed: ($after[1] - $before[1]) / (2 * $step),
            distanceSpeed: ($after[2] - $before[2]) / (2 * $step),
        );
    }

    /**
     * Only the apparent ecliptic longitude, without speed.
     *
     * The speed costs three times what the position costs, because it comes from centred
     * differences: the body has to be calculated at the instant and at the two beside it.
     * Whoever is only going to read the longitude pays three times what they need, and in a
     * sweep that shows: the milestones of a life ask for some seven thousand positions, and with
     * the extra speed they took thirty seconds.
     *
     * It returns a float and not a `Position` on purpose. Returning one with the speed at zero,
     * anyone who read that field would get a zero that looks like data: a planet standing still
     * instead of one going at twelve degrees per year. A float has no such field to read.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return float Degrees.
     */
    public static function apparentLongitude(Body|DownloadableBody $body, float $jdTT): float
    {
        if ($body instanceof Body && $body->isLunarPoint()) {
            return self::lunarPoint($body, $jdTT)->longitude;
        }

        return self::apparentEcliptic($body, $jdTT)[0];
    }

    /**
     * All the positions at once.
     *
     * @param list<Body> $bodies
     * @param float $jdTT
     * @return array<string, Position>
     */
    public static function positions(array $bodies, float $jdTT): array
    {
        $positions = [];

        foreach ($bodies as $body) {
            $positions[$body->value] = self::position($body, $jdTT);
        }

        return $positions;
    }

    /**
     * Apparent ecliptic longitude and latitude in degrees, and distance in AU.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    private static function apparentEcliptic(Body|DownloadableBody $body, float $jdTT): array
    {
        $t = Time::centuries($jdTT);

        $vector = self::geometricGeocentric($body, $jdTT);
        $distance = sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);

        /* The Moon steps out of two of the four steps, and the two exceptions are of the kind
           that give no error and shift the position by twenty arcseconds.

           Aberration: the tilt of the light by the velocity of the observer. The Moon orbits
           WITH the Earth, so it shares that velocity and between the two there is no
           aberration to correct. Its light delay, which does exist, already comes discounted
           from the series itself.

           FK5 frame: it corrects VSOP87's own system. ELP is not in that system, it is
           fitted to the JPL ephemerides, so applying the correction to it takes it out of
           where it already was right. And the tabulated ones neither, for the same reason:
           see `needsFk5`. */
        if ($body !== Body::Moon) {
            // The order is not free: first the light arrives curved by the Sun and then the
            // movement of the observer tilts it. The other way round you correct the aberration
            // of a direction that is not yet the one that is seen.
            if ($body !== Body::Sun) {
                $vector = self::deflection($vector, $distance, $jdTT);
            }

            $vector = self::aberration($vector, $distance, $jdTT);
        }

        $longitude = rad2deg(atan2($vector[1], $vector[0]));
        $latitude = rad2deg(atan2($vector[2], sqrt($vector[0] ** 2 + $vector[1] ** 2)));

        if ($body !== Body::Moon && self::needsFk5($body, $jdTT)) {
            [$longitude, $latitude] = self::fk5Correction($longitude, $latitude, $t);
        }

        // The nutation IS everyone's: the body does not move it, it moves the whole
        // reference frame.
        $longitude += rad2deg(Time::nutation($t)[0]);

        return [self::normalize($longitude), $latitude, $distance];
    }

    /**
     * Geocentric longitude, latitude and distance in the true ecliptic of date, with the
     * corrections of the light the type says.
     *
     * The apparent one is DELEGATED to `apparentEcliptic`, which is the chart's and is not
     * touched: two definitions of the same thing is a place where they can diverge. The rest is
     * the same chain with steps turned off, and there are two bodies that do not follow it whole:
     *
     * - The Moon never carries deflection (its light does not pass close to the Sun), so
     * without deflection it is the usual apparent one and without aberration it is the
     * astrometric one. And its astrometric position does not come from its own path but from
     * the general one: the heliocentric position at the instant the light left minus the
     * Earth at this one. The own path applies no aberration because the Moon travels with us,
     * and that is true of the SUM of the two corrections, not of each one: the light time of a
     * Moon that moves with the Earth already eats the annual aberration, and what is left
     * without it is not obtained by subtracting anything. Measured against Horizons with
     * `VEC_CORR='LT'`, which is this very thing.
     * - The Sun carries no light time, because it is at the origin of the frame it is counted
     * in, nor deflection, because its light cannot be deviated by its own mass. It is the Swiss
     * convention, measured: its geometric and its astrometric positions are the same.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param PositionType $type
     * @return array{0: float, 1: float, 2: float}
     */
    private static function geocentricEcliptic(Body|DownloadableBody $body, float $jdTT, PositionType $type): array
    {
        $neverDeflected = $body === Body::Sun || $body === Body::Moon;

        if ($type === PositionType::Apparent || ($neverDeflected && $type === PositionType::NoDeflection)) {
            return self::apparentEcliptic($body, $jdTT);
        }

        $t = Time::centuries($jdTT);
        $earth = self::geometricEarth($jdTT);

        $vector = match (true) {
            $body === Body::Sun => [-$earth[0], -$earth[1], -$earth[2]],
            $body === Body::Moon && ! $type->lightTime() => self::geometricMoon($jdTT),
            $type->lightTime() => self::withLightTime(
                fn (float $jd) => self::geometricHeliocentric($body, $jd),
                $earth,
                $jdTT
            ),
            default => self::subtract(self::geometricHeliocentric($body, $jdTT), $earth),
        };

        $distance = sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);

        // In the same order as the apparent one: first the deflection and then the aberration.
        if (! $neverDeflected && $type->deflection()) {
            $vector = self::deflection($vector, $distance, $jdTT);
        }

        if ($type->aberration()) {
            $vector = self::aberration($vector, $distance, $jdTT);
        }

        $longitude = rad2deg(atan2($vector[1], $vector[0]));
        $latitude = rad2deg(atan2($vector[2], sqrt($vector[0] ** 2 + $vector[1] ** 2)));

        if ($body !== Body::Moon && self::needsFk5($body, $jdTT)) {
            [$longitude, $latitude] = self::fk5Correction($longitude, $latitude, $t);
        }

        $longitude += rad2deg(Time::nutation($t)[0]);

        return [self::normalize($longitude), $latitude, $distance];
    }

    /**
     * Spherical coordinates in the true ecliptic of date, taken to whichever one is asked for.
     *
     * The whole engine works in the true one of date and changes ecliptic at the end, in a
     * single place, instead of having each chain written three times.
     *
     * - To the mean one the nutation is removed, which in the ecliptic only moves the
     * longitude: it is subtracting exactly what was added.
     * - To J2000 you go from the mean one, with the same rotation the Moon and the JPL tables
     * use (`Precession::toJ2000`). Without removing the nutation first you would precess a
     * longitude that is not in the ecliptic the rotation starts from.
     *
     * The distance does not change: rotating the reference frame brings nothing closer.
     *
     * @param array{0: float, 1: float, 2: float} $spherical
     * @param float $jdTT
     * @param ReferenceEcliptic $ecliptic
     * @return array{0: float, 1: float, 2: float}
     */
    private static function inEcliptic(array $spherical, float $jdTT, ReferenceEcliptic $ecliptic): array
    {
        if ($ecliptic === ReferenceEcliptic::TrueOfDate) {
            return $spherical;
        }

        [$longitude, $latitude, $distance] = $spherical;
        $t = Time::centuries($jdTT);

        $longitude -= rad2deg(Time::nutation($t)[0]);

        if ($ecliptic === ReferenceEcliptic::MeanOfDate) {
            return [self::normalize($longitude), $latitude, $distance];
        }

        // With the unit vector and not with the distance, because a node has no distance.
        $l = deg2rad($longitude);
        $b = deg2rad($latitude);
        $vector = Precession::toJ2000([cos($b) * cos($l), cos($b) * sin($l), sin($b)], $t);

        return [
            self::normalize(rad2deg(atan2($vector[1], $vector[0]))),
            rad2deg(atan2($vector[2], sqrt($vector[0] ** 2 + $vector[1] ** 2))),
            $distance,
        ];
    }

    /**
     * The geometry the phase and the size of a disc come from: the phase angle and the
     * three sides of the Sun, body and Earth triangle.
     *
     * It lives here and not in `Phenomena` because the delicate parts are two things only this
     * class knows.
     *
     * At which instant each side is measured. The body is seen where it was when the light
     * that arrives now left, so its distance to the Sun is measured at THAT instant and not at
     * this one. It sounds like a detail and in the Moon it is worth 0.08 degrees of phase angle:
     * the light of the Sun takes eight minutes to reach it and in eight minutes the Moon travels
     * four arcminutes. With the ordinary heliocentric position, which measures its delay from the
     * Sun and not from here, the phase of the Moon came out systematically shifted and no
     * calculation gave an error.
     *
     * And that the angle comes from the VECTORS and not from the law of cosines. The formula
     * of the three sides is the one in every textbook and here it loses digits: its numerator is
     * `r² + Δ² − R²`, and in the Moon that subtracts two numbers worth 1.03 to give 0.0029, that
     * is, two and a half digits of precision go at once. With the angle between the two vectors,
     * by arctangent of the magnitude of the cross product divided by the dot product, there is
     * nothing to cancel. Measured against the JPL, the difference goes down from six thousandths
     * of a degree to one ten-thousandth.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return array{alfa: float, r: float, delta: float, R: float} The angle in degrees and the
     * three distances in AU.
     */
    public static function phaseGeometry(Body|DownloadableBody $body, float $jdTT): array
    {
        $geocentric = self::geometricGeocentric($body, $jdTT);
        $delta = sqrt($geocentric[0] ** 2 + $geocentric[1] ** 2 + $geocentric[2] ** 2);

        $earth = self::geometricEarth($jdTT);
        $capitalR = sqrt($earth[0] ** 2 + $earth[1] ** 2 + $earth[2] ** 2);

        // The body, where it was when the light that is seen now left.
        $emission = self::geometricHeliocentric($body, $jdTT - self::LIGHT_PER_AU * $delta);
        $r = sqrt($emission[0] ** 2 + $emission[1] ** 2 + $emission[2] ** 2);

        if ($r <= 0.0 || $delta <= 0.0) {
            return ['alpha' => 0.0, 'r' => $r, 'delta' => $delta, 'R' => $capitalR];
        }

        // From the body towards the Sun and from the body towards here.
        $toTheSun = [-$emission[0], -$emission[1], -$emission[2]];
        $toHere = [-$geocentric[0], -$geocentric[1], -$geocentric[2]];

        $dot = $toTheSun[0] * $toHere[0] + $toTheSun[1] * $toHere[1] + $toTheSun[2] * $toHere[2];
        $cross = [
            $toTheSun[1] * $toHere[2] - $toTheSun[2] * $toHere[1],
            $toTheSun[2] * $toHere[0] - $toTheSun[0] * $toHere[2],
            $toTheSun[0] * $toHere[1] - $toTheSun[1] * $toHere[0],
        ];

        $alpha = rad2deg(atan2(
            sqrt($cross[0] ** 2 + $cross[1] ** 2 + $cross[2] ** 2),
            $dot
        ));

        return ['alpha' => $alpha, 'r' => $r, 'delta' => $delta, 'R' => $capitalR];
    }

    /**
     * Longitude, latitude and distance seen from the Sun or from the barycentre.
     *
     * With the light time measured from the origin: `withLightTime` with the observer at
     * zero. FK5 frame (for whoever it applies to, see `needsFk5`) and nutation like the
     * geocentric one, so that the four frames speak the same system and subtracting two of them
     * makes sense; the aberration not, because the origin does not move. The Moon here does
     * carry FK5: its heliocentric position is the VSOP87 Earth plus a quarter of a hundredth of
     * an AU from ELP, and the Earth rules.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param bool $barycentric
     * @param bool $lightTime Without it, it is the geometric one: where the body is at that instant.
     * @return array{0: float, 1: float, 2: float}
     */
    private static function eclipticFromOrigin(Body|DownloadableBody $body, float $jdTT, bool $barycentric, bool $lightTime): array
    {
        $t = Time::centuries($jdTT);

        $geometric = $barycentric
            ? fn (float $jd): array => self::add(self::geometricHeliocentric($body, $jd), self::barycentricSun($jd))
            : fn (float $jd): array => self::geometricHeliocentric($body, $jd);

        $vector = $lightTime
            ? self::withLightTime($geometric, [0.0, 0.0, 0.0], $jdTT)
            : $geometric($jdTT);
        $distance = sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);

        $longitude = rad2deg(atan2($vector[1], $vector[0]));
        $latitude = rad2deg(atan2($vector[2], sqrt($vector[0] ** 2 + $vector[1] ** 2)));

        if (self::needsFk5($body, $jdTT)) {
            [$longitude, $latitude] = self::fk5Correction($longitude, $latitude, $t);
        }

        $longitude += rad2deg(Time::nutation($t)[0]);

        return [self::normalize($longitude), $latitude, $distance];
    }

    /**
     * Apparent longitude, latitude and distance from a point on the surface.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @param float $latitude Geographic.
     * @param float $longitude Geographic.
     * @param float $heightMetres
     * @param PositionType $type
     * @return array{0: float, 1: float, 2: float}
     */
    private static function topocentricEcliptic(Body|DownloadableBody $body, float $jdTT, float $latitude, float $longitude, float $heightMetres, PositionType $type): array
    {
        [$eclipticLongitude, $eclipticLatitude, $distance] = self::geocentricEcliptic($body, $jdTT, $type);

        $geocentric = Horizon::equatorial(
            new Position($body, $eclipticLongitude, $eclipticLatitude, $distance, 0.0),
            $jdTT
        );

        $jdUt = Time::ut($jdTT);
        $vector = Horizon::topocentricVector($geocentric->vector(), $latitude, $longitude, $heightMetres, $jdUt);

        // The diurnal aberration, with the same arithmetic as the annual one: the direction
        // tilts towards where the observer is going, in the ratio of its speed to that of light.
        // It goes with the annual one: without aberration there is neither of the two.
        if ($type->aberration()) {
            $velocity = Horizon::observerVelocity($latitude, $longitude, $heightMetres, $jdUt);
            $norm = sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);

            foreach ([0, 1, 2] as $axis) {
                $vector[$axis] += $norm * $velocity[$axis] / self::C_KM_S;
            }
        }

        return Horizon::eclipticOf(Equatorial::fromVector($vector), $jdTT);
    }

    /**
     * The Earth to body vector, corrected for light time.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    private static function geometricGeocentric(Body|DownloadableBody $body, float $jdTT): array
    {
        /* Through `geometricHeliocentric` and not through `Vsop87` raw, which is what there was:
           this is the Earth that is subtracted from EVERYTHING that is seen from here, so
           skipping its correction here left the Sun uncorrected, which is minus this vector, and
           put the error of the Earth into the eight planets. It gave no error at all: it gave
           the position from before. */
        $earth = self::geometricEarth($jdTT);

        // The Sun is at the origin of the heliocentric system, so seeing it from the
        // Earth is looking towards where the Earth is not. Light time does not move it:
        // by definition it does not depart from the origin.
        if ($body === Body::Sun) {
            return [-$earth[0], -$earth[1], -$earth[2]];
        }

        /* The Moon has a path of its own because its light delay is the one from here to it and
           not the one of the path to the Sun. The correction is evaluated at `$jdTT` and not at
           the delayed instant, and it makes no difference: in the second and a bit the light
           takes, a correction whose fastest term has a period of one month changes by one
           hundred-thousandth of an arcsecond. */
        if ($body === Body::Moon) {
            return self::corrected('moon', Moon::geocentricRectangular($jdTT), $jdTT);
        }

        // Pluto, Chiron and the asteroids by their table and the planets by VSOP87:
        // `geometricHeliocentric` knows it, and here the Earth is only subtracted with light time.
        return self::withLightTime(
            fn (float $jd) => self::geometricHeliocentric($body, $jd),
            $earth,
            $jdTT
        );
    }

    /**
     * The Sun to body vector, geometric, in the ecliptic of date and in AU.
     *
     * It is the only place that knows where each body comes from: from VSOP87 the planets and
     * the Earth, from its JPL table the tabulated ones, and the Moon as the Earth plus its
     * GEOMETRIC geocentric position (without the Earth-Moon light delay, which plays no part
     * here: the delay that counts is the one of the path to the origin and it is the caller who
     * puts it in). The Sun is the origin.
     *
     * @param Body|DownloadableBody $body
     * @param float $jd
     * @return array{0: float, 1: float, 2: float}
     */
    private static function geometricHeliocentric(Body|DownloadableBody $body, float $jd): array
    {
        if ($body === Body::Sun) {
            return [0.0, 0.0, 0.0];
        }

        if ($body === Body::Moon) {
            // Through the Earth and not through `Vsop87` directly, so that the correction of the
            // Earth is applied only once and in a single place.
            return self::add(
                self::geometricHeliocentric(Body::Earth, $jd),
                self::geometricMoon($jd)
            );
        }

        /* The asteroids, satellites and comets that are downloaded from the JPL. They come in the
           ecliptic of J2000, like the usual tables, and they are rotated to date the same way. A
           satellite is downloaded from the barycentre of its system, so its planet is added to it,
           which in the engine IS that barycentre (`Satellite::horizonsCenter`): through here and not
           through `Vsop87` raw, so that the correction of the planet towards the JPL comes in once
           and in one place. */
        if ($body instanceof DownloadableBody) {
            $vector = Precession::toDate(DownloadedPositions::j2000($body, $jd), Time::centuries($jd));

            return $body instanceof Satellite
                ? self::add(self::geometricHeliocentric($body->planet(), $jd), $vector)
                : $vector;
        }

        // The tabulated ones already come from the JPL: there is nothing to correct towards the JPL.
        if ($body->isTabulated()) {
            return EphemerisPositions::atDate($body->value, $jd);
        }

        /* The nineteen that come neither from a series nor from a table but from propagating a
           postulated ellipse. They carry no correction towards the JPL either, and this time not
           for the usual reason: it is that there is no JPL to correct against, because these
           bodies do not exist.

           * *Two of the nineteen orbit the EARTH** (Selena and Waldemath's moon), so what
           `FictitiousBodies` returns for them is a geocentric vector and the Earth has to be
           added to it to take it to the origin everything else works in. Through
           `geometricHeliocentric` and not through `Vsop87` raw, exactly like the Moon, so that
           the correction of the Earth towards the JPL is applied once and in one place.
           Treating them as heliocentric would leave them one astronomical unit away from where
           they go, looking exactly like a normal planet in any old sign. */
        if ($body->isFictitious()) {
            $vector = FictitiousBodies::rectangular($body, $jd);

            return FictitiousBodies::isGeocentric($body)
                ? self::add(self::geometricHeliocentric(Body::Earth, $jd), $vector)
                : $vector;
        }

        return self::corrected(
            $body->value,
            Vsop87::rectangular($body->value, Time::millennia($jd)),
            $jd
        );
    }

    /**
     * The fingerprint of the engine's data files.
     *
     * It exists for the caches. What is calculated from a chart (the stars in conjunction, the
     * parans, the prenatal eclipses) costs close to a second and is a function of the birth
     * data, so it is stored by chart code. But it is also a function of the EPHEMERIDES, and
     * those change: the day a table is regenerated or a correction is added, a cache keyed only
     * on the code would go on serving the old positions for a day, with no error and without
     * anyone noticing. Putting this into the key, a change of data invalidates what is stored
     * without anyone having to remember.
     *
     * The date and the size are looked at and not the content: reading five megabytes to
     * calculate a key would cost more than redoing the arithmetic.
     *
     * And the engine's classes come in too, because a change of calculation moves what is
     * stored just as a change of data does and touches no file in `resources/astro`. It happened
     * when UTC was hooked up to the chart and the nutation was added to the nodes: the stored
     * stars, parans and solar return would have gone on being served with the instant and the
     * frame from before. With this a deployment invalidates the caches, which last a day, and
     * nobody has to remember to bump a number by hand.
     *
     * @return string
     */
    public static function dataFingerprint(): string
    {
        static $fingerprint = null;

        if ($fingerprint !== null) {
            return $fingerprint;
        }

        $parts = [];

        foreach ([...glob(DataFolder::path('{*,*/*}'), GLOB_BRACE), ...glob(__DIR__.'/*.php')] as $path) {
            if (is_file($path)) {
                $parts[] = basename($path).':'.filemtime($path).':'.filesize($path);
            }
        }

        sort($parts);

        return $fingerprint = substr(md5(implode('|', $parts)), 0, 12);
    }

    /**
     * The geometric heliocentric Earth, remembered per instant.
     *
     * Two places ask for it for every position that is calculated, `geometricGeocentric` to
     * subtract it and the deflection to know where the Sun is, and a position is calculated
     * three times per body, because the speed comes from centred differences. They are a few
     * different instants per chart and always the same ones, so eight are kept.
     *
     * @param float $jd
     * @return array{0: float, 1: float, 2: float}
     */
    private static function geometricEarth(float $jd): array
    {
        static $memo = [];

        $key = (string) $jd;

        if (! isset($memo[$key])) {
            if (count($memo) >= 8) {
                array_shift($memo);
            }

            $memo[$key] = self::geometricHeliocentric(Body::Earth, $jd);
        }

        return $memo[$key];
    }

    /**
     * Deviates the direction of a body by the gravity of the Sun.
     *
     * The mass of the Sun curves the light that passes close to it, so a body seen almost behind
     * the Sun appears to be a little further from it than it is. It is Eddington's measurement in
     * the 1919 eclipse, the one that made Einstein famous, and on a chart it is not a theoretical
     * curiosity: it is the ONLY place where the engine departed from the JPL by almost one
     * arcsecond, and it happens to a planet right up against the Sun, which is a situation with a
     * name of its own in astrology.
     *
     * How much it is worth, measured from the centre of the Sun: 1.75 arcseconds grazing its
     * limb, 0.47 at one degree, 0.09 at five and 0.004 at ninety. That is, outside a few degrees
     * around the Sun it is thousandths.
     *
     * The formula is the one from the Explanatory Supplement, the same one Horizons and Swiss
     * use, and the constant is twice the Schwarzschild radius of the Sun divided by the
     * astronomical unit.
     *
     * It is not applied to the Moon, nor to the Sun. The light of the Moon does not pass
     * close to the Sun, it comes from right beside us, which is the same reason why it carries
     * no annual aberration either; and that of the Sun cannot be deviated by its own mass,
     * because it comes out of it.
     *
     * @param array{0: float, 1: float, 2: float} $vector Geocentric, already with light time.
     * @param float $distance Magnitude of that vector, in AU.
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    private static function deflection(array $vector, float $distance, float $jdTT): array
    {
        return self::deflectionFrom($vector, $distance, self::geometricEarth($jdTT));
    }

    /**
     * The same arithmetic with the observer put in by hand, which is what is needed to look
     * from another planet (`planetocentric`).
     *
     * The deflection depends on where the observer is and not only on where the body is:
     * what produces it is the light passing close to the Sun ON ITS WAY TO HERE, so the
     * triangle carries the three vertices inside it. From Mars, the Sun is seen in another
     * direction and what is left almost behind it is something else.
     *
     * @param array{0: float, 1: float, 2: float} $vector From the observer to the body, already with light time.
     * @param float $distance Magnitude of that vector, in AU.
     * @param array{0: float, 1: float, 2: float} $observer Heliocentric position of the observer, in AU.
     * @return array{0: float, 1: float, 2: float}
     */
    private static function deflectionFrom(array $vector, float $distance, array $observer): array
    {
        $earth = $observer;
        $observerDistance = sqrt($earth[0] ** 2 + $earth[1] ** 2 + $earth[2] ** 2);

        if ($distance <= 0.0 || $observerDistance <= 0.0) {
            return $vector;
        }

        // The body seen from the Sun at the instant the light left: what is seen from the
        // observer plus the observer, that is, its delayed heliocentric position.
        $heliocentric = self::add($vector, $earth);
        $heliocentricNorm = sqrt($heliocentric[0] ** 2 + $heliocentric[1] ** 2 + $heliocentric[2] ** 2);

        if ($heliocentricNorm <= 0.0) {
            return $vector;
        }

        $p = [];
        $q = [];
        $e = [];

        foreach ([0, 1, 2] as $axis) {
            $p[$axis] = $vector[$axis] / $distance;
            $q[$axis] = $heliocentric[$axis] / $heliocentricNorm;
            $e[$axis] = $earth[$axis] / $observerDistance;
        }

        $pq = $p[0] * $q[0] + $p[1] * $q[1] + $p[2] * $q[2];
        $qe = $q[0] * $e[0] + $q[1] * $e[1] + $q[2] * $e[2];
        $ep = $e[0] * $p[0] + $e[1] * $p[1] + $e[2] * $p[2];

        /* The denominator vanishes when the body is exactly behind the CENTRE of the Sun,
           where the formula goes to infinity because the light would pass through the inside of
           the star. Below this value the body is covered by the solar disc and is not seen:
           there is no apparent direction to correct. The number is not a round one, it is the
           one that corresponds to the limb of the Sun seen from here, half a degree of diameter. */
        $denominator = 1.0 + $qe;

        if ($denominator < 1e-5) {
            return $vector;
        }

        $factor = self::SOLAR_DEFLECTION / ($observerDistance * $denominator);

        foreach ([0, 1, 2] as $axis) {
            $p[$axis] += $factor * ($pq * $e[$axis] - $ep * $q[$axis]);
        }

        // The same distance is returned: what gravity bends is the direction.
        $norm = sqrt($p[0] ** 2 + $p[1] ** 2 + $p[2] ** 2);

        return [
            $p[0] / $norm * $distance,
            $p[1] / $norm * $distance,
            $p[2] / $norm * $distance,
        ];
    }

    /**
     * Adds to a vector from our series what it is missing to be the JPL one.
     *
     * VSOP87 and ELP are series fitted forty years ago to DE200, and against DE440 they depart
     * by from a tenth of an arcsecond (the inner ones, in the 20th century) to several (Uranus
     * and Neptune at three centuries, the Moon in 1600 because of the tidal acceleration). The
     * difference is tabulated in `CorrectionTable`, in Chebyshev and in the same ecliptic of date
     * this class works in, so correcting is adding.
     *
     * Without a file or outside its range, nothing is added and the engine does exactly what
     * it did before. That is what separates a correction layer from a change of ephemerides:
     * there is no case in which the chart cannot be cast.
     *
     * @param string $key
     * @param array{0: float, 1: float, 2: float} $vector
     * @param float $jd
     * @return array{0: float, 1: float, 2: float}
     */
    private static function corrected(string $key, array $vector, float $jd): array
    {
        $correction = CorrectionTable::vector($key, $jd);

        return $correction === null ? $vector : self::add($vector, $correction);
    }

    /**
     * The GEOMETRIC geocentric Moon in rectangular coordinates, ecliptic of date and AU: without
     * the Earth-Moon light delay, which only plays a part when looking from the Earth.
     *
     * @param float $jd
     * @return array{0: float, 1: float, 2: float}
     */
    private static function geometricMoon(float $jd): array
    {
        [$l, $b, $r] = Moon::spherical($jd);

        // The correction of the Moon is GEOCENTRIC, which is what ELP gives: this way the error
        // of the Earth and that of the Moon are each corrected on their own side and do not mix.
        return self::corrected(
            'moon',
            [$r * cos($b) * cos($l), $r * cos($b) * sin($l), $r * sin($b)],
            $jd
        );
    }

    /**
     * Where the Sun is with respect to the barycentre of the solar system, in the ecliptic of
     * date and in AU.
     *
     * It is the definition of barycentre turned into arithmetic: minus the sum of the
     * heliocentric positions weighted by their mass, divided by the total mass with the Sun
     * included. The masses are those of DE440 (`resources/astro/masses.php`, downloaded by
     * `astronomy masses`) and the positions the same ones the rest of the engine uses.
     *
     * It used to come from the E series of VSOP87, and that series puts the Sun a thousand
     * kilometres from where DE440 puts the barycentre, measured against the JPL vectors from
     * `500@0` and without truncating the series. With the sum of masses over our own series it
     * stays between 60 and 135 kilometres on seven dates from 1700 to 2300, and Mercury, which
     * is the one that notices it most, goes from 5.7 arcseconds to half of one. It was not the
     * asteroids that are missing (Ceres moves the barycentre 0.2 km): it was the fit of VSOP87E
     * itself.
     *
     * Everything goes in the SAME frame, the ecliptic of date, because that is what
     * `geometricHeliocentric` returns for all of them: VSOP87 already comes in it, the ELP Moon
     * too and the table of Pluto is rotated when it is read. A weighted mean of vectors commutes
     * with the rotation, so nothing else has to be rotated nor does one have to choose between
     * two precessions, which was the trap of the E series, which came in J2000.
     *
     * The Earth and the Moon come in as their barycentre with the mass of the SYSTEM (the GMB of
     * DE440): the Earth plus the geocentric Moon divided by one plus EMRAT. Pluto comes in as
     * long as its table reaches the date and outside that it is left out: it weighs 37 kilometres
     * in the barycentre (seven thousand-millionths of a solar mass at 35 AU), below what the
     * frame is good for, and it is not worth Mars losing its barycentric position in 1500 for that.
     *
     * @param float $jd
     * @return array{0: float, 1: float, 2: float}
     */
    private static function barycentricSun(float $jd): array
    {
        $masses = self::masses();

        $weighted = [0.0, 0.0, 0.0];
        $total = $masses['gm']['sun'];

        $accumulate = function (float $gm, array $position) use (&$weighted, &$total): void {
            foreach ([0, 1, 2] as $axis) {
                $weighted[$axis] += $gm * $position[$axis];
            }

            $total += $gm;
        };

        foreach ([Body::Mercury, Body::Venus, Body::Mars, Body::Jupiter, Body::Saturn, Body::Uranus, Body::Neptune] as $planet) {
            $accumulate($masses['gm'][$planet->value], self::geometricHeliocentric($planet, $jd));
        }

        $earth = Vsop87::rectangular(Body::Earth->value, Time::millennia($jd));
        $moon = self::geometricMoon($jd);
        $part = 1 / (1 + $masses['emrat']);

        $accumulate($masses['gm']['earth-moon'], [
            $earth[0] + $moon[0] * $part,
            $earth[1] + $moon[1] * $part,
            $earth[2] + $moon[2] * $part,
        ]);

        [$from, $to] = EphemerisPositions::range(Body::Pluto->value);

        if ($jd >= $from && $jd <= $to) {
            $accumulate($masses['gm']['pluto'], self::geometricHeliocentric(Body::Pluto, $jd));
        }

        return [-$weighted[0] / $total, -$weighted[1] / $total, -$weighted[2] / $total];
    }

    /** @var array{ephemeris: string, au: float, emrat: float, gm: array<string, float>}|null */
    private static ?array $masses = null;

    /**
     * The masses of DE440, read once per process.
     *
     * @return array{ephemeris: string, au: float, emrat: float, gm: array<string, float>}
     */
    private static function masses(): array
    {
        return self::$masses ??= require DataFolder::path('masses.php');
    }

    /**
     * Whether a body gets the correction from the VSOP87 frame to FK5.
     *
     * Only what comes out of VSOP87. Pluto, Chiron and the asteroids come tabulated from the JPL
     * and the JPL works in ICRF, which is FK5 to two hundredths of an arcsecond: applying to them
     * the nine hundredths of the correction shifted them a whole tenth and in the opposite
     * direction. Measured against Horizons in TT, twenty-five dates from 1700 to 2300: the mean
     * in longitude of the six goes from between -0.03" and -0.12" to between -0.01" and +0.06",
     * and the RMS goes down in all six; from 1900 to 2050 the mean stays between -0.03" and +0.01".
     *
     * The geocentric Moon is a separate case and it is decided where it is applied: it does not
     * carry it either, being from ELP.
     *
     * And a body with a correction table does not carry it either, for the exact same reason:
     * VSOP87 plus its correction is no longer VSOP87, it is a JPL position, that is, ICRF. That
     * includes the geocentric Sun, which is the Earth turned around, and the barycentric one. The
     * condition is asked and not written by hand: the day a body gains its table, it stops
     * carrying FK5 by itself. Measured: it is 0.0903 arcseconds constant in longitude and up to
     * 0.055 in latitude, half of what the engine has left against Horizons, and with this the
     * worst case of the eight goes down from 0.20 to 0.12.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return bool
     */
    private static function needsFk5(Body|DownloadableBody $body, float $jdTT): bool
    {
        /* The nineteen bodies of elements do not come through here, and the reason is the same one
           why the tabulated ones do not: the FK5 correction straightens VSOP87's own dynamical
           frame, and this is not VSOP87. Their elements are referred to the mean ecliptic and
           equinox of another epoch and from there they are precessed with our own precession, so
           they are already in the frame the engine works in. Applying it to them would be nine
           hundredths of an arcsecond taken from another reference system, which is exactly the
           bug that was already stepped on by applying it to Pluto. */
        if ($body instanceof DownloadableBody) {
            // What is downloaded from the JPL is already in its frame, like the tabulated ones. A
            // satellite is its planet plus a JPL vector, so it inherits the planet's answer, just
            // as the Sun inherits the Earth's.
            return $body instanceof Satellite && self::needsFk5($body->planet(), $jdTT);
        }

        if ($body->isFictitious()) {
            return false;
        }

        /* The Sun has no table of its own and does not need one: its geocentric position is the
           Earth turned around and the barycentric one a sum of heliocentric positions, so it
           inherits the frame of the Earth and with it the answer. Asking for "sol", which does
           not exist as a table, FK5 went on being applied to the Sun while it came from an Earth
           already in ICRF: it showed in that it was the only body that stayed at 0.17 arcseconds
           while the eight went down to 0.10. */
        $frame = $body === Body::Sun ? Body::Earth : $body;

        /* The DATE is asked about and not whether there is a file: outside the range of the table
           there is no correction, so that body goes back to being pure VSOP87 and goes back to
           needing FK5. Asking only about the file, a date outside the range was left without
           correction and without FK5, that is, nine hundredths of an arcsecond WORSE than before
           the layer existed, which is exactly the opposite of what it promises. */
        return ! $frame->isTabulated() && CorrectionTable::vector($frame->value, $jdTT) === null;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return array{0: float, 1: float, 2: float}
     */
    private static function add(array $a, array $b): array
    {
        return [$a[0] + $b[0], $a[1] + $b[1], $a[2] + $b[2]];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return array{0: float, 1: float, 2: float}
     */
    private static function subtract(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
    }

    /**
     * Iterates until the delay of the light stops changing.
     *
     * It converges in three or four turns even for Neptune, because the planet barely moves
     * in the four hours its light takes to arrive.
     *
     * @param callable(float): array{0: float, 1: float, 2: float} $heliocentric
     * @param array{0: float, 1: float, 2: float} $earth
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    private static function withLightTime(callable $heliocentric, array $earth, float $jdTT): array
    {
        $delay = 0.0;
        $vector = [0.0, 0.0, 0.0];

        for ($iteration = 0; $iteration < 8; $iteration++) {
            $body = $heliocentric($jdTT - $delay);

            $vector = [
                $body[0] - $earth[0],
                $body[1] - $earth[1],
                $body[2] - $earth[2],
            ];

            $updated = self::LIGHT_PER_AU * sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);

            if (abs($updated - $delay) < 1e-9) {
                $delay = $updated;

                break;
            }

            $delay = $updated;
        }

        return $vector;
    }

    /**
     * Annual aberration of a direction without distance, that of a star.
     *
     * It is the same arithmetic as that of the planets with the distance set to one: what tilts
     * is the direction, and a star has nothing but direction.
     *
     * @param array{0: float, 1: float, 2: float} $unit
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    public static function unitAberration(array $unit, float $jdTT): array
    {
        return self::aberration($unit, 1.0, $jdTT);
    }

    /**
     * Annual aberration: it tilts the direction according to how fast the observer goes.
     *
     * The velocity of the Earth is obtained by differences instead of with the classical formula
     * of Meeus. It comes out the same, and a difference of positions is much harder to write
     * wrong than a series of sines copied by hand.
     *
     * @param array{0: float, 1: float, 2: float} $vector
     * @param float $distance
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    public static function aberration(array $vector, float $distance, float $jdTT): array
    {
        $h = 0.05;

        $before = Vsop87::rectangular('earth', Time::millennia($jdTT - $h));
        $after = Vsop87::rectangular('earth', Time::millennia($jdTT + $h));

        $velocity = [];

        foreach ([0, 1, 2] as $axis) {
            $velocity[$axis] = ($after[$axis] - $before[$axis]) / (2 * $h);
        }

        return self::aberrationFrom($vector, $distance, $velocity);
    }

    /**
     * The same tilt with the velocity of the observer put in by hand, to look from
     * another planet.
     *
     * Aberration is the OBSERVER's and not the body's: the light tilts because the one who
     * looks moves. From Jupiter, which goes at 13 km/s instead of at 30, it is half as long and
     * points somewhere else.
     *
     * The velocity of the Earth is obtained above from `Vsop87` raw, without the correction
     * towards the JPL, and there it stays: what is differentiated is a velocity, the correction
     * changes its seventh digit and changing it would move everybody's chart to gain nothing.
     *
     * @param array{0: float, 1: float, 2: float} $vector
     * @param float $distance
     * @param array{0: float, 1: float, 2: float} $velocity Heliocentric velocity of the observer, in AU per day.
     * @return array{0: float, 1: float, 2: float}
     */
    private static function aberrationFrom(array $vector, float $distance, array $velocity): array
    {
        foreach ([0, 1, 2] as $axis) {
            $vector[$axis] += $distance * $velocity[$axis] / self::C;
        }

        return $vector;
    }

    /**
     * From the dynamical frame of VSOP87 to FK5 (Meeus 32.3). Who carries it is said by `needsFk5`.
     *
     * @param float $longitude
     * @param float $latitude
     * @param float $t
     * @return array{0: float, 1: float}
     */
    private static function fk5Correction(float $longitude, float $latitude, float $t): array
    {
        $lp = deg2rad($longitude - 1.397 * $t - 0.00031 * $t * $t);

        $dLongitude = -0.09033 + 0.03916 * (cos($lp) + sin($lp)) * tan(deg2rad($latitude));
        $dLatitude = 0.03916 * (cos($lp) - sin($lp));

        return [$longitude + $dLongitude / 3600, $latitude + $dLatitude / 3600];
    }

    /**
     * @param float $degrees
     * @return float
     */
    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }
}
