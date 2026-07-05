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
			'output' => trim($stdout . "\n" . $stderr),
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
	 * Simulates rolling back selected files to a historical snapshot.
	 * @param string[] $filePaths List of file paths that need rollback.
	 * @param string|null $snapshotReference A reference point (e.g., commit hash or timestamp).
	 * @return array{success: bool, message: string}
	 */
	public function rollbackToSnapshot(array $filePaths, ?string $snapshotReference): array {
		if (empty($filePaths)) {
			$this->logger->warning('Attempted to roll back with no files selected.');
			return ['success' => false, 'message' => 'No files selected for rollback.'];
		}

		// --- REAL IMPLEMENTATION NOTES ---
		// 1. Validate $snapshotReference exists in the target repo.
		// 2. For each file: Run command similar to: git checkout {$snapshotReference}-- {local_file_path}
		// 3. Handle potential conflicts or missing state checks.

		if (!$snapshotReference) {
			return ['success' => false, 'message' => 'Snapshot reference is required for rollback.'];
		}

		$this->logger->info(sprintf('Simulating rollback of %d files to snapshot: %s', count($filePaths), $snapshotReference));
		return [
			'success' => true,
			'message' => 'Successfully rolled back selected files to the specified snapshot.',
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

	// Future methods: getCommitHistory(path), listSnapshots() etc.
}
