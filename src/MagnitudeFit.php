<?php

namespace Astronomy;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Fits each body's brightness against the magnitude JPL Horizons publishes, and writes
 * `magnitudes.php`, which is what `Magnitudes` reads.
 *
 * The model is `V = 5·log10(r·Δ) + f(α)`: the first two terms are geometry and there is nothing to
 * fit in them, so they are taken off the published magnitude and what is left is `f(α)`, which is
 * the only thing that knows anything about each planet. That is fitted with Chebyshev and stored.
 *
 * **Why it is fitted instead of copying the published polynomials.** There are published ones, and
 * they are good (Mallama and Hilton, 2018), but they are several dozen coefficients written by hand
 * and one badly transcribed gives a magnitude that is believable and false. Fitting against the
 * source, the residual this reports IS the check: if the transcription were wrong, there would be
 * no transcription left to spoil.
 *
 * **Saturn takes two variables because it has rings.** Between the open ring and the edge-on ring
 * there is almost a whole magnitude, and that is not a function of the phase angle: it is of the
 * ring opening, which Horizons publishes as the latitude of the observer. It is fitted apart and
 * added.
 *
 * Plain PHP, and it prints nothing: it returns what it measured so that whoever called it can say
 * it. In a framework application it is a console command that calls this, and on its own
 * `MagnitudeFit::regenerate()` is enough.
 */
final class MagnitudeFit
{
    /**
     * What Horizons is asked for per body: from, to and step.
     *
     * The inner ones run through the whole range of phases in a couple of years, so twenty years at
     * a short step is plenty. The outer ones never move away from full, and what has to be covered
     * in them is the long cycle: Saturn's is the thirty years of the ring.
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const REQUESTS = [
        'mercury' => ['1990-01-01', '2010-01-01', '2 d'],
        'venus' => ['1990-01-01', '2010-01-01', '2 d'],
        'mars' => ['1990-01-01', '2010-01-01', '2 d'],
        'jupiter' => ['1990-01-01', '2010-01-01', '3 d'],
        'saturn' => ['1970-01-01', '2030-01-01', '7 d'],
        'uranus' => ['1970-01-01', '2030-01-01', '7 d'],
        'neptune' => ['1970-01-01', '2030-01-01', '7 d'],
        'pluto' => ['1970-01-01', '2030-01-01', '7 d'],
        'moon' => ['2000-01-01', '2004-01-01', '3 h'],
        /* Chiron, Pholus and the four asteroids. Their brightness goes by the H-G model, which is
           also a function of the phase angle, so it fits in the same fit with no case of its own.
           They are in the chart, and giving them a null brightness while the datum is there would
           be leaving them half done. */
        'chiron' => ['1990-01-01', '2010-01-01', '5 d'],
        'pholus' => ['1990-01-01', '2010-01-01', '5 d'],
        'ceres' => ['1990-01-01', '2010-01-01', '5 d'],
        'pallas' => ['1990-01-01', '2010-01-01', '5 d'],
        'juno' => ['1990-01-01', '2010-01-01', '5 d'],
        'vesta' => ['1990-01-01', '2010-01-01', '5 d'],
    ];

    /** Horizons' endpoint. */
    private const URL = 'https://ssd.jpl.nasa.gov/api/horizons.api';

    /** The highest degree that is tried for a fit. */
    private const MAX_DEGREE = 24;

    /**
     * How few rows are too few to fit anything with.
     *
     * Horizons leaves the magnitude blank while a body is behind the Sun, so a response always
     * arrives with holes in it; a hundred usable rows is the line under which the response was not
     * what was asked for.
     */
    private const MIN_ROWS = 100;

    /**
     * Fits every body of `REQUESTS` and writes the table into the data folder.
     *
     * @param HttpClient|null $http
     * @param string|null $output Where to write it; by default `magnitudes.php` in the data folder.
     * @return array{bodies: array<string, array<string, mixed>>, path: string}
     *
     * @throws RuntimeException If Horizons does not answer with usable rows for some body, in which
     *                          case nothing is written.
     */
    public static function regenerate(?HttpClient $http = null, ?string $output = null): array
    {
        $http ??= new NativeHttpClient();
        $models = [];

        foreach (array_keys(self::REQUESTS) as $key) {
            $models[$key] = self::fitFor(Body::from($key), $http);
        }

        return ['bodies' => $models, 'path' => self::write($models, $output)];
    }

    /**
     * One body's model, fitted against the magnitudes Horizons publishes for it.
     *
     * It is public so that whoever drives the run can say which body is being asked for before the
     * request goes out: a full run is fifteen requests and a couple of minutes.
     *
     * Besides what gets written it carries what was measured: `points`, how many usable rows came
     * back, and for Saturn `flattening` and `worstOpening`, which are the two numbers that say
     * whether the ring model holds.
     *
     * @param Body $body
     * @param HttpClient|null $http
     * @return array{alpha: array{0: float, 1: float}, coefficients: list<float>, degree: int, residual: float, worst: float, points: int, ring?: array{range: array{0: float, 1: float}, coefficients: list<float>, degree: int, pole: array{0: float, 1: float, 2: float}}, flattening?: float, worstOpening?: float}
     *
     * @throws RuntimeException If there is no request for this body, or Horizons does not answer
     *                          with usable rows.
     */
    public static function fitFor(Body $body, ?HttpClient $http = null): array
    {
        [$from, $to, $step] = self::REQUESTS[$body->value]
            ?? throw new RuntimeException("There is no magnitude request for {$body->value}.");

        $rows = self::observations($http ?? new NativeHttpClient(), $body, $from, $to, $step);
        $model = self::fit($rows, $body === Body::Saturn);
        $model['points'] = count($rows);

        return $model;
    }

    /**
     * Writes the table and returns the path it went to.
     *
     * @param array<string, array<string, mixed>> $models By body value, as `fitFor` returns them.
     * @param string|null $output
     * @return string
     */
    public static function write(array $models, ?string $output = null): string
    {
        $output = $output ?: DataFolder::path('magnitudes.php');
        $lines = [
            '<?php',
            '',
            '/*',
            ' * Visual magnitudes, fitted against JPL Horizons',
            ' *',
            ' * GENERATED. Do not edit by hand: written by `astronomy magnitudes`',
            ' * in this package.',
            ' *',
            ' * The model is V = 5·log10(r·Δ) + f(α). Only f is stored here, as Chebyshev',
            ' * coefficients over the phase angle normalized to [-1, 1] within its range.',
            ' * Saturn also carries a term for the ring opening angle, worth almost a full',
            ' * magnitude between the rings open and edge-on.',
            ' *',
            " * `residual` is the mean error of the fit against Horizons' own magnitude and",
            ' * `worst` the largest, both in magnitudes. Outside `alpha` there is no',
            ' * extrapolation: the magnitude comes back null, because a polynomial outside its',
            " * range doesn't give an error, it gives a number.",
            ' *',
            " * The degree of each fit wasn't picked by eye: it was measured on the half of the",
            ' * points the fit never saw. It is written next to each body.',
            ' *',
            " * Saturn's pole is also a fit, from the ring opening angle that Horizons",
            ' * publishes, and it is a unit vector in the J2000 ecliptic.',
            ' */',
            '',
            'return [',
        ];

        foreach ($models as $key => $model) {
            $lines[] = "    '{$key}' => [";
            $lines[] = sprintf("        'alpha' => [%.6F, %.6F],", $model['alpha'][0], $model['alpha'][1]);
            /* In English, which is how `Magnitudes::of()` reads it: `$model['coefficients']`. It was
               the only key of this method left in Spanish, with the ring's one three lines below
               already right, so a freshly generated file left all fifteen bodies with no brightness
               while Saturn's ring went on working. */
            $lines[] = '        \'coefficients\' => ['.self::numbers($model['coefficients']).'],';

            if (isset($model['ring'])) {
                $lines[] = sprintf(
                    "        'ring' => [\n"
                        ."            'range' => [%.6F, %.6F],\n"
                        ."            'degree' => %d,\n"
                        ."            'pole' => [%s],\n"
                        ."            'coefficients' => [%s],\n"
                        .'        ],',
                    $model['ring']['range'][0],
                    $model['ring']['range'][1],
                    $model['ring']['degree'],
                    self::numbers($model['ring']['pole']),
                    self::numbers($model['ring']['coefficients'])
                );
            }

            $lines[] = sprintf("        'degree' => %d,", $model['degree']);
            $lines[] = sprintf("        'residual' => %.6F,", $model['residual']);
            $lines[] = sprintf("        'worst' => %.6F,", $model['worst']);
            $lines[] = '    ],';
        }

        $lines[] = '];';

        file_put_contents($output, implode("\n", $lines)."\n");

        return $output;
    }

    /**
     * Magnitude, distances and phase angle according to Horizons.
     *
     * @param HttpClient $http
     * @param Body $body
     * @param string $from
     * @param string $to
     * @param string $step
     * @return list<array{v: float, r: float, delta: float, alpha: float, ring: float, date: string}>
     *
     * @throws RuntimeException
     */
    private static function observations(HttpClient $http, Body $body, string $from, string $to, string $step): array
    {
        /* The CENTRE of the planet and not its barycentre, the other way round from what the
           verification does: the magnitude and the diameter are the body's, and Horizons gives no
           brightness to a barycentre, because a point of mass reflects no light. The small bodies
           go by the identifier `Body` already knows, which for them IS the object. */
        $identifier = match ($body) {
            Body::Mercury => '199', Body::Venus => '299', Body::Moon => '301',
            Body::Mars => '499', Body::Jupiter => '599', Body::Saturn => '699',
            Body::Uranus => '799', Body::Neptune => '899', Body::Pluto => '999',
            default => $body->horizonsId()
                ?? throw new RuntimeException('There is no identifier for '.$body->value),
        };

        $text = $http->get(self::URL.'?'.http_build_query([
            'format' => 'text',
            'COMMAND' => "'{$identifier}'",
            'EPHEM_TYPE' => 'OBSERVER',
            'CENTER' => "'500@399'",
            'START_TIME' => "'{$from}'",
            'STOP_TIME' => "'{$to}'",
            'STEP_SIZE' => "'{$step}'",
            // 9 magnitude, 14 the observer's latitude (the ring opening), 19 distance to the Sun,
            // 20 distance to here, 24 phase angle.
            'QUANTITIES' => "'9,14,19,20,24'",
            'ANG_FORMAT' => 'DEG',
            'CSV_FORMAT' => 'YES',
        ]));

        // With `strpos` and not with a lazy regular expression: over responses of more than a
        // megabyte, PCRE goes past its backtracking limit and returns false without throwing
        // anything.
        $start = strpos($text, '$$SOE');
        $end = strpos($text, '$$EOE');

        if ($start === false || $end === false) {
            throw new RuntimeException('No data block: '.substr(trim($text), -200));
        }

        $header = self::columns($text, $start);
        $columns = ['APmag' => 'v', 'r' => 'r', 'delta' => 'delta', 'S-T-O' => 'alpha'];

        // The observer's latitude is only needed where there is a ring to measure, and Horizons
        // does not give it to an asteroid.
        if ($body === Body::Saturn) {
            $columns['ObsSub-LAT'] = 'ring';
        }

        /* A column that is not there is NOT filled with zero: it is reported. Horizons calls it
           `ObsSub-LAT` and not `Obsrv-lat`, which is what this code used to say, and since the gap
           was being filled with zeros the ring fit ran on a constant and the normal system blew up
           as singular. It blew up, and it was lucky: with a single different point it would have
           fitted noise and returned magnitudes that looked fine. */
        foreach (array_keys($columns) as $column) {
            if (! isset($header[$column])) {
                throw new RuntimeException(
                    "Horizons does not bring the column {$column}. It brings: ".implode(', ', array_keys($header))
                );
            }
        }

        $rows = [];

        foreach (preg_split('/\R/', trim(substr($text, $start + 5, $end - $start - 5))) as $line) {
            $fields = array_map('trim', explode(',', $line));
            $row = [];

            foreach ($columns as $column => $name) {
                $index = $header[$column];
                $row[$name] = isset($fields[$index]) && is_numeric($fields[$index])
                    ? (float) $fields[$index]
                    : null;
            }

            $row['date'] = $fields[0] ?? '';

            // With no magnitude there is nothing to fit, and Horizons leaves it blank when the body
            // is behind the Sun.
            if ($row['v'] === null || $row['r'] === null || $row['delta'] === null
                || $row['alpha'] === null) {
                continue;
            }

            $rows[] = ['v' => $row['v'], 'r' => $row['r'], 'delta' => $row['delta'],
                'alpha' => $row['alpha'], 'ring' => $row['ring'] ?? 0.0, 'date' => $row['date']];
        }

        if (count($rows) < self::MIN_ROWS) {
            throw new RuntimeException('Only '.count($rows).' usable rows.');
        }

        return $rows;
    }

    /**
     * Where each column is, READ from the header and not counted by hand.
     *
     * Horizons puts columns of empty flags in between and how many there are changes with what is
     * asked for, so reading by a fixed position is a comparison that one day starts measuring
     * something else without anything failing. It is already recorded in the verification command.
     *
     * @param string $text
     * @param int $start
     * @return array<string, int>
     *
     * @throws RuntimeException
     */
    private static function columns(string $text, int $start): array
    {
        $lines = preg_split('/\R/', substr($text, 0, $start));
        $columns = [];

        // The header is the last line with commas before the data block.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (! str_contains($lines[$i], ',')) {
                continue;
            }

            foreach (array_map('trim', explode(',', $lines[$i])) as $index => $name) {
                if ($name !== '') {
                    $columns[$name] = $index;
                }
            }

            if ($columns !== []) {
                return $columns;
            }
        }

        throw new RuntimeException('The column header cannot be found.');
    }

    /**
     * Fits f(α) and, if it is needed, the ring term.
     *
     * **The degree is not picked by eye: it is measured.** It is fitted with half the points and the
     * error is measured on the OTHER half, the one the fit has not seen, and the degree goes up
     * while that improves. Measuring on the fit's own points the error always falls as the degree
     * goes up, so that measurement does not say which one is right: it says the highest always
     * wins, which is exactly how a degree that oscillates between the points gets chosen. It is the
     * same trap already recorded in the correction towards the JPL.
     *
     * @param list<array{v: float, r: float, delta: float, alpha: float, ring: float, date: string}> $rows
     * @param bool $withRing
     * @return array{alpha: array{0: float, 1: float}, coefficients: list<float>, degree: int, residual: float, worst: float, ring?: array{range: array{0: float, 1: float}, coefficients: list<float>, degree: int, pole: array{0: float, 1: float, 2: float}}, flattening?: float, worstOpening?: float}
     *
     * @throws RuntimeException
     */
    private static function fit(array $rows, bool $withRing): array
    {
        $alphas = array_column($rows, 'alpha');
        $min = min($alphas);
        $max = max($alphas);

        // What is left once the geometry is taken off: that is f(α).
        $f = array_map(
            fn (array $row): float => $row['v'] - 5.0 * log10($row['r'] * $row['delta']),
            $rows
        );

        $x = array_map(fn (float $a): float => self::normalize($a, $min, $max), $alphas);

        [$coefficients, $degree] = self::bestDegree($x, $f);
        $model = ['alpha' => [$min, $max], 'coefficients' => $coefficients, 'degree' => $degree];

        if ($withRing) {
            /* The residual the phase fit leaves IS the signal of the ring, so it is fitted on that.
               With the opening in absolute value: the ring looks just as bright from above as from
               below, and telling the two sides apart would be fitting noise. */
            ['pole' => $pole, 'openings' => $centric, 'flattening' => $flattening,
                'worstOpening' => $worstOpening] = self::pole($rows);
            $residuals = [];
            $openings = [];

            foreach ($rows as $i => $row) {
                $residuals[] = $f[$i] - Chebyshev::evaluate($coefficients, $x[$i]);
                $openings[] = abs($centric[$i]);
            }

            $minRing = min($openings);
            $maxRing = max($openings);
            $y = array_map(fn (float $b): float => self::normalize($b, $minRing, $maxRing), $openings);

            [$ringCoefficients, $ringDegree] = self::bestDegree($y, $residuals);

            $model['ring'] = [
                'range' => [$minRing, $maxRing],
                'coefficients' => $ringCoefficients,
                'degree' => $ringDegree,
                'pole' => $pole,
            ];

            $model['flattening'] = $flattening;
            $model['worstOpening'] = $worstOpening;

            // It is measured against the planetocentric opening, which is the one that was fitted
            // and the one `Phenomena` knows how to compute; the one Horizons publishes is another
            // thing.
            foreach ($rows as $i => $row) {
                $rows[$i]['ring'] = $centric[$i];
            }
        }

        [$model['residual'], $model['worst']] = self::measure($rows, $model, $f, $x);

        return $model;
    }

    /**
     * The degree that generalizes best, measured on points the fit has not seen.
     *
     * @param list<float> $x
     * @param list<float> $y
     * @return array{0: list<float>, 1: int}
     */
    private static function bestDegree(array $x, array $y): array
    {
        $xFit = [];
        $yFit = [];
        $xTest = [];
        $yTest = [];

        foreach ($x as $i => $value) {
            if ($i % 2 === 0) {
                $xFit[] = $value;
                $yFit[] = $y[$i];
            } else {
                $xTest[] = $value;
                $yTest[] = $y[$i];
            }
        }

        $bestError = INF;
        $bestDegree = 0;

        for ($degree = 2; $degree <= self::MAX_DEGREE; $degree++) {
            $coefficients = Chebyshev::fit($xFit, $yFit, $degree);
            $error = 0.0;

            foreach ($xTest as $i => $value) {
                $error = max($error, abs($yTest[$i] - Chebyshev::evaluate($coefficients, $value)));
            }

            // Improving for real is required, not scraping thousandths: otherwise the highest
            // degree always wins on noise and coefficients that add nothing get stored.
            if ($error < $bestError * 0.98) {
                $bestError = $error;
                $bestDegree = $degree;
            }
        }

        // With the degree chosen on half the sample, the one that gets stored is fitted with all.
        return [Chebyshev::fit($x, $y, $bestDegree), $bestDegree];
    }

    /**
     * Saturn's pole and the planet's flattening, both FITTED from the ring opening that Horizons
     * publishes.
     *
     * The ring opening is the angle between the Saturn-Earth direction and the planet's equator:
     * `sin B = −n · u`, with `n` the pole and `u` the direction from here to Saturn. With `u`
     * computed by our own ephemerides and `B` published, `n` comes out of a linear fit with three
     * unknowns.
     *
     * **And there is one turn more, which was discovered by measuring.** Fitting the pole as it
     * comes gives a vector of modulus 1.16 instead of 1, and that 16% is not noise: Horizons
     * publishes the **planetodetic** latitude, the one measured against the normal to the
     * ellipsoid, and Saturn is flattened by almost ten per cent. The one the ring answers to is the
     * planetocentric one, and between the two there are five degrees. Since the two are related by
     * `tan φc = (1−f)² · tan φd`, the flattening is obtained **by requiring the pole to come out
     * unitary**: it is the only `f` that manages it, and it is found by bisection.
     *
     * That this fit gives Saturn's published flattening without having been told it is the check
     * that the model is the right one. The guard on the modulus is what caught this: without it,
     * the ring term would have been fitted against the wrong latitude and would have given
     * believable, crooked magnitudes.
     *
     * @param list<array{ring: float, date: string}> $rows
     * @return array{pole: array{0: float, 1: float, 2: float}, openings: list<float>, flattening: float, worstOpening: float}
     *
     * @throws RuntimeException
     */
    private static function pole(array $rows): array
    {
        $directions = [];

        foreach ($rows as $row) {
            $date = DateTimeImmutable::createFromFormat(
                'Y-M-d H:i',
                $row['date'],
                new DateTimeZone('UTC')
            );

            if ($date === false) {
                throw new RuntimeException('The date from Horizons cannot be read: '.$row['date']);
            }

            $jdTT = Time::tt(Time::julianDay($date));
            $position = Ephemeris::position(Body::Saturn, $jdTT);
            $lambda = deg2rad($position->longitude);
            $beta = deg2rad($position->latitude);

            /* In the J2000 ecliptic and not in the one of the date: the pole stands still in space
               and the ecliptic of the date turns, so fitting it there the result would be an
               average of sixty years of precession, almost a degree of blur. */
            $directions[] = [
                Precession::toJ2000(
                    [cos($beta) * cos($lambda), cos($beta) * sin($lambda), sin($beta)],
                    ($jdTT - 2451545.0) / 36525.0
                ),
                deg2rad($row['ring']),
            ];
        }

        // The modulus of the pole falls as the flattening grows, so there is a single f that leaves
        // it at one and it is cornered by bisection.
        $from = 0.0;
        $to = 0.3;

        for ($i = 0; $i < 60; $i++) {
            $middle = ($from + $to) / 2.0;

            if (self::poleModulus($directions, $middle) > 1.0) {
                $from = $middle;
            } else {
                $to = $middle;
            }
        }

        $flattening = ($from + $to) / 2.0;
        [$n, $modulus] = self::fitPole($directions, $flattening);

        if (abs($modulus - 1.0) > 1e-6) {
            throw new RuntimeException(sprintf(
                'The fitted pole is not unitary (modulus %.6f) with flattening %.6f.',
                $modulus,
                $flattening
            ));
        }

        $openings = [];
        $worst = 0.0;

        foreach ($directions as [$u, $detic]) {
            $centric = atan((1.0 - $flattening) ** 2 * tan($detic));
            $computed = -($n[0] * $u[0] + $n[1] * $u[1] + $n[2] * $u[2]);
            $openings[] = rad2deg($centric);
            $worst = max($worst, abs(rad2deg(asin(max(-1.0, min(1.0, $computed))) - $centric)));
        }

        return ['pole' => $n, 'openings' => $openings, 'flattening' => $flattening, 'worstOpening' => $worst];
    }

    /**
     * @param list<array{0: array{0: float, 1: float, 2: float}, 1: float}> $directions
     * @param float $flattening
     * @return float
     */
    private static function poleModulus(array $directions, float $flattening): float
    {
        return self::fitPole($directions, $flattening)[1];
    }

    /**
     * Least squares of the pole for a given flattening.
     *
     * @param list<array{0: array{0: float, 1: float, 2: float}, 1: float}> $directions
     * @param float $flattening
     * @return array{0: array{0: float, 1: float, 2: float}, 1: float}
     *
     * @throws RuntimeException
     */
    private static function fitPole(array $directions, float $flattening): array
    {
        $matrix = array_fill(0, 3, array_fill(0, 4, 0.0));

        foreach ($directions as [$u, $detic]) {
            $sine = sin(atan((1.0 - $flattening) ** 2 * tan($detic)));

            for ($i = 0; $i < 3; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $matrix[$i][$j] += $u[$i] * $u[$j];
                }

                // sin B = −n · u, so the independent term carries the sign the other way round.
                $matrix[$i][3] += $u[$i] * (-$sine);
            }
        }

        $n = self::solve3($matrix);
        $modulus = sqrt($n[0] ** 2 + $n[1] ** 2 + $n[2] ** 2);

        return [[$n[0] / $modulus, $n[1] / $modulus, $n[2] / $modulus], $modulus];
    }

    /**
     * Solves a three by three system by elimination with pivoting.
     *
     * @param list<list<float>> $matrix
     * @return array{0: float, 1: float, 2: float}
     *
     * @throws RuntimeException
     */
    private static function solve3(array $matrix): array
    {
        for ($column = 0; $column < 3; $column++) {
            $pivot = $column;

            for ($row = $column + 1; $row < 3; $row++) {
                if (abs($matrix[$row][$column]) > abs($matrix[$pivot][$column])) {
                    $pivot = $row;
                }
            }

            if (abs($matrix[$pivot][$column]) < 1e-300) {
                throw new RuntimeException('The pole system is singular.');
            }

            [$matrix[$column], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$column]];

            for ($row = 0; $row < 3; $row++) {
                if ($row === $column) {
                    continue;
                }

                $factor = $matrix[$row][$column] / $matrix[$column][$column];

                for ($k = $column; $k <= 3; $k++) {
                    $matrix[$row][$k] -= $factor * $matrix[$column][$k];
                }
            }
        }

        return [
            $matrix[0][3] / $matrix[0][0],
            $matrix[1][3] / $matrix[1][1],
            $matrix[2][3] / $matrix[2][2],
        ];
    }

    /**
     * Mean error and worst error of the model over every point that was asked for.
     *
     * @param list<array{ring: float}> $rows
     * @param array<string, mixed> $model
     * @param list<float> $f
     * @param list<float> $x
     * @return array{0: float, 1: float}
     */
    private static function measure(array $rows, array $model, array $f, array $x): array
    {
        $sum = 0.0;
        $worst = 0.0;

        foreach ($f as $i => $value) {
            $computed = Chebyshev::evaluate($model['coefficients'], $x[$i]);

            if (isset($model['ring'])) {
                $computed += Chebyshev::evaluate(
                    $model['ring']['coefficients'],
                    self::normalize(
                        abs($rows[$i]['ring']),
                        $model['ring']['range'][0],
                        $model['ring']['range'][1]
                    )
                );
            }

            $error = abs($value - $computed);
            $sum += $error;
            $worst = max($worst, $error);
        }

        return [$sum / count($f), $worst];
    }

    /**
     * @param float $value
     * @param float $min
     * @param float $max
     * @return float
     */
    private static function normalize(float $value, float $min, float $max): float
    {
        return $max <= $min ? 0.0 : 2.0 * ($value - $min) / ($max - $min) - 1.0;
    }

    /**
     * @param list<float> $numbers
     * @return string
     */
    private static function numbers(array $numbers): string
    {
        return implode(', ', array_map(fn (float $c): string => sprintf('%.10F', $c), $numbers));
    }
}
