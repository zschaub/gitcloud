<script setup lang="ts">
import NcAppContent from "@nextcloud/vue/components/NcAppContent";
import { ref, computed } from "vue";

// --- Mock State for Dashboard Components (To be populated with real data) ---
const fileCount = ref(12);
const dirCount = ref(5);
const totalSizeMb = ref(45.2);
const lastCommitTime = ref("Just now");
const gitStatus = ref("Clean"); // Could be 'Modified', 'Unstaged', etc.

// Mock calculation for status display
const hasUncommittedChanges = computed(() => gitStatus.value !== "Clean");
</script>

<template>
    <NcContent app-name="snapcloud">
        <div class="dashboard-container">
            <h1>Git Dashboard</h1>

            <!-- Section 1: Stats Overview -->
            <section class="stats-grid">
                <div class="stat-card">
                    <h3>Files Tracked</h3>
                    <p>{{ fileCount }}</p>
                </div>
                <div class="stat-card">
                    <h3>Directories</h3>
                    <p>{{ dirCount }}</p>
                </div>
                <div class="stat-card">
                    <h3>Total Size</h3>
                    <p>{{ totalSizeMb }} MB</p>
                </div>
                <div
                    class="stat-card status-indicator"
                    :class="{ 'status-modified': !hasUncommittedChanges }"
                >
                    <h3>Status</h3>
                    <p>{{ gitStatus }}</p>
                </div>
            </section>

            <!-- Section 2: Controls (Where Git Actions will live) -->
            <section class="controls-panel">
                <h2>Version Controls</h2>

                <!-- Placeholder for File/Folder Selection Status -->
                <div class="selection-status">
                    Selected Items Count:
                    <span v-if="fileCount + dirCount > 0">{{
                        fileCount + dirCount
                    }}</span
                    >, otherwise none.
                </div>

                <!-- Control Buttons - These will trigger API calls -->
                <div class="action-buttons">
                    <button @click="$emit('openCommitDialog')">
                        ✨ Commit Changes
                    </button>
                    <button @click="$emit('openRollbackDialog')">
                        ↩️ Rollback Snapshot
                    </button>
                    <!-- Placeholder for Branch/History viewing -->
                    <div class="info-box">View History</div>
                </div>
            </section>
        </div>
    </NcContent>
</template>

<style module>
/* Basic styling to make the mockup visible and structured */
.dashboard-container {
    padding: 20px;
}

h1 {
    border-bottom: 1px solid var(--nextcloud-theme-border-color, #eee);
    padding-bottom: 10px;
    margin-bottom: 30px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
}

.stat-card {
    padding: 15px;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    background-color: var(--nc-card-background-color, #fff);
}

.stat-card h3 {
    font-size: 0.9em;
    color: var(--nextcloud-theme-secondary-color, #666);
    margin-bottom: 5px;
}

.stat-card p {
    font-size: 1.8em;
    font-weight: bold;
}

/* Specific style for the status indicator */
.status-indicator p {
    font-size: 2em;
}

/* Mock logic classes */
.status-modified {
    border-left: 5px solid var(--nextcloud-theme-warning-color, orange);
}

.controls-panel {
    padding: 20px;
    background-color: var(--nc-card-background-color, #f9f9f9);
    border-radius: 6px;
}

.action-buttons button {
    margin-right: 15px;
    padding: 10px 20px;
    cursor: pointer;
    /* Nextcloud standard styling approximation */
    background-color: var(--nextcloud-theme-primary-color, #007bff);
    color: white;
    border: none;
    border-radius: 4px;
}

.info-box {
    padding: 10px;
    margin-top: 20px;
    background-color: #eee;
    border-radius: 4px;
}
</style>
