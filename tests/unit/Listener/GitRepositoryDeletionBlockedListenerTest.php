<?php

declare(strict_types=1);

namespace Listener;

use OCA\GitCloud\Listener\GitRepositoryDeletionBlockedListener;
use OCP\EventDispatcher\Event;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

final class GitRepositoryDeletionBlockedListenerTest extends TestCase {
	public function testHandleIgnoresEventsOfOtherTypes(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$listener = new GitRepositoryDeletionBlockedListener($rootFolder);
		$listener->handle($this->createMock(Event::class));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingWhenNodeHasNoOwner(): void {
		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$listener = new GitRepositoryDeletionBlockedListener($rootFolder);
		$listener->handle(new BeforeNodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingForOrdinaryTrackedFile(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getPath')->willReturn('/testuser/files/folder/file.txt');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/folder/file.txt')->willReturn('/folder/file.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$listener = new GitRepositoryDeletionBlockedListener($rootFolder);
		$listener->handle(new BeforeNodeDeletedEvent($node));

		$this->addToAssertionCount(1);
	}

	public function testHandleBlocksDeletingTheGitDirectoryItself(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getPath')->willReturn('/testuser/files/.git');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/.git')->willReturn('/.git');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$listener = new GitRepositoryDeletionBlockedListener($rootFolder);

		$this->expectException(AbortedEventException::class);
		$listener->handle(new BeforeNodeDeletedEvent($node));
	}

	public function testHandleBlocksDeletingAFileInsideTheGitDirectory(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$node = $this->createMock(Node::class);
		$node->method('getOwner')->willReturn($owner);
		$node->method('getPath')->willReturn('/testuser/files/.git/index');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->with('/testuser/files/.git/index')->willReturn('/.git/index');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$listener = new GitRepositoryDeletionBlockedListener($rootFolder);

		$this->expectException(AbortedEventException::class);
		$listener->handle(new BeforeNodeDeletedEvent($node));
	}
}
