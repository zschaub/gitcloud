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

		$snapshotMapper = $this->createMock(SnapshotMapper::class);
		$snapshotMapper->method('findLatestForFileId')->with('testuser', 42)->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
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
}
