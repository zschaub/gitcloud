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

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
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

		$latestSnapshot = new Snapshot();
		$latestSnapshot->setStatus('committed');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($latestSnapshot);

		$rootFolder = $this->createMock(IRootFolder::class);
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
}
