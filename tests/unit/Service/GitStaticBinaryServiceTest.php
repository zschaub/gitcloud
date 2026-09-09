<?php

declare(strict_types=1);

namespace Service;

use OCA\GitCloud\Service\GitArchitecture;
use OCA\GitCloud\Service\GitStaticBinaryService;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * These tests fake only "curl" on PATH (a script that ignores the requested URL and
 * copies a locally-built fixture archive to the requested output path instead), the
 * same convention VcsServiceTest already uses to fake "git" on PATH - proving
 * GitStaticBinaryService actually drives real proc_open/tar/checksum logic rather than
 * assuming success, without making a real network request in a unit test. "tar" and
 * "rm" are the real system binaries, since extracting/cleaning up a locally-built
 * fixture archive doesn't require faking them.
 */
final class GitStaticBinaryServiceTest extends TestCase {
	private ?string $tmpAppPath = null;
	private ?string $tmpWorkDir = null;
	private ?string $originalPath = null;

	protected function tearDown(): void {
		if ($this->tmpAppPath !== null && is_dir($this->tmpAppPath)) {
			exec('rm -rf ' . escapeshellarg($this->tmpAppPath));
		}
		$this->tmpAppPath = null;

		if ($this->tmpWorkDir !== null && is_dir($this->tmpWorkDir)) {
			exec('rm -rf ' . escapeshellarg($this->tmpWorkDir));
		}
		$this->tmpWorkDir = null;

		if ($this->originalPath !== null) {
			putenv('PATH=' . $this->originalPath);
			$this->originalPath = null;
		}
	}

	private function currentArch(): string {
		$arch = GitArchitecture::detect();
		if ($arch === null) {
			$this->markTestSkipped('Not running on a supported (Linux amd64/arm64) test architecture.');
		}

		return $arch;
	}

	/**
	 * Builds a real gzipped tarball, at $workDir/<archiveBaseName>.tar.gz, whose only
	 * content is a top-level <archiveBaseName>/git file - the same layout
	 * gitcloud-git-static's real releases use - and returns [archivePath, sha256].
	 * @return array{0: string, 1: string}
	 */
	private function buildFixtureArchive(string $workDir, string $archiveBaseName): array {
		$sourceDir = $workDir . '/source/' . $archiveBaseName;
		mkdir($sourceDir, 0755, true);
		file_put_contents($sourceDir . '/git', "#!/bin/sh\necho FIXTURE_GIT\nexit 0\n");
		chmod($sourceDir . '/git', 0755);

		$archivePath = $workDir . '/' . $archiveBaseName . '.tar.gz';
		exec(sprintf(
			'tar -czf %s -C %s %s',
			escapeshellarg($archivePath),
			escapeshellarg($workDir . '/source'),
			escapeshellarg($archiveBaseName),
		), result_code: $exitCode);
		if ($exitCode !== 0) {
			throw new \RuntimeException('Failed to build fixture archive for test setup.');
		}

		$sha256 = hash_file('sha256', $archivePath);
		if ($sha256 === false) {
			throw new \RuntimeException('Failed to hash fixture archive for test setup.');
		}

		return [$archivePath, $sha256];
	}

	/**
	 * Prepends a temp directory containing a fake "curl" executable to PATH, so
	 * GitStaticBinaryService's real proc_open(['curl', ...]) call resolves to it
	 * instead of (or absent) a real curl. The fake script ignores the requested URL
	 * entirely and just copies $fixtureArchivePath to whatever "-o" destination it was
	 * given, unconditionally succeeding.
	 */
	private function installFakeCurlCopying(string $fixtureArchivePath): void {
		$this->originalPath = (string)getenv('PATH');

		$binDir = sys_get_temp_dir() . '/gitcloud-test-curl-' . uniqid();
		mkdir($binDir, 0755, true);
		$this->tmpWorkDir ??= sys_get_temp_dir() . '/gitcloud-test-workdir-' . uniqid();

		$script = "#!/bin/sh\ncp " . escapeshellarg($fixtureArchivePath) . " \"\$3\"\nexit 0\n";
		file_put_contents($binDir . '/curl', $script);
		chmod($binDir . '/curl', 0755);

		putenv('PATH=' . $binDir . PATH_SEPARATOR . $this->originalPath);
	}

	private function installFakeCurlFailing(): void {
		$this->originalPath = (string)getenv('PATH');

		$binDir = sys_get_temp_dir() . '/gitcloud-test-curl-' . uniqid();
		mkdir($binDir, 0755, true);

		file_put_contents($binDir . '/curl', "#!/bin/sh\nexit 22\n");
		chmod($binDir . '/curl', 0755);

		putenv('PATH=' . $binDir . PATH_SEPARATOR . $this->originalPath);
	}

	/**
	 * Creates a fake app install directory containing build/git-static.json pinned to
	 * a single asset (for the current test architecture) with the given file name and
	 * checksum, mirroring the real build/git-static.json this class reads at runtime.
	 */
	private function createFakeAppPath(string $archiveBaseName, string $sha256, string $tag = 'v9.9.9-test'): string {
		$arch = $this->currentArch();

		$this->tmpAppPath = sys_get_temp_dir() . '/gitcloud-test-app-' . uniqid();
		mkdir($this->tmpAppPath . '/build', 0755, true);

		$pin = [
			'repo' => 'zschaub/gitcloud-git-static',
			'tag' => $tag,
			'assets' => [
				$arch => [
					'file' => $archiveBaseName . '.tar.gz',
					'sha256' => $sha256,
				],
			],
		];
		file_put_contents($this->tmpAppPath . '/build/git-static.json', json_encode($pin, JSON_THROW_ON_ERROR));

		return $this->tmpAppPath;
	}

	/**
	 * Places a fake already-installed bin/<arch>/git under $appPath, optionally with a
	 * git.version marker recording which tag it was supposedly installed from - used to
	 * simulate a binary installed before the version-marker feature existed (no marker),
	 * or one installed under an older pin than build/git-static.json currently has
	 * (marker present but stale), without needing to actually download+extract anything.
	 */
	private function installFakeBundledBinary(string $appPath, string $arch, ?string $installedVersionMarker): void {
		$binDir = $appPath . '/bin/' . $arch;
		mkdir($binDir, 0755, true);
		file_put_contents($binDir . '/git', "#!/bin/sh\nexit 0\n");
		chmod($binDir . '/git', 0755);

		if ($installedVersionMarker !== null) {
			file_put_contents($binDir . '/git.version', $installedVersionMarker);
		}
	}

	public function testDownloadForCurrentArchitectureVerifiesAndInstallsBundledBinary(): void {
		$arch = $this->currentArch();

		$this->tmpWorkDir = sys_get_temp_dir() . '/gitcloud-test-workdir-' . uniqid();
		mkdir($this->tmpWorkDir, 0755, true);
		[$archivePath, $sha256] = $this->buildFixtureArchive($this->tmpWorkDir, 'git-test-' . $arch);

		$appPath = $this->createFakeAppPath('git-test-' . $arch, $sha256);
		$this->installFakeCurlCopying($archivePath);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$result = $service->downloadForCurrentArchitecture();

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('v9.9.9-test', $result['message']);

		$installedBinary = $appPath . '/bin/' . $arch . '/git';
		$this->assertFileExists($installedBinary);
		$this->assertTrue(is_executable($installedBinary));
		$this->assertStringContainsString('FIXTURE_GIT', file_get_contents($installedBinary));
	}

	public function testDownloadForCurrentArchitectureAbortsWithoutInstallingWhenChecksumMismatches(): void {
		$arch = $this->currentArch();

		$this->tmpWorkDir = sys_get_temp_dir() . '/gitcloud-test-workdir-' . uniqid();
		mkdir($this->tmpWorkDir, 0755, true);
		[$archivePath] = $this->buildFixtureArchive($this->tmpWorkDir, 'git-test-' . $arch);

		// Deliberately wrong checksum - a real sha256 hex string, just not the
		// fixture archive's actual one.
		$wrongSha256 = str_repeat('0', 64);
		$appPath = $this->createFakeAppPath('git-test-' . $arch, $wrongSha256);
		$this->installFakeCurlCopying($archivePath);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$result = $service->downloadForCurrentArchitecture();

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('checksum verification', $result['message']);
		$this->assertFileDoesNotExist($appPath . '/bin/' . $arch . '/git');
	}

	public function testDownloadForCurrentArchitectureFailsWithClearMessageWhenDownloadFails(): void {
		$arch = $this->currentArch();
		$appPath = $this->createFakeAppPath('git-test-' . $arch, str_repeat('0', 64));
		$this->installFakeCurlFailing();

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$result = $service->downloadForCurrentArchitecture();

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Failed to download', $result['message']);
		$this->assertFileDoesNotExist($appPath . '/bin/' . $arch . '/git');
	}

	public function testDownloadForCurrentArchitectureFailsWhenAppPathCannotBeResolved(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willThrowException(new AppPathNotFoundException());

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$result = $service->downloadForCurrentArchitecture();

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('install directory', $result['message']);
	}

	public function testGetStatusReportsStaticGitAbsentAndPinnedVersionBeforeDownload(): void {
		$arch = $this->currentArch();
		$appPath = $this->createFakeAppPath('git-test-' . $arch, str_repeat('0', 64));

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$status = $service->getStatus();

		$this->assertSame($arch, $status['architecture']);
		$this->assertFalse($status['staticGitPresent']);
		$this->assertNull($status['installedVersion']);
		$this->assertSame('v9.9.9-test', $status['pinnedVersion']);
		$this->assertFalse($status['updateAvailable']);
	}

	public function testGetStatusReportsStaticGitPresentAndUpToDateAfterSuccessfulDownload(): void {
		$arch = $this->currentArch();

		$this->tmpWorkDir = sys_get_temp_dir() . '/gitcloud-test-workdir-' . uniqid();
		mkdir($this->tmpWorkDir, 0755, true);
		[$archivePath, $sha256] = $this->buildFixtureArchive($this->tmpWorkDir, 'git-test-' . $arch);

		$appPath = $this->createFakeAppPath('git-test-' . $arch, $sha256);
		$this->installFakeCurlCopying($archivePath);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$downloadResult = $service->downloadForCurrentArchitecture();
		$this->assertTrue($downloadResult['success']);

		$status = $service->getStatus();
		$this->assertTrue($status['staticGitPresent']);
		$this->assertSame('v9.9.9-test', $status['installedVersion']);
		$this->assertSame('v9.9.9-test', $status['pinnedVersion']);
		$this->assertFalse($status['updateAvailable']);
	}

	public function testGetStatusReportsUpdateAvailableWhenInstalledVersionMarkerIsOlderThanPin(): void {
		$arch = $this->currentArch();
		$appPath = $this->createFakeAppPath('git-test-' . $arch, str_repeat('0', 64), tag: 'v2.0.0');
		$this->installFakeBundledBinary($appPath, $arch, 'v1.0.0');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$status = $service->getStatus();

		$this->assertTrue($status['staticGitPresent']);
		$this->assertSame('v1.0.0', $status['installedVersion']);
		$this->assertSame('v2.0.0', $status['pinnedVersion']);
		$this->assertTrue($status['updateAvailable']);
	}

	public function testGetStatusDoesNotReportUpdateAvailableWhenInstalledBinaryHasNoVersionMarker(): void {
		// Simulates a binary installed before version-tracking existed (an older
		// GitCloud release, or a manual `composer fetch-git-static` run predating the
		// marker file) - deliberately treated as "unknown", not "update available",
		// so it isn't nagged to redownload for no functional reason.
		$arch = $this->currentArch();
		$appPath = $this->createFakeAppPath('git-test-' . $arch, str_repeat('0', 64), tag: 'v2.0.0');
		$this->installFakeBundledBinary($appPath, $arch, null);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$status = $service->getStatus();

		$this->assertTrue($status['staticGitPresent']);
		$this->assertNull($status['installedVersion']);
		$this->assertFalse($status['updateAvailable']);
	}

	public function testDownloadForCurrentArchitectureClearsUpdateAvailableAfterUpdating(): void {
		$arch = $this->currentArch();
		$appPath = $this->createFakeAppPath('git-test-' . $arch, str_repeat('0', 64), tag: 'v2.0.0');
		$this->installFakeBundledBinary($appPath, $arch, 'v1.0.0');

		$this->tmpWorkDir = sys_get_temp_dir() . '/gitcloud-test-workdir-' . uniqid();
		mkdir($this->tmpWorkDir, 0755, true);
		[$archivePath, $sha256] = $this->buildFixtureArchive($this->tmpWorkDir, 'git-test-' . $arch);

		// The pin's checksum must match the fixture actually served by the fake curl,
		// so rewrite it now that the fixture's real hash is known.
		file_put_contents($appPath . '/build/git-static.json', json_encode([
			'repo' => 'zschaub/gitcloud-git-static',
			'tag' => 'v2.0.0',
			'assets' => [$arch => ['file' => 'git-test-' . $arch . '.tar.gz', 'sha256' => $sha256]],
		], JSON_THROW_ON_ERROR));
		$this->installFakeCurlCopying($archivePath);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('gitcloud')->willReturn($appPath);

		$logger = $this->createMock(LoggerInterface::class);
		$service = new GitStaticBinaryService($logger, $appManager);

		$this->assertTrue($service->getStatus()['updateAvailable']);

		$downloadResult = $service->downloadForCurrentArchitecture();
		$this->assertTrue($downloadResult['success']);

		$status = $service->getStatus();
		$this->assertSame('v2.0.0', $status['installedVersion']);
		$this->assertFalse($status['updateAvailable']);
	}
}
