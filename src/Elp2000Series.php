<?php

namespace Astronomy;

use RuntimeException;

/**
 * Turns ELP 2000-82B, the theory of the Moon's motion, into PHP tables. Pure PHP: in a framework
 * application it is a console command that calls it, and on its own `Elp2000Series::regenerate()`
 * is enough.
 *
 * The Moon is the hard body. It moves thirteen degrees a day, which means an error of one minute
 * of clock already carries it half an arcminute, and its orbit is deformed by the Sun, by the
 * flattening of the Earth, by the planets, by the tides and even by relativity. That is why its
 * theory is thirty-seven thousand terms spread over thirty-six files, and not the six series per
 * coordinate that are enough for a planet.
 *
 * This is a literal port of `elp82b.f`, the subroutine Chapront-Touzé and Chapront published along
 * with the series themselves. It is not a free version: the same files, the same constants of the
 * fit to DE200 and the same construction of the arguments. Writing a lunar theory that «looks
 * like» it from memory is a guarantee of an error nobody is ever going to see.
 *
 * What does change is WHEN each thing is done. The original subroutine corrects the amplitude of
 * every term and composes its argument on every call; here that is done once, when generating, and
 * what is stored is the term already finished: final amplitude and the coefficients of the
 * polynomial in T of its argument. At run time all that is left is summing sines.
 *
 * And one more thing is stored that the subroutine does not need: how many times the Moon's MEAN
 * LONGITUDE enters the argument of each term (D, l and F carry it with coefficient one, and zeta
 * as well). Collapsing the argument into a polynomial lost that datum, and it is needed in order
 * to «run» the Moon along its orbit with the Sun held still, which is how the interpolated apogee
 * and perigee are computed. Recovering it from the frequency was tried (dividing by the lunar rate
 * and rounding) and it fails exactly where it weighs most: the fourteen-arcsecond Venus term,
 * 18V - 16T - l, has an almost null frequency because the planetary part cancels the rate of l, so
 * the rounding gives 0 and the multiplier is -1. Here it is known for certain, and that is why it
 * is written here.
 */
final class Elp2000Series
{
    /** Where the original series are published: catalogue VI/79 at the CDS in Strasbourg. */
    public const CDS = 'https://cdsarc.cds.unistra.fr/ftp/cats/VI/79';

    /** The truncation level in radians, the `prec` of the original subroutine. */
    /**
     * Truncation level in radians, the `prec` of the original subroutine.
     *
     * **It is 1e-8 because that is what the series that ship were built with**, and the same trap
     * as in `Vsop87Series`: the console command this came from defaulted to 1e-7 while the
     * committed files say «truncated to 1.0E-8, 2,246 terms kept out of 37,872», so a run with no
     * options rewrote the Moon coarser and nothing failed.
     *
     * And it is not worth going below: measured, from 1e-8 downwards the raw residual against
     * DE440 stays at 15.05 arcseconds against 15.07, so what is left is no longer truncation, it
     * is DE200 against DE440. Tripling the terms would triple the cost of every Moon, which
     * `Eclipses` and `Occultations` ask for thousands of times, to save a hundred kilobytes.
     */
    public const THRESHOLD = 1e-8;

    /** Arcseconds in a radian. */
    private const RAD = 648000 / M_PI;

    /** Semi-major axis of the lunar orbit used as the scale of the distance series, in km. */
    private const ATH = 384747.9806743165;

    private const A0 = 384747.9806448954;

    private const AM = 0.074801329518;

    private const ALPHA = 0.002571881335;

    /** @var array<int, list<float>> The fundamental arguments, as polynomials in T. */
    private array $del = [];

    /** @var list<float> */
    private array $zeta = [];

    /** @var list<float> Mean longitude of the Moon, polynomial in T. */
    private array $w1 = [];

    /** @var list<float> Mean longitude of the lunar perigee. */
    private array $w2 = [];

    /** @var list<float> Mean longitude of the lunar ascending node. */
    private array $w3 = [];

    /** @var float General precession in longitude, in arcseconds per century. */
    private float $precession = 0.0;

    /** @var array<int, list<float>> Mean longitudes of the eight planets. */
    private array $p = [];

    private float $delnu;

    private float $dele;

    private float $delg;

    private float $delnp;

    private float $delep;

    private float $dtasm;

    /**
     * **There is no completeness check on the download here, and it is not an oversight.**
     *
     * `Vsop87Series` has one, because every VSOP87 block declares its own count in its header
     * («367 TERMS») and a short download leaves a block with fewer rows than it announces. ELP's
     * files say only what they are («MAIN PROBLEM. LONGITUDE(SINE)»): there is no published
     * number to check against, so any guard here would be a threshold somebody chose, and a
     * threshold somebody chose is exactly what this engine does not put between a source and its
     * data.
     *
     * What that leaves open: a cut-off response from the CDS parses fewer terms and writes a
     * well-formed file, with no error and a Moon that answers a slightly different sky. The 2,246
     * terms the committed files carry are the check that exists, and it is made by a person
     * comparing, not by the code.
     */
    /**
     * Downloads the thirty-six original files and writes `elp2000/` into the data folder.
     *
     * What it leaves there is `constants.php`, with the three mean longitudes and the scales, and
     * `L.php`, `B.php` and `R.php`, with the terms of longitude, latitude and distance keyed by
     * the power of T their amplitude is multiplied by. `Moon` reads exactly that.
     *
     * @param HttpClient|null $http
     * @param float $threshold Truncation level in radians, the `prec` of the original subroutine.
     * @param string $source Where the series are downloaded from.
     * @return array{files: list<array{file: int, variable: string, lines: int}>, kept: int, read: int, paths: list<string>}
     *
     * @throws RuntimeException If any of the thirty-six files cannot be downloaded.
     */
    public static function regenerate(?HttpClient $http = null, float $threshold = self::THRESHOLD, string $source = self::CDS): array
    {
        return (new self())->build($http ?? new NativeHttpClient(), $threshold, $source);
    }

    private function __construct() {}

    /**
     * Reads the thirty-six files, keeps what is above the threshold and writes the tables.
     *
     * @param HttpClient $http
     * @param float $threshold
     * @param string $source
     * @return array{files: list<array{file: int, variable: string, lines: int}>, kept: int, read: int, paths: list<string>}
     *
     * @throws RuntimeException
     */
    private function build(HttpClient $http, float $threshold, string $source): array
    {
        $this->constants();

        $source = rtrim($source, '/');

        // The subroutine's thresholds: in arcseconds for longitude and latitude, in kilometres
        // for the distance, because the series come in those units.
        $limit = [
            1 => $threshold * self::RAD,
            2 => $threshold * self::RAD,
            3 => $threshold * self::ATH,
        ];

        /** @var array<int, array<int, list<array{float, float, float, float, float, float, int}>>> */
        $series = [1 => [], 2 => [], 3 => []];
        $read = 0;
        $kept = 0;
        $files = [];

        for ($file = 1; $file <= 36; $file++) {
            $url = "{$source}/ELP{$file}";

            try {
                $raw = $http->get($url);
            } catch (RuntimeException $error) {
                throw new RuntimeException("Could not download {$url}: ".$error->getMessage(), 0, $error);
            }

            // Which coordinate this file carries: they come in threes, longitude, latitude and
            // distance, over and over along the thirty-six.
            $variable = ($file - 1) % 3 + 1;

            $lines = preg_split('/\R/', $raw) ?: [];
            array_shift($lines); // The header with the name of the series.

            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }

                $read++;

                $term = match (true) {
                    $file <= 3 => $this->mainProblem($line, $file, $limit[$variable]),
                    $file <= 9, $file >= 22 => $this->perturbation($line, $file, $limit[$variable]),
                    default => $this->planetary($line, $file, $limit[$variable]),
                };

                if ($term === null) {
                    continue;
                }

                [$power, $amplitude, $argument, $moon] = $term;

                $series[$variable][$power][] = [$amplitude, ...$argument, $moon];
                $kept++;
            }

            $files[] = [
                'file' => $file,
                'variable' => ['', 'L', 'B', 'R'][$variable],
                'lines' => count($lines),
            ];
        }

        return [
            'files' => $files,
            'kept' => $kept,
            'read' => $read,
            'paths' => $this->write($series, $threshold, $kept, $read),
        ];
    }

    /**
     * The subroutine's constants, exactly as they are.
     *
     * @return void
     */
    private function constants(): void
    {
        $rad = self::RAD;
        $deg = M_PI / 180;
        $gms = fn (float $g, float $m, float $s) => ($g + $m / 60 + $s / 3600) * $deg;

        // Mean longitude of the Moon, of its perigee and of its node, and the Earth's.
        $w1 = [$gms(218, 18, 59.95571), 1732559343.73604 / $rad, -5.8883 / $rad, 0.6604e-2 / $rad, -0.3169e-4 / $rad];
        $w2 = [$gms(83, 21, 11.67475), 14643420.2632 / $rad, -38.2776 / $rad, -0.45047e-1 / $rad, 0.21301e-3 / $rad];
        $w3 = [$gms(125, 2, 40.39816), -6967919.3622 / $rad, 6.3622 / $rad, 0.7625e-2 / $rad, -0.3586e-4 / $rad];
        $earth = [$gms(100, 27, 59.22059), 129597742.2758 / $rad, -0.0202 / $rad, 0.9e-5 / $rad, 0.15e-6 / $rad];
        $perihelion = [$gms(102, 56, 14.42753), 1161.2283 / $rad, 0.5327 / $rad, -0.138e-3 / $rad, 0.0];

        $precessionInRadians = 5029.0966 / $rad;

        // Mean longitudes of the eight planets. The third one is the Earth's, which is already in
        // radians and that is why it is not multiplied by `deg` like the rest.
        $this->p = [
            0 => [$gms(252, 15, 3.25986), 538101628.68898 / $rad],
            1 => [$gms(181, 58, 47.28305), 210664136.43355 / $rad],
            2 => [$earth[0], $earth[1]],
            3 => [$gms(355, 25, 59.78866), 68905077.59284 / $rad],
            4 => [$gms(34, 21, 5.34212), 10925660.42861 / $rad],
            5 => [$gms(50, 4, 38.89694), 4399609.65932 / $rad],
            6 => [$gms(314, 3, 18.01841), 1542481.19393 / $rad],
            7 => [$gms(304, 20, 55.19575), 786550.32074 / $rad],
        ];

        // The fit of the constants to the JPL's DE200/LE200 ephemerides. Without this the theory
        // stays in its own system and does not match any modern ephemeris.
        $this->delnu = 0.55604 / $rad / $w1[1];
        $this->dele = 0.01789 / $rad;
        $this->delg = -0.08066 / $rad;
        $this->delnp = -0.06424 / $rad / $w1[1];
        $this->delep = -0.12879 / $rad;
        $this->dtasm = 2 * self::ALPHA / (3 * self::AM);

        // The Delaunay arguments, each one as a degree four polynomial in T.
        for ($k = 0; $k < 5; $k++) {
            $this->del[0][$k] = $w1[$k] - $earth[$k];
            $this->del[1][$k] = $earth[$k] - $perihelion[$k];
            $this->del[2][$k] = $w1[$k] - $w2[$k];
            $this->del[3][$k] = $w1[$k] - $w3[$k];
        }

        $this->del[0][0] += M_PI;

        $this->zeta = [$w1[0], $w1[1] + $precessionInRadians];
        $this->w1 = $w1;
        $this->w2 = $w2;
        $this->w3 = $w3;
        $this->precession = 5029.0966;
    }

    /**
     * ELP1 to ELP3: the main problem, that is Earth, Moon and Sun on their own.
     *
     * Fortran format `4i3,2x,f13.5,6(2x,f10.2)`: four multipliers of the Delaunay arguments and
     * seven coefficients, of which the first is the amplitude and the rest serve to correct it
     * according to the fit to DE200.
     *
     * @param string $line
     * @param int $file
     * @param float $limit
     * @return array{0: int, 1: float, 2: list<float>, 3: int}|null
     */
    private function mainProblem(string $line, int $file, float $limit): ?array
    {
        $ilu = [];

        for ($i = 0; $i < 4; $i++) {
            $ilu[$i] = (int) substr($line, $i * 3, 3);
        }

        $coefficients = [];
        $coefficients[0] = (float) substr($line, 12, 15);

        for ($i = 1; $i < 7; $i++) {
            $coefficients[$i] = (float) substr($line, 27 + ($i - 1) * 12, 12);
        }

        // The cut is made on the UNCORRECTED amplitude, as in the subroutine. Correcting first
        // and cutting afterwards would throw away different terms.
        if (abs($coefficients[0]) < $limit) {
            return null;
        }

        $tgv = $coefficients[1] + $this->dtasm * $coefficients[5];

        if ($file === 3) {
            $coefficients[0] -= 2 * $coefficients[0] * $this->delnu / 3;
        }

        $amplitude = $coefficients[0]
            + $tgv * ($this->delnp - self::AM * $this->delnu)
            + $coefficients[2] * $this->delg
            + $coefficients[3] * $this->dele
            + $coefficients[4] * $this->delep;

        $argument = array_fill(0, 5, 0.0);

        for ($k = 0; $k < 5; $k++) {
            for ($i = 0; $i < 4; $i++) {
                $argument[$k] += $ilu[$i] * $this->del[$i][$k];
            }
        }

        // The distance series are cosines, and here everything is summed as a sine. Ninety
        // degrees of phase turn one into the other and so the evaluator is a single one.
        if ($file === 3) {
            $argument[0] += M_PI / 2;
        }

        // D, l and F carry the mean longitude of the Moon; l' is the Sun's anomaly and does not.
        return [0, $amplitude, $argument, $ilu[0] + $ilu[2] + $ilu[3]];
    }

    /**
     * ELP4 to ELP9 and ELP22 to ELP36: figure of the Earth, tides, figure of the Moon,
     * relativity and solar eccentricity.
     *
     * Format `5i3,1x,f9.5,1x,f9.5,1x,f9.3`.
     *
     * @param string $line
     * @param int $file
     * @param float $limit
     * @return array{0: int, 1: float, 2: list<float>, 3: int}|null
     */
    private function perturbation(string $line, int $file, float $limit): ?array
    {
        $iz = (int) substr($line, 0, 3);
        $ilu = [];

        for ($i = 0; $i < 4; $i++) {
            $ilu[$i] = (int) substr($line, 3 + $i * 3, 3);
        }

        $phase = (float) substr($line, 15, 10);
        $amplitude = (float) substr($line, 25, 10);

        if ($amplitude < $limit) {
            return null;
        }

        // Poisson series: their amplitude grows with time. They are stored apart by power of T
        // instead of being multiplied out here, which is what the subroutine does.
        $power = match (true) {
            $file >= 7 && $file <= 9 => 1,
            $file >= 25 && $file <= 27 => 1,
            $file >= 34 => 2,
            default => 0,
        };

        $argument = [$phase * M_PI / 180, 0.0, 0.0, 0.0, 0.0];

        for ($k = 0; $k < 2; $k++) {
            $argument[$k] += $iz * $this->zeta[$k];

            for ($i = 0; $i < 4; $i++) {
                $argument[$k] += $ilu[$i] * $this->del[$i][$k];
            }
        }

        // Zeta is the mean longitude of the Moon with the precession on top: it counts as it.
        return [$power, $amplitude, $argument, $iz + $ilu[0] + $ilu[2] + $ilu[3]];
    }

    /**
     * ELP10 to ELP21: planetary perturbations.
     *
     * Format `11i3,1x,f9.5,1x,f9.5,1x,f9.3`. Tables 1 and 2 do not use the eleven multipliers the
     * same way, and mixing them up gives a Moon with an error of arcseconds that only shows up on
     * certain dates.
     *
     * @param string $line
     * @param int $file
     * @param float $limit
     * @return array{0: int, 1: float, 2: list<float>, 3: int}|null
     */
    private function planetary(string $line, int $file, float $limit): ?array
    {
        $ipla = [];

        for ($i = 0; $i < 11; $i++) {
            $ipla[$i] = (int) substr($line, $i * 3, 3);
        }

        $phase = (float) substr($line, 33, 10);
        $amplitude = (float) substr($line, 43, 10);

        if ($amplitude < $limit) {
            return null;
        }

        $power = ($file >= 13 && $file <= 15) || ($file >= 19 && $file <= 21) ? 1 : 0;

        $argument = [$phase * M_PI / 180, 0.0, 0.0, 0.0, 0.0];

        for ($k = 0; $k < 2; $k++) {
            if ($file <= 15) {
                // Table 1: the eight planets and three lunar arguments at the end.
                $argument[$k] += $ipla[8] * $this->del[0][$k]
                    + $ipla[9] * $this->del[2][$k]
                    + $ipla[10] * $this->del[3][$k];

                for ($i = 0; $i < 8; $i++) {
                    $argument[$k] += $ipla[$i] * $this->p[$i][$k];
                }
            } else {
                // Table 2: seven planets and the four Delaunay arguments.
                for ($i = 0; $i < 4; $i++) {
                    $argument[$k] += $ipla[$i + 7] * $this->del[$i][$k];
                }

                for ($i = 0; $i < 7; $i++) {
                    $argument[$k] += $ipla[$i] * $this->p[$i][$k];
                }
            }
        }

        // In table 1 the last three are D, l and F; in table 2 the last four are D, l', l and F,
        // and l' does not carry the Moon.
        $moon = $file <= 15
            ? $ipla[8] + $ipla[9] + $ipla[10]
            : $ipla[7] + $ipla[9] + $ipla[10];

        return [$power, $amplitude, $argument, $moon];
    }

    /**
     * Writes `constants.php` and the three series files, and says where they went.
     *
     * @param array<int, array<int, list<array{float, float, float, float, float, float, int}>>> $series
     * @param float $threshold
     * @param int $kept
     * @param int $read
     * @return list<string>
     */
    private function write(array $series, float $threshold, int $kept, int $read): array
    {
        $destination = DataFolder::path('elp2000');

        if (! is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        /* The mean longitude of the Moon is stored here and not copied into the class that
           evaluates. It is the dominant term: without it the longitude is not a longitude, it is
           a correction of a few degrees around zero. Having it written in two places is having it
           wrong in one of the two the day the tables are regenerated. */
        $constants = "<?php\n\n"
            ."/*\n"
            ." * ELP 2000-82B · evaluator constants. GENERATED by `astronomy elp2000`\n"
            ." * in this package.\n"
            ." */\n\n"
            ."return [\n"
            ."    // Mean longitude of the Moon, in radians, as a polynomial in T.\n"
            ."    'w1' => [\n";

        foreach ($this->w1 as $coefficient) {
            $constants .= sprintf("        %.15F,\n", $coefficient);
        }

        $constants .= "    ],\n\n";

        /* The perigee and the node come out of the SAME theory as the Moon, and that is why they
           are stored here instead of copying from somewhere else the formulas that go round the
           manuals.

           The check that they fit: the rate of the node in ELP is -1935.53 degrees per century
           and that of the precession is +1.397. Added up they give -1934.136, which is exactly
           the rate of the mean node everybody publishes. The same for the perigee: 4067.617 plus
           1.397 are 4069.014. That two different routes give the same number is the sign that the
           constants are the ones they have to be. */
        foreach (['w2' => $this->w2, 'w3' => $this->w3] as $name => $polynomial) {
            $constants .= '    // '.($name === 'w2' ? 'Mean longitude of the lunar perigee' : 'Mean longitude of the ascending node')
                .", in radians, as a polynomial in T.\n"
                ."    '{$name}' => [\n";

            foreach ($polynomial as $coefficient) {
                $constants .= sprintf("        %.15F,\n", $coefficient);
            }

            $constants .= "    ],\n\n";
        }

        $constants .= "    // General precession in longitude, in arcseconds per julian century. The three mean\n"
            ."    // longitudes above are referred to the INERTIAL equinox, so this has to be added to\n"
            ."    // carry them to the equinox of date.\n"
            .sprintf("    'precession' => %.10F,\n\n", $this->precession)
            ."    // Arcseconds per radian: the L and B series come in arcseconds.\n"
            .sprintf("    'rad' => %.10F,\n\n", self::RAD)
            ."    // Scale of the distance series.\n"
            .sprintf("    'distance_scale' => %.16F,\n", self::A0 / self::ATH)
            ."];\n";

        file_put_contents("{$destination}/constants.php", $constants);

        $paths = ["{$destination}/constants.php"];

        foreach ([1 => 'L', 2 => 'B', 3 => 'R'] as $index => $name) {
            $powers = $series[$index];
            ksort($powers);

            $php = "<?php\n\n";
            $php .= "/*\n";
            $php .= " * ELP 2000-82B · ".match ($name) {
                'L' => 'longitude (arcseconds)',
                'B' => 'latitude (arcseconds)',
                'R' => 'distance (kilometers)',
            }."\n";
            $php .= " *\n";
            $php .= " * GENERATED. Do not edit by hand: written by `astronomy elp2000`\n";
            $php .= " * in this package, from the original series of Chapront-Touzé and Chapront published at\n";
            $php .= " * the CDS in Strasbourg.\n";
            $php .= " *\n";
            $php .= " * Each term is [amplitude, c0, c1, c2, c3, c4, m] and equals amplitude·sin(c0 + c1·T +\n";
            $php .= " * ...), with T in Julian centuries since J2000. The key above is the power of T that\n";
            $php .= " * the amplitude is additionally multiplied by (Poisson series grow with time).\n";
            $php .= " *\n";
            $php .= " * The trailing integer m is how many times the Moon's MEAN LONGITUDE enters the\n";
            $php .= " * argument (the multipliers of D, l and F added up, plus zeta's where there is one).\n";
            $php .= " * It is not needed to evaluate the series: it is needed to run the Moon along its\n";
            $php .= " * orbit with the Sun held still, which is how the interpolated apogee and perigee are\n";
            $php .= " * computed. It cannot be recovered from the frequency: in 18V - 16T - l, the\n";
            $php .= " * fourteen-arcsecond Venus term, the planetary part cancels the rate of l and\n";
            $php .= " * rounding gives 0 where it should be -1.\n";
            $php .= " *\n";
            $php .= " * Truncated to {$threshold} rad. {$kept} terms kept out of {$read} read in total.\n";
            $php .= " */\n\n";
            $php .= "return [\n";

            foreach ($powers as $power => $terms) {
                $php .= "    {$power} => [\n";

                foreach ($terms as $t) {
                    $php .= sprintf(
                        "        [%.8F, %.12F, %.12F, %.12F, %.12F, %.12F, %d],\n",
                        $t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6]
                    );
                }

                $php .= "    ],\n";
            }

            file_put_contents("{$destination}/{$name}.php", $php."];\n");
            $paths[] = "{$destination}/{$name}.php";
        }

        return $paths;
    }
}
