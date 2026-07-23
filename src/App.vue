<script setup lang="ts">
import NcAppContent from "@nextcloud/vue/components/NcAppContent";
import NcTextField from "@nextcloud/vue/components/NcTextField";
import NcListItem from "@nextcloud/vue/components/NcListItem";
import NcButton from "@nextcloud/vue/components/NcButton";
import { ref, computed, onMounted } from "vue";
import axios from "@nextcloud/axios";
import { generateOcsUrl } from "@nextcloud/router";

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

const hasUncommittedChanges = computed(() => gitStatus.value === "Modified");

const selectedDirectory = ref<string | null>(null); // null = Overview

// mockFiles mixes leading-slash and non-leading-slash paths; normalize before
// taking the parent directory so both styles group correctly.
function dirnameOf(filePath: string): string {
    const normalized = filePath.replace(/^\/+/, "");
    const idx = normalized.lastIndexOf("/");
    return idx === -1 ? "/" : normalized.slice(0, idx);
}

const committedDirectories = computed(() => {
    const dirs = new Set(mockFiles.value.map(dirnameOf));
    return Array.from(dirs).sort();
});

const filteredDirectories = computed(() => {
    if (!searchTerm.value) return committedDirectories.value;
    return committedDirectories.value.filter((dir) =>
        dir.toLowerCase().includes(searchTerm.value.toLowerCase()),
    );
});

const selectedDirectoryFiles = computed(() => {
    if (!selectedDirectory.value) return [];
    return mockFiles.value.filter((file) => dirnameOf(file) === selectedDirectory.value);
});

const selectedDirectoryFileCount = computed(() => selectedDirectoryFiles.value.length);

function selectDirectory(dir: string) {
    selectedDirectory.value = dir;
}

function deselectDirectory() {
    selectedDirectory.value = null;
}

const isCommitting = ref(false);
const commitError = ref("");
const commitSuccessMessage = ref("");

async function commitSelectedDirectory() {
    if (!selectedDirectory.value) return;

    const message = window.prompt(
        `Commit message for "${selectedDirectory.value}":`,
        "",
    );
    if (message === null) return;
    if (message.trim() === "") {
        window.alert("A commit message is required.");
        return;
    }

    isCommitting.value = true;
    commitError.value = "";
    commitSuccessMessage.value = "";
    try {
        const response = await axios.post(generateOcsUrl("apps/gitcloud/commit"), {
            files: selectedDirectoryFiles.value,
            message,
        });
        commitSuccessMessage.value = response.data.ocs.data.message;
        await loadStatus();
    } catch (error) {
        const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } };
        commitError.value =
            axiosError.response?.data?.ocs?.data?.message ??
            "Failed to commit changes to GitCloud.";
    } finally {
        isCommitting.value = false;
    }
}
</script>

<template>
    <NcAppContent app-name="gitcloud">
        <div class="dashboard-container">
            <h1>Git Dashboard</h1>

            <p v-if="statusError" class="status-error">{{ statusError }}</p>

            <!-- State A: Overview -->
            <template v-if="!selectedDirectory">
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

                <div class="directories-panel">
                    <h2>Committed Directories</h2>
                    <NcTextField
                        :model-value="searchTerm"
                        label="Search directories"
                        placeholder="Search directories…"
                        @update:model-value="searchTerm = String($event)"
                    />
                    <ul v-if="filteredDirectories.length" class="directory-list">
                        <NcListItem
                            v-for="dir in filteredDirectories"
                            :key="dir"
                            :name="dir"
                            @click="selectDirectory(dir)"
                        />
                    </ul>
                    <p v-else>No directories found.</p>
                </div>
            </template>

            <!-- State B: Directory Detail -->
            <template v-else>
                <div class="detail-header">
                    <NcButton variant="tertiary" @click="deselectDirectory">
                        ← Back to Overview
                    </NcButton>
                    <h2>{{ selectedDirectory }}</h2>
                </div>

                <section class="stats-grid">
                    <div class="stat-card">
                        <h3>Files in Directory</h3>
                        <p>{{ selectedDirectoryFileCount }}</p>
                    </div>
                    <div
                        class="stat-card status-indicator"
                        :class="{ 'status-modified': hasUncommittedChanges }"
                    >
                        <h3>Status</h3>
                        <p>{{ gitStatus }}</p>
                    </div>
                </section>

                <div class="controls-panel">
                    <h2>Version Control</h2>
                    <p v-if="commitError" class="status-error">{{ commitError }}</p>
                    <p v-if="commitSuccessMessage" class="status-success">{{ commitSuccessMessage }}</p>
                    <div class="action-buttons">
                        <NcButton
                            variant="primary"
                            :disabled="isCommitting"
                            @click="commitSelectedDirectory"
                        >
                            {{ isCommitting ? "Committing…" : "✨ Commit Changes" }}
                        </NcButton>
                        <NcButton
                            variant="secondary"
                            disabled
                            title="Rollback is not yet implemented (Phase 2.4)"
                        >
                            ↩️ Rollback Snapshot
                        </NcButton>
                    </div>
                </div>
            </template>
        </div>
    </NcAppContent>
</template>

<style scoped>
.dashboard-container {
    padding: 20px;
    min-height: 100%;
    background-color: var(
        --nextcloud-theme-page-background,
        #f8f9fa
    ) !important;
    border-radius: 6px;
    border: 1px solid var(--nextcloud-theme-card-background-color);

    /* Several @nextcloud/vue components (NcTextField, NcListItem, NcButton's
       tertiary variant) read these real Nextcloud theme variables and/or
       inherit `color` from them, which otherwise follow the instance's
       active (possibly dark) theme and clash with this dashboard's
       always-light styling above. Redeclaring `color` (not just the custom
       property) ensures descendants that don't set their own color, like
       NcListItem's name text or a tertiary NcButton's label, inherit the
       light-mode value from here instead of a dark-theme value computed
       higher up the tree. */
    --color-main-background: #ffffff;
    --color-main-text: #222222;
    --color-text-maxcontrast: #767676;
    --color-border-maxcontrast: #cccccc;
    --input-border-color: #cccccc;
    --color-background-hover: #f5f5f5;
    color: var(--color-main-text);
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

.status-success {
    color: var(--nextcloud-theme-success-color, #2e7d32);
    margin-bottom: 20px;
}

.controls-panel,
.directories-panel {
    padding: 20px;
    background-color: var(--nextcloud-theme-main-background, #ffffff);
    border-radius: 6px;
}

.directories-panel {
    margin-bottom: 20px;
}

.directory-list {
    list-style: none;
    padding: 0;
    margin-top: 10px;
}

.detail-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
}

.action-buttons {
    display: flex;
    gap: 15px;
}
</style>
