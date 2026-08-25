<script setup lang="ts">
import NcAppContent from "@nextcloud/vue/components/NcAppContent";
import NcTextField from "@nextcloud/vue/components/NcTextField";
import NcButton from "@nextcloud/vue/components/NcButton";
import CommitDialog from "./components/CommitDialog.vue";
import RollbackPanel from "./components/RollbackPanel.vue";
import FolderOutlineIcon from "@mdi/svg/svg/folder-outline.svg?raw";
import ChevronRightIcon from "@mdi/svg/svg/chevron-right.svg?raw";
import FileDocumentOutlineIcon from "@mdi/svg/svg/file-document-outline.svg?raw";
import ClockOutlineIcon from "@mdi/svg/svg/clock-outline.svg?raw";
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

const directoryTotalSizeMb = ref(0);
const directoryGitStatus = ref("Loading…");
const directoryStatusError = ref("");

async function loadDirectoryStatus(directory: string) {
    try {
        const response = await axios.get(generateOcsUrl("apps/gitcloud/status"), {
            params: { directory },
        });
        const data = response.data.ocs.data;
        directoryTotalSizeMb.value = data.totalSizeMb;
        directoryGitStatus.value = data.gitStatus;
        directoryStatusError.value = "";
    } catch (error) {
        directoryGitStatus.value = "Unknown";
        directoryStatusError.value = extractErrorMessage(error, "Failed to load directory status.");
    }
}

function extractErrorMessage(error: unknown, fallback: string): string {
    const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } };
    return axiosError.response?.data?.ocs?.data?.message ?? fallback;
}

interface CommittedFile {
    path: string;
    status: string; // 'Modified', 'Unchanged', or 'Uncommitted'
}

interface CommittedDirectory {
    path: string;
    files: CommittedFile[];
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

function statusVariant(status: string): "clean" | "modified" | "uncommitted" | "unknown" {
    if (status === "Modified") return "modified";
    if (status === "Uncommitted") return "uncommitted";
    if (status === "Clean" || status === "Unchanged") return "clean";
    return "unknown";
}

const gitStatusVariant = computed(() => statusVariant(gitStatus.value));
const directoryGitStatusVariant = computed(() => statusVariant(directoryGitStatus.value));

const selectedDirectory = ref<string | null>(null); // null = Overview

function directoryLabel(path: string): string {
    return path === "/" ? "Root" : path;
}

interface DirectoryRow {
    path: string;
    label: string;
    depth: number;
    files: CommittedFile[];
    isReal: boolean; // false = a path segment shown only to group its children, not an actual committed directory
}

interface TreeBuilderNode {
    path: string;
    label: string;
    files: CommittedFile[];
    isReal: boolean;
    children: Map<string, TreeBuilderNode>;
}

// Builds a nested tree out of the flat directory list the backend returns, so a
// committed subfolder (e.g. "folder/sub") renders indented under its parent
// ("folder") instead of as its own unrelated top-level entry. Ancestor path
// segments with no directly committed files of their own (e.g. "folder" when
// only "folder/sub" was ever committed to) still get a row purely to group
// their children under, but aren't selectable since there's nothing to show.
function buildDirectoryTree(dirs: CommittedDirectory[]): DirectoryRow[] {
    const roots = new Map<string, TreeBuilderNode>();

    for (const dir of dirs) {
        if (dir.path === "/") continue;

        let children = roots;
        let currentPath = "";
        let node: TreeBuilderNode | undefined;
        for (const segment of dir.path.split("/")) {
            currentPath = currentPath ? `${currentPath}/${segment}` : segment;
            node = children.get(segment);
            if (!node) {
                node = { path: currentPath, label: segment, files: [], isReal: false, children: new Map() };
                children.set(segment, node);
            }
            children = node.children;
        }
        if (node) {
            node.isReal = true;
            node.files = dir.files;
        }
    }

    function flatten(nodes: Map<string, TreeBuilderNode>, depth: number): DirectoryRow[] {
        const rows: DirectoryRow[] = [];
        for (const node of [...nodes.values()].sort((a, b) => a.label.localeCompare(b.label))) {
            rows.push({ path: node.path, label: node.label, depth, files: node.files, isReal: node.isReal });
            rows.push(...flatten(node.children, depth + 1));
        }
        return rows;
    }

    return flatten(roots, 0);
}

const displayedDirectoryRows = computed<DirectoryRow[]>(() => {
    if (searchTerm.value) {
        return directories.value
            .filter((dir) => directoryLabel(dir.path).toLowerCase().includes(searchTerm.value.toLowerCase()))
            .map((dir) => ({ path: dir.path, label: directoryLabel(dir.path), depth: 0, files: dir.files, isReal: true }));
    }

    const rows: DirectoryRow[] = [];
    const root = directories.value.find((dir) => dir.path === "/");
    if (root) {
        rows.push({ path: "/", label: "Root", depth: 0, files: root.files, isReal: true });
    }
    rows.push(...buildDirectoryTree(directories.value));
    return rows;
});

const selectedDirectoryFiles = computed(() => {
    if (!selectedDirectory.value) return [];
    return directories.value.find((dir) => dir.path === selectedDirectory.value)?.files ?? [];
});

const selectedDirectoryFileCount = computed(() => selectedDirectoryFiles.value.length);

function modifiedFileCount(dir: CommittedDirectory): number {
    return dir.files.filter((file) => file.status === "Modified").length;
}

function uncommittedFileCount(dir: CommittedDirectory): number {
    return dir.files.filter((file) => file.status === "Uncommitted").length;
}

const selectedFiles = ref<Set<string>>(new Set());

function selectDirectory(dir: string) {
    selectedDirectory.value = dir;
    selectedFiles.value = new Set();
    loadDirectoryStatus(dir);
}

function deselectDirectory() {
    selectedDirectory.value = null;
    selectedFiles.value = new Set();
}

function toggleFileSelection(file: string) {
    const next = new Set(selectedFiles.value);
    if (next.has(file)) {
        next.delete(file);
    } else {
        next.add(file);
    }
    selectedFiles.value = next;
}

function clearFileSelection() {
    selectedFiles.value = new Set();
}

const selectedFileCount = computed(() => selectedFiles.value.size);

const commitDialogOpen = ref(false);
const commitDialogFiles = ref<string[]>([]);

function openCommitForSelection() {
    commitDialogFiles.value = Array.from(selectedFiles.value);
    commitDialogOpen.value = true;
}

function onCommitted() {
    clearFileSelection();
    const refreshes = [loadStatus(), loadDirectories()];
    if (selectedDirectory.value) {
        refreshes.push(loadDirectoryStatus(selectedDirectory.value));
    }
    Promise.all(refreshes);
}

const rollbackPanelOpen = ref(false);
const rollbackPanelFilePath = ref<string | null>(null);

function openRollbackForFile(file: string) {
    rollbackPanelFilePath.value = file;
    rollbackPanelOpen.value = true;
}

function onRolledBack() {
    const refreshes = [loadStatus(), loadDirectories()];
    if (selectedDirectory.value) {
        refreshes.push(loadDirectoryStatus(selectedDirectory.value));
    }
    Promise.all(refreshes);
}
</script>

<template>
    <NcAppContent app-name="gitcloud">
        <div class="dashboard-container">
            <p v-if="statusError" class="banner banner--error">{{ statusError }}</p>

            <!-- State A: Overview -->
            <template v-if="!selectedDirectory">
                <h1>Git Dashboard</h1>

                <section class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-card__label">Files Tracked</div>
                        <div class="stat-card__value">{{ fileCount }}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card__label">Directories</div>
                        <div class="stat-card__value">{{ dirCount }}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card__label">Total Size</div>
                        <div class="stat-card__value">{{ totalSizeMb }} MB</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card__label">Status</div>
                        <div class="stat-card__status">
                            <span class="status-dot" :class="`status-dot--${gitStatusVariant}`" />
                            <span class="stat-card__value stat-card__value--status">{{ gitStatus }}</span>
                        </div>
                    </div>
                </section>

                <p v-if="directoriesError" class="banner banner--error">{{ directoriesError }}</p>

                <template v-if="directories.length === 0">
                    <div class="empty-panel">
                        <span class="empty-panel__icon" v-html="FolderOutlineIcon" />
                        <div class="empty-panel__title">No directories tracked yet</div>
                        <div class="empty-panel__copy">
                            Right-click a file in the Files app and choose "Add to GitCloud" to make your first
                            commit — it'll show up here.
                        </div>
                    </div>
                </template>
                <div v-else class="directories-panel">
                    <div class="directories-panel__header">
                        <h2>Committed Directories</h2>
                        <NcTextField
                            class="directories-panel__search"
                            :model-value="searchTerm"
                            label="Search directories"
                            placeholder="Search directories…"
                            @update:model-value="searchTerm = String($event)"
                        />
                    </div>
                    <ul v-if="displayedDirectoryRows.length" class="directory-list">
                        <li
                            v-for="row in displayedDirectoryRows"
                            :key="row.path"
                            class="directory-row"
                            :class="{ 'directory-row--synthetic': !row.isReal }"
                            :style="{ paddingLeft: `${20 + row.depth * 22}px` }"
                            @click="row.isReal && selectDirectory(row.path)"
                        >
                            <span class="directory-row__icon" v-html="FolderOutlineIcon" />
                            <span class="directory-row__label">{{ row.label }}</span>
                            <template v-if="row.isReal">
                                <span class="directory-row__pill">{{ row.files.length }} files</span>
                                <span v-if="modifiedFileCount(row) > 0" class="directory-row__pill directory-row__pill--modified">
                                    {{ modifiedFileCount(row) }} modified
                                </span>
                                <span v-if="uncommittedFileCount(row) > 0" class="directory-row__pill directory-row__pill--uncommitted">
                                    {{ uncommittedFileCount(row) }} uncommitted
                                </span>
                                <span class="directory-row__chevron" v-html="ChevronRightIcon" />
                            </template>
                        </li>
                    </ul>
                    <p v-else class="no-match">No directories match "{{ searchTerm }}".</p>
                </div>
            </template>

            <!-- State B: Directory Detail -->
            <template v-else>
                <NcButton variant="tertiary" class="back-button" @click="deselectDirectory">
                    ← Back to Overview
                </NcButton>
                <h2>{{ directoryLabel(selectedDirectory) }}</h2>

                <p v-if="directoryStatusError" class="banner banner--error">{{ directoryStatusError }}</p>

                <section class="stats-grid stats-grid--detail">
                    <div class="stat-card">
                        <div class="stat-card__label">Files in Directory</div>
                        <div class="stat-card__value">{{ selectedDirectoryFileCount }}</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card__label">Total Size</div>
                        <div class="stat-card__value">{{ directoryTotalSizeMb }} MB</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-card__label">Status</div>
                        <div class="stat-card__status">
                            <span class="status-dot" :class="`status-dot--${directoryGitStatusVariant}`" />
                            <span class="stat-card__value stat-card__value--status">{{ directoryGitStatus }}</span>
                        </div>
                    </div>
                </section>

                <div class="files-panel">
                    <h2>Files</h2>
                    <ul class="file-list">
                        <li v-for="file in selectedDirectoryFiles" :key="file.path" class="file-row">
                            <input
                                type="checkbox"
                                class="file-row__checkbox"
                                :checked="selectedFiles.has(file.path)"
                                @change="toggleFileSelection(file.path)"
                            />
                            <span class="file-row__icon" v-html="FileDocumentOutlineIcon" />
                            <span class="file-row__name">{{ file.path }}</span>
                            <span class="file-row__status">
                                <span class="status-dot" :class="`status-dot--${statusVariant(file.status)}`" />
                                {{ file.status }}
                            </span>
                            <NcButton variant="tertiary" @click="openRollbackForFile(file.path)">
                                <template #icon>
                                    <span class="file-row__history-icon" v-html="ClockOutlineIcon" />
                                </template>
                                History
                            </NcButton>
                        </li>
                    </ul>
                </div>
            </template>
        </div>

        <div v-if="selectedFileCount > 0" class="selection-bar">
            <span class="selection-bar__count">{{ selectedFileCount }} file(s) selected</span>
            <NcButton variant="tertiary" @click="clearFileSelection">Clear</NcButton>
            <NcButton variant="primary" class="selection-bar__commit" @click="openCommitForSelection">
                Commit Changes…
            </NcButton>
        </div>

        <CommitDialog
            :open="commitDialogOpen"
            :files="commitDialogFiles"
            @update:open="commitDialogOpen = $event"
            @committed="onCommitted"
        />

        <RollbackPanel
            :open="rollbackPanelOpen"
            :file-path="rollbackPanelFilePath"
            @update:open="rollbackPanelOpen = $event"
            @rolled-back="onRolledBack"
        />
    </NcAppContent>
</template>

<style scoped>
.dashboard-container {
    padding: 32px 40px 90px;
    min-height: 100%;
}

h1 {
    font-size: 22px;
    font-weight: 700;
    margin: 0 0 24px;
}

h2 {
    font-size: 16px;
    font-weight: 700;
}

.banner {
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 20px;
    font-size: 13px;
    font-weight: 600;
}

.banner--error {
    background-color: color-mix(in srgb, var(--color-error) 15%, transparent);
    color: var(--color-error);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 32px;
}

.stats-grid--detail {
    grid-template-columns: repeat(3, 1fr);
    max-width: 720px;
    margin-bottom: 28px;
}

.stat-card {
    background-color: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: 10px;
    padding: 18px 20px;
}

.stat-card__label {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 8px;
}

.stat-card__value {
    font-size: 26px;
    font-weight: 700;
}

.stat-card__status {
    display: flex;
    align-items: center;
    gap: 8px;
}

.stat-card__value--status {
    font-size: 18px;
}

.status-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex: none;
}

.status-dot--clean {
    background-color: var(--color-text-maxcontrast);
}

.status-dot--modified {
    background-color: var(--color-warning);
}

.status-dot--uncommitted {
    background-color: var(--color-primary-element);
}

.status-dot--unknown {
    background-color: var(--color-text-maxcontrast);
}

.empty-panel {
    background-color: var(--color-main-background);
    border: 1px dashed var(--color-border);
    border-radius: 12px;
    padding: 48px 32px;
    text-align: center;
}

.empty-panel__icon {
    display: inline-flex;
    width: 40px;
    height: 40px;
    margin-bottom: 16px;
    color: var(--color-text-maxcontrast);
}

.empty-panel__icon :deep(svg) {
    width: 100%;
    height: 100%;
    fill: currentColor;
}

.empty-panel__title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 8px;
}

.empty-panel__copy {
    font-size: 14px;
    color: var(--color-text-maxcontrast);
    max-width: 380px;
    margin: 0 auto;
}

.directories-panel,
.files-panel {
    background-color: var(--color-main-background);
    border: 1px solid var(--color-border);
    border-radius: 10px;
    overflow: hidden;
}

.directories-panel__header {
    padding: 18px 20px;
    border-bottom: 1px solid var(--color-border);
}

.directories-panel__header h2 {
    margin: 0 0 12px;
}

.directories-panel__search {
    max-width: 320px;
}

.files-panel h2 {
    margin: 0;
    padding: 16px 20px;
    border-bottom: 1px solid var(--color-border);
}

.directory-list,
.file-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.directory-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--color-border);
    cursor: pointer;
}

.directory-row:last-child {
    border-bottom: none;
}

.directory-row:hover {
    background-color: var(--color-background-hover);
}

.directory-row--synthetic {
    cursor: default;
    color: var(--color-text-maxcontrast);
}

.directory-row--synthetic:hover {
    background-color: transparent;
}

.directory-row--synthetic .directory-row__label {
    font-weight: 400;
}

.directory-row__icon,
.file-row__icon {
    display: flex;
    width: 18px;
    height: 18px;
    flex: none;
    color: var(--color-text-maxcontrast);
}

.directory-row__icon :deep(svg),
.file-row__icon :deep(svg) {
    width: 100%;
    height: 100%;
    fill: currentColor;
}

.directory-row__label {
    flex: 1;
    font-size: 14px;
    font-weight: 600;
}

.directory-row__pill {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    background-color: var(--color-background-hover);
    padding: 3px 9px;
    border-radius: 20px;
}

.directory-row__pill--modified {
    color: var(--color-warning);
    background-color: color-mix(in srgb, var(--color-warning) 15%, transparent);
}

.directory-row__pill--uncommitted {
    color: var(--color-primary-element);
    background-color: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
}

.directory-row__chevron {
    display: flex;
    width: 14px;
    height: 14px;
    flex: none;
    color: var(--color-text-maxcontrast);
}

.directory-row__chevron :deep(svg) {
    width: 100%;
    height: 100%;
    fill: currentColor;
}

.no-match {
    padding: 24px 20px;
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    text-align: center;
}

.back-button {
    margin-bottom: 8px;
}

.file-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 13px 20px;
    border-bottom: 1px solid var(--color-border);
}

.file-row:last-child {
    border-bottom: none;
}

.file-row__checkbox {
    width: 16px;
    height: 16px;
    cursor: pointer;
    accent-color: var(--color-primary-element);
}

.file-row__name {
    flex: 1;
    font-size: 14px;
}

.file-row__status {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--color-text-maxcontrast);
}

.file-row__history-icon {
    display: flex;
    width: 13px;
    height: 13px;
}

.file-row__history-icon :deep(svg) {
    width: 100%;
    height: 100%;
    fill: currentColor;
}

.selection-bar {
    position: sticky;
    bottom: 0;
    left: 0;
    right: 0;
    background-color: var(--color-main-background);
    border-top: 1px solid var(--color-border);
    padding: 14px 40px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.08);
}

.selection-bar__count {
    font-size: 13px;
    font-weight: 600;
}

.selection-bar__commit {
    margin-left: auto;
}
</style>
