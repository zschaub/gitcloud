<?php

declare(strict_types=1);

namespace OCA\GitCloud\Service;

use OCA\GitCloud\AppInfo\Application;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Downloads and verifies the pinned gitcloud-git-static release binary (see
 * build/git-static.json) for the server's own architecture, triggered live from a
 * Settings > Administration > GitCloud request, so an admin can opt into static git
 * without needing shell access to run `composer fetch-git-static` themselves.
 *
 * Shells out to curl/tar rather than a PHP HTTP client or ext-phar, mirroring both
 * build/fetch-git-static.php's own documented rationale and VcsService's own
 * shell-out-to-real-binaries approach elsewhere in this codebase, and uses proc_open
 * with an argv array (as VcsService::runGit() already does) rather than a shell
 * string, so no argument escaping is needed even though every value passed here
 * actually comes from this app's own checked-in git-static.json, not user input.
 */
class GitStaticBinaryService {
	/**
	 * Written alongside bin/<arch>/git, holding the exact release tag (e.g.
	 * "v2.55.0-1") that was actually downloaded and installed there - not to be
	 * confused with build/git-static.json's pinned tag, which is whatever the
	 * *currently installed version of the GitCloud app* wants installed. The two
	 * only ever disagree after GitCloud itself is upgraded to a version that bumped
	 * the pin while an older binary is still sitting in bin/, which is exactly the
	 * "update available" case getStatus() detects below.
	 */
	private const VERSION_MARKER_FILENAME = 'git.version';

	public function __construct(
		private LoggerInterface $logger,
		private IAppManager $appManager,
	) {
	}

	/**
	 * @return array{architecture: string|null, installedVersion: string|null, pinnedVersion: string|null, updateAvailable: bool}
	 */
	public function getStatus(): array {
		$architecture = GitArchitecture::detect();

		try {
			$appPath = $this->appManager->getAppPath(Application::APP_ID);
		} catch (AppPathNotFoundException) {
			return ['architecture' => $architecture, 'installedVersion' => null, 'pinnedVersion' => null, 'updateAvailable' => false];
		}

		$pin = $this->loadPin($appPath);
		$pinnedVersion = $pin['tag'] ?? null;

		// Only meaningful once a binary is actually installed - a missing binary is
		// "not downloaded" (the existing Download button), not "update available".
		$staticGitPresent = $architecture !== null && BundledGitBinary::path($appPath, $architecture) !== false;
		$installedVersion = $staticGitPresent ? $this->installedVersion($appPath, $architecture) : null;

		return [
			'architecture' => $architecture,
			'installedVersion' => $installedVersion,
			'pinnedVersion' => $pinnedVersion,
			// A binary installed before this version-tracking existed (e.g. via an
			// older GitCloud release, or a manual `composer fetch-git-static` run
			// predating the marker file below) has no recorded installedVersion -
			// treated as "unknown", not "update available", to avoid nagging every
			// existing install to redownload for no functional reason.
			'updateAvailable' => $installedVersion !== null && $pinnedVersion !== null && $installedVersion !== $pinnedVersion,
		];
	}

	/**
	 * Downloads, checksum-verifies, and installs the static git binary for this
	 * server's own architecture into bin/<arch>/git inside the app's install
	 * directory - the same location VcsService::findBundledGitBinary() already knows
	 * to look for, so no further configuration is needed beyond selecting "static" (or
	 * leaving "auto") as the git binary mode.
	 * @return array{success: bool, message: string}
	 */
	public function downloadForCurrentArchitecture(): array {
		$architecture = GitArchitecture::detect();
		if ($architecture === null) {
			return ['success' => false, 'message' => 'A bundled static git binary is only available for Linux amd64/arm64 servers.'];
		}

		try {
			$appPath = $this->appManager->getAppPath(Application::APP_ID);
		} catch (AppPathNotFoundException) {
			return ['success' => false, 'message' => 'Could not resolve the GitCloud app install directory.'];
		}

		$pin = $this->loadPin($appPath);
		if ($pin === null) {
			return ['success' => false, 'message' => 'Could not read this app\'s bundled build/git-static.json release manifest.'];
		}

		$asset = $pin['assets'][$architecture] ?? null;
		if (!is_array($asset) || !isset($asset['file'], $asset['sha256'])) {
			return ['success' => false, 'message' => sprintf('No static git build is published for architecture "%s".', $architecture)];
		}

		$url = sprintf('https://github.com/%s/releases/download/%s/%s', $pin['repo'], $pin['tag'], $asset['file']);
		$workDir = sys_get_temp_dir() . '/gitcloud-git-static-' . bin2hex(random_bytes(8));
		mkdir($workDir, 0755, true);
		$archivePath = $workDir . '/' . $asset['file'];

		try {
			$downloadResult = $this->runProcess(['curl', '-fsSL', '-o', $archivePath, $url]);
			if (!$downloadResult['success'] || !is_file($archivePath)) {
				$this->logger->warning(sprintf('Failed to download static git binary: %s', $downloadResult['output']));
				return ['success' => false, 'message' => sprintf('Failed to download the static git binary: %s', $downloadResult['output'])];
			}

			$actualSha256 = hash_file('sha256', $archivePath);
			if ($actualSha256 === false || !hash_equals($asset['sha256'], $actualSha256)) {
				$this->logger->error('Downloaded static git binary failed checksum verification.');
				return ['success' => false, 'message' => 'Downloaded file failed checksum verification - aborted for safety.'];
			}

			$extractResult = $this->runProcess(['tar', '-xzf', $archivePath, '-C', $workDir]);
			if (!$extractResult['success']) {
				$this->logger->warning(sprintf('Failed to extract static git binary archive: %s', $extractResult['output']));
				return ['success' => false, 'message' => sprintf('Failed to extract the downloaded archive: %s', $extractResult['output'])];
			}

			// The tarball's top-level directory is named after the archive itself
			// minus its .tar.gz extension (see gitcloud-git-static's own packaging).
			$extractedDir = $workDir . '/' . basename($asset['file'], '.tar.gz');
			if (!is_dir($extractedDir) || !is_file($extractedDir . '/git')) {
				return ['success' => false, 'message' => 'Downloaded archive did not have the expected layout.'];
			}

			$binDir = BundledGitBinary::directoryFor($appPath, $architecture);
			if (!is_dir($binDir) && !mkdir($binDir, 0755, true) && !is_dir($binDir)) {
				return ['success' => false, 'message' => sprintf('Failed to create %s.', $binDir)];
			}

			$binaryPath = $binDir . '/git';
			// @-suppressed and checked via return value, not the emitted warning -
			// the same convention runProcess() below already uses for @proc_open.
			if (!@copy($extractedDir . '/git', $binaryPath)) {
				return ['success' => false, 'message' => sprintf('Failed to write the static git binary to %s. Check that the web server user can write to this app\'s directory.', $binaryPath)];
			}
			if (!@chmod($binaryPath, 0755)) {
				return ['success' => false, 'message' => sprintf('Failed to make %s executable.', $binaryPath)];
			}
			// Records exactly which pinned tag this install came from, so a later
			// getStatus() call can tell "up to date" apart from "installed before
			// GitCloud itself was upgraded to a version pinning something newer".
			if (@file_put_contents($binDir . '/' . self::VERSION_MARKER_FILENAME, $pin['tag']) === false) {
				return ['success' => false, 'message' => sprintf('Installed the static git binary, but failed to write its version marker to %s.', $binDir)];
			}
			// Carried along for GPL-2.0 compliance and build provenance, not read by
			// VcsService at runtime - only bin/<arch>/git itself is ever executed.
			// Best-effort: a missing license file shouldn't fail an otherwise-successful install.
			foreach (['COPYING', 'BUILD.md'] as $extra) {
				if (is_file($extractedDir . '/' . $extra)) {
					@copy($extractedDir . '/' . $extra, $binDir . '/' . $extra);
				}
			}

			$this->logger->info(sprintf('Downloaded and installed static git binary (%s) for %s.', $pin['tag'], $architecture));
			return [
				'success' => true,
				'message' => sprintf('Downloaded static git %s for %s.', $pin['tag'], $architecture),
			];
		} finally {
			$this->runProcess(['rm', '-rf', $workDir]);
		}
	}

	/**
	 * @return array{repo?: string, tag?: string, assets?: array<string, array{file: string, sha256: string}>}|null
	 */
	private function loadPin(string $appPath): ?array {
		$pinPath = $appPath . '/build/git-static.json';
		if (!is_file($pinPath)) {
			return null;
		}

		$contents = file_get_contents($pinPath);
		if ($contents === false) {
			return null;
		}

		$decoded = json_decode($contents, true);
		return is_array($decoded) ? $decoded : null;
	}

	private function installedVersion(string $appPath, string $architecture): ?string {
		$markerPath = BundledGitBinary::directoryFor($appPath, $architecture) . '/' . self::VERSION_MARKER_FILENAME;
		if (!is_file($markerPath)) {
			return null;
		}

		$contents = file_get_contents($markerPath);
		if ($contents === false || trim($contents) === '') {
			return null;
		}

		return trim($contents);
	}

	/**
	 * Runs an external process without invoking a shell, mirroring
	 * VcsService::runGit()'s own proc_open pattern for consistency and to avoid any
	 * need for argument escaping.
	 * @param string[] $args
	 * @return array{success: bool, output: string}
	 */
	private function runProcess(array $args): array {
		error_clear_last();
		$process = @proc_open(
			$args,
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
		);

		if (!is_resource($process)) {
			$lastError = error_get_last();
			return ['success' => false, 'output' => $lastError['message'] ?? 'Unable to start the process.'];
		}

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		return ['success' => $exitCode === 0, 'output' => rtrim($stdout . "\n" . $stderr)];
	}
}
