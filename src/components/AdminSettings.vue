<script setup lang="ts">
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'

const maxFileSizeMb = ref<number>(loadState('gitcloud', 'max-file-size-mb', 100))
const enforcementMode = ref<string>(loadState('gitcloud', 'enforcement-mode', 'block'))

const status = ref<null | 'loading' | 'success' | 'error'>(null)
const resultMessage = ref('')

function extractErrorMessage(error: unknown, fallback: string): string {
	const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } }
	return axiosError.response?.data?.ocs?.data?.message ?? fallback
}

async function save() {
	status.value = 'loading'
	try {
		const response = await axios.post(generateOcsUrl('apps/gitcloud/admin/settings'), {
			maxFileSizeMb: maxFileSizeMb.value,
			enforcementMode: enforcementMode.value,
		})
		status.value = 'success'
		resultMessage.value = response.data.ocs.data.message
	} catch (error) {
		status.value = 'error'
		resultMessage.value = extractErrorMessage(error, 'Failed to save GitCloud settings.')
	}
}
</script>

<template>
	<NcSettingsSection
		name="GitCloud"
		description="Configure the maximum file size GitCloud will commit and how oversized files are handled.">
		<NcInputField
			v-model="maxFileSizeMb"
			type="number"
			label="Maximum file size (MB)"
			:min="1" />

		<div class="admin-settings__enforcement">
			<NcCheckboxRadioSwitch
				v-model="enforcementMode"
				value="block"
				name="enforcement-mode"
				type="radio">
				Block commits containing oversized files
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="enforcementMode"
				value="warn"
				name="enforcement-mode"
				type="radio">
				Warn, but still commit oversized files
			</NcCheckboxRadioSwitch>
		</div>

		<NcButton variant="primary" :disabled="status === 'loading'" @click="save">
			{{ status === "loading" ? "Saving…" : "Save" }}
		</NcButton>

		<NcNoteCard v-if="status === 'success'" type="success" :text="resultMessage" />
		<NcNoteCard v-if="status === 'error'" type="error" :text="resultMessage" />
	</NcSettingsSection>
</template>

<style scoped>
.admin-settings__enforcement {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 12px 0;
}
</style>
