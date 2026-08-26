<?php

declare(strict_types=1);

namespace Listener;

use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Listener\GitTrackedNodeDeletedListener;
use OCA\GitCloud\Service\VcsService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GitTrackedNodeDeletedListenerTest extends TestCase {
	public function testHandleIgnoresEventsOfOtherTypes(): void {
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('findLatestForFileId');

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle($this->createMock(Event::class));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenNodeHasNoOwner(): void {
		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn(null);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('findLatestForFileId');

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenFileHasNoGitCloudHistory(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getId')->willReturn(42);
		$node->method('getPath')->willReturn('/testuser/files/untracked.txt');

		// No history for this node's own file_id, and (per the folder-delete
		// fallback) no other tracked file's path falls under it either - an
		// untracked file's own path is never a valid prefix of another path.
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn(null);
		$snapshotMapper->method('findAllForUser')->with('testuser')->willReturn([]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/untracked.txt')->willReturn('/untracked.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->expects($this->never())->method('autoCommitDelete');
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenAlreadyRecordedAsDeleted(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getId')->willReturn(42);

		$existingDeletedSnapshot = new Snapshot();
		$existingDeletedSnapshot->setStatus('deleted');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($existingDeletedSnapshot);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->expects($this->never())->method('autoCommitDelete');
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleAutoCommitsDeleteForTrackedFile(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getId')->willReturn(42);

		$latestSnapshot = new Snapshot();
		$latestSnapshot->setStatus('committed');
		$latestSnapshot->setFilePath('folder/file1.txt');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($latestSnapshot);

		$userFolder = $this->createMock(Folder::class);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('autoCommitDelete')
			->with('/data/testuser/files', 'folder/file1.txt', 42, 'testuser')
			->willReturn(['success' => true, 'message' => 'Auto-committed deletion of folder/file1.txt.']);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleLogsAndDoesNotThrowWhenRepositoryPathCannotBeResolved(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getId')->willReturn(42);

		$latestSnapshot = new Snapshot();
		$latestSnapshot->setStatus('committed');
		$latestSnapshot->setFilePath('folder/file1.txt');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($latestSnapshot);

		$userFolder = $this->createMock(Folder::class);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn(false);
		$vcsService->expects($this->never())->method('autoCommitDelete');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleAutoCommitsDeleteForEachTrackedFileInsideDeletedFolder(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Folder::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getPath')->willReturn('/testuser/files/Test Folder');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/Test Folder')->willReturn('/Test Folder');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$insideFile1 = new Snapshot();
		$insideFile1->setFileId(10);
		$insideFile1->setFilePath('Test Folder/one.txt');
		$insideFile1->setStatus('committed');

		$insideFile2 = new Snapshot();
		$insideFile2->setFileId(11);
		$insideFile2->setFilePath('Test Folder/two.txt');
		$insideFile2->setStatus('committed');

		// Already recorded as deleted (e.g. from a prior partial run) - must not
		// be re-committed as a deletion a second time.
		$alreadyDeleted = new Snapshot();
		$alreadyDeleted->setFileId(12);
		$alreadyDeleted->setFilePath('Test Folder/already-gone.txt');
		$alreadyDeleted->setStatus('deleted');

		// Outside the deleted folder entirely - must not be touched.
		$unrelated = new Snapshot();
		$unrelated->setFileId(13);
		$unrelated->setFilePath('Other Folder/unrelated.txt');
		$unrelated->setStatus('committed');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForUser')->with('testuser')->willReturn([
			$insideFile1, $insideFile2, $alreadyDeleted, $unrelated,
		]);
		$snapshotMapper->expects($this->never())->method('findLatestForFileId');

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->exactly(2))
			->method('autoCommitDelete')
			->willReturnMap([
				['/data/testuser/files', 'Test Folder/one.txt', 10, 'testuser', ['success' => true, 'message' => 'ok']],
				['/data/testuser/files', 'Test Folder/two.txt', 11, 'testuser', ['success' => true, 'message' => 'ok']],
			]);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleOnlyConsidersEachFileIdsLatestSnapshotInsideDeletedFolder(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Folder::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getPath')->willReturn('/testuser/files/Test Folder');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/Test Folder')->willReturn('/Test Folder');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		// findAllForUser is ordered newest-first: the newer "rolled_back" snapshot
		// for file_id 10 must win over the older "deleted" one, so this file's
		// deletion still gets auto-committed rather than being skipped.
		$newer = new Snapshot();
		$newer->setFileId(10);
		$newer->setFilePath('Test Folder/one.txt');
		$newer->setStatus('rolled_back');

		$older = new Snapshot();
		$older->setFileId(10);
		$older->setFilePath('Test Folder/one.txt');
		$older->setStatus('deleted');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findAllForUser')->with('testuser')->willReturn([$newer, $older]);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('autoCommitDelete')
			->with('/data/testuser/files', 'Test Folder/one.txt', 10, 'testuser')
			->willReturn(['success' => true, 'message' => 'ok']);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenDeletedFolderIsTheRepositoryRoot(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Folder::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getPath')->willReturn('/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files')->willReturn('/');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('findAllForUser');

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('autoCommitDelete');

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeDeletedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}
}
