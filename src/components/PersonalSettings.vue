<script setup lang="ts">
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { extractBlobErrorMessage, extractErrorMessage } from '../utils/ocs'

const confirmOpen = ref(false)
const confirmationText = ref('')
const isDeleting = ref(false)
const isDownloading = ref(false)

const status = ref<null | 'success' | 'error'>(null)
const resultMessage = ref('')

const backupStatus = ref<null | 'error'>(null)
const backupErrorMessage = ref('')

async function downloadBackup() {
	isDownloading.value = true
	backupStatus.value = null
	backupErrorMessage.value = ''
	try {
		const response = await axios.get(generateOcsUrl('apps/gitcloud/history/backup'), {
			responseType: 'blob',
		})
		const url = window.URL.createObjectURL(response.data as Blob)
		const link = document.createElement('a')
		link.href = url
		link.download = `gitcloud-backup-${new Date().toISOString().slice(0, 10)}.tar.gz`
		document.body.appendChild(link)
		link.click()
		link.remove()
		window.URL.revokeObjectURL(url)
	} catch (error) {
		backupStatus.value = 'error'
		backupErrorMessage.value = await extractBlobErrorMessage(error, 'Failed to download backup.')
	} finally {
		isDownloading.value = false
	}
}

function openConfirm() {
	confirmationText.value = ''
	status.value = null
	resultMessage.value = ''
	confirmOpen.value = true
}

function closeConfirm() {
	confirmOpen.value = false
}

async function confirmDelete() {
	if (confirmationText.value !== 'delete') return false
	isDeleting.value = true
	try {
		const response = await axios.post(generateOcsUrl('apps/gitcloud/history/delete'))
		status.value = 'success'
		resultMessage.value = response.data.ocs.data.message
		confirmOpen.value = false
	} catch (error) {
		status.value = 'error'
		resultMessage.value = extractErrorMessage(error, 'Failed to delete commit history.')
		confirmOpen.value = false
	} finally {
		isDeleting.value = false
	}
	return false
}

const confirmButtons = computed(() => [
	{
		label: 'Cancel',
		variant: 'tertiary' as const,
		callback: () => closeConfirm(),
	},
	{
		label: isDeleting.value ? 'Deleting…' : 'Permanently delete',
		variant: 'error' as const,
		disabled: isDeleting.value || confirmationText.value !== 'delete',
		callback: confirmDelete,
	},
])
</script>

<template>
	<NcSettingsSection
		name="Back up commit history"
		description="Download a backup of your GitCloud commit history. This does not include your files themselves - only the Git repository used for commits and rollbacks.">
		<NcButton :disabled="isDownloading" @click="downloadBackup">
			{{ isDownloading ? 'Preparing backup…' : 'Download backup' }}
		</NcButton>

		<NcNoteCard v-if="backupStatus === 'error'" type="error" :text="backupErrorMessage" />
	</NcSettingsSection>

	<NcSettingsSection
		name="Danger zone"
		description="Permanently delete your GitCloud commit history to reclaim disk space. Your files are not affected.">
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
			@update:open="(value) => !value && closeConfirm()">
			<p class="personal-settings__warning">
				This permanently deletes your entire GitCloud commit and rollback history. Your
				current files are not affected, but you will no longer be able to roll back to any
				previous snapshot. <strong>This cannot be undone.</strong>
			</p>
			<NcTextField
				v-model="confirmationText"
				label="Type &quot;delete&quot; to confirm"
				placeholder="delete" />
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
