<?php

declare(strict_types=1);

namespace OCA\GitCloud\Migration;

use OCA\GitCloud\Service\VcsService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * One-time relocation of each user's GitCloud repository from `files/.git` - where every
 * release up to and including 0.2.7 kept it - to a `gitcloud` directory beside `files`,
 * outside the user's Nextcloud storage entirely (see VcsService::resolveGitDirectory()).
 *
 * The repository is moved wholesale with a single rename(): a plain repository whose
 * `.git` directory is moved to a sibling path keeps its full history and works unchanged
 * when git is pointed at it with `--git-dir`/`--work-tree`, so no git-level surgery is
 * needed. Working-tree files are never touched.
 *
 * The now-stale `.git` entry is also dropped from Nextcloud's file cache, so it stops
 * appearing as a phantom folder in the Files app until the next filesystem scan.
 */
class MoveGitDirectoryOutOfUserFiles implements IRepairStep {
	public function __construct(
		private IUserManager $userManager,
		private IRootFolder $rootFolder,
		private VcsService $vcsService,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'Move GitCloud repositories out of the user files directory';
	}

	public function run(IOutput $output): void {
		$moved = 0;

		$this->userManager->callForSeenUsers(function (IUser $user) use ($output, &$moved): void {
			if ($this->relocateForUser($user->getUID(), $output)) {
				$moved++;
			}
		});

		$output->info($moved === 0
			? 'No GitCloud repository needed relocating.'
			: sprintf('Relocated %d GitCloud repository/repositories out of the user files directory.', $moved));
	}

	/**
	 * @return bool Whether a repository was actually relocated for this user.
	 */
	private function relocateForUser(string $userId, IOutput $output): bool {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (NotFoundException|NotPermittedException $e) {
			$this->logger->warning(sprintf('GitCloud: could not open the files folder for user %s while relocating its repository: %s', $userId, $e->getMessage()), ['exception' => $e]);
			return false;
		}

		$repositoryPath = $this->vcsService->resolveRepositoryPath($userFolder);
		if ($repositoryPath === false) {
			// Not on local storage, so GitCloud never created a repository here anyway.
			return false;
		}

		$legacyPath = $repositoryPath . '/' . VcsService::LEGACY_GIT_DIRECTORY_NAME;
		$targetPath = $this->vcsService->resolveGitDirectory($repositoryPath);

		if (!is_dir($legacyPath)) {
			return false;
		}

		if (is_dir($targetPath)) {
			// A repository already exists at the new location; leave both alone rather
			// than guessing which one holds the history the user actually wants.
			$output->warning(sprintf('GitCloud: %s has repositories at both the old and new location - leaving both untouched.', $userId));
			$this->logger->warning(sprintf('GitCloud: user %s has a repository at both %s and %s; not relocating.', $userId, $legacyPath, $targetPath));
			return false;
		}

		if (!@rename($legacyPath, $targetPath)) {
			$lastError = error_get_last();
			$output->warning(sprintf('GitCloud: failed to relocate the repository for %s: %s', $userId, $lastError['message'] ?? 'unknown error'));
			$this->logger->error(sprintf('GitCloud: failed to relocate %s to %s: %s', $legacyPath, $targetPath, $lastError['message'] ?? 'unknown error'));
			return false;
		}

		$this->forgetLegacyCacheEntry($userFolder, $userId);

		$output->info(sprintf('GitCloud: relocated the repository for %s out of its files directory.', $userId));
		$this->logger->info(sprintf('GitCloud: relocated %s to %s', $legacyPath, $targetPath));

		return true;
	}

	/**
	 * Drops the stale `.git` row (and its children) from oc_filecache. Uses the same
	 * IUpdater the app already relies on after writing file content outside the Node API
	 * (see ApiController::rollbackSnapshot).
	 */
	private function forgetLegacyCacheEntry(Folder $userFolder, string $userId): void {
		try {
			$userFolder->getStorage()->getUpdater()->remove(
				ltrim($userFolder->getInternalPath() . '/' . VcsService::LEGACY_GIT_DIRECTORY_NAME, '/'),
			);
		} catch (\Throwable $e) {
			// The move itself already succeeded; a stale cache row only means the folder
			// lingers in the Files app until the next scan, so this must not abort upgrade.
			$this->logger->warning(sprintf('GitCloud: relocated the repository for %s but could not clear its old file cache entry: %s', $userId, $e->getMessage()), ['exception' => $e]);
		}
	}
}
