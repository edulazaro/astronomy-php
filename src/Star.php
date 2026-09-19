<?php

namespace Astronomy;

/**
 * A fixed star just as the catalogue holds it: what is known about it before asking for any
 * date.
 *
 * The coordinates are ICRS and referred to epoch J2000.0, and the proper motion is as
 * Hipparcos publishes it: `pmRa` already has the cosine of the declination multiplied in, so it
 * is a real displacement across the sky and not an increment of right ascension. Confusing the
 * two gives no error, and on Polaris it multiplies the motion by seventy.
 *
 * Where the numbers come from is told by `source`: Hipparcos-2 (van Leeuwen 2007) for almost
 * all of them, which is what Swiss Ephemeris carries and is still the reference for the bright
 * stars because Gaia saturates on them; SIMBAD for the clusters and for whatever has no
 * Hipparcos number. The epoch is always 2000.0 and `fromArray` checks it: Hipparcos-2 is
 * published at J1991.25 and the command carries it to J2000 with the proper motion, and the
 * engine counts the years from J2000. A catalogue referred to another epoch would leave every
 * star eight years of proper motion out of place without giving any error.
 *
 * **What a star MEANS is not here**, and that is the line this package draws. The planetary
 * nature an astrologer reads into a star (Regulus «of Mars and Jupiter») is Robson, 1923, who
 * collects Ptolemy: it is not measured against anything and Swiss does not give it either. It
 * travelled inside this catalogue in a `nature` column, and not by decision: the file was born
 * inside an application, and when the engine was pulled out into a package the whole data folder
 * moved without anyone reading what the columns were. It lives in that application now.
 */
readonly class Star
{
    /** Hipparcos, the new reduction (van Leeuwen 2007, catalogue I/311 of the CDS). */
    public const HIPPARCOS2_SOURCE = 'hipparcos2';

    /** SIMBAD (CDS), which gives whatever it has for the object: today almost always Gaia. */
    public const SIMBAD_SOURCE = 'simbad';

    /** The only epoch the engine understands: `Stars` counts the years from J2000. */
    public const EPOCH = 2000.0;

    /**
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $designation,
        public string $simbad,
        /**
         * The other names it goes by, as Swiss's list gives them.
         *
         * They are here so that `Stars::find` answers to what someone writes and not only to
         * what the catalogue happens to call it: Swiss names M 44 «Praesepe Cluster» and alpha
         * Centauri «Rigil Kentaurus», so asking for Praesepe or for Toliman found nothing.
         *
         * @var list<string>
         */
        public array $aliases,
        public ?int $hip,
        public string $constellation,
        /** Degrees, ICRS, epoch J2000.0. */
        public float $rightAscension,
        /** Degrees, ICRS, epoch J2000.0. */
        public float $declination,
        /** Milliarcseconds per year, with the cos(dec) already inside. */
        public float $pmRa,
        /** Milliarcseconds per year. */
        public float $pmDec,
        /** Milliarcseconds, or null if SIMBAD does not give it. */
        public ?float $parallax,
        /** Kilometres per second, or null. */
        public ?float $radialVelocity,
        /** V band. The clusters have none. */
        public ?float $magnitude,
        /** Where the astrometry comes from: `HIPPARCOS2_SOURCE` or `SIMBAD_SOURCE`. Null if not recorded. */
        public ?string $source = null,
        /** Epoch to which `rightAscension` and `declination` are referred, in Julian years. */
        public float $epoch = self::EPOCH,
    ) {}

    /**
     * @param string $key
     * @param array<string, mixed> $data An entry of `resources/astro/stars.php`.
     * @return self
     */
    public static function fromArray(string $key, array $data): self
    {
        $epoch = (float) ($data['epoch'] ?? self::EPOCH);

        // The check that holds the rule up: the engine knows nothing about epochs, so a
        // catalogue referred to another one would be wrong in silence. Better that it blows up
        // while loading.
        if (abs($epoch - self::EPOCH) > 1e-9) {
            throw new \InvalidArgumentException(sprintf(
                '%s is referred to epoch %.2f and the engine only understands J%.1f', $key, $epoch, self::EPOCH
            ));
        }

        return new self(
            key: $key,
            name: (string) $data['name'],
            designation: (string) $data['designation'],
            simbad: (string) $data['simbad'],
            aliases: array_map(strval(...), $data['aliases'] ?? []),
            hip: $data['hip'] ?? null,
            constellation: (string) $data['constellation'],
            rightAscension: (float) $data['ra'],
            declination: (float) $data['dec'],
            pmRa: (float) $data['pm_ra'],
            pmDec: (float) $data['pm_dec'],
            parallax: isset($data['parallax']) ? (float) $data['parallax'] : null,
            radialVelocity: isset($data['radial_velocity']) ? (float) $data['radial_velocity'] : null,
            magnitude: isset($data['magnitude']) ? (float) $data['magnitude'] : null,
            source: isset($data['source']) ? (string) $data['source'] : null,
            epoch: $epoch,
        );
    }

    /**
     * Whether it is an extended object, a cluster or a galaxy, and not a star.
     *
     * Tradition treats Praesepe, Facies, Aculeus and Acumen as if they were stars, and they are
     * computed the same way. But their «position» is the centre of something that has extent,
     * and in the text it is better not to call them a star.
     *
     * **It is the designation that says so and not the missing magnitude**, which is what this
     * asked while the catalogue had 174 entries and only clusters lacked one. Over 1,099 that is
     * false: SIMBAD publishes no V flux for a system it gives as a whole, so 22 perfectly
     * ordinary stars have no magnitude (14 Andromedae, gamma Cephei, mu1 Bootis) and were being
     * called clusters. The eleven that are extended are exactly the eleven the catalogue names with a
     * catalogue number, which is why they are the ones it asks SIMBAD for by that number.
     *
     * @return bool
     */
    public function isCluster(): bool
    {
        return preg_match('/^(M|NGC|IC) /', $this->designation) === 1;
    }
}
