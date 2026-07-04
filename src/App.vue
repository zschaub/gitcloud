<script setup lang="ts">
import NcAppContent from "@nextcloud/vue/components/NcAppContent";
import { ref, computed, onMounted } from "vue";
import axios from "@nextcloud/axios";
import { generateOcsUrl } from "@nextcloud/router";

const emit = defineEmits<{
    (e: "openCommitDialog"): void;
    (e: "openRollbackDialog"): void;
}>();

const selectedFiles = ref<string[]>([]);

const fileCount = ref(0);
const dirCount = ref(0);
const totalSizeMb = ref(0);
const gitStatus = ref("Loading…"); // 'Clean', 'Modified', 'Uninitialized', etc.
const statusError = ref("");

async function loadStatus() {
    try {
        const response = await axios.get(generateOcsUrl("apps/gitcloud/status"));
        const data = response.data.ocs.data;
        fileCount.value = data.fileCount;
        dirCount.value = data.dirCount;
        totalSizeMb.value = data.totalSizeMb;
        gitStatus.value = data.gitStatus;
        statusError.value = "";
    } catch (error) {
        gitStatus.value = "Unknown";
        const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } };
        statusError.value =
            axiosError.response?.data?.ocs?.data?.message ??
            "Failed to load GitCloud status.";
    }
}

onMounted(loadStatus);

const searchTerm = ref("");
const mockFiles = ref([
    "/folder/file-a.txt",
    "/folder/sub/image-b.png",
    "document/report.pdf",
    "/other_dir/readme.md",
    "config/settings.yml",
]);

const filteredFiles = computed(() => {
    if (!searchTerm.value) return mockFiles.value;
    return mockFiles.value.filter((file) =>
        file.toLowerCase().includes(searchTerm.value.toLowerCase()),
    );
});

function selectFile(file: string) {
    const index = selectedFiles.value.indexOf(file);
    if (index > -1) {
        selectedFiles.value.splice(index, 1);
    } else {
        selectedFiles.value.push(file);
    }
}

const hasUncommittedChanges = computed(() => gitStatus.value === "Modified");
</script>

<template>
    <NcAppContent app-name="gitcloud">
        <div class="dashboard-container">
            <h1>Git Dashboard</h1>

            <p v-if="statusError" class="status-error">{{ statusError }}</p>

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
                    :class="{ 'status-modified': hasUncommittedChanges }"
                >
                    <h3>Status</h3>
                    <p>{{ gitStatus }}</p>
                </div>
            </section>

            <!-- Section 2: File Selection & Controls -->
            <div class="controls-panel">
                <h2>Version Control</h2>
                <div class="action-buttons">
                    <button @click="emit('openCommitDialog')">
                        ✨ Commit Changes
                    </button>
                    <button @click="emit('openRollbackDialog')">
                        ↩️ Rollback Snapshot
                    </button>
                    <div class="info-box">View History</div>
                </div>
            </div>
        </div>
    </NcAppContent>
</template>

<style scoped>
.dashboard-container {
    padding: 20px;
    background-color: var(
        --nextcloud-theme-page-background,
        #f8f9fa
    ) !important;
    border-radius: 6px;
    border: 1px solid var(--nextcloud-theme-card-background-color);
}

h1 {
    border-bottom: 1px solid var(--nextcloud-theme-border-color, #ccc);
    padding-bottom: 10px;
    margin-bottom: 30px;
    color: var(--nextcloud-theme-primary-text-color, #222222);
}

h2 {
    color: var(--nextcloud-theme-primary-text-color, #222222);
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
    color: var(--nextcloud-theme-primary-text-color, #1e1e1e);
    margin-bottom: 5px;
}

.stat-card p {
    font-size: 1.8em;
    font-weight: bold;
    color: var(--nextcloud-theme-primary-text-color, #222222);
}

.status-indicator p {
    font-size: 2em;
}

.status-modified {
    border-left: 5px solid var(--nextcloud-theme-warning-color, orange);
}

.status-error {
    color: var(--nextcloud-theme-error-color, #d32f2f);
    margin-bottom: 20px;
}

.controls-panel {
    padding: 20px;
    background-color: var(--nextcloud-theme-main-background, #ffffff);
    border-radius: 6px;
}

.action-buttons button {
    margin-right: 15px;
    padding: 10px 20px;
    cursor: pointer;
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
