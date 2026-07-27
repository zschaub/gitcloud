<?php

declare(strict_types=1);

namespace Controller;

use OCA\GitCloud\AppInfo\Application;
use OCA\GitCloud\Controller\ApiController;
use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Service\VcsService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	public function testCommitChangesSucceedsWithFilesAndMessage(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$file1 = $this->createMock(Node::class);
		$file1->method('getStorage')->willReturn($storage);
		$file1->method('getPath')->willReturn('/testuser/files/file1.txt');

		$file2 = $this->createMock(Node::class);
		$file2->method('getStorage')->willReturn($storage);
		$file2->method('getPath')->willReturn('/testuser/files/file2.txt');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->willReturnMap([
			['file1.txt', $file1],
			['file2.txt', $file2],
		]);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/file1.txt', '/file1.txt'],
			['/testuser/files/file2.txt', '/file2.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('commitChanges')
			->with('/data/testuser/files', ['file1.txt', 'file2.txt'], 'Initial commit', 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully staged and committed changes.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->commitChanges(['file1.txt', 'file2.txt'], 'Initial commit');

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testCommitChangesFailsWithoutFilesOrMessage(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->commitChanges();

		$this->assertEquals('error', $response->getData()['status']);
	}

	public function testCommitChangesFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->commitChanges(['file1.txt'], 'Initial commit');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testGetSnapshotsSucceedsWithValidFilePath(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);

		$file = $this->createMock(Node::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getPath')->willReturn('/testuser/files/file1.txt');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('file1.txt')->willReturn($file);
		$userFolder->method('getRelativePath')->with('/testuser/files/file1.txt')->willReturn('/file1.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$snapshot = new Snapshot();
		$snapshot->setId(1);
		$snapshot->setCommitHash('abc123');
		$snapshot->setMessage('Initial commit');
		$snapshot->setStatus('committed');
		$snapshot->setCreatedAt(1000);
		$snapshot->setParentSnapshotId(null);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('getSnapshotsForFile')
			->with('testuser', 'file1.txt')
			->willReturn([$snapshot]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->getSnapshots('file1.txt');

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals([
			[
				'id' => 1,
				'commitHash' => 'abc123',
				'message' => 'Initial commit',
				'status' => 'committed',
				'createdAt' => 1000,
				'parentSnapshotId' => null,
			],
		], $response->getData()['snapshots']);
	}

	public function testGetSnapshotsFailsWithoutFilePath(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->getSnapshots();

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testGetSnapshotsFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->getSnapshots('file1.txt');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testRollbackSnapshotSucceedsWithValidFileAndSnapshotId(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$file = $this->createMock(Node::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getPath')->willReturn('/testuser/files/file1.txt');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('file1.txt')->willReturn($file);
		$userFolder->method('getRelativePath')->with('/testuser/files/file1.txt')->willReturn('/file1.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('rollbackToSnapshot')
			->with('/data/testuser/files', 'file1.txt', 1, 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully rolled back file1.txt to the selected snapshot.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->rollbackSnapshot('file1.txt', 1);

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testRollbackSnapshotFailsWithoutFileOrSnapshotId(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->rollbackSnapshot();

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testRollbackSnapshotFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->rollbackSnapshot('file1.txt', 1);

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testGetDirectoriesSucceedsWithLoggedInUser(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$rootFolder = $this->createMock(IRootFolder::class);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => '/', 'files' => ['readme.txt']],
				['path' => 'folder', 'files' => ['folder/a.txt']],
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->getDirectories();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals([
			['path' => '/', 'files' => ['readme.txt']],
			['path' => 'folder', 'files' => ['folder/a.txt']],
		], $response->getData()['directories']);
	}

	public function testGetDirectoriesFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->getDirectories();

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}
}
