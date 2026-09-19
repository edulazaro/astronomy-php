<?php

namespace Astronomy;

use InvalidArgumentException;
use LogicException;

/**
 * Where the engine's data lives: the analytical series, the JPL tables, the corrections and the
 * catalogues.
 *
 * This is what used to tie the engine to a framework: every class asked for its file with a framework path helper, so
 * outside a booted application not a single position could be computed. Now they all ask for
 * it here, and the folder can be changed.
 *
 * By default it is this project's `resources/astro`, and nothing has to be configured. The
 * folder is deduced from where this very file is, so it works the same inside a framework as with
 * just Composer's autoload. An application changes it with `useFolder()` at boot; here that is done
 * by the host application at boot, from wherever it keeps its configuration.
 *
 * It is set at boot and not changed afterwards, and that is why `useFolder()` blows up if
 * something has already been read from another folder. The engine classes remember what they read in
 * static variables: a folder changed halfway through the process would leave some tables coming from
 * one and some from the other, without any error. Setting the same folder again is allowed, because
 * that is what happens every time the application boots again within the same process, which is what
 * the tests do.
 */
final class DataFolder
{
    /** The folder that was set, or null for the default one. */
    private static ?string $folder = null;

    /** Whether some path has already been asked for: from that moment on, changing folder is not safe. */
    private static bool $used = false;

    /**
     * Sets the folder the engine reads from.
     *
     * @param string $folder
     * @return void
     *
     * @throws InvalidArgumentException If the folder does not exist.
     * @throws LogicException If something has already been read from another folder.
     */
    public static function useFolder(string $folder): void
    {
        $folder = rtrim($folder, '/\\');

        if (! is_dir($folder)) {
            throw new InvalidArgumentException("The engine data folder does not exist: {$folder}");
        }

        if (self::$used && realpath($folder) !== realpath(self::folder())) {
            throw new LogicException(sprintf(
                'The engine data folder was already in use (%s) and cannot be changed to %s midway: data from both would get mixed. Set it at boot.',
                self::folder(),
                $folder
            ));
        }

        self::$folder = $folder;
    }

    /**
     * The folder the engine reads from.
     *
     * @return string
     */
    public static function folder(): string
    {
        return self::$folder ?? dirname(__DIR__).'/resources/astro';
    }

    /**
     * The path of a data file or subfolder, relative to the engine's folder.
     *
     * @param string $relative For example `positions/pluto.php`, or `{*,*}/*` for a glob.
     * @return string
     */
    public static function path(string $relative = ''): string
    {
        self::$used = true;

        return $relative === '' ? self::folder() : self::folder().'/'.ltrim($relative, '/\\');
    }
}
