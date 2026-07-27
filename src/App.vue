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
        statusError.value = extractErrorMessage(error, "Failed to load GitCloud status.");
    }
}

function extractErrorMessage(error: unknown, fallback: string): string {
    const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } };
    return axiosError.response?.data?.ocs?.data?.message ?? fallback;
}

interface CommittedDirectory {
    path: string;
    files: string[];
}

const directories = ref<CommittedDirectory[]>([]);
const directoriesError = ref("");

async function loadDirectories() {
    try {
        const response = await axios.get(generateOcsUrl("apps/gitcloud/directories"));
        directories.value = response.data.ocs.data.directories;
        directoriesError.value = "";
    } catch (error) {
        directoriesError.value = extractErrorMessage(error, "Failed to load committed directories.");
    }
}

onMounted(() => {
    loadStatus();
    loadDirectories();
});

const searchTerm = ref("");

const hasUncommittedChanges = computed(() => gitStatus.value === "Modified");

const selectedDirectory = ref<string | null>(null); // null = Overview

const committedDirectories = computed(() => directories.value.map((dir) => dir.path));

const filteredDirectories = computed(() => {
    if (!searchTerm.value) return committedDirectories.value;
    return committedDirectories.value.filter((dir) =>
        dir.toLowerCase().includes(searchTerm.value.toLowerCase()),
    );
});

const selectedDirectoryFiles = computed(() => {
    if (!selectedDirectory.value) return [];
    return directories.value.find((dir) => dir.path === selectedDirectory.value)?.files ?? [];
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
        await Promise.all([loadStatus(), loadDirectories()]);
    } catch (error) {
        commitError.value = extractErrorMessage(error, "Failed to commit changes to GitCloud.");
    } finally {
        isCommitting.value = false;
    }
}

const isRollingBack = ref(false);
const rollbackError = ref("");
const rollbackSuccessMessage = ref("");

async function rollbackSelectedDirectory() {
    if (!selectedDirectory.value || selectedDirectoryFiles.value.length === 0) return;

    rollbackError.value = "";
    rollbackSuccessMessage.value = "";

    let filePath: string;
    if (selectedDirectoryFiles.value.length === 1) {
        filePath = selectedDirectoryFiles.value[0];
    } else {
        const fileList = selectedDirectoryFiles.value
            .map((file, index) => `${index + 1}. ${file}`)
            .join("\n");
        const choice = window.prompt(
            `Which file do you want to roll back?\n${fileList}\n\nEnter the file path exactly as shown above:`,
            selectedDirectoryFiles.value[0],
        );
        if (choice === null) return;
        if (!selectedDirectoryFiles.value.includes(choice)) {
            window.alert("Please enter one of the listed file paths exactly.");
            return;
        }
        filePath = choice;
    }

    isRollingBack.value = true;
    let snapshots: { id: number; message: string; createdAt: number }[];
    try {
        const response = await axios.get(generateOcsUrl("apps/gitcloud/snapshots"), {
            params: { filePath },
        });
        snapshots = response.data.ocs.data.snapshots;
    } catch (error) {
        rollbackError.value = extractErrorMessage(error, "Failed to load snapshots for the selected file.");
        isRollingBack.value = false;
        return;
    }
    isRollingBack.value = false;

    if (!snapshots.length) {
        window.alert(`No snapshots found for "${filePath}".`);
        return;
    }

    const snapshotList = snapshots
        .map((snapshot, index) => {
            const timestamp = new Date(snapshot.createdAt * 1000).toLocaleString();
            return `${index + 1}. #${snapshot.id} — ${snapshot.message} (${timestamp})`;
        })
        .join("\n");
    const snapshotChoice = window.prompt(
        `Snapshots for "${filePath}" (newest first):\n${snapshotList}\n\nEnter the number of the snapshot to roll back to:`,
        "1",
    );
    if (snapshotChoice === null) return;

    const index = parseInt(snapshotChoice, 10) - 1;
    if (isNaN(index) || index < 0 || index >= snapshots.length) {
        window.alert("Invalid selection.");
        return;
    }

    const snapshotId = snapshots[index].id;
    if (
        !window.confirm(
            `Roll back "${filePath}" to snapshot #${snapshotId}? This will create a new commit and cannot be undone automatically.`,
        )
    ) {
        return;
    }

    isRollingBack.value = true;
    try {
        const response = await axios.post(generateOcsUrl("apps/gitcloud/rollback"), {
            filePath,
            snapshotId,
        });
        rollbackSuccessMessage.value = response.data.ocs.data.message;
        await Promise.all([loadStatus(), loadDirectories()]);
    } catch (error) {
        rollbackError.value = extractErrorMessage(error, "Failed to roll back the selected file.");
    } finally {
        isRollingBack.value = false;
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
                    <p v-if="directoriesError" class="status-error">{{ directoriesError }}</p>
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
                    <p v-if="rollbackError" class="status-error">{{ rollbackError }}</p>
                    <p v-if="rollbackSuccessMessage" class="status-success">{{ rollbackSuccessMessage }}</p>
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
                            :disabled="isRollingBack"
                            @click="rollbackSelectedDirectory"
                        >
                            {{ isRollingBack ? "Rolling back…" : "↩️ Rollback Snapshot" }}
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
