<script setup lang="ts">
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { computed, onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'

const maxFileSizeMb = ref<number>(loadState('gitcloud', 'max-file-size-mb', 100))
const enforcementMode = ref<string>(loadState('gitcloud', 'enforcement-mode', 'block'))
const gitBinaryMode = ref<string>(loadState('gitcloud', 'git-binary-mode', 'auto'))

const status = ref<null | 'loading' | 'success' | 'error'>(null)
const resultMessage = ref('')

interface GitBinaryStatus {
	systemGitAvailable: boolean
	staticGitAvailable: boolean
	resolvedBinary: string
	architecture: string | null
	installedVersion: string | null
	pinnedVersion: string | null
	updateAvailable: boolean
}

const gitStatus = ref<GitBinaryStatus | null>(null)
const downloadStatus = ref<null | 'loading' | 'success' | 'error'>(null)
const downloadMessage = ref('')

const checkingForUpdate = ref(false)
const hasCheckedForUpdate = ref(false)
const showUpdateConfirm = ref(false)

const updateAvailableMessage = computed(() => {
	if (!gitStatus.value) return ''
	const pinned = gitStatus.value.pinnedVersion ? ` (${gitStatus.value.pinnedVersion})` : ''
	const installed = gitStatus.value.installedVersion ? ` — you currently have ${gitStatus.value.installedVersion}` : ''
	return `A newer static git build is available${pinned}${installed}.`
})

function extractErrorMessage(error: unknown, fallback: string): string {
	const axiosError = error as { response?: { data?: { ocs?: { data?: { message?: string } } } } }
	return axiosError.response?.data?.ocs?.data?.message ?? fallback
}

async function loadGitStatus() {
	try {
		const response = await axios.get(generateOcsUrl('apps/gitcloud/admin/git-binary-status'))
		gitStatus.value = response.data.ocs.data
	} catch {
		gitStatus.value = null
	}
}

async function save() {
	status.value = 'loading'
	try {
		const response = await axios.post(generateOcsUrl('apps/gitcloud/admin/settings'), {
			maxFileSizeMb: maxFileSizeMb.value,
			enforcementMode: enforcementMode.value,
			gitBinaryMode: gitBinaryMode.value,
		})
		status.value = 'success'
		resultMessage.value = response.data.ocs.data.message
		await loadGitStatus()
	} catch (error) {
		status.value = 'error'
		resultMessage.value = extractErrorMessage(error, 'Failed to save GitCloud settings.')
	}
}

async function downloadStaticGit() {
	downloadStatus.value = 'loading'
	try {
		const response = await axios.post(generateOcsUrl('apps/gitcloud/admin/download-static-git'))
		downloadStatus.value = 'success'
		downloadMessage.value = response.data.ocs.data.message
		await loadGitStatus()
	} catch (error) {
		downloadStatus.value = 'error'
		downloadMessage.value = extractErrorMessage(error, 'Failed to download the static git binary.')
	}
}

async function checkForUpdates() {
	checkingForUpdate.value = true
	hasCheckedForUpdate.value = false
	await loadGitStatus()
	checkingForUpdate.value = false
	hasCheckedForUpdate.value = true
}

function cancelUpdateConfirm() {
	showUpdateConfirm.value = false
}

async function confirmUpdate() {
	await downloadStaticGit()
	showUpdateConfirm.value = false
	return false
}

const updateConfirmButtons = computed(() => [
	{
		label: 'Cancel',
		variant: 'tertiary' as const,
		callback: () => cancelUpdateConfirm(),
	},
	{
		label: downloadStatus.value === 'loading' ? 'Updating…' : 'Update',
		variant: 'primary' as const,
		disabled: downloadStatus.value === 'loading',
		callback: confirmUpdate,
	},
])

onMounted(loadGitStatus)
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

		<h3 class="admin-settings__subheading">
			Git binary
		</h3>
		<p>
			Choose which git executable GitCloud uses: automatically prefer a bundled static
			binary when one is available, always use the system-installed git on PATH, or
			always use the bundled static binary.
		</p>

		<div class="admin-settings__enforcement">
			<NcCheckboxRadioSwitch v-model="gitBinaryMode"
				value="auto"
				name="git-binary-mode"
				type="radio">
				Automatic (prefer bundled static git, fall back to system git)
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="gitBinaryMode"
				value="system"
				name="git-binary-mode"
				type="radio">
				Always use system git
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="gitBinaryMode"
				value="static"
				name="git-binary-mode"
				type="radio">
				Always use bundled static git
			</NcCheckboxRadioSwitch>
		</div>

		<ul v-if="gitStatus" class="admin-settings__git-status">
			<li>System git: {{ gitStatus.systemGitAvailable ? 'Available' : 'Not found' }}</li>
			<li>
				Static git{{ gitStatus.architecture ? ` (${gitStatus.architecture})` : '' }}:
				{{ gitStatus.staticGitAvailable
					? `Downloaded${gitStatus.installedVersion ? ' (' + gitStatus.installedVersion + ')' : ''}`
					: 'Not downloaded' }}
			</li>
			<li>Currently in use: {{ gitStatus.resolvedBinary === 'none' ? 'None — git is unavailable' : gitStatus.resolvedBinary }}</li>
		</ul>

		<p v-if="gitStatus && gitStatus.architecture === null">
			A bundled static git binary is only available for Linux amd64/arm64 servers.
		</p>

		<NcButton
			v-if="gitStatus && !gitStatus.staticGitAvailable && gitStatus.architecture"
			:disabled="downloadStatus === 'loading'"
			@click="downloadStaticGit">
			{{ downloadStatus === "loading" ? "Downloading…" : "Download static git" }}
		</NcButton>

		<NcButton
			v-if="gitStatus && gitStatus.staticGitAvailable"
			:disabled="checkingForUpdate"
			@click="checkForUpdates">
			{{ checkingForUpdate ? "Checking…" : "Check for updates" }}
		</NcButton>

		<NcNoteCard v-if="downloadStatus === 'success'" type="success" :text="downloadMessage" />
		<NcNoteCard v-if="downloadStatus === 'error'" type="error" :text="downloadMessage" />

		<NcNoteCard
			v-if="hasCheckedForUpdate && gitStatus && !gitStatus.updateAvailable"
			type="success"
			text="Static git is already up to date." />

		<template v-if="gitStatus && gitStatus.updateAvailable">
			<NcNoteCard type="warning" :text="updateAvailableMessage" />
			<NcButton variant="primary" @click="showUpdateConfirm = true">
				Update static git
			</NcButton>
		</template>

		<NcDialog
			:open="showUpdateConfirm"
			name="Update static git?"
			size="small"
			:buttons="updateConfirmButtons"
			@update:open="(value) => !value && cancelUpdateConfirm()">
			<p>
				Download and install static git {{ gitStatus?.pinnedVersion }}, replacing the
				currently installed {{ gitStatus?.installedVersion }}?
			</p>
		</NcDialog>
	</NcSettingsSection>
</template>

<style scoped>
.admin-settings__enforcement {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin: 12px 0;
}

.admin-settings__subheading {
    margin-block-start: 24px;
}

.admin-settings__git-status {
    margin: 12px 0;
    padding-inline-start: 20px;
}
</style>
