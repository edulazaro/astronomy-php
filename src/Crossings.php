<?php

namespace Astronomy;

use Closure;
use InvalidArgumentException;

/**
 * When a body reaches a longitude.
 *
 * This is Swiss's `swe_solcross`, `swe_mooncross`, `swe_mooncross_node` and
 * `swe_helio_cross`, and it is the question that holds up half of the astrology of time: a
 * sign ingress, a lunation, a solar return, an exact transit and the Moon's passage through
 * its node are the same computation with a different body and a different target.
 *
 * **It exists above all because it was written three times and none of them could be called
 * from outside.** `MoonPhase` solved it with regula falsi on the elongation,
 * `SolarReturn` with a six-hour sweep and sixty bisections, and `Transits` with thirty
 * bisections on the longitude. All three answered correctly and none of them was any use for
 * asking "when does Saturn enter Pisces", which is the same question.
 *
 * ## The two traps of looking for a longitude crossing
 *
 * **The folded deviation jumps 360 degrees at the opposite point, and that jump looks like a
 * crossing.** The longitude is compared against the target folded to [−180, 180) so that it
 * passes through zero at the crossing; the price is that on going past the antipode the
 * deviation jumps from +179 to −179, which is a sign change with every appearance of being
 * the crossing being looked for, and on top of that it falls half a zodiac away. That is why
 * an interval is only valid if the deviation has changed by less than half a turn.
 *
 * **A body can cross the same longitude three times**, because it retrogrades: it goes past,
 * comes back and goes past again. That forces the sweep step to be short enough that two
 * crossings cannot fit inside a single one, and there the number that rules is not the speed
 * of the body but **the width of its retrograde arc**, which is 2.8 degrees for Neptune and
 * 16 for Venus. At half a degree per step there is no room for a there and back, and the
 * sweep cannot skip a pair.
 *
 * The Sun and the Moon never retrograde as seen from here (the Moon drops to 11.7 degrees per
 * day and does not cross zero), so those two are allowed a step twenty times longer. It is
 * not a small optimization: with the fine step, looking for a lunation would cost eight
 * hundred Moon ephemerides instead of forty.
 *
 * The step comes from the speed measured between the last two samples, not from a table of
 * mean motions: a table is one more place to get it wrong and here the speed already comes
 * with the sweep.
 */
class Crossings
{
    /** Degrees the Sun and the Moon are allowed to advance in each step of the sweep. */
    private const DIRECT_MARGIN = 10.0;

    /**
     * Degrees per step for everything that can retrograde. It is half of the narrowest
     * retrograde arc there is (Neptune, 2.8 degrees there and back), so a crossing on the way
     * out and one on the way back cannot both fit inside one step.
     */
    private const RETROGRADE_MARGIN = 0.5;

    private const MIN_STEP = 1e-3;

    private const MAX_STEP = 400.0;

    /**
     * Cap on the step, in days, while the body is within one margin of the target.
     *
     * **It is what keeps a GRAZE from being missed, and the margin in degrees is not enough
     * for that.** A body can cross a boundary, poke a couple of arcminutes past it and come
     * back: those are two real crossings, and between them the body barely moves, so the step
     * derived from the distance covered shoots up and both fit inside a single one. The
     * retrograde arc reasoning, which is written above, does not cover this case: there what
     * bounds things is how far the body MOVES AWAY from the boundary, and that can be as
     * little as you like.
     *
     * So the rule is one of position and not of speed: **while the body is within one margin
     * of the boundary, it is looked at every five days at most**, whether it is moving fast or
     * not. That catches any excursion lasting more than ten days, which for Pluto near a
     * station is five thousandths of a degree deep, that is 19 arcseconds.
     *
     * Measured: Pluto was missing TWO pairs of crossings in its three-hundred-year window, the
     * one of June 2066 (24 days outside, 1.6 arcminutes) and the one of April 2185 (21 days,
     * 1.4 arcminutes). Both exist, checked with a half-day brute-force sweep. With this rule
     * all 66 come out, and Pluto's whole window goes from 0.46 to 0.82 seconds.
     */
    private const NEAR_STEP = 5.0;

    /** Cap on the samples of a sweep, so that an impossible search comes to an end. */
    private const MAX_SAMPLES = 20000;

    /**
     * The instant at which the apparent ecliptic longitude of a body is exactly the one asked
     * for, searching from `$jdTT` forwards (`$direction` 1) or backwards (`$direction` −1).
     *
     * It returns the FIRST crossing it finds, which with a retrograde body is not necessarily
     * the one you had in mind: the three passes of Mars over the same degree are three
     * different crossings and this one gives the nearest.
     *
     * @param Body|DownloadableBody $body
     * @param float $longitude Degrees.
     * @param float $jdTT Where the search starts from, in Terrestrial Time.
     * @param float $direction 1 towards the future, −1 towards the past.
     * @param float|null $limit Days of search at most; by default, however long the body takes
     *                          to go once round the long way.
     * @param Ayanamsa|CustomAyanamsa|null $ayanamsa To search over sidereal longitudes.
     * @return float|null The Julian day TT of the crossing, or null if there is none in the window.
     */
    public static function ofLongitude(
        Body|DownloadableBody $body,
        float $longitude,
        float $jdTT,
        float $direction = 1.0,
        ?float $limit = null,
        Ayanamsa|CustomAyanamsa|null $ayanamsa = null,
    ): ?float {
        return self::search(
            self::longitudeOf($body, $ayanamsa),
            $longitude,
            $jdTT,
            $direction,
            $limit ?? self::window($body),
            self::margin($body),
            self::canRetrograde($body),
        );
    }

    /**
     * When the Sun reaches a longitude. This is `swe_solcross`.
     *
     * @param float $longitude
     * @param float $jdTT
     * @param float $direction
     * @param float|null $limit
     * @param Ayanamsa|CustomAyanamsa|null $ayanamsa
     * @return float|null
     */
    public static function ofTheSun(
        float $longitude,
        float $jdTT,
        float $direction = 1.0,
        ?float $limit = null,
        Ayanamsa|CustomAyanamsa|null $ayanamsa = null,
    ): ?float {
        return self::ofLongitude(Body::Sun, $longitude, $jdTT, $direction, $limit ?? 400.0, $ayanamsa);
    }

    /**
     * When the Moon reaches a longitude. This is `swe_mooncross`.
     *
     * @param float $longitude
     * @param float $jdTT
     * @param float $direction
     * @param float|null $limit
     * @param Ayanamsa|CustomAyanamsa|null $ayanamsa
     * @return float|null
     */
    public static function ofTheMoon(
        float $longitude,
        float $jdTT,
        float $direction = 1.0,
        ?float $limit = null,
        Ayanamsa|CustomAyanamsa|null $ayanamsa = null,
    ): ?float {
        return self::ofLongitude(Body::Moon, $longitude, $jdTT, $direction, $limit ?? 40.0, $ayanamsa);
    }

    /**
     * When the Moon crosses the ecliptic, that is when it passes through one of its nodes.
     * This is `swe_mooncross_node`.
     *
     * **It is not a longitude crossing but a LATITUDE one**, and that is why it does not go
     * through `ofLongitude`: what has to be zero is the Moon's ecliptic latitude, not its
     * longitude. The longitude returned is where the Moon was as it crossed, which is that of
     * the true node at that instant.
     *
     * @param float $jdTT
     * @param float $direction
     * @return array{jd: float, longitud: float, norte: bool}|null `norte` says whether it is going up.
     */
    public static function throughLunarNode(float $jdTT, float $direction = 1.0): ?array
    {
        $latitude = fn (float $jd): float => Ephemeris::position(Body::Moon, $jd)->latitude;

        /* The Moon's latitude is a wave of 27.2 days and five degrees of amplitude, so with a
           half-day step there are some fifty samples per turn and no pair of zeros fits inside
           one step: thirteen and a half days go by between two nodes. */
        $step = 0.5 * $direction;
        $previous = $jdTT;
        $previousValue = $latitude($jdTT);

        for ($i = 1; $i <= 60; $i++) {
            $jd = $jdTT + $i * $step;
            $value = $latitude($jd);

            if (($previousValue < 0.0) !== ($value < 0.0)) {
                [$from, $to] = $direction > 0 ? [$previous, $jd] : [$jd, $previous];

                $crossing = self::root(
                    fn (float $x): float => $latitude($x),
                    $from,
                    $to,
                );

                return [
                    'jd' => $crossing,
                    'longitude' => Ephemeris::apparentLongitude(Body::Moon, $crossing),
                    // It goes up if just afterwards it is north of the ecliptic.
                    'north' => $latitude($crossing + 0.01) > 0.0,
                ];
            }

            $previous = $jd;
            $previousValue = $value;
        }

        return null;
    }

    /**
     * When a body reaches a longitude seen FROM THE SUN. This is `swe_helio_cross`.
     *
     * The heliocentric longitude never retrogrades (a planet runs its orbit in one direction),
     * so here the fine step is not needed and the sweep goes with the wide margin.
     *
     * @param Body|DownloadableBody $body
     * @param float $longitude
     * @param float $jdTT
     * @param float $direction
     * @param float|null $limit
     * @return float|null
     */
    public static function heliocentric(
        Body|DownloadableBody $body,
        float $longitude,
        float $jdTT,
        float $direction = 1.0,
        ?float $limit = null,
    ): ?float {
        return self::search(
            fn (float $jd): float => Ephemeris::heliocentric($body, $jd)->longitude,
            $longitude,
            $jdTT,
            $direction,
            /* The HELIOCENTRIC longitude never retrogrades: retrogradation is an effect of
               looking from an Earth that moves. So neither short step nor watch. */
            $limit ?? self::window($body),
            self::DIRECT_MARGIN,
            false,
        );
    }

    /**
     * When a body enters a sign, that is when it crosses its zero degree.
     *
     * @param Body|DownloadableBody $body
     * @param Sign $sign
     * @param float $jdTT
     * @param float $direction
     * @param Ayanamsa|CustomAyanamsa|null $ayanamsa
     * @return float|null
     */
    public static function ingressInto(
        Body|DownloadableBody $body,
        Sign $sign,
        float $jdTT,
        float $direction = 1.0,
        Ayanamsa|CustomAyanamsa|null $ayanamsa = null,
    ): ?float {
        $degree = array_search($sign, Sign::cases(), true) * 30.0;

        return self::ofLongitude($body, (float) $degree, $jdTT, $direction, null, $ayanamsa);
    }

    /**
     * Every sign ingress of a body within a window, in order.
     *
     * @param Body|DownloadableBody $body
     * @param float $from
     * @param float $to Both in Terrestrial Time.
     * @param Ayanamsa|CustomAyanamsa|null $ayanamsa
     * @return list<array{jd: float, signo: Sign, retrogrado: bool}>
     */
    public static function ingresses(
        Body|DownloadableBody $body,
        float $from,
        float $to,
        Ayanamsa|CustomAyanamsa|null $ayanamsa = null,
    ): array {
        if ($to <= $from) {
            throw new InvalidArgumentException('The ingress window runs from lower to higher.');
        }

        $longitude = self::longitudeOf($body, $ayanamsa);
        $margin = self::margin($body);
        $watch = self::canRetrograde($body);
        $ingresses = [];

        /* **One single pass, not twelve.** The first thing written here looked for the next
           crossing of each of the twelve zero degrees and kept the nearest one, that is it ran
           through the whole window twelve times for every ingress found: twelve ingresses of
           the Sun in a year cost 783 milliseconds. And there is no need to ask about twelve
           targets, because **changing sign is changing thirty-degree block**: it is enough to
           look at which block the longitude falls in and see when that number changes. With
           that it is 57 milliseconds.

           This leans on the sweep step letting whatever retrogrades advance half a degree at
           most, so a whole sign boundary does not fit inside one step, let alone two. */
        $previous = $from;
        $previousLongitude = $longitude($from);
        $previousBlock = (int) floor($previousLongitude / 30.0);
        $step = self::MIN_STEP;

        for ($i = 0; $i < self::MAX_SAMPLES && $previous < $to; $i++) {
            $jd = min($previous + $step, $to);
            $current = $longitude($jd);
            $currentBlock = (int) floor($current / 30.0);

            if ($currentBlock !== $previousBlock) {
                /* Two different things that are easy to confuse, and confusing them cost a
                   bug: the BOUNDARY that is crossed and the SIGN that is entered.

                   The boundary is the zero degree of the arriving block going forwards, and
                   that of the leaving block when retrograding, because on going backwards the
                   sign it was in is left through the bottom. That is what is needed to look
                   for the root.

                   But the sign that is ENTERED is always the destination one, whichever way it
                   goes. Naming the ingress with the sign of the boundary, a planet retrograding
                   from Scorpio to Libra said "enters Scorpio", which is the sign it is leaving.
                   **Pluto gave its three crossings of 1983 and 1984 all three as Scorpio.** It
                   gave no error: it gave a good-looking sign name. */
                $forward = (fmod($current - $previousLongitude + 540.0, 360.0) - 180.0) > 0.0;
                $boundary = ($forward ? $currentBlock : $previousBlock) * 30.0;

                $crossing = self::root(
                    fn (float $x): float => fmod($longitude($x) - $boundary + 540.0, 360.0) - 180.0,
                    $previous,
                    $jd
                );

                $ingresses[] = [
                    'jd' => $crossing,
                    'sign' => Sign::cases()[$currentBlock % 12],
                    'retrograde' => ! $forward,
                ];
            }

            $step = self::nextStep(
                $step,
                $current - $previousLongitude,
                $margin,
                $watch && self::toBoundary($current) < $margin
            );

            $previous = $jd;
            $previousLongitude = $current;
            $previousBlock = $currentBlock;
        }

        return $ingresses;
    }

    /**
     * The zero of a function inside an interval that already has it cornered.
     *
     * **Regula falsi with the Illinois correction, not bisection.** It is what `MoonPhase`
     * already did and it is the best of the three implementations there were: the functions
     * here are almost straight over the interval (a body covers degrees per day with
     * hundredths of curvature), so the secant nails the zero in four or five evaluations where
     * bisection needs thirty or sixty. The Illinois correction, which halves the endpoint that
     * stays put, avoids the case in which the secant sticks to one side and stops converging;
     * and the bracket is always kept, so it cannot leave the interval.
     *
     * Every evaluation is an ephemeris, and in the milestones of a life this is called
     * hundreds of times: the difference between five and thirty shows on the page.
     *
     * **And the bracket is only returned if it has shrunk.** With regula falsi one endpoint can
     * stay put very far away while the other sticks to the zero, so the midpoint of the
     * bracket is NOT the zero: it is halfway between the root and an endpoint that never moved.
     * That was the bug in the version that was in `MoonPhase`, which left the loop looking at
     * the value of the function and returned the mean anyway: measured, it left three out of
     * every five lunations up to 0.35 arcseconds of elongation off, that is 0.68 seconds of
     * clock, looking perfectly fine. If the loop runs out without closing the bracket, the best
     * evaluation seen is returned, which is the only one known to be good.
     *
     * @param Closure $function It has to change sign between the two endpoints.
     * @param float $from
     * @param float $to
     * @param float $tolerance In days.
     * @return float
     */
    public static function root(Closure $function, float $from, float $to, float $tolerance = 1e-9): float
    {
        $fa = $function($from);
        $fb = $function($to);

        if ($fa == 0.0) {
            return $from;
        }

        if ($fb == 0.0) {
            return $to;
        }

        if (($fa < 0.0) === ($fb < 0.0)) {
            throw new InvalidArgumentException('The interval does not bracket any zero.');
        }

        $side = 0;
        $best = abs($fa) < abs($fb) ? $from : $to;
        $bestValue = min(abs($fa), abs($fb));

        for ($i = 0; $i < 60 && ($to - $from) > $tolerance; $i++) {
            $mid = $to - $fb * ($to - $from) / ($fb - $fa);

            // The secant can land on the endpoint through rounding, and then the interval
            // stops shrinking and the loop runs out without getting anywhere.
            if ($mid <= $from || $mid >= $to) {
                $mid = ($from + $to) / 2.0;
            }

            $fm = $function($mid);

            if ($fm == 0.0) {
                return $mid;
            }

            if (abs($fm) < $bestValue) {
                $best = $mid;
                $bestValue = abs($fm);
            }

            if (($fm < 0.0) === ($fa < 0.0)) {
                $from = $mid;
                $fa = $fm;

                if ($side === -1) {
                    $fb /= 2.0;
                }

                $side = -1;
            } else {
                $to = $mid;
                $fb = $fm;

                if ($side === 1) {
                    $fa /= 2.0;
                }

                $side = 1;
            }
        }

        return ($to - $from) <= $tolerance ? ($from + $to) / 2.0 : $best;
    }

    /**
     * The sweep: it samples until it finds an interval where the deviation changes sign, and
     * then refines.
     *
     * @param Closure $longitude
     * @param float $target
     * @param float $jdTT
     * @param float $direction
     * @param float $limit
     * @param float $margin Degrees the body is allowed to advance per step.
     * @param bool $watch Whether to keep an eye on the neighbourhood of the target.
     * @return float|null
     */
    private static function search(
        Closure $longitude,
        float $target,
        float $jdTT,
        float $direction,
        float $limit,
        float $margin,
        bool $watch,
    ): ?float {
        $deviation = fn (float $jd): float => fmod($longitude($jd) - $target + 540.0, 360.0) - 180.0;

        $direction = $direction >= 0.0 ? 1.0 : -1.0;
        $previous = $jdTT;
        $previousValue = $deviation($jdTT);

        // The starting instant counts as a crossing backwards, not forwards, the same as in
        // lunations: the previous one can be right now, the next one cannot.
        if ($previousValue == 0.0 && $direction < 0.0) {
            return $jdTT;
        }

        $step = self::MIN_STEP;
        $travelled = 0.0;

        for ($i = 0; $i < self::MAX_SAMPLES && $travelled < $limit; $i++) {
            $step = min($step, $limit - $travelled);
            $jd = $previous + $direction * $step;
            $value = $deviation($jd);
            $travelled += $step;

            /* Two conditions, and both are needed. The sign change says there is a crossing in
               the interval; the deviation having moved less than half a turn says that this
               sign change is the crossing and not the jump of the folding on going past the
               opposite point, which goes from +179 to −179 and looks exactly the same. */
            if (($previousValue < 0.0) !== ($value < 0.0) && abs($value - $previousValue) < 180.0) {
                [$a, $b] = $direction > 0 ? [$previous, $jd] : [$jd, $previous];

                return self::root($deviation, $a, $b);
            }

            /* The next step comes from what the body has covered in this one, so that it
               always advances the same arc: fast when it is moving quickly and long when it is
               dragging itself along. The speed is folded because the deviation may have
               crossed the antipode. */
            $step = self::nextStep($step, $value - $previousValue, $margin, $watch && abs($value) < $margin);

            $previous = $jd;
            $previousValue = $value;
        }

        return null;
    }

    /**
     * How much to advance in the next step, so that the body always covers the same arc.
     *
     * **And the step can do no more than double at a time, which is what it cost to find.**
     * The natural rule is "if in this step it has covered half of what it should, double the
     * step", and that breaks exactly where it hurts most: at a STATION a planet stops, so what
     * it has covered tends to zero and the rule orders a step of hundreds of days. With that
     * the sweep jumps over the whole retrogradation and misses the two crossings that only
     * exist there, which are precisely the interesting ones. It was caught by the test that
     * requires Mars to enter a sign backwards in 2018: it was finding zero retrograde ingresses
     * and gave no error at all.
     *
     * @param float $step The one just taken, in days.
     * @param float $travelled How far the body has moved, in degrees and unfolded.
     * @param float $margin Degrees it is allowed to move per step.
     * @param bool $near Whether the body is within one margin of the target.
     * @return float
     */
    private static function nextStep(float $step, float $travelled, float $margin, bool $near): float
    {
        $moved = abs(fmod($travelled + 540.0, 360.0) - 180.0);

        $next = $moved > 1e-12 ? $step * $margin / $moved : $step * 2.0;

        // Near the target the clock rules and not what has been covered, which is what saves
        // the grazes.
        return min(max($next, self::MIN_STEP), $step * 2.0, $near ? self::NEAR_STEP : self::MAX_STEP);
    }

    /**
     * Whether a graze can escape this body, that is whether it retrogrades.
     *
     * The Sun and the Moon seen from here never do, so their longitude is monotonic and cannot
     * cross a boundary and come back: it crosses once and carries on. Watching the
     * neighbourhood of the target for them would be paying for the short step for nothing, and
     * the Sun would get it two days out of every three because its margin is ten degrees.
     *
     * @param Body|DownloadableBody $body
     * @return bool
     */
    private static function canRetrograde(Body|DownloadableBody $body): bool
    {
        return ! in_array($body, [Body::Sun, Body::Moon], true);
    }

    /**
     * How many degrees a longitude is from the nearest sign boundary.
     *
     * @param float $longitude
     * @return float
     */
    private static function toBoundary(float $longitude): float
    {
        $within = fmod(fmod($longitude, 30.0) + 30.0, 30.0);

        return min($within, 30.0 - $within);
    }

    /**
     * The apparent longitude of a body as a function of time, sidereal if asked for.
     *
     * @param Body|DownloadableBody $body
     * @param Ayanamsa|CustomAyanamsa|null $ayanamsa
     * @return Closure
     */
    private static function longitudeOf(Body|DownloadableBody $body, Ayanamsa|CustomAyanamsa|null $ayanamsa): Closure
    {
        if ($ayanamsa === null) {
            return fn (float $jd): float => Ephemeris::apparentLongitude($body, $jd);
        }

        /* The ayanamsa is evaluated at the instant of the crossing and not at the starting one,
           which is the same rule the transits already follow: with the one from the beginning,
           an ingress a year ahead is off by the fifty arcseconds the ayanamsa moves in that
           year. */
        return fn (float $jd): float => fmod(
            Ephemeris::apparentLongitude($body, $jd) - $ayanamsa->value($jd) + 360.0,
            360.0
        );
    }

    /**
     * Degrees per sweep step for this body.
     *
     * @param Body|DownloadableBody $body
     * @return float
     */
    private static function margin(Body|DownloadableBody $body): float
    {
        return self::canRetrograde($body) ? self::RETROGRADE_MARGIN : self::DIRECT_MARGIN;
    }

    /**
     * How far it searches by default: somewhat more than the body takes to go once round, so
     * that a crossing that does exist is not left out by a hair.
     *
     * @param Body|DownloadableBody $body
     * @return float
     */
    private static function window(Body|DownloadableBody $body): float
    {
        /* A body downloaded from the JPL falls into the case below, and that is as it should
           be: it does not say how long it takes to go once round, so it is searched in the
           widest window and treated like the one that retrogrades the most. Paying too much in
           the sweep is losing a little time; paying too little is missing a crossing that
           exists. */
        return match ($body) {
            Body::Moon => 40.0,
            Body::Sun, Body::Mercury, Body::Venus => 400.0,
            Body::Mars => 800.0,
            Body::Jupiter => 4500.0,
            Body::Saturn => 11000.0,
            Body::Uranus => 31000.0,
            Body::Neptune => 61000.0,
            default => 100000.0,
        };
    }
}
