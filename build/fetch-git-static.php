<?php

declare(strict_types=1);

/**
 * Downloads the pinned gitcloud-git-static release binaries (see git-static.json in
 * this directory) into bin/<arch>/git at the app root, verifying each download against
 * its pinned sha256 checksum before it's ever placed where VcsService will look for it.
 *
 * Run explicitly via `composer fetch-git-static` - not wired into
 * post-install-cmd/post-update-cmd, since it needs outbound network access that a plain
 * `composer install` shouldn't silently require. Safe to skip entirely: GitCloud falls
 * back to a system-installed git on PATH when bin/<arch>/git isn't present.
 *
 * Shells out to curl/tar rather than using PHP's URL-fopen wrappers or ext-phar, for the
 * same reason VcsService itself shells out to git directly instead of a PHP library -
 * see README.md's Requirements section.
 */

$rootDir = dirname(__DIR__);
$pin = json_decode(file_get_contents(__DIR__ . '/git-static.json'), true, flags: JSON_THROW_ON_ERROR);

foreach ($pin['assets'] as $arch => $asset) {
	$url = sprintf('https://github.com/%s/releases/download/%s/%s', $pin['repo'], $pin['tag'], $asset['file']);
	echo "Fetching {$asset['file']} ({$arch})...\n";

	$workDir = sys_get_temp_dir() . '/gitcloud-git-static-' . bin2hex(random_bytes(8));
	mkdir($workDir, 0755, true);
	$archivePath = $workDir . '/' . $asset['file'];

	try {
		$exitCode = null;
		passthru('curl -fsSL -o ' . escapeshellarg($archivePath) . ' ' . escapeshellarg($url), $exitCode);
		if ($exitCode !== 0 || !is_file($archivePath)) {
			fwrite(STDERR, "Failed to download {$url}\n");
			exit(1);
		}

		$actualSha256 = hash_file('sha256', $archivePath);
		if (!hash_equals($asset['sha256'], (string) $actualSha256)) {
			fwrite(STDERR, sprintf(
				"Checksum mismatch for %s: expected %s, got %s\n",
				$asset['file'],
				$asset['sha256'],
				$actualSha256,
			));
			exit(1);
		}

		passthru('tar -xzf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($workDir), $exitCode);
		if ($exitCode !== 0) {
			fwrite(STDERR, "Failed to extract {$asset['file']}\n");
			exit(1);
		}

		// The tarball's top-level directory is named after the archive itself
		// minus its .tar.gz extension (see gitcloud-git-static's own packaging).
		$extractedDir = $workDir . '/' . basename($asset['file'], '.tar.gz');
		if (!is_dir($extractedDir) || !is_file($extractedDir . '/git')) {
			fwrite(STDERR, "Unexpected archive layout in {$asset['file']}\n");
			exit(1);
		}

		$binDir = $rootDir . '/bin/' . $arch;
		if (!is_dir($binDir)) {
			mkdir($binDir, 0755, true);
		}

		copy($extractedDir . '/git', $binDir . '/git');
		chmod($binDir . '/git', 0755);
		// Carried along for GPL-2.0 compliance and build provenance, not read by
		// VcsService at runtime - only bin/<arch>/git itself is ever executed.
		copy($extractedDir . '/COPYING', $binDir . '/COPYING');
		copy($extractedDir . '/BUILD.md', $binDir . '/BUILD.md');

		echo 'OK: bin/' . $arch . '/git (' . number_format(filesize($binDir . '/git') / 1024 / 1024, 1) . " MB)\n";
	} finally {
		exec('rm -rf ' . escapeshellarg($workDir));
	}
}

echo "Done.\n";
