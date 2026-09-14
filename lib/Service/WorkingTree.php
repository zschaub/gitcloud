<?php

declare(strict_types=1);

namespace OCA\GitCloud\Service;

use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * Whether a node's bytes actually live inside GitCloud's Git working tree.
 *
 * VcsService::resolveRepositoryPath() derives the working tree exclusively from
 * the user folder's own storage (<data>/<uid>/files, with the Git directory at
 * the sibling <data>/<uid>/gitcloud), but a node is addressed by its *virtual*
 * mount-tree path (Folder::getRelativePath(), pure string subtraction that
 * happily crosses mount boundaries). A group folder, a received share or an
 * external storage is a separate mount inside files/ whose bytes are somewhere
 * else entirely - a group folder's live under <data>/__groupfolders/<numericId>/,
 * which no string transformation of its virtual path can recover - so git is
 * handed a pathspec that does not exist.
 *
 * IStorage::isLocal() does not catch this: it means "the bytes are on this
 * filesystem", not "the bytes are in this user's home", so a group folder or a
 * local external mount passes it. Comparing storage identity against the user
 * folder's own storage is what actually matches how the working tree is derived,
 * and covers every mount type, including ones that don't exist yet.
 *
 * Kept as a shared static helper, following GitArchitecture::detect() and
 * BundledGitBinary, rather than a VcsService method: VcsService is mocked
 * throughout the controller and listener test suites, where an instance method
 * would silently default to false.
 */
final class WorkingTree {
	public static function contains(Node $node, Folder $userFolder): bool {
		return $node->getStorage()->getId() === $userFolder->getStorage()->getId();
	}
}
