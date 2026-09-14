<?php

declare(strict_types=1);

namespace OCA\GitCloud\Service;

/**
 * Where a bundled static git binary lives inside GitCloud's own app directory -
 * kept as a single shared definition, for the same reason GitArchitecture::detect()
 * is: VcsService (which looks for a binary already on disk) and
 * GitStaticBinaryService (which downloads and installs one, and reports on it)
 * must never disagree on the layout.
 */
final class BundledGitBinary {
	/**
	 * Directory holding the binary for a given architecture, relative to the app
	 * directory: bin/<arch>/.
	 */
	public static function directoryFor(string $appPath, string $architecture): string {
		return $appPath . '/bin/' . $architecture;
	}

	/**
	 * Absolute path of a usable bundled binary, or false when none is installed for
	 * this architecture (missing file, or present but not executable).
	 */
	public static function path(string $appPath, string $architecture): string|false {
		$candidate = self::directoryFor($appPath, $architecture) . '/git';

		return (is_file($candidate) && is_executable($candidate)) ? $candidate : false;
	}
}
