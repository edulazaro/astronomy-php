<?php

namespace Astronomy;

use InvalidArgumentException;
use RuntimeException;

/**
 * Downloads the data of an asteroid, a satellite or a comet from the JPL and leaves it in its file.
 *
 * **It is plain PHP**: it knows nothing of any framework. A host application's download command
 * only calls it, and outside the application it is used just the same:
 *
 *     Downloader::download(Asteroid::number(136199), $jdTT);
 *     Downloader::downloadBetween(Satellite::Io, $fromTT, $toTT);
 *
 * **Whether a body may be downloaded at all is NOT decided here, and that is the whole point.** The JPL
 * has a million and a half asteroids; which of them an application is willing to fetch, and whether it
 * fetches them at all, is that application's policy and not this engine's. There was an allow-list in
 * here and it was taken out: a library that reads a configuration is a library that has opinions about
 * a program it has never seen. This downloads what it is asked for.
 *
 * **Every file picks its step by measuring, and not per group.** There is no step that works for all of
 * them: measured against Horizons on 14 September 2026, with the same nine-point interpolation the engine
 * uses, Juno needs a point every 10 days (error of 1.6e-9 AU; with 20, 6.6e-8), Io every 2.7 hours
 * (1.3e-8; with 5.3 hours, 4.5e-6), Phobos every 32 minutes (7e-10; with 64, 4.9e-7) and Phaethon, near
 * its perihelion, every 8 hours. So it is downloaded with a fine step, how far off the interpolation
 * would be with twice that step is measured **against the JPL points that are dropped**, and the longest
 * step that meets the tolerance is saved. If not even twice the step meets it, a finer step is estimated
 * and it is downloaded again. The error saved in the file is measured, not assumed.
 *
 * **The tolerance is 0.01 arcseconds at the closest distance from which the body can be seen**, which is
 * where an error in astronomical units buys the most angle: its shortest distance to the Sun minus the
 * Earth's aphelion, and for a satellite that of its planet. With a floor of 0.05 AU: an asteroid that
 * comes closer to the Earth than that is guaranteed the tolerance at 0.05 AU, and closer in, the angular
 * error grows in proportion. It is on purpose. A close approach bends the orbit within hours, and a fixed
 * step feels it across the whole table: Apophis, with its 2029 approach inside, is off by 1.2e-4 AU with
 * one point per day. Those go by years (`yearsPerFile`) or they do not fit.
 */
final class Downloader
{
    /**
     * Extra days of data on each side of a file's span.
     *
     * They cover what the engine asks for outside the instant: the light time, which looks backwards and
     * for a body a thousand astronomical units away is almost six days, and the centred differences of
     * the velocity.
     */
    public const MARGIN_DAYS = 7.0;

    /** 0.01 arcseconds, in radians. */
    private const TOLERANCE = 0.01 / 206264.80624709636;

    /** The farthest from the Sun that the Earth gets, in AU. */
    private const EARTH_APHELION = 1.0167;

    /** The shortest distance at which the tolerance is guaranteed, in AU. */
    private const MIN_DISTANCE = 0.05;

    /**
     * The largest decimation that is tried. The rows that are downloaded are a multiple of this plus
     * one, so that any decimation keeps the last point and the file reaches the end of its span.
     */
    private const MAX_DECIMATION = 64;

    /** Rows downloaded at most for one file: some ten megabytes in memory. */
    private const MAX_ROWS = 400000;

    /** How many times the step is refined before giving up. */
    private const ATTEMPTS = 6;

    /**
     * Downloads the file that contains an instant, if it was not already there.
     *
     * Two processes asking for the same file at the same time do not download it twice: the second one
     * waits for the lock and finds it done.
     *
     * @param DownloadableBody $body
     * @param float $jdTT
     * @param bool $refresh Download it even if it is already there.
     * @param HttpClient|null $http By default, `NativeHttpClient`.
     * @param int $waitSeconds How long to wait between attempts when the JPL does not answer.
     * @return string The path of the file.
     *
     * @throws RuntimeException If Horizons does not give the data or the tolerance is not reached.
     */
    public static function download(
        DownloadableBody $body,
        float $jdTT,
        bool $refresh = false,
        ?HttpClient $http = null,
        int $waitSeconds = Horizons::WAIT_SECONDS,
    ): string {
        $path = DataFolder::path($body->file($jdTT));

        if (! $refresh && is_file($path)) {
            return $path;
        }

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create the folder '.dirname($path).'.');
        }

        $lock = fopen($path.'.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException("Cannot lock {$path}.");
        }

        try {
            if (! $refresh && is_file($path)) {
                return $path;
            }

            [$from, $to] = self::span($body, $jdTT);
            $horizons = new Horizons($http ?? new NativeHttpClient(), $waitSeconds);
            $table = self::measure($horizons, $body, $from, $to);

            /* The uncertainty the JPL declares for that body over that span, which is the figure that
               says whom to trust: with it Chiron and Pholus were judged good enough. **It is only
               asked for asteroids**, and that is measured: satellites and comets answer `n.a.`, like
               the planets, because their ephemerides publish no covariance. It is one extra request
               over data that is already downloaded, so if it fails nothing happens: it goes to null. */
            $uncertainty = $body instanceof Asteroid
                ? $horizons->uncertainty($body->horizonsId(), $from, $to)
                : null;

            DownloadedPositions::write($path, $table['jd'], $table['minutes'] / 1440, $table['x'], $table['y'], $table['z'], [
                'body' => $body->name(),
                'group' => $body->group()->value,
                'horizons' => $body->horizonsId(),
                'centre' => self::center($body),
                'source' => $table['source'],
                'from' => $from,
                'to' => $to,
                'stepMinutes' => $table['minutes'],
                'errorAu' => $table['error'],
                'toleranceAu' => $table['tolerance'],
                'uncertaintySeconds' => $uncertainty,
                'downloaded' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            return $path;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($path.'.lock');
        }
    }

    /**
     * Downloads every file that covers an interval: one per span, or a single whole table if the group
     * is not split into years (`yearsPerFile()` is null).
     *
     * @param DownloadableBody $body
     * @param float $fromTT
     * @param float $toTT
     * @param bool $refresh
     * @param HttpClient|null $http
     * @param int $waitSeconds
     * @return list<string>
     */
    public static function downloadBetween(
        DownloadableBody $body,
        float $fromTT,
        float $toTT,
        bool $refresh = false,
        ?HttpClient $http = null,
        int $waitSeconds = Horizons::WAIT_SECONDS,
    ): array {
        if ($toTT < $fromTT) {
            throw new InvalidArgumentException('The interval runs backwards.');
        }

        $years = Downloadables::yearsPerFile($body->group());

        if ($years === null) {
            return [self::download($body, $fromTT, $refresh, $http, $waitSeconds)];
        }

        $paths = [];
        $last = Downloadables::spanStart($body->group(), $toTT);

        for ($year = (int) Downloadables::spanStart($body->group(), $fromTT); $year <= $last; $year += $years) {
            $paths[] = self::download($body, Time::civilJulianDay($year, 7, 1.0), $refresh, $http, $waitSeconds);
        }

        return $paths;
    }

    /**
     * Which interval the file of an instant covers, without the margin.
     *
     * The whole table covers the same as the JPL tables the engine already carries, which is also the
     * range of dates the chart accepts.
     *
     * @param DownloadableBody $body
     * @param float $jdTT
     * @return array{0: float, 1: float}
     */
    public static function span(DownloadableBody $body, float $jdTT): array
    {
        $start = Downloadables::spanStart($body->group(), $jdTT);

        if ($start === null) {
            return EphemerisPositions::range(Body::Pluto->value);
        }

        return [
            Time::civilJulianDay($start, 1, 1.0),
            Time::civilJulianDay($start + (int) Downloadables::yearsPerFile($body->group()), 1, 1.0),
        ];
    }

    /**
     * Where its positions are asked from: the Sun for asteroids and comets, like the tables the engine
     * already carries, and the barycentre of its system for a satellite.
     *
     * @param DownloadableBody $body
     * @return string
     */
    public static function center(DownloadableBody $body): string
    {
        return $body instanceof Satellite ? $body->horizonsCenter() : '500@10';
    }

    /**
     * Downloads, measures and decimates until it finds the longest step that meets the tolerance.
     *
     * @param Horizons $horizons
     * @param DownloadableBody $body
     * @param float $from
     * @param float $to
     * @return array{jd: float, minutos: int, x: list<float>, y: list<float>, z: list<float>, error: float, tolerancia: float, fuente: string}
     */
    private static function measure(Horizons $horizons, DownloadableBody $body, float $from, float $to): array
    {
        $minutes = self::initialStep($body);
        $first = $from - self::MARGIN_DAYS;

        for ($attempt = 1; ; $attempt++) {
            $rows = (int) ceil(($to + self::MARGIN_DAYS - $first) * 1440 / $minutes / self::MAX_DECIMATION) * self::MAX_DECIMATION + 1;

            if ($rows > self::MAX_ROWS) {
                throw new RuntimeException(sprintf(
                    '%s needs data every %s to stay within 0.01 arcseconds, and its whole span would be %s rows. Its files have to be split into fewer years (yearsPerFile).',
                    $body->name(), self::durationText($minutes), number_format($rows, 0, ',', '.')
                ));
            }

            $data = $horizons->vectors(
                $body->horizonsId(),
                self::center($body),
                $first,
                $first + ($rows - 1) * $minutes / 1440,
                $minutes
            );

            $tolerance = self::tolerance($body, $data, $from, $to);
            $error = self::decimationError($data, 2);

            if ($error <= $tolerance) {
                $factor = 2;

                while ($factor < self::MAX_DECIMATION
                    && $rows >= 4 * $factor * DownloadedPositions::WINDOW
                    && ($next = self::decimationError($data, 2 * $factor)) <= $tolerance) {
                    $factor *= 2;
                    $error = $next;
                }

                return [
                    'jd' => $first,
                    'minutes' => $factor * $minutes,
                    'x' => self::everyNth($data['x'], $factor),
                    'y' => self::everyNth($data['y'], $factor),
                    'z' => self::everyNth($data['z'], $factor),
                    'error' => $error,
                    'tolerance' => $tolerance,
                    'source' => $data['source'],
                ];
            }

            if ($minutes === 1 || $attempt === self::ATTEMPTS) {
                throw new RuntimeException(sprintf(
                    'Could not download %s below 0.01 arcseconds: with data every %s the interpolation is off by %.1e AU and %.1e is allowed.',
                    $body->name(), self::durationText(2 * $minutes), $error, $tolerance
                ));
            }

            /* What was measured goes as the step raised to between eight and nine in the smooth orbits
               (Io, Titan, Phobos, Halley). It is estimated with six, which falls short on purpose, and
               with another twenty per cent of margin: going too fine costs a bigger download, and
               falling short costs another whole download. */
            $minutes = max(1, min(intdiv($minutes, 2), (int) floor($minutes * ($tolerance / $error) ** (1 / 6) * 0.8)));
        }
    }

    /**
     * The worst error of interpolating with one in every `$factor` points, measured at the points that
     * are dropped.
     *
     * The window is placed exactly as `DownloadedPositions::j2000` places it, flush against the edges
     * included, so what is measured is what the engine will get. Inside the table the weights only
     * depend on which point is dropped in each stretch, and they are computed once per remainder:
     * without that, measuring four hundred thousand points is thirty million products.
     *
     * @param array{x: list<float>, y: list<float>, z: list<float>} $data
     * @param int $factor
     * @return float AU.
     */
    private static function decimationError(array $data, int $factor): float
    {
        ['x' => $x, 'y' => $y, 'z' => $z] = $data;
        $rows = count($x);
        $coarse = intdiv($rows - 1, $factor) + 1;
        $half = intdiv(DownloadedPositions::WINDOW, 2);
        $weightsByRemainder = [];
        $worst = 0.0;

        for ($m = 1; $m < $rows; $m++) {
            $remainder = $m % $factor;

            if ($remainder === 0) {
                continue;
            }

            $position = $m / $factor;
            $centred = (int) round($position) - $half;
            $first = max(0, min($centred, $coarse - DownloadedPositions::WINDOW));

            $weights = $first === $centred
                ? ($weightsByRemainder[$remainder] ??= DownloadedPositions::weights($position - $first))
                : DownloadedPositions::weights($position - $first);

            $px = 0.0;
            $py = 0.0;
            $pz = 0.0;

            foreach ($weights as $i => $weight) {
                $k = ($first + $i) * $factor;
                $px += $weight * $x[$k];
                $py += $weight * $y[$k];
                $pz += $weight * $z[$k];
            }

            $worst = max($worst, sqrt(($px - $x[$m]) ** 2 + ($py - $y[$m]) ** 2 + ($pz - $z[$m]) ** 2));
        }

        return $worst;
    }

    /**
     * The tolerance in AU for a file: 0.01 arcseconds at the closest distance from which the body can
     * be seen.
     *
     * @param DownloadableBody $body
     * @param array{x: list<float>, y: list<float>, z: list<float>} $data
     * @param float $from
     * @param float $to
     * @return float
     */
    private static function tolerance(DownloadableBody $body, array $data, float $from, float $to): float
    {
        $squared = INF;

        if ($body instanceof Satellite) {
            // What is downloaded for a satellite is its distance to the planet, which does not say how
            // far away it is from here: that is said by the planet, which is looked at every thirty days.
            for ($jd = $from; $jd < $to + 30; $jd += 30) {
                $squared = min($squared, Ephemeris::heliocentric($body->planet(), min($jd, $to), PositionType::Geometric)->distance ** 2);
            }
        } else {
            foreach ($data['x'] as $i => $x) {
                $squared = min($squared, $x * $x + $data['y'][$i] ** 2 + $data['z'][$i] ** 2);
            }
        }

        return self::TOLERANCE * max(sqrt($squared) - self::EARTH_APHELION, self::MIN_DISTANCE);
    }

    /**
     * The fine step that is tried first, in minutes. If it is not enough, `measure` refines it.
     *
     * @param DownloadableBody $body
     * @return int
     */
    private static function initialStep(DownloadableBody $body): int
    {
        return match ($body->group()) {
            DownloadableGroup::Asteroids => 5 * 1440,
            DownloadableGroup::Comets => 6 * 60,
            DownloadableGroup::Satellites => 30,
        };
    }

    /**
     * @param list<float> $values
     * @param int $factor
     * @return list<float>
     */
    private static function everyNth(array $values, int $factor): array
    {
        $result = [];

        for ($i = 0, $n = count($values); $i < $n; $i += $factor) {
            $result[] = $values[$i];
        }

        return $result;
    }

    /**
     * @param int $minutes
     * @return string
     */
    private static function durationText(int $minutes): string
    {
        return match (true) {
            $minutes % 1440 === 0 => ($minutes / 1440).($minutes === 1440 ? ' day' : ' days'),
            $minutes % 60 === 0 => ($minutes / 60).($minutes === 60 ? ' hour' : ' hours'),
            default => $minutes.($minutes === 1 ? ' minute' : ' minutes'),
        };
    }
}
