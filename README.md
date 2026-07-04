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
2. **Create a database** — Add persistent storage (Nextcloud app database table(s) via migrations) to track snapshot/commit metadata.
3. **Commit Changes** — Execute `git commit` with user-provided messages for staged/selected files.
4. **Rollback Snapshot** — Revert selected files to a previously captured snapshot (requires robust snapshot management).

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
- [ ] Create a database (migrations) to track snapshot/commit metadata (Phase 2.2).
- [ ] Implement Commit Changes with user-provided commit messages (Phase 2.3).
- [ ] Implement Rollback Snapshot to revert files to a prior snapshot (Phase 2.4).
