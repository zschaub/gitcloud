<script setup lang="ts">
import NcAppContent from "@nextcloud/vue/components/NcAppContent";
import { ref, computed } from "vue";

// --- Mock State for Dashboard Components (To be populated with real data) ---
const selectedFiles = ref<string[]>([]);

    const fileCount = ref(12);
    const dirCount = ref(5);
    const totalSizeMb = ref(45.2);
    const gitStatus = ref("Clean"); // Could be 'Modified', 'Unstaged', etc.

    // New state for file selection UI
    const searchTerm = ref("");
    const mockFiles = ref([
        "/folder/file-a.txt",
        "/folder/sub/image-b.png",
        "document/report.pdf",
        "/other_dir/readme.md"
    ]);

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

            <!-- Section 2: File Selection & Controls -->
            <div class="controls-panel">
                <h2>Version Control</h2>

                <!-- New section for finding/selecting files -->
                <section class="file-picker">
                    <h3>Select Files & Folders</h3>
                    <input type="text" v-model="searchTerm" placeholder="Search or browse files (e.g., path/to/file)">
                    <div class="file-list">
                        <!-- Mock File List -->
                        <ul v-if="mockFiles.length > 0">
                            <li v-for="item in mockFiles" :key="item">{{ item }}</li>
                        </ul>
                         <p v-else>No files found matching your criteria.</p>
                    </div>
                </section>

                <!-- Display selected items and count -->
                <div class="selection-status">
                    <strong>Selected Items ({{ selectedFiles.length }})</strong>:
                    <ul class="selected-list">
                        <li v-for="(file, index) in selectedFiles" :key="index">{{ file }}</li>
                    </ul>
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
            </div>
        </div
    </template>
    </NcContent>
</template>

<style module>
/* Basic styling to make the mockup visible and structured */
.dashboard-container {

    /* Basic styling to make the mockup visible and structured */

   /* Basic styling to make the mockup visible and structured */
   /* Increasing specificity to override default Nextcloud page background */
   .dashboard-container {
       padding: 20px;
       background-color: var(--nextcloud-theme-page-background, #f8f9fa) !important;
       border-radius: 6px;
       /* Adding a slight border to visually contain the element if background fails */
       border: 1px solid var(--nextcloud-theme-card-background-color);
   }
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
