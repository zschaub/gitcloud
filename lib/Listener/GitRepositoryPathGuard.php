<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCP\Files\IRootFolder;
use OCP\Files\Node;

/**
 * Shared by the Before* listeners that guard GitCloud's own .git repository
 * against being deleted, renamed, or written to directly through Nextcloud's
 * normal file operations (Files app, WebDAV, sync clients, occ, ...), which
 * would otherwise be free to corrupt it - .git sits on disk at the root of the
 * user's own Nextcloud storage rather than somewhere GitCloud can hide it.
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
