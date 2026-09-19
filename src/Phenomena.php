<?php

namespace Astronomy;

use InvalidArgumentException;

/**
 * Phase, size and brightness of a body: this is Swiss's `swe_pheno`.
 *
 * Everything comes out of one triangle, the one formed by the Sun, the body and us, and out of its
 * three sides, which `Ephemeris::phaseGeometry` gives: the distance from the body to the Sun, the
 * one from the body to here and the one from the Sun to here. From those come the phase angle and
 * the illuminated fraction of the disc; the apparent size is the body's radius divided by how far
 * away it is, and the brightness is set by `Magnitudes`.
 *
 * The triangle is measured by `Ephemeris` and not by this class, because the delicate part there is
 * at which instant each side is measured (the body is seen where it was when its light left) and
 * that the angle must not come out of the law of cosines, which on the Moon loses two and a half
 * digits.
 *
 * The three things that are not obvious
 *
 * The elongation is measured between the APPARENT directions, not with the triangle. The two
 * numbers look very much alike and are not the same thing: the triangle gives the geometric angle
 * and the elongation is what is seen, that is with light-time, aberration and latitude applied.
 * Since the elongation exists to decide whether a planet can be looked at, the good one is the one
 * that is seen.
 *
 * The phase angle does not tell waxing from waning, because it is symmetric: the first quarter
 * and the last quarter both have ninety degrees and half the disc lit. What separates them is which
 * side of the Sun the body is on, and that is what `orientedElongation` says, measured from 0 to
 * 360. It is the same trap already noted in `MoonPhase`.
 *
 * The Sun has no phase. It does not light itself, so its phase angle is zero, its disc is whole
 * and its elongation is zero too. Computing its triangle would give a division by zero, because one
 * of the sides measures zero.
 */
class Phenomena
{
    /**
     * What is seen of a body at one instant.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return Phenomenon
     */
    public static function of(Body|DownloadableBody $body, float $jdTT): Phenomenon
    {
        if ($body instanceof Body && ($body->isLunarPoint() || $body->isFictitious())) {
            throw new InvalidArgumentException(
                'The nodes, Lilith and the fictitious bodies have no disc: '.$body->name()
            );
        }

        $position = Ephemeris::position($body, $jdTT);
        ['alpha' => $alpha, 'r' => $r, 'delta' => $delta] = Ephemeris::phaseGeometry($body, $jdTT);

        if ($body === Body::Sun) {
            return new Phenomenon(
                body: $body,
                phaseAngle: 0.0,
                illuminatedFraction: 1.0,
                elongation: 0.0,
                apparentDiameter: self::diameter($body, $delta),
                magnitude: Magnitudes::of($body, $delta, $delta, 0.0),
            );
        }

        $sun = Ephemeris::position(Body::Sun, $jdTT);

        return new Phenomenon(
            body: $body,
            phaseAngle: $alpha,
            // The part of the visible disc that is lit: (1 + cos α) / 2.
            illuminatedFraction: (1.0 + cos(deg2rad($alpha))) / 2.0,
            elongation: self::apparentSeparation($position, $sun),
            apparentDiameter: self::diameter($body, $delta),
            /* For a body downloaded from the JPL there is no brightness model, and the magnitude
               goes to null instead of to a number: `Magnitudes` is fitted body by body against the
               JPL itself, and none of those fits is good for just any asteroid. The same as already
               happens with Uranus and Neptune outside the range of phase angles they were fitted
               with. */
            magnitude: $body instanceof Body
                ? Magnitudes::of($body, $r, $delta, $alpha, self::ringOpening($body, $position, $jdTT))
                : null,
        );
    }

    /**
     * How open Saturn's ring is seen, in degrees: the observer's latitude as seen from the planet.
     * Null for every other body, which has no ring to see.
     *
     * It is the angle between the Saturn-Earth direction and the planet's equator, that is the sine
     * of the dot product of the pole by that direction. The pole stands still in space and the
     * ecliptic of date turns**, so the direction is taken to J2000 before multiplying it: doing it
     * in the ecliptic of date, the pole would drift a degree in a century and the ring opening with
     * it.
     *
     * Every fifteen years the ring turns edge-on and disappears, and between that and the fully
     * open ring there is almost a whole magnitude of difference in Saturn's brightness.
     *
     * @param Body|DownloadableBody $body
     * @param Position $position
     * @param float $jdTT
     * @return float|null
     */
    private static function ringOpening(Body $body, Position $position, float $jdTT): ?float
    {
        $pole = Magnitudes::pole($body);

        if ($pole === null) {
            return null;
        }

        $lambda = deg2rad($position->longitude);
        $beta = deg2rad($position->latitude);

        $u = Precession::toJ2000(
            [cos($beta) * cos($lambda), cos($beta) * sin($lambda), sin($beta)],
            ($jdTT - 2451545.0) / 36525.0
        );

        $sine = -($pole[0] * $u[0] + $pole[1] * $u[1] + $pole[2] * $u[2]);

        return rad2deg(asin(max(-1.0, min(1.0, $sine))));
    }

    /**
     * The elongation measured from 0 to 360 in longitude, from the Sun to the body, which is what
     * tells whether it is waxing or waning. See `Phenomenon::isWaxing`.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return float
     */
    public static function orientedElongation(Body|DownloadableBody $body, float $jdTT): float
    {
        $bodyLongitude = Ephemeris::apparentLongitude($body, $jdTT);
        $sunLongitude = Ephemeris::apparentLongitude(Body::Sun, $jdTT);

        return fmod($bodyLongitude - $sunLongitude + 360.0, 360.0);
    }

    /**
     * The apparent diameter of the disc, in degrees.
     *
     * It goes with an arcsine and not with the linear approximation because that is the one that
     * truly corresponds to a sphere seen from outside: what is seen is the tangent cone, not the
     * projected diameter. The difference only reaches hundredths of an arcsecond on the Moon, but
     * writing the right one costs no more.
     *
     * With the EQUATORIAL radius and not with `radiusKm`, which is the mean one: the giants are
     * flattened and on Saturn that is three and a half per cent of the disc's width. It came out of
     * comparing with Horizons, which gives the equatorial width like everyone else.
     *
     * @param Body|DownloadableBody $body
     * @param float $distance In astronomical units.
     * @return float
     */
    private static function diameter(Body|DownloadableBody $body, float $distance): float
    {
        // For a downloaded body the radius is not known, so no disc is invented for it: zero, which
        // is what those without a written radius already return, and here it is almost true besides.
        $radius = $body instanceof Body ? $body->equatorialRadiusKm() : 0.0;

        if ($radius <= 0.0 || $distance <= 0.0) {
            return 0.0;
        }

        $sine = $radius / ($distance * Equatorial::AU_KM);

        return $sine >= 1.0 ? 180.0 : 2.0 * rad2deg(asin($sine));
    }

    /**
     * Apparent angular separation between two positions, on the sphere.
     *
     * It is not the difference of longitudes: the latitude counts, and on the Moon that is five
     * degrees and on Pluto seventeen. It is done with the cosine of the angle between the two
     * directions.
     *
     * @param Position $a
     * @param Position $b
     * @return float
     */
    private static function apparentSeparation(Position $a, Position $b): float
    {
        $la = deg2rad($a->latitude);
        $lb = deg2rad($b->latitude);
        $dl = deg2rad($a->longitude - $b->longitude);

        $cosine = sin($la) * sin($lb) + cos($la) * cos($lb) * cos($dl);

        return rad2deg(acos(max(-1.0, min(1.0, $cosine))));
    }
}
