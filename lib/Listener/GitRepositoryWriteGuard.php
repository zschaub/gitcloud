<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

/**
 * Blocks writing to a file inside GitCloud's own .git repository.
 *
 * This is deliberately NOT a BeforeNodeWrittenEvent IEventListener - confirmed
 * by driving a real write against a running instance (see CHANGELOG) that such
 * a listener cannot actually cancel a write. Nextcloud core's own
 * HookConnector::write() never sets the legacy write hook's by-reference 'run'
 * flag the way HookConnector::delete()/rename() do for their own events, and
 * OC_Hook::emit() (which invokes HookConnector::write() as a hook slot)
 * swallows any exception a listener throws instead of re-throwing it - so an
 * AbortedEventException thrown from a BeforeNodeWrittenEvent listener is
 * silently discarded and the write proceeds untouched.
 *
 * Connecting straight to the legacy OC_Filesystem::write hook (via
 * OCP\Util::connectHook(), the same public API OCA\Files_Sharing\Helper uses
 * for its own legacy hooks) and setting its 'run' argument to false instead
 * works correctly: core's own DAV write path
 * (apps/dav/lib/Connector/Sabre/File.php::emitPreHooks()) checks that flag and
 * turns a cancelled write into a clean, real error for the client - verified
 * against a real running instance for a directly-authenticated WebDAV PUT
 * (e.g. curl with Basic Auth, or a third-party sync client's own uploads).
 *
 * KNOWN GAP, also found by driving a real instance: a write made through the
 * Files web app's own browser-session-authenticated upload (e.g. drag-and-drop
 * or the "New > Upload file" button, including its overwrite/conflict flow)
 * does NOT go through this guard - confirmed this isn't .git-specific by
 * reproducing the same gap on a write to an ordinary, unrelated tracked file.
 * Application::boot() (and therefore this connectHook() call) never runs at
 * all for that specific PUT request, even though it reliably runs for every
 * other request in the same browser session, including other DAV requests to
 * the same file moments later. Root cause not identified - see CHANGELOG.
 */
class GitRepositoryWriteGuard {
	use GitRepositoryPathGuard;

	/**
	 * @param array{path?: string, run?: bool} $arguments
	 */
	public function blockWrite($arguments): void {
		if ($this->isRelativePathInsideGitRepository((string)($arguments['path'] ?? ''))) {
			$arguments['run'] = false;
		}
	}
}
