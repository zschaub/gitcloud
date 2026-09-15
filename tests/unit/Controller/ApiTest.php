<?php

declare(strict_types=1);

namespace Controller;

use OCA\GitCloud\AppInfo\Application;
use OCA\GitCloud\Controller\ApiController;
use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Service\GitStaticBinaryService;
use OCA\GitCloud\Service\VcsService;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\Cache\IUpdater;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	/**
	 * Default max-file-size (100MB, block mode) app config mock, used by every test
	 * that doesn't specifically exercise the file-size enforcement behavior.
	 */
	private function defaultAppConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(100);
		$appConfig->method('getValueString')->willReturn('block');

		return $appConfig;
	}

	/**
	 * Default GitStaticBinaryService mock, used by every test that doesn't
	 * specifically exercise the git-binary-status/download endpoints.
	 */
	private function defaultGitStaticBinaryService(): GitStaticBinaryService {
		return $this->createMock(GitStaticBinaryService::class);
	}

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
		$file1->method('getId')->willReturn(101);

		$file2 = $this->createMock(Node::class);
		$file2->method('getStorage')->willReturn($storage);
		$file2->method('getPath')->willReturn('/testuser/files/file2.txt');
		$file2->method('getId')->willReturn(102);

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
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('commitChanges')
			->with('/data/testuser/files', [
				['path' => 'file1.txt', 'fileId' => 101],
				['path' => 'file2.txt', 'fileId' => 102],
			], 'Initial commit', 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully staged and committed changes.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['file1.txt', 'file2.txt'], 'Initial commit');

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testCommitChangesExpandsFolderIntoContainedFiles(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		// folder/
		//   a.txt
		//   sub/
		//     b.txt
		$fileA = $this->createMock(Node::class);
		$fileA->method('getStorage')->willReturn($storage);
		$fileA->method('getPath')->willReturn('/testuser/files/folder/a.txt');
		$fileA->method('getId')->willReturn(201);

		$fileB = $this->createMock(Node::class);
		$fileB->method('getStorage')->willReturn($storage);
		$fileB->method('getPath')->willReturn('/testuser/files/folder/sub/b.txt');
		$fileB->method('getId')->willReturn(202);

		$subFolder = $this->createMock(Folder::class);
		$subFolder->method('getStorage')->willReturn($storage);
		$subFolder->method('getDirectoryListing')->willReturn([$fileB]);

		$folder = $this->createMock(Folder::class);
		$folder->method('getStorage')->willReturn($storage);
		$folder->method('getDirectoryListing')->willReturn([$fileA, $subFolder]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('folder')->willReturn($folder);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/folder/a.txt', '/folder/a.txt'],
			['/testuser/files/folder/sub/b.txt', '/folder/sub/b.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('commitChanges')
			->with('/data/testuser/files', [
				['path' => 'folder/a.txt', 'fileId' => 201],
				['path' => 'folder/sub/b.txt', 'fileId' => 202],
			], 'Initial commit', 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully staged and committed changes.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['folder'], 'Initial commit');

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testCommitChangesFailsWhenFolderContainsNestedNonLocalMount(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$localStorage = $this->createMock(IStorage::class);
		$localStorage->method('isLocal')->willReturn(true);
		$localStorage->method('getLocalFile')->willReturn('/data/testuser/files');

		// folder/ is on local storage, but has an external-storage mount nested
		// inside it (e.g. SMB mounted at folder/mount) that is not local.
		$remoteStorage = $this->createMock(IStorage::class);
		$remoteStorage->method('isLocal')->willReturn(false);

		$fileA = $this->createMock(Node::class);
		$fileA->method('getStorage')->willReturn($localStorage);
		$fileA->method('getPath')->willReturn('/testuser/files/folder/a.txt');

		$mountedFolder = $this->createMock(Folder::class);
		$mountedFolder->method('getStorage')->willReturn($remoteStorage);
		$mountedFolder->method('getPath')->willReturn('/testuser/files/folder/mount');

		$folder = $this->createMock(Folder::class);
		$folder->method('getStorage')->willReturn($localStorage);
		$folder->method('getDirectoryListing')->willReturn([$fileA, $mountedFolder]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($localStorage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('folder')->willReturn($folder);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/folder/a.txt', '/folder/a.txt'],
			['/testuser/files/folder/mount', '/folder/mount'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('commitChanges');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['folder'], 'Initial commit');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertStringContainsString('folder/mount', $response->getData()['message']);
	}

	public function testCommitChangesFailsWhenFileIsOnAGroupFolderStorage(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('isLocal')->willReturn(true);
		$homeStorage->method('getId')->willReturn('home::testuser');
		$homeStorage->method('getLocalFile')->willReturn('/data/testuser/files');

		// A group folder is a separate mount inside files/ whose bytes live under
		// <data>/__groupfolders/<numericId>/ - local (so isLocal() passes), but
		// not inside the user's home storage, i.e. not in the Git working tree.
		$groupFolderStorage = $this->createMock(IStorage::class);
		$groupFolderStorage->method('isLocal')->willReturn(true);
		$groupFolderStorage->method('getId')->willReturn('local::/data/__groupfolders/3/');

		$file = $this->createMock(Node::class);
		$file->method('getStorage')->willReturn($groupFolderStorage);
		$file->method('getPath')->willReturn('/testuser/files/Team Folder/report.md');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('Team Folder/report.md')->willReturn($file);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/Team Folder/report.md', '/Team Folder/report.md'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('commitChanges');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['Team Folder/report.md'], 'Initial commit');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertStringContainsString('can only track files in your personal files', $response->getData()['message']);
		$this->assertStringContainsString('Team Folder/report.md', $response->getData()['message']);
	}

	public function testCommitChangesFailsWhenFolderContainsNestedDifferentStorageMount(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('isLocal')->willReturn(true);
		$homeStorage->method('getId')->willReturn('home::testuser');
		$homeStorage->method('getLocalFile')->willReturn('/data/testuser/files');

		// folder/ is on the user's own home storage, but has a received share
		// (local bytes, different storage) mounted inside it.
		$sharedStorage = $this->createMock(IStorage::class);
		$sharedStorage->method('isLocal')->willReturn(true);
		$sharedStorage->method('getId')->willReturn('shared::/folder/shared.txt');

		$fileA = $this->createMock(Node::class);
		$fileA->method('getStorage')->willReturn($homeStorage);
		$fileA->method('getPath')->willReturn('/testuser/files/folder/a.txt');

		$sharedFile = $this->createMock(Node::class);
		$sharedFile->method('getStorage')->willReturn($sharedStorage);
		$sharedFile->method('getPath')->willReturn('/testuser/files/folder/shared.txt');

		$folder = $this->createMock(Folder::class);
		$folder->method('getStorage')->willReturn($homeStorage);
		$folder->method('getDirectoryListing')->willReturn([$fileA, $sharedFile]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('folder')->willReturn($folder);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/folder/a.txt', '/folder/a.txt'],
			['/testuser/files/folder/shared.txt', '/folder/shared.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('commitChanges');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['folder'], 'Initial commit');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertStringContainsString('folder/shared.txt', $response->getData()['message']);
	}

	public function testCommitChangesFailsWithoutFilesOrMessage(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges();

		$this->assertEquals('error', $response->getData()['status']);
	}

	public function testCommitChangesAcceptsMessageThatIsLiteralZero(): void {
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
		$file1->method('getId')->willReturn(101);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('file1.txt')->willReturn($file1);
		$userFolder->method('getRelativePath')->with('/testuser/files/file1.txt')->willReturn('/file1.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('commitChanges')
			->with('/data/testuser/files', [['path' => 'file1.txt', 'fileId' => 101]], '0', 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully staged and committed changes.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['file1.txt'], '0');

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testCommitChangesFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

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
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getSnapshotsForFile')
			->with('testuser', 'file1.txt')
			->willReturn([$snapshot]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

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
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

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
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getSnapshots('file1.txt');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testGetSnapshotsFallsBackToPathHistoryWhenFileNoLongerExists(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('deleted.txt')->willThrowException(new NotFoundException());

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$snapshot = new Snapshot();
		$snapshot->setId(1);
		$snapshot->setCommitHash('abc123');
		$snapshot->setMessage('Auto-commit: deleted deleted.txt');
		$snapshot->setStatus('deleted');
		$snapshot->setCreatedAt(1000);
		$snapshot->setParentSnapshotId(null);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getSnapshotsForFile')
			->with('testuser', 'deleted.txt')
			->willReturn([$snapshot]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getSnapshots('deleted.txt');

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertCount(1, $response->getData()['snapshots']);
		$this->assertEquals('deleted', $response->getData()['snapshots'][0]['status']);
	}

	public function testGetSnapshotsFailsWhenFileNotFoundAndNoHistoryExists(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('nonexistent.txt')->willThrowException(new NotFoundException());

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getSnapshotsForFile')->with('testuser', 'nonexistent.txt')->willReturn([]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getSnapshots('nonexistent.txt');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
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

		$updater = $this->createMock(IUpdater::class);
		$updater->expects($this->once())->method('update')->with('files/file1.txt');
		$storage->method('getUpdater')->willReturn($updater);

		$file = $this->createMock(Node::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getPath')->willReturn('/testuser/files/file1.txt');
		$file->method('getInternalPath')->willReturn('files/file1.txt');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('file1.txt')->willReturn($file);
		$userFolder->method('getRelativePath')->with('/testuser/files/file1.txt')->willReturn('/file1.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('rollbackToSnapshot')
			->with('/data/testuser/files', 'file1.txt', 1, 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully rolled back file1.txt to the selected snapshot.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->rollbackSnapshot('file1.txt', 1);

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testRollbackSnapshotDoesNotRefreshCacheWhenRollbackFails(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$updater = $this->createMock(IUpdater::class);
		$updater->expects($this->never())->method('update');
		$storage->method('getUpdater')->willReturn($updater);

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
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('rollbackToSnapshot')
			->with('/data/testuser/files', 'file1.txt', 1, 'testuser')
			->willReturn([
				'success' => false,
				'message' => 'File is already at the selected snapshot.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->rollbackSnapshot('file1.txt', 1);

		$this->assertEquals('error', $response->getData()['status']);
	}

	public function testRollbackSnapshotRestoresDeletedFileViaUserFolderUpdater(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$updater = $this->createMock(IUpdater::class);
		$updater->expects($this->once())->method('update')->with('files/deleted.txt');
		$storage->method('getUpdater')->willReturn($updater);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('deleted.txt')->willThrowException(new NotFoundException());

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('rollbackToSnapshot')
			->with('/data/testuser/files', 'deleted.txt', 1, 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully rolled back deleted.txt to the selected snapshot.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->rollbackSnapshot('deleted.txt', 1);

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testRollbackSnapshotFailsWithoutFileOrSnapshotId(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

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
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

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

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturn(true);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => '/', 'files' => ['readme.txt']],
				['path' => 'folder', 'files' => ['folder/a.txt']],
			]);
		$vcsService->expects($this->once())
			->method('getFileStatuses')
			->with('/data/testuser/files', ['readme.txt', 'folder/a.txt'])
			->willReturn(['readme.txt' => 'Unchanged', 'folder/a.txt' => 'Modified']);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getDirectories();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals([
			['path' => '/', 'files' => [['path' => 'readme.txt', 'status' => 'Unchanged']]],
			['path' => 'folder', 'files' => [['path' => 'folder/a.txt', 'status' => 'Modified']]],
		], $response->getData()['directories']);
	}

	public function testGetDirectoriesOmitsFilesDeletedFromNextcloud(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturnMap([
			['readme.txt', true],
			['folder/deleted.txt', false],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => '/', 'files' => ['readme.txt']],
				['path' => 'folder', 'files' => ['folder/deleted.txt']],
			]);
		$vcsService->expects($this->once())
			->method('getFileStatuses')
			->with('/data/testuser/files', ['readme.txt'])
			->willReturn(['readme.txt' => 'Unchanged']);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getDirectories();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals([
			['path' => '/', 'files' => [['path' => 'readme.txt', 'status' => 'Unchanged']]],
		], $response->getData()['directories']);
	}

	public function testGetDirectoriesSurfacesDeletedFileInsteadOfDroppingIt(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturnMap([
			['folder/deleted.txt', false],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => 'folder', 'files' => ['folder/deleted.txt']],
			]);
		// Built once for every tracked path, rather than one lookup per missing file.
		$vcsService->expects($this->once())
			->method('getLatestStatusByFilePath')
			->with('testuser')
			->willReturn(['folder/deleted.txt' => 'deleted']);
		// The deleted path has nothing left in the working tree to shell out
		// `git status` for, so it must not be included in the batched call.
		$vcsService->expects($this->once())
			->method('getFileStatuses')
			->with('/data/testuser/files', [])
			->willReturn([]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getDirectories();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals([
			['path' => 'folder', 'files' => [['path' => 'folder/deleted.txt', 'status' => 'Deleted']]],
		], $response->getData()['directories']);
	}

	public function testGetDirectoriesSurfacesUncommittedFilesInTrackedSubdirectory(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		// folder/
		//   a.txt      (already committed)
		//   new.txt    (never committed - should surface as 'Uncommitted')
		//   sub/       (a subfolder - should be skipped, not recursed into)
		$fileA = $this->createMock(Node::class);
		$fileA->method('getStorage')->willReturn($storage);
		$fileA->method('getPath')->willReturn('/testuser/files/folder/a.txt');

		$newFile = $this->createMock(Node::class);
		$newFile->method('getStorage')->willReturn($storage);
		$newFile->method('getPath')->willReturn('/testuser/files/folder/new.txt');

		$subFolder = $this->createMock(Folder::class);
		$subFolder->method('getStorage')->willReturn($storage);

		$folder = $this->createMock(Folder::class);
		$folder->method('getStorage')->willReturn($storage);
		$folder->method('getDirectoryListing')->willReturn([$fileA, $newFile, $subFolder]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturnMap([
			['folder/a.txt', true],
		]);
		$userFolder->method('get')->with('folder')->willReturn($folder);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/folder/a.txt', '/folder/a.txt'],
			['/testuser/files/folder/new.txt', '/folder/new.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => 'folder', 'files' => ['folder/a.txt']],
			]);
		$vcsService->expects($this->once())
			->method('getFileStatuses')
			->with('/data/testuser/files', ['folder/a.txt'])
			->willReturn(['folder/a.txt' => 'Unchanged']);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getDirectories();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals([
			[
				'path' => 'folder',
				'files' => [
					['path' => 'folder/a.txt', 'status' => 'Unchanged'],
					['path' => 'folder/new.txt', 'status' => 'Uncommitted'],
				],
			],
		], $response->getData()['directories']);
	}

	public function testGetDirectoriesDoesNotSurfaceUncommittedFilesOnAnotherStorage(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$homeStorage = $this->createMock(IStorage::class);
		$homeStorage->method('isLocal')->willReturn(true);
		$homeStorage->method('getId')->willReturn('home::testuser');
		$homeStorage->method('getLocalFile')->willReturn('/data/testuser/files');

		$groupFolderStorage = $this->createMock(IStorage::class);
		$groupFolderStorage->method('isLocal')->willReturn(true);
		$groupFolderStorage->method('getId')->willReturn('local::/data/__groupfolders/3/');

		// folder/
		//   a.txt       (already committed, on the home storage)
		//   shared.txt  (never committed, on another storage - can never be
		//                committed, so it must not surface as 'Uncommitted')
		$fileA = $this->createMock(Node::class);
		$fileA->method('getStorage')->willReturn($homeStorage);
		$fileA->method('getPath')->willReturn('/testuser/files/folder/a.txt');

		$mountedFile = $this->createMock(Node::class);
		$mountedFile->method('getStorage')->willReturn($groupFolderStorage);
		$mountedFile->method('getPath')->willReturn('/testuser/files/folder/shared.txt');

		$folder = $this->createMock(Folder::class);
		$folder->method('getStorage')->willReturn($homeStorage);
		$folder->method('getDirectoryListing')->willReturn([$fileA, $mountedFile]);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($homeStorage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturnMap([
			['folder/a.txt', true],
		]);
		$userFolder->method('get')->with('folder')->willReturn($folder);
		$userFolder->method('getRelativePath')->willReturnMap([
			['/testuser/files/folder/a.txt', '/folder/a.txt'],
			['/testuser/files/folder/shared.txt', '/folder/shared.txt'],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => 'folder', 'files' => ['folder/a.txt']],
			]);
		$vcsService->method('getFileStatuses')
			->with('/data/testuser/files', ['folder/a.txt'])
			->willReturn(['folder/a.txt' => 'Unchanged']);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getDirectories();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals([
			[
				'path' => 'folder',
				'files' => [
					['path' => 'folder/a.txt', 'status' => 'Unchanged'],
				],
			],
		], $response->getData()['directories']);
	}

	public function testGetDirectoriesDoesNotSurfaceUncommittedFilesAtRepositoryRoot(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturnMap([
			['readme.txt', true],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => '/', 'files' => ['readme.txt']],
			]);
		$vcsService->expects($this->once())
			->method('getFileStatuses')
			->with('/data/testuser/files', ['readme.txt'])
			->willReturn(['readme.txt' => 'Unchanged']);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getDirectories();

		// $userFolder->get() is never stubbed for '/', so if the root directory were
		// (incorrectly) passed to findUncommittedFiles, the mock's default null return
		// would fail the `instanceof Folder` check anyway - this asserts the intended
		// behavior (root is skipped entirely) rather than relying on that side effect.
		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals([
			['path' => '/', 'files' => [['path' => 'readme.txt', 'status' => 'Unchanged']]],
		], $response->getData()['directories']);
	}

	public function testGetDirectoriesFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getDirectories();

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testGetStatusReturnsWholeRepositoryStatsWithoutDirectoryParam(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturnMap([
			['readme.txt', true],
			['folder/a.txt', true],
			['folder/b.txt', true],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => '/', 'files' => ['readme.txt']],
				['path' => 'folder', 'files' => ['folder/a.txt', 'folder/b.txt']],
			]);
		$vcsService->expects($this->once())
			->method('getDirectoryStatus')
			->with('/data/testuser/files', ['readme.txt', 'folder/a.txt', 'folder/b.txt'])
			->willReturn([
				'success' => true,
				'totalSizeBytes' => 2 * 1024 * 1024,
				'gitStatus' => 'Clean',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getStatus();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals(3, $response->getData()['fileCount']);
		$this->assertEquals(2, $response->getData()['dirCount']);
		$this->assertEquals(2.0, $response->getData()['totalSizeMb']);
		$this->assertEquals('Clean', $response->getData()['gitStatus']);
	}

	public function testGetStatusExcludesDirectoriesWithAllFilesDeletedFromDirCount(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturnMap([
			['readme.txt', true],
			['deleted-folder/gone.txt', false],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => '/', 'files' => ['readme.txt']],
				['path' => 'deleted-folder', 'files' => ['deleted-folder/gone.txt']],
			]);
		$vcsService->expects($this->once())
			->method('getDirectoryStatus')
			->with('/data/testuser/files', ['readme.txt'])
			->willReturn([
				'success' => true,
				'totalSizeBytes' => 1024,
				'gitStatus' => 'Clean',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getStatus();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals(1, $response->getData()['fileCount']);
		$this->assertEquals(1, $response->getData()['dirCount']);
	}

	public function testGetStatusReturnsEmptyStatsWhenNothingCommitted(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([]);
		$vcsService->expects($this->once())
			->method('getDirectoryStatus')
			->with('/data/testuser/files', [])
			->willReturn([
				'success' => true,
				'totalSizeBytes' => 0,
				'gitStatus' => 'Uninitialized',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getStatus();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals(0, $response->getData()['fileCount']);
		$this->assertEquals(0, $response->getData()['dirCount']);
		$this->assertEquals(0.0, $response->getData()['totalSizeMb']);
		$this->assertEquals('Uninitialized', $response->getData()['gitStatus']);
	}

	public function testGetStatusScopesStatsToDirectoryWhenGiven(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->willReturnMap([
			['folder/a.txt', true],
			['folder/deleted.txt', false],
		]);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => '/', 'files' => ['readme.txt']],
				['path' => 'folder', 'files' => ['folder/a.txt', 'folder/deleted.txt']],
			]);
		$vcsService->expects($this->once())
			->method('getDirectoryStatus')
			->with('/data/testuser/files', ['folder/a.txt'])
			->willReturn([
				'success' => true,
				'totalSizeBytes' => 512 * 1024,
				'gitStatus' => 'Modified',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getStatus('folder');

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals(1, $response->getData()['fileCount']);
		$this->assertEquals(0, $response->getData()['dirCount']);
		$this->assertEquals(0.5, $response->getData()['totalSizeMb']);
		$this->assertEquals('Modified', $response->getData()['gitStatus']);
	}

	public function testGetStatusRoundsSmallDirectorySizeToTwoDecimalPlacesInsteadOfZero(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('nodeExists')->with('folder/small.txt')->willReturn(true);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getCommittedDirectories')
			->with('testuser')
			->willReturn([
				['path' => 'folder', 'files' => ['folder/small.txt']],
			]);
		$vcsService->expects($this->once())
			->method('getDirectoryStatus')
			->with('/data/testuser/files', ['folder/small.txt'])
			->willReturn([
				'success' => true,
				// ~48.8 KB - rounds to 0.0 MB at one decimal place, but should
				// still show as a nonzero size at two.
				'totalSizeBytes' => 50000,
				'gitStatus' => 'Clean',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getStatus('folder');

		$this->assertEquals(0.05, $response->getData()['totalSizeMb']);
	}

	public function testGetStatusFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->getStatus();

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testCommitChangesRejectsFileOverLimitInBlockMode(): void {
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
		$file1->method('getPath')->willReturn('/testuser/files/big.txt');
		$file1->method('getSize')->willReturn(200 * 1024 * 1024);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('big.txt')->willReturn($file1);
		$userFolder->method('getRelativePath')->with('/testuser/files/big.txt')->willReturn('/big.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('commitChanges');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(100);
		$appConfig->method('getValueString')->willReturn('block');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $appConfig, $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['big.txt'], 'Initial commit');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertStringContainsString('big.txt', $response->getData()['message']);
		$this->assertStringContainsString('100 MB', $response->getData()['message']);
	}

	public function testCommitChangesReturnsWarningWithoutCommittingWhenFileOverLimitInWarnModeUnconfirmed(): void {
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
		$file1->method('getPath')->willReturn('/testuser/files/big.txt');
		$file1->method('getSize')->willReturn(200 * 1024 * 1024);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('big.txt')->willReturn($file1);
		$userFolder->method('getRelativePath')->with('/testuser/files/big.txt')->willReturn('/big.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('commitChanges');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(100);
		$appConfig->method('getValueString')->willReturn('warn');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $appConfig, $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['big.txt'], 'Initial commit');

		$this->assertEquals('warning', $response->getData()['status']);
		$this->assertEquals(['big.txt'], $response->getData()['warnings']);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testCommitChangesCommitsOverLimitFileInWarnModeWhenConfirmed(): void {
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
		$file1->method('getPath')->willReturn('/testuser/files/big.txt');
		$file1->method('getSize')->willReturn(200 * 1024 * 1024);
		$file1->method('getId')->willReturn(301);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('big.txt')->willReturn($file1);
		$userFolder->method('getRelativePath')->with('/testuser/files/big.txt')->willReturn('/big.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('commitChanges')
			->with('/data/testuser/files', [['path' => 'big.txt', 'fileId' => 301]], 'Initial commit', 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully staged and committed changes.',
			]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(100);
		$appConfig->method('getValueString')->willReturn('warn');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $appConfig, $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['big.txt'], 'Initial commit', confirmed: true);

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals(['big.txt'], $response->getData()['warnings']);
	}

	public function testCommitChangesStillRejectsFileOverLimitInBlockModeEvenWhenConfirmed(): void {
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
		$file1->method('getPath')->willReturn('/testuser/files/big.txt');
		$file1->method('getSize')->willReturn(200 * 1024 * 1024);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('big.txt')->willReturn($file1);
		$userFolder->method('getRelativePath')->with('/testuser/files/big.txt')->willReturn('/big.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('commitChanges');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(100);
		$appConfig->method('getValueString')->willReturn('block');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $appConfig, $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['big.txt'], 'Initial commit', confirmed: true);

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertStringContainsString('big.txt', $response->getData()['message']);
	}

	public function testCommitChangesSucceedsWhenAllFilesUnderLimit(): void {
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
		$file1->method('getSize')->willReturn(1024);
		$file1->method('getId')->willReturn(101);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');
		$userFolder->method('get')->with('file1.txt')->willReturn($file1);
		$userFolder->method('getRelativePath')->with('/testuser/files/file1.txt')->willReturn('/file1.txt');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('commitChanges')
			->with('/data/testuser/files', [['path' => 'file1.txt', 'fileId' => 101]], 'Initial commit', 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'Successfully staged and committed changes.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->commitChanges(['file1.txt'], 'Initial commit');

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertArrayNotHasKey('warnings', $response->getData());
	}

	public function testSaveAdminSettingsPersistsValues(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('setValueInt')
			->with(Application::APP_ID, 'max_file_size_mb', 50);

		$setStringCalls = [];
		$appConfig->expects($this->exactly(2))
			->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value) use (&$setStringCalls): bool {
				$setStringCalls[$key] = $value;
				return true;
			});

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $appConfig, $this->defaultGitStaticBinaryService());

		$response = $controller->saveAdminSettings(50, 'warn', 'static');

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertSame(['enforcement_mode' => 'warn', 'git_binary_mode' => 'static'], $setStringCalls);
	}

	public function testSaveAdminSettingsRejectsInvalidEnforcementMode(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueInt');
		$appConfig->expects($this->never())->method('setValueString');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $appConfig, $this->defaultGitStaticBinaryService());

		$response = $controller->saveAdminSettings(50, 'foo');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testSaveAdminSettingsRejectsInvalidGitBinaryMode(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueInt');
		$appConfig->expects($this->never())->method('setValueString');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $appConfig, $this->defaultGitStaticBinaryService());

		$response = $controller->saveAdminSettings(50, 'block', 'bogus');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testSaveAdminSettingsRejectsNonPositiveMaxFileSize(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->never())->method('setValueInt');
		$appConfig->expects($this->never())->method('setValueString');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $appConfig, $this->defaultGitStaticBinaryService());

		$response = $controller->saveAdminSettings(0, 'block');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testGetGitBinaryStatusReturnsCombinedStatus(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getGitBinaryStatus')->willReturn([
			'mode' => 'auto',
			'systemGitAvailable' => true,
			'staticGitAvailable' => false,
			'resolvedBinary' => 'system',
		]);

		$gitStaticBinaryService = $this->createMock(GitStaticBinaryService::class);
		$gitStaticBinaryService->method('getStatus')->willReturn([
			'architecture' => 'amd64',
			'installedVersion' => null,
			'pinnedVersion' => 'v2.55.0-1',
			'updateAvailable' => false,
		]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $gitStaticBinaryService);

		$response = $controller->getGitBinaryStatus();
		$data = $response->getData();

		$this->assertEquals('success', $data['status']);
		$this->assertSame('auto', $data['mode']);
		$this->assertTrue($data['systemGitAvailable']);
		$this->assertFalse($data['staticGitAvailable']);
		$this->assertSame('system', $data['resolvedBinary']);
		$this->assertSame('amd64', $data['architecture']);
		$this->assertNull($data['installedVersion']);
		$this->assertSame('v2.55.0-1', $data['pinnedVersion']);
		$this->assertFalse($data['updateAvailable']);
	}

	public function testGetGitBinaryStatusReportsUpdateAvailable(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('getGitBinaryStatus')->willReturn([
			'mode' => 'static',
			'systemGitAvailable' => false,
			'staticGitAvailable' => true,
			'resolvedBinary' => 'static',
		]);

		$gitStaticBinaryService = $this->createMock(GitStaticBinaryService::class);
		$gitStaticBinaryService->method('getStatus')->willReturn([
			'architecture' => 'amd64',
			'installedVersion' => 'v2.54.0-1',
			'pinnedVersion' => 'v2.55.0-1',
			'updateAvailable' => true,
		]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $gitStaticBinaryService);

		$response = $controller->getGitBinaryStatus();
		$data = $response->getData();

		$this->assertSame('v2.54.0-1', $data['installedVersion']);
		$this->assertSame('v2.55.0-1', $data['pinnedVersion']);
		$this->assertTrue($data['updateAvailable']);
	}

	public function testDownloadStaticGitReturnsSuccessResponse(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$gitStaticBinaryService = $this->createMock(GitStaticBinaryService::class);
		$gitStaticBinaryService->method('downloadForCurrentArchitecture')->willReturn([
			'success' => true,
			'message' => 'Downloaded static git v2.55.0-1 for amd64.',
		]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $gitStaticBinaryService);

		$response = $controller->downloadStaticGit();

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testDownloadStaticGitReturnsErrorResponseWhenDownloadFails(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$gitStaticBinaryService = $this->createMock(GitStaticBinaryService::class);
		$gitStaticBinaryService->method('downloadForCurrentArchitecture')->willReturn([
			'success' => false,
			'message' => 'Checksum verification failed.',
		]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $gitStaticBinaryService);

		$response = $controller->downloadStaticGit();

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testDeleteHistoryCallsVcsServiceWithResolvedRepositoryPathAndUser(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('deleteHistory')
			->with('/data/testuser/files', 'testuser')
			->willReturn([
				'success' => true,
				'message' => 'All commit history has been permanently deleted.',
			]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->deleteHistory();

		$this->assertEquals('success', $response->getData()['status']);
	}

	public function testDeleteHistoryFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->deleteHistory();

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testDownloadHistoryBackupReturnsStreamResponseForResolvedRepository(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$backupPath = sys_get_temp_dir() . '/gitcloud-test-backup-' . uniqid() . '.tar.gz';
		file_put_contents($backupPath, 'fake-archive-contents');

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('createHistoryBackup')
			->with('/data/testuser/files', 'testuser')
			->willReturn(['success' => true, 'path' => $backupPath]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->downloadHistoryBackup();

		$this->assertInstanceOf(StreamResponse::class, $response);
		$this->assertEquals(200, $response->getStatus());

		unlink($backupPath);
	}

	public function testDownloadHistoryBackupFailsWhenVcsServiceReportsFailure(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn(true);
		$storage->method('getLocalFile')->willReturn('/data/testuser/files');

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('createHistoryBackup')->willReturn([
			'success' => false,
			'message' => 'No commit history has been created yet.',
		]);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->downloadHistoryBackup();

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testDownloadHistoryBackupFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->downloadHistoryBackup();

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testUntrackFileCallsVcsServiceWithUserAndPath(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('untrackFile')
			->with('testuser', 'folder/a.txt')
			->willReturn(['success' => true, 'message' => 'Stopped tracking folder/a.txt.']);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->untrackFile('folder/a.txt');

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testUntrackFileFailsWithoutPath(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('untrackFile');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->untrackFile('');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testUntrackFileFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('untrackFile');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->untrackFile('folder/a.txt');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}

	public function testUntrackFileReturnsErrorStatusWhenServiceReportsFailure(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->method('untrackFile')->willReturn(['success' => false, 'message' => 'not tracked.']);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->untrackFile('untracked.txt');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testUntrackDirectoryCallsVcsServiceWithUserAndPath(): void {
		$request = $this->createMock(IRequest::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$userFolder = $this->createMock(Folder::class);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('testuser')->willReturn($userFolder);

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->once())
			->method('untrackDirectory')
			->with('testuser', 'docs')
			->willReturn(['success' => true, 'message' => 'Stopped tracking 2 file(s) in docs.']);

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->untrackDirectory('docs');

		$this->assertEquals('success', $response->getData()['status']);
		$this->assertEquals(200, $response->getStatus());
	}

	public function testUntrackDirectoryFailsWithoutPath(): void {
		$request = $this->createMock(IRequest::class);
		$userSession = $this->createMock(IUserSession::class);
		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('untrackDirectory');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->untrackDirectory('');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(400, $response->getStatus());
	}

	public function testUntrackDirectoryFailsWhenNoUserIsLoggedIn(): void {
		$request = $this->createMock(IRequest::class);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$rootFolder = $this->createMock(IRootFolder::class);
		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn('/data/testuser/files');
		$vcsService->expects($this->never())->method('untrackDirectory');

		$controller = new ApiController(Application::APP_ID, $request, $userSession, $rootFolder, $vcsService, $this->defaultAppConfig(), $this->defaultGitStaticBinaryService());

		$response = $controller->untrackDirectory('docs');

		$this->assertEquals('error', $response->getData()['status']);
		$this->assertEquals(401, $response->getStatus());
	}
}
