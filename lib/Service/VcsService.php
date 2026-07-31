<?php

declare(strict_types=1);

namespace OCA\GitCloud\Service;

use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Db\SnapshotMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
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

	public function __construct(LoggerInterface $logger, SnapshotMapper $snapshotMapper, ITimeFactory $timeFactory) {
		$this->logger = $logger;
		$this->snapshotMapper = $snapshotMapper;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Stages the given files and commits them in the Git repository rooted at $repositoryPath.
	 * Initializes the repository if it does not already exist.
	 * @param string $repositoryPath Absolute local filesystem path to the repository's working tree.
	 * @param string[] $relativeFilePaths List of file paths, relative to $repositoryPath, to stage.
	 * @param string $message The commit message provided by the user.
	 * @param string $userId The UID of the user performing the commit, used to record snapshot rows.
	 * @return array{success: bool, message: string}
	 */
	public function commitChanges(string $repositoryPath, array $relativeFilePaths, string $message, string $userId): array {
		if (empty($relativeFilePaths)) {
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

		foreach ($relativeFilePaths as $filePath) {
			$previousSnapshots = $this->getSnapshotsForFile($userId, $filePath);
			$parentSnapshotId = isset($previousSnapshots[0]) ? $previousSnapshots[0]->getId() : null;
			$this->createSnapshotRecord($userId, $filePath, $commitHash, $message, $parentSnapshotId, 'committed');
		}

		$this->logger->info(sprintf('Committed %d file(s) with message: "%s"', count($relativeFilePaths), $message));
		return [
			'success' => true,
			'message' => 'Successfully staged and committed changes.',
		];
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
	private function runGit(string $cwd, array $args): array {
		$process = proc_open(
			array_merge(['git'], $args),
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
			$cwd,
		);

		if (!is_resource($process)) {
			return ['success' => false, 'output' => 'Unable to start the git process.'];
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
	 * Computes live stats (file/dir counts, total size, and Git status) for the
	 * repository rooted at $repositoryPath, for display on the dashboard.
	 * @return array{success: bool, message?: string, fileCount?: int, dirCount?: int, totalSizeBytes?: int, gitStatus?: string}
	 */
	public function getRepositoryStatus(string $repositoryPath): array {
		if (!is_dir($repositoryPath)) {
			$this->logger->warning(sprintf('Repository path does not exist: %s', $repositoryPath));
			return ['success' => false, 'message' => 'Repository path does not exist.'];
		}

		$fileCount = 0;
		$dirCount = 0;
		$totalSizeBytes = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($repositoryPath, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);

		foreach ($iterator as $entry) {
			if (str_contains($entry->getPathname(), DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)
				|| str_ends_with($entry->getPathname(), DIRECTORY_SEPARATOR . '.git')) {
				continue;
			}

			if ($entry->isDir()) {
				$dirCount++;
			} else {
				$fileCount++;
				$totalSizeBytes += $entry->getSize();
			}
		}

		$gitStatus = 'Uninitialized';
		if (is_dir($repositoryPath . '/.git')) {
			$statusResult = $this->runGit($repositoryPath, ['status', '--porcelain']);
			$gitStatus = ($statusResult['success'] && trim($statusResult['output']) === '') ? 'Clean' : 'Modified';
		}

		return [
			'success' => true,
			'fileCount' => $fileCount,
			'dirCount' => $dirCount,
			'totalSizeBytes' => $totalSizeBytes,
			'gitStatus' => $gitStatus,
		];
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

		foreach (explode("\0", $statusResult['output']) as $entry) {
			if ($entry === '') {
				continue;
			}

			$filePath = substr($entry, 3);
			if (isset($statuses[$filePath])) {
				$statuses[$filePath] = 'Modified';
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
		$this->createSnapshotRecord($userId, $relativeFilePath, $newCommitHash, $message, $parentSnapshotId, 'rolled_back');

		$this->logger->info(sprintf('Rolled back %s to snapshot #%d', $relativeFilePath, $snapshotId));
		return [
			'success' => true,
			'message' => sprintf('Successfully rolled back %s to the selected snapshot.', $relativeFilePath),
		];
	}

	/**
	 * Records a new snapshot row for a committed or rolled-back file state.
	 */
	public function createSnapshotRecord(
		string $userId,
		string $filePath,
		string $commitHash,
		string $message,
		?int $parentSnapshotId,
		string $status,
	): Snapshot {
		$snapshot = new Snapshot();
		$snapshot->setUserId($userId);
		$snapshot->setFilePath($filePath);
		$snapshot->setCommitHash($commitHash);
		$snapshot->setMessage($message);
		$snapshot->setParentSnapshotId($parentSnapshotId);
		$snapshot->setStatus($status);
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

	// Future methods: getCommitHistory(path), listSnapshots() etc.
}
