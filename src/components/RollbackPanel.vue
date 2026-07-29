<script setup lang="ts">
import NcDialog from "@nextcloud/vue/components/NcDialog";
import NcButton from "@nextcloud/vue/components/NcButton";
import NcLoadingIcon from "@nextcloud/vue/components/NcLoadingIcon";
import CloseIcon from "@mdi/svg/svg/close.svg?raw";
import { ref, computed, watch } from "vue";
import axios from "@nextcloud/axios";
import { generateOcsUrl } from "@nextcloud/router";

const props = defineProps<{
    open: boolean;
    filePath: string | null;
}>();

const emit = defineEmits<{
    "update:open": [value: boolean];
    "rolled-back": [];
}>();

interface Snapshot {
    id: number;
    commitHash: string;
    message: string;
    status: string;
    createdAt: number;
    parentSnapshotId: number | null;
}

const snapshots = ref<Snapshot[]>([]);
const loadError = ref("");
const isLoading = ref(false);

const rollbackStatus = ref<null | "success" | "error">(null);
const rollbackResultMessage = ref("");

const confirmSnapshot = ref<Snapshot | null>(null);
const isRollingBack = ref(false);

function fileName(path: string): string {
    return path.split("/").pop() ?? path;
}

function extractErrorMessage(error: unknown, fallback: string): string {
    const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } };
    return axiosError.response?.data?.ocs?.data?.message ?? fallback;
}

async function loadSnapshots() {
    if (!props.filePath) return;
    isLoading.value = true;
    loadError.value = "";
    try {
        const response = await axios.get(generateOcsUrl("apps/gitcloud/snapshots"), {
            params: { filePath: props.filePath },
        });
        snapshots.value = response.data.ocs.data.snapshots;
    } catch (error) {
        loadError.value = extractErrorMessage(error, "Failed to load snapshots for the selected file.");
    } finally {
        isLoading.value = false;
    }
}

watch(
    () => [props.open, props.filePath],
    ([isOpen]) => {
        if (isOpen) {
            snapshots.value = [];
            loadError.value = "";
            rollbackStatus.value = null;
            rollbackResultMessage.value = "";
            confirmSnapshot.value = null;
            loadSnapshots();
        }
    },
);

function formatRelative(unixSeconds: number): string {
    const diffMs = Date.now() - unixSeconds * 1000;
    const minutes = Math.floor(diffMs / 60000);
    if (minutes < 1) return "just now";
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;
    return `${Math.floor(days / 30)}mo ago`;
}

function formatAbsolute(unixSeconds: number): string {
    return new Date(unixSeconds * 1000).toLocaleString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
    });
}

function requestRollback(snapshot: Snapshot) {
    confirmSnapshot.value = snapshot;
}

async function confirmRollback() {
    if (!confirmSnapshot.value || !props.filePath) return false;
    isRollingBack.value = true;
    try {
        const response = await axios.post(generateOcsUrl("apps/gitcloud/rollback"), {
            filePath: props.filePath,
            snapshotId: confirmSnapshot.value.id,
        });
        rollbackStatus.value = "success";
        rollbackResultMessage.value = response.data.ocs.data.message;
        confirmSnapshot.value = null;
        emit("rolled-back");
        await loadSnapshots();
    } catch (error) {
        rollbackStatus.value = "error";
        rollbackResultMessage.value = extractErrorMessage(error, "Failed to roll back the selected file.");
        confirmSnapshot.value = null;
    } finally {
        isRollingBack.value = false;
    }
    return false;
}

function cancelRollbackConfirm() {
    confirmSnapshot.value = null;
}

function close() {
    emit("update:open", false);
}

const confirmButtons = computed(() => [
    {
        label: "Cancel",
        variant: "tertiary" as const,
        callback: () => cancelRollbackConfirm(),
    },
    {
        label: isRollingBack.value ? "Rolling back…" : "Rollback",
        variant: "error" as const,
        disabled: isRollingBack.value,
        callback: confirmRollback,
    },
]);
</script>

<template>
    <div v-if="open" class="rollback-panel__backdrop" @click.self="close">
        <div class="rollback-panel">
            <div class="rollback-panel__header">
                <div>
                    <div class="rollback-panel__eyebrow">Snapshot history</div>
                    <div class="rollback-panel__filename">{{ filePath ? fileName(filePath) : "" }}</div>
                </div>
                <NcButton variant="tertiary" aria-label="Close" @click="close">
                    <template #icon>
                        <span class="rollback-panel__close-icon" v-html="CloseIcon" />
                    </template>
                </NcButton>
            </div>

            <p v-if="rollbackStatus === 'success'" class="rollback-panel__banner rollback-panel__banner--success">
                {{ rollbackResultMessage }}
            </p>
            <p v-if="rollbackStatus === 'error'" class="rollback-panel__banner rollback-panel__banner--error">
                {{ rollbackResultMessage }}
            </p>

            <div class="rollback-panel__body">
                <NcLoadingIcon v-if="isLoading" :size="32" />
                <p v-else-if="loadError" class="rollback-panel__banner rollback-panel__banner--error">
                    {{ loadError }}
                </p>
                <p v-else-if="snapshots.length === 0" class="rollback-panel__empty">
                    No snapshots found for this file.
                </p>
                <div v-else class="rollback-panel__timeline">
                    <div v-for="(snapshot, index) in snapshots" :key="snapshot.id" class="rollback-panel__row">
                        <div class="rollback-panel__rail">
                            <span
                                class="rollback-panel__node"
                                :class="{ 'rollback-panel__node--current': index === 0 }"
                            />
                            <span v-if="index < snapshots.length - 1" class="rollback-panel__connector" />
                        </div>
                        <div class="rollback-panel__content">
                            <div class="rollback-panel__meta-line">
                                <span class="rollback-panel__message">{{ snapshot.message }}</span>
                                <span
                                    class="rollback-panel__pill"
                                    :class="
                                        snapshot.status === 'committed'
                                            ? 'rollback-panel__pill--success'
                                            : 'rollback-panel__pill--warning'
                                    "
                                >
                                    {{ snapshot.status === "committed" ? "Committed" : "Rolled back" }}
                                </span>
                            </div>
                            <div class="rollback-panel__timestamp">
                                {{ formatRelative(snapshot.createdAt) }} · {{ formatAbsolute(snapshot.createdAt) }} ·
                                <span class="rollback-panel__hash">{{ snapshot.commitHash.slice(0, 7) }}</span>
                            </div>
                            <span v-if="index === 0" class="rollback-panel__current-label">Current version</span>
                            <NcButton v-else variant="secondary" @click="requestRollback(snapshot)">
                                Rollback to this snapshot
                            </NcButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <NcDialog
            :open="confirmSnapshot !== null"
            name="Rollback this file?"
            size="small"
            :buttons="confirmButtons"
            @update:open="(value) => !value && cancelRollbackConfirm()"
        >
            <p class="rollback-confirm__message">
                This replaces the current content of
                <strong>{{ filePath ? fileName(filePath) : "" }}</strong>
                with the snapshot "{{ confirmSnapshot?.message }}" ({{
                    confirmSnapshot ? formatRelative(confirmSnapshot.createdAt) : ""
                }}). This can't be undone automatically.
            </p>
        </NcDialog>
    </div>
</template>

<style scoped>
.rollback-panel__backdrop {
    position: fixed;
    inset: 0;
    background-color: rgba(0, 0, 0, 0.45);
    display: flex;
    justify-content: flex-end;
    z-index: 2000;
}

.rollback-panel {
    background-color: var(--color-main-background);
    color: var(--color-main-text);
    width: 420px;
    max-width: 92vw;
    height: 100%;
    box-shadow: -20px 0 60px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
}

.rollback-panel__header {
    padding: 18px 22px;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
}

.rollback-panel__eyebrow {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin-bottom: 2px;
}

.rollback-panel__filename {
    font-size: 16px;
    font-weight: 700;
}

.rollback-panel__close-icon {
    display: flex;
    width: 16px;
    height: 16px;
}

.rollback-panel__close-icon :deep(svg) {
    width: 100%;
    height: 100%;
    fill: currentColor;
}

.rollback-panel__banner {
    margin: 16px 22px 0;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
}

.rollback-panel__banner--success {
    background-color: color-mix(in srgb, var(--color-success) 15%, transparent);
    color: var(--color-success);
}

.rollback-panel__banner--error {
    background-color: color-mix(in srgb, var(--color-error) 15%, transparent);
    color: var(--color-error);
}

.rollback-panel__body {
    flex: 1;
    overflow: auto;
    padding: 22px;
}

.rollback-panel__empty {
    color: var(--color-text-maxcontrast);
    font-size: 13px;
    text-align: center;
}

.rollback-panel__row {
    display: flex;
    gap: 14px;
}

.rollback-panel__rail {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: none;
}

.rollback-panel__node {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background-color: var(--color-text-maxcontrast);
    flex: none;
    margin-top: 2px;
}

.rollback-panel__node--current {
    background-color: var(--color-primary-element);
}

.rollback-panel__connector {
    width: 2px;
    flex: 1;
    min-height: 36px;
    background-color: var(--color-border);
}

.rollback-panel__content {
    flex: 1;
    padding-bottom: 22px;
}

.rollback-panel__meta-line {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 3px;
}

.rollback-panel__message {
    font-size: 13.5px;
    font-weight: 600;
}

.rollback-panel__pill {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 20px;
}

.rollback-panel__pill--success {
    background-color: color-mix(in srgb, var(--color-success) 15%, transparent);
    color: var(--color-success);
}

.rollback-panel__pill--warning {
    background-color: var(--color-warning);
    color: var(--color-main-background);
}

.rollback-panel__timestamp {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    margin-bottom: 8px;
}

.rollback-panel__hash {
    font-family: ui-monospace, monospace;
}

.rollback-panel__current-label {
    font-size: 12px;
    color: var(--color-text-maxcontrast);
    font-weight: 600;
}

.rollback-confirm__message {
    font-size: 13px;
    color: var(--color-text-maxcontrast);
    line-height: 1.5;
}

.rollback-confirm__message strong {
    color: var(--color-main-text);
}
</style>
