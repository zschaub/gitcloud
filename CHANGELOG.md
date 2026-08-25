# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.22] - 2026-08-25

### Changed

- Committing a file over the admin-configured size limit in **Warn** enforcement mode used to commit it immediately and only mention it afterward as a small note card next to the success message — easy to miss, and with no way to back out once it had already happened. `POST /apps/gitcloud/commit` now takes a `confirmed` flag (default `false`); when oversized files are found and `confirmed` is `false`, it returns `status: "warning"` with the offending file list **without committing anything**. `CommitDialog.vue` shows this as a blocking popup ("Git may not handle this correctly due to size") listing the files, with Commit/Cancel buttons — Commit re-submits the same request with `confirmed: true` (which then actually runs the commit), Cancel returns to the still-open commit dialog untouched. Block mode is unaffected — it continues to reject an oversized file outright via `FileTooLargeException` regardless of `confirmed`, both because that's already a hard pre-commit gate and because the user confirmed Block already worked correctly; only Warn mode's UX changed.

Covered by three new/rewritten `ApiTest` cases (`testCommitChangesReturnsWarningWithoutCommittingWhenFileOverLimitInWarnModeUnconfirmed`, `testCommitChangesCommitsOverLimitFileInWarnModeWhenConfirmed`, `testCommitChangesStillRejectsFileOverLimitInBlockModeEvenWhenConfirmed`); full PHPUnit suite (54 tests) and `composer openapi` verified passing inside the running `stable34` container; `composer lint` and a clean `vite build` also verified.

## [0.1.21] - 2026-08-25

### Added

- Adding a new file to a folder that already has at least one file committed to GitCloud now surfaces that new file in the dashboard automatically, tagged `Uncommitted`, instead of staying invisible until someone manually commits it. `GET /apps/gitcloud/directories` groups files strictly from `gitcloud_snapshots` history, so a file that had never been committed had no row to be grouped by and simply never appeared — even though its containing directory was already shown as "tracked". `ApiController::getDirectories` now also lists, for each non-root tracked directory, any file physically present in that Nextcloud folder that isn't already in its committed file set (`ApiController::findUncommittedFiles`, a direct `Folder::getDirectoryListing()` scan — not recursive into subfolders, since each subfolder is its own directory grouping), reporting each as `{path, status: 'Uncommitted'}` alongside the existing `Modified`/`Unchanged` entries. These files are selectable/committable in Directory Detail exactly like any other file, since they're real paths under the user's storage.
- The repository root directory (`/`) is intentionally excluded from this — it groups files purely because they sit at the top level of the user's whole Nextcloud storage rather than because that folder was deliberately tracked, so applying the same logic there would surface every unrelated file at the root as "Uncommitted".
- Overview's Committed Directories list gained an "N uncommitted" pill (next to the existing "N modified" pill) on directories with newly-surfaced files, and the per-file status pill in Directory Detail now renders a distinct color for `Uncommitted` vs. `Modified`/`Unchanged` (`src/App.vue`'s `statusVariant`/`uncommittedFileCount`).

Covered by two new `ApiTest` cases (`testGetDirectoriesSurfacesUncommittedFilesInTrackedSubdirectory`, `testGetDirectoriesDoesNotSurfaceUncommittedFilesAtRepositoryRoot`); the full PHPUnit suite could not be run in this environment since it requires the running `stable34` container, but `composer lint`/`composer cs:check` and a clean `vite build` were verified. The user should confirm the new tests pass and the pill/tag render as expected in their running instance.

## [0.1.20] - 2026-08-25

### Fixed

- The dashboard Overview page's "Status" stat card showed "Modified" essentially permanently, even immediately after a fresh commit with no pending changes — and, less visibly, Files Tracked/Directories/Total Size were similarly wrong. `ApiController::getStatus()`'s whole-repository branch (used whenever no `directory` query param is given) delegated to `VcsService::getRepositoryStatus()`, which walked the *entire* repository working tree — i.e. the user's whole Nextcloud "files" folder, since that's what backs the repository path — rather than just the files actually committed to GitCloud. Since GitCloud only ever `git add`s files a user explicitly commits, nearly everything else in that folder is untracked, so `git status --porcelain` over the whole tree returned non-empty output almost always. This bug dates back to when `/apps/gitcloud/status` was first added (0.1.1); the same class of bug was already fixed for Directory Detail's stats (0.1.9) and per-file Modified/Unchanged badges (0.1.10), but the global Overview card was never updated to match. `getStatus()` now gathers the same GitCloud-tracked file set `getDirectories()` already uses (`VcsService::getCommittedDirectories()` filtered against still-existing files) for both the whole-repository and `?directory=`-scoped branches, and delegates size/status to the existing, already-correct `VcsService::getDirectoryStatus()` in both cases — `getRepositoryStatus()` (now dead code, confirmed via grep to have no other callers) was deleted. Covered by two new tests (`ApiTest::testGetStatusExcludesDirectoriesWithAllFilesDeletedFromDirCount`, `testGetStatusReturnsEmptyStatsWhenNothingCommitted`) and a rewritten `testGetStatusReturnsWholeRepositoryStatsWithoutDirectoryParam`; full PHPUnit suite (50 tests, up from 48) verified passing inside the running `stable34` container, `composer lint` and `composer openapi` also verified (response shape unchanged, no spec diff). No frontend changes needed — `App.vue` already reads the same response field names.
- `vite.config.ts` had 3 TypeScript errors, present since the project's very first commit: `extractLicenseInformation: true` and `thirdPartyLicense: false` don't match `@nextcloud/vite-config@2.5.2`'s actual option types (`REUSELicensesPluginOptions | false` and `string | undefined` respectively — neither accepts a bare `true`/`false` the way this config used them), and `import { join, resolve } from "path"` had no `@types/node` installed to resolve against. `vite build` never caught these since Vite doesn't type-check its own config file, so they only ever surfaced in an editor's TS language server. Changed `extractLicenseInformation: true` to `extractLicenseInformation: {}` (the object form that enables the plugin with default options, matching the prior behavior) and removed `thirdPartyLicense: false` (its default, "no BOM generated," is already what `false` was being used to express); added `@types/node` as a devDependency to resolve the `path` import. Verified with a scoped `tsc --noEmit` over just `vite.config.ts` (zero errors, down from 3) and a clean `vite build` producing the same output structure (`.license` files still emitted per chunk, still no separate third-party-license BOM file).

## [0.1.19] - 2026-08-25

### Fixed

Three bugs found testing 0.1.18's new Settings pages against a real running instance:

- The enforcement-mode radio buttons in **Settings > Administration > GitCloud** never visually filled in when clicked. `AdminSettings.vue` bound them with `:checked="enforcementMode"` / `@update:checked="..."`, but `NcCheckboxRadioSwitch` (as shipped in `@nextcloud/vue@9.6.0`) has no `checked` prop at all — it only exposes `modelValue`/`update:modelValue`, so the binding was silently a no-op extra attribute and the click handler never ran. Switched both radios to `v-model="enforcementMode"`.
- **Settings > Administration > GitCloud**'s sidebar icon rendered solid black while every other section's icon rendered white in dark theme. The Settings navigation applies a `filter: var(--background-invert-if-dark)` CSS filter to section icons, which assumes icons are authored with a **black** fill (inverted to white only in dark theme) — confirmed by inspecting core's own admin sections (`Sharing.php` → `actions/share.svg`) and a third-party app's pattern (`user_ldap/lib/Settings/Section.php` → a dedicated `app-dark.svg`, distinct from its `app.svg`). `AdminSection::getIcon()` was instead reusing `app.svg`, which has `fill="white"` (correct for the colored-circle background of the main Nextcloud left nav, which doesn't apply this filter) — inverted, white becomes black. Added a new `img/app-dark.svg` (same glyph, black fill) and pointed `AdminSection::getIcon()` at it.
- **Settings > Personal > GitCloud** never appeared in the sidebar at all, even for an admin account. `Personal::getSection()` returned `'additional'` to reuse Nextcloud's built-in "Additional settings" personal section, but `\OC\Settings\Manager::getPersonalSections()` only creates that section's sidebar entry when **two or more** apps register a personal setting under it (`count($this->getPersonalSettings('additional')) > 1`) — with GitCloud the only app using it in this environment, the count was 1 and the whole section (GitCloud's only personal page) was silently dropped. Added a dedicated `lib/Settings/PersonalSection.php` (mirroring `AdminSection.php`, registered via a new `<personal-section>` in `info.xml`) so the page no longer depends on another app sharing "Additional settings".

Full PHPUnit suite (48 tests) verified passing inside the running `stable34` container; `composer lint` and a clean `vite build` also verified. The radio-button and icon fixes could not be visually re-verified in a browser per this project's rules (code-changes-only, no driving the app) — verified by reading `@nextcloud/vue`'s actual component props/source and core's own icon-file conventions instead; the user should confirm both in their running instance.

## [0.1.18] - 2026-08-25

### Added

- GitCloud now has real pages in Nextcloud's Settings UI, separate from its own dashboard/nav entry (Phase 3-adjacent groundwork, not part of the original roadmap phases).
  - **Settings > Administration > GitCloud** (new dedicated section, `lib/Settings/AdminSection.php` + `lib/Settings/Admin.php`, first use of `OCP\Settings\IIconSection`/`IDelegatedSettings` and `OCP\IAppConfig` in this codebase) lets an admin set a maximum file size (in MB, default 100) and choose an enforcement mode: **Block** (default) rejects a commit containing an oversized file, or **Warn** commits it anyway and reports it back. Saving goes through a new `POST /apps/gitcloud/admin/settings` endpoint gated by `#[AuthorizedAdminSetting]`, so delegated admins (not just full admins) can manage it.
  - **Settings > Personal > GitCloud** (`lib/Settings/Personal.php`) lets each user permanently delete their own GitCloud commit history to reclaim disk space: a new `POST /apps/gitcloud/history/delete` endpoint (`VcsService::deleteHistory`) deletes the repository's `.git` directory and reinitializes an empty one, and clears every `gitcloud_snapshots` row for that user (`SnapshotMapper::deleteAllForUser`) since their commit hashes are no longer valid afterward. Working-tree files are untouched — only history is wiped. This is irreversible, so the UI (`PersonalSettings.vue`) requires typing "delete" into a confirmation field before the action is enabled, a step up from `RollbackPanel`'s existing two-button confirm dialog.
- `ApiController::commitChanges` now enforces the admin-configured file-size limit per file during the existing folder-expansion walk (`collectRelativeFilePaths`): an oversized file either aborts the whole commit with a clear error (Block mode, mirroring the existing `NotLocalStorageException` all-or-nothing behavior, via a new `FileTooLargeException`) or is committed anyway with its path reported in a new optional `warnings` array on the success response (Warn mode), which `CommitDialog.vue` now surfaces as a note card listing the oversized files that were committed.

### Fixed

- `ApiController::commitChanges`'s docblock declared `@param string[] $files`, which the OpenAPI extractor rejects as an ambiguous array syntax (unrelated to this release's actual feature work, but it blocked regenerating the spec for the new endpoints above) — changed to `list<string>`.

Full PHPUnit suite (48 tests, up from 37) and `composer openapi` verified passing inside the running `stable34` container; `composer lint` and a clean `vite build` also verified. Psalm's pre-existing baseline (95 findings before this change, tracked since 0.1.14 as an unrelated stub/environment issue) rose to 109 with this change — the added findings are `Mixed*` types from `VcsService::removeDirectoryRecursive`'s `RecursiveDirectoryIterator` walk (the same untyped-`SplFileInfo` pattern Psalm already can't resolve in the pre-existing `getRepositoryStatus`) and `PossiblyUnusedMethod` on the new DI-constructed `Settings\*` classes' constructors (Psalm doesn't see Nextcloud's `info.xml`-driven settings-class instantiation as a "call"), neither of which reflects a real defect.

## [0.1.17] - 2026-08-24

### Fixed

- The Files-app "Add to GitCloud" right-click action (`src/fileActions/addToGitCloud.js`) only committed the first of multiple selected files (`context.nodes[0]`), silently dropping the rest with no indication to the user. Now passes every selected node's path (`context.nodes.map(...)`) through to `CommitDialog`, which already supported a multi-file `files` array.
- `VcsService::getFileStatuses` mis-parsed renamed files out of `git status --porcelain -z` output: a staged rename emits an extra NUL-terminated field for the old path (e.g. `"R  renamed.txt\0a.txt\0"`) with no `XY ` status-code prefix, but the parser applied `substr($entry, 3)` to every field uniformly, mangling the old path into a key that could never match and silently leaving that entry's real status unreported. The parser now recognizes `R`/`C` (rename/copy) index-status entries and skips their trailing old-path field instead of mis-parsing it. Covered by a new `VcsServiceTest::testGetFileStatusesHandlesRenamedFileWithoutCorruptingOtherEntries`.
- `ApiController::commitChanges` only checked `isLocal()` on the top-level selected node before recursively expanding folders (`collectRelativeFilePaths`); a folder containing a nested non-local mount (e.g. an external SMB/FTP storage mounted at a subpath) recursed into it anyway, producing paths that don't exist on the local repository filesystem and failing the entire `git add` batch — including for legitimate local files in the same folder. `collectRelativeFilePaths` now checks `isLocal()` at every level of the recursion and throws a new `OCA\GitCloud\Exception\NotLocalStorageException` (caught in `commitChanges` and turned into the existing "File is not on local storage" error response) as soon as a non-local node is encountered. Covered by a new `ApiTest::testCommitChangesFailsWhenFolderContainsNestedNonLocalMount`.
- `VcsService::runGitConfigSet` hardcoded `'success' => true` regardless of the actual git exit code, discarding `proc_close()`'s return value — the same defect already fixed for `runGitConfigGet` in 0.1.15, reintroduced in the sibling method. Now returns `'success' => $exitCode === 0` like `runGitConfigGet` and `runGit`.
- `runGitConfigGet`/`runGitConfigSet` trimmed their combined stdout+stderr output with `rtrim($output, "")` — an empty character mask makes `rtrim` a no-op, so trailing whitespace was never actually stripped (verified: `php -r 'var_dump(rtrim("hello\n\n", ""));'` returns the string unchanged). Both now use the same bare `rtrim($output)` as `runGit` itself.

### Changed

- `VcsService::runGit` previously ran its git-identity check-and-configure sequence (up to 6 extra `git config` subprocesses) before every single git invocation, including pure reads like `status`, `diff --cached --quiet`, and `rev-parse HEAD`. Since only `git commit` actually requires an author identity, the check now only runs when the command being executed is `commit`, meaningfully reducing subprocess overhead on read-heavy requests like a dashboard load (repository status + batched file statuses + per-file snapshot lookups).

Full PHPUnit suite (37 tests, up from 35) verified passing inside the running `stable34` container; `composer lint` and a clean `vite build` also verified.

## [0.1.16] - 2026-08-24

### Fixed

- `POST /apps/gitcloud/commit` rejected a commit message that was literally the string `"0"` with "Missing file paths or commit message.", because `ApiController::commitChanges`'s validation used PHP's `empty($message)`, which treats `"0"` as empty — even though `VcsService::commitChanges`'s own check (`trim($message) === ''`) would have accepted it. Now uses `trim($message) === ''` for consistency. Covered by a new `ApiTest::testCommitChangesAcceptsMessageThatIsLiteralZero`; full PHPUnit suite (35 tests) verified passing inside the running `stable34` container.

## [0.1.15] - 2026-08-24

### Fixed

- `VcsService::runGit`'s automatic git identity setup (added in 0.1.14's predecessor commit "Fix Git username and email setting bug") silently discarded any real git identity — whether inherited from system/global git config or previously set on the repo — and force-overwrote `user.email`/`user.name` to the hardcoded fallback (`Nextcloud <www-data@nextcloud.local>`) on every single `runGit()` call, not just when identity was actually unset. Root cause was two bugs in `VcsService::runGitConfigGet`: (1) it ran `git config <key> --get`, which git parses as *setting* `<key>` to the literal value `"--get"` rather than reading it — confirmed by inspecting `.git/config` after a call, which showed `email = --get`; (2) it hardcoded `'success' => true` regardless of the actual git exit code, discarding `proc_close()`'s return value, so the "is this identity actually configured" check could never fail. Together, every commit/rollback GitCloud ever made was authored as `Nextcloud <www-data@nextcloud.local>`, never a real configured identity. Fixed by correcting the flag order (`git config --get <key>`) and using the real exit code for `success`; also fixed `runGit`'s surrounding condition checks, which treated "not found" (`success === false`) as "still needs a default" only when `success === true`, and re-check-after-set logic that would otherwise let a real system-wide identity be immediately clobbered by the hardcoded default a few lines later. Verified against a real git repo that a pre-configured identity now survives `runGit()` unchanged, and that a repo with no identity configured anywhere still falls back to the hardcoded default (so commits don't start failing with "Please tell me who you are"). Full PHPUnit suite (34 tests) verified passing inside the running `stable34` container.

## [0.1.14] - 2026-08-24

### Fixed

- Rolling back a file (`POST /apps/gitcloud/rollback`) restores its content via `git checkout <hash> -- <file>` directly on the local filesystem, bypassing Nextcloud's Node/View write path entirely. This left `oc_filecache`'s mtime/etag/size stale for the file, so desktop/mobile sync clients — which detect remote changes by polling WebDAV for etag changes — never saw the rollback and never re-downloaded the restored content, even though the file on disk had changed. `ApiController::rollbackSnapshot` now calls `$node->getStorage()->getUpdater()->update($node->getInternalPath())` after a successful rollback to resync the cache entry and propagate the new etag/mtime up to parent folders, mirroring the same call Nextcloud's own WebDAV PUT handler (`apps/dav/lib/Connector/Sabre/File.php`) makes after writing file content outside the Node API. Verified this is the correct, documented pattern (`OCP\Files\Storage\IStorage::getUpdater()`/`IUpdater::update()`) by inspecting Nextcloud core inside the running `stable34` container, and confirmed core's DAV PUT handler and several other apps (`files_trashbin`, `files_sharing`) use the identical call after direct-storage writes. Covered by a new `ApiTest::testRollbackSnapshotDoesNotRefreshCacheWhenRollbackFails` and an updated `testRollbackSnapshotSucceedsWithValidFileAndSnapshotId` asserting the updater is invoked (only on success) with the file's internal path; full PHPUnit suite (34 tests) and Psalm verified inside the running `stable34` container — Psalm's pre-existing 95 findings (an unrelated stub/environment issue) are unchanged in count before and after this change. 

## [0.1.13] - 2026-08-24

### Changed

- Overview's Committed Directories list now renders subfolders nested under their parent folder instead of as separate, unrelated top-level entries. `src/App.vue` builds a client-side tree (`buildDirectoryTree`) out of the flat `directories` list the backend already returns, indenting each path segment under its parent; a folder path with no directly-committed files of its own (e.g. "folder" when only "folder/sub" was ever committed to) still gets a row purely to group its children under, shown muted and non-clickable since there's nothing to open. Searching the list still shows a flat, full-path result set as before — nesting only applies to the unfiltered view. No backend changes; `GET /apps/gitcloud/directories`'s response shape is unchanged. Verified with a clean `vite build`; `vue-tsc`/`eslint` could not be run in this environment (dependency/config resolution issues unrelated to this change).

## [0.1.12] - 2026-08-24

### Fixed

- Committing a folder (e.g. via the Files-app "Add to GitCloud" right-click action on a directory, which passes the clicked node's own path straight through to `POST /apps/gitcloud/commit`) previously staged the folder's contents via `git add <folder>` — which git recurses through fine — but recorded only a single `gitcloud_snapshots` row for the folder's own path rather than one per file inside it. Since `VcsService::getCommittedDirectories` buckets snapshot rows by the directory portion of their file path, that folder path was bucketed as a bogus "file" under the repository root, and every real file nested inside the folder (including anything in subfolders) never got its own snapshot row — so subfolder contents never appeared in the dashboard's Committed Directories/Directory Detail lists. Committing a subfolder directly afterward then reported "No changes to commit for the selected file(s)." because git had already committed everything during the parent folder's commit. `ApiController::commitChanges` now recursively expands any folder passed in `files` into its contained files (`ApiController::collectRelativeFilePaths`, walking `Folder::getDirectoryListing()`) before staging/recording, so every file — at any nesting depth — gets tracked individually. Covered by a new `ApiTest::testCommitChangesExpandsFolderIntoContainedFiles` unit test; full PHPUnit suite (33 tests) verified passing inside the running `stable34` container.

## [0.1.11] - 2026-07-31

### Fixed

- `GET /apps/gitcloud/directories` previously called `VcsService::getFileStatuses` once per committed directory, spawning a separate `git status --porcelain -z` subprocess for each one (found by `/code-review`). `ApiController::getDirectories` now collects the existing files across every directory first and makes a single batched `getFileStatuses` call over their union, then splits the resulting status map back out per directory — one git subprocess per request regardless of how many directories are tracked. Verified via updated unit tests asserting `getFileStatuses` is called exactly once (`ApiTest::testGetDirectoriesSucceedsWithLoggedInUser`, `testGetDirectoriesOmitsFilesDeletedFromNextcloud`) and the full PHPUnit suite (32 tests) passing inside the running `stable34` container, plus a live `/directories` call confirming output is unchanged.

## [0.1.10] - 2026-07-29

### Added

- `GET /apps/gitcloud/directories` now returns each file with a real `status` field (`Modified` or `Unchanged`, computed by `VcsService::getFileStatuses` via `git status --porcelain -z` scoped to that directory's files) instead of a bare path string. Directory Detail's file list shows this per-file status next to each filename, and the Committed Directories list on Overview shows an "N modified" pill on directories that have pending changes. This was the last open item for Phase 2 — completes the per-file Modified/Unchanged status follow-up flagged during the 0.1.8 UI redesign.

### Fixed

- Verifying this feature against a real running instance (`stable34.local`) surfaced two bugs neither the temp-repo PHPUnit tests nor static checks caught, both in `VcsService::runGit`/`getFileStatuses`, because the test data happened to have no spaces in any path: (1) `git status --porcelain` quotes any path containing a space or other special character (e.g. `"Test Folder/status test.txt"`), which never matched a plain relative path — fixed by using `--porcelain -z` for NUL-delimited, unquoted paths. (2) `runGit()`'s `trim()` of combined stdout+stderr was silently eating the meaningful leading space of git's own status-code column (e.g. `" M"` for "modified, not staged") whenever it happened to be the very first byte of output, shifting every parsed path by one character — fixed by trimming only trailing whitespace (`rtrim`) in `runGit`, since every other caller that cares about leading whitespace already trims its own hash/emptiness check explicitly. Added `VcsServiceTest::testGetFileStatusesHandlesFilePathsContainingSpaces` to cover both. Full PHPUnit suite (32 tests) verified passing inside the running `stable34` container; end-to-end flow (commit a file, confirm `Unchanged`, edit it on disk, confirm `Modified`, re-commit, confirm back to `Unchanged`) and directory-scoped Total Size/Status verified via the real OCS API against a file with a space in its path.

## [0.1.9] - 2026-07-29

### Added

- Directory Detail's Total Size and Status stats are now scoped to the selected directory instead of reflecting the whole repository. `GET /apps/gitcloud/status` accepts an optional `directory` query param; when given, `VcsService::getDirectoryStatus` sums the sizes of just that directory's still-existing committed files and runs `git status --porcelain -- <files>` scoped to them, instead of walking/statusing the entire repository. The dashboard now fetches this alongside the existing file-count stat whenever a directory is selected, refreshing it after each commit/rollback like the other stats. The "(repo-wide)" caveat label is gone since Status is now accurate per-directory.

## [0.1.8] - 2026-07-29

### Fixed

- `GET /apps/gitcloud/directories` no longer lists files/directories that have been deleted from Nextcloud since they were last committed. `gitcloud_snapshots` rows are kept forever for history purposes, but the API now filters each directory's file list against `Folder::nodeExists()` and drops any directory left with no existing files, so deleted items stop showing up as "ghost" entries in the dashboard.

### Added

- Redesigned the dashboard (`src/App.vue`) and the Files-app "Add to GitCloud" right-click action (`src/fileActions/addToGitCloud.js`) around a shared `CommitDialog.vue` (`NcDialog`-based commit modal) and a new `RollbackPanel.vue` (per-file snapshot timeline drawer), replacing every `window.prompt`/`alert`/`confirm` call in both entry points with real `@nextcloud/vue` UI.
- Commit is now file-scoped instead of whole-directory: Directory Detail shows a checkbox per file, a bottom selection bar ("N file(s) selected" / Clear / "Commit Changes…") opens `CommitDialog` pre-filled with just the checked files. The same dialog, pre-filled with the single right-clicked file, now backs the Files-app action, so there is one commit UI instead of two.
- Each file in Directory Detail has a "History" button opening `RollbackPanel`, a right-hand drawer listing that file's `GET /apps/gitcloud/snapshots` entries newest-first as a connected timeline (commit message, Committed/Rolled back pill, relative + absolute time, short hash). Every snapshot but the newest gets a "Rollback to this snapshot" button that opens a small confirm dialog before calling `POST /apps/gitcloud/rollback`; the result renders inline in the still-open panel.
- Overview gained a 4-stat card grid (Files Tracked, Directories, Total Size, Status with a colored dot) and dedicated empty ("No directories tracked yet…") and no-search-match states for the Committed Directories list; Directory Detail's Status card is explicitly labeled "(repo-wide)" since it isn't yet scoped to the selected directory (known backend gap, unchanged from prior releases).
- `.dashboard-container` no longer force-locks to a light theme — the hardcoded `--color-*` overrides are removed and the dashboard now inherits Nextcloud's real theme variables throughout, so it follows the instance's active light/dark theme like the rest of the UI.

### Note

- Per-file Modified/Unchanged status (considered for the Directory Detail file list during this redesign) was intentionally left out: `GET /apps/gitcloud/directories` has no per-file status field to back it, and shipping a fabricated one would misrepresent real repository state. Flagged as a follow-up: add a real `status` field per file to the `/directories` response.

## [0.1.7] - 2026-07-27

### Added

- `GET /apps/gitcloud/directories` and `VcsService::getCommittedDirectories` replace the dashboard's mock-derived committed-directories list: groups every file the user has ever committed (from `gitcloud_snapshots`) by directory, deduplicated and sorted, with files at the repository root grouped under `/`. The dashboard's Overview list and Directory Detail's file set (used by Commit/Rollback) are now both backed by this real data instead of the hardcoded mock array; both are refreshed after a successful commit or rollback.
- Verified against a running instance: committing real files across the repository root and nested subdirectories produced correctly grouped, sorted directory entries; confirmed the full PHPUnit suite (21 tests) passes there.

### Fixed

- `VcsService::getCommittedDirectories` normalizes each file path's leading slash before adding it to its directory's file list (not just when computing which directory it belongs to), so a snapshot recorded with a leading-slash path no longer produces a duplicate-looking entry that doesn't match its own directory grouping. Caught by a unit test before it shipped.

## [0.1.6] - 2026-07-27

### Added

- Phase 2.4 (Rollback Snapshot): `GET /apps/gitcloud/snapshots` lists a file's recorded snapshots newest-first; `POST /apps/gitcloud/rollback` restores a single file to a chosen snapshot's commit content via `git checkout <hash> -- <file>`, commits the restoration, and records a new `gitcloud_snapshots` row (status `rolled_back`, parented to the file's prior snapshot). Rejects requests for a snapshot that doesn't exist, belongs to another user, doesn't match the requested file, or would be a no-op (file already at that content).
- Wired the dashboard's "Rollback Snapshot" button to the new endpoints: prompts for which file to roll back (when the selected directory has more than one), fetches and lists that file's snapshots via `window.prompt` (consistent with the existing `window.prompt`/`window.alert` commit-message flow), confirms before restoring, then calls `/rollback` and shows the result inline alongside the existing commit success/error messaging.
- Verified end-to-end against a running instance (commit twice, list snapshots, roll back, confirm both the file content and git history reverted, plus the not-found/wrong-user/wrong-file/no-op edge cases each return a clear error) and confirmed the full PHPUnit suite (17 tests) passes there.

## [0.1.5] - 2026-07-06

### Added

- Redesigned the dashboard (`src/App.vue`) into a two-state Overview/Directory Detail layout (Phase 2.1 follow-up): Overview shows the global stat cards plus a searchable, selectable list of committed directories; selecting one switches to Directory Detail, showing stats scoped to that directory plus Commit Changes/Rollback Snapshot controls, with a button to return to Overview. The committed-directories list and directory-scoped stats are derived from client-side mock data for now — a real backend query grouping `gitcloud_snapshots` by directory prefix is tracked as a follow-up.
- Directory Detail's "Commit Changes" button is wired to the real `POST /apps/gitcloud/commit` endpoint (same request pattern as the "Add to GitCloud" context menu action). "Rollback Snapshot" is shown disabled, pending Phase 2.4's rollback implementation.
- Adopted `NcTextField`, `NcListItem`, and `NcButton` from `@nextcloud/vue` for the new search box, directory list, and action buttons.

### Fixed

- The new `NcTextField`/`NcListItem`/tertiary `NcButton` elements were unreadable (dark-on-dark or white-on-white) on Nextcloud instances using a dark theme, because those components inherit text/background color from real Nextcloud theme variables while the rest of the dashboard uses its own always-light, hardcoded-fallback styling. `.dashboard-container` now overrides the relevant theme variables (and re-declares `color`, since some child elements inherit an already-computed literal color rather than re-reading the variable) so the whole dashboard renders consistently light regardless of the instance's active theme.

### Removed

- Removed the dashboard's unused `openCommitDialog`/`openRollbackDialog` emits (no listener ever consumed them) and the dead, unrendered mock file-selection code (`selectedFiles`, `selectFile`, `filteredFiles`) superseded by the new directory-selection state. Removed the static, non-functional "View History" placeholder box.

## [0.1.4] - 2026-07-04

### Fixed

- `VcsService::commitChanges` no longer runs `git commit` when the selected file(s) have no actual changes staged (e.g. committing an already up-to-date file). Previously this let raw, confusing git stderr (e.g. `nothing added to commit but untracked files present`) leak through to the UI; it now returns a clear "No changes to commit for the selected file(s)." message.

## [0.1.3] - 2026-07-04

### Added

- `VcsService::commitChanges` now records a `gitcloud_snapshots` row for each committed file after a successful commit, using the resulting `git rev-parse HEAD` commit hash and linking to the file's most recent prior snapshot as its parent (Phase 2.3).

### Fixed

- Moved `VcsService` from `lib/VcsService.php` to `lib/Service/VcsService.php` so its `OCA\GitCloud\Service` namespace complies with PSR-4 — the mismatch made the class unresolvable via dependency injection, causing every API request that depends on `VcsService` (including `/commit`) to fail with a 500 error.
- Fixed a null-pointer bug in the new parent-snapshot lookup: committing a file with no prior snapshot (e.g. its first-ever commit) called `->getId()` on a null array element before the commit could be recorded.
- Fixed `ApiController::commitChanges(array $data)` binding: Nextcloud's AppFramework binds request parameters by argument name, but the frontend sends `{files, message}` with no top-level `data` key, so `$data` was always `null` and every real `/commit` request crashed with a `TypeError` before running. The controller now takes `$files` and `$message` directly. This bug predates Phase 2.3 and had never been exercised by an actual HTTP request — only by unit tests that call the controller method directly, bypassing Nextcloud's parameter binding entirely.
- Fixed `ApiController::commitChanges` passing paths with a leading `/` (as returned by `Folder::getRelativePath()`) straight to `git add`, which git rejects as an invalid path for any file not at the repository root. Paths are now stripped of the leading slash before being passed to `VcsService`.

## [0.1.2] - 2026-07-04

### Added

- Database migration creating the `gitcloud_snapshots` table to track snapshot/commit metadata (user, file path, commit hash, message, parent snapshot reference, status, timestamp).
- `Snapshot` entity and `SnapshotMapper` for querying snapshot records, and `VcsService` helper methods (`createSnapshotRecord`, `getSnapshotsForFile`, `updateSnapshotStatus`) to create/read/update them (Phase 2.2).

## [0.1.1] - 2026-07-04

### Added

- `GET /apps/gitcloud/status` API endpoint and `VcsService::getRepositoryStatus()` to compute live file/directory counts, total size, and Git status for the user's repository.
- Dashboard now loads real stats from the backend on mount instead of showing dummy values.

## [0.1.0] - 2026-07-03

### Added

- Dummy dashboard
- Right-click context menu "Add to GitCloud" action (dummy button)
- Backend Git service and API controller to stage and commit selected files via `git init`/`git add`/`git commit`
