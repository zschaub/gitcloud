<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCP\Files\IRootFolder;
use OCP\Files\Node;

/**
 * Shared by the Before* listeners that guard a `.git` directory at the root of a
 * user's Nextcloud storage against being deleted, renamed, or written to through
 * Nextcloud's normal file operations (Files app, WebDAV, sync clients, occ, ...).
 *
 * Since 0.2.8 GitCloud's own repository no longer lives there - it sits beside the
 * user's `files` directory, outside their storage entirely, where none of those
 * channels can reach it (see VcsService::resolveGitDirectory()). These guards are
 * kept as belt-and-braces rather than the only line of defense: they still protect
 * an install that hasn't run the relocation repair step yet, and anyone who has a
 * `.git` directory in their files for unrelated reasons.
 */
trait GitRepositoryPathGuard {
	private function isInsideGitRepository(Node $node, IRootFolder $rootFolder): bool {
		$owner = $node->getOwner();
		if ($owner === null) {
			return false;
		}

		try {
			$userFolder = $rootFolder->getUserFolder($owner->getUID());
		} catch (\Throwable $e) {
			return false;
		}

		return $this->isRelativePathInsideGitRepository($userFolder->getRelativePath($node->getPath()) ?? '');
	}

	private function isRelativePathInsideGitRepository(string $relativePath): bool {
		$relativePath = ltrim($relativePath, '/');

		return $relativePath === '.git' || str_starts_with($relativePath, '.git/');
	}
}
