<?php

declare(strict_types=1);

namespace OCA\GitCloud\Exception;

/**
 * Thrown when a file or a folder's contents are not on local storage
 * (e.g. an external storage mount), which GitCloud cannot commit.
 * The exception message is the offending file's relative path.
 */
class NotLocalStorageException extends \RuntimeException {
}
