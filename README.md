# GitCloud Nextcloud App README

## Project Overview
`GitCloud` is a Nextcloud app designed to bring basic, user-friendly Git functionality directly into the file manager interface. The goal is to allow users to manage version control operations (like committing changes or rolling back snapshots) without leaving the cloud storage context.

## Current State

- **App Name:** GitCloud
- **Base Directory:** `gitcloud`
- **Status:** Initial planning phase. This README will be updated as features are implemented.

## Development Roadmap & Milestones

We will tackle this project in progressive phases to ensure stability and testability at each step.

### 🚀 Phase 1: Foundation & Dashboard

**Goal:** Establish the necessary UI hooks and baseline functionality.
- Create a main dashboard view showing aggregate statistics (number of files, total size, Git status indicators).
- Deliverable: A functional `/dashboard` endpoint/view.

### 🛠️ Phase 2: Context Menu Integration & Core Actions

**Goal:** Allow users to right-click on a file or directory and select Git actions.

**Actions to Implement (in order):**
1. ✅ **Add to GitCloud** - Right-click context menu entry is registered (backend exec still a stub; not yet wired to stage files).
2. **Commit Changes** - Execute a `git commit` with user-provided messages.
3. **Rollback Snapshot** - Revert files in the selection to a specified previous snapshot (requires proper snapshot management logic).

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
