<?php

declare(strict_types=1);

namespace Listener;

use OCA\GitCloud\Listener\GitRepositoryWriteGuard;
use PHPUnit\Framework\TestCase;

final class GitRepositoryWriteGuardTest extends TestCase {
	/**
	 * blockWrite() takes $arguments by value (matching core's own
	 * HookConnector::delete()/rename() convention), but the hook's real 'run'
	 * argument is always a PHP reference into the caller's own $run variable
	 * (see View::runHooks()/DAV File::emitPreHooks()) - PHP preserves a
	 * reference nested inside an array even across an otherwise by-value copy,
	 * which is what actually lets this cancel the write. These tests build
	 * $arguments the same way to exercise the real mechanism, not just the
	 * local array.
	 */
	private function runFlagAfterBlockWrite(string $path): bool {
		$run = true;
		$arguments = ['path' => $path, 'run' => &$run];

		(new GitRepositoryWriteGuard())->blockWrite($arguments);

		return $run;
	}

	public function testBlockWriteLeavesRunTrueForOrdinaryTrackedFile(): void {
		$this->assertTrue($this->runFlagAfterBlockWrite('/folder/file.txt'));
	}

	public function testBlockWriteSetsRunFalseForTheGitDirectoryItself(): void {
		$this->assertFalse($this->runFlagAfterBlockWrite('/.git'));
	}

	public function testBlockWriteSetsRunFalseForAFileInsideTheGitDirectory(): void {
		$this->assertFalse($this->runFlagAfterBlockWrite('/.git/config'));
	}

	public function testBlockWriteLeavesRunTrueWhenPathArgumentIsMissing(): void {
		$run = true;
		$arguments = ['run' => &$run];

		(new GitRepositoryWriteGuard())->blockWrite($arguments);

		$this->assertTrue($run);
	}
}
