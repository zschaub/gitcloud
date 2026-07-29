import { registerFileAction } from "@nextcloud/files";
import { createApp, h } from "vue";
import CloudIcon from "@mdi/svg/svg/cloud-upload.svg?raw"; // or any inline SVG string
import CommitDialog from "../components/CommitDialog.vue";

export const addToGitCloudAction = {
  id: "gitcloud-add-to-gitcloud",

  displayName() {
    return "Add to GitCloud";
  },

  iconSvgInline: () => CloudIcon,

  // return false/undefined to hide, true to show
  enabled() {
    return true;
  },

  // false = never inline, only shows in the "..." / right-click menu
  inline: () => false,

  async exec(context) {
    const node = context.nodes[0];

    return new Promise((resolve) => {
      const mountEl = document.createElement("div");
      document.body.appendChild(mountEl);

      let committed = false;

      const app = createApp({
        data() {
          return { open: true };
        },
        methods: {
          onUpdateOpen(value) {
            this.open = value;
            if (!value) {
              app.unmount();
              mountEl.remove();
              resolve(committed);
            }
          },
          onCommitted() {
            committed = true;
          },
        },
        render() {
          return h(CommitDialog, {
            open: this.open,
            files: [node.path],
            "onUpdate:open": this.onUpdateOpen,
            onCommitted: this.onCommitted,
          });
        },
      });

      app.mount(mountEl);
    });
  },

  order: 100,
};

export function registerAddToGitCloudAction() {
  registerFileAction(addToGitCloudAction);
}
