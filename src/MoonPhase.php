<?php

namespace Astronomy;

use DateTimeImmutable;

/**
 * The Moon's phase at birth, and the two lunations that surround that instant.
 *
 * The NAME of the phase comes from a single number: the elongation, that is, how far the Moon
 * is ahead of the Sun in ecliptic longitude. At 0 it is new moon and at 180 full, and the rest
 * of the names are stretches of that run. No new ephemeris is needed: they are the two
 * positions we already compute.
 *
 * The illuminated fraction does NOT come from the elongation, and that is the easy
 * confusion, because the formula going around is `(1 − cos elongation) / 2` and it gives an
 * almost good number. Almost: the elongation is the angle seen from HERE and what decides how
 * much of the disk is lit is the angle seen from THE MOON, which is another one. The Moon is so
 * close that the two do not add up to one hundred and eighty degrees: they differ by up to an
 * eighth of a degree. Measured over a whole lunation, the approximation departs by 0.2
 * percentage points, and near new moon that is 5% in proportion: 3.52 % where the good one says
 * 3.72 %. It is computed with `Phenomena`, which measures the angle at the Moon, and it costs
 * 1.2 milliseconds more.
 *
 * The elongation is ALWAYS measured forward, from 0 to 360, not folded to the shorter arc.
 * That is what tells waxing from waning: 90 degrees is first quarter and 270 is last quarter,
 * and folding to the short arc would give 90 for both and they would be indistinguishable. It
 * is the same care the separation of aspects already asks for, and for the same reason.
 */
class MoonPhase
{
    /**
     * The four phases with a proper name are INSTANTS, not stretches, and that is why they
     * carry a margin.
     *
     * New moon is the exact moment when the elongation is zero, and the same goes for the two
     * quarters and the full moon. Splitting the turn into eight equal stretches, which is what
     * there was, turns each of those instants into 3.7 days of «new moon» and leaves the
     * name saying nothing; worse still, half of every full moon falls on the «gibosa creciente»
     * side, so the closest full moon in seventy years, that of 14 November 2016, came out on the
     * sheet as «waxing gibbous» with 99.83 % of the disk lit. A sentence that contradicts
     * itself.
     *
     * With six degrees of margin on each side, a proper name lasts a little less than a day,
     * which is how the almanacs give it, and the four stretches in between share out the rest.
     */
    private const NAMED_MARGIN = 6.0;

    /** The four named instants, by the degree of elongation they fall on. */
    private const NAMED = [
        [0.0, 'new-moon'],
        [90.0, 'first-quarter'],
        [180.0, 'full-moon'],
        [270.0, 'last-quarter'],
    ];

    /** The four stretches in between, by the quadrant they occupy. */
    private const INTERMEDIATE = ['waxing-crescent', 'waxing-gibbous', 'waning-gibbous', 'waning-crescent'];

    /**
     * @param float $elongation Degrees the Moon is ahead of the Sun, from 0 to 360.
     * @param string $name
     * @param float $illumination Fraction of the disk lit, from 0 to 1.
     * @param float $apparentDiameter Degrees the disk took up.
     */
    public function __construct(
        public readonly float $elongation,
        public readonly string $name,
        public readonly float $illumination,
        public readonly float $apparentDiameter = 0.0,
    ) {}

    /**
     * The elongation as it is written, with the turn closed.
     *
     * At new moon the elongation is 359.997, which rounded to two decimals prints as 360.00
     * right below the sentence saying that at zero it is new moon. It is not wrong, because a
     * whole turn is the same place, but it reads as if it contradicted itself. It is rounded
     * FIRST and the turn closed afterwards, which in that order gives zero.
     *
     * @param int $decimals
     * @return float
     */
    public function elongationForDisplay(int $decimals = 2): float
    {
        return fmod(round($this->elongation, $decimals), 360.0);
    }

    /**
     * How much bigger or smaller than usual the Moon looked, as a percentage.
     *
     * The orbit is elliptical, so the disk grows and shrinks by fourteen per cent between
     * perigee and apogee: that is what lies behind the word «supermoon», which is not an
     * astronomical term but is a measurable fact. It is compared against the semi-major axis of
     * the orbit, which is the mean size.
     *
     * @return float Positive if it looked bigger than normal.
     */
    public function relativeToMeanSize(): float
    {
        if ($this->apparentDiameter <= 0.0) {
            return 0.0;
        }

        // The disk at the mean distance, 384,399 km, with the project's lunar radius.
        $mean = 2.0 * rad2deg(asin(Body::Moon->radiusKm() / 384399.0));

        return ($this->apparentDiameter / $mean - 1.0) * 100.0;
    }

    /**
     * The name of the phase for a given elongation.
     *
     * @param float $elongation From 0 to 360.
     * @return string
     */
    private static function nameOf(float $elongation): string
    {
        foreach (self::NAMED as [$point, $label]) {
            // Folded, so that new moon recognises both degree 1 and degree 359.
            $distance = abs(fmod($elongation - $point + 540.0, 360.0) - 180.0);

            if ($distance <= self::NAMED_MARGIN) {
                return $label;
            }
        }

        // Outside the four instants, the quadrant it falls in decides.
        return self::INTERMEDIATE[((int) floor($elongation / 90.0)) % 4];
    }

    /**
     * @param float $jdTT
     * @return self
     */
    public static function at(float $jdTT): self
    {
        $elongation = self::elongation($jdTT);

        $name = self::nameOf($elongation);

        $phenomenon = Phenomena::of(Body::Moon, $jdTT);

        return new self(
            elongation: $elongation,
            name: $name,
            illumination: $phenomenon->illuminatedFraction,
            apparentDiameter: $phenomenon->apparentDiameter,
        );
    }

    /**
     * How far the Moon is ahead of the Sun, from 0 to 360 and unfolded.
     *
     * @param float $jdTT
     * @return float
     */
    public static function elongation(float $jdTT): float
    {
        $sun = Ephemeris::position(Body::Sun, $jdTT);
        $moon = Ephemeris::position(Body::Moon, $jdTT);

        return fmod($moon->longitude - $sun->longitude + 360.0, 360.0);
    }

    /**
     * The lunation before and the one after an instant.
     *
     * They are searched by bisection on the elongation, which is the same technique used to
     * check the lunar nodes and Lilith: instead of an approximate formula nobody can say how
     * far off it is, the instant at which the quantity takes the value it must is searched for,
     * with the Moon we already have.
     *
     * @param float $jdTT
     * @return array{anterior: array{tipo: string, jd: float}, posterior: array{tipo: string, jd: float}}
     */
    public static function lunations(float $jdTT): array
    {
        /* It walks away from the instant backwards and forwards, one day at a time, and stops
           at the FIRST crossing on each side: since the elongation always grows, the first
           crossing found is the nearest lunation. Before, a fixed window of thirty-two days was
           swept whole and every crossing in it was refined, up to four of them, with forty
           bisections each: some two hundred elongations and 350 milliseconds per chart, half of
           what drawing the sheet cost. Now it is about thirty.

           Each elongation is two positions with their velocity, that is six ephemeris
           evaluations: that is why what rules is how many are asked for, not what each one
           costs. */
        return [
            'previous' => self::nearestCrossing($jdTT, -1.0),
            'next' => self::nearestCrossing($jdTT, 1.0),
        ];
    }

    /**
     * The first lunation on one side of the instant: backwards with direction −1, forwards with
     * +1. One never has to go further than a synodic month, and it stops before that.
     *
     * @param float $jdTT
     * @param float $direction
     * @return array{tipo: string, jd: float}|null
     */
    private static function nearestCrossing(float $jdTT, float $direction): ?array
    {
        $step = 1.0;
        $previousJd = $jdTT;
        $previousElongation = self::elongation($jdTT);

        for ($i = 1; $i <= 31; $i++) {
            $jd = $jdTT + $direction * $i * $step;
            $elongation = self::elongation($jd);

            foreach ([[0.0, 'new-moon'], [180.0, 'full-moon']] as [$target, $type]) {
                // Ordered in time, so the crossing is always searched for upwards.
                [$from, $to, $a, $b] = $direction > 0
                    ? [$previousJd, $jd, $previousElongation, $elongation]
                    : [$jd, $previousJd, $elongation, $previousElongation];

                $da = fmod($a - $target + 540.0, 360.0) - 180.0;
                $db = fmod($b - $target + 540.0, 360.0) - 180.0;

                // The elongation always grows, so only the upward crossing counts: the other
                // way round is the artificial jump of the folding.
                if (! ($da < 0.0 && $db >= 0.0)) {
                    continue;
                }

                $crossing = Crossings::root(
                    fn (float $jd): float => fmod(self::elongation($jd) - $target + 540.0, 360.0) - 180.0,
                    $from,
                    $to,
                );

                // A crossing at the instant itself counts as the previous one, not the next.
                if (($direction < 0 && $crossing <= $jdTT) || ($direction > 0 && $crossing > $jdTT)) {
                    return ['type' => $type, 'jd' => $crossing];
                }
            }

            $previousJd = $jd;
            $previousElongation = $elongation;
        }

        return null;
    }
}
