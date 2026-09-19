<?php

namespace Astronomy;

use InvalidArgumentException;
use LogicException;

/**
 * How the data of the downloadable bodies is laid out on disk, and where each file is.
 *
 * Asteroids by number, satellites of the planets and comets: a million and a half, four hundred and
 * fifty eight and four thousand of them. That does not ship with a package, so it is fetched from
 * JPL Horizons with `Downloader` and written into the data folder. A chart reads none of them.
 *
 * **This class holds no policy, and that is the whole design.** It does not know which bodies may be
 * downloaded, and it never goes to the network on its own. When a file is missing, `path()` throws
 * `MissingData`, which carries the body and the file that is missing, and whoever called decides
 * what to do with that:
 *
 *     try {
 *         $position = Ephemeris::position($eris, $jdTT);
 *     } catch (MissingData $missing) {
 *         Downloader::download($missing->body, $jdTT);
 *     }
 *
 * The reason is that the decision depends on something only the application knows: whether it is
 * serving a web request, where waiting seconds for the JPL is unacceptable, or running a script,
 * where it is the whole point. A library that decides that for you is a library you have to
 * configure to stop it.
 *
 * What IS here is `useYearsPerFile()`, which is not policy but the shape of the data: the name of a
 * file carries the years it holds (`satellites/501/1980-1989.bin`), so whoever writes and whoever
 * reads have to agree on it.
 *
 * **The group is called `satellites` and not `moons`** because `Moon` is already the ELP theory of
 * our Moon and `Body::Moon` the body. Three things called the same that are not the same thing is
 * the confusion already noted with Vulcan and Vulkanus.
 *
 */
final class Downloadables
{
    /** @var array<string, int|null> Per group, the years each file carries. */
    private static array $years = [];

    /**
     * How the data of each group is split into files.
     *
     * **This is the only thing that is configured here, and it is not policy: it is the shape of
     * the data on disk.** The file of a body is named after the years it carries
     * (`satellites/501/1980-1989.bin`), so whoever downloads and whoever reads have to agree or
     * the reader looks for a name that was never written.
     *
     * **What is NOT here, on purpose: whether a body may be downloaded, and whether something
     * missing should be fetched right now.** Both are decisions of the application, not of a
     * library: only the application knows whether it is serving a web request, where waiting for
     * the JPL is unacceptable, or running a script at night, where it is the whole point. The
     * package gives the tools and says what is missing; it takes no decision.
     *
     * @param array<string, int|null> $yearsPerGroup Years each file carries, by group. Null is
     *        the whole range in one file.
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public static function useYearsPerFile(array $yearsPerGroup): void
    {
        $groups = array_map(fn (DownloadableGroup $group) => $group->value, DownloadableGroup::cases());
        $unknown = array_diff(array_keys($yearsPerGroup), $groups);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Years per file go by group, and «%s» is not one. The groups are %s.',
                implode('», «', $unknown),
                implode(', ', $groups)
            ));
        }

        $years = [];

        foreach (DownloadableGroup::cases() as $group) {
            $years[$group->value] = array_key_exists($group->value, $yearsPerGroup)
                ? self::readYears($group, $yearsPerGroup[$group->value])
                : $group->defaultYearsPerFile();
        }

        self::$years = $years;
    }




    /**
     * How many years each file of the group carries; null is the whole table.
     *
     * It is looked up with `array_key_exists` and not with `??` because null is a configurable value,
     * the whole table, and with `??` a group configured to null would silently fall back to its default.
     *
     * @param DownloadableGroup $group
     * @return int|null
     */
    public static function yearsPerFile(DownloadableGroup $group): ?int
    {
        return array_key_exists($group->value, self::$years)
            ? self::$years[$group->value]
            : $group->defaultYearsPerFile();
    }

    /**
     * The file of a body for an instant, relative to the data folder.
     *
     * The three types use it, so that the rule is written in a single place.
     *
     * The chunks are aligned to the calendar and not to the date asked for: with ten years, 1985 falls
     * in 1980-1989 whoever asks for it, so two requests from the same decade share a file. The year is
     * that of the instant in TT; the seventy seconds it differs from the clock do not matter, because
     * every file will carry margin on both sides.
     *
     * With one year per file the name is the year, `1985.bin`, and with more, the two ends,
     * `1980-1989.bin`. If both were named by the first year, changing the configuration from one to ten
     * would make a one-year file be read as if it were a ten-year one.
     *
     * @internal
     *
     * @param DownloadableGroup $group
     * @param string $key What tells the body apart within its group, already fit for a file name.
     * @param float $jdTT
     * @return string
     */
    public static function file(DownloadableGroup $group, string $key, float $jdTT): string
    {
        $start = self::spanStart($group, $jdTT);

        if ($start === null) {
            return "{$group->folder()}/{$key}.bin";
        }

        $years = (int) self::yearsPerFile($group);
        $span = $years === 1 ? (string) $start : $start.'-'.($start + $years - 1);

        return "{$group->folder()}/{$key}/{$span}.bin";
    }

    /**
     * The first year of the span that contains an instant, or null if the group goes in a whole table.
     *
     * The file name and the `Downloader` ask for it, and the two have to split at the same place.
     *
     * @internal
     *
     * @param DownloadableGroup $group
     * @param float $jdTT
     * @return int|null
     */
    public static function spanStart(DownloadableGroup $group, float $jdTT): ?int
    {
        $years = self::yearsPerFile($group);

        return $years === null ? null : (int) (floor(Time::civilDate($jdTT)[0] / $years) * $years);
    }

    /**
     * The full path of the file of a body for an instant, or an exception saying how to get it.
     *
     * @param DownloadableBody $body
     * @param float $jdTT
     * @return string
     *
     * @throws MissingData
     */
    public static function path(DownloadableBody $body, float $jdTT): string
    {
        $file = $body->file($jdTT);
        $path = DataFolder::path($file);

        if (is_file($path)) {
            return $path;
        }

        $when = self::yearsPerFile($body->group()) === null ? '' : ' in '.Time::civilDate($jdTT)[0];

        throw new MissingData($body, $file, sprintf(
            'The data for %s%s is missing: %s is not in %s. Whoever wants it fetches it with Downloader, and decides when: this package does not go to the network on its own.',
            $body->name(),
            $when,
            $file,
            DataFolder::folder()
        ));
    }

    /**
     * A body of a group from what would be written in its list: a number, a designation or, for the
     * satellites, the name of its case. It is what whoever receives bodies as text, such as a command,
     * uses.
     *
     * @param DownloadableGroup $group
     * @param int|string $entry
     * @return DownloadableBody
     *
     * @throws InvalidArgumentException
     */
    public static function body(DownloadableGroup $group, int|string $entry): DownloadableBody
    {
        return self::readEntry($group, $entry);
    }


    /**
     * @param DownloadableGroup $group
     * @param mixed $entry
     * @return DownloadableBody
     */
    private static function readEntry(DownloadableGroup $group, mixed $entry): DownloadableBody
    {
        if ($entry instanceof DownloadableBody) {
            if ($entry->group() !== $group) {
                throw new InvalidArgumentException("{$entry->name()} belongs to {$entry->group()->value} and is in the list of {$group->value}.");
            }

            return $entry;
        }

        if (! is_int($entry) && ! is_string($entry)) {
            throw new InvalidArgumentException("The list of {$group->value} only takes numbers and strings, and what arrived was a ".get_debug_type($entry).'.');
        }

        $isNumber = is_int($entry) || ctype_digit($entry);

        return match ($group) {
            DownloadableGroup::Asteroids => $isNumber ? Asteroid::number((int) $entry) : Asteroid::designation($entry),
            DownloadableGroup::Comets => Comet::designation((string) $entry),
            DownloadableGroup::Satellites => ($isNumber ? Satellite::tryFrom((int) $entry) : self::satelliteNamed($entry))
                ?? throw new InvalidArgumentException("There is no satellite «{$entry}» in the JPL list. They go by their identifier (501) or by their case name in Satellite (Io)."),
        };
    }

    /**
     * @param string $name The name of a `Satellite` case.
     * @return Satellite|null
     */
    private static function satelliteNamed(string $name): ?Satellite
    {
        foreach (Satellite::cases() as $satellite) {
            if ($satellite->name === $name) {
                return $satellite;
            }
        }

        return null;
    }

    /**
     * @param DownloadableGroup $group
     * @param mixed $value
     * @return int|null
     */
    private static function readYears(DownloadableGroup $group, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $years = filter_var($value, FILTER_VALIDATE_INT);

        if ($years === false || $years < 1) {
            throw new InvalidArgumentException("The years per file for {$group->value} have to be an integer of one or more, or null for the whole table.");
        }

        return $years;
    }
}
