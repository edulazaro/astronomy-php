<?php

namespace Astronomy;

/**
 * Occultations of planets and stars by the Moon.
 *
 * It is what `swe_lun_occult_when_glob` and `swe_lun_occult_when_loc` do, and here it is
 * the same computation as the solar eclipses with the Sun swapped for another body: the
 * shadow that the Moon casts towards the Earth covering something, and whether that shadow
 * touches the surface. That is why the geometry (`Eclipses::shadow()`) and the local
 * circumstances (`Eclipses::localOccultation()`) are not repeated: they are called.
 *
 * What changes is where to look. An eclipse can only happen at new Moon; an occultation,
 * at every conjunction of the Moon with the body, which for a planet is once a month and on
 * a date that depends on the planet. The conjunction in ecliptic longitude is found by
 * secant and the shadow is built there.
 *
 * A star from the catalogue comes in as a `Star`, and `Horizon::track()` turns it into
 * its apparent direction of date, the same as in `RiseSet`. It carries neither parallax nor
 * disc, and the geometry knows it because its distance is null and its radius zero. A
 * callable returning an `Equatorial` serves for whatever is not in the catalogue.
 *
 * **A star far from the ecliptic is discarded before computing anything.** The Moon never
 * gets farther than 5.3 degrees from the ecliptic, and to be covered from somewhere on
 * Earth the star has to be within the lunar parallax (1.0) plus the semidiameter
 * (0.28) of its centre: 6.6 degrees. Vega is at 61. Without that discard, looking for its
 * occultations over ten years is one hundred and thirty conjunctions built for nothing, and
 * with it it is one position. Swiss uses the same threshold of 7 degrees.
 */
class Occultations
{
    /** Mean rate of the Moon with respect to a slow body, in degrees per day. */
    private const MEAN_RATE = 13.0;

    /**
     * Ecliptic latitude, in degrees, beyond which the Moon never reaches a star. It is the
     * 5.3 of the Moon plus the 1.3 of parallax and semidiameter, rounded up as Swiss does;
     * proper motion does not cross the strip in centuries.
     */
    private const MAXIMUM_LATITUDE = 7.0;

    /**
     * The occultations of a body or a star between two instants, in order.
     *
     * @param Body|Star|callable(float): Equatorial $object
     * @param float $jdUtFrom
     * @param float $jdUtTo
     * @return list<Occultation>
     */
    public static function between(Body|Star|callable $object, float $jdUtFrom, float $jdUtTo): array
    {
        if (self::outOfReach($object, $jdUtFrom)) {
            return [];
        }

        $occultations = [];
        $t = Time::tt($jdUtFrom);

        while ($t <= Time::tt($jdUtTo)) {
            $conjunction = self::conjunction($object, $t);

            if ($conjunction > Time::tt($jdUtTo)) {
                break;
            }

            $occultation = self::at($object, $conjunction);

            if ($occultation !== null && $occultation->maximum->jdUt >= $jdUtFrom && $occultation->maximum->jdUt <= $jdUtTo) {
                $occultations[] = $occultation;
            }

            // On to the next conjunction, which is a month away: twenty days place it
            // without any risk of falling back on the same one.
            $t = $conjunction + 20.0;
        }

        return $occultations;
    }

    /**
     * The next occultation of a body or a star after an instant, or null if there is none
     * within the span.
     *
     * @param Body|Star|callable(float): Equatorial $object
     * @param float $jdUtFrom
     * @param int $months How many conjunctions to look at, at most.
     * @return Occultation|null
     */
    public static function next(Body|Star|callable $object, float $jdUtFrom, int $months = 36): ?Occultation
    {
        if (self::outOfReach($object, $jdUtFrom)) {
            return null;
        }

        $t = Time::tt($jdUtFrom);

        for ($i = 0; $i < $months; $i++) {
            $conjunction = self::conjunction($object, $t);
            $occultation = self::at($object, $conjunction);

            if ($occultation !== null && $occultation->maximum->jdUt > $jdUtFrom) {
                return $occultation;
            }

            $t = $conjunction + 20.0;
        }

        return null;
    }

    /**
     * What is seen of an occultation from one place: disappearance, reappearance and
     * whether the Moon is above the horizon when it happens. Null if from there there is no
     * occultation or it is not seen.
     *
     * @param Occultation $occultation
     * @param Place $place
     * @param float $heightMetres
     * @return LocalCircumstances|null
     */
    public static function local(Occultation $occultation, Place $place, float $heightMetres = 0.0): ?LocalCircumstances
    {
        return Eclipses::localOccultation($occultation->target, $occultation->maximum->jdUt, $place, $heightMetres);
    }

    /**
     * Whether it is a star that the Moon never reaches, by latitude. A planet is not
     * discarded this way: its latitude changes, and Pluto's reaches seventeen degrees on
     * one stretch of the orbit and drops to zero on another.
     *
     * @param Body|Star|callable $object
     * @param float $jdUt
     * @return bool
     */
    private static function outOfReach(Body|Star|callable $object, float $jdUt): bool
    {
        return $object instanceof Star
            && abs(Stars::position($object, Time::tt($jdUt))->latitude) > self::MAXIMUM_LATITUDE;
    }

    /**
     * An occultation at the conjunction that falls on an instant, or null if the Moon's
     * shadow does not reach the Earth.
     *
     * @param Body|Star|callable $object
     * @param float $jdTT Instant of the conjunction in longitude.
     * @return Occultation|null
     */
    private static function at(Body|Star|callable $object, float $jdTT): ?Occultation
    {
        $track = Horizon::track($object);
        $radius = Horizon::radiusKm($object);

        $shadowAt = function (float $t) use ($track, $radius): array {
            $body = $track($t);

            // A star has no distance: it is put at one light year, which for the geometry
            // of the shadow is the same as infinity and does not divide by zero.
            $vector = $body->distanceKm === null
                ? array_map(fn (float $c) => $c * 9.46e12, $body->unit())
                : $body->vector();

            return Eclipses::shadow($vector, $radius, Horizon::equatorialOf(Body::Moon, $t)->vector());
        };

        [$tMaximum, , $curvature] = Eclipses::minimum(fn (float $t) => $shadowAt($t)['r0'], $jdTT);
        $atMaximum = $shadowAt($tMaximum);

        if (! $atMaximum['penumbra']) {
            return null;
        }

        $a = Horizon::EQUATORIAL_RADIUS_KM;

        [$start, $end] = Eclipses::contacts(
            function (float $t) use ($shadowAt, $a): float {
                $shadow = $shadowAt($t);

                return $shadow['r0'] - ($a * $shadow['cosf2'] + $shadow['D0'] / 2);
            },
            $tMaximum, $atMaximum['r0'], $curvature, $a * $atMaximum['cosf2'] + $atMaximum['D0'] / 2
        );

        $jdUtMaximum = Time::ut($tMaximum);
        [$latitude, $longitude] = Eclipses::geographicCoordinates($atMaximum['surface'], $jdUtMaximum);

        $body = $track($tMaximum);
        $moon = Horizon::equatorialOf(Body::Moon, $tMaximum);

        $instant = fn (?float $t): ?UtInstant => $t === null ? null : UtInstant::fromJd(Time::ut($t));

        return new Occultation(
            name: Horizon::nameOf($object),
            target: $object instanceof Body || $object instanceof Star ? $object : $track,
            type: $atMaximum['umbra'] ? EclipseType::Total : EclipseType::Partial,
            central: $atMaximum['central'],
            maximum: UtInstant::fromJd($jdUtMaximum),
            gamma: $atMaximum['gamma'],
            minimumSeparation: $body->separation($moon),
            start: $instant($start),
            end: $instant($end),
            maximumLatitude: $latitude,
            maximumLongitude: $longitude,
        );
    }

    /**
     * The next conjunction in ecliptic longitude of the Moon with the body, by secant.
     *
     * It starts from the mean rate of the Moon and corrects with how much the difference of
     * longitudes has really moved between two evaluations. Three or four steps leave the
     * conjunction below the minute, which is more than enough: the one that nails the
     * maximum is the search for the shadow, and this one only has to leave it close by.
     *
     * @param Body|Star|callable $object
     * @param float $jdTT
     * @return float
     */
    private static function conjunction(Body|Star|callable $object, float $jdTT): float
    {
        $track = Horizon::track($object);

        $difference = function (float $t) use ($track): float {
            $moon = Ephemeris::position(Body::Moon, $t)->longitude;

            return fmod(self::eclipticLongitude($track($t), $t) - $moon + 720.0, 360.0);
        };

        // The first difference is taken from zero to 360: what the Moon still needs to
        // catch up with the body going forwards. The following ones, already close, are
        // folded.
        $t0 = $jdTT;
        $y0 = $difference($t0);
        $t1 = $t0 + $y0 / self::MEAN_RATE;
        $y1 = self::wrap($difference($t1));

        for ($i = 0; $i < 10 && abs($y1) > 0.01 && $y1 !== $y0; $i++) {
            $t2 = $t1 - $y1 * ($t1 - $t0) / ($y1 - $y0);
            [$t0, $y0] = [$t1, $y1];
            [$t1, $y1] = [$t2, self::wrap($difference($t2))];
        }

        return $t1;
    }

    /**
     * Ecliptic longitude of an equatorial direction, in degrees.
     *
     * @param Equatorial $coordinate
     * @param float $jdTT
     * @return float
     */
    private static function eclipticLongitude(Equatorial $coordinate, float $jdTT): float
    {
        $eps = Time::trueObliquity(Time::centuries($jdTT));
        $a = deg2rad($coordinate->rightAscension);
        $d = deg2rad($coordinate->declination);

        return fmod(rad2deg(atan2(sin($a) * cos($eps) + tan($d) * sin($eps), cos($a))) + 360.0, 360.0);
    }

    /**
     * @param float $degrees
     * @return float
     */
    private static function wrap(float $degrees): float
    {
        return fmod($degrees + 540.0, 360.0) - 180.0;
    }

}
