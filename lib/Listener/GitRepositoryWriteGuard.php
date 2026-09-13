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
 * A gap shipped in 0.2.6 - a write through the Files web app's own
 * browser-session-authenticated upload was not blocked - was root-caused and
 * closed in 0.2.7: GitCloud declared no <types> in appinfo/info.xml, so
 * remote.php's filtered loadApps(['filesystem', 'logging']) never loaded the app
 * and Application::boot() (and therefore this connectHook() call) never ran for
 * that request. Declaring <types><filesystem/></types> fixed it for both DAV auth
 * paths; see the 0.2.7 CHANGELOG entry.
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
