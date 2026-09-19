<?php

namespace Astronomy;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Tabulates what our Moon is missing in order to be the JPL's.
 *
 * `Moon` is a truncated ELP 2000-82B, an analytical theory Chapront-Touzé and Chapront fitted to
 * DE200 forty years ago. Against DE440, which is what Horizons serves today, it drifts away by a
 * fifth of an arcsecond in the year 2000, eight tenths in 1900 and **fifteen arcseconds in 1600**.
 * Almost all of that is the tidal acceleration: how much the Moon is slowed down by raising tides
 * on the Earth, which enters its mean longitude as a term in the square of the time. DE200 fitted
 * it with one value and DE440 with another, and the difference grows towards both sides of 2000
 * like a parabola. That is why the error is symmetric: fifteen arcseconds in 1600 and nineteen in
 * 2400.
 *
 * This is not fixed by touching the series: it is fixed by measuring the difference and storing
 * it. That is what this class does, and `Ephemeris::geometricMoon` adds it.
 *
 * ## What is asked for and why
 *
 * The GEOCENTRIC vector of the Moon, geometric and in Terrestrial Time:
 *
 * - `CENTER='500@399'` and not the Earth-Moon barycentre: it is what ELP gives, and correcting
 *   each thing on its own leaves the Earth's error in the Earth's correction and the Moon's in
 *   its own. Mixed together there would be no way of telling which is whose.
 * - `VEC_CORR='NONE'`, geometric: no light time. `Moon::geocentricRectangular` does not carry it
 *   either, because the delay is put in afterwards by whoever looks from the Earth. Asking for
 *   `LT` would tabulate the difference plus the delay, that is, seven tenths of an arcsecond of
 *   error put in by hand.
 * - `TIME_TYPE='TT'`. Asking in UT, Horizons converts with ITS delta T and we with ours, and the
 *   comparison ends up measuring the difference between the two clocks instead of the one between
 *   the ephemerides. It is the same trap `astro:verificar` already has written down.
 *
 * All three are what `Horizons::vectors` asks for, which is why the request goes through it: it
 * also carries the retry policy this project measured, because the JPL answers a 200 with an EMPTY
 * BODY when it is rate limiting, which cuts a download in half without an error.
 *
 * What comes back is in the J2000 ecliptic and has to be turned to the one of date with
 * `Precession::toDate`, which is exactly what `EphemerisPositions::atDate` does with the tables of
 * Pluto and the asteroids. The correction is stored in the ecliptic of date because that is where
 * `Ephemeris` works, and so adding it is adding.
 *
 * ## Regenerating does not bite its own tail
 *
 * The residual is measured against `Moon::spherical`, which is pure ELP and does not know a
 * correction table exists. Were it measured against `Ephemeris`, which already adds it, the second
 * run would measure a residual of almost nothing and would write a table of zeros: the correction
 * would erase itself without giving any error.
 *
 * ## The numbers that decided block, degree and step
 *
 * All measured over three three-year windows in 1600, 2000 and 2400, fitting and then comparing
 * against the points in between. The frontier between what it costs and what it gives, asking for
 * a point every twelve hours:
 *
 * ```
 * block  degree  worst(")   MB (800 years)   points per coefficient
 * 64     24      0.046      1.31             5.2
 * 48     24      0.028      1.74             3.9
 * 32     18      0.023      1.98             3.4
 * 16     10      0.019      2.30             3.0
 * ```
 *
 * With twelve hours it does not go below two hundredths of an arcsecond, and not because the
 * residual has anything else inside: it is that the step limits the degree. The rule in
 * `Chebyshev::fit` asks for three times as many points as coefficients, so a block of sixteen days
 * sampled every twelve hours gives 33 points and does not allow going past degree 10.
 *
 * Bringing the step down to SIX hours, that same block of sixteen days admits degree 14 with 65
 * points, that is 4.3 points per coefficient, and the error falls to **0.006"**. That is what is
 * used: 16 days, degree 14, a point every six hours, 3.1 MB for 1600 to 2400.
 *
 * ## Why the fine step is not a luxury
 *
 * The rule of three points per coefficient is not caution: it is measured, and it bites right
 * here. With DAILY sampling, the block of 48 days and degree 24 is left with 49 points for 25
 * coefficients; the fit passes through nearly all of them and **its error at its own points falls
 * to 0.024" while the real error, measured between them, rises to 0.244"**. Ten times worse and
 * looking for all the world as if it were better, which is the worst way to be wrong.
 *
 * ## Truncating ELP less does not pay off, and that is measured too
 *
 * The other way out was to lower the threshold of `astronomy elp2000` so that ELP brought more terms:
 * that kills the part of the residual that is truncation, which is the short-period part and
 * therefore the expensive one in Chebyshev. It was measured, and it does not pay:
 *
 * ```
 * threshold  terms  ms per Moon  raw residual in 1600  table for 0.05"
 * 1e-7        769      0.098          15.67"              2.6 MB
 * 1e-8       2246      0.289          15.05"              1.3 MB
 * 1e-9       6891      0.854          15.07"              1.2 MB
 * ```
 *
 * From today's threshold (1e-8) downwards **the raw residual stops going down**: 15.05" against
 * 15.07". That is, what is left is not truncation, it is the difference between DE200 and DE440,
 * and against that no term of ELP is any use. Tripling the terms would cost tripling the time of
 * each Moon, which `Eclipses` and `Occultations` call thousands of times, to save a hundred
 * kilobytes of table. It stays as it is.
 */
final class MoonCorrectionTable
{
    /** What is asked of Horizons: body 301, seen from the centre of the Earth. */
    private const COMMAND = '301';

    private const CENTRE = '500@399';

    /**
     * How many points are asked for at most in one go.
     *
     * Horizons cut off at ten thousand and did not say so: twelve thousand were asked for and ten
     * thousand arrived, with the table ending halfway through the range asked for and looking for
     * all the world as if it were right. Nine thousand are asked for to leave a margin and the
     * range is cut into chunks.
     */
    private const POINTS_PER_REQUEST = 9000;

    /** One arcsecond at the Moon's mean distance, in AU. Only for reporting. */
    private const AU_PER_ARCSECOND = 1.2467e-8;

    /**
     * Downloads the Moon from Horizons, fits it block by block and writes `correction/moon.bin`.
     *
     * The residual of each point is worked out as it arrives and not afterwards: keeping the
     * million raw vectors and ours at the same time is half a gigabyte of PHP arrays for nothing.
     *
     * @param HttpClient|null $http
     * @param string $from First date, in Terrestrial Time.
     * @param string $to Last date.
     * @param int $blockDays Days each Chebyshev block covers.
     * @param int $degree Degree of the polynomial of each coordinate.
     * @param int $stepHours Separation between the points asked of Horizons.
     * @param callable|null $progress Called as ($stage, $done, $total), with `$stage` either
     *                                'download' or 'fit'. For whoever wants to show a bar.
     * @return array{path: string, bytes: int, blocks: int, block_days: int, degree: int,
     *               step_hours: int, points: int, points_per_block: int, first: string,
     *               last: string, from: string, to: string, epoch: float, worst_fit: float,
     *               worst_fit_arcseconds: float, worst_seam: float, worst_seam_arcseconds: float}
     *
     * @throws RuntimeException If the parameters do not hold together or Horizons does not answer.
     */
    public static function regenerate(
        ?HttpClient $http = null,
        string $from = '1600-01-01',
        string $to = '2400-01-01',
        int $blockDays = 16,
        int $degree = 14,
        int $stepHours = 6,
        ?callable $progress = null,
    ): array {
        $plan = self::plan($from, $to, $blockDays, $degree, $stepHours);

        $residuals = self::residuals(
            new Horizons($http ?? new NativeHttpClient()),
            $plan['epoch'],
            $plan['points'],
            $stepHours,
            $progress
        );

        return self::fitAndWrite($residuals, $plan, $progress);
    }

    /**
     * The layout of the table, without asking the network for a single byte.
     *
     * It is public because the caller wants to say what it is about to do before spending an hour
     * doing it, and the numbers of that sentence (how many blocks, how many points, which dates
     * the table really covers) are these. `regenerate()` calls it as well, so the rules live in
     * one place only.
     *
     * @param string $from
     * @param string $to
     * @param int $blockDays
     * @param int $degree
     * @param int $stepHours
     * @return array{blocks: int, block_days: int, degree: int, step_hours: int, points: int,
     *               points_per_block: int, first: string, last: string, from: string, to: string,
     *               epoch: float}
     *
     * @throws RuntimeException
     */
    public static function plan(
        string $from = '1600-01-01',
        string $to = '2400-01-01',
        int $blockDays = 16,
        int $degree = 14,
        int $stepHours = 6,
    ): array {
        if ($blockDays < 1 || $degree < 1 || $stepHours < 1 || ($blockDays * 24) % $stepHours !== 0) {
            throw new RuntimeException('The block has to be a whole number of days and the hours have to divide it exactly.');
        }

        $perBlock = intdiv($blockDays * 24, $stepHours);

        // The rule of `Chebyshev::fit`, brought up front so that it is not discovered late.
        if ($perBlock + 1 < 3 * ($degree + 1)) {
            throw new RuntimeException(sprintf(
                'With blocks of %d days every %d hours there are %d points per block and degree %d asks for %d coefficients: at least %d points are needed.',
                $blockDays, $stepHours, $perBlock + 1, $degree, $degree + 1, 3 * ($degree + 1)
            ));
        }

        $first = self::date($from);
        $last = self::date($to);

        if ($first === null || $last === null || $first >= $last) {
            throw new RuntimeException('The dates are not valid or they run backwards.');
        }

        /* The table starts ONE BLOCK BEFORE the date asked for, and that is not rounding up: it
           is that the Moon is looked at backwards.

           `Moon::geocentricRectangular` discounts the light time by evaluating the series 1.3
           seconds before the instant asked for, and `Ephemeris`'s derivative takes the velocity
           with a centred difference of six hours. Both ask for the correction a little before the
           date, and right at the first instant of the table that falls OUTSIDE:
           `CorrectionTable::vector` returns null, nothing is added and the position comes out
           uncorrected. Measured: with the table starting exactly in 1995, 1 January 1995 came out
           at 0.134" while the rest of the year stayed at 0.04". It gives no error at all, just one
           date worse than the ones next to it. */
        $start = $first->modify('-'.$blockDays.' days');
        $days = (int) ceil(($last->getTimestamp() - $start->getTimestamp()) / 86400);
        $blocks = (int) ceil($days / $blockDays);

        if ($blocks < 1) {
            throw new RuntimeException('The range does not even reach one block.');
        }

        $end = $start->modify('+'.($blocks * $blockDays).' days');

        return [
            'blocks' => $blocks,
            'block_days' => $blockDays,
            'degree' => $degree,
            'step_hours' => $stepHours,
            'points' => $blocks * $perBlock + 1,
            'points_per_block' => $perBlock,
            'first' => $start->format('Y-m-d'),
            'last' => $end->format('Y-m-d'),
            'from' => $first->format('Y-m-d'),
            'to' => $last->format('Y-m-d'),
            'epoch' => Time::julianDay($start),
        ];
    }

    /**
     * Downloads the series and returns the residual of each point, in AU and in the ecliptic of
     * date.
     *
     * **The series is asked for in chunks and each chunk is turned into residuals right away.**
     * Keeping the whole of Horizons' answer and the whole of ours at the same time is twice the
     * memory for a number that is thrown away one line later.
     *
     * The hole in the series that used to be watched for here is now watched for one floor down:
     * `Horizons::vectors` checks EVERY row against the Julian day it should have, which is a
     * stronger check than a constant step, and it is also what makes sure the first point is the
     * one that was asked for. That check is the one thing standing between a table shifted one
     * step and a file that looks perfectly fine with all its dates moved.
     *
     * @param Horizons $horizons
     * @param float $epoch Julian day of the first point, TT.
     * @param int $points
     * @param int $stepHours
     * @param callable|null $progress
     * @return array{0: list<float>, 1: list<float>, 2: list<float>}
     *
     * @throws RuntimeException
     */
    private static function residuals(Horizons $horizons, float $epoch, int $points, int $stepHours, ?callable $progress): array
    {
        $step = $stepHours / 24;
        $residuals = [[], [], []];
        $done = 0;

        while ($done < $points) {
            $last = min($points, $done + self::POINTS_PER_REQUEST) - 1;

            $chunk = $horizons->vectors(
                self::COMMAND,
                self::CENTRE,
                $epoch + $done * $step,
                $epoch + $last * $step,
                $stepHours * 60
            );

            foreach ($chunk['x'] as $i => $x) {
                $jd = $epoch + ($done + $i) * $step;

                $reference = Precession::toDate([$x, $chunk['y'][$i], $chunk['z'][$i]], Time::centuries($jd));
                $ours = self::rawMoon($jd);

                $residuals[0][] = $reference[0] - $ours[0];
                $residuals[1][] = $reference[1] - $ours[1];
                $residuals[2][] = $reference[2] - $ours[2];
            }

            $done = count($residuals[0]);

            if ($progress !== null) {
                $progress('download', $done, $points);
            }
        }

        if ($done !== $points) {
            throw new RuntimeException("{$points} points were asked for and {$done} arrived.");
        }

        return $residuals;
    }

    /**
     * Fits block by block, writes the file and counts what was left over.
     *
     * @param array{0: list<float>, 1: list<float>, 2: list<float>} $residuals
     * @param array{blocks: int, block_days: int, degree: int, step_hours: int, points: int,
     *              points_per_block: int, first: string, last: string, from: string, to: string,
     *              epoch: float} $plan
     * @param callable|null $progress
     * @return array{path: string, bytes: int, blocks: int, block_days: int, degree: int,
     *               step_hours: int, points: int, points_per_block: int, first: string,
     *               last: string, from: string, to: string, epoch: float, worst_fit: float,
     *               worst_fit_arcseconds: float, worst_seam: float, worst_seam_arcseconds: float}
     *
     * @throws RuntimeException
     */
    private static function fitAndWrite(array $residuals, array $plan, ?callable $progress): array
    {
        $perBlock = $plan['points_per_block'];
        $degree = $plan['degree'];

        // The axis of the fit is the same for every block: points spread at a fixed step between
        // -1 and 1. It is worked out once.
        $x = [];

        for ($i = 0; $i <= $perBlock; $i++) {
            $x[] = 2.0 * $i / $perBlock - 1.0;
        }

        $coefficients = [];
        $worst = 0.0;
        $worstSeam = 0.0;
        $previous = null;

        for ($b = 0; $b < $plan['blocks']; $b++) {
            $start = $b * $perBlock;
            $block = [];

            for ($axis = 0; $axis < 3; $axis++) {
                $y = array_slice($residuals[$axis], $start, $perBlock + 1);
                $block[$axis] = Chebyshev::fit($x, $y, $degree);
            }

            // The error of the fit measured at its own points. It is not the real error, which is
            // between them, but with three times as many points as coefficients the two look
            // alike: that is what the fine sampling buys.
            for ($i = 0; $i <= $perBlock; $i++) {
                $total = 0.0;

                for ($axis = 0; $axis < 3; $axis++) {
                    $error = Chebyshev::evaluate($block[$axis], $x[$i]) - $residuals[$axis][$start + $i];
                    $total += $error * $error;
                }

                $worst = max($worst, sqrt($total));
            }

            /* The jump at the seam. Two neighbouring blocks are fitted separately, so nothing
               forces them to agree where they meet, and a jump does not show in the position but
               it does in the VELOCITY, which comes out of centred differences. It is measured here
               so that the number is written down and nobody has to assume it. */
            if ($previous !== null) {
                $total = 0.0;

                for ($axis = 0; $axis < 3; $axis++) {
                    $left = Chebyshev::evaluate($previous[$axis], 1.0);
                    $right = Chebyshev::evaluate($block[$axis], -1.0);
                    $total += ($right - $left) ** 2;
                }

                $worstSeam = max($worstSeam, sqrt($total));
            }

            $coefficients[] = $block;
            $previous = $block;

            if ($progress !== null) {
                $progress('fit', $b + 1, $plan['blocks']);
            }
        }

        $folder = DataFolder::path('correction');

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $path = "{$folder}/moon.bin";

        $bytes = CorrectionTable::write(
            $path,
            $plan['epoch'],
            (float) $plan['block_days'],
            $degree,
            $coefficients
        );

        return $plan + [
            'path' => $path,
            'bytes' => $bytes,
            'worst_fit' => $worst,
            'worst_fit_arcseconds' => $worst / self::AU_PER_ARCSECOND,
            'worst_seam' => $worstSeam,
            'worst_seam_arcseconds' => $worstSeam / self::AU_PER_ARCSECOND,
        ];
    }

    /**
     * Our geometric geocentric Moon UNCORRECTED, in the ecliptic of date and in AU.
     *
     * It is what `Ephemeris::geometricMoon` computed before the table existed, and it is here and
     * not there on purpose: measuring the residual against the already corrected version would
     * write a table of zeros on the second run.
     *
     * @param float $jd
     * @return array{0: float, 1: float, 2: float}
     */
    private static function rawMoon(float $jd): array
    {
        [$l, $b, $r] = Moon::spherical($jd);

        return [$r * cos($b) * cos($l), $r * cos($b) * sin($l), $r * sin($b)];
    }

    /**
     * @param string $text
     * @return DateTimeImmutable|null
     */
    private static function date(string $text): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($text.' 00:00:00', new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
