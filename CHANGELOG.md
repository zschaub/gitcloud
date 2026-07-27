# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
