import { registerFileAction } from '@nextcloud/files'
import { createApp, h } from 'vue'
// eslint-disable-next-line import/no-unresolved -- Vite-only `?raw` suffix import; eslint-plugin-import's resolver doesn't understand Vite's raw-asset query imports
import CloudIcon from '@mdi/svg/svg/cloud-upload.svg?raw'
import CommitDialog from '../components/CommitDialog.vue'

export const addToGitCloudAction = {
	id: 'gitcloud-add-to-gitcloud',

	displayName() {
		return 'Add to GitCloud'
	},

	iconSvgInline: () => CloudIcon,

	// return false/undefined to hide, true to show
	enabled() {
		return true
	},

	// false = never inline, only shows in the "..." / right-click menu
	inline: () => false,

	async exec(context) {
		const paths = context.nodes.map((node) => node.path)

		return new Promise((resolve) => {
			const mountEl = document.createElement('div')
			document.body.appendChild(mountEl)

			let committed = false

			const app = createApp({
				data() {
					return { open: true }
				},
				methods: {
					onUpdateOpen(value) {
						this.open = value
						if (!value) {
							app.unmount()
							mountEl.remove()
							resolve(committed)
						}
					},
					onCommitted() {
						committed = true
					},
				},
				render() {
					return h(CommitDialog, {
						open: this.open,
						files: paths,
						'onUpdate:open': this.onUpdateOpen,
						onCommitted: this.onCommitted,
					})
				},
			})

			app.mount(mountEl)
		})
	},

	order: 100,
}

export function registerAddToGitCloudAction() {
	registerFileAction(addToGitCloudAction)
}
