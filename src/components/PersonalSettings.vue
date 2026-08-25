<script setup lang="ts">
import NcSettingsSection from "@nextcloud/vue/components/NcSettingsSection";
import NcButton from "@nextcloud/vue/components/NcButton";
import NcNoteCard from "@nextcloud/vue/components/NcNoteCard";
import NcDialog from "@nextcloud/vue/components/NcDialog";
import NcTextField from "@nextcloud/vue/components/NcTextField";
import { ref, computed } from "vue";
import axios from "@nextcloud/axios";
import { generateOcsUrl } from "@nextcloud/router";

const confirmOpen = ref(false);
const confirmationText = ref("");
const isDeleting = ref(false);

const status = ref<null | "success" | "error">(null);
const resultMessage = ref("");

function extractErrorMessage(error: unknown, fallback: string): string {
    const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } };
    return axiosError.response?.data?.ocs?.data?.message ?? fallback;
}

function openConfirm() {
    confirmationText.value = "";
    status.value = null;
    resultMessage.value = "";
    confirmOpen.value = true;
}

function closeConfirm() {
    confirmOpen.value = false;
}

async function confirmDelete() {
    if (confirmationText.value !== "delete") return false;
    isDeleting.value = true;
    try {
        const response = await axios.post(generateOcsUrl("apps/gitcloud/history/delete"));
        status.value = "success";
        resultMessage.value = response.data.ocs.data.message;
        confirmOpen.value = false;
    } catch (error) {
        status.value = "error";
        resultMessage.value = extractErrorMessage(error, "Failed to delete commit history.");
        confirmOpen.value = false;
    } finally {
        isDeleting.value = false;
    }
    return false;
}

const confirmButtons = computed(() => [
    {
        label: "Cancel",
        variant: "tertiary" as const,
        callback: () => closeConfirm(),
    },
    {
        label: isDeleting.value ? "Deleting…" : "Permanently delete",
        variant: "error" as const,
        disabled: isDeleting.value || confirmationText.value !== "delete",
        callback: confirmDelete,
    },
]);
</script>

<template>
    <NcSettingsSection
        name="GitCloud"
        description="Danger zone: permanently delete your GitCloud commit history to reclaim disk space."
    >
        <NcButton variant="error" @click="openConfirm">
            Delete all commit history
        </NcButton>

        <NcNoteCard v-if="status === 'success'" type="success" :text="resultMessage" />
        <NcNoteCard v-if="status === 'error'" type="error" :text="resultMessage" />

        <NcDialog
            :open="confirmOpen"
            name="Delete all commit history?"
            size="small"
            :buttons="confirmButtons"
            @update:open="(value) => !value && closeConfirm()"
        >
            <p class="personal-settings__warning">
                This permanently deletes your entire GitCloud commit and rollback history. Your
                current files are not affected, but you will no longer be able to roll back to any
                previous snapshot. <strong>This cannot be undone.</strong>
            </p>
            <NcTextField
                v-model="confirmationText"
                label='Type "delete" to confirm'
                placeholder="delete"
            />
        </NcDialog>
    </NcSettingsSection>
</template>

<style scoped>
.personal-settings__warning {
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 12px;
}
</style>
