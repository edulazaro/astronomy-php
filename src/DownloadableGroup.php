<?php

namespace Astronomy;

/**
 * The three groups of bodies the engine does not ship with and that are downloaded from the JPL
 * when they are asked for: asteroids, planetary satellites and comets.
 *
 * They do not go in `Body` because they do not fit. The JPL has 1,563,747 asteroids, 4,076 comets
 * and 458 satellites with an ephemeris, counted on 14 September 2026, and it numbers new asteroids
 * every month: an enum with a million and a half cases is a file PHP compiles whole in order to use
 * one of them, and that goes stale the same day it is generated.
 *
 * **They are stored in `downloads/`, inside the data folder, and that depth is not accidental.**
 * `Ephemeris::dataFingerprint` looks at the files in the first two levels to invalidate the caches
 * when the series change, and whatever is downloaded lands in the third and the fourth. If it got
 * in, every download would throw away the caches of every chart, which read none of these bodies,
 * and the fingerprint would have to walk thousands of files on every request. A test guards it.
 */
enum DownloadableGroup: string implements Translatable
{
    case Asteroids = 'asteroids';
    case Satellites = 'satellites';
    case Comets = 'comets';

    /**
     * The stable key, which here is the value: it is already a frozen slug.
     *
     * @return string
     */
    public function key(): string
    {
        return $this->value;
    }

    /**
     * The name in English, for whoever does not translate.
     *
     * @return string
     */
    public function name(): string
    {
        return match ($this) {
            self::Asteroids => 'Asteroids',
            self::Satellites => 'Satellites',
            self::Comets => 'Comets',
        };
    }

    /**
     * The group's folder, relative to the data folder.
     *
     * @return string
     */
    public function folder(): string
    {
        return 'downloads/'.$this->value;
    }

    /**
     * How many years go in each file when nothing else is configured; null is the whole table.
     *
     * Measured against Horizons on 14 September 2026. **Each request costs about 0.7 seconds even
     * when a single day is asked for**, so splitting into small chunks does not divide the time by
     * the same factor it divides the data:
     *
     * - An asteroid goes whole because it pays off: Eris from 1600 to 2400 is 3.2 seconds in a
     *   single request, and a single decade already costs one.
     * - A satellite goes by years. Io needs one point per hour: a year is 1.9 seconds, a decade six
     *   and the eight hundred years seven and a half minutes. Titan, 1.3 against 2.3.
     * - A comet, by years as well, and besides it only makes sense near its passes.
     *
     * The usual thing is to ask for a single date, and with years the first wait is the shortest.
     * Anyone about to sweep decades on end, transits over sixty years, is better off with ten: that
     * is six downloads and not sixty.
     *
     * @return int|null
     */
    public function defaultYearsPerFile(): ?int
    {
        return match ($this) {
            self::Asteroids => null,
            self::Satellites, self::Comets => 1,
        };
    }
}
