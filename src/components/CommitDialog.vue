<script setup lang="ts">
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ref, computed, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const props = defineProps<{
    open: boolean;
    files: string[];
}>()

const emit = defineEmits<{
    'update:open': [value: boolean];
    committed: [];
}>()

const message = ref('')
const status = ref<null | 'loading' | 'success' | 'error'>(null)
const resultMessage = ref('')
const warnings = ref<string[]>([])

const pendingConfirmation = ref(false)
const pendingWarningFiles = ref<string[]>([])
const isConfirming = ref(false)

watch(
	() => props.open,
	(isOpen) => {
		if (isOpen) {
			message.value = ''
			status.value = null
			resultMessage.value = ''
			warnings.value = []
			pendingConfirmation.value = false
			pendingWarningFiles.value = []
		}
	},
)

function fileName(path: string): string {
	return path.split('/').pop() ?? path
}

const title = computed(() =>
	props.files.length > 1 ? `Commit ${props.files.length} files` : 'Commit file',
)

const commitDisabled = computed(
	() => message.value.trim() === '' || status.value === 'loading' || status.value === 'success',
)

async function postCommit(confirmed: boolean) {
	return axios.post(generateOcsUrl('apps/gitcloud/commit'), {
		files: props.files,
		message: message.value,
		confirmed,
	})
}

async function submitCommit() {
	if (message.value.trim() === '' || status.value === 'loading') return false

	status.value = 'loading'
	try {
		const response = await postCommit(false)
		const data = response.data.ocs.data
		if (data.status === 'warning') {
			pendingWarningFiles.value = data.warnings ?? []
			pendingConfirmation.value = true
			status.value = null
			return false
		}
		status.value = 'success'
		resultMessage.value = data.message
		warnings.value = data.warnings ?? []
		emit('committed')
	} catch (error) {
		status.value = 'error'
		const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } }
		resultMessage.value
            = axiosError.response?.data?.ocs?.data?.message ?? 'Failed to commit changes to GitCloud.'
	}
	return false
}

async function confirmCommit() {
	isConfirming.value = true
	try {
		const response = await postCommit(true)
		pendingConfirmation.value = false
		status.value = 'success'
		resultMessage.value = response.data.ocs.data.message
		warnings.value = response.data.ocs.data.warnings ?? []
		emit('committed')
	} catch (error) {
		pendingConfirmation.value = false
		status.value = 'error'
		const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } }
		resultMessage.value
            = axiosError.response?.data?.ocs?.data?.message ?? 'Failed to commit changes to GitCloud.'
	} finally {
		isConfirming.value = false
	}
}

function cancelPendingConfirmation() {
	pendingConfirmation.value = false
	pendingWarningFiles.value = []
}

function close() {
	emit('update:open', false)
}

const buttons = computed(() => {
	const closeButton = {
		label: status.value === 'success' ? 'Done' : 'Cancel',
		variant: 'tertiary' as const,
		callback: () => {
			close()
		},
	}
	if (status.value === 'success') {
		return [closeButton]
	}
	return [
		closeButton,
		{
			label: status.value === 'loading' ? 'Committing…' : 'Commit',
			variant: 'primary' as const,
			disabled: commitDisabled.value,
			callback: submitCommit,
		},
	]
})

const confirmButtons = computed(() => [
	{
		label: 'Cancel',
		variant: 'tertiary' as const,
		callback: () => cancelPendingConfirmation(),
	},
	{
		label: isConfirming.value ? 'Committing…' : 'Commit',
		variant: 'primary' as const,
		disabled: isConfirming.value,
		callback: confirmCommit,
	},
])
</script>

<template>
	<NcDialog
		:open="open"
		:name="title"
		size="normal"
		:buttons="buttons"
		@update:open="(value) => !value && close()">
		<div class="commit-dialog">
			<div class="commit-dialog__chips">
				<span v-for="file in files" :key="file" class="commit-dialog__chip">
					{{ fileName(file) }}
				</span>
			</div>

			<NcTextArea
				:model-value="message"
				label="Commit message"
				placeholder="Describe what changed…"
				:rows="3"
				:disabled="status === 'loading' || status === 'success'"
				@update:model-value="message = String($event)" />

			<p v-if="status === 'success'" class="commit-dialog__result commit-dialog__result--success">
				{{ resultMessage }}
			</p>
			<p v-if="status === 'error'" class="commit-dialog__result commit-dialog__result--error">
				{{ resultMessage }}
			</p>
			<NcNoteCard v-if="warnings.length > 0" type="warning" heading="Oversized files committed anyway">
				<ul class="commit-dialog__warnings">
					<li v-for="file in warnings" :key="file">
						{{ fileName(file) }}
					</li>
				</ul>
			</NcNoteCard>
		</div>
	</NcDialog>

	<NcDialog
		:open="pendingConfirmation"
		name="File size warning"
		size="small"
		:buttons="confirmButtons"
		@update:open="(value) => !value && cancelPendingConfirmation()">
		<p class="commit-dialog__confirm-message">
			Git may not handle this correctly due to size:
		</p>
		<ul class="commit-dialog__warnings">
			<li v-for="file in pendingWarningFiles" :key="file">
				{{ fileName(file) }}
			</li>
		</ul>
		<p class="commit-dialog__confirm-message">
			Commit anyway, or cancel and remove the oversized file(s) first.
		</p>
	</NcDialog>
</template>

<style scoped>
.commit-dialog {
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 4px 0 12px;
}

.commit-dialog__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.commit-dialog__chip {
    font-size: 12px;
    background-color: var(--color-background-hover);
    border: 1px solid var(--color-border);
    border-radius: 6px;
    padding: 4px 9px;
}

.commit-dialog__result {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    margin: 0;
}

.commit-dialog__result--success {
    color: var(--color-success);
}

.commit-dialog__result--error {
    color: var(--color-error);
}

.commit-dialog__warnings {
    margin: 0;
    padding-inline-start: 18px;
}

.commit-dialog__confirm-message {
    font-size: 13px;
    line-height: 1.5;
}
</style>
