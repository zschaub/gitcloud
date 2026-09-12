<?php

declare(strict_types=1);

namespace Migration;

use OCA\GitCloud\Migration\MoveGitDirectoryOutOfUserFiles;
use OCA\GitCloud\Service\VcsService;
use OCP\Files\Cache\IUpdater;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MoveGitDirectoryOutOfUserFilesTest extends TestCase {
	private ?string $tmpHomePath = null;

	protected function tearDown(): void {
		if ($this->tmpHomePath !== null && is_dir($this->tmpHomePath)) {
			exec('rm -rf ' . escapeshellarg($this->tmpHomePath));
		}
		$this->tmpHomePath = null;
	}

	/**
	 * Lays out a stand-in for a user's home directory the way Nextcloud's really is,
	 * and returns the working tree path (<home>/files).
	 */
	private function createHome(): string {
		$this->tmpHomePath = sys_get_temp_dir() . '/gitcloud-repair-test-' . uniqid();
		mkdir($this->tmpHomePath);
		mkdir($this->tmpHomePath . '/files');

		return $this->tmpHomePath . '/files';
	}

	/**
	 * @param string|false $repositoryPath What VcsService::resolveRepositoryPath() should report.
	 */
	private function buildStep(string|false $repositoryPath, ?IUpdater $updater = null): MoveGitDirectoryOutOfUserFiles {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getUpdater')->willReturn($updater ?? $this->createMock(IUpdater::class));

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getStorage')->willReturn($storage);
		$userFolder->method('getInternalPath')->willReturn('files');

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(function (\Closure $callback) use ($user): void {
			$callback($user);
		});

		$vcsService = $this->createMock(VcsService::class);
		$vcsService->method('resolveRepositoryPath')->willReturn($repositoryPath);
		$vcsService->method('resolveGitDirectory')->willReturnCallback(
			static fn (string $path): string => rtrim(dirname($path), '/') . '/' . VcsService::GIT_DIRECTORY_NAME,
		);

		return new MoveGitDirectoryOutOfUserFiles(
			$userManager,
			$rootFolder,
			$vcsService,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testRelocatesALegacyRepositoryOutOfTheUsersFilesDirectory(): void {
		$workingTree = $this->createHome();
		mkdir($workingTree . '/.git');
		file_put_contents($workingTree . '/.git/HEAD', "ref: refs/heads/master\n");
		file_put_contents($workingTree . '/keep-me.txt', 'untouched');

		$this->buildStep($workingTree)->run($this->createMock(IOutput::class));

		$this->assertDirectoryDoesNotExist($workingTree . '/.git');
		$this->assertDirectoryExists($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME);
		$this->assertSame("ref: refs/heads/master\n", file_get_contents($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME . '/HEAD'));
		// Working-tree files are never part of the move.
		$this->assertSame('untouched', file_get_contents($workingTree . '/keep-me.txt'));
	}

	public function testDropsTheStaleFileCacheEntryAfterRelocating(): void {
		$workingTree = $this->createHome();
		mkdir($workingTree . '/.git');

		$updater = $this->createMock(IUpdater::class);
		$updater->expects($this->once())->method('remove')->with('files/.git');

		$this->buildStep($workingTree, $updater)->run($this->createMock(IOutput::class));
	}

	public function testDoesNothingWhenThereIsNoLegacyRepository(): void {
		$workingTree = $this->createHome();

		$updater = $this->createMock(IUpdater::class);
		$updater->expects($this->never())->method('remove');

		$this->buildStep($workingTree, $updater)->run($this->createMock(IOutput::class));

		$this->assertDirectoryDoesNotExist($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME);
	}

	public function testLeavesBothAloneWhenARepositoryAlreadyExistsAtTheNewLocation(): void {
		$workingTree = $this->createHome();
		mkdir($workingTree . '/.git');
		file_put_contents($workingTree . '/.git/HEAD', 'legacy');
		mkdir($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME);
		file_put_contents($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME . '/HEAD', 'already relocated');

		$this->buildStep($workingTree)->run($this->createMock(IOutput::class));

		// Neither is clobbered - which one holds the history the user wants isn't
		// something the repair step can safely guess.
		$this->assertSame('legacy', file_get_contents($workingTree . '/.git/HEAD'));
		$this->assertSame('already relocated', file_get_contents($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME . '/HEAD'));
	}

	public function testSkipsUsersWhoseStorageIsNotLocal(): void {
		$workingTree = $this->createHome();
		mkdir($workingTree . '/.git');

		// resolveRepositoryPath() returns false for non-local storage, where GitCloud
		// never created a repository in the first place.
		$this->buildStep(false)->run($this->createMock(IOutput::class));

		$this->assertDirectoryExists($workingTree . '/.git');
		$this->assertDirectoryDoesNotExist($this->tmpHomePath . '/' . VcsService::GIT_DIRECTORY_NAME);
	}
}
