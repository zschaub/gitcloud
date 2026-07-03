import { registerFileAction } from "@nextcloud/files";
import CloudIcon from "@mdi/svg/svg/cloud-upload.svg?raw"; // or any inline SVG string

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
    // TODO: wire this up to the backend endpoint
    console.log("[gitcloud] add-to-gitcloud stub for", context.nodes[0].path);
    return null;
  },

  order: 100,
};

export function registerAddToGitCloudAction() {
  registerFileAction(addToGitCloudAction);
}
