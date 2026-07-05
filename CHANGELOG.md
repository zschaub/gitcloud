# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
