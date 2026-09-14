<?php

declare(strict_types=1);

namespace OCA\GitCloud\Exception;

/**
 * Thrown when a file or a folder's contents sit on a different storage than the
 * user's own home storage (a group folder, a received share, an external mount),
 * and therefore outside GitCloud's Git working tree - see Service\WorkingTree.
 * The exception message is the offending file's relative path.
 */
class NotInWorkingTreeException extends \RuntimeException {
}
