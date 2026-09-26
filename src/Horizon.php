<?php

namespace Astronomy;

use Closure;

/**
 * The sky seen from one place: the observer, their horizon and what stands above it.
 *
 * Everything else in the engine is geocentric, that is, computed from the centre of the Earth,
 * and for a birth chart that is enough, because a chart is read in ecliptic longitudes and six
 * thousand kilometres do not move them. Here it is not enough, for two reasons:
 *
 * - Parallax. From the ground the Moon is seen up to one degree lower than from the centre
 * of the Earth, because the observer is six thousand kilometres closer to the horizon they
 * look at it over. One degree is four minutes of clock time in its rising. For anything to do
 * with the horizon, the Moon goes in topocentric coordinates.
 * - Refraction. The atmosphere lifts whatever is low: at the horizon, 34 arcminutes. The
 * Sun that is seen touching the sea is already geometrically below it in full.
 *
 * And here lives the trap of the two time scales as well, for the third time: the position of
 * the body is asked for in Terrestrial Time, but where the observer is depends on how much the
 * Earth has turned, and that goes in Universal Time. Everything that takes a `$jdUt` converts
 * it inside when it needs the other one.
 *
 * Azimuth is measured from north towards east. Swiss Ephemeris measures it from south
 * towards west; `Horizontal::azimuthFromSouth()` gives it in that convention for comparison.
 */
readonly class Horizon
{
    /** Equatorial radius of the Earth, in km. WGS84. */
    public const EQUATORIAL_RADIUS_KM = 6378.137;

    /** Flattening of the Earth. WGS84. */
    public const FLATTENING = 1 / 298.257223563;

    /** Pressure of the standard atmosphere, in millibars. It is the one `swe_refrac` uses. */
    public const PRESSURE = 1010.0;

    /** Temperature of the standard atmosphere, in degrees Celsius. */
    public const TEMPERATURE = 10.0;

    /**
     * Thermal lapse rate of the standard atmosphere, in degrees per metre: how much the air cools
     * as it goes up. It is `swe_set_lapse_rate`, and it only enters into the dip of the horizon.
     */
    public const LAPSE_RATE = -0.0065;

    /** Angular speed of the Earth's rotation, in radians per second (IERS). */
    public const ROTATION_RAD_S = 7.2921151467e-5;

    /**
     * Radius of the Sun, in km. It is the one Swiss Ephemeris uses for eclipses, so that the
     * magnitudes can be compared against the published ones.
     */
    /** Below this, Bennett's formula is not defined: its denominator vanishes. */
    private const ALTURA_MINIMA_BENNETT = -4.3;

    public const SUN_RADIUS_KM = 696000.0;

    /**
     * The Moon's two k values, which the IAU adopted in 1982 for eclipse predictions.
     *
     * There are two and not one, and choosing wrong costs kilometres. The one above is the
     * MEAN LIMB and holds for the penumbra and for the first and the last contact. The one below
     * is the UMBRAL LIMB, 0.08 % smaller, and it is the one that rules where what decides is the
     * last light of the Sun slipping through the valleys between the mountains of the limb: the
     * second and third contacts, and the cone that draws the path of a total eclipse.
     *
     * Neither of the two is the radius of the physical body.
     *
     * They live here because two places use them, `Eclipses` for its interior contacts and
     * `CentralPath` for its cone, and they were written by hand in both with the same figure.
     * Measured on the 2017 path: with the mean limb it comes out at 117.90 km and totality at
     * 164.5 seconds, where NASA publishes 114.7 and 160.1. Three kilometres too many in every
     * stretch and four seconds for free, looking every bit as if it were right.
     */
    public const K_MEAN_LIMB = 0.2725076;

    public const K_UMBRA_LIMB = 0.272281;

    /** The radius of the mean limb, in km. */
    public const MOON_RADIUS_KM = self::K_MEAN_LIMB * self::EQUATORIAL_RADIUS_KM;

    /** That of the umbral limb, 1.45 km smaller. */
    public const MOON_UMBRA_RADIUS_KM = self::K_UMBRA_LIMB * self::EQUATORIAL_RADIUS_KM;

    /**
     * An observing place, with everything the horizon needs to know about it.
     */
    public function __construct(
        public Place $place,
        public float $heightMetres = 0.0,
    ) {}

    /**
     * Local apparent sidereal time, in degrees: how much this particular meridian has turned.
     *
     * @param float $jdUt
     * @return float
     */
    public function localSiderealTime(float $jdUt): float
    {
        return self::normalize(Time::apparentSiderealTime($jdUt) + $this->place->longitude);
    }

    /**
     * Where the observer is with respect to the centre of the Earth, in km, on the axes of the
     * true equator of date.
     *
     * With the flattening: the Earth is 21 km wider across the equator than across the poles,
     * and a point at 45 degrees of latitude is eleven arcminutes closer to the equator than its
     * geographic latitude says. For the Moon that is several arcseconds of parallax. Height
     * above sea level is added to the radius just as it is: a thousand metres move the Moon half
     * an arcsecond, that is, it only counts up a mountain.
     *
     * @param float $jdUt
     * @return array{0: float, 1: float, 2: float}
     */
    public function observer(float $jdUt): array
    {
        return self::observerVector($this->place->latitude, $this->place->longitude, $this->heightMetres, $jdUt);
    }

    /**
     * The same, without `Place`: for whoever has latitude, longitude and height as separate
     * values, which is what `Ephemeris::topocentric()` receives. It is the only place where the
     * vector is built; `observer()` calls here.
     *
     * @param float $latitude Degrees, north positive.
     * @param float $longitude Degrees, east positive.
     * @param float $heightMetres
     * @param float $jdUt
     * @return array{0: float, 1: float, 2: float} Kilometres, axes of the true equator of date.
     */
    public static function observerVector(float $latitude, float $longitude, float $heightMetres, float $jdUt): array
    {
        $phi = deg2rad($latitude);
        $lambda = deg2rad(self::normalize(Time::apparentSiderealTime($jdUt) + $longitude));
        $f = self::FLATTENING;

        // The two functions of the ellipsoid: how much the radius shrinks in the direction of the
        // equator and in the direction of the axis. On a sphere both would be one.
        $c = 1 / sqrt(cos($phi) ** 2 + (1 - $f) ** 2 * sin($phi) ** 2);
        $s = (1 - $f) ** 2 * $c;

        $heightKm = $heightMetres / 1000.0;
        $radial = (self::EQUATORIAL_RADIUS_KM * $c + $heightKm) * cos($phi);

        return [
            $radial * cos($lambda),
            $radial * sin($lambda),
            (self::EQUATORIAL_RADIUS_KM * $s + $heightKm) * sin($phi),
        ];
    }

    /**
     * How fast the observer moves because of the Earth's rotation, in km/s and on the same axes
     * as `observerVector()`.
     *
     * It is omega times the vector: the Earth turns about its z axis, so every point describes a
     * circle towards the east at 465 metres per second at the equator and at nothing at the pole.
     * It is used for DIURNAL aberration, which is the same tilting of the light as the annual one
     * but by this velocity instead of by that of the orbit: a third of an arcsecond at most.
     * Little, and Swiss carries it in its topocentric coordinates, so without it the comparison
     * drags that third of a second along as if it were error.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $heightMetres
     * @param float $jdUt
     * @return array{0: float, 1: float, 2: float}
     */
    public static function observerVelocity(float $latitude, float $longitude, float $heightMetres, float $jdUt): array
    {
        [$x, $y] = self::observerVector($latitude, $longitude, $heightMetres, $jdUt);

        return [-self::ROTATION_RAD_S * $y, self::ROTATION_RAD_S * $x, 0.0];
    }

    /**
     * From an apparent ecliptic position to geocentric equatorial coordinates, with the distance
     * in kilometres.
     *
     * With the TRUE obliquity, not the mean one: the longitude of `Position` already carries the
     * nutation, so it is referred to the true equinox, and the rotation has to be the one of the
     * true equator. With the mean one, equator and ecliptic do not fit together and nine
     * arcseconds of error come out in the declination.
     *
     * @param Position $position
     * @param float $jdTT
     * @return Equatorial
     */
    public static function equatorial(Position $position, float $jdTT): Equatorial
    {
        $eps = Time::trueObliquity(Time::centuries($jdTT));
        $l = deg2rad($position->longitude);
        $b = deg2rad($position->latitude);

        $x = cos($b) * cos($l);
        $y = cos($b) * sin($l);
        $z = sin($b);

        $ye = $y * cos($eps) - $z * sin($eps);
        $ze = $y * sin($eps) + $z * cos($eps);

        return new Equatorial(
            rightAscension: self::normalize(rad2deg(atan2($ye, $x))),
            declination: rad2deg(asin($ze)),
            // The nodes and Lilith come with zero distance: they are directions, not bodies.
            distanceKm: $position->distance > 0 ? $position->distance * Equatorial::AU_KM : null,
        );
    }

    /**
     * The way back from `equatorial()`: from a direction referred to the true equator of date to
     * ecliptic longitude and latitude, with the distance in AU.
     *
     * With the same true obliquity, for the same reason: the way there and the way back have to
     * be the same rotation, or a position that goes through both does not come back to where it
     * was.
     *
     * @param Equatorial $coordinate
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float} [longitude in degrees, latitude in degrees, distance in AU (zero if it has none)]
     */
    public static function eclipticOf(Equatorial $coordinate, float $jdTT): array
    {
        $eps = Time::trueObliquity(Time::centuries($jdTT));
        [$x, $y, $z] = $coordinate->unit();

        $ye = $y * cos($eps) + $z * sin($eps);
        $ze = -$y * sin($eps) + $z * cos($eps);

        return [
            self::normalize(rad2deg(atan2($ye, $x))),
            rad2deg(atan2($ze, sqrt($x * $x + $ye * $ye))),
            $coordinate->distanceKm === null ? 0.0 : $coordinate->distanceKm / Equatorial::AU_KM,
        ];
    }

    /**
     * The same rotation as `equatorial()` and `eclipticOf()`, applied to the POSITION AND TO ITS
     * VELOCITY at once. It is `swe_cotrans_sp`.
     *
     * Six numbers go in and six come out: longitude, latitude and distance, and how much each one
     * changes per unit of time. The unit of time does not matter and is not named: what this does
     * is a rotation, and a rotation is linear, so it comes out in the same unit it went in with.
     *
     * The velocity is not rotated as if it were another position, and that is the reason this
     * exists instead of calling the usual rotation twice. Angles are not a vector: rotating the
     * point «one degree of longitude per day» as if it were a point of the sky gives nonsense.
     * What gets rotated is the rectangular vector and its derivative, which are vectors, and from
     * there one goes back to angles with the chain rule. That is why the latitude appears in
     * the denominator of the latitude that comes back: near the pole, one degree per day of
     * longitude is a great deal less distance covered.
     *
     * In this engine there is nobody to feed it, and it is better said here than discovered:
     * `Position` carries the velocity IN LONGITUDE, a single number, and that is a measured
     * decision (the full velocity comes out of centred differences and costs three times as much;
     * the milestones of a life went from thirty seconds to ten precisely by no longer asking for
     * it). So this is a door of the package: whoever brings six numbers from wherever, rotates
     * them.
     *
     * The direction is chosen by the name of the method and not by the sign of the obliquity,
     * which is how Swiss does it: a parameter whose SIGN changes what the function does is a
     * place to get it wrong without noticing.
     *
     * @param array{float, float, float, float, float, float} $ecliptic Longitude, latitude, distance and their three velocities.
     * @param float $jdTT
     * @return array{float, float, float, float, float, float} Right ascension, declination, distance and their velocities.
     */
    public static function equatorialWithSpeed(array $ecliptic, float $jdTT): array
    {
        return self::rotateWithSpeed($ecliptic, -Time::trueObliquity(Time::centuries($jdTT)));
    }

    /**
     * The way back: from equatorial coordinates with velocity to ecliptic coordinates with
     * velocity.
     *
     * @param array{float, float, float, float, float, float} $equatorial
     * @param float $jdTT
     * @return array{float, float, float, float, float, float}
     */
    public static function eclipticWithSpeed(array $equatorial, float $jdTT): array
    {
        return self::rotateWithSpeed($equatorial, Time::trueObliquity(Time::centuries($jdTT)));
    }

    /**
     * The rotation about the axis that points to the First Point of Aries, on the vector and on
     * its derivative.
     *
     * @param array{float, float, float, float, float, float} $spherical
     * @param float $angle Radians. Positive goes from the equator to the ecliptic.
     * @return array{float, float, float, float, float, float}
     */
    private static function rotateWithSpeed(array $spherical, float $angle): array
    {
        [$longitude, $latitude, $distance, $vLongitude, $vLatitude, $vDistance] = $spherical;

        $l = deg2rad($longitude);
        $b = deg2rad($latitude);
        $dl = deg2rad($vLongitude);
        $db = deg2rad($vLatitude);

        // The distance is taken outside the rotation: a scaling commutes with a rotation, and
        // that way a point with no distance (a star, a node) does not force a division by zero.
        $vector = [cos($b) * cos($l), cos($b) * sin($l), sin($b)];

        $derivative = [
            -sin($b) * $db * cos($l) - cos($b) * sin($l) * $dl,
            -sin($b) * $db * sin($l) + cos($b) * cos($l) * $dl,
            cos($b) * $db,
        ];

        $cosine = cos($angle);
        $sine = sin($angle);

        $rotate = fn (array $v): array => [$v[0], $v[1] * $cosine + $v[2] * $sine, -$v[1] * $sine + $v[2] * $cosine];

        [$x, $y, $z] = $rotate($vector);
        [$dx, $dy, $dz] = $rotate($derivative);

        $plane = $x * $x + $y * $y;

        return [
            self::normalize(rad2deg(atan2($y, $x))),
            rad2deg(atan2($z, sqrt($plane))),
            $distance,
            rad2deg(($x * $dy - $y * $dx) / $plane),
            rad2deg(($dz * $plane - $z * ($x * $dx + $y * $dy)) / sqrt($plane)),
            $vDistance,
        ];
    }

    /**
     * Apparent geocentric equatorial coordinates of a body or of a star at an instant.
     *
     * This is the only place where a `Star` is turned into a direction, and everything that
     * rises and sets or gets occulted (`RiseSet`, `Occultations`, `Eclipses`) comes through here
     * by way of `track()`. APPARENT right ascension and declination of date, with aberration and
     * nutation, which is what `swe_rise_trans` and `swe_lun_occult_when_*` use when they are
     * given a star name: the mean position would leave the star up to twenty arcseconds away from
     * where it is seen, which in an occultation is forty seconds of clock time. Without distance:
     * a star has no parallax, and `Equatorial::direction` says so with its null.
     *
     * @param Body|Star $object
     * @param float $jdTT
     * @return Equatorial
     */
    public static function equatorialOf(Body|Star $object, float $jdTT): Equatorial
    {
        if ($object instanceof Star) {
            $position = Stars::position($object, $jdTT);

            return Equatorial::direction($position->rightAscension, $position->declination);
        }

        return self::equatorial(Ephemeris::position($object, $jdTT), $jdTT);
    }

    /**
     * Whatever moves across the sky, as a function of time.
     *
     * A `Body` goes through the ephemerides and a `Star` through the catalogue, both by way of
     * `equatorialOf()`. A callable is an arbitrary direction: it receives a Julian day in TT and
     * returns the apparent `Equatorial` of date. That way a fictitious point, or a star that is
     * not in the catalogue, is plugged in without touching anything here.
     *
     * @param Body|Star|callable(float): Equatorial $object
     * @return Closure(float): Equatorial
     */
    public static function track(Body|Star|callable $object): Closure
    {
        if ($object instanceof Body || $object instanceof Star) {
            return fn (float $jdTT): Equatorial => self::equatorialOf($object, $jdTT);
        }

        return Closure::fromCallable($object);
    }

    /**
     * The name of whatever rises, sets or gets occulted, so as to write it down.
     *
     * A body and a star by their own name. This is the engine's label, so it goes in English;
     * whoever writes a reading translates it if they need to.
     * A callable has no name and is called 'star', which is what it is meant for.
     *
     * @param Body|Star|callable $object
     * @return string
     */
    public static function nameOf(Body|Star|callable $object): string
    {
        return match (true) {
            $object instanceof Body => $object->name(),
            $object instanceof Star => $object->name,
            default => 'star',
        };
    }

    /**
     * Physical radius of the object, in km. Zero for a star or a direction: they are points.
     * The radii are known by each body (`Body::radiusKm`); here the only thing decided is that
     * whatever is not a body has no disc.
     *
     * @param Body|Star|callable $object
     * @return float
     */
    public static function radiusKm(Body|Star|callable $object): float
    {
        return $object instanceof Body ? $object->radiusKm() : 0.0;
    }

    /**
     * Apparent semidiameter, in degrees, at whatever distance the coordinate says.
     *
     * @param Equatorial $coordinate
     * @param float $radiusKm
     * @return float
     */
    public static function semidiameter(Equatorial $coordinate, float $radiusKm): float
    {
        if ($radiusKm <= 0.0 || $coordinate->distanceKm === null) {
            return 0.0;
        }

        return rad2deg(asin(min(1.0, $radiusKm / $coordinate->distanceKm)));
    }

    /**
     * From geocentric to topocentric: the same direction seen from the ground.
     *
     * It is subtracting the observer's vector, nothing more. All the work is in the two vectors
     * speaking the same axes (true equator of date) and the same units (km), which is what
     * `equatorial()` and `observer()` guarantee.
     *
     * @param Equatorial $geocentric
     * @param float $jdUt
     * @return Equatorial
     */
    public function topocentric(Equatorial $geocentric, float $jdUt): Equatorial
    {
        if ($geocentric->distanceKm === null) {
            return $geocentric;
        }

        return $this->topocentricFromVector($geocentric->vector(), $jdUt);
    }

    /**
     * Topocentric coordinates from a geocentric vector in km, for whoever already has the vector.
     *
     * @param array{0: float, 1: float, 2: float} $vectorKm
     * @param float $jdUt
     * @return Equatorial
     */
    public function topocentricFromVector(array $vectorKm, float $jdUt): Equatorial
    {
        return Equatorial::fromVector(self::topocentricVector(
            $vectorKm, $this->place->latitude, $this->place->longitude, $this->heightMetres, $jdUt
        ));
    }

    /**
     * The subtraction itself, as a vector and without `Place`: the geocentric one minus the
     * observer, in km and on the axes of the true equator of date. Everything topocentric in this
     * class comes through here, and `Ephemeris::topocentric()` does too, so that the parallax is
     * computed in a single place.
     *
     * @param array{0: float, 1: float, 2: float} $vectorKm
     * @param float $latitude
     * @param float $longitude
     * @param float $heightMetres
     * @param float $jdUt
     * @return array{0: float, 1: float, 2: float}
     */
    public static function topocentricVector(array $vectorKm, float $latitude, float $longitude, float $heightMetres, float $jdUt): array
    {
        return self::subtract($vectorKm, self::observerVector($latitude, $longitude, $heightMetres, $jdUt));
    }

    /**
     * Altitude and azimuth of an already topocentric direction.
     *
     * @param Equatorial $topocentric
     * @param float $jdUt
     * @return Horizontal
     */
    public function horizontal(Equatorial $topocentric, float $jdUt): Horizontal
    {
        $h = deg2rad($this->localSiderealTime($jdUt) - $topocentric->rightAscension);
        $phi = deg2rad($this->place->latitude);
        $delta = deg2rad($topocentric->declination);

        // The sine is clamped before the arcsine: rounding takes it out of [-1, 1] right at the
        // zenith and `asin` returns NAN, which propagates in silence through the whole reckoning.
        $sine = sin($phi) * sin($delta) + cos($phi) * cos($delta) * cos($h);
        $altitude = rad2deg(asin(max(-1.0, min(1.0, $sine))));

        // From north towards east. It is the same formula with which `HousePositionTest` measures the
        // azimuth of the vertex by an independent route.
        $azimuth = rad2deg(atan2(
            -cos($delta) * sin($h),
            sin($delta) * cos($phi) - cos($delta) * sin($phi) * cos($h)
        ));

        return new Horizontal(
            altitude: $altitude,
            apparentAltitude: self::apparentAltitude($altitude),
            azimuth: self::normalize($azimuth),
        );
    }

    /**
     * The way back: from azimuth and altitude to right ascension and declination. It is Swiss's
     * `swe_azalt_rev`.
     *
     * The altitude that goes in is the TRUE one, not the apparent one, and mixing them up
     * costs half a degree right down at the horizon. Refraction lifts the image, so a body that
     * is seen at the horizon is really 34 arcminutes below it: to put in here what is read off a
     * theodolite, it has to go through `trueAltitude()` first. Swiss demands the same thing and
     * for the same reason. `Horizontal` carries both numbers under different names precisely so
     * that one can choose knowing what one is doing.
     *
     * The reckoning is the exact inverse of `horizontal()`, not a formula brought in from
     * somewhere else: the rotation of the sphere is orthogonal, so undoing it is its transpose,
     * and writing it that way guarantees that the way there and the way back cancel out. With a
     * formula copied from a manual, any discrepancy of azimuth convention (from the north or from
     * the south) would give a declination that looks right and with the hemisphere swapped.
     *
     * To go back as far as the ecliptic there is `eclipticOf()`, which is what Swiss does with
     * `SE_HOR2ECL` instead of `SE_HOR2EQU`.
     *
     * Against `swe_azalt_rev`, sixteen cases in four places and four dates from 1900 to 2023:
     * the declination comes out identical to exactly zero and the right ascension differs by
     * 0.017 to 0.283 arcseconds. And that residual has a name: it is the same for every place
     * and every azimuth of a given date, because the only way in that it has is the right
     * ascension, which comes out of sidereal time. Checked: the difference between our apparent
     * sidereal time and theirs gives those three numbers down to the last printed figure. That
     * is, the way back itself carries nothing, and what is left is the sidereal time offset that
     * this document already had written down for the ascendant.
     *
     * @param float $azimuth From north towards east, in degrees, as `Horizontal` gives it.
     * @param float $trueAltitude Degrees above the horizon, without refraction.
     * @param float $jdUt
     * @param float|null $distanceKm If it is known, it travels with the coordinate; if not, it is
     * a direction without distance, like a star.
     * @return Equatorial
     */
    public function equatorialFromHorizontal(
        float $azimuth,
        float $trueAltitude,
        float $jdUt,
        ?float $distanceKm = null,
    ): Equatorial {
        $a = deg2rad($azimuth);
        $altitude = deg2rad($trueAltitude);
        $phi = deg2rad($this->place->latitude);

        // The same vector that comes out of `horizontal()`: north, east and zenith.
        $north = cos($altitude) * cos($a);
        $east = cos($altitude) * sin($a);
        $zenith = sin($altitude);

        // And the transpose of the rotation that over there goes from the equator to the horizon.
        $x = -sin($phi) * $north + cos($phi) * $zenith;
        $y = -$east;
        $z = cos($phi) * $north + sin($phi) * $zenith;

        $hourAngle = rad2deg(atan2($y, $x));

        return new Equatorial(
            rightAscension: self::normalize($this->localSiderealTime($jdUt) - $hourAngle),
            declination: rad2deg(asin(max(-1.0, min(1.0, $z)))),
            distanceKm: $distanceKm,
        );
    }

    /**
     * The whole road: from a body or a star (or from its geocentric position) to where it is
     * seen.
     *
     * @param Body|Star|Position|Equatorial $object If it is `Equatorial`, geocentric.
     * @param float $jdUt
     * @return Horizontal
     */
    public function at(Body|Star|Position|Equatorial $object, float $jdUt): Horizontal
    {
        $jdTT = Time::tt($jdUt);

        $geocentric = match (true) {
            $object instanceof Body, $object instanceof Star => self::equatorialOf($object, $jdTT),
            $object instanceof Position => self::equatorial($object, $jdTT),
            default => $object,
        };

        return $this->horizontal($this->topocentric($geocentric, $jdUt), $jdUt);
    }

    /**
     * From true altitude to apparent: how much the atmosphere lifts it.
     *
     * Sæmundsson's formula (1986), which is the one `swe_refrac` uses in this direction, with
     * Meeus's pressure and temperature correction. Below five degrees under the horizon there is
     * no refraction worth anything: nothing is seen from there.
     *
     * And a convention that comes from Swiss and is worth knowing: if the apparent altitude would
     * come out negative, the true one is returned untouched. For a body that is really below the
     * horizon, refraction means nothing.
     *
     * @param float $trueAltitude Degrees.
     * @param float $pressure Millibars.
     * @param float $temperature Degrees Celsius.
     * @return float Degrees.
     */
    public static function apparentAltitude(float $trueAltitude, float $pressure = self::PRESSURE, float $temperature = self::TEMPERATURE): float
    {
        $factor = $pressure / 1010.0 * 283.0 / (273.0 + $temperature);
        $h = $trueAltitude;

        if ($h > 15.0) {
            $a = tan(deg2rad(90.0 - $h));
            $refraction = (58.276 * $a - 0.0824 * $a ** 3) * $factor / 3600.0;
        } elseif ($h > -5.0) {
            $a = $h + 10.3 / ($h + 5.11);
            $refraction = $a + 1e-10 >= 90.0 ? 0.0 : 1.02 / tan(deg2rad($a)) * $factor / 60.0;
        } else {
            $refraction = 0.0;
        }

        return $h + $refraction > 0.0 ? $h + $refraction : $h;
    }

    /**
     * From apparent altitude to true. The way back, by Bennett's formula (1982), which is the one
     * `swe_refrac` uses in this direction.
     *
     * Same convention: if the true one would come out negative, the apparent one is returned.
     *
     * @param float $apparentAltitude
     * @param float $pressure
     * @param float $temperature
     * @return float
     */
    public static function trueAltitude(float $apparentAltitude, float $pressure = self::PRESSURE, float $temperature = self::TEMPERATURE): float
    {
        $refraction = self::refractionFromApparent($apparentAltitude, $pressure, $temperature);

        return $apparentAltitude - $refraction > 0.0 ? $apparentAltitude - $refraction : $apparentAltitude;
    }

    /**
     * Refraction at the horizon itself, in degrees: what has to be discounted from a body that is
     * seen touching the horizon in order to know where it really is. With the standard atmosphere
     * it is 34.46 arcminutes.
     *
     * It comes out of Bennett evaluated at zero, which is the known apparent altitude: asking for
     * the true one is going round in circles, because refraction depends on the apparent one.
     *
     * @param float $pressure
     * @param float $temperature
     * @return float
     */
    public static function refractionAtHorizon(float $pressure = self::PRESSURE, float $temperature = self::TEMPERATURE): float
    {
        return self::refractionOf(0.0, $pressure, $temperature);
    }

    /**
     * Refraction at any APPARENT altitude, in degrees: how much what is seen has to be brought
     * down in order to know where it is.
     *
     * It is `swe_refrac` in its apparent-to-true direction, and it comes out into the open
     * because a rising over a high horizon needs it up there and not at zero: at five degrees the
     * refraction is 0.16 and at the horizon 0.57, so using the one at the horizon for a mountain
     * leaves the rising half a degree of altitude out.
     *
     * The APPARENT altitude is what is asked for, which is the one that is seen, and that is
     * the asymmetry of refraction: it depends on where the light comes in at the end, not on
     * where the body is. That is why `refractionAtHorizon()` is this very one evaluated at zero
     * and there is no direct way to ask «how much does what is really at such an altitude
     * refract».
     *
     * @param float $apparentAltitude Degrees.
     * @param float $pressure Millibars.
     * @param float $temperature Degrees Celsius.
     * @return float
     */
    public static function refractionOf(float $apparentAltitude, float $pressure = self::PRESSURE, float $temperature = self::TEMPERATURE): float
    {
        return self::refractionFromApparent($apparentAltitude, $pressure, $temperature);
    }

    /**
     * The refraction coefficient of the air: what fraction of the curvature of the Earth a
     * grazing ray follows. With the standard atmosphere it is 0.17, that is, the light bends a
     * little less than a fifth of what the planet bends.
     *
     * It is the Explanatory Supplement formula, and the thermal lapse rate is the one that
     * rules**: pressure and temperature move it little and the lapse rate moves everything,
     * because what bends the ray is the air changing density with height. That is why Swiss
     * exposes it as a parameter with `swe_set_lapse_rate` instead of taking it for granted.
     *
     * Above one the ray would bend more than the Earth and the horizon would stop dropping: that
     * is the atmospheric duct that makes ships visible beyond the horizon. It takes a thermal
     * inversion of 129 degrees per kilometre, that is, it does not happen, and even so it is
     * clamped, because what comes out of there is the square root of a negative number.
     *
     * @param float $pressure Millibars.
     * @param float $temperature Degrees Celsius.
     * @param float $lapseRate Degrees per metre.
     * @return float
     */
    public static function refractionCoefficient(
        float $pressure = self::PRESSURE,
        float $temperature = self::TEMPERATURE,
        float $lapseRate = self::LAPSE_RATE,
    ): float {
        $kelvin = $temperature + 273.15;

        return min(1.0, 503.0 * $pressure / ($kelvin * $kelvin) * (0.0342 + $lapseRate));
    }

    /**
     * At what altitude the horizon is seen from `$heightMetres` above the sea, in degrees and
     * NEGATIVE: the higher one stands, the lower it lies. It is the part of
     * `swe_refrac_extended` that did not fit into ordinary refraction.
     *
     * It comes out with the sign it is used with, so that it can be chained without thinking:
     * `RiseSet::next(..., horizonAltitude: Horizon::horizonDip(1000.0))`. An observer at a
     * thousand metres sees the horizon 0.93 degrees below the astronomical one, and that brings
     * the rising forward by some four minutes.
     *
     * The reckoning is two things: the geometry, which is the angle at which the tangent to a
     * sphere is seen from outside it, and the refraction, which reduces it because the ray
     * bends following the planet. With standard air the dip comes out at 91 % of the geometric
     * one, that is, 1.75 arcminutes per square root of a metre.
     *
     * Against `swe_refrac_extended`, with coherent atmospheres from 1 to 3,100 metres and four
     * lapse rates: worst 1.6 arcseconds. And that figure has to be read next to the other
     * one: not knowing the lapse rate moves the dip by up to 94 arcseconds, sixty times more.
     * Here the precision is not set by the formula, it is set by the air of that morning.
     *
     * @param float $heightMetres Of the observer above sea level.
     * @param float $pressure Millibars.
     * @param float $temperature Degrees Celsius.
     * @param float $lapseRate Degrees per metre.
     * @return float Degrees, zero or negative.
     */
    public static function horizonDip(
        float $heightMetres,
        float $pressure = self::PRESSURE,
        float $temperature = self::TEMPERATURE,
        float $lapseRate = self::LAPSE_RATE,
    ): float {
        if ($heightMetres <= 0.0) {
            return 0.0;
        }

        $radius = self::EQUATORIAL_RADIUS_KM * 1000.0;
        $geometric = rad2deg(acos($radius / ($radius + $heightMetres)));

        return -$geometric * sqrt(1.0 - self::refractionCoefficient($pressure, $temperature, $lapseRate));
    }

    /**
     * Bennett: refraction from the apparent altitude, in degrees.
     *
     * @param float $apparentAltitude
     * @param float $pressure
     * @param float $temperature
     * @return float
     */
    private static function refractionFromApparent(float $apparentAltitude, float $pressure, float $temperature): float
    {
        $factor = $pressure / 1010.0 * 283.0 / (273.0 + $temperature);

        /* Bennett is only defined from minus four degrees upwards: at -4.4 the denominator
           vanishes and below that it changes sign and returns a piece of nonsense that looks
           every bit like a number. From `RiseSet` it is never reached, but this is public API and
           `HorizonteTest` already calls it. Below the limit there is no refraction to give: what
           is five degrees below the horizon is not seen, and returning zero says that. */
        if ($apparentAltitude <= self::ALTURA_MINIMA_BENNETT) {
            return 0.0;
        }

        $a = $apparentAltitude + 7.31 / ($apparentAltitude + 4.4);

        if ($a + 1e-10 >= 90.0) {
            return 0.0;
        }

        $minutes = 1.0 / tan(deg2rad($a));
        $minutes -= 0.06 * sin(deg2rad(14.7 * $minutes + 13.0));

        return $minutes * $factor / 60.0;
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
     * @param float $degrees
     * @return float
     */
    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360.0) + 360.0, 360.0);
    }
}
