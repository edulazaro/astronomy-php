<?php

namespace Astronomy;

/**
 * The Moon, by ELP 2000-82B.
 *
 * It is kept apart from the planets because it is nothing like them. A planet is solved
 * with six series around an ellipse; the Moon needs thirty-seven thousand terms because
 * it is deformed at the same time by the Sun, the flattening of the Earth, the other
 * planets, the tides and relativity, and none of those perturbations is negligible at the
 * precision that is needed.
 *
 * And it is kept apart for another reason, which is the one that really matters here:
 * **annual aberration is not applied to the Moon**. Aberration tilts the direction of the
 * light because the observer moves, and the Moon moves with us: it travels in the same
 * orbit around the Sun. Applying it to the Moon as if it were a planet shifts it twenty
 * arcseconds. What it does carry is the light delay, which is a little over a second but
 * in that time the Moon advances seven tenths of an arcsecond.
 *
 * The series are evaluated in the mean ecliptic of date, which is the system they are
 * written in and the same one VSOP87 works in. The original subroutine ends by rotating
 * them to J2000; that rotation is not done here, because we keep them at the date.
 */
class Moon
{
    /** Days light takes to travel one astronomical unit. */
    private const LIGHT_PER_AU = 0.005775518331;

    /** @var array<string, array<int, list<array{float, float, float, float, float, float}>>>|null */
    private static ?array $series = null;

    /** @var array{w1: list<float>, rad: float, distance_scale: float}|null */
    private static ?array $constants = null;

    /**
     * Geocentric rectangular position in the mean ecliptic of date, in AU, with the light
     * delay already subtracted.
     *
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    public static function geocentricRectangular(float $jdTT): array
    {
        [, , $distance] = self::spherical($jdTT);

        // A single pass is enough: in the second and a bit that the light takes, the
        // Earth-Moon distance does not change enough to move the delay.
        $delay = self::LIGHT_PER_AU * $distance;

        [$longitude, $latitude, $distance] = self::spherical($jdTT - $delay);

        return [
            $distance * cos($latitude) * cos($longitude),
            $distance * cos($latitude) * sin($longitude),
            $distance * sin($latitude),
        ];
    }

    /**
     * Longitude and latitude in radians and distance in AU, geometric.
     *
     * @param float $jdTT
     * @return array{0: float, 1: float, 2: float}
     */
    public static function spherical(float $jdTT): array
    {
        $constants = self::constants();
        $t = Time::centuries($jdTT);

        $sumL = self::evaluateSeries('L', $t);
        $sumB = self::evaluateSeries('B', $t);
        $sumR = self::evaluateSeries('R', $t);

        // The longitude is the mean longitude plus what the series contribute. The series
        // on their own are the correction, not the position.
        $longitude = self::polynomial($constants['w1'], $t) + $sumL / $constants['rad'];

        /* And here the general precession, which is the step that shows up nowhere until
           you compare against a real ephemeris.

           ELP measures longitudes from the INERTIAL equinox, which does not move. A birth
           chart wants them from the equinox of date, which does move: it goes back fifty
           arcseconds a year. Without adding that, the Moon comes out with an error of a
           degree and a half in 1900, of zero right at 2000 and of three quarters of a
           degree in 2050. That is: perfect in the year you happen to test it and wrong in
           every other one.

           The final rotation of the original subroutine does this same thing by another
           route, taking the result to J2000. Here we stay at the date, which is where the
           rest of the engine works. */
        // With the quadratic term, not just the constant: see Time::generalPrecession.
        $longitude += Time::generalPrecession($t) / $constants['rad'];
        $latitude = $sumB / $constants['rad'];
        // ELP gives the distance in kilometres and the engine works in AU: the constant is
        // the one in `Equatorial`, which is where the kilometres live, and not a copy here.
        $distance = $sumR * $constants['distance_scale'] / Equatorial::AU_KM;

        $fullTurn = 2 * M_PI;

        return [fmod(fmod($longitude, $fullTurn) + $fullTurn, $fullTurn), $latitude, $distance];
    }

    /**
     * @param string $variable
     * @param float $t
     * @return float
     */
    private static function evaluateSeries(string $variable, float $t): float
    {
        $total = 0.0;

        foreach (self::series($variable) as $power => $terms) {
            $sum = 0.0;

            foreach ($terms as [$amplitude, $c0, $c1, $c2, $c3, $c4]) {
                $sum += $amplitude * sin($c0 + $t * ($c1 + $t * ($c2 + $t * ($c3 + $t * $c4))));
            }

            $total += $sum * $t ** $power;
        }

        return $total;
    }

    /**
     * @param string $variable
     * @return array<int, list<array{float, float, float, float, float, float}>>
     */
    private static function series(string $variable): array
    {
        self::$series[$variable] ??= require DataFolder::path("elp2000/{$variable}.php");

        return self::$series[$variable];
    }

    /**
     * @return array{w1: list<float>, rad: float, distance_scale: float}
     */
    private static function constants(): array
    {
        return self::$constants ??= require DataFolder::path('elp2000/constants.php');
    }

    /**
     * @param list<float> $coefficients
     * @param float $t
     * @return float
     */
    private static function polynomial(array $coefficients, float $t): float
    {
        $value = 0.0;

        foreach (array_reverse($coefficients) as $coefficient) {
            $value = $value * $t + $coefficient;
        }

        return $value;
    }
}
