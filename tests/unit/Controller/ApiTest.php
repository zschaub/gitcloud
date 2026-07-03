<?php

declare(strict_types=1);

namespace Controller;

use OCA\GitCloud\AppInfo\Application;
use OCA\GitCloud\Controller\ApiController;
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
			['/testuser/files/file1.txt', 'file1.txt'],
			['/testuser/files/file2.txt', 'file2.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('commitChanges')
			->with('/data/testuser/files', ['file1.txt', 'file2.txt'], 'Initial commit')
			->willReturn([
				'success' => true,
				'message' => 'Successfully staged and committed changes.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->commitChanges([
			'files' => ['file1.txt', 'file2.txt'],
			'message' => 'Initial commit',
		]);

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testCommitChangesFailsWithoutFilesOrMessage(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->commitChanges([]);

		$this->assertEquals('error', $response->getData()['status']);
	}

	public function testCommitChangesFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService);

		$response = $controller->commitChanges([
			'files' => ['file1.txt'],
			'message' => 'Initial commit',
		]);

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}
}
