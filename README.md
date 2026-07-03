# GitCloud Nextcloud App README

## Project Overview
`GitCloud` is a **standalone Nextcloud app** that brings basic, user-friendly Git functionality directly into the file manager interface. The goal is to allow users to manage version control operations (like committing changes or rolling back snapshots) without leaving the cloud storage context.

**No GitHub connection.** GitCloud does not connect to GitHub (or any remote repository service). It operates entirely within your local Nextcloud instance, managing only files that you have checked out and work with locally. This is a self-contained app — no remotes, push/pull, or external account configuration.

## Current State

- **App Name:** GitCloud
- **Base Directory:** `gitcloud`
- **Status:** Initial planning phase. This README will be updated as features are implemented.

## Development Roadmap & Milestones

We will tackle this project in progressive phases to ensure stability and testability at each step.

### 🚀 Phase 1: Foundation & Dashboard

**Goal:** Establish the necessary UI hooks and baseline functionality.
- ✅ Create a main dashboard view showing aggregate statistics (number of files, total size, Git status indicators).
- ✅ Deliverable: A functional dashboard
- ✅ Add to GitCloud - Right-click context menu entry is registered (backend exec still a stub; not yet wired to stage files).

### 🛠️ Phase 2: Context Menu Integration & Core Actions

**Goal:** Allow users to right-click on a file or directory and select Git actions.

**Actions to Implement (in order):**
1. **Commit Changes** - Execute a `git commit` with user-provided messages.
2. **Rollback Snapshot** - Revert files in the selection to a specified previous snapshot (requires proper snapshot management logic).

### Phase 3: Advanced Functionality & Polish

**Goal:** Add advanced features once core operations are stable.
- View commit history for selected items
- Compare snapshots
- Manage branches visually

## Development Steps

- [x] Created this README file to outline the plan.
- [X] Implement initial dashboard structure.
- [x] Register "Add to GitCloud" context menu entry via `@nextcloud/files`.

**⚠️ Important Note:** This development relies heavily on Nextcloud's internal APIs for context menu integration. Implementation will require specific backend services or JavaScript hooks, with compatibility considerations based on the target Nextcloud version. In particular, the `@nextcloud/files` frontend package must be kept at the major version bundled by the target Nextcloud core (v4.x for Nextcloud 34) — an older major version's context menu registration API is silently ignored, with no error, if it doesn't match what the core Files app reads from.
