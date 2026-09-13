<?php

declare(strict_types=1);

namespace Service;

use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Service\VcsService;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VcsServiceTest extends TestCase {
	private ?string $tmpRepoPath = null;
	private ?string $tmpHomePath = null;
	private ?string $tmpAppPath = null;

	protected function tearDown(): void {
		if ($this->tmpHomePath !== null && is_dir($this->tmpHomePath)) {
			exec('rm -rf ' . escapeshellarg($this->tmpHomePath));
		}
		$this->tmpHomePath = null;
		$this->tmpRepoPath = null;

		if ($this->tmpAppPath !== null && is_dir($this->tmpAppPath)) {
			exec('rm -rf ' . escapeshellarg($this->tmpAppPath));
		}
		$this->tmpAppPath = null;
	}

	/**
	 * Creates a temporary stand-in for a user's home directory laid out the way
	 * Nextcloud's really is - `<home>/files` as the working tree, with room for
	 * VcsService's own `<home>/gitcloud` repository directory beside it - and returns
	 * the working tree path. tearDown() removes the whole home directory, so the
	 * repository is cleaned up along with it.
	 */
	private function createWorkingTree(): string {
		$this->tmpHomePath = sys_get_temp_dir() . '/gitcloud-test-' . uniqid();
		mkdir($this->tmpHomePath);
		$workingTree = $this->tmpHomePath . '/files';
		mkdir($workingTree);

		return $workingTree;
	}

	/**
	 * Shell prefix for running raw git commands against the test repository the same way
	 * VcsService does: the repository directory sits beside the working tree rather than
	 * inside it, so both `--git-dir` and `--work-tree` have to be spelled out.
	 */
	private function gitCommand(): string {
		return 'git --git-dir=' . escapeshellarg($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME)
			. ' --work-tree=' . escapeshellarg($this->tmpRepoPath)
			. ' -C ' . escapeshellarg($this->tmpRepoPath);
	}

	/**
	 * Initializing the repository deliberately passes only `--git-dir`: adding
	 * `--work-tree` to `git init` produces an incomplete repository, the same constraint
	 * VcsService::ensureRepository() documents.
	 */
	private function gitInitCommand(): string {
		return 'git --git-dir=' . escapeshellarg($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME) . ' init -q';
	}

	/**
	 * Maps the real architecture PHPUnit is currently running under to the same
	 * bin/<arch>/ directory name VcsService::findBundledGitBinary() would look for,
	 * so these tests work on whatever architecture actually runs them (amd64 or
	 * arm64 CI runners alike) instead of hardcoding one.
	 */
	private function currentBundledArchDir(): string {
		return match (php_uname('m')) {
			'x86_64', 'amd64' => 'amd64',
			'aarch64', 'arm64' => 'arm64',
			default => throw new \RuntimeException('Unsupported test architecture: ' . php_uname('m')),
		};
	}

	/**
	 * Creates a fake "app install directory" containing bin/<arch>/git as an
	 * executable shell script that unconditionally prints $marker and exits 0,
	 * regardless of the git subcommand/args it's invoked with. Used to prove
	 * VcsService actually invoked this bundled binary rather than real system git,
	 * without needing a real static git binary in the test environment.
	 */
	private function createFakeBundledGitBinary(string $marker, bool $executable = true): string {
		$this->tmpAppPath = sys_get_temp_dir() . '/gitcloud-test-app-' . uniqid();
		$binDir = $this->tmpAppPath . '/bin/' . $this->currentBundledArchDir();
		mkdir($binDir, 0755, true);

		$binaryPath = $binDir . '/git';
		file_put_contents($binaryPath, "#!/bin/sh\necho '{$marker}'\nexit 0\n");
		chmod($binaryPath, $executable ? 0755 : 0644);

		return $this->tmpAppPath;
	}

	public function testCommitChangesRecordsSnapshotWithHeadCommitHashAndNoParent(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn(null);
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Snapshot $snapshot): bool {
				return $snapshot->getUserId() === 'testuser'
					&& $snapshot->getFilePath() === 'file1.txt'
					&& $snapshot->getMessage() === 'Initial commit'
					&& $snapshot->getParentSnapshotId() === null
					&& $snapshot->getStatus() === 'committed'
					&& $snapshot->getFileId() === 42
					&& preg_match('/^[0-9a-f]{40}$/', $snapshot->getCommitHash()) === 1;
			}))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->commitChanges($this->tmpRepoPath, [['path' => 'file1.txt', 'fileId' => 42]], 'Initial commit', 'testuser');

		$this->assertTrue($result['success']);
	}

	public function testCommitChangesUsesMostRecentSnapshotForSameFileIdAsParent(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$previousSnapshot = new Snapshot();
		$previousSnapshot->setId(7);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($previousSnapshot);
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Snapshot $snapshot): bool => $snapshot->getParentSnapshotId() === 7))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->commitChanges($this->tmpRepoPath, [['path' => 'file1.txt', 'fileId' => 42]], 'Second commit', 'testuser');

		$this->assertTrue($result['success']);
	}

	public function testCommitChangesStartsFreshChainWhenFileIdDiffersFromPriorPathHistory(): void {
		// A file deleted and later recreated at the same path gets a new fileid
		// from Nextcloud's filecache; the new commit must not be misattributed
		// as a continuation of the old file's history just because the path matches.
		$this->tmpRepoPath = $this->createWorkingTree();
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello again');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		// The old file's history lives under fileId 42; the recreated file has fileId 99.
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 99)->willReturn(null);
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Snapshot $snapshot): bool => $snapshot->getParentSnapshotId() === null && $snapshot->getFileId() === 99))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->commitChanges($this->tmpRepoPath, [['path' => 'file1.txt', 'fileId' => 99]], 'Recreated file', 'testuser');

		$this->assertTrue($result['success']);
	}

	public function testCreateSnapshotRecordPopulatesAndInsertsSnapshot(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Snapshot $snapshot): bool {
				return $snapshot->getUserId() === 'testuser'
					&& $snapshot->getFilePath() === 'file1.txt'
					&& $snapshot->getCommitHash() === 'abc123'
					&& $snapshot->getMessage() === 'Initial commit'
					&& $snapshot->getParentSnapshotId() === null
					&& $snapshot->getStatus() === 'committed'
					&& $snapshot->getCreatedAt() === 1720000000;
			}))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->createSnapshotRecord('testuser', 'file1.txt', 'abc123', 'Initial commit', null, 'committed');

		$this->assertSame('testuser', $result->getUserId());
		$this->assertNull($result->getFileId());
	}

	public function testCreateSnapshotRecordStoresGivenFileId(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Snapshot $snapshot): bool => $snapshot->getFileId() === 42))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->createSnapshotRecord('testuser', 'file1.txt', 'abc123', 'Initial commit', null, 'committed', 42);

		$this->assertSame(42, $result->getFileId());
	}

	public function testRollbackToSnapshotRestoresFileAndRecordsSnapshot(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		file_put_contents($this->tmpRepoPath . '/file1.txt', 'original');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');
		exec($this->gitCommand() . ' rev-parse HEAD', $headOutput);
		$originalCommitHash = trim($headOutput[0]);

		file_put_contents($this->tmpRepoPath . '/file1.txt', 'changed');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Second commit"');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$targetSnapshot = new Snapshot();
		$targetSnapshot->setId(1);
		$targetSnapshot->setUserId('testuser');
		$targetSnapshot->setFilePath('file1.txt');
		$targetSnapshot->setCommitHash($originalCommitHash);
		$targetSnapshot->setFileId(55);

		$mostRecentSnapshot = new Snapshot();
		$mostRecentSnapshot->setId(2);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('find')->with(1)->willReturn($targetSnapshot);
		// Chained by file id, like every other snapshot writer in this class, so the
		// new row hangs off the file's real latest snapshot even if that was recorded
		// under a different path after a rename.
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 55)->willReturn($mostRecentSnapshot);
		$snapshotMapper->expects($this->never())->method('findAllForFile');
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Snapshot $snapshot): bool {
				return $snapshot->getUserId() === 'testuser'
					&& $snapshot->getFilePath() === 'file1.txt'
					&& $snapshot->getParentSnapshotId() === 2
					&& $snapshot->getStatus() === 'rolled_back'
					// Carried forward from the target snapshot being restored to,
					// so the new row still links to the same fileid identity - this
					// also correctly handles rolling back an already-deleted file.
					&& $snapshot->getFileId() === 55;
			}))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->rollbackToSnapshot($this->tmpRepoPath, 'file1.txt', 1, 'testuser');

		$this->assertTrue($result['success']);
		$this->assertSame('original', file_get_contents($this->tmpRepoPath . '/file1.txt'));
	}

	public function testRollbackToSnapshotChainsByPathForLegacySnapshotWithoutFileId(): void {
		// A snapshot recorded before the file_id migration has nothing to chain by, so
		// the parent lookup falls back to an exact path match rather than dropping the
		// chain entirely.
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		file_put_contents($this->tmpRepoPath . '/file1.txt', 'original');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');
		exec($this->gitCommand() . ' rev-parse HEAD', $headOutput);
		$originalCommitHash = trim($headOutput[0]);

		file_put_contents($this->tmpRepoPath . '/file1.txt', 'changed');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Second commit"');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$targetSnapshot = new Snapshot();
		$targetSnapshot->setId(1);
		$targetSnapshot->setUserId('testuser');
		$targetSnapshot->setFilePath('file1.txt');
		$targetSnapshot->setCommitHash($originalCommitHash);
		$targetSnapshot->setFileId(null);

		$mostRecentSnapshot = new Snapshot();
		$mostRecentSnapshot->setId(7);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('find')->with(1)->willReturn($targetSnapshot);
		$snapshotMapper->method('findAllForFile')->with('testuser', 'file1.txt')->willReturn([$mostRecentSnapshot]);
		$snapshotMapper->expects($this->never())->method('findLatestForFileId');
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(fn (Snapshot $snapshot): bool => $snapshot->getParentSnapshotId() === 7 && $snapshot->getFileId() === null))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->rollbackToSnapshot($this->tmpRepoPath, 'file1.txt', 1, 'testuser');

		$this->assertTrue($result['success']);
	}

	public function testRollbackToSnapshotFailsWhenSnapshotBelongsToDifferentUser(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$snapshot = new Snapshot();
		$snapshot->setId(1);
		$snapshot->setUserId('otheruser');
		$snapshot->setFilePath('file1.txt');
		$snapshot->setCommitHash('abc123');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('find')->with(1)->willReturn($snapshot);
		$snapshotMapper->expects($this->never())->method('insert');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->rollbackToSnapshot($this->tmpRepoPath, 'file1.txt', 1, 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testRollbackToSnapshotFailsWhenSnapshotNotFound(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('find')->willThrowException(new DoesNotExistException('not found'));

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->rollbackToSnapshot($this->tmpRepoPath, 'file1.txt', 99, 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testGetDirectoryStatusSumsSizeOfGivenFilesOnly(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		mkdir($this->tmpRepoPath . '/folder');
		file_put_contents($this->tmpRepoPath . '/folder/a.txt', str_repeat('a', 10));
		file_put_contents($this->tmpRepoPath . '/folder/b.txt', str_repeat('b', 20));
		file_put_contents($this->tmpRepoPath . '/other.txt', str_repeat('c', 1000));

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->getDirectoryStatus($this->tmpRepoPath, ['folder/a.txt', 'folder/b.txt']);

		$this->assertTrue($result['success']);
		$this->assertSame(30, $result['totalSizeBytes']);
	}

	public function testGetDirectoryStatusReturnsUninitializedWhenNoGitRepository(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		file_put_contents($this->tmpRepoPath . '/a.txt', 'hello');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->getDirectoryStatus($this->tmpRepoPath, ['a.txt']);

		$this->assertTrue($result['success']);
		$this->assertSame('Uninitialized', $result['gitStatus']);
	}

	public function testGetDirectoryStatusReportsCleanWhenOnlyFilesOutsideDirectoryAreModified(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		mkdir($this->tmpRepoPath . '/folder');
		file_put_contents($this->tmpRepoPath . '/folder/a.txt', 'original');
		file_put_contents($this->tmpRepoPath . '/other.txt', 'original');
		exec($this->gitCommand() . ' add .');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		// Modify a file outside the scoped directory only.
		file_put_contents($this->tmpRepoPath . '/other.txt', 'changed');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->getDirectoryStatus($this->tmpRepoPath, ['folder/a.txt']);

		$this->assertTrue($result['success']);
		$this->assertSame('Clean', $result['gitStatus']);
	}

	public function testGetDirectoryStatusReportsModifiedWhenScopedFileIsChanged(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		mkdir($this->tmpRepoPath . '/folder');
		file_put_contents($this->tmpRepoPath . '/folder/a.txt', 'original');
		exec($this->gitCommand() . ' add .');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		file_put_contents($this->tmpRepoPath . '/folder/a.txt', 'changed');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->getDirectoryStatus($this->tmpRepoPath, ['folder/a.txt']);

		$this->assertTrue($result['success']);
		$this->assertSame('Modified', $result['gitStatus']);
	}

	public function testGetFileStatusesMarksOnlyChangedFilesAsModified(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		mkdir($this->tmpRepoPath . '/folder');
		file_put_contents($this->tmpRepoPath . '/folder/a.txt', 'original');
		file_put_contents($this->tmpRepoPath . '/folder/b.txt', 'original');
		exec($this->gitCommand() . ' add .');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		file_put_contents($this->tmpRepoPath . '/folder/a.txt', 'changed');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$statuses = $service->getFileStatuses($this->tmpRepoPath, ['folder/a.txt', 'folder/b.txt']);

		$this->assertSame([
			'folder/a.txt' => 'Modified',
			'folder/b.txt' => 'Unchanged',
		], $statuses);
	}

	public function testGetFileStatusesHandlesFilePathsContainingSpaces(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		mkdir($this->tmpRepoPath . '/Test Folder');
		file_put_contents($this->tmpRepoPath . '/Test Folder/status test.txt', 'original');
		file_put_contents($this->tmpRepoPath . '/Test Folder/unchanged.txt', 'original');
		exec($this->gitCommand() . ' add .');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		file_put_contents($this->tmpRepoPath . '/Test Folder/status test.txt', 'changed');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		// Without -z, git quotes paths containing spaces in --porcelain output
		// (e.g. `"Test Folder/status test.txt"`), which would never match a plain
		// path here and silently leave every file reported as 'Unchanged'. The
		// modified file is listed first so this also covers runGit() not eating
		// the leading space of git's own status code column (e.g. " M").
		$statuses = $service->getFileStatuses($this->tmpRepoPath, ['Test Folder/status test.txt', 'Test Folder/unchanged.txt']);

		$this->assertSame([
			'Test Folder/status test.txt' => 'Modified',
			'Test Folder/unchanged.txt' => 'Unchanged',
		], $statuses);
	}

	public function testGetFileStatusesHandlesRenamedFileWithoutCorruptingOtherEntries(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		file_put_contents($this->tmpRepoPath . '/a.txt', 'original');
		file_put_contents($this->tmpRepoPath . '/b.txt', 'original');
		exec($this->gitCommand() . ' add .');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		// `git mv` stages the rename immediately, so `git status --porcelain -z`
		// emits "R  renamed.txt\0a.txt\0" — the extra NUL field for the old path
		// has no "XY " status prefix and must not be parsed as its own entry.
		exec($this->gitCommand() . ' mv a.txt renamed.txt');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$statuses = $service->getFileStatuses($this->tmpRepoPath, ['renamed.txt', 'b.txt']);

		$this->assertSame([
			'renamed.txt' => 'Modified',
			'b.txt' => 'Unchanged',
		], $statuses);
	}

	public function testGetFileStatusesReturnsUnchangedWhenNoGitRepository(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		file_put_contents($this->tmpRepoPath . '/a.txt', 'hello');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$statuses = $service->getFileStatuses($this->tmpRepoPath, ['a.txt']);

		$this->assertSame(['a.txt' => 'Unchanged'], $statuses);
	}

	public function testGetCommittedDirectoriesGroupsFilesByDirectorySortedWithRootLast(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$rootFile = new Snapshot();
		$rootFile->setFilePath('readme.txt');

		$folderFileA = new Snapshot();
		$folderFileA->setFilePath('folder/b.txt');

		$folderFileB = new Snapshot();
		$folderFileB->setFilePath('folder/a.txt');

		$nestedFile = new Snapshot();
		$nestedFile->setFilePath('/folder/sub/c.txt');

		// A second snapshot for the same file (e.g. a later commit) must not
		// produce a duplicate entry in the file list.
		$folderFileADuplicate = new Snapshot();
		$folderFileADuplicate->setFilePath('folder/b.txt');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForUser')->with('testuser')->willReturn([
			$rootFile,
			$folderFileA,
			$folderFileB,
			$nestedFile,
			$folderFileADuplicate,
		]);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$directories = $service->getCommittedDirectories('testuser');

		$this->assertSame([
			['path' => '/', 'files' => ['readme.txt']],
			['path' => 'folder', 'files' => ['folder/a.txt', 'folder/b.txt']],
			['path' => 'folder/sub', 'files' => ['folder/sub/c.txt']],
		], $directories);
	}

	public function testGetCommittedDirectoriesReturnsEmptyArrayForUserWithNoSnapshots(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForUser')->with('testuser')->willReturn([]);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$this->assertSame([], $service->getCommittedDirectories('testuser'));
	}

	public function testGetLatestStatusByFilePathKeepsOnlyTheNewestStatusPerPath(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		// findAllForUser is ordered newest-first, so the first row seen for a path wins.
		$newest = new Snapshot();
		$newest->setFilePath('folder/a.txt');
		$newest->setStatus('deleted');

		$older = new Snapshot();
		$older->setFilePath('folder/a.txt');
		$older->setStatus('committed');

		$otherFile = new Snapshot();
		$otherFile->setFilePath('/folder/b.txt');
		$otherFile->setStatus('rolled_back');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForUser')->with('testuser')->willReturn([$newest, $older, $otherFile]);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$this->assertSame([
			// Keyed without a leading slash, matching every other path in this class.
			'folder/a.txt' => 'deleted',
			'folder/b.txt' => 'rolled_back',
		], $service->getLatestStatusByFilePath('testuser'));
	}

	public function testSnapshotRowsAreFetchedOncePerRequestAcrossReaders(): void {
		// A dashboard load reads the same rows from two angles; VcsService is built
		// fresh per request, so that must be one query rather than one per reader.
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$snapshot = new Snapshot();
		$snapshot->setFilePath('folder/a.txt');
		$snapshot->setStatus('committed');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->once())
			->method('findAllForUser')
			->with('testuser')
			->willReturn([$snapshot]);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$service->getCommittedDirectories('testuser');
		$service->getLatestStatusByFilePath('testuser');
		$service->getCommittedDirectories('testuser');

		$this->addToAssertionCount(1);
	}

	public function testSnapshotRowsAreRefetchedAfterANewSnapshotIsRecorded(): void {
		// The per-request memo must not survive a write, or a caller that commits and
		// then re-reads in the same request would see stale rows.
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->exactly(2))
			->method('findAllForUser')
			->with('testuser')
			->willReturn([]);
		$snapshotMapper->method('insert')->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$service->getCommittedDirectories('testuser');
		$service->createSnapshotRecord('testuser', 'folder/a.txt', 'abc123', 'msg', null, 'committed', 7);
		$service->getCommittedDirectories('testuser');

		$this->addToAssertionCount(1);
	}

	public function testUntrackFileDeletesByFileIdWhenAvailable(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$snapshot = new Snapshot();
		$snapshot->setFilePath('folder/a.txt');
		$snapshot->setFileId(42);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForFile')->with('testuser', 'folder/a.txt')->willReturn([$snapshot]);
		$snapshotMapper->expects($this->once())->method('deleteAllForFileId')->with('testuser', 42);
		$snapshotMapper->expects($this->never())->method('deleteAllForFile');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->untrackFile('testuser', 'folder/a.txt');

		$this->assertTrue($result['success']);
	}

	public function testUntrackFileFallsBackToPathMatchWhenFileIdMissing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$snapshot = new Snapshot();
		$snapshot->setFilePath('legacy.txt');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForFile')->with('testuser', 'legacy.txt')->willReturn([$snapshot]);
		$snapshotMapper->expects($this->once())->method('deleteAllForFile')->with('testuser', 'legacy.txt');
		$snapshotMapper->expects($this->never())->method('deleteAllForFileId');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->untrackFile('testuser', 'legacy.txt');

		$this->assertTrue($result['success']);
	}

	public function testUntrackFileFailsWhenFileHasNoGitCloudHistory(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForFile')->willReturn([]);
		$snapshotMapper->expects($this->never())->method('deleteAllForFileId');
		$snapshotMapper->expects($this->never())->method('deleteAllForFile');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->untrackFile('testuser', 'untracked.txt');

		$this->assertFalse($result['success']);
	}

	public function testUntrackDirectoryUntracksFilesUnderPrefixIncludingSubdirectories(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$docsFile = new Snapshot();
		$docsFile->setFilePath('docs/readme.md');
		$docsFile->setFileId(1);

		$nestedFile = new Snapshot();
		$nestedFile->setFilePath('docs/sub/notes.txt');
		$nestedFile->setFileId(2);

		$otherFile = new Snapshot();
		$otherFile->setFilePath('other/file.txt');
		$otherFile->setFileId(3);

		$rootFile = new Snapshot();
		$rootFile->setFilePath('root.txt');
		$rootFile->setFileId(4);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForUser')->with('testuser')
			->willReturn([$docsFile, $nestedFile, $otherFile, $rootFile]);
		$snapshotMapper->method('findAllForFile')->willReturnMap([
			['testuser', 'docs/readme.md', [$docsFile]],
			['testuser', 'docs/sub/notes.txt', [$nestedFile]],
		]);
		$snapshotMapper->expects($this->exactly(2))->method('deleteAllForFileId')
			->with('testuser', $this->logicalOr(1, 2));

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->untrackDirectory('testuser', 'docs');

		$this->assertTrue($result['success']);
	}

	public function testUntrackDirectoryAtRootOnlyAffectsTopLevelFiles(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$docsFile = new Snapshot();
		$docsFile->setFilePath('docs/readme.md');
		$docsFile->setFileId(1);

		$rootFile = new Snapshot();
		$rootFile->setFilePath('root.txt');
		$rootFile->setFileId(4);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForUser')->with('testuser')->willReturn([$docsFile, $rootFile]);
		$snapshotMapper->method('findAllForFile')->with('testuser', 'root.txt')->willReturn([$rootFile]);
		$snapshotMapper->expects($this->once())->method('deleteAllForFileId')->with('testuser', 4);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->untrackDirectory('testuser', '/');

		$this->assertTrue($result['success']);
	}

	public function testUntrackDirectoryFailsWhenDirectoryHasNoTrackedFiles(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForUser')->with('testuser')->willReturn([]);
		$snapshotMapper->expects($this->never())->method('deleteAllForFileId');
		$snapshotMapper->expects($this->never())->method('deleteAllForFile');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->untrackDirectory('testuser', 'nonexistent');

		$this->assertFalse($result['success']);
	}

	public function testDeleteHistoryRemovesGitDirectoryAndReinitializes(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->once())->method('deleteAllForUser')->with('testuser');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->deleteHistory($this->tmpRepoPath, 'testuser');

		$this->assertTrue($result['success']);
		$this->assertDirectoryExists($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME);
		$this->assertDirectoryDoesNotExist($this->tmpRepoPath . '/.git');

		exec($this->gitCommand() . ' rev-parse HEAD 2>&1', $output, $exitCode);
		$this->assertNotSame(0, $exitCode);
	}

	public function testDeleteHistoryLeavesWorkingTreeFilesIntact(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);
		$service->deleteHistory($this->tmpRepoPath, 'testuser');

		$this->assertSame('hello', file_get_contents($this->tmpRepoPath . '/file1.txt'));
	}

	public function testDeleteHistoryFailsWhenRepositoryPathMissing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('deleteAllForUser');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->deleteHistory('/nonexistent/path/' . uniqid(), 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testCreateHistoryBackupProducesExtractableArchiveContainingGitDirectory(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->createHistoryBackup($this->tmpRepoPath, 'testuser');

		$this->assertTrue($result['success']);
		$this->assertFileExists($result['path']);

		exec('tar -tzf ' . escapeshellarg($result['path']), $entries, $exitCode);
		$this->assertSame(0, $exitCode);
		$this->assertContains(VcsService::GIT_DIRECTORY_NAME . '/', $entries);

		unlink($result['path']);
	}

	public function testCreateHistoryBackupFailsWhenRepositoryPathMissing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->createHistoryBackup('/nonexistent/path/' . uniqid(), 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testCreateHistoryBackupFailsWhenNoHistoryExistsYet(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->createHistoryBackup($this->tmpRepoPath, 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testAutoCommitDeleteStagesRemovalAndRecordsDeletedSnapshot(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		// The file is already gone from the working tree by the time GitCloud
		// reacts to the delete event - `git add` on a missing path stages the
		// removal exactly like `git rm` would.
		unlink($this->tmpRepoPath . '/file1.txt');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$previousSnapshot = new Snapshot();
		$previousSnapshot->setId(3);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($previousSnapshot);
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Snapshot $snapshot): bool {
				return $snapshot->getUserId() === 'testuser'
					&& $snapshot->getFilePath() === 'file1.txt'
					&& $snapshot->getParentSnapshotId() === 3
					&& $snapshot->getStatus() === 'deleted'
					&& $snapshot->getFileId() === 42
					&& preg_match('/^[0-9a-f]{40}$/', $snapshot->getCommitHash()) === 1;
			}))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->autoCommitDelete($this->tmpRepoPath, 'file1.txt', 42, 'testuser');

		$this->assertTrue($result['success']);

		exec($this->gitCommand() . ' log -1 --pretty=%s', $logOutput);
		$this->assertSame('Auto-commit: deleted file1.txt', $logOutput[0]);
	}

	public function testAutoCommitDeleteFailsWhenRepositoryNotInitialized(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('insert');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->autoCommitDelete($this->tmpRepoPath, 'file1.txt', 42, 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testAutoCommitDeleteFailsWhenNothingStagedForThatPath(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		// Nothing was ever committed at this path, so `git add` on it stages nothing.
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('insert');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->autoCommitDelete($this->tmpRepoPath, 'never-committed.txt', 42, 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testAutoCommitRenameStagesBothPathsAndRecordsCommittedSnapshotAtNewPath(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		file_put_contents($this->tmpRepoPath . '/old.txt', 'hello');
		exec($this->gitCommand() . ' add old.txt');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');

		// Nextcloud's rename/move has already completed on disk by the time this
		// runs - there is no old.txt left to `git mv` from.
		rename($this->tmpRepoPath . '/old.txt', $this->tmpRepoPath . '/new.txt');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$previousSnapshot = new Snapshot();
		$previousSnapshot->setId(9);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($previousSnapshot);
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Snapshot $snapshot): bool {
				return $snapshot->getUserId() === 'testuser'
					&& $snapshot->getFilePath() === 'new.txt'
					&& $snapshot->getParentSnapshotId() === 9
					&& $snapshot->getStatus() === 'committed'
					&& $snapshot->getFileId() === 42;
			}))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->autoCommitRename($this->tmpRepoPath, 'old.txt', 'new.txt', 42, 'testuser');

		$this->assertTrue($result['success']);

		exec($this->gitCommand() . ' log -1 --pretty=%s', $logOutput);
		$this->assertSame('Auto-commit: renamed old.txt to new.txt', $logOutput[0]);
	}

	public function testAutoCommitRenameFailsWhenRepositoryNotInitialized(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('insert');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->autoCommitRename($this->tmpRepoPath, 'old.txt', 'new.txt', 42, 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testAutoCommitRestoreStagesFileAndRecordsCommittedSnapshotClearingDeletedStatus(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		exec($this->gitInitCommand());
		exec($this->gitCommand() . ' config user.email "test@example.com"');
		exec($this->gitCommand() . ' config user.name "Test"');

		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');
		exec($this->gitCommand() . ' add file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Initial commit"');
		exec($this->gitCommand() . ' rm -q file1.txt');
		exec($this->gitCommand() . ' commit -q -m "Auto-commit: deleted file1.txt"');

		// Nextcloud's trash restore has already put the file back on disk by the
		// time this runs, but git's index still has it removed from the earlier
		// delete commit.
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$previousSnapshot = new Snapshot();
		$previousSnapshot->setId(5);
		$previousSnapshot->setStatus('deleted');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($previousSnapshot);
		$snapshotMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (Snapshot $snapshot): bool {
				return $snapshot->getUserId() === 'testuser'
					&& $snapshot->getFilePath() === 'file1.txt'
					&& $snapshot->getParentSnapshotId() === 5
					&& $snapshot->getStatus() === 'committed'
					&& $snapshot->getFileId() === 42;
			}))
			->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->autoCommitRestore($this->tmpRepoPath, 'file1.txt', 42, 'testuser');

		$this->assertTrue($result['success']);

		exec($this->gitCommand() . ' log -1 --pretty=%s', $logOutput);
		$this->assertSame('Auto-commit: restored file1.txt', $logOutput[0]);
	}

	public function testAutoCommitRestoreFailsWhenRepositoryNotInitialized(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('insert');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->autoCommitRestore($this->tmpRepoPath, 'file1.txt', 42, 'testuser');

		$this->assertFalse($result['success']);
	}

	public function testResolveRepositoryPathReturnsFalseForNonLocalStorage(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(false);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$this->assertFalse($service->resolveRepositoryPath($userFolder));
	}

	public function testResolveRepositoryPathReturnsLocalFilePathForLocalStorage(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$this->assertSame('/data/testuser/files', $service->resolveRepositoryPath($userFolder));
	}

	public function testRunGitReturnsClearErrorWhenGitBinaryNotOnPath(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$originalPath = getenv('PATH');
		putenv('PATH=' . sys_get_temp_dir());
		try {
			$result = $service->runGit($this->tmpRepoPath, ['init']);
		} finally {
			putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
		}

		$this->assertFalse($result['success']);
		$this->assertSame(VcsService::GIT_NOT_INSTALLED_MESSAGE, $result['output']);
	}

	public function testCommitChangesFailsWithClearErrorWhenGitBinaryNotOnPath(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('insert');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$originalPath = getenv('PATH');
		putenv('PATH=' . sys_get_temp_dir());
		try {
			$result = $service->commitChanges($this->tmpRepoPath, [['path' => 'file1.txt', 'fileId' => 42]], 'Initial commit', 'testuser');
		} finally {
			putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
		}

		$this->assertFalse($result['success']);
		$this->assertStringContainsString(VcsService::GIT_NOT_INSTALLED_MESSAGE, $result['message']);
	}

	public function testRunGitSurfacesRealReasonWhenProcOpenFailsForReasonsOtherThanMissingGit(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		// git is present on PATH, but the working directory itself doesn't exist,
		// so proc_open() fails for a different reason than "git isn't installed".
		$result = $service->runGit('/nonexistent/gitcloud-test-path-' . uniqid(), ['--version']);

		$this->assertFalse($result['success']);
		$this->assertStringStartsWith('Unable to start the git process: ', $result['output']);
		$this->assertNotSame('Unable to start the git process: ', $result['output']);
	}

	public function testRunGitPrefersBundledBinaryForCurrentArchitectureWhenPresentAndExecutable(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$appPath = $this->createFakeBundledGitBinary('BUNDLED_GIT_MARKER');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager);

		$result = $service->runGit($this->tmpRepoPath, ['--version']);

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('BUNDLED_GIT_MARKER', $result['output']);
	}

	public function testRunGitFallsBackToSystemGitWhenBundledBinaryDoesNotExist(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		// Real app path, but nothing was ever fetched into bin/<arch>/git under it.
		$this->tmpAppPath = sys_get_temp_dir() . '/gitcloud-test-app-' . uniqid();
		mkdir($this->tmpAppPath);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($this->tmpAppPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager);

		$result = $service->runGit($this->tmpRepoPath, ['--version']);

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('git version', $result['output']);
	}

	public function testRunGitFallsBackToSystemGitWhenBundledBinaryExistsButIsNotExecutable(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$appPath = $this->createFakeBundledGitBinary('BUNDLED_GIT_MARKER', executable: false);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager);

		$result = $service->runGit($this->tmpRepoPath, ['--version']);

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('git version', $result['output']);
		$this->assertStringNotContainsString('BUNDLED_GIT_MARKER', $result['output']);
	}

	public function testRunGitFallsBackToSystemGitWhenAppPathCannotBeResolved(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willThrowException(new AppPathNotFoundException());

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager);

		$result = $service->runGit($this->tmpRepoPath, ['--version']);

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('git version', $result['output']);
	}

	public function testRunGitStillFailsWithClearErrorWhenNeitherBundledNorSystemGitIsAvailable(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		// Real app path, but nothing was ever fetched into bin/<arch>/git under it,
		// combined with no system git on PATH - the worst case, still a clear error.
		$this->tmpAppPath = sys_get_temp_dir() . '/gitcloud-test-app-' . uniqid();
		mkdir($this->tmpAppPath);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($this->tmpAppPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager);

		$originalPath = getenv('PATH');
		putenv('PATH=' . sys_get_temp_dir());
		try {
			$result = $service->runGit($this->tmpRepoPath, ['--version']);
		} finally {
			putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
		}

		$this->assertFalse($result['success']);
		$this->assertSame(VcsService::GIT_NOT_INSTALLED_MESSAGE, $result['output']);
	}

	/**
	 * Builds an IAppConfig mock whose git_binary_mode value is fixed to $mode,
	 * mirroring exactly how VcsService reads it: getValueString(APP_ID, 'git_binary_mode', 'auto').
	 */
	private function createAppConfigWithGitBinaryMode(string $mode): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('gitcloud', 'git_binary_mode', 'auto')->willReturn($mode);

		return $appConfig;
	}

	public function testResolveGitBinaryModeSystemIgnoresBundledBinaryEvenWhenPresent(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$appPath = $this->createFakeBundledGitBinary('BUNDLED_GIT_MARKER');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$appConfig = $this->createAppConfigWithGitBinaryMode('system');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager, $appConfig);

		$result = $service->runGit($this->tmpRepoPath, ['--version']);

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('git version', $result['output']);
		$this->assertStringNotContainsString('BUNDLED_GIT_MARKER', $result['output']);
	}

	public function testResolveGitBinaryModeStaticUsesBundledBinaryEvenWhenSystemGitAlsoExists(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		$appPath = $this->createFakeBundledGitBinary('BUNDLED_GIT_MARKER');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$appConfig = $this->createAppConfigWithGitBinaryMode('static');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager, $appConfig);

		$result = $service->runGit($this->tmpRepoPath, ['--version']);

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('BUNDLED_GIT_MARKER', $result['output']);
	}

	public function testResolveGitBinaryModeStaticFailsWithDedicatedMessageWhenNoBundledBinaryExists(): void {
		$this->tmpRepoPath = $this->createWorkingTree();

		// Real app path, but nothing was ever fetched into bin/<arch>/git under it -
		// "static" mode must not silently fall back to system git even though it's
		// on PATH in this test environment.
		$this->tmpAppPath = sys_get_temp_dir() . '/gitcloud-test-app-' . uniqid();
		mkdir($this->tmpAppPath);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($this->tmpAppPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$appConfig = $this->createAppConfigWithGitBinaryMode('static');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager, $appConfig);

		$result = $service->runGit($this->tmpRepoPath, ['--version']);

		$this->assertFalse($result['success']);
		$this->assertSame(VcsService::GIT_STATIC_SELECTED_BUT_MISSING_MESSAGE, $result['output']);
	}

	public function testGetGitBinaryStatusReportsStaticAsResolvedWhenBundledBinaryPreferredInAutoMode(): void {
		$appPath = $this->createFakeBundledGitBinary('BUNDLED_GIT_MARKER');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$appConfig = $this->createAppConfigWithGitBinaryMode('auto');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager, $appConfig);

		$status = $service->getGitBinaryStatus();

		$this->assertSame('auto', $status['mode']);
		$this->assertTrue($status['staticGitAvailable']);
		$this->assertSame('static', $status['resolvedBinary']);
	}

	public function testGetGitBinaryStatusReportsNoneWhenModeIsStaticAndNoBundledBinaryExists(): void {
		$this->tmpAppPath = sys_get_temp_dir() . '/gitcloud-test-app-' . uniqid();
		mkdir($this->tmpAppPath);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($this->tmpAppPath);

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$appConfig = $this->createAppConfigWithGitBinaryMode('static');

		$service = new VcsService($logger, $snapshotMapper, $timeFactory, $appManager, $appConfig);

		$status = $service->getGitBinaryStatus();

		$this->assertSame('static', $status['mode']);
		$this->assertFalse($status['staticGitAvailable']);
		$this->assertSame('none', $status['resolvedBinary']);
	}

	public function testResolveGitDirectoryPutsTheRepositoryBesideTheWorkingTreeNotInsideIt(): void {
		$service = new VcsService(
			$this->createMock(LoggerInterface::class),
			$this->createMock(SnapshotMapper::class),
			$this->createMock(ITimeFactory::class),
		);

		$gitDirectory = $service->resolveGitDirectory('/var/www/html/data/alice/files');

		$this->assertSame('/var/www/html/data/alice/gitcloud', $gitDirectory);
		// The whole point of the relocation: nothing under the user's own files directory,
		// which is what Nextcloud exposes over the Files app, WebDAV and sync clients.
		$this->assertStringNotContainsString('/files/', $gitDirectory);
	}

	public function testCommitChangesCreatesTheRepositoryBesideTheWorkingTreeLeavingNoDotGitInIt(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');

		$logger = $this->createMock(LoggerInterface::class);
		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getTime')->willReturn(1720000000);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->willReturn(null);
		$snapshotMapper->method('insert')->willReturnArgument(0);

		$service = new VcsService($logger, $snapshotMapper, $timeFactory);

		$result = $service->commitChanges($this->tmpRepoPath, [['path' => 'file1.txt', 'fileId' => 7]], 'first', 'testuser');

		$this->assertTrue($result['success']);
		$this->assertDirectoryExists($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME);
		$this->assertDirectoryDoesNotExist($this->tmpRepoPath . '/.git');
		$this->assertFileDoesNotExist($this->tmpRepoPath . '/.git');
	}

	public function testCommitChangesWritesItsIdentityConfigIntoTheRelocatedRepositoryNotTheUsersFiles(): void {
		$this->tmpRepoPath = $this->createWorkingTree();
		file_put_contents($this->tmpRepoPath . '/file1.txt', 'hello');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->willReturn(null);
		$snapshotMapper->method('insert')->willReturnArgument(0);

		$service = new VcsService(
			$this->createMock(LoggerInterface::class),
			$snapshotMapper,
			$this->createMock(ITimeFactory::class),
		);

		$this->assertTrue($service->commitChanges($this->tmpRepoPath, [['path' => 'file1.txt', 'fileId' => 7]], 'first', 'testuser')['success']);

		// Identity setup goes through runGitConfigGet/Set, which must also be pointed at
		// the relocated repository - otherwise it would silently read and write the
		// server-wide git config instead of this user's repository.
		$config = file_get_contents($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME . '/config');
		$this->assertStringContainsString('[user]', $config);
		$this->assertStringContainsString('bare = false', $config);
	}
}
