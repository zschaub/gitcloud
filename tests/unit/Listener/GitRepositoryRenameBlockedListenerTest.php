<?php

declare(strict_types=1);

namespace Listener;

use OCA\GitCloud\Listener\GitRepositoryRenameBlockedListener;
use OCP\EventDispatcher\Event;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

final class GitRepositoryRenameBlockedListenerTest extends TestCase {
	public function testHandleIgnoresEventsOfOtherTypes(): void {
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$listener = new GitRepositoryRenameBlockedListener($rootFolder);
		$listener->handle($this->createMock(Event::class));

		$this->addToAssertionCount(1);
	}

	public function testHandleDoesNothingForOrdinaryRename(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Node::class);
		$source->method('getOwner')->willReturn($owner);
		$source->method('getPath')->willReturn('/testuser/files/folder/old.txt');

		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getPath')->willReturn('/testuser/files/folder/new.txt');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/folder/old.txt', '/folder/old.txt'],
			['/testuser/files/folder/new.txt', '/folder/new.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$listener = new GitRepositoryRenameBlockedListener($rootFolder);
		$listener->handle(new BeforeNodeRenamedEvent($source, $target));

		$this->addToAssertionCount(1);
	}

	public function testHandleBlocksRenamingTheGitDirectoryAway(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Node::class);
		$source->method('getOwner')->willReturn($owner);
		$source->method('getPath')->willReturn('/testuser/files/.git');

		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getPath')->willReturn('/testuser/files/git-backup');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/.git', '/.git'],
			['/testuser/files/git-backup', '/git-backup'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$listener = new GitRepositoryRenameBlockedListener($rootFolder);

		$this->expectException(AbortedEventException::class);
		$listener->handle(new BeforeNodeRenamedEvent($source, $target));
	}

	public function testHandleBlocksMovingAFileOntoAPathInsideTheGitDirectory(): void {
		$owner = $this->createMock(\OCP\IUser::class);
		$owner->method('getUID')->willReturn('testuser');

		$source = $this->createMock(Node::class);
		$source->method('getOwner')->willReturn($owner);
		$source->method('getPath')->willReturn('/testuser/files/folder/file.txt');

		$target = $this->createMock(Node::class);
		$target->method('getOwner')->willReturn($owner);
		$target->method('getPath')->willReturn('/testuser/files/.git/file.txt');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/folder/file.txt', '/folder/file.txt'],
			['/testuser/files/.git/file.txt', '/.git/file.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$listener = new GitRepositoryRenameBlockedListener($rootFolder);

		$this->expectException(AbortedEventException::class);
		$listener->handle(new BeforeNodeRenamedEvent($source, $target));
	}
}
