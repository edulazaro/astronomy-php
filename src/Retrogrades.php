<?php

namespace Astronomy;

use InvalidArgumentException;

/**
 * When a planet stops and turns around.
 *
 * Seen from here, a planet does not always move forward through the zodiac: for a few weeks a
 * year it seems to go backwards. It is not really moving backwards, it is that the Earth
 * overtakes it on the inside, like a car you pass and that for a moment seems to go
 * backwards. The instants when it changes direction are called stations, and between the
 * retrograde one and the direct one lies the retrograde period.
 *
 * It all comes out of a number the ephemeris already gives: the speed in longitude, which
 * comes with its sign. A station is where that number is zero, so this is not a new
 * calculation, it is finding a zero of something that is already computed, and `Crossings::root`
 * takes care of that.
 *
 * Why this class is not called `Stations`
 *
 * Because a station of a planet and a season of the year are the same word in more than one
 * language, Spanish among them («estación»), and this engine grew inside an application that
 * also has an equinoxes and solstices tool. Two classes named the same for things that have
 * nothing to do with each other is exactly the confusion already noted with `Vulcan` against
 * `Vulkanus`: it gives no error at all, someone simply opens the wrong one.
 *
 * The step of the sweep, which is the only delicate part
 *
 * A Mercury retrograde period lasts about three weeks, and it is the shortest of them all. If
 * the step of the sweep came close to that, the two stations of one and the same period would
 * fit inside a single step, the speed would have the same sign at both ends and the whole
 * retrograde period would disappear without giving any error. It is the same trap already told
 * in `Crossings`, where what rules is not the speed of the body but the width of its retrograde
 * arc.
 *
 * With a two day step not even half a Mercury retrograde period fits, so a pair cannot be
 * skipped. The test checks it by brute force with a step twenty times finer.
 */
class Retrogrades
{
    /**
     * Days between samples. Well below the three weeks the shortest retrograde period lasts,
     * Mercury's, so that two stations cannot fit inside one step.
     */
    private const STEP = 2.0;

    /** Cap on the samples, so that an absurd window ends instead of hanging. */
    private const MAX_SAMPLES = 20000;

    /**
     * The Sun and the Moon never go retrograde seen from here, and it is not an approximation:
     * the Moon goes down to 11.7 degrees per day and does not cross zero. Asking for them is
     * almost always a mistake of the caller, so it is said instead of returning an empty list,
     * which would read as «this year it has not gone retrograde».
     *
     * @param Body|DownloadableBody $body
     * @return void
     */
    private static function requireCanRetrograde(Body|DownloadableBody $body): void
    {
        if (in_array($body, [Body::Sun, Body::Moon], true)) {
            throw new InvalidArgumentException(
                'The Sun and the Moon do not go retrograde seen from the Earth: '.$body->name()
            );
        }
    }

    /**
     * Every station of a body inside a window, in order.
     *
     * @param Body|DownloadableBody $body
     * @param float $from Julian day in Terrestrial Time.
     * @param float $to Ditto.
     * @return list<array{jd: float, longitud: float, retrograda: bool}> `retrograda` tells
     * whether from that instant on the body starts going backwards.
     */
    public static function stations(Body|DownloadableBody $body, float $from, float $to): array
    {
        self::requireCanRetrograde($body);

        if ($to <= $from) {
            throw new InvalidArgumentException('The window runs from lower to higher.');
        }

        $speed = fn (float $jd): float => Ephemeris::position($body, $jd)->speed;

        $stations = [];
        $previous = $from;
        $previousValue = $speed($from);

        for ($i = 1; $i <= self::MAX_SAMPLES; $i++) {
            /* Sampling goes by index and not by accumulating the step: a hundred and fifty sums
               over a seven figure Julian day drag nanodays, and the last sample gets lost.
               It already bit in `RiseSet` and it is told in CLAUDE.md. */
            $jd = min($from + $i * self::STEP, $to);
            $value = $speed($jd);

            if (($previousValue < 0.0) !== ($value < 0.0)) {
                $crossing = Crossings::root($speed, $previous, $jd);

                $stations[] = [
                    'jd' => $crossing,
                    'longitude' => Ephemeris::apparentLongitude($body, $crossing),
                    // If after the station the speed is negative, this is where it starts going
                    // backwards.
                    'retrograde' => $value < 0.0,
                ];
            }

            if ($jd >= $to) {
                break;
            }

            $previous = $jd;
            $previousValue = $value;
        }

        return $stations;
    }

    /**
     * The retrograde periods that TOUCH the window, pairing up the stations.
     *
     * The search runs on a window widened on both sides, and that is the part that is not
     * obvious: a period that started in December and ends in January belongs to both years, and
     * asking only for the year would lose half of its information (an end without a beginning
     * would show up). The widening is half a year, which is more than the longest retrograde
     * period lasts, the one of the slow bodies.
     *
     * @param Body|DownloadableBody $body
     * @param float $from
     * @param float $to
     * @return list<array{inicio: float|null, fin: float|null, longitudInicio: float|null, longitudFin: float|null}>
     * A null endpoint means that the station falls outside the widening, which can only
     * happen with very large windows.
     */
    public static function periods(Body|DownloadableBody $body, float $from, float $to): array
    {
        $margin = 200.0;
        $stations = self::stations($body, $from - $margin, $to + $margin);

        $periods = [];
        $open = null;

        foreach ($stations as $station) {
            if ($station['retrograde']) {
                $open = $station;

                continue;
            }

            // A direct station closes whatever period was open. If there is none open it is
            // because it started before the widening, and that is said with a null instead of
            // making up a start date.
            $periods[] = [
                'start' => $open['jd'] ?? null,
                'end' => $station['jd'],
                'longitudInicio' => $open['longitude'] ?? null,
                'longitudFin' => $station['longitude'],
            ];

            $open = null;
        }

        if ($open !== null) {
            $periods[] = [
                'start' => $open['jd'],
                'end' => null,
                'longitudInicio' => $open['longitude'],
                'longitudFin' => null,
            ];
        }

        // Only the ones that really overlap the requested window: the widening is there so as
        // not to split periods, not to return the ones of the neighbouring year.
        return array_values(array_filter(
            $periods,
            fn (array $p): bool => ($p['end'] ?? INF) >= $from && ($p['start'] ?? -INF) <= $to
        ));
    }

    /**
     * Whether the body goes backwards at that instant.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return bool
     */
    public static function retrogradeAt(Body|DownloadableBody $body, float $jdTT): bool
    {
        self::requireCanRetrograde($body);

        return Ephemeris::position($body, $jdTT)->speed < 0.0;
    }

    /**
     * The retrograde period under way, or the next one if it is going forward now.
     *
     * @param Body|DownloadableBody $body
     * @param float $jdTT
     * @return array{enCurso: bool, inicio: float|null, fin: float|null, longitudInicio: float|null, longitudFin: float|null}|null
     */
    public static function around(Body|DownloadableBody $body, float $jdTT): ?array
    {
        /* A year and a half of window: it is plenty for the slowest of them all. Mercury goes
           retrograde three times a year and Neptune once, so with this there is always at least
           one. */
        $periods = self::periods($body, $jdTT - 30.0, $jdTT + 550.0);

        foreach ($periods as $period) {
            if (($period['start'] ?? -INF) <= $jdTT && ($period['end'] ?? INF) >= $jdTT) {
                return ['enCurso' => true] + $period;
            }
        }

        foreach ($periods as $period) {
            if (($period['start'] ?? INF) > $jdTT) {
                return ['enCurso' => false] + $period;
            }
        }

        return null;
    }
}
