# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
