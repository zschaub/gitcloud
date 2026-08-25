<?php

declare(strict_types=1);

namespace OCA\GitCloud\Exception;

/**
 * Thrown when a file exceeds the admin-configured max file size while
 * enforcement mode is "block". The exception message is the offending
 * file's relative path.
 */
class FileTooLargeException extends \RuntimeException {
}
