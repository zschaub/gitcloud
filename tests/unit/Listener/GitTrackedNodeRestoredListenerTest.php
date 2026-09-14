<?php

declare(strict_types=1);

namespace Listener;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Listener\GitTrackedNodeRestoredListener;
use OCA\GitCloud\Service\VcsService;
use OCP\EventDispatcher\Event;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GitTrackedNodeRestoredListenerTest extends TestCase {
	public function testHandleIgnoresEventsOfOtherTypes(): void {
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('findLatestForFileId');

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRestoredListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle($this->createMock(Event::class));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenTargetHasNoOwner(): void {
		$source = $this->createMock(Node::class);
		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn(null);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('findLatestForFileId');

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRestoredListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRestoredEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenFileHasNoGitCloudHistory(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Node::class);
		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(42);
		$target->method('getPath')->willReturn('/testuser/files/untracked.txt');

		// No history for this node's own file_id, and (per the folder-restore
		// fallback) no other tracked file's path falls under it either - an
		// untracked file's own path is never a valid prefix of another path.
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn(null);
		$snapshotMapper->method('findAllForUserUnderPath')->willReturn([]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/untracked.txt')->willReturn('/untracked.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->expects($this->never())->method('autoCommitRestore');
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRestoredListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRestoredEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenLastKnownStatusIsNotDeleted(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Node::class);
		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(42);
		$target->method('getPath')->willReturn('/testuser/files/folder/file1.txt');

		$latestSnapshot = new Snapshot();
		$latestSnapshot->setStatus('committed');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($latestSnapshot);
		$snapshotMapper->method('findAllForUserUnderPath')->willReturn([]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/folder/file1.txt')->willReturn('/folder/file1.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->expects($this->never())->method('autoCommitRestore');
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRestoredListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRestoredEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleAutoCommitsRestoreForFileGitCloudLastSawAsDeleted(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Node::class);
		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(42);
		$target->method('getPath')->willReturn('/testuser/files/folder/file1.txt');

		$latestSnapshot = new Snapshot();
		$latestSnapshot->setStatus('deleted');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($latestSnapshot);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/folder/file1.txt')->willReturn('/folder/file1.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('autoCommitRestore')
			->with('/data/testuser/files', 'folder/file1.txt', 42, 'testuser')
			->willReturn(['success' => true, 'message' => 'Auto-committed restore of folder/file1.txt.']);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRestoredListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRestoredEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleAutoCommitsRestoreForEachTrackedFileInsideRestoredFolder(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Folder::class);
		$target = $this->createMock(Folder::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(999);
		$target->method('getPath')->willReturn('/testuser/files/Test Folder');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/Test Folder')->willReturn('/Test Folder');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$insideFile1 = new Snapshot();
		$insideFile1->setFileId(10);
		$insideFile1->setFilePath('Test Folder/one.txt');
		$insideFile1->setStatus('deleted');

		$insideFile2 = new Snapshot();
		$insideFile2->setFileId(11);
		$insideFile2->setFilePath('Test Folder/two.txt');
		$insideFile2->setStatus('deleted');

		// Already in sync - nothing to reconcile for this one.
		$alreadyCommitted = new Snapshot();
		$alreadyCommitted->setFileId(12);
		$alreadyCommitted->setFilePath('Test Folder/already-fine.txt');
		$alreadyCommitted->setStatus('committed');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		// The folder's own file_id has no history (the unconditional single-file
		// gate this listener always checks first), which is what triggers the
		// fallback below.
		$snapshotMapper->expects($this->once())->method('findLatestForFileId')->with('testuser', 999)->willReturn(null);
		$snapshotMapper->expects($this->once())
			->method('findAllForUserUnderPath')
			->with('testuser', 'Test Folder/')
			->willReturn([$insideFile1, $insideFile2, $alreadyCommitted]);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->exactly(2))
			->method('autoCommitRestore')
			->willReturnMap([
				['/data/testuser/files', 'Test Folder/one.txt', 10, 'testuser', ['success' => true, 'message' => 'ok']],
				['/data/testuser/files', 'Test Folder/two.txt', 11, 'testuser', ['success' => true, 'message' => 'ok']],
			]);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRestoredListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRestoredEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleOnlyConsidersEachFileIdsLatestSnapshotInsideRestoredFolder(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Folder::class);
		$target = $this->createMock(Folder::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(999);
		$target->method('getPath')->willReturn('/testuser/files/Test Folder');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/Test Folder')->willReturn('/Test Folder');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		// The folder's own file_id has no history (the unconditional single-file
		// gate this listener always checks first), which is what triggers the
		// fallback below. findAllForUserUnderPath is ordered newest-first: the newer "committed"
		// snapshot for file_id 10 must win over the older "deleted" one, so this
		// file is correctly left alone rather than being redundantly restored.
		$newer = new Snapshot();
		$newer->setFileId(10);
		$newer->setFilePath('Test Folder/one.txt');
		$newer->setStatus('committed');

		$older = new Snapshot();
		$older->setFileId(10);
		$older->setFilePath('Test Folder/one.txt');
		$older->setStatus('deleted');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 999)->willReturn(null);
		$snapshotMapper->method('findAllForUserUnderPath')->with('testuser', 'Test Folder/')->willReturn([$newer, $older]);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('autoCommitRestore');

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRestoredListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRestoredEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenRestoredFolderIsTheRepositoryRoot(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Folder::class);
		$target = $this->createMock(Folder::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(999);
		$target->method('getPath')->willReturn('/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files')->willReturn('/');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 999)->willReturn(null);
		$snapshotMapper->expects($this->never())->method('findAllForUserUnderPath');

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->expects($this->never())->method('autoCommitRestore');

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRestoredListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRestoredEvent($source, $target));

		$this->addToAssertionCount(1);
	}
}
