<?php

namespace Astronomy;

use RuntimeException;

/**
 * Builds the fixed star catalogue: which stars there are, and where each one is.
 *
 * It is the star half of what `Downloader` does for asteroids, and it lives in the package for
 * the same reason: **an engine has to be able to build its own data**. It used to be an Artisan
 * command inside one application, so the package shipped a catalogue it could not regenerate.
 *
 * Two services, because they answer two different questions:
 *
 * - **SIMBAD** (CDS, Strasbourg) says WHICH star this is: its main identifier, its Hipparcos
 *   number, its V magnitude and its object type.
 * - **Hipparcos-2** (van Leeuwen 2007, catalogue `I/311/hip2` through VizieR) says where it is:
 *   position, proper motion and parallax. It is what Swiss Ephemeris uses, and for the bright
 *   stars, which are all of the ones anybody reads, it is still the reference: Gaia saturates
 *   on them.
 *
 * **Nothing is asked for by name, and that is the whole trick of this class.** `star-names.php`
 * carries a J2000 position for each star, and the star is identified by a cone search around it.
 * Asking by name means trusting a spelling, and the published lists write the same star four
 * different ways; a position is unambiguous. The position is used only to ask the question: what
 * ends up in the file is Hipparcos-2's own astrometry.
 *
 * **If a single star does not resolve, nothing is written.** A catalogue missing one star gives
 * no error anywhere: that star simply stops appearing in charts and nobody knows it should.
 */
class StarCatalog
{
    /** SIMBAD's synchronous TAP endpoint. */
    public const SIMBAD = 'https://simbad.cds.unistra.fr/simbad/sim-tap/sync';

    /** VizieR's synchronous TAP endpoint, which is where Hipparcos-2 is served from. */
    public const VIZIER = 'https://tapvizier.cds.unistra.fr/TAPVizieR/tap/sync';

    /** The VizieR table with the new reduction of Hipparcos. */
    private const HIPPARCOS_TABLE = 'I/311/hip2';

    /** The epoch Hipparcos-2 refers its positions to, in Julian years. */
    private const HIPPARCOS_EPOCH = 1991.25;

    /**
     * The radius of the cone each star is looked for in, in degrees. Twenty arcseconds.
     *
     * It is wide enough for the published positions to disagree by the proper motion of a century
     * and narrow enough that no second star falls inside: the closest pair in the list is more
     * than a minute of arc apart.
     */
    private const CONE = 20 / 3600;

    /**
     * How far Hipparcos-2 and SIMBAD are allowed to disagree about the same star, in arcseconds.
     *
     * Measured over the 164 with a HIP number: 0.63 in the worst case (Terebellum, a double) and
     * a median of zero. Five arcseconds bothers none of them and catches what matters: radians
     * read as degrees are tens of degrees, and a HIP that points at another object, much more.
     */
    private const TOLERANCE = 5.0;

    /** How many cones go in one SIMBAD query. Measured: fifty answer in under a second. */
    private const BATCH = 50;

    /**
     * Object types that are not a point of light: clusters and galaxies.
     *
     * Two things follow from that and both are measured, not assumed. They have no V magnitude
     * of their own in SIMBAD, because the magnitude of an extended object depends on how much of
     * it you count. And **they have no proper motion**: M 31 moves forty microarcseconds a year,
     * which is zero for anything a chart does, and SIMBAD does not publish one.
     */
    private const EXTENDED = ['OpC', 'GlC', 'Cl*', 'G', 'GiG', 'GiC', 'AGN', 'SyG', 'Sy1', 'Sy2', 'LIN', 'IG'];

    /**
     * The wider cone extended objects are looked for in, in degrees. Ten arcminutes.
     *
     * A cluster has no centre anybody agrees on: Praesepe is published five arcminutes away
     * depending on which member stars are counted, so twenty arcseconds finds nothing. It is only
     * used on a second pass, for what the narrow cone did not find, and only extended objects are
     * accepted from it: a star of the list is never five arcminutes from where it is said to be.
     */
    private const WIDE_CONE = 10 / 60;

    /** What comes back inside a cone and is not the star that was asked for. */
    private const NOT_A_STAR = ['Pl', 'Pl?', 'PoC', 'PoG'];

    /** Roman (1987), catalogue VI/42 at CDS: the IAU boundaries as a lookup table. */
    public const BOUNDARIES = 'https://cdsarc.cds.unistra.fr/ftp/VI/42/data.dat';

    /** The greek letters the way SIMBAD abbreviates them in a Bayer designation. */
    private const GREEK = [
        'alf' => 'α', 'bet' => 'β', 'gam' => 'γ', 'del' => 'δ', 'eps' => 'ε', 'zet' => 'ζ',
        'eta' => 'η', 'tet' => 'θ', 'iot' => 'ι', 'kap' => 'κ', 'lam' => 'λ', 'mu.' => 'μ',
        'nu.' => 'ν', 'ksi' => 'ξ', 'omi' => 'ο', 'pi.' => 'π', 'rho' => 'ρ', 'sig' => 'σ',
        'tau' => 'τ', 'ups' => 'υ', 'phi' => 'φ', 'khi' => 'χ', 'chi' => 'χ', 'psi' => 'ψ',
        'ome' => 'ω',
    ];

    /**
     * The stars whose letter names one constellation and whose position falls in another.
     *
     * **It is not an error, it is the sky.** Bayer lettered his stars in 1603 and Flamsteed
     * numbered his in 1712, and Delporte did not draw the boundaries until 1930: a few stars
     * ended up on the wrong side of a line that did not exist when they were named. Measured
     * over the whole catalogue, there are exactly two of them in a thousand and eighty with a
     * designation, and the other 1,078 agree.
     *
     * Written down rather than allowed, so that a third one stops the catalogue. A new
     * disagreement is either a position that moved or a transform that broke, and neither of
     * those announces itself: a star in the wrong constellation reads perfectly well.
     */
    private const OUTSIDE_THEIR_NAME = [
        '* ups Per' => 'Andromeda',
        '* 41 Lyn' => 'Ursa Major',
    ];

    /**
     * Mean obliquity of the ecliptic at J2000.0, in arcseconds.
     *
     * The IAU 2006 constant, the same one `Stars` carries and for the same reason:
     * `Time::meanObliquity(0)` is Laskar's series at zero and gives 84381.448, and what is wanted
     * here is the one that matches ICRS.
     */
    private const OBLIQUITY_J2000 = 84381.406;

    /**
     * Resolves the catalogue and writes it into the data folder.
     *
     * @param HttpClient|null $http
     * @param list<string>|null $only Designations or names, for fetching a few instead of all.
     * @return array{stars: int, hipparcos: int, simbad: int, path: string}
     *
     * @throws RuntimeException If any star does not resolve, in which case nothing is written.
     */
    public static function regenerate(?HttpClient $http = null, ?array $only = null): array
    {
        $http ??= new NativeHttpClient();
        $wanted = self::wanted($only);
        $keys = self::keys();
        $boundaries = self::boundaries($http);
        $resolved = [];
        $fromHipparcos = 0;

        foreach (array_chunk($wanted, self::BATCH, true) as $batch) {
            /* What Swiss names with a catalogue number is asked for BY that number and never by
               a cone, and it is the only way it comes out right: a cone around a cluster finds
               its member stars, and one of those looks like a perfectly good answer. Measured:
               Acumen came back as `2MASS J17535074-3447372`, three arcminutes from M 7 and not a
               cluster at all, and Capulus as `Cl* NGC 869 W 276`, which says inside its own name
               that it is a member of the thing that was being asked for. */
            $found = self::byIdentifier($http, $batch);
            $byCone = array_diff_key($batch, $found);

            $found += self::identify($http, $byCone, self::CONE);

            /* Second pass with the wide cone, only for what the narrow one did not find, and
               only accepting extended objects from it. A cluster has no agreed centre; a star
               is never ten arcminutes from where it is said to be. */
            $missing = array_diff_key($batch, $found);

            if ($missing !== []) {
                $found += self::identify($http, $missing, self::WIDE_CONE, true);
            }

            $hips = array_values(array_filter(array_map(fn (array $row) => $row['hip'] ?? null, $found)));
            $astrometry = $hips === [] ? [] : self::hipparcos($http, $hips);
            $common = self::commonNames($http, $found);

            foreach ($batch as $designation => $entry) {
                $row = $found[$designation] ?? throw new RuntimeException(sprintf(
                    'No star was found within %.0f arcseconds of where %s (%s) is said to be. The catalogue is not written.',
                    self::CONE * 3600, $entry['name'], $designation
                ));

                $star = self::compose($designation, $entry, $row, $astrometry[$row['hip'] ?? -1] ?? null, $boundaries, $common);

                if ($star['source'] === Star::HIPPARCOS2_SOURCE) {
                    $fromHipparcos++;
                }

                $key = $keys[$designation];

                /* A key already taken means two stars would be written as one and the second
                   would win without a word. It cost ten of them: α Cet, which IS Menkar, was
                   being replaced by λ Cet, two magnitudes fainter and thirty degrees away in
                   right ascension. `keys()` makes that impossible; this is here to say so if it
                   ever stops being impossible. */
                if (isset($resolved[$key])) {
                    throw new RuntimeException(sprintf(
                        '%s and %s would both be written as «%s». The catalogue is not written.',
                        $resolved[$key]['designation'], $designation, $key
                    ));
                }

                $resolved[$key] = $star;
            }
        }

        $path = DataFolder::path('stars.php');
        file_put_contents($path, self::render($resolved, $fromHipparcos));

        return [
            'stars' => count($resolved),
            'hipparcos' => $fromHipparcos,
            'simbad' => count($resolved) - $fromHipparcos,
            'path' => $path,
        ];
    }

    /**
     * The key each star of the list gets, by designation.
     *
     * A key is the name, which is what anyone asking for a star writes. **But a name is not
     * unique in the list and a designation is**, and that difference cost ten stars before it was
     * measured: ten names belong to two entries each, five of them a system and one of its
     * components (β and β¹ Cap are both Dabih) and five two components of a wide pair that share
     * the traditional name (π³ and π⁴ Ori are both Tabit). Writing them by name wrote one over
     * the other in silence.
     *
     * So a name is the key only where it belongs to a single entry, and where it does not, BOTH
     * of them key by their designation. Not the brighter one, not the first: giving the name to
     * one of the two would depend on the order of the file or on a judgement about which star
     * deserves it, and neither is something a later reader could check. `Stars::find` still
     * answers to the shared name, and resolves it to the brighter of the two, which IS a
     * measurement.
     *
     * Computed over the whole list and never over what is being fetched, so that
     * `astronomy stars Menkar` writes the same key a full run would.
     *
     * @return array<string, string>
     */
    private static function keys(): array
    {
        /** @var array<string, array{name: string, ra: float, dec: float, aliases: list<string>}> $all */
        $all = require DataFolder::path('star-names.php');

        $howMany = [];

        foreach ($all as $entry) {
            if ($entry['name'] !== '') {
                $howMany[self::key($entry['name'])] = ($howMany[self::key($entry['name'])] ?? 0) + 1;
            }
        }

        $keys = [];

        foreach ($all as $designation => $entry) {
            $name = self::key($entry['name']);

            $keys[$designation] = $entry['name'] !== '' && $howMany[$name] === 1
                ? $name
                : self::key($designation);
        }

        return $keys;
    }

    /**
     * The list of stars to resolve, from `star-names.php`.
     *
     * @param list<string>|null $only
     * @return array<string, array{name: string, ra: float, dec: float, aliases: list<string>}>
     *
     * @throws RuntimeException
     */
    private static function wanted(?array $only): array
    {
        /** @var array<string, array{name: string, ra: float, dec: float, aliases: list<string>}> $all */
        $all = require DataFolder::path('star-names.php');

        if ($only === null || $only === []) {
            return $all;
        }

        $wanted = [];

        foreach ($only as $asked) {
            $key = self::key($asked);
            $match = null;

            foreach ($all as $designation => $entry) {
                if ($designation === $asked || self::key($entry['name']) === $key) {
                    $match = $designation;

                    break;
                }

                foreach ($entry['aliases'] as $alias) {
                    if (self::key($alias) === $key) {
                        $match = $designation;

                        break 2;
                    }
                }
            }

            if ($match === null) {
                throw new RuntimeException("«{$asked}» is not in the star list.");
            }

            $wanted[$match] = $all[$match];
        }

        return $wanted;
    }

    /**
     * Which star sits at each position, according to SIMBAD.
     *
     * One query for the whole batch, with the cones ORed together. What comes back is every
     * object inside any of them, so each one is assigned to the cone it falls in: a bright star
     * brings along its companions and sometimes a planet, and the one that was asked for is the
     * closest of what is left after throwing out what is not a star.
     *
     * @param HttpClient $http
     * @param array<string, array{name: string, ra: float, dec: float, aliases: list<string>}> $batch
     * @param float $cone Radius, in degrees.
     * @param bool $extendedOnly Only accept clusters and galaxies, which is the second pass.
     * @return array<string, array<string, string|int|float|null>> By designation.
     *
     * @throws RuntimeException
     */
    private static function identify(HttpClient $http, array $batch, float $cone, bool $extendedOnly = false): array
    {
        $cones = [];

        foreach ($batch as $entry) {
            $cones[] = sprintf(
                "CONTAINS(POINT('ICRS', b.ra, b.dec), CIRCLE('ICRS', %.8F, %.8F, %.8F)) = 1",
                $entry['ra'], $entry['dec'], $cone
            );
        }

        $adql = 'SELECT b.main_id, b.otype, b.ra, b.dec, b.pmra, b.pmdec, b.plx_value, b.rvz_radvel, '
            .'f.flux AS magnitude, h.id AS hip '
            .'FROM basic b '
            ."LEFT JOIN flux f ON f.oidref = b.oid AND f.filter = 'V' "
            ."LEFT JOIN ident h ON h.oidref = b.oid AND h.id LIKE 'HIP %' "
            .'WHERE ('.implode(' OR ', $cones).')';

        $rows = self::csv($http->get(self::SIMBAD.'?'.http_build_query([
            'request' => 'doQuery',
            'lang' => 'adql',
            'format' => 'csv',
            'query' => $adql,
        ])), 'SIMBAD');

        $candidates = [];

        foreach ($rows as $row) {
            if ($row['ra'] === null || $row['dec'] === null || in_array((string) $row['otype'], self::NOT_A_STAR, true)) {
                continue;
            }

            if ($extendedOnly && ! in_array((string) $row['otype'], self::EXTENDED, true)) {
                continue;
            }

            /* A candidate with no proper motion is not a candidate, unless it is extended. What
               that throws out is the SYSTEM of a double that SIMBAD publishes as a whole without
               astrometry (61 Cygni), leaving its components, which do have it. */
            $extended = in_array((string) $row['otype'], self::EXTENDED, true);

            if (! $extended && (! is_numeric($row['pmra'] ?? null) || ! is_numeric($row['pmdec'] ?? null))) {
                continue;
            }

            $candidates[] = $row;
        }

        $found = [];

        foreach ($batch as $designation => $entry) {
            $mine = [];

            foreach ($candidates as $row) {
                $distance = self::separation($entry['ra'], $entry['dec'], (float) $row['ra'], (float) $row['dec']);

                if ($distance <= $cone * 3600) {
                    $mine[] = ['distance' => $distance, 'row' => $row];
                }
            }

            /* Each of the two ways down a system is collapsed only where the list is not already
               asking for that rung. `alCen` is the whole of alpha Centauri, so both go; `be-1Cyg`
               is beta-one Cygni, so `* bet01 Cyg A` still collapses into it but it does not
               collapse into `* bet Cyg`; and `zePscA` names the component letter itself. */
            $mine = self::withoutComponents(
                $mine,
                letters: ! self::asksForALetter($designation),
                indices: preg_match('/-\d/', $designation) !== 1,
            );

            $best = null;

            foreach ($mine as $candidate) {
                /* The closest wins, and where two are the same distance the brighter one does:
                   that happens with a double whose components SIMBAD publishes at the same
                   position, and the star of the tradition is always the bright one. */
                if ($best === null
                    || $candidate['distance'] < $best['distance'] - 0.001
                    || (abs($candidate['distance'] - $best['distance']) <= 0.001
                        && self::magnitude($candidate['row']) < self::magnitude($best['row']))) {
                    $best = $candidate;
                }
            }

            if ($best === null) {
                continue;
            }

            $row = $best['row'];
            $row['hip'] = $row['hip'] === null ? null : self::hipNumber((string) $row['hip']);
            $found[$designation] = $row;
        }

        return $found;
    }

    /**
     * The IAU constellation boundaries, from the table Nancy Roman published for exactly this.
     *
     * *«Identification of a Constellation From Position»*, PASP 99, 695 (1987), catalogue VI/42
     * at CDS. Three hundred and fifty-seven rows of «from this right ascension to that one, above
     * this declination, this constellation», in **B1875**, which is the frame Delporte drew the
     * 1930 boundaries in and the reason they are all parallels and meridians there and crooked
     * anywhere else. Sorted so that the first row a position falls into is its constellation.
     *
     * **It is downloaded and not typed, like everything else here**, and it is only needed while
     * the catalogue is being built: what ends up in `stars.php` is the name.
     *
     * @param HttpClient $http
     * @return list<array{0: float, 1: float, 2: float, 3: string}>
     *
     * @throws RuntimeException
     */
    private static function boundaries(HttpClient $http): array
    {
        $body = $http->get(self::BOUNDARIES);

        /* The table writes its abbreviations in capitals (`CVN`, `UMA`) and the IAU's are mixed
           case (`CVn`, `UMa`), so they are matched without looking at case rather than guessed
           at: `ucfirst(strtolower())` turns `CVN` into `Cvn`, which is nobody's constellation. */
        $known = [];

        foreach (array_keys(Constellations::all()) as $abbreviation) {
            $known[strtolower($abbreviation)] = $abbreviation;
        }

        $rows = [];

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^\s*([\d.]+)\s+([\d.]+)\s+([-+]?[\d.]+)\s+([A-Za-z]{3})\s*$/', $line, $parts) !== 1) {
                continue;
            }

            $abbreviation = $known[strtolower($parts[4])]
                ?? throw new RuntimeException("The boundary table names «{$parts[4]}», which is not an IAU constellation.");

            $rows[] = [(float) $parts[1], (float) $parts[2], (float) $parts[3], $abbreviation];
        }

        /* The count is the check. A table that arrives short does not fail: it answers with the
           constellation of the next row down, which is a neighbouring sky and reads as right. */
        if (count($rows) !== 357) {
            throw new RuntimeException(sprintf('The boundary table came with %d rows and it has 357.', count($rows)));
        }

        return $rows;
    }

    /**
     * The constellation a J2000 position falls in.
     *
     * The position is taken back to B1875 through the engine's own precession, going out to the
     * ecliptic and back, because a second precession model inside this file is the one thing this
     * engine does not do. Proper motion is not applied: a constellation is degrees across and no
     * star of the catalogue has crossed one since 1875.
     *
     * @param float $ra Degrees, J2000.
     * @param float $dec Degrees, J2000.
     * @param list<array{0: float, 1: float, 2: float, 3: string}> $boundaries
     * @return string
     *
     * @throws RuntimeException
     */
    private static function constellationAt(float $ra, float $dec, array $boundaries): string
    {
        /* B1875.0, which is a Besselian epoch and not a calendar year: 1900.0 is JD 2415020.31352
           and a Besselian year is 365.242198781 days. */
        $t = (2405889.25855 - 2451545.0) / 36525.0;

        $alpha = deg2rad($ra);
        $delta = deg2rad($dec);
        $vector = [cos($delta) * cos($alpha), cos($delta) * sin($alpha), sin($delta)];

        /* `OBLIQUITY_J2000` is in arcseconds and `Time::meanObliquity` answers in RADIANS, which
           is not the same unit and does not look wrong: dividing that one by 3600 as well put
           Aldebaran in Eridanus, twenty-two degrees south, which is the obliquity itself. */
        $vector = self::aroundX($vector, +deg2rad(self::OBLIQUITY_J2000 / 3600));
        $vector = Precession::toDate($vector, $t);
        $vector = self::aroundX($vector, -Time::meanObliquity($t));

        $hours = fmod(fmod(rad2deg(atan2($vector[1], $vector[0])) / 15, 24) + 24, 24);
        $degrees = rad2deg(asin(max(-1.0, min(1.0, $vector[2]))));

        foreach ($boundaries as [$from, $to, $below, $abbreviation]) {
            if ($degrees >= $below && $hours >= $from && $hours < $to) {
                return Constellations::name($abbreviation) ?? $abbreviation;
            }
        }

        throw new RuntimeException(sprintf('No constellation contains %.6f, %+.6f.', $ra, $dec));
    }

    /**
     * The common names SIMBAD has for each object of a batch.
     *
     * SIMBAD keeps them in `ident` with a `NAME ` prefix, which is exactly what is wanted here
     * and nothing else: an object also carries its HD, its HIP, its TYC and its Gaia number, and
     * none of those is something a person writes.
     *
     * **They are asked for rather than written down**, and what that buys is measured: Swiss
     * calls M 44 «Praesepe Cluster» and alpha Centauri «Rigil Kentaurus», so `find('Praesepe')`
     * and `find('Toliman')` came back empty after the catalogue grew, and the clusters had no
     * alias in Swiss's list to fall back on.
     *
     * @param HttpClient $http
     * @param array<string, array<string, string|int|float|null>> $found
     * @return array<string, list<string>> By SIMBAD identifier.
     *
     * @throws RuntimeException
     */
    private static function commonNames(HttpClient $http, array $found): array
    {
        $identifiers = [];

        foreach ($found as $row) {
            $identifiers[self::flattened((string) $row['main_id'])] = true;
        }

        if ($identifiers === []) {
            return [];
        }

        $quoted = implode(', ', array_map(
            static fn (string $id) => "'".str_replace("'", "''", $id)."'",
            array_keys($identifiers)
        ));

        $rows = self::csv($http->get(self::SIMBAD.'?'.http_build_query([
            'request' => 'doQuery',
            'lang' => 'adql',
            'format' => 'csv',
            'query' => 'SELECT b.main_id, i.id FROM basic b JOIN ident i ON i.oidref = b.oid '
                ."WHERE b.main_id IN ({$quoted}) AND i.id LIKE 'NAME %'",
        ])), 'SIMBAD');

        $names = [];

        foreach ($rows as $row) {
            $names[self::flattened((string) $row['main_id'])][] = trim(substr(self::flattened((string) $row['id']), 5));
        }

        return $names;
    }

    /**
     * The other names a star goes by: the list's aliases plus SIMBAD's common names.
     *
     * Its own name is not among them, and neither is anything that reduces to the same key: an
     * alias that is the name again is a row of the index pointing at where it already points.
     *
     * @param array{name: string, ra: float, dec: float, aliases: list<string>} $entry
     * @param string $mainId
     * @param array<string, list<string>> $common
     * @return list<string>
     */
    private static function otherNames(array $entry, string $mainId, array $common): array
    {
        $names = [];

        foreach ([...$entry['aliases'], ...($common[$mainId] ?? [])] as $alias) {
            $key = self::key($alias);

            if ($key !== '' && $key !== self::key($entry['name'])) {
                $names[$key] = $alias;
            }
        }

        ksort($names);

        return array_values($names);
    }

    /**
     * The designation as a person writes it: from «* alf02 Lib» to «α² Lib».
     *
     * **This is what gets read**, in the chart sheet and in the text the interpreter is handed,
     * so it is the Bayer letter and not Swiss's own abbreviation: `alLeo` is an identifier and
     * «α Leo» is a designation. The identifier is still what the list is keyed by; what is
     * written down is this.
     *
     * A Flamsteed number stays a number, a component letter stays at the end, and what has no
     * Bayer or Flamsteed designation at all, which is the clusters and the galaxies and the ones
     * named after a catalogue, keeps what the list calls it with a space put back in: `M44` is
     * written «M 44», because `Star::isCluster` reads that.
     *
     * @param string $mainId SIMBAD's identifier.
     * @param string $designation What the list calls it.
     * @return string
     */
    private static function written(string $mainId, string $designation): string
    {
        if (preg_match('/^\*{1,2}\s+(\S+)\s+([A-Za-z]{3})(?:\s+([A-Z]))?$/', $mainId, $parts) !== 1) {
            return preg_match('/^(M|NGC|IC)\s?(\d+)$/', $designation, $number) === 1
                ? $number[1].' '.$number[2]
                : $designation;
        }

        [, $letter, $abbreviation] = $parts;
        $component = isset($parts[3]) ? ' '.$parts[3] : '';

        /* «alf02» is alpha two and «c02» is latin c two; a bare «42» is a Flamsteed number and
           is left alone. Bayer ran out of greek letters and carried on in the latin alphabet, so
           a single letter is a designation and not something missing from the table. */
        if (preg_match('/^([a-z.]+)(\d\d)?$/', $letter, $pieces) === 1) {
            $letter = self::GREEK[$pieces[1]] ?? $pieces[1];

            if (isset($pieces[2])) {
                $letter .= ['1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶',
                    '7' => '⁷', '8' => '⁸', '9' => '⁹'][ltrim($pieces[2], '0')] ?? $pieces[2];
            }
        }

        return "{$letter} {$abbreviation}{$component}";
    }

    /**
     * A rectangular vector turned about the x axis, which is what changing between the equator
     * and the ecliptic is.
     *
     * @param array{0: float, 1: float, 2: float} $vector
     * @param float $angle Radians.
     * @return array{0: float, 1: float, 2: float}
     */
    private static function aroundX(array $vector, float $angle): array
    {
        return [
            $vector[0],
            $vector[1] * cos($angle) + $vector[2] * sin($angle),
            -$vector[1] * sin($angle) + $vector[2] * cos($angle),
        ];
    }

    /**
     * What the list names with a catalogue number, asked for by that number.
     *
     * Everything else goes by cone, because a Bayer designation is not something SIMBAD can look
     * up. These can, and they have to: all eleven are extended objects, and **a cone around an
     * extended object finds the stars inside it**, each of them a valid-looking answer three
     * arcminutes from the thing that was asked for. An identifier has no such ambiguity.
     *
     * @param HttpClient $http
     * @param array<string, array{name: string, ra: float, dec: float, aliases: list<string>}> $batch
     * @return array<string, array<string, string|int|float|null>> By designation.
     *
     * @throws RuntimeException
     */
    private static function byIdentifier(HttpClient $http, array $batch): array
    {
        $asked = [];

        foreach (array_keys($batch) as $designation) {
            /* `M7` and `NGC869` in the list are `M 7` and `NGC 869` in SIMBAD. */
            if (preg_match('/^(M|NGC|IC)\s?(\d+)$/', $designation, $parts) === 1) {
                $asked[$parts[1].' '.$parts[2]] = $designation;
            }
        }

        if ($asked === []) {
            return [];
        }

        $quoted = implode(', ', array_map(static fn (string $id) => "'".$id."'", array_keys($asked)));

        $adql = 'SELECT i.id AS asked, b.main_id, b.otype, b.ra, b.dec, b.pmra, b.pmdec, b.plx_value, '
            .'b.rvz_radvel, f.flux AS magnitude, h.id AS hip '
            .'FROM basic b '
            .'JOIN ident i ON i.oidref = b.oid '
            ."LEFT JOIN flux f ON f.oidref = b.oid AND f.filter = 'V' "
            ."LEFT JOIN ident h ON h.oidref = b.oid AND h.id LIKE 'HIP %' "
            .'WHERE i.id IN ('.$quoted.')';

        $rows = self::csv($http->get(self::SIMBAD.'?'.http_build_query([
            'request' => 'doQuery',
            'lang' => 'adql',
            'format' => 'csv',
            'query' => $adql,
        ])), 'SIMBAD');

        $found = [];

        foreach ($rows as $row) {
            $designation = $asked[self::flattened((string) $row['asked'])] ?? null;

            if ($designation === null || $row['ra'] === null || $row['dec'] === null) {
                continue;
            }

            unset($row['asked']);

            /* **Asked for by its own catalogue number, what comes back IS the object**, so the
               rule that a body with no proper motion is a datum that did not arrive does not
               apply to it: all eleven of these are extended and none of them moves. Said this way
               and not by adding to the list of SIMBAD types, which is where NGC 4194 was lost:
               it is a radio galaxy, `rG`, and no hand-written list of types is ever finished. */
            $row['asObject'] = '1';
            $row['hip'] = $row['hip'] === null ? null : self::hipNumber((string) $row['hip']);
            $found[$designation] = $row;
        }

        return $found;
    }

    /**
     * The candidates with the components dropped whose system is among them.
     *
     * **Inside twenty arcseconds a system and its components are the same object written at two
     * levels, and which level is meant is decided by what was asked for, not by which is closer
     * or brighter.** Swiss says `alCen` for the system and `ga-1Leo` for the component, and
     * SIMBAD writes the same difference as `* alf Cen` against `* alf Cen A`. So a candidate
     * whose identifier is another candidate's plus a component letter is that other one seen
     * from closer, and it goes.
     *
     * It is not a tie-break, which is why it is not down in the loop with the magnitude: the two
     * are a tenth of an arcsecond apart and both about five arcseconds from where Swiss puts the
     * pair, so the closest of the two is decided by rounding.
     *
     * **What it buys is measured and it is Toliman.** Alpha Centauri is an eighty-year double,
     * and Hipparcos-2 has no solution for the system: only the two components, each with its
     * instantaneous 1991 motion, which carries the orbital velocity inside and extrapolated a
     * century in a straight line leaves A twenty-eight arcseconds from where the system goes.
     * SIMBAD does publish the system, with its own proper motion, which is the one Swiss
     * carries. Without this the cone took the component, and with it it takes the system and
     * stays on SIMBAD, because the system has no HIP to go asking Hipparcos-2 for.
     *
     * It costs nothing where the system has no astrometry of its own, which is the other half of
     * this and is 61 Cygni: SIMBAD publishes it as a pair with no numbers, so it never reaches
     * here and its components are left alone.
     *
     * @param list<array{distance: float, row: array<string, string|null>}> $candidates
     * @param bool $letters Collapse `* alf Cen A` into `* alf Cen`.
     * @param bool $indices Collapse `* alf01 Cru` into `* alf Cru`.
     * @return list<array{distance: float, row: array<string, string|null>}>
     */
    private static function withoutComponents(array $candidates, bool $letters, bool $indices): array
    {
        $at = [];

        foreach ($candidates as $index => $candidate) {
            $at[self::flattened((string) $candidate['row']['main_id'])] = $index;
        }

        $drop = [];

        foreach ($candidates as $index => $candidate) {
            $row = $candidate['row'];
            $parentId = self::systemOf(self::flattened((string) $row['main_id']), $letters, $indices);

            if ($parentId === null || ! isset($at[$parentId])) {
                continue;
            }

            $drop[$index] = true;
            $parent = $at[$parentId];

            /* **The system keeps the magnitude of its brightest component, because it has none
               of its own.** SIMBAD does not publish a V magnitude for a double taken as a whole,
               so preferring the system would have left Acrux and Mizar without one, and a star
               with no magnitude falls out of every filter that reads brightness: it is what
               chooses the fifty-odd stars a chart's parans are worth listing. It is not an
               invented number and it is not the combined magnitude either, which would be
               brighter and which nobody publishes here: it is the magnitude of the component
               that makes almost all of the light, which is the star the tradition means. */
            if (self::magnitude($candidates[$parent]['row']) > self::magnitude($row)) {
                $candidates[$parent]['row']['magnitude'] = $row['magnitude'];
            }
        }

        return array_values(array_diff_key($candidates, $drop));
    }

    /**
     * The identifier of the system a SIMBAD identifier is a component of, or null.
     *
     * **SIMBAD writes a component in two ways and both had to be measured, because only the
     * first is obvious.** One is a letter at the end, `* alf Cen A` under `* alf Cen`. The other
     * is the Bayer index, `* alf01 Cru` under `* alf Cru`, and missing it left Acrux, Mizar and
     * Mesarthim on a system with no published magnitude: their light is their bright component's
     * and SIMBAD says so there and nowhere else.
     *
     * @param string $identifier
     * @param bool $letters
     * @param bool $indices
     * @return string|null
     */
    private static function systemOf(string $identifier, bool $letters, bool $indices): ?string
    {
        if ($letters && preg_match('/^(.+) [A-Z]$/', $identifier, $parts) === 1) {
            return $parts[1];
        }

        if ($indices && preg_match('/^(\* [A-Za-z]+)\d{2}( .+)$/', $identifier, $parts) === 1) {
            return $parts[1].$parts[2];
        }

        return null;
    }

    /**
     * Whether the list's designation ends in a component letter, as `zePscA` and `61CygA` do.
     *
     * **A trailing capital cannot be read as that letter on its own**, because ten constellation
     * abbreviations end in one: `alCrB` is alpha Coronae Borealis and `alTrA` alpha Trianguli
     * Australis, neither of them a component B or A of anything. So the letter only counts when
     * what is left in front of it ends in an abbreviation the table knows.
     *
     * @param string $designation
     * @return bool
     */
    private static function asksForALetter(string $designation): bool
    {
        if (preg_match('/^(.+?)([A-Z][A-Za-z]{2})[A-Z]$/', $designation, $parts) !== 1) {
            return false;
        }

        return Constellations::name($parts[2]) !== null;
    }

    /**
     * An identifier with its whitespace collapsed, which is how SIMBAD pads its own.
     *
     * @param string $identifier
     * @return string
     */
    private static function flattened(string $identifier): string
    {
        return preg_replace('/\s+/', ' ', trim($identifier)) ?? $identifier;
    }

    /**
     * Hipparcos-2 for a list of HIP numbers, in a single query to VizieR.
     *
     * A HIP that is not in the table simply does not come back and that star falls to SIMBAD.
     * What is watched is that the answer does not arrive cut short: one more row than there can
     * be is asked for, and if that many arrive the server has truncated it.
     *
     * @param HttpClient $http
     * @param list<int> $hips
     * @return array<int, array<string, string|null>>
     *
     * @throws RuntimeException
     */
    private static function hipparcos(HttpClient $http, array $hips): array
    {
        $hips = array_values(array_unique($hips));

        $rows = self::csv($http->get(self::VIZIER.'?'.http_build_query([
            'request' => 'doQuery',
            'lang' => 'adql',
            'format' => 'csv',
            'MAXREC' => count($hips) + 1,
            'query' => 'SELECT HIP, RArad, DErad, pmRA, pmDE, Plx FROM "'.self::HIPPARCOS_TABLE.'" '
                .'WHERE HIP IN ('.implode(',', $hips).')',
        ])), 'VizieR');

        if (count($rows) > count($hips)) {
            throw new RuntimeException('VizieR has returned more rows than there are HIP numbers asked for: the query is not what it looks like.');
        }

        $byHip = [];

        foreach ($rows as $row) {
            $byHip[(int) $row['HIP']] = $row;
        }

        return $byHip;
    }

    /**
     * One star's row of the catalogue.
     *
     * @param string $designation
     * @param array{name: string, ra: float, dec: float, aliases: list<string>} $entry
     * @param array<string, string|int|float|null> $row What SIMBAD says.
     * @param array<string, string|null>|null $hipparcos
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private static function compose(string $designation, array $entry, array $row, ?array $hipparcos, array $boundaries, array $common): array
    {
        $mainId = preg_replace('/\s+/', ' ', trim((string) $row['main_id']));

        /* **The magnitude is allowed to be missing, and that was measured the hard way.** This
           refused anything without a V magnitude that was not a cluster, on the assumption that
           only clusters lack one. Over a thousand stars that assumption is false: SIMBAD gives no
           V flux to a system it publishes as a whole (14 Andromedae, gamma Arietis, mu1 Bootis),
           only to its components. The position does not depend on the magnitude, so it goes in as
           null and whoever filters by brightness skips them, the same as with a cluster. */
        $astrometry = $hipparcos !== null
            ? self::fromHipparcos($hipparcos, $row)
            : self::fromSimbad($row, $mainId);

        /* **The constellation comes from the position, and the designation is what checks it.**
           It used to be read out of the designation alone, and that leaves without a sky exactly
           what has no Bayer letter, which is the clusters and the galaxies: five of them were
           written by hand in the generator this came from, and hand-written is what this engine
           does not do. Where the designation does say one, the two have to agree, and that is a
           thousand and eighty stars checked against a source that knows nothing about them. */
        $constellation = self::constellationAt($astrometry['ra'], $astrometry['dec'], $boundaries);
        $written = self::constellation($mainId);

        if ($written !== null && $written !== $constellation
            && (self::OUTSIDE_THEIR_NAME[$mainId] ?? null) !== $constellation) {
            throw new RuntimeException(
                "{$mainId} is written in {$written} and its position falls in {$constellation}. "
                .'If that is right, it goes in OUTSIDE_THEIR_NAME with the other two.'
            );
        }

        return [
            'name' => $entry['name'] !== '' ? $entry['name'] : self::written($mainId, $designation),
            'designation' => self::written($mainId, $designation),
            'aliases' => self::otherNames($entry, $mainId, $common),
            'simbad' => $mainId,
            'hip' => $row['hip'],
            'constellation' => $constellation,
            'source' => $astrometry['source'],
            'epoch' => Star::EPOCH,
            'ra' => $astrometry['ra'],
            'dec' => $astrometry['dec'],
            'pm_ra' => $astrometry['pm_ra'],
            'pm_dec' => $astrometry['pm_dec'],
            'parallax' => $astrometry['parallax'],
            'radial_velocity' => $row['rvz_radvel'] !== null ? (float) $row['rvz_radvel'] : null,
            'magnitude' => $row['magnitude'] !== null ? (float) $row['magnitude'] : null,
        ];
    }

    /**
     * @param array<string, string|int|float|null> $row
     * @param string $mainId
     * @return array{source: string, ra: float, dec: float, pm_ra: float, pm_dec: float, parallax: float|null}
     *
     * @throws RuntimeException
     */
    private static function fromSimbad(array $row, string $mainId): array
    {
        foreach (['ra', 'dec'] as $field) {
            if ($row[$field] === null || ! is_numeric($row[$field])) {
                throw new RuntimeException("SIMBAD gives no «{$field}» for {$mainId}");
            }
        }

        /* An extended object does not move: M 31 goes at forty microarcseconds a year and a
           cluster has no proper motion of its own. Zero is the measurement and not a default,
           and it is only accepted for them: a star with no proper motion is a datum that did
           not arrive, and it stops the catalogue. */
        $extended = in_array((string) $row['otype'], self::EXTENDED, true) || isset($row['asObject']);

        foreach (['pmra', 'pmdec'] as $field) {
            if (($row[$field] === null || ! is_numeric($row[$field])) && ! $extended) {
                throw new RuntimeException("SIMBAD gives no «{$field}» for {$mainId} (type {$row['otype']})");
            }
        }

        return [
            'source' => Star::SIMBAD_SOURCE,
            'ra' => (float) $row['ra'],
            'dec' => (float) $row['dec'],
            'pm_ra' => is_numeric($row['pmra'] ?? null) ? (float) $row['pmra'] : 0.0,
            'pm_dec' => is_numeric($row['pmdec'] ?? null) ? (float) $row['pmdec'] : 0.0,
            'parallax' => $row['plx_value'] !== null ? (float) $row['plx_value'] : null,
        ];
    }

    /**
     * Hipparcos-2's astrometry, carried from J1991.25 to J2000 with its own proper motion.
     *
     * It is the same computation `Stars` does to go from J2000 to a date, and the one Swiss did
     * to write its file: **`pmRA` carries the cosine of the declination inside**, so turning it
     * into right ascension means DIVIDING by it. Checked against `sefstars.txt` star by star: it
     * agrees to three tenths of a milliarcsecond.
     *
     * And the guard against the gross error: the resulting position has to land where SIMBAD
     * puts the same star. Radians read as degrees, or a HIP pointing at another object, are tens
     * of degrees; the real disagreement between the two catalogues never reaches a second.
     *
     * @param array<string, string|null> $hipparcos
     * @param array<string, string|int|float|null> $row SIMBAD's, for the check.
     * @return array{source: string, ra: float, dec: float, pm_ra: float, pm_dec: float, parallax: float|null}
     *
     * @throws RuntimeException
     */
    private static function fromHipparcos(array $hipparcos, array $row): array
    {
        foreach (['RArad', 'DErad', 'pmRA', 'pmDE'] as $field) {
            if (($hipparcos[$field] ?? null) === null || ! is_numeric($hipparcos[$field])) {
                throw new RuntimeException("Hipparcos-2 gives no «{$field}» for HIP {$hipparcos['HIP']}");
            }
        }

        $years = Star::EPOCH - self::HIPPARCOS_EPOCH;
        $masToDegrees = 1 / 3.6e6;

        $dec1991 = (float) $hipparcos['DErad'];
        $pmRa = (float) $hipparcos['pmRA'];
        $pmDec = (float) $hipparcos['pmDE'];

        $ra = (float) $hipparcos['RArad'] + $pmRa / cos(deg2rad($dec1991)) * $years * $masToDegrees;
        $dec = $dec1991 + $pmDec * $years * $masToDegrees;
        $ra = fmod(fmod($ra, 360) + 360, 360);

        if ($row['ra'] !== null && $row['dec'] !== null) {
            $separation = self::separation((float) $row['ra'], (float) $row['dec'], $ra, $dec);

            if ($separation > self::TOLERANCE) {
                throw new RuntimeException(sprintf(
                    'Hipparcos-2 puts HIP %s %.1f arcseconds from where SIMBAD puts %s: either it is not the same star or the units are not degrees.',
                    $hipparcos['HIP'], $separation, $row['main_id']
                ));
            }
        }

        return [
            'source' => Star::HIPPARCOS2_SOURCE,
            'ra' => $ra,
            'dec' => $dec,
            'pm_ra' => $pmRa,
            'pm_dec' => $pmDec,
            'parallax' => $hipparcos['Plx'] !== null && is_numeric($hipparcos['Plx']) ? (float) $hipparcos['Plx'] : null,
        ];
    }

    /**
     * The constellation, from the Bayer designation SIMBAD returns («* alf Leo» is in Leo).
     *
     * It is deduced and not written down: the abbreviation follows the Bayer or Flamsteed part
     * of the designation, and it is the IAU's. What has no such designation, which is the
     * clusters and the galaxies and a handful named after their catalogue, goes to null rather
     * than to a guess.
     *
     * **An abbreviation that is there and is not the IAU's stops the catalogue**, which is what
     * this promised and did not do: it returned null, and that is indistinguishable from a
     * cluster with no designation. Two hundred and eighty-five stars came out with no sky and no
     * word said, because the constellation table had been written for a list a sixth this long.
     *
     * @param string $mainId
     * @return string|null
     *
     * @throws RuntimeException
     */
    private static function constellation(string $mainId): ?string
    {
        /* SIMBAD prefixes a designation by what the object is («*» a star, «V*» a variable,
           «**» a double), and the abbreviation comes after the Bayer letter or the Flamsteed
           number. What does not match this shape has no designation to read a sky out of. */
        if (! preg_match('/^\*{1,2}\s+\S+\s+([A-Z][A-Za-z]{2})\b/', $mainId, $parts)
            && ! preg_match('/^V\*\s+\S+\s+([A-Z][A-Za-z]{2})\b/', $mainId, $parts)) {
            return null;
        }

        return Constellations::name($parts[1])
            ?? throw new RuntimeException("«{$parts[1]}», of {$mainId}, is not an IAU constellation.");
    }

    /**
     * The angular separation between two positions, in arcseconds.
     *
     * @param float $ra1
     * @param float $dec1
     * @param float $ra2
     * @param float $dec2
     * @return float
     */
    private static function separation(float $ra1, float $dec1, float $ra2, float $dec2): float
    {
        $dRa = fmod(fmod($ra1 - $ra2, 360) + 540, 360) - 180;

        return hypot($dRa * cos(deg2rad(($dec1 + $dec2) / 2)), $dec1 - $dec2) * 3600;
    }

    /**
     * @param array<string, string|int|float|null> $row
     * @return float The V magnitude, or a number bigger than any star's when there is none.
     */
    private static function magnitude(array $row): float
    {
        return $row['magnitude'] !== null && is_numeric($row['magnitude']) ? (float) $row['magnitude'] : 99.0;
    }

    /**
     * From «HIP 49669» to 49669.
     *
     * @param string $identifier
     * @return int
     *
     * @throws RuntimeException
     */
    private static function hipNumber(string $identifier): int
    {
        if (! preg_match('/HIP\s*(\d+)/', $identifier, $parts)) {
            throw new RuntimeException("«{$identifier}» does not look like a Hipparcos identifier.");
        }

        return (int) $parts[1];
    }

    /**
     * A name reduced to a key: no accents, lower case, hyphens.
     *
     * @param string $name
     * @return string
     */
    private static function key(string $name): string
    {
        $withoutAccents = strtr(
            mb_strtolower(trim($name)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']
        );

        return trim(preg_replace('/[^a-z0-9]+/', '-', $withoutAccents) ?? '', '-');
    }

    /**
     * A CSV answer turned into rows, checking the width before combining.
     *
     * `array_combine` does not return false since PHP 8: it throws, so a guard after it is
     * unreachable and one malformed line kills the whole thing with a fatal instead of saying
     * which line it was.
     *
     * @param string $body
     * @param string $service For the message.
     * @return list<array<string, string|null>>
     *
     * @throws RuntimeException
     */
    private static function csv(string $body, string $service): array
    {
        $lines = preg_split('/\R/', trim($body)) ?: [];
        $header = str_getcsv((string) array_shift($lines), escape: '');

        if ($header === [] || $header === [null]) {
            throw new RuntimeException("{$service} answered without a header: ".mb_substr($body, 0, 300));
        }

        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, escape: '');

            if (count($values) !== count($header)) {
                throw new RuntimeException("A line from {$service} does not match the header: {$line}");
            }

            $rows[] = array_map(fn ($v) => $v === '' ? null : $v, array_combine($header, $values));
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $stars
     * @param int $fromHipparcos
     * @return string
     */
    private static function render(array $stars, int $fromHipparcos): string
    {
        $php = "<?php\n\n/*\n";
        $php .= " * Fixed stars · catalogue\n *\n";
        $php .= " * GENERATED. Do not edit by hand: it is written by `Astronomy\\StarCatalog::regenerate()`,\n";
        $php .= " * which the command line exposes as `astronomy stars`. Which stars there are comes from\n";
        $php .= " * `star-names.php`; each one is identified by a cone search in SIMBAD (CDS, Strasbourg)\n";
        $php .= " * around the position listed there, and the astrometry comes from Hipparcos-2\n";
        $php .= " * (van Leeuwen 2007, I/311/hip2 through VizieR) for every star with a HIP number.\n";
        $php .= " * Clusters and whatever has no HIP carry SIMBAD's. `source` says which one each came from.\n *\n";
        $php .= " * `ra` and `dec` in degrees, ICRS, referred to `epoch` (Julian years), which is always\n";
        $php .= " * 2000.0: Hipparcos-2 is published at J1991.25 and this carries it to J2000 with the proper\n";
        $php .= " * motion, the same as the Swiss Ephemeris file does. `pm_ra` and `pm_dec` in milliarcseconds\n";
        $php .= " * per year; `pm_ra` already carries the cos(dec) inside, as Hipparcos publishes it.\n";
        $php .= " * `parallax` in milliarcseconds, `radial_velocity` in km/s (always from SIMBAD, which\n";
        $php .= " * Hipparcos does not measure), `magnitude` in the V band. Clusters have no magnitude.\n *\n";
        $php .= " * What a star MEANS is not here and never will be: a planetary nature is astrology, it is\n";
        $php .= " * not measured against anything, and it belongs to whoever reads the chart.\n *\n";
        $php .= sprintf(" * %d stars; %d with astrometry from Hipparcos-2 and %d from SIMBAD.\n */\n\n", count($stars), $fromHipparcos, count($stars) - $fromHipparcos);
        $php .= "return [\n";

        foreach ($stars as $key => $star) {
            $php .= "    '{$key}' => [\n";

            foreach ($star as $field => $value) {
                $php .= sprintf("        '%s' => %s,\n", $field, self::literal($value));
            }

            $php .= "    ],\n";
        }

        return $php."];\n";
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function literal(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            /* Enough digits for the value to come back identical, and not one more: a position
               written with seventeen figures is noise pretending to be precision. */
            return rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') ?: '0';
        }

        if (is_array($value)) {
            return '['.implode(', ', array_map(self::literal(...), $value)).']';
        }

        return "'".str_replace("'", "\\'", (string) $value)."'";
    }
}
