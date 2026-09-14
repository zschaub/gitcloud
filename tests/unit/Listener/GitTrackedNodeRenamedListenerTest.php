<?php

declare(strict_types=1);

namespace Listener;

use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Listener\GitTrackedNodeRenamedListener;
use OCA\GitCloud\Service\VcsService;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GitTrackedNodeRenamedListenerTest extends TestCase {
	public function testHandleIgnoresEventsOfOtherTypes(): void {
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->expects($this->never())->method('findLatestForFileId');

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
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

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

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
		$vcsService->expects($this->never())->method('autoCommitRename');
		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleAutoCommitsRenameForTrackedFile(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Node::class);
		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(42);
		$target->method('getPath')->willReturn('/testuser/files/folder/new.txt');

		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('getId')->willReturn('home::testuser');
		$target->method('getStorage')->willReturn($homeStorage);

		$latestSnapshot = new Snapshot();
		$latestSnapshot->setFilePath('folder/old.txt');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($latestSnapshot);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);
		$userFolder->method('getRelativePath')->with('/testuser/files/folder/new.txt')->willReturn('/folder/new.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('autoCommitRename')
			->with('/data/testuser/files', 'folder/old.txt', 'folder/new.txt', 42, 'testuser')
			->willReturn(['success' => true, 'message' => 'Auto-committed rename of folder/old.txt to folder/new.txt.']);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenNewPathMatchesLastKnownPath(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Node::class);
		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(42);
		$target->method('getPath')->willReturn('/testuser/files/folder/same.txt');

		$latestSnapshot = new Snapshot();
		$latestSnapshot->setFilePath('folder/same.txt');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($latestSnapshot);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/folder/same.txt')->willReturn('/folder/same.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('autoCommitRename');

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleRecordsADeletionWhenFileIsMovedOutsideTheWorkingTree(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('getId')->willReturn('home::testuser');

		// The file was moved into a group folder: a separate mount inside files/
		// whose bytes live outside the user's home storage, so there is no new
		// path in the working tree for git to stage.
		$groupFolderStorage = $this->createMock(IStorage::class);
		$groupFolderStorage->method('getId')->willReturn('local::/data/__groupfolders/3/');

		$source = $this->createMock(Node::class);
		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(42);
		$target->method('getPath')->willReturn('/testuser/files/Team Folder/old.txt');
		$target->method('getStorage')->willReturn($groupFolderStorage);

		$latestSnapshot = new Snapshot();
		$latestSnapshot->setFilePath('folder/old.txt');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn($latestSnapshot);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);
		$userFolder->method('getRelativePath')->with('/testuser/files/Team Folder/old.txt')->willReturn('/Team Folder/old.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('autoCommitRename');
		$vcsService->expects($this->once())
			->method('autoCommitDelete')
			->with('/data/testuser/files', 'folder/old.txt', 42, 'testuser')
			->willReturn(['success' => true, 'message' => 'Auto-committed deletion of folder/old.txt.']);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}
}
