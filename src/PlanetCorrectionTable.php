<?php

namespace Astronomy;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Tabulates what VSOP87 is missing in order to be DE440, planet by planet. Pure PHP: in a framework
 * application it is a console command that calls it, and on its own
 * `PlanetCorrectionTable::regenerate()` is enough.
 *
 * VSOP87 is a theory from 1987 fitted to DE200, and on top of that we carry it truncated. Against
 * DE440, which is what the JPL serves today, it drifts away from a tenth of an arcsecond (the inner
 * planets, in the 20th century) to eight arcseconds (Neptune three centuries out from 2000), and
 * that was the largest error the engine had left: more than the precession, more than the nutation
 * and more than the light time put together.
 *
 * The position is not tabulated: the difference is. Storing the JPL's positions for eight
 * planets and eight hundred years would cost tens of megabytes, because a large number has to be
 * described exactly (Neptune runs at around 30 AU). The difference is a number a thousand times
 * smaller, so for the same ABSOLUTE error, which is the one that shows up in a chart, it needs far
 * fewer coefficients. And it has a property the other one does not: if the file is missing or the
 * date falls outside the range, `Ephemeris` carries on with its series and the chart is raised just
 * the same. See `CorrectionTable` for the format and `Ephemeris::corrected` for the wiring.
 *
 * The Earth is the most important of the eight bodies and the easiest one to forget, because it is
 * not drawn in any chart: the geocentric Sun is minus the heliocentric Earth, so correcting the
 * Earth is correcting the Sun, which is what gets read the most. And the Moon comes free with it,
 * since the engine computes it as the Earth plus its geocentric position.
 *
 * Pluto, Chiron and the asteroids carry no correction and cannot carry one: they already come from
 * the JPL through their own tables. Correcting them towards the JPL would be correcting them
 * towards themselves.
 *
 * The path, which is the ephemeris tables' one with two more steps
 *
 * 1. Horizons is asked for the GEOMETRIC heliocentric vector (`VEC_CORR='NONE'`: no light time and
 * no aberration, which the engine puts on afterwards) in the J2000 ecliptic.
 * 2. It is turned to the ecliptic of date with `Precession::toDate`, which is what
 * `EphemerisPositions::atDate` does with the tables for Pluto and the asteroids.
 * 3. `Vsop87::rectangular` is subtracted from it, which is exactly the vector the correction layer
 * is going to add this to.
 * 4. The residual is fitted with Chebyshev in blocks and written.
 *
 * The time is asked for in TT. Asking in UT, Horizons converts with its delta T and we convert
 * with ours, and outside 1900 to 2050 what gets measured is the difference between the two delta T
 * and not the ephemeris. It is the same lesson the verification command already learnt.
 *
 * The range is 1599 to 2401 and not 1600 to 2400, which is what the Pluto table covers. The
 * extra year on each side is margin: outside the table the correction does not degrade, it switches
 * off entirely, and the velocity is derived by centred differences at six hours. Without that
 * margin, a chart of 1 January 1600 would ask for the correction six hours before the first block,
 * would not find it, and would get a spike of velocity.
 */
final class PlanetCorrectionTable
{
    /** The first day of the range by default. */
    public const FIRST_DAY = '1599-01-01';

    /** The last day of the range by default. */
    public const LAST_DAY = '2401-01-01';

    /**
     * What Horizons is asked for and what it is fitted with, body by body.
     *
     * `[identifier, days per block, degree, days between points]`.
     *
     * The three numbers are measured, not chosen. Blocks and degrees were swept against the
     * whole eight hundred years of residual, measuring the error of the fit in arcseconds of
     * GEOCENTRIC LONGITUDE, which is what gets read, and not in AU: the same distance in AU is
     * eleven times as many arcseconds on Mars, which comes to within 0.38 AU, as on Jupiter. What
     * came out of the sweep:
     *
     * - The residual has the shape of the ORBITAL PERIOD of the body, so the block is chosen of the
     * order of that period: Mercury asks for 176 days and Neptune takes forty years.
     * - For the same coefficients per day, `(degree+1)/block`, it comes to almost the same whether
     * they are spread over short blocks of low degree or long blocks of high degree. The blocks
     * chosen are the ones where the degree stays below thirty, which is where the fit's normal
     * system is still well conditioned.
     * - The sampling step does not drive this. With the same block and the same degree, asking
     * for a point a day or one every four days gives the same error to the fourth figure, as long
     * as the three times as many points as coefficients is respected. What drives it is the
     * coefficients per day.
     * - Below one or two hundredths of an arcsecond the fit stops getting better: there the
     * polynomial stops being what dominates and what the truncated series left out starts to.
     * Spending more bytes there buys nothing, and all the less so because the engine's own floor
     * (the precession model against the JPL's) runs at around a tenth.
     *
     * A fit only says what it is worth where it has been looked at. The sweep evaluated the
     * error at the same points it fitted with, and that is measuring the fit against itself:
     * between two points it could be doing anything. It was measured again with steps of 7 and 11
     * days, which are not multiples of the 40 and 60 the outer ones are fitted with, and it comes
     * out the same (0.009″ on Jupiter and Uranus, 0.017″ on Saturn, 0.008″ on Neptune). That it
     * came out the same was not obvious, and that is why it is measured.
     *
     * A slow body's block is not chosen by how slowly it moves. Neptune runs six arcseconds a
     * day and takes blocks of forty years; Mercury runs an hour and a half of arc and asks for
     * blocks of six months. But what is being fitted is not the position but the RESIDUAL against
     * VSOP87, and that one has no reason to go at the planet's rate: it goes at the rate of the
     * terms the theory fitted worst. It so happens that here the two do coincide, and that was
     * measured before the long block was taken as good.
     *
     * **The identifier is the BARYCENTRE of the planet's system and not the centre of the body,
     * except for the Earth.** Horizons does not serve 499, 599, 699, 799 or 899 over the whole
     * range: it answers 200 with the body's data sheet and with no data block, the same as it does
     * with Pluto (`999`). The barycentre (`4`..`8`) it does, from 1550 to 2650, and for the outer
     * ones it is besides what VSOP87 represents. For Mercury and Venus the barycentre IS the body,
     * they have no moons. The Earth is the exception and goes as `399`: its barycentre with the
     * Moon lies 4700 kilometres from the centre of the planet, that is 6.8 arcseconds seen from
     * here, and VSOP87 gives the centre (measured: against `399` the residual is 0.09 arcseconds
     * and against `3` it is 6.8). Horizons serves `399` over the whole range.
     *
     * And the centre of a large planet is not «almost» its barycentre: it is another ephemeris.
     * Horizons's `799` comes from the solution for the satellites of Uranus and its `7` from DE440,
     * and the two grow apart the further one gets from the epoch they were fitted at: measured,
     * 1.27 arcseconds in 1650 and 0.65 in 2190, crossing zero towards 2010. It is the same story
     * already recorded for Pluto (`999` against `9`, 2100 km) but much larger. With the engine at
     * one arcsecond that could not be seen; now it can, and the verification command, which asks by
     * `Body::horizonsId()` and therefore by `799`, gets 1.1 arcseconds out of Uranus in 1700 that
     * are not its own. Jupiter, Saturn and Neptune do not have it: their two versions are less than
     * 0.07 arcseconds apart.
     *
     * @var array<string, array{0: string, 1: int, 2: int, 3: int}>
     */
    private const BODIES = [
        'mercury' => ['1', 176, 20, 2],
        'venus' => ['2', 224, 16, 4],
        'earth' => ['399', 364, 20, 4],
        'mars' => ['4', 344, 16, 4],
        'jupiter' => ['5', 4320, 28, 40],
        'saturn' => ['6', 5400, 24, 60],
        'uranus' => ['7', 7680, 20, 40],
        'neptune' => ['8', 15000, 24, 60],
    ];

    /**
     * The bodies that have a measured correction, with the parameters each one is fitted with.
     *
     * Public so that whoever calls this can validate what was asked for and name the eight in an
     * error message without writing the list down a second time.
     *
     * @return array<string, array{0: string, 1: int, 2: int, 3: int}> `[identifier, days per block, degree, days between points]`.
     */
    public static function bodies(): array
    {
        return self::BODIES;
    }

    /**
     * Downloads the JPL vectors, fits the residual against VSOP87 and writes the tables.
     *
     * The three fitting parameters are the measured ones for each body unless they are given here,
     * and then the same ones are used for every body that is generated, which is what the sweep
     * that measured them needed.
     *
     * @param HttpClient|null $http
     * @param string|null $body One of the eight; null for all of them.
     * @param string $from First day of the range.
     * @param string $to Last day of the range.
     * @param int|null $blockDays Days per block; the measured one for that body by default.
     * @param int|null $degree Degree of the polynomial; the measured one for that body by default.
     * @param int|null $stepDays Days between points asked for; the measured one for that body by default.
     * @param bool $dryRun Measures the fit and writes nothing, for trying other parameters.
     * @return array{tables: list<array{body: string, horizons: string, blocks: int, block_days: int, degree: int, step_days: int, points_per_block: int, first_jd: float, last_jd: float, worst_residual: float, seam_jump: float, bytes: int, path: string|null}>, bytes: int, files: int}
     * `bytes` and `files` count only what was actually written, so they are zero on a dry run.
     *
     * @throws RuntimeException If the body is not one of the eight, the parameters do not add up or
     * the series that arrives is not the one that was asked for.
     */
    public static function regenerate(
        ?HttpClient $http = null,
        ?string $body = null,
        string $from = self::FIRST_DAY,
        string $to = self::LAST_DAY,
        ?int $blockDays = null,
        ?int $degree = null,
        ?int $stepDays = null,
        bool $dryRun = false,
    ): array {
        if ($body !== null && ! isset(self::BODIES[$body])) {
            throw new RuntimeException(
                "There is no measured correction for «{$body}». They are: ".implode(', ', array_keys(self::BODIES)).'.'
            );
        }

        $firstJd = self::julianDayOf($from);
        $lastJd = self::julianDayOf($to);

        if ($firstJd === null || $lastJd === null || $firstJd >= $lastJd) {
            throw new RuntimeException('The dates are not valid or they run backwards.');
        }

        $horizons = new Horizons($http ?? new NativeHttpClient());
        $folder = DataFolder::path('correction');

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $tables = [];
        $bytes = 0;
        $files = 0;

        foreach ($body === null ? array_keys(self::BODIES) : [$body] as $one) {
            $table = self::one($horizons, $one, $folder, $firstJd, $lastJd, $blockDays, $degree, $stepDays, $dryRun);

            if ($table['path'] !== null) {
                $bytes += $table['bytes'];
                $files++;
            }

            $tables[] = $table;
        }

        return ['tables' => $tables, 'bytes' => $bytes, 'files' => $files];
    }

    /**
     * One body's table.
     *
     * @param Horizons $horizons
     * @param string $body
     * @param string $folder
     * @param float $firstJd
     * @param float $lastJd
     * @param int|null $blockDays
     * @param int|null $degree
     * @param int|null $stepDays
     * @param bool $dryRun
     * @return array{body: string, horizons: string, blocks: int, block_days: int, degree: int, step_days: int, points_per_block: int, first_jd: float, last_jd: float, worst_residual: float, seam_jump: float, bytes: int, path: string|null}
     *
     * @throws RuntimeException
     */
    private static function one(
        Horizons $horizons,
        string $body,
        string $folder,
        float $firstJd,
        float $lastJd,
        ?int $blockDays,
        ?int $degree,
        ?int $stepDays,
        bool $dryRun,
    ): array {
        [$id, $measuredBlock, $measuredDegree, $measuredStep] = self::BODIES[$body];

        $blockDays = (int) ($blockDays ?: $measuredBlock);
        $degree = (int) ($degree ?: $measuredDegree);
        $stepDays = (int) ($stepDays ?: $measuredStep);

        /* The block has to be a WHOLE number of steps, and this is not an implementation
           convenience. If it is not, the last point asked for falls short of the block's right
           edge and the polynomial EXTRAPOLATES over what is left. Measured on Mercury: with blocks
           of 440 days and a step of 7, the fit went from 0.07 to 1.12 arcseconds, all of it in the
           seam, and raising the degree made it worse instead of better. */
        if ($blockDays % $stepDays !== 0) {
            throw new RuntimeException("A block of {$blockDays} days is not a whole number of {$stepDays}-day steps.");
        }

        $pointsPerBlock = intdiv($blockDays, $stepDays) + 1;

        // What `Chebyshev::fit` asks for: with just the bare minimum of points the fit passes
        // through all of them and says nothing about what lies between them, which is exactly
        // what has to be measured.
        if ($pointsPerBlock < 3 * ($degree + 1)) {
            throw new RuntimeException(sprintf(
                'With blocks of %d days and a step of %d there are %d points per block, and degree %d asks for at least %d.',
                $blockDays, $stepDays, $pointsPerBlock, $degree, 3 * ($degree + 1)
            ));
        }

        $days = (int) round($lastJd - $firstJd);
        $blocks = (int) ceil($days / $blockDays);

        if ($blocks < 2) {
            throw new RuntimeException('The range does not even make two blocks.');
        }

        /* The table has a WHOLE number of blocks, so it ends where the last one ends and not
           where the date that was asked for ends. It is rounded UP on purpose: rounding down,
           Neptune with blocks of forty years would have stopped at 2380, and at the boundary the
           correction does not degrade, it disappears all at once. */
        $endJd = $firstJd + $blocks * $blockDays;

        $vectors = $horizons->vectors($id, '500@10', $firstJd, $endJd, $stepDays * 1440);
        $residuals = self::residuals($body, $firstJd, $stepDays, $vectors);

        [$coefficients, $worst, $jump] = self::fit($residuals, $blocks, $pointsPerBlock, $degree);

        $bytes = 32 + $blocks * 3 * ($degree + 1) * 4;
        $path = "{$folder}/{$body}.bin";

        if (! $dryRun) {
            $bytes = CorrectionTable::write($path, $firstJd, (float) $blockDays, $degree, $coefficients);
        }

        return [
            'body' => $body,
            'horizons' => $id,
            'blocks' => $blocks,
            'block_days' => $blockDays,
            'degree' => $degree,
            'step_days' => $stepDays,
            'points_per_block' => $pointsPerBlock,
            'first_jd' => $firstJd,
            'last_jd' => $endJd,
            'worst_residual' => $worst,
            'seam_jump' => $jump,
            'bytes' => $bytes,
            'path' => $dryRun ? null : $path,
        ];
    }

    /**
     * The residual: what VSOP87 is missing in order to be the JPL, in the ecliptic of date.
     *
     * The two steps are the ones the engine takes on its own, and that is why they are here and not
     * in the layer that reads the table: the JPL serves in J2000 and it has to be turned with the
     * SAME precession the tables for Pluto and the asteroids are turned with, because otherwise the
     * correction would carry the difference between two precession models inside it.
     *
     * The Julian day of each point is rebuilt from the first one and the step instead of being read
     * back: `Horizons::vectors` has already checked, row by row, that every one of them fell where
     * it was asked for to within a millionth of a day.
     *
     * @param string $body
     * @param float $firstJd
     * @param int $stepDays
     * @param array{x: list<float>, y: list<float>, z: list<float>} $vectors
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function residuals(string $body, float $firstJd, int $stepDays, array $vectors): array
    {
        $residuals = [];

        foreach ($vectors['x'] as $i => $x) {
            $jd = $firstJd + $i * $stepDays;
            $jpl = Precession::toDate([$x, $vectors['y'][$i], $vectors['z'][$i]], Time::centuries($jd));
            $series = Vsop87::rectangular($body, Time::millennia($jd));

            $residuals[] = [$jd, $jpl[0] - $series[0], $jpl[1] - $series[1], $jpl[2] - $series[2]];
        }

        return $residuals;
    }

    /**
     * Fits the residual block by block and measures what the fit leaves out.
     *
     * Two different things are measured and both matter. The worst residual is how far the
     * polynomial strays from the real residual, that is, what the correction does not correct. The
     * seam jump is how far two neighbouring blocks are apart at the boundary they share: a fit
     * by blocks is not continuous by construction, and a jump there does not show in the position
     * but it does in the VELOCITY, which `Ephemeris` gets by centred differences at six hours. A
     * jump of an arcsecond would give a spike of four arcseconds a day, and that is a planet that
     * appears to turn from retrograde to direct for no reason.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $residuals
     * @param int $blocks
     * @param int $pointsPerBlock
     * @param int $degree
     * @return array{0: list<list<list<float>>>, 1: float, 2: float}
     */
    private static function fit(array $residuals, int $blocks, int $pointsPerBlock, int $degree): array
    {
        $coefficients = [];
        $worst = 0.0;
        $jump = 0.0;
        $right = null;
        $stepsPerBlock = $pointsPerBlock - 1;

        for ($b = 0; $b < $blocks; $b++) {
            $first = $b * $stepsPerBlock;

            $x = [];
            $y = [[], [], []];

            for ($i = 0; $i < $pointsPerBlock; $i++) {
                $x[] = 2.0 * $i / $stepsPerBlock - 1.0;

                foreach ([0, 1, 2] as $axis) {
                    $y[$axis][] = $residuals[$first + $i][$axis + 1];
                }
            }

            $block = [];

            foreach ([0, 1, 2] as $axis) {
                $block[$axis] = Chebyshev::fit($x, $y[$axis], $degree);
            }

            $coefficients[] = $block;

            for ($i = 0; $i < $pointsPerBlock; $i++) {
                $square = 0.0;

                foreach ([0, 1, 2] as $axis) {
                    $square += (Chebyshev::evaluate($block[$axis], $x[$i]) - $y[$axis][$i]) ** 2;
                }

                $worst = max($worst, sqrt($square));
            }

            $left = [
                Chebyshev::evaluate($block[0], -1.0),
                Chebyshev::evaluate($block[1], -1.0),
                Chebyshev::evaluate($block[2], -1.0),
            ];

            if ($right !== null) {
                $jump = max($jump, sqrt(
                    ($left[0] - $right[0]) ** 2
                    + ($left[1] - $right[1]) ** 2
                    + ($left[2] - $right[2]) ** 2
                ));
            }

            $right = [
                Chebyshev::evaluate($block[0], 1.0),
                Chebyshev::evaluate($block[1], 1.0),
                Chebyshev::evaluate($block[2], 1.0),
            ];
        }

        return [$coefficients, $worst, $jump];
    }

    /**
     * The Julian day of a date written as a year, a month and a day, or null if it is not one.
     *
     * @param string $text
     * @return float|null
     */
    private static function julianDayOf(string $text): ?float
    {
        try {
            return Time::julianDay(new DateTimeImmutable($text.' 00:00:00', new DateTimeZone('UTC')));
        } catch (Throwable) {
            return null;
        }
    }
}
