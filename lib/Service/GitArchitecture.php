<?php

declare(strict_types=1);

namespace OCA\GitCloud\Service;

/**
 * Maps the server's real machine architecture to the bin/<arch>/ directory
 * naming used both by VcsService (to find a bundled git binary already on
 * disk) and GitStaticBinaryService (to know which release asset to
 * download) - kept as a single shared mapping so the two can never disagree
 * on what "amd64"/"arm64" means for this server.
 */
final class GitArchitecture {
	public static function detect(): ?string {
		if (PHP_OS_FAMILY !== 'Linux') {
			return null;
		}

		return match (php_uname('m')) {
			'x86_64', 'amd64' => 'amd64',
			'aarch64', 'arm64' => 'arm64',
			default => null,
		};
	}
}
