<?php

namespace Astronomy;

/**
 * A body the engine does not ship and whose data is downloaded from the JPL: a `Satellite`, an
 * `Asteroid` or a `Comet`.
 *
 * Two things about it matter, what is asked of the JPL and which file it ends up stored in, and both
 * are asked here so that `Downloader`, which downloads, and the engine, which reads, cannot disagree.
 * If each one built the path on its own, the day one of them changed, files nobody reads would be
 * downloaded, without any error at all.
 */
interface DownloadableBody
{
    /**
     * What is asked of JPL Horizons in `COMMAND`.
     *
     * @return string
     */
    public function horizonsId(): string;

    /**
     * The file that holds its data for that instant, relative to the data folder.
     *
     * Whether it exists or not is another question, and `Downloadables::path()` answers that one.
     *
     * @param float $jdTT
     * @return string
     */
    public function file(float $jdTT): string;

    /**
     * How it is written for a person: "Io", "S/2003 J 2", "(136199)", "C/1995 O1".
     *
     * @return string
     */
    public function name(): string;

    /**
     * @return DownloadableGroup
     */
    public function group(): DownloadableGroup;
}
