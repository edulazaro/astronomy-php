<?php

namespace Astronomy;

use RuntimeException;

/**
 * A downloadable body was requested and its file is not on disk.
 *
 * It is a class of its own and not a plain `RuntimeException` because whoever catches it may want
 * to do something other than show an error: an application that downloads on the fly catches it,
 * queues the download and answers "not there yet". That is what it carries the body and the
 * missing file for.
 */
final class MissingData extends RuntimeException
{
    /**
     * @param DownloadableBody $body
     * @param string $dataFile Relative to the data folder.
     * @param string $message
     */
    public function __construct(
        public readonly DownloadableBody $body,
        public readonly string $dataFile,
        string $message,
    ) {
        parent::__construct($message);
    }
}
