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
		$source->method('getPath')->willReturn('/testuser/files/untracked-old.txt');
		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(42);
		$target->method('getPath')->willReturn('/testuser/files/untracked-new.txt');

		// No history for this node's own file_id, and (per the folder-rename
		// fallback) no other tracked file's path falls under it either - an
		// untracked file's own path is never a valid prefix of another path.
		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn(null);
		$snapshotMapper->method('findAllForUserUnderPath')->willReturn([]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/untracked-old.txt', '/untracked-old.txt'],
			['/testuser/files/untracked-new.txt', '/untracked-new.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->expects($this->never())->method('autoCommitRename');
		$vcsService->expects($this->never())->method('autoCommitDelete');
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

	public function testHandleAutoCommitsRenameForEachTrackedFileInsideRenamedFolder(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('getId')->willReturn('home::testuser');

		$source = $this->createMock(Folder::class);
		$source->method('getPath')->willReturn('/testuser/files/Old Folder');

		$target = $this->createMock(Folder::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(999);
		$target->method('getPath')->willReturn('/testuser/files/New Folder');
		$target->method('getStorage')->willReturn($homeStorage);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/Old Folder', '/Old Folder'],
			['/testuser/files/New Folder', '/New Folder'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$insideFile1 = new Snapshot();
		$insideFile1->setFileId(10);
		$insideFile1->setFilePath('Old Folder/one.txt');
		$insideFile1->setStatus('committed');

		$insideFile2 = new Snapshot();
		$insideFile2->setFileId(11);
		$insideFile2->setFilePath('Old Folder/sub/two.txt');
		$insideFile2->setStatus('committed');

		// Already recorded as deleted - can't have moved along with the folder.
		$alreadyDeleted = new Snapshot();
		$alreadyDeleted->setFileId(12);
		$alreadyDeleted->setFilePath('Old Folder/already-gone.txt');
		$alreadyDeleted->setStatus('deleted');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		// The folder's own file_id has no history (the unconditional single-file
		// gate this listener always checks first), which is what triggers the
		// fallback below.
		$snapshotMapper->expects($this->once())->method('findLatestForFileId')->with('testuser', 999)->willReturn(null);
		$snapshotMapper->expects($this->once())
			->method('findAllForUserUnderPath')
			->with('testuser', 'Old Folder/')
			->willReturn([$insideFile1, $insideFile2, $alreadyDeleted]);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->exactly(2))
			->method('autoCommitRename')
			->willReturnMap([
				['/data/testuser/files', 'Old Folder/one.txt', 'New Folder/one.txt', 10, 'testuser', ['success' => true, 'message' => 'ok']],
				['/data/testuser/files', 'Old Folder/sub/two.txt', 'New Folder/sub/two.txt', 11, 'testuser', ['success' => true, 'message' => 'ok']],
			]);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleOnlyConsidersEachFileIdsLatestSnapshotInsideRenamedFolder(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('getId')->willReturn('home::testuser');

		$source = $this->createMock(Folder::class);
		$source->method('getPath')->willReturn('/testuser/files/Old Folder');

		$target = $this->createMock(Folder::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(999);
		$target->method('getPath')->willReturn('/testuser/files/New Folder');
		$target->method('getStorage')->willReturn($homeStorage);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/Old Folder', '/Old Folder'],
			['/testuser/files/New Folder', '/New Folder'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		// The folder's own file_id has no history (the unconditional single-file
		// gate this listener always checks first), which is what triggers the
		// fallback below. findAllForUserUnderPath is ordered newest-first: the newer "committed"
		// snapshot for file_id 10 must win over the older "deleted" one, so this
		// file's rename still gets auto-committed rather than being skipped.
		$newer = new Snapshot();
		$newer->setFileId(10);
		$newer->setFilePath('Old Folder/one.txt');
		$newer->setStatus('committed');

		$older = new Snapshot();
		$older->setFileId(10);
		$older->setFilePath('Old Folder/one.txt');
		$older->setStatus('deleted');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 999)->willReturn(null);
		$snapshotMapper->method('findAllForUserUnderPath')->with('testuser', 'Old Folder/')->willReturn([$newer, $older]);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('autoCommitRename')
			->with('/data/testuser/files', 'Old Folder/one.txt', 'New Folder/one.txt', 10, 'testuser')
			->willReturn(['success' => true, 'message' => 'ok']);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenRenamedFolderIsTheRepositoryRoot(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Folder::class);
		$source->method('getPath')->willReturn('/testuser/files');

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
		$vcsService->expects($this->never())->method('autoCommitRename');
		$vcsService->expects($this->never())->method('autoCommitDelete');

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleRecordsDeletionForEachDescendantWhenFolderIsMovedOutsideTheWorkingTree(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('getId')->willReturn('home::testuser');

		$groupFolderStorage = $this->createMock(IStorage::class);
		$groupFolderStorage->method('getId')->willReturn('local::/data/__groupfolders/3/');

		$source = $this->createMock(Folder::class);
		$source->method('getPath')->willReturn('/testuser/files/Old Folder');

		$target = $this->createMock(Folder::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getId')->willReturn(999);
		$target->method('getPath')->willReturn('/testuser/files/Team Folder');
		$target->method('getStorage')->willReturn($groupFolderStorage);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/Old Folder', '/Old Folder'],
			['/testuser/files/Team Folder', '/Team Folder'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$insideFile = new Snapshot();
		$insideFile->setFileId(10);
		$insideFile->setFilePath('Old Folder/one.txt');
		$insideFile->setStatus('committed');

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 999)->willReturn(null);
		$snapshotMapper->method('findAllForUserUnderPath')->with('testuser', 'Old Folder/')->willReturn([$insideFile]);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->with($userFolder)->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('autoCommitRename');
		$vcsService->expects($this->once())
			->method('autoCommitDelete')
			->with('/data/testuser/files', 'Old Folder/one.txt', 10, 'testuser')
			->willReturn(['success' => true, 'message' => 'ok']);

		$logger = $this->createMock(LoggerInterface::class);

		$listener = new GitTrackedNodeRenamedListener($snapshotMapper, $rootFolder, $vcsService, $logger);
		$listener->handle(new NodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}
}
