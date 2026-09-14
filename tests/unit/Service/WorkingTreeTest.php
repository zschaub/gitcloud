<?php

declare(strict_types=1);

namespace Service;

use OCA\GitCloud\Service\WorkingTree;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;

final class WorkingTreeTest extends TestCase {
	public function testContainsIsTrueWhenNodeIsOnTheUserFoldersOwnStorage(): void {
		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('getId')->willReturn('home::testuser');

		$node = $this->createMock(Node::class);
		$node->method('getStorage')->willReturn($homeStorage);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);

		$this->assertTrue(WorkingTree::contains($node, $userFolder));
	}

	public function testContainsIsFalseWhenNodeIsOnADifferentStorage(): void {
		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('getId')->willReturn('home::testuser');

		// A group folder is a separate mount inside files/ whose bytes live under
		// <data>/__groupfolders/<numericId>/ - local, but not in the working tree.
		$groupFolderStorage = $this->createMock(IStorage::class);
		$groupFolderStorage->method('getId')->willReturn('local::/data/__groupfolders/3/');

		$node = $this->createMock(Node::class);
		$node->method('getStorage')->willReturn($groupFolderStorage);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);

		$this->assertFalse(WorkingTree::contains($node, $userFolder));
	}
}
