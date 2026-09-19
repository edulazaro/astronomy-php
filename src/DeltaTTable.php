<?php

namespace Astronomy;

use RuntimeException;
use ZipArchive;

/**
 * Downloads the delta T values and leaves them in `resources/astro/deltat.php`.
 *
 * Delta T is what the Terrestrial Time of the planets drifts from the Universal Time of the
 * houses, and it goes into everything that mixes the two scales: rise and set times, eclipses,
 * occultations, the ascendant. It is not computed, it is measured: it is how much the rotation of
 * the Earth has slowed down, and that is only known by watching the clock. With the Espenak and
 * Meeus polynomials, which are a 2006 fit extrapolated, the engine ran six seconds high in 2026
 * and as much as thirty-two in 1605, and those six seconds were a systematic shift of every UT
 * instant the engine gives out.
 *
 * What is done here is what Swiss Ephemeris has done since its version 2.06, which is the
 * reference everything else is verified against. Three stretches and three sources, all of them
 * public and none of them typed in by hand:
 *
 * - Before 1955: the cubic spline fit of Stephenson, Morrison and Hohenkerk («Measurement of the
 *   Earth's Rotation: 720 BC to AD 2015», Proc. R. Soc. A, 2016), which is their reconstruction
 *   from Babylonian, Chinese and Arab eclipses and from telescopic occultations. It is the 54
 *   segments of Table S15 of the paper's supplementary material, which is open access and served
 *   by Europe PMC. The table of yearly values from 1620 of the Astronomical Almanac, which is
 *   what Swiss used up to 2.05, no longer rules over any range.
 * - From 1955 to 1973: the USNO's semestral values (`historic_deltat.data`), which are the ones
 *   from the Astronomical Almanac. From 1955 there are atomic clocks and delta T stops depending
 *   on any lunar theory.
 * - From February 1973: one value per month reconstructed from the IERS series, which is who
 *   measures the rotation of the Earth: `delta T = 32.184 + (TAI-UTC) - (UT1-UTC)`, with UT1-UTC
 *   from the `finals2000A.all` file and the leap seconds from `Leap_Second.dat`. The file also
 *   carries the IERS's own prediction for the following year, and it is written down marking how
 *   far what was measured reaches.
 *
 * And the leap second table is written as well, which until now was used for the computation and
 * thrown away. Delta T carries it ALREADY SUMMED IN inside each monthly value, so with the old
 * file there was no way to separate UTC from UT1: the wall clock is UTC and what moves the sky is
 * UT1, and they drift up to 0.9 seconds, which in the ascendant is 12 arcseconds of median and up
 * to eight minutes near the polar circle. They are 28 rows of date and whole second, they do not
 * change unless the IERS decides on a new leap, and `Time::taiMinusUtc()` reads them.
 *
 * The USNO files at maia.usno.navy.mil have not been answering from here for some time, so each
 * source has a list of addresses and the first one that answers is used; the report says which.
 * For the USNO the fallback is the Internet Archive's copy, which is the same file.
 *
 * Everything is parsed with regular expressions, and the count and the shape are demanded before
 * a single byte is written, because a missing month or a column read one place over give no
 * error: they give a slightly wrong delta T that nobody sees in a chart wheel. And what is
 * reconstructed from the IERS is cross-checked against what the USNO computed on its own in the
 * years where the two sources overlap, which is what catches a miscounted column or a lost leap
 * second.
 */
final class DeltaTTable
{
    /**
     * The supplementary material of the 2016 paper, served by Europe PMC as a zip carrying
     * another zip inside (`rspa20160404supp2.zip`) with Table S15 as text.
     */
    private const SPLINE_SOURCES = [
        'https://www.ebi.ac.uk/europepmc/webservices/rest/PMC5247521/supplementaryFiles',
    ];

    private const INNER_ZIP = 'rspa20160404supp2.zip';

    private const SPLINE_FILE = 'Table-S15.txt';

    /** Segments of Table S15, from -720 to 2016. */
    private const SPLINE_SEGMENTS = 54;

    /** The USNO's semestral values since 1657, which from 1955 onward are the Astronomical Almanac's. */
    private const HISTORIC_SOURCES = [
        'https://maia.usno.navy.mil/ser7/historic_deltat.data',
        'https://web.archive.org/web/2099id_/https://maia.usno.navy.mil/ser7/historic_deltat.data',
    ];

    /** The IERS's Earth orientation: daily UT1-UTC since 2 January 1973, with a year of prediction. */
    private const FINALS_SOURCES = [
        'https://datacenter.iers.org/data/9/finals2000A.all',
        'https://maia.usno.navy.mil/ser7/finals2000A.all',
    ];

    /** Leap seconds, from the IERS Earth Orientation Centre (Paris Observatory). */
    private const LEAP_SECOND_SOURCES = [
        'https://hpiers.obspm.fr/iers/bul/bulc/Leap_Second.dat',
    ];

    /** First year taken from the USNO: where the atomic clocks begin and where Swiss leaves the spline. */
    private const TABLE_START_YEAR = 1955.0;

    /** Last year taken from the USNO: from February 1973 onward the IERS months rule. */
    private const HISTORIC_END_YEAR = 1973.0;

    /** What separates TAI from TT, in seconds. */
    private const TT_MINUS_TAI = 32.184;

    /**
     * Downloads the four sources, checks them against each other and writes `deltat.php`.
     *
     * Nothing is written until everything has been parsed, counted and cross-checked: a file left
     * half written is a delta T that is slightly wrong everywhere, and that does not fail, it just
     * answers a different sky.
     *
     * @param HttpClient|null $http
     * @return array{spline: int, values: int, leapSeconds: int, observedUntil: float, predictedUntil: float, overlapYears: int, sources: array<string, string>, unreachable: list<string>, path: string}
     *
     * @throws RuntimeException If any source does not answer or does not have the expected shape.
     */
    public static function regenerate(?HttpClient $http = null): array
    {
        $http ??= new NativeHttpClient();
        $unreachable = [];

        [$splineBody, $splineSource] = self::first($http, self::SPLINE_SOURCES, $unreachable);
        $spline = self::spline($splineBody);

        [$historicBody, $historicSource] = self::first($http, self::HISTORIC_SOURCES, $unreachable);
        $historic = self::historic($historicBody);

        [$leapBody, $leapSource] = self::first($http, self::LEAP_SECOND_SOURCES, $unreachable);
        $leapSeconds = self::leapSeconds($leapBody);

        [$finalsBody, $finalsSource] = self::first($http, self::FINALS_SOURCES, $unreachable);
        [$monthly, $observedUntil] = self::monthly($finalsBody, $leapSeconds);

        $overlapYears = self::crossCheck($historic, $monthly);

        $table = array_merge(
            array_values(array_filter($historic, fn (array $row) => $row[0] >= self::TABLE_START_YEAR && $row[0] <= self::HISTORIC_END_YEAR)),
            $monthly
        );

        for ($i = 1; $i < count($table); $i++) {
            if ($table[$i][1] <= $table[$i - 1][1]) {
                throw new RuntimeException(sprintf('The table is out of order at Julian day %.1f.', $table[$i][1]));
            }
        }

        $sources = [
            'spline' => $splineSource.' ('.self::INNER_ZIP.', '.self::SPLINE_FILE.')',
            'tabla_1955_1973' => $historicSource,
            'tabla_desde_1973' => $finalsSource,
            'leapSeconds' => $leapSource,
        ];

        $path = DataFolder::path('deltat.php');

        file_put_contents($path, self::render($spline, $table, $leapSeconds, $observedUntil, $sources));

        return [
            'spline' => count($spline),
            'values' => count($table),
            'leapSeconds' => count($leapSeconds),
            'observedUntil' => $observedUntil,
            'predictedUntil' => $table[count($table) - 1][1],
            'overlapYears' => $overlapYears,
            'sources' => $sources,
            'unreachable' => $unreachable,
            'path' => $path,
        ];
    }

    /**
     * Table S15 of the supplement: 54 rows of «i K_i K_i+1 a0 a1 a2 a3». The years carry one
     * decimal and the coefficients three; they are kept as text, exactly as they are written.
     *
     * @param string $body The outer zip from Europe PMC.
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     *
     * @throws RuntimeException
     */
    private static function spline(string $body): array
    {
        $inner = self::fromZip($body, self::INNER_ZIP);
        $text = $inner === null ? null : self::fromZip($inner, self::SPLINE_FILE);

        if ($text === null) {
            throw new RuntimeException(sprintf('The supplement does not carry %s inside %s.', self::SPLINE_FILE, self::INNER_ZIP));
        }

        preg_match_all(
            '/^\s*\d+\s+(-?\d+\.\d)\s+(-?\d+\.\d)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s*$/m',
            $text,
            $rows,
            PREG_SET_ORDER
        );

        $segments = array_map(fn (array $row) => array_slice($row, 1), $rows);

        if (count($segments) !== self::SPLINE_SEGMENTS) {
            throw new RuntimeException(sprintf('Table S15 has %d segments and there had to be %d.', count($segments), self::SPLINE_SEGMENTS));
        }

        // The first segment is the one that anchors everything: from -720 to 400, with 20550.593
        // seconds in -720.
        if ($segments[0][0] !== '-720.0' || $segments[0][1] !== '400.0' || $segments[0][2] !== '20550.593') {
            throw new RuntimeException('The first segment of Table S15 is not the one from -720 to 400 with 20550.593: the parse has read something else.');
        }

        // The segments chain with no gaps and the spline is continuous at every knot: the end of
        // one (a0+a1+a2+a3) is the start of the next (its a0), bar the rounding of the published
        // coefficients to thousandths.
        for ($i = 1; $i < count($segments); $i++) {
            if ($segments[$i][0] !== $segments[$i - 1][1]) {
                throw new RuntimeException(sprintf('Segment %d starts at %s and the previous one ended at %s.', $i + 1, $segments[$i][0], $segments[$i - 1][1]));
            }

            $end = array_sum(array_map('floatval', array_slice($segments[$i - 1], 2)));

            if (abs($end - (float) $segments[$i][2]) > 0.01) {
                throw new RuntimeException(sprintf('The spline jumps %.3f seconds in the year %s.', $end - (float) $segments[$i][2], $segments[$i][0]));
            }
        }

        return $segments;
    }

    /**
     * The USNO's semestral file: «year delta_T error LOD error», with the year as a decimal
     * (1955.000, 1955.500). The decimal year is turned into a Julian day and the value is kept
     * as text.
     *
     * @param string $body
     * @return list<array{0: float, 1: float, 2: string}> Rows of [decimal year, JD, value].
     *
     * @throws RuntimeException
     */
    private static function historic(string $body): array
    {
        preg_match_all('/^\s*(\d{4})\.(\d{3})\s+(-?\d+(?:\.\d+)?)\s+/m', $body, $rows, PREG_SET_ORDER);

        $values = [];

        foreach ($rows as [, $year, $thousandths, $value]) {
            $decimal = (int) $year + (int) $thousandths / 1000.0;
            $start = Time::civilJulianDay((int) $year, 1, 1.0);
            $next = Time::civilJulianDay((int) $year + 1, 1, 1.0);

            $values[] = [$decimal, $start + ((int) $thousandths / 1000) * ($next - $start), $value];
        }

        $inRange = array_filter($values, fn (array $row) => $row[0] >= self::TABLE_START_YEAR && $row[0] <= self::HISTORIC_END_YEAR);

        // Two per year from 1955.0 to 1973.0, both included.
        if (count($inRange) !== 37) {
            throw new RuntimeException(sprintf('The USNO history has %d values between 1955 and 1973 and there had to be 37.', count($inRange)));
        }

        $first = array_values($inRange)[0];

        if ($first[0] !== 1955.0 || $first[2] !== '31.07') {
            throw new RuntimeException('The history does not start at 1955.0 with 31.07: the parse has read something else.');
        }

        return $values;
    }

    /**
     * The leap seconds: «MJD day month year TAI-UTC», one for each leap since 1972.
     *
     * @param string $body
     * @return list<array{0: float, 1: int}>
     *
     * @throws RuntimeException
     */
    private static function leapSeconds(string $body): array
    {
        preg_match_all('/^\s*(\d+)\.\d\s+\d+\s+\d+\s+\d{4}\s+(\d+)\s*$/m', $body, $rows, PREG_SET_ORDER);

        $leaps = array_map(fn (array $row) => [(float) $row[1], (int) $row[2]], $rows);

        usort($leaps, fn (array $a, array $b) => $a[0] <=> $b[0]);

        // The first one is 1 January 1972 with TAI-UTC = 10, and since then there have been 27.
        if (count($leaps) < 27 || $leaps[0][0] !== 41317.0 || $leaps[0][1] !== 10) {
            throw new RuntimeException('The leap seconds do not start at MJD 41317 with 10 seconds.');
        }

        return $leaps;
    }

    /**
     * One value per month since February 1973, reconstructed from the IERS `finals2000A.all`.
     *
     * It is a fixed-width file: year, month and day in the first six columns, the MJD from 8 to
     * 15, and UT1-UTC from 59 to 68 with its mark in 58: «I» if it is measured and «P» if it is a
     * prediction. Day 1 of each month is taken and
     * delta T = 32.184 + (TAI-UTC) - (UT1-UTC) is computed.
     *
     * @param string $body
     * @param list<array{0: float, 1: int}> $leapSeconds
     * @return array{0: list<array{0: float, 1: float, 2: string}>, 1: float} Rows of [decimal year, JD, value] and the JD of the last measured one.
     *
     * @throws RuntimeException
     */
    private static function monthly(string $body, array $leapSeconds): array
    {
        $values = [];
        $observedUntil = null;

        foreach (preg_split('/\R/', $body) as $line) {
            if (strlen($line) < 68 || substr($line, 4, 2) !== ' 1') {
                continue;
            }

            $mark = $line[57];
            $ut1MinusUtc = trim(substr($line, 58, 10));

            if (! in_array($mark, ['I', 'P'], true) || ! preg_match('/^-?\d+\.\d+$/', $ut1MinusUtc)) {
                continue;
            }

            $mjd = (float) trim(substr($line, 7, 8));
            $jd = $mjd + 2400000.5;

            $taiMinusUtc = null;

            foreach ($leapSeconds as [$from, $seconds]) {
                if ($mjd >= $from) {
                    $taiMinusUtc = $seconds;
                }
            }

            if ($taiMinusUtc === null) {
                throw new RuntimeException(sprintf('There is no leap second for MJD %.0f.', $mjd));
            }

            // The entries are exact seven-figure decimals, so the floating point sum rounded to
            // seven decimals gives the exact decimal back.
            $values[] = [self::decimalYear($jd), $jd, sprintf('%.7f', self::TT_MINUS_TAI + $taiMinusUtc - (float) $ut1MinusUtc)];

            if ($mark === 'I') {
                $observedUntil = $jd;
            }
        }

        if (count($values) < 640 || $observedUntil === null) {
            throw new RuntimeException(sprintf('Only %d months of the IERS have been read.', count($values)));
        }

        // No gaps: each month is 28 to 31 days after the previous one.
        for ($i = 1; $i < count($values); $i++) {
            $step = $values[$i][1] - $values[$i - 1][1];

            if ($step < 28 || $step > 31) {
                throw new RuntimeException(sprintf('Some month of the IERS is missing around Julian day %.1f.', $values[$i][1]));
            }
        }

        // It starts on 1 February 1973 and what is measured reaches at least last year.
        $first = $values[0];

        if ($first[1] !== 2441714.5) {
            throw new RuntimeException('The IERS series does not start on 1 February 1973.');
        }

        if (self::decimalYear($observedUntil) < (int) date('Y') - 1) {
            throw new RuntimeException('The measured IERS series ends more than a year ago: the file is stale.');
        }

        return [$values, $observedUntil];
    }

    /**
     * Where the USNO and the IERS overlap, from 1974 to 1984, the 1 January values have to agree
     * to hundredths: they are the same measurement by two routes, and a column read one place
     * over or a leap second lost in the reconstruction shows up here and nowhere else.
     *
     * @param list<array{0: float, 1: float, 2: string}> $historic
     * @param list<array{0: float, 1: float, 2: string}> $monthly
     * @return int The years that were compared.
     *
     * @throws RuntimeException
     */
    private static function crossCheck(array $historic, array $monthly): int
    {
        $byJd = [];

        foreach ($monthly as [, $jd, $value]) {
            $byJd[sprintf('%.1f', $jd)] = (float) $value;
        }

        $compared = 0;

        foreach ($historic as [$year, $jd, $value]) {
            $key = sprintf('%.1f', $jd);

            if ($year !== floor($year) || ! isset($byJd[$key])) {
                continue;
            }

            $compared++;

            if (abs($byJd[$key] - (float) $value) > 0.01) {
                throw new RuntimeException(sprintf('In %.0f the USNO gives %s and the IERS reconstruction %.4f.', $year, $value, $byJd[$key]));
            }
        }

        if ($compared < 10) {
            throw new RuntimeException(sprintf('Only %d years of overlap between the USNO and the IERS to cross-check.', $compared));
        }

        return $compared;
    }

    /**
     * The first address that answers, and which one it was.
     *
     * An answer that arrives empty counts as no answer, which is what the original did with
     * `file_get_contents`: a body of zero bytes parses into zero rows, and zero rows is a check
     * failing far away from the address that caused it.
     *
     * @param HttpClient $http
     * @param list<string> $urls
     * @param list<string> $unreachable The ones that did not answer, appended to.
     * @return array{0: string, 1: string}
     *
     * @throws RuntimeException If none of them answers.
     */
    private static function first(HttpClient $http, array $urls, array &$unreachable): array
    {
        foreach ($urls as $url) {
            try {
                $body = $http->get($url);
            } catch (RuntimeException) {
                $body = '';
            }

            if ($body !== '') {
                return [$body, $url];
            }

            $unreachable[] = $url;
        }

        throw new RuntimeException('None of the addresses has answered: '.implode(', ', $urls));
    }

    /**
     * A file out of a zip held in memory, by its name and without its path.
     *
     * @param string $zip The contents of the zip.
     * @param string $name The file being looked for inside, without its path.
     * @return string|null
     */
    private static function fromZip(string $zip, string $name): ?string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'deltat');
        file_put_contents($temporary, $zip);

        $archive = new ZipArchive();
        $contents = null;

        if ($archive->open($temporary) === true) {
            for ($i = 0; $i < $archive->numFiles; $i++) {
                if (basename((string) $archive->getNameIndex($i)) === $name) {
                    $contents = $archive->getFromIndex($i) ?: null;
                    break;
                }
            }

            $archive->close();
        }

        unlink($temporary);

        return $contents;
    }

    /**
     * @param float $jd
     * @return float
     */
    private static function decimalYear(float $jd): float
    {
        return 2000 + ($jd - 2451544.5) / 365.25;
    }

    /**
     * The contents of `deltat.php`.
     *
     * **The header names `astronomy delta-t` and it stays that way**: what is written here
     * has to come out byte for byte like the file that is committed, and a regenerated data file
     * that differs from the one in the repository is indistinguishable from one whose numbers
     * moved. The same goes for the two Spanish keys of `sources`, which are what the committed
     * file carries.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> $spline
     * @param list<array{0: float, 1: float, 2: string}> $table
     * @param list<array{0: float, 1: int}> $leapSeconds Each leap as [MJD, TAI-UTC], exactly as it comes from the IERS.
     * @param float $observedUntil
     * @param array<string, string> $sources
     * @return string
     */
    private static function render(array $spline, array $table, array $leapSeconds, float $observedUntil, array $sources): string
    {
        $php = "<?php\n\n";
        $php .= "/*\n";
        $php .= " * Delta T: observed values and their historical reconstruction\n";
        $php .= " *\n";
        $php .= " * GENERATED. Do not edit by hand: written by `astronomy delta-t`\n";
        $php .= " * in this package. Read by `Time::deltaT()`, which documents what each block is used for.\n";
        $php .= " *\n";
        $php .= " * `spline`: the Table S15 from Stephenson, Morrison and Hohenkerk (2016), their cubic\n";
        $php .= " * spline fit from -720 to 2016. Each row is [start year, end year, a0, a1, a2, a3] and\n";
        $php .= " * delta T = a0 + a1·t + a2·t² + a3·t³, with t the fraction of the segment, from 0 to 1.\n";
        $php .= " * Years are Gregorian 1 January. Referred to a lunar tidal acceleration of -25.85 arc\n";
        $php .= " * seconds per century squared. Used before 1955.\n";
        $php .= " *\n";
        $php .= " * `table`: [Julian day, delta T in seconds] from 1 January 1955 onward. Up to 1973 these\n";
        $php .= " * are the USNO semestral values (the ones from the Astronomical Almanac) and from\n";
        $php .= " * February 1973 one per month, reconstructed from the IERS series as\n";
        $php .= " * 32.184 + (TAI-UTC) - (UT1-UTC). The last ones are the IERS prediction:\n";
        $php .= " * `observedUntil` is the Julian day of the last measured value.\n";
        $php .= " *\n";
        $php .= " * `leapSeconds`: [Julian day at 0h UTC, TAI-UTC in whole seconds] from 1 January 1972\n";
        $php .= " * onward, i.e. the leap second table. Kept apart from `table` even though it went into\n";
        $php .= " * its computation, because delta T already carries it SUMMED IN, and without it UTC\n";
        $php .= " * can't be separated from UT1: the wall clock is UTC and what moves the sky is UT1, and\n";
        $php .= " * they drift up to 0.9 seconds. Read by `Time::taiMinusUtc()`. The IERS publishes it in\n";
        $php .= " * MJD; here it goes in Julian day like everything else in the file.\n";
        $php .= " *\n";
        $php .= " * The numbers are written as they appear in the sources.\n";
        $php .= " *\n";
        $php .= sprintf(" * Generated on %s.\n", date('Y-m-d'));
        $php .= " */\n\n";
        $php .= "return [\n";
        $php .= "    'spline' => [\n";

        foreach ($spline as $segment) {
            $php .= '        ['.implode(', ', $segment)."],\n";
        }

        $php .= "    ],\n";
        $php .= "    'table' => [\n";

        foreach ($table as [, $jd, $value]) {
            $php .= sprintf("        [%.1f, %s],\n", $jd, $value);
        }

        $php .= "    ],\n";
        $php .= sprintf("    'observedUntil' => %.1f,\n", $observedUntil);
        $php .= "    'leapSeconds' => [\n";

        foreach ($leapSeconds as [$mjd, $seconds]) {
            $php .= sprintf("        [%.1f, %d],\n", $mjd + 2400000.5, $seconds);
        }

        $php .= "    ],\n";
        $php .= "    'sources' => [\n";

        foreach ($sources as $key => $url) {
            $php .= sprintf("        '%s' => '%s',\n", $key, $url);
        }

        $php .= "    ],\n";

        return $php."];\n";
    }
}
