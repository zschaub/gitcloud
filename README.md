# GitCloud Nextcloud App README

## Project Overview
`GitCloud` is a **standalone Nextcloud app** that brings basic, user-friendly Git functionality directly into the file manager interface. The goal is to allow users to manage version control operations (like committing changes or rolling back snapshots) without leaving the cloud storage context.

**No GitHub connection.** GitCloud does not connect to GitHub (or any remote repository service). It operates entirely within your local Nextcloud instance, managing only files that you have checked out and work with locally. This is a self-contained app — no remotes, push/pull, or external account configuration.

## Current State

- **App Name:** GitCloud
- **Base Directory:** `gitcloud`
- **Status:** Phase 1 complete. Phase 2 in progress. This README will be updated as features are implemented.

## Development Roadmap & Milestones

We will tackle this project in progressive phases to ensure stability and testability at each step.

### ✅ Phase 1: Foundation — Complete

**Goal:** Establish the app structure, UI entry points, and baseline interface. Everything below is done.
- ✅ Main dashboard view — shows aggregate statistics (file count, total size, Git status indicators).
- ✅ Right-click context menu entry "Add to GitCloud" registered via `@nextcloud/files`.
- ✅ Wire the backend stub (`VcsService`) to actually stage and commit selected files when the context action is triggered.

### 🚧 Phase 2: Core Actions — Commits & Rollbacks (In Progress)

**Goal:** Reliable, everyday Git operations that users need most.
1. ✅ **Wire up the dashboard UI** — Connect the dashboard's placeholder elements to real, live data (file counts, sizes, Git status) instead of dummy values.
   - ✅ **Follow-up:** Redesign the dashboard into a two-state layout — an Overview (global stats + a searchable list of committed directories) that switches to a Directory Detail view (stats scoped to the selected directory, plus Commit/Rollback controls) when a directory is selected, with a way to deselect back to Overview. Commit Changes and Rollback Snapshot are both wired to their real endpoints.
   - ✅ **Follow-up:** Replace the mock-derived committed-directories list and Directory Detail file set with real data — `GET /apps/gitcloud/directories` groups every file the user has ever committed (from `gitcloud_snapshots`) by directory. The directory-scoped stats numbers (file count is now accurate; total size and Git status still reflect the whole repository, not just the selected directory) remain a follow-up — see kanban.
   - ⬜ **Follow-up (open):** Make total size and Git status in Directory Detail scoped to the selected directory instead of the whole repository — see kanban.
2. ✅ **Create a database** — Add persistent storage (Nextcloud app database table(s) via migrations) to track snapshot/commit metadata.
3. ✅ **Commit Changes** — Collect a user-provided commit message in the UI and record a snapshot row for each commit (the underlying `git add`/`git commit` plumbing and context menu wiring were already completed in Phase 1).
4. ✅ **Rollback Snapshot** — Expose available snapshots per file via the API, revert selected files to a previously captured snapshot, and record a new snapshot row for the rollback. The dashboard's Rollback Snapshot button prompts for which file (when the selected directory has more than one real committed file) and which snapshot to restore, following the same `window.prompt`/`window.alert` convention as the commit flow.
   - ✅ **Follow-up:** Redesigned Commit/Rollback around real `@nextcloud/vue` UI instead of `window.prompt`/`alert`/`confirm` — commit is now per-file (checkbox selection + a shared `CommitDialog` used by both the dashboard and the Files-app right-click action) and rollback is a per-file `RollbackPanel` snapshot timeline with a confirm step, replacing the old prompt-chain flow. The dashboard also dropped its light-theme lock and now follows the instance's active theme. Per-file Modified/Unchanged status was considered but left out — no backend field exists for it yet; see kanban.
   - ⬜ **Follow-up (open):** Add a real per-file Modified/Unchanged status to `GET /apps/gitcloud/directories` and surface it in Directory Detail's file list — see kanban.

### 🔮 Phase 3: Advanced Features (TBD)

**Goal:** Exploration and polish once core operations are stable and tested in real usage.
- View commit history for selected items
- Compare snapshots
- Manage branches visually

*This phase is tentative — we will refine the feature list based on what users actually need.*

## Development Steps

- [x] Created this README file to outline the plan.
- [X] Implement initial dashboard structure.
- [x] Register "Add to GitCloud" context menu entry via `@nextcloud/files`.
- [x] Wire `VcsService` to stage and commit selected files via `git add`/`git commit` on the user's local Nextcloud storage.
- [x] Wire dashboard UI elements to real backend data (Phase 2.1).
- [x] Create a database (migrations) to track snapshot/commit metadata (Phase 2.2).
- [x] Collect a commit message in the UI and record a snapshot row after each commit (Phase 2.3).
- [x] Expose available snapshots via the API and implement Rollback Snapshot to revert files to a prior snapshot, wired into the dashboard UI (Phase 2.4).
- [x] Redesign the dashboard into an Overview/Directory-Detail two-state layout with a committed-directories search (follow-up to Phase 2.1).
- [x] Replace the mock-derived committed-directories list and Directory Detail file set with a real backend endpoint grouping committed files by directory (follow-up to Phase 2.1). Directory-scoped size/Git-status stats are still repository-wide; see kanban for the follow-up backend work.
- [ ] Scope Directory Detail's total size and Git status stats to the selected directory instead of the whole repository (follow-up to Phase 2.1).
- [ ] Add a real per-file Modified/Unchanged status field to `GET /apps/gitcloud/directories` and show it in Directory Detail's file list (follow-up to Phase 2.4).
