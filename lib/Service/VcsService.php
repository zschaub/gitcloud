<?php

declare(strict_types=1);

namespace OCA\GitCloud\Service;

use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Db\SnapshotMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

/**
 * Service class handling all version control system logic for GitCloud.
 * In a real-world scenario, this would interface with an actual Git repository
 * accessible to the Nextcloud instance (e.g., via SSH keys or local filesystem mounting).
 */
class VcsService {
	private LoggerInterface $logger;
	private SnapshotMapper $snapshotMapper;
	private ITimeFactory $timeFactory;
	private ?bool $gitAvailable = null;

	public const GIT_NOT_INSTALLED_MESSAGE = 'git is not installed on this server (the "git" binary could not be found on the PATH). Please install git and ensure it is available to the web server user.';

	public function __construct(LoggerInterface $logger, SnapshotMapper $snapshotMapper, ITimeFactory $timeFactory) {
		$this->logger = $logger;
		$this->snapshotMapper = $snapshotMapper;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Checks, without shelling out, whether a "git" executable exists anywhere on the
	 * current PATH. Cached per-instance since VcsService is constructed fresh per request.
	 */
	private function isGitAvailable(): bool {
		if ($this->gitAvailable !== null) {
			return $this->gitAvailable;
		}

		$pathEnv = getenv('PATH');
		if ($pathEnv === false || $pathEnv === '') {
			return $this->gitAvailable = false;
		}

		foreach (explode(PATH_SEPARATOR, $pathEnv) as $dir) {
			if ($dir !== '' && is_executable($dir . '/git')) {
				return $this->gitAvailable = true;
			}
		}

		return $this->gitAvailable = false;
	}

	/**
	 * Builds a real error message for a failed proc_open() call (e.g. resource limits,
	 * a cwd that vanished mid-request, permission issues) from PHP's own last-error
	 * state, instead of a generic "something went wrong" placeholder. Callers must
	 * call error_clear_last() immediately before their proc_open() so this reflects
	 * that call's own failure and not a stale, unrelated warning.
	 */
	private function describeProcOpenFailure(): string {
		$lastError = error_get_last();
		return sprintf('Unable to start the git process: %s', $lastError['message'] ?? 'unknown error');
	}

	/**
	 * Stages the given files and commits them in the Git repository rooted at $repositoryPath.
	 * Initializes the repository if it does not already exist.
	 * @param string $repositoryPath Absolute local filesystem path to the repository's working tree.
	 * @param list<array{path: string, fileId: int}> $relativeFiles Files, relative to $repositoryPath, to stage,
	 *                                                              each paired with its Nextcloud fileid.
	 * @param string $message The commit message provided by the user.
	 * @param string $userId The UID of the user performing the commit, used to record snapshot rows.
	 * @return array{success: bool, message: string}
	 */
	public function commitChanges(string $repositoryPath, array $relativeFiles, string $message, string $userId): array {
		if (empty($relativeFiles)) {
			$this->logger->warning('Attempted to commit with no files selected.');
			return ['success' => false, 'message' => 'No files selected for commitment.'];
		}

		if (trim($message) === '') {
			return ['success' => false, 'message' => 'A commit message is required.'];
		}

		if (!is_dir($repositoryPath)) {
			$this->logger->warning(sprintf('Repository path does not exist: %s', $repositoryPath));
			return ['success' => false, 'message' => 'Repository path does not exist.'];
		}

		$initResult = $this->ensureRepository($repositoryPath);
		if (!$initResult['success']) {
			return $initResult;
		}

		$relativeFilePaths = array_column($relativeFiles, 'path');

		$addResult = $this->runGit($repositoryPath, array_merge(['add', '--'], $relativeFilePaths));
		if (!$addResult['success']) {
			$this->logger->warning(sprintf('git add failed: %s', $addResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to stage files: %s', $addResult['output'])];
		}

		$stagedDiffResult = $this->runGit($repositoryPath, ['diff', '--cached', '--quiet']);
		if ($stagedDiffResult['success']) {
			return ['success' => false, 'message' => 'No changes to commit for the selected file(s).'];
		}

		$commitResult = $this->runGit($repositoryPath, ['commit', '-m', $message]);
		if (!$commitResult['success']) {
			$this->logger->info(sprintf('git commit did not succeed: %s', $commitResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to commit changes: %s', $commitResult['output'])];
		}

		$headResult = $this->runGit($repositoryPath, ['rev-parse', 'HEAD']);
		if (!$headResult['success']) {
			$this->logger->warning(sprintf('git rev-parse HEAD failed after commit: %s', $headResult['output']));
		}
		$commitHash = $headResult['success'] ? trim($headResult['output']) : '';

		foreach ($relativeFiles as $file) {
			// Chained by fileid, not path, so a file deleted and later recreated at
			// the same path (a different fileid) correctly starts a fresh history
			// chain instead of being misattributed as a continuation of the old one.
			$parentSnapshot = $this->snapshotMapper->findLatestForFileId($userId, $file['fileId']);
			$parentSnapshotId = $parentSnapshot?->getId();
			$this->createSnapshotRecord($userId, $file['path'], $commitHash, $message, $parentSnapshotId, 'committed', $file['fileId']);
		}

		$this->logger->info(sprintf('Committed %d file(s) with message: "%s"', count($relativeFiles), $message));
		return [
			'success' => true,
			'message' => 'Successfully staged and committed changes.',
		];
	}

	/**
	 * Reacts to a GitCloud-tracked file being deleted outside GitCloud (Files app,
	 * WebDAV, sync clients, etc.) by staging its removal and auto-committing it
	 * immediately, so the repository and dashboard stay in sync with reality
	 * instead of waiting for the user's next manual commit.
	 * @return array{success: bool, message: string}
	 */
	public function autoCommitDelete(string $repositoryPath, string $relativeFilePath, int $fileId, string $userId): array {
		if (!is_dir($repositoryPath) || !is_dir($repositoryPath . '/.git')) {
			return ['success' => false, 'message' => 'Repository has not been initialized yet.'];
		}

		// The path is already missing from the working tree (Nextcloud already
		// deleted it), so `git add` on it stages the deletion exactly like `git rm`
		// would - the same staging idiom used everywhere else in this class.
		$addResult = $this->runGit($repositoryPath, ['add', '--', $relativeFilePath]);
		if (!$addResult['success']) {
			$this->logger->warning(sprintf('git add failed while auto-committing a delete: %s', $addResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to stage deletion: %s', $addResult['output'])];
		}

		$stagedDiffResult = $this->runGit($repositoryPath, ['diff', '--cached', '--quiet']);
		if ($stagedDiffResult['success']) {
			return ['success' => false, 'message' => 'Nothing to auto-commit for the deleted file.'];
		}

		$message = sprintf('Auto-commit: deleted %s', $relativeFilePath);
		$commitResult = $this->runGit($repositoryPath, ['commit', '-m', $message]);
		if (!$commitResult['success']) {
			$this->logger->info(sprintf('git commit did not succeed while auto-committing a delete: %s', $commitResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to auto-commit deletion: %s', $commitResult['output'])];
		}

		$headResult = $this->runGit($repositoryPath, ['rev-parse', 'HEAD']);
		if (!$headResult['success']) {
			$this->logger->warning(sprintf('git rev-parse HEAD failed after auto-committing a delete: %s', $headResult['output']));
		}
		$commitHash = $headResult['success'] ? trim($headResult['output']) : '';

		$parentSnapshot = $this->snapshotMapper->findLatestForFileId($userId, $fileId);
		$parentSnapshotId = $parentSnapshot?->getId();
		$this->createSnapshotRecord($userId, $relativeFilePath, $commitHash, $message, $parentSnapshotId, 'deleted', $fileId);

		$this->logger->info(sprintf('Auto-committed deletion of %s', $relativeFilePath));
		return ['success' => true, 'message' => sprintf('Auto-committed deletion of %s.', $relativeFilePath)];
	}

	/**
	 * Reacts to a GitCloud-tracked file being renamed or moved outside GitCloud by
	 * staging both the now-missing old path and the new path and auto-committing
	 * immediately. Nextcloud's rename/move has already completed on disk by the time
	 * this runs, so a literal `git mv` can't operate on the old location anymore;
	 * staging both paths together lets git's own similarity-based rename detection
	 * record it as a rename in the commit, which is the correct git-native equivalent.
	 * @return array{success: bool, message: string}
	 */
	public function autoCommitRename(string $repositoryPath, string $oldRelativePath, string $newRelativePath, int $fileId, string $userId): array {
		if (!is_dir($repositoryPath) || !is_dir($repositoryPath . '/.git')) {
			return ['success' => false, 'message' => 'Repository has not been initialized yet.'];
		}

		$addResult = $this->runGit($repositoryPath, ['add', '--', $oldRelativePath, $newRelativePath]);
		if (!$addResult['success']) {
			$this->logger->warning(sprintf('git add failed while auto-committing a rename: %s', $addResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to stage rename: %s', $addResult['output'])];
		}

		$stagedDiffResult = $this->runGit($repositoryPath, ['diff', '--cached', '--quiet']);
		if ($stagedDiffResult['success']) {
			return ['success' => false, 'message' => 'Nothing to auto-commit for the renamed file.'];
		}

		$message = sprintf('Auto-commit: renamed %s to %s', $oldRelativePath, $newRelativePath);
		$commitResult = $this->runGit($repositoryPath, ['commit', '-m', $message]);
		if (!$commitResult['success']) {
			$this->logger->info(sprintf('git commit did not succeed while auto-committing a rename: %s', $commitResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to auto-commit rename: %s', $commitResult['output'])];
		}

		$headResult = $this->runGit($repositoryPath, ['rev-parse', 'HEAD']);
		if (!$headResult['success']) {
			$this->logger->warning(sprintf('git rev-parse HEAD failed after auto-committing a rename: %s', $headResult['output']));
		}
		$commitHash = $headResult['success'] ? trim($headResult['output']) : '';

		$parentSnapshot = $this->snapshotMapper->findLatestForFileId($userId, $fileId);
		$parentSnapshotId = $parentSnapshot?->getId();
		$this->createSnapshotRecord($userId, $newRelativePath, $commitHash, $message, $parentSnapshotId, 'committed', $fileId);

		$this->logger->info(sprintf('Auto-committed rename of %s to %s', $oldRelativePath, $newRelativePath));
		return ['success' => true, 'message' => sprintf('Auto-committed rename of %s to %s.', $oldRelativePath, $newRelativePath)];
	}

	/**
	 * Reacts to a GitCloud-tracked, GitCloud-deleted file being restored from
	 * Nextcloud's trash by re-staging it and auto-committing it immediately, so
	 * the repository's index and the dashboard's Deleted status stay in sync
	 * with the file actually being back on disk. Without this, the file stays
	 * missing from git's index indefinitely (GitCloud only ever finds out about
	 * a delete or restore via these listeners, never by polling), which breaks
	 * the next auto-tracked change on it - e.g. a subsequent rename fails
	 * because `autoCommitRename` stages the old path together with the new one,
	 * and git has nothing at the old path to find.
	 * @return array{success: bool, message: string}
	 */
	public function autoCommitRestore(string $repositoryPath, string $relativeFilePath, int $fileId, string $userId): array {
		if (!is_dir($repositoryPath) || !is_dir($repositoryPath . '/.git')) {
			return ['success' => false, 'message' => 'Repository has not been initialized yet.'];
		}

		$addResult = $this->runGit($repositoryPath, ['add', '--', $relativeFilePath]);
		if (!$addResult['success']) {
			$this->logger->warning(sprintf('git add failed while auto-committing a restore: %s', $addResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to stage restored file: %s', $addResult['output'])];
		}

		$stagedDiffResult = $this->runGit($repositoryPath, ['diff', '--cached', '--quiet']);
		if ($stagedDiffResult['success']) {
			return ['success' => false, 'message' => 'Nothing to auto-commit for the restored file.'];
		}

		$message = sprintf('Auto-commit: restored %s', $relativeFilePath);
		$commitResult = $this->runGit($repositoryPath, ['commit', '-m', $message]);
		if (!$commitResult['success']) {
			$this->logger->info(sprintf('git commit did not succeed while auto-committing a restore: %s', $commitResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to auto-commit restore: %s', $commitResult['output'])];
		}

		$headResult = $this->runGit($repositoryPath, ['rev-parse', 'HEAD']);
		if (!$headResult['success']) {
			$this->logger->warning(sprintf('git rev-parse HEAD failed after auto-committing a restore: %s', $headResult['output']));
		}
		$commitHash = $headResult['success'] ? trim($headResult['output']) : '';

		$parentSnapshot = $this->snapshotMapper->findLatestForFileId($userId, $fileId);
		$parentSnapshotId = $parentSnapshot?->getId();
		// Status 'committed', not 'deleted' - this is what clears the file's prior
		// Deleted dashboard state, the same convention rollbackToSnapshot already
		// uses to clear it when a user explicitly rolls back a deleted file.
		$this->createSnapshotRecord($userId, $relativeFilePath, $commitHash, $message, $parentSnapshotId, 'committed', $fileId);

		$this->logger->info(sprintf('Auto-committed restore of %s', $relativeFilePath));
		return ['success' => true, 'message' => sprintf('Auto-committed restore of %s.', $relativeFilePath)];
	}

	/**
	 * Resolves the local filesystem path backing $userFolder, or false if it isn't on
	 * local storage or the path can't be resolved. Shared by ApiController (which wraps
	 * the false case in an error DataResponse) and the Node-event listeners (which have
	 * no request/response cycle to return one).
	 */
	public function resolveRepositoryPath(Folder $userFolder): string|false {
		$storage = $userFolder->getStorage();
		if (!$storage->isLocal()) {
			return false;
		}

		return $storage->getLocalFile($userFolder->getInternalPath());
	}

	/**
	 * @return array{success: bool, message?: string}
	 */
	private function ensureRepository(string $repositoryPath): array {
		if (is_dir($repositoryPath . '/.git')) {
			return ['success' => true];
		}

		$initResult = $this->runGit($repositoryPath, ['init']);
		if (!$initResult['success']) {
			$this->logger->warning(sprintf('git init failed: %s', $initResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to initialize repository: %s', $initResult['output'])];
		}

		return ['success' => true];
	}

	/**
	 * Runs a git command in $cwd without invoking a shell, avoiding any need for argument escaping.
	 * @param string[] $args
	 * @return array{success: bool, output: string}
	 */
	public function runGit(string $cwd, array $args): array {
		if (!$this->isGitAvailable()) {
			$this->logger->error('Cannot run git command: ' . self::GIT_NOT_INSTALLED_MESSAGE);
			return ['success' => false, 'output' => self::GIT_NOT_INSTALLED_MESSAGE];
		}

		// Git requires user identity (name/email) to be set before creating a commit.
		// If not configured, try to configure it using system git config defaults.
		// Only commands that actually author a commit need this, so read-only
		// commands (status, diff, rev-parse, ...) skip it entirely.
		if ($args !== [] && $args[0] === 'commit') {
			$configResult = $this->runGitConfigGet('user.email', $cwd);
			$configNameResult = $this->runGitConfigGet('user.name', $cwd);

			// If identity is not set locally, check for system-wide config
			if (!$configResult['success'] || trim($configResult['output']) === '') {
				$systemEmailResult = $this->runGitConfigGet('user.email', '/');
				if ($systemEmailResult['success'] && trim($systemEmailResult['output']) !== '') {
					$this->runGitConfigSet('user.email', trim($systemEmailResult['output']), $cwd);
					$configResult = ['success' => true, 'output' => trim($systemEmailResult['output'])];
				}
			}

			if (!$configNameResult['success'] || trim($configNameResult['output']) === '') {
				$systemNameResult = $this->runGitConfigGet('user.name', '/');
				if ($systemNameResult['success'] && trim($systemNameResult['output']) !== '') {
					$this->runGitConfigSet('user.name', trim($systemNameResult['output']), $cwd);
					$configNameResult = ['success' => true, 'output' => trim($systemNameResult['output'])];
				}
			}

			// If no identity is found anywhere, use defaults for Nextcloud's php-fpm user
			if ((!$configResult['success'] || trim($configResult['output']) === '')
				|| (!$configNameResult['success'] || trim($configNameResult['output']) === '')) {
				$defaultEmail = 'www-data@nextcloud.local';
				$defaultName = 'Nextcloud';

				$this->runGitConfigSet('user.email', $defaultEmail, $cwd);
				$this->runGitConfigSet('user.name', $defaultName, $cwd);
			}
		}

		// Suppressed: a failure here is deliberately captured via error_get_last()
		// in describeProcOpenFailure() below rather than left to PHP's own warning.
		error_clear_last();
		$process = @proc_open(
			array_merge(['git'], $args),
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
			$cwd,
		);

		if (!is_resource($process)) {
			return ['success' => false, 'output' => $this->describeProcOpenFailure()];
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		return [
			'success' => $exitCode === 0,
			// Only trailing whitespace is trimmed here: `git status --porcelain`'s
			// leading status-code column can itself be a space (e.g. " M" for
			// "modified, not staged"), which a full trim() would silently eat from
			// the very first line of output.
			'output' => rtrim($stdout . "\n" . $stderr),
		];
	}

	/**
	 * Runs a git config get command directly via proc_open (used during identity setup).
	 */
	public function runGitConfigGet(string $key, string $cwd): array {
		// Suppressed: a failure here is deliberately captured via error_get_last()
		// in describeProcOpenFailure() below rather than left to PHP's own warning.
		error_clear_last();
		$process = @proc_open(
			array_merge(['git', 'config', '--get', $key]),
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
			$cwd,
		);

		if (!is_resource($process)) {
			return ['success' => false, 'output' => $this->describeProcOpenFailure()];
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		return ['success' => $exitCode === 0, 'output' => rtrim($stdout . "\n" . $stderr)];
	}

	/**
	 * Runs a git config set command directly via proc_open (used during identity setup).
	 */
	public function runGitConfigSet(string $key, string $value, string $cwd): array {
		// Suppressed: a failure here is deliberately captured via error_get_last()
		// in describeProcOpenFailure() below rather than left to PHP's own warning.
		error_clear_last();
		$process = @proc_open(
			array_merge(['git', 'config', $key, $value]),
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
			$cwd,
		);

		if (!is_resource($process)) {
			return ['success' => false, 'output' => $this->describeProcOpenFailure()];
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		return ['success' => $exitCode === 0, 'output' => rtrim($stdout . "\n" . $stderr)];
	}

	/**
	 * Computes total size and Git status scoped to a specific set of files within
	 * the repository rooted at $repositoryPath, for the dashboard's Directory Detail view.
	 * @param string[] $relativeFilePaths
	 * @return array{success: bool, message?: string, totalSizeBytes?: int, gitStatus?: string}
	 */
	public function getDirectoryStatus(string $repositoryPath, array $relativeFilePaths): array {
		if (!is_dir($repositoryPath)) {
			$this->logger->warning(sprintf('Repository path does not exist: %s', $repositoryPath));
			return ['success' => false, 'message' => 'Repository path does not exist.'];
		}

		$totalSizeBytes = 0;
		foreach ($relativeFilePaths as $filePath) {
			$absolutePath = $repositoryPath . '/' . $filePath;
			if (is_file($absolutePath)) {
				$totalSizeBytes += filesize($absolutePath);
			}
		}

		$gitStatus = 'Uninitialized';
		if (is_dir($repositoryPath . '/.git')) {
			if (empty($relativeFilePaths)) {
				$gitStatus = 'Clean';
			} else {
				$statusResult = $this->runGit($repositoryPath, array_merge(['status', '--porcelain', '--'], $relativeFilePaths));
				$gitStatus = ($statusResult['success'] && trim($statusResult['output']) === '') ? 'Clean' : 'Modified';
			}
		}

		return [
			'success' => true,
			'totalSizeBytes' => $totalSizeBytes,
			'gitStatus' => $gitStatus,
		];
	}

	/**
	 * Computes each file's Modified/Unchanged status relative to HEAD, for display
	 * per-file in the dashboard's Directory Detail file list.
	 * @param string[] $relativeFilePaths
	 * @return array<string, string> Map of file path to 'Modified' or 'Unchanged'.
	 */
	public function getFileStatuses(string $repositoryPath, array $relativeFilePaths): array {
		$statuses = array_fill_keys($relativeFilePaths, 'Unchanged');

		if (empty($relativeFilePaths) || !is_dir($repositoryPath . '/.git')) {
			return $statuses;
		}

		// -z gives NUL-delimited, unquoted paths; without it git quotes any path
		// containing a space or other special character (e.g. `"folder/a.txt"`),
		// which would never match a plain relative path in $statuses below.
		$statusResult = $this->runGit($repositoryPath, array_merge(['status', '--porcelain', '-z', '--'], $relativeFilePaths));
		if (!$statusResult['success']) {
			return $statuses;
		}

		$entries = explode("\0", $statusResult['output']);
		for ($i = 0, $count = count($entries); $i < $count; $i++) {
			$entry = $entries[$i];
			if ($entry === '') {
				continue;
			}

			$filePath = substr($entry, 3);
			if (isset($statuses[$filePath])) {
				$statuses[$filePath] = 'Modified';
			}

			// A rename/copy ("R"/"C" in the index-status column) is followed by an
			// extra NUL-terminated field holding the old path, which has no "XY "
			// status prefix of its own — skip it so it isn't mis-parsed as a path.
			if ($entry[0] === 'R' || $entry[0] === 'C') {
				$i++;
			}
		}

		return $statuses;
	}

	/**
	 * Restores a single file to the content it had at a previously recorded snapshot,
	 * then commits the restoration and records a new snapshot row for it.
	 * @param string $repositoryPath Absolute local filesystem path to the repository's working tree.
	 * @param string $relativeFilePath Path of the file to roll back, relative to $repositoryPath.
	 * @param int $snapshotId ID of the `gitcloud_snapshots` row to restore the file to.
	 * @param string $userId The UID of the user performing the rollback.
	 * @return array{success: bool, message: string}
	 */
	public function rollbackToSnapshot(string $repositoryPath, string $relativeFilePath, int $snapshotId, string $userId): array {
		if (trim($relativeFilePath) === '') {
			$this->logger->warning('Attempted to roll back with no file selected.');
			return ['success' => false, 'message' => 'No file selected for rollback.'];
		}

		if (!is_dir($repositoryPath)) {
			$this->logger->warning(sprintf('Repository path does not exist: %s', $repositoryPath));
			return ['success' => false, 'message' => 'Repository path does not exist.'];
		}

		if (!is_dir($repositoryPath . '/.git')) {
			return ['success' => false, 'message' => 'Repository has not been initialized yet.'];
		}

		try {
			$snapshot = $this->snapshotMapper->find($snapshotId);
		} catch (DoesNotExistException) {
			return ['success' => false, 'message' => 'Snapshot not found.'];
		}

		if ($snapshot->getUserId() !== $userId || $snapshot->getFilePath() !== $relativeFilePath) {
			return ['success' => false, 'message' => 'Snapshot does not match the requested file.'];
		}

		$commitHash = $snapshot->getCommitHash();
		if (trim($commitHash) === '') {
			return ['success' => false, 'message' => 'Snapshot has no associated commit to restore.'];
		}

		$checkoutResult = $this->runGit($repositoryPath, ['checkout', $commitHash, '--', $relativeFilePath]);
		if (!$checkoutResult['success']) {
			$this->logger->warning(sprintf('git checkout failed during rollback: %s', $checkoutResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to restore file: %s', $checkoutResult['output'])];
		}

		$addResult = $this->runGit($repositoryPath, ['add', '--', $relativeFilePath]);
		if (!$addResult['success']) {
			$this->logger->warning(sprintf('git add failed during rollback: %s', $addResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to stage restored file: %s', $addResult['output'])];
		}

		$stagedDiffResult = $this->runGit($repositoryPath, ['diff', '--cached', '--quiet']);
		if ($stagedDiffResult['success']) {
			return ['success' => false, 'message' => 'File is already at the selected snapshot.'];
		}

		$message = sprintf('Roll back %s to snapshot #%d', $relativeFilePath, $snapshotId);
		$commitResult = $this->runGit($repositoryPath, ['commit', '-m', $message]);
		if (!$commitResult['success']) {
			$this->logger->info(sprintf('git commit did not succeed during rollback: %s', $commitResult['output']));
			return ['success' => false, 'message' => sprintf('Failed to commit restored file: %s', $commitResult['output'])];
		}

		$headResult = $this->runGit($repositoryPath, ['rev-parse', 'HEAD']);
		if (!$headResult['success']) {
			$this->logger->warning(sprintf('git rev-parse HEAD failed after rollback: %s', $headResult['output']));
		}
		$newCommitHash = $headResult['success'] ? trim($headResult['output']) : '';

		$previousSnapshots = $this->getSnapshotsForFile($userId, $relativeFilePath);
		$parentSnapshotId = isset($previousSnapshots[0]) ? $previousSnapshots[0]->getId() : null;
		// Carries the fileid of the snapshot being restored forward onto the new
		// row. This also correctly handles rolling back an already-deleted file
		// (no live Node to source a fileid from), and its non-'deleted' status
		// is what clears a prior 'Deleted' dashboard state, with no special-casing.
		$this->createSnapshotRecord($userId, $relativeFilePath, $newCommitHash, $message, $parentSnapshotId, 'rolled_back', $snapshot->getFileId());

		$this->logger->info(sprintf('Rolled back %s to snapshot #%d', $relativeFilePath, $snapshotId));
		return [
			'success' => true,
			'message' => sprintf('Successfully rolled back %s to the selected snapshot.', $relativeFilePath),
		];
	}

	/**
	 * Records a new snapshot row for a committed, rolled-back, or deleted file state.
	 */
	public function createSnapshotRecord(
		string $userId,
		string $filePath,
		string $commitHash,
		string $message,
		?int $parentSnapshotId,
		string $status,
		?int $fileId = null,
	): Snapshot {
		$snapshot = new Snapshot();
		$snapshot->setUserId($userId);
		$snapshot->setFilePath($filePath);
		$snapshot->setCommitHash($commitHash);
		$snapshot->setMessage($message);
		$snapshot->setParentSnapshotId($parentSnapshotId);
		$snapshot->setStatus($status);
		$snapshot->setFileId($fileId);
		$snapshot->setCreatedAt($this->timeFactory->getTime());

		return $this->snapshotMapper->insert($snapshot);
	}

	/**
	 * @return Snapshot[]
	 */
	public function getSnapshotsForFile(string $userId, string $filePath): array {
		return $this->snapshotMapper->findAllForFile($userId, $filePath);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function updateSnapshotStatus(int $snapshotId, string $status): Snapshot {
		$snapshot = $this->snapshotMapper->find($snapshotId);
		$snapshot->setStatus($status);

		return $this->snapshotMapper->update($snapshot);
	}

	/**
	 * Stops GitCloud from tracking a single file: deletes every snapshot row
	 * recorded for it, chained by file_id when available so a renamed file's full
	 * history is cleared regardless of which path each row was recorded under
	 * (falls back to an exact path match for legacy rows predating the file_id
	 * migration). The file itself, and any Git history already committed for it,
	 * is left untouched on disk - this only stops GitCloud's own dashboard and
	 * auto-tracking listeners (which gate on SnapshotMapper history existing via
	 * findLatestForFileId) from reacting to it further.
	 * @return array{success: bool, message: string}
	 */
	public function untrackFile(string $userId, string $relativeFilePath): array {
		$relativeFilePath = ltrim($relativeFilePath, '/');

		$snapshots = $this->snapshotMapper->findAllForFile($userId, $relativeFilePath);
		if (empty($snapshots)) {
			return ['success' => false, 'message' => sprintf('%s is not tracked by GitCloud.', $relativeFilePath)];
		}

		$fileId = $snapshots[0]->getFileId();
		if ($fileId !== null) {
			$this->snapshotMapper->deleteAllForFileId($userId, $fileId);
		} else {
			$this->snapshotMapper->deleteAllForFile($userId, $relativeFilePath);
		}

		$this->logger->info(sprintf('Stopped tracking %s in GitCloud.', $relativeFilePath));
		return ['success' => true, 'message' => sprintf('Stopped tracking %s.', $relativeFilePath)];
	}

	/**
	 * Stops GitCloud from tracking every currently-tracked file under a directory,
	 * including nested subdirectories (mirroring how a folder delete already
	 * cascades to its tracked descendants - see GitTrackedNodeDeletedListener), by
	 * calling untrackFile() for each. The repository root ("/") is deliberately
	 * not treated the same recursive way - only files directly grouped under it
	 * are affected - so a single click can't untrack the user's entire GitCloud
	 * history at once; that's what the Personal Settings "delete all history"
	 * action is already for.
	 * @return array{success: bool, message: string}
	 */
	public function untrackDirectory(string $userId, string $directoryPath): array {
		$isRoot = $directoryPath === '/';
		$prefix = $isRoot ? '' : rtrim($directoryPath, '/') . '/';

		$filesToUntrack = [];
		foreach ($this->getCommittedDirectories($userId) as $directory) {
			if ($isRoot) {
				if ($directory['path'] === '/') {
					$filesToUntrack = $directory['files'];
				}
				continue;
			}

			foreach ($directory['files'] as $filePath) {
				if (str_starts_with($filePath, $prefix)) {
					$filesToUntrack[] = $filePath;
				}
			}
		}

		if (empty($filesToUntrack)) {
			return ['success' => false, 'message' => sprintf('%s is not tracked by GitCloud.', $directoryPath)];
		}

		foreach ($filesToUntrack as $filePath) {
			$this->untrackFile($userId, $filePath);
		}

		$this->logger->info(sprintf('Stopped tracking %d file(s) under %s in GitCloud.', count($filesToUntrack), $directoryPath));
		return ['success' => true, 'message' => sprintf('Stopped tracking %d file(s) in %s.', count($filesToUntrack), $directoryPath)];
	}

	/**
	 * Groups the user's ever-committed files by directory, for the dashboard's
	 * committed-directories list. A file with no directory component (i.e. at
	 * the repository root) is grouped under "/".
	 * @return list<array{path: string, files: list<string>}>
	 */
	public function getCommittedDirectories(string $userId): array {
		$filesByDirectory = [];
		foreach ($this->snapshotMapper->findAllForUser($userId) as $snapshot) {
			$filePath = ltrim($snapshot->getFilePath(), '/');
			$filesByDirectory[$this->directoryOf($filePath)][$filePath] = true;
		}

		ksort($filesByDirectory);

		$directories = [];
		foreach ($filesByDirectory as $directory => $files) {
			$fileList = array_keys($files);
			sort($fileList);
			$directories[] = ['path' => $directory, 'files' => $fileList];
		}

		return $directories;
	}

	/**
	 * Returns the directory portion of a repository-relative file path, or "/"
	 * if the file is at the repository root.
	 */
	private function directoryOf(string $filePath): string {
		$normalized = ltrim($filePath, '/');
		$slashPos = strrpos($normalized, '/');

		return $slashPos === false ? '/' : substr($normalized, 0, $slashPos);
	}

	/**
	 * Irreversibly wipes all Git history for the repository rooted at $repositoryPath
	 * by deleting .git and reinitializing an empty repository. Working-tree files are
	 * left untouched, since $repositoryPath is the user's own Nextcloud home directory
	 * and .git holds only history, not the live file content. Also removes every
	 * gitcloud_snapshots row for $userId, since their commit hashes become invalid
	 * once history is wiped.
	 *
	 * There is no locking around this (the codebase has none anywhere today), so a
	 * commit/rollback racing with a history wipe is a known, unhandled edge case.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function deleteHistory(string $repositoryPath, string $userId): array {
		if (!is_dir($repositoryPath)) {
			$this->logger->warning(sprintf('Repository path does not exist: %s', $repositoryPath));
			return ['success' => false, 'message' => 'Repository path does not exist.'];
		}

		if (is_dir($repositoryPath . '/.git')) {
			$this->removeDirectoryRecursive($repositoryPath . '/.git');
		}

		$initResult = $this->ensureRepository($repositoryPath);
		if (!$initResult['success']) {
			return ['success' => false, 'message' => $initResult['message'] ?? 'Failed to reinitialize repository.'];
		}

		$this->snapshotMapper->deleteAllForUser($userId);

		$this->logger->info(sprintf('Deleted all Git history for user %s', $userId));
		return ['success' => true, 'message' => 'All commit history has been permanently deleted.'];
	}

	/**
	 * Recursively deletes a directory and its contents.
	 */
	private function removeDirectoryRecursive(string $path): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		foreach ($iterator as $entry) {
			if ($entry->isDir()) {
				rmdir($entry->getPathname());
			} else {
				unlink($entry->getPathname());
			}
		}

		rmdir($path);
	}

	// Future methods: getCommitHistory(path), listSnapshots() etc.
}
