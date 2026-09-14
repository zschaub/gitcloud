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

	// return false/undefined to hide, true to show.
	// GitCloud's repository working tree is the user's own home storage only, so
	// hide the action on anything sitting on another mount - group folders,
	// received shares, external storages. `nc:mount-type` is part of
	// @nextcloud/files' default PROPFIND set and is computed per node from its
	// mount point, so it is populated for every descendant of a mount and not
	// just the mount root; it is '' for the plain home mount. Any non-empty value
	// hides the action, rather than matching a specific mount type, so a mount
	// type GitCloud has never heard of is hidden too. The backend check remains
	// authoritative - a home folder containing a nested mount still passes here.
	enabled(context) {
		return context.nodes.every((node) => !node.attributes?.['mount-type'])
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
