# GitCloud Nextcloud App README

## Project Overview
`GitCloud` is a **standalone Nextcloud app** that brings basic, user-friendly Git functionality directly into the file manager interface. The goal is to allow users to manage version control operations (like committing changes or rolling back snapshots) without leaving the cloud storage context.

**No GitHub connection.** GitCloud does not connect to GitHub (or any remote repository service). It operates entirely within your local Nextcloud instance, managing only files that you have checked out and work with locally. This is a self-contained app — no remotes, push/pull, or external account configuration.

## Requirements

- **The `git` binary must be installed and on the `PATH` of the user running PHP** (e.g. `www-data`/php-fpm) on the Nextcloud server. GitCloud has no bundled git and no PHP git library — every operation (`VcsService::runGit`/`runGitConfigGet`/`runGitConfigSet`) shells out directly to the `git` executable via `proc_open`. If `git` isn't installed, commit/rollback/status requests will fail with a generic error rather than a clear "git is not installed" message.

## Usage

1. **Commit a file or folder.** In the Nextcloud Files app, right-click any file or folder and choose **Add to GitCloud** from the context menu. Enter a commit message and confirm — the file (or, for a folder, every file inside it, including nested subfolders) is staged and committed to GitCloud's Git repository.
2. **Open the GitCloud tab.** Select **GitCloud** from the Nextcloud left navigation to open the dashboard.
   - **Overview** lists every directory you've committed files under, with aggregate stats (files tracked, directories, total size, Git status) and a search box to filter the list.
   - Selecting a directory switches to **Directory Detail**, showing that directory's committed files with a per-file status of Modified, Unchanged, Uncommitted (present on disk but never committed), or Deleted. The Committed Directories list on Overview also shows "N modified"/"N uncommitted" pills per directory. From here you can select files and commit further changes, or open a file's **History** to view its snapshot timeline and roll back to an earlier version.
3. **Delete, rename, or move a tracked file however you normally would** (Files app, WebDAV, sync client, etc.) — no need to do this "through" GitCloud. GitCloud detects the change immediately and auto-commits it to keep its history in sync: a deleted file shows a **Deleted** status in Directory Detail (its History and Rollback still work, and rolling back recreates the file); a renamed or moved file's history automatically follows it to its new location; restoring a deleted file from Nextcloud's trash automatically re-commits it and clears its **Deleted** status.
4. **Configure GitCloud in Nextcloud Settings.**
   - **Settings > Administration > GitCloud** lets an admin set the maximum file size GitCloud will commit and whether an oversized file blocks the commit (default) or is committed anyway with a warning.
   - **Settings > Personal > GitCloud** lets each user permanently delete their own GitCloud commit history (a full Git history wipe, not just the visible list) to reclaim disk space; their current files are not affected.

## Current State

- **App Name:** GitCloud
- **Base Directory:** `gitcloud`
- **Status:** Phase 1 and Phase 2 complete, plus admin/personal Settings pages (max file size limit, personal commit-history deletion) and automatic tracking of files deleted/renamed/moved outside GitCloud. Phase 3 not yet started. This README will be updated as features are implemented.

## Development Roadmap & Milestones

We will tackle this project in progressive phases to ensure stability and testability at each step.

### ✅ Phase 1: Foundation — Complete

**Goal:** Establish the app structure, UI entry points, and baseline interface. Everything below is done.
- ✅ Main dashboard view — shows aggregate statistics (file count, total size, Git status indicators).
- ✅ Right-click context menu entry "Add to GitCloud" registered via `@nextcloud/files`.
- ✅ Wire the backend stub (`VcsService`) to actually stage and commit selected files when the context action is triggered.

### ✅ Phase 2: Core Actions — Commits & Rollbacks — Complete

**Goal:** Reliable, everyday Git operations that users need most.
1. ✅ **Wire up the dashboard UI** — Connect the dashboard's placeholder elements to real, live data (file counts, sizes, Git status) instead of dummy values.
   - ✅ **Follow-up:** Redesign the dashboard into a two-state layout — an Overview (global stats + a searchable list of committed directories) that switches to a Directory Detail view (stats scoped to the selected directory, plus Commit/Rollback controls) when a directory is selected, with a way to deselect back to Overview. Commit Changes and Rollback Snapshot are both wired to their real endpoints.
   - ✅ **Follow-up:** Replace the mock-derived committed-directories list and Directory Detail file set with real data — `GET /apps/gitcloud/directories` groups every file the user has ever committed (from `gitcloud_snapshots`) by directory. The directory-scoped stats numbers (file count is now accurate; total size and Git status still reflect the whole repository, not just the selected directory) remain a follow-up — see kanban.
   - ✅ **Follow-up:** Directory Detail's total size and Git status are now scoped to the selected directory instead of the whole repository — `GET /apps/gitcloud/status` accepts an optional `directory` param backed by a new `VcsService::getDirectoryStatus`.
2. ✅ **Create a database** — Add persistent storage (Nextcloud app database table(s) via migrations) to track snapshot/commit metadata.
3. ✅ **Commit Changes** — Collect a user-provided commit message in the UI and record a snapshot row for each commit (the underlying `git add`/`git commit` plumbing and context menu wiring were already completed in Phase 1).
4. ✅ **Rollback Snapshot** — Expose available snapshots per file via the API, revert selected files to a previously captured snapshot, and record a new snapshot row for the rollback. The dashboard's Rollback Snapshot button prompts for which file (when the selected directory has more than one real committed file) and which snapshot to restore, following the same `window.prompt`/`window.alert` convention as the commit flow.
   - ✅ **Follow-up:** Redesigned Commit/Rollback around real `@nextcloud/vue` UI instead of `window.prompt`/`alert`/`confirm` — commit is now per-file (checkbox selection + a shared `CommitDialog` used by both the dashboard and the Files-app right-click action) and rollback is a per-file `RollbackPanel` snapshot timeline with a confirm step, replacing the old prompt-chain flow. The dashboard also dropped its light-theme lock and now follows the instance's active theme. Per-file Modified/Unchanged status was considered but left out — no backend field exists for it yet; see kanban.
   - ✅ **Follow-up:** Added a real per-file Modified/Unchanged status to `GET /apps/gitcloud/directories` (`VcsService::getFileStatuses`, backed by `git status --porcelain` scoped to each directory's files) and surfaced it in Directory Detail's file list, plus an "N modified" pill on Overview's Committed Directories list. This was the last open item for Phase 2.
   - ✅ **Follow-up:** The per-file status set was later expanded beyond Modified/Unchanged to include `Uncommitted` (a file physically present in an already-tracked folder that's never been committed) and `Deleted` (a committed file removed outside GitCloud), with matching pills/tags in the UI. See CHANGELOG 0.1.21 and 0.1.23.

### 🔮 Phase 3: Advanced Features (TBD)

**Goal:** Exploration and polish once core operations are stable and tested in real usage.
- Compare snapshots
- Manage branches visually
- Delete commits (a targeted removal of individual commits from history — distinct from the existing Personal Settings option to wipe a user's *entire* GitCloud history at once)
- Remove specific files / folders from GitCloud (stop tracking a file without deleting it from disk)
- Git binary not installed error message (today a missing `git` binary surfaces as a generic failure rather than a clear message; see the note in Requirements)
- Multi-file / whole-directory rollback to a single point in time — rollback is currently per-file only; restoring several files together to how they looked at a given moment was deliberately scoped out of the original rollback implementation
- Diff/preview view — show what actually changed before committing or before rolling back; both actions are currently "blind," with no content diff shown anywhere in the UI
- Ignore-pattern / exclusion support for auto-tracking — once a folder has any committed file, every subsequent change to any file in it is auto-committed, with no way to exclude specific paths or file types
- Surface real git error output instead of a generic failure message — a broader version of the "Git binary not installed" item above, covering git failures in general
- Snapshot pruning/retention controls — Personal Settings currently only offers a full history wipe; a middle ground (e.g. "prune snapshots older than X" or "keep the last N per file") would help reclaim space without losing everything

*This phase is tentative — we will refine the feature list based on what users actually need.*
