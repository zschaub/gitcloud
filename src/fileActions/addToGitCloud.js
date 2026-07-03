import { registerFileAction } from "@nextcloud/files";
import axios from "@nextcloud/axios";
import { generateOcsUrl } from "@nextcloud/router";
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
    const node = context.nodes[0];

    const message = window.prompt(
      `Commit message for "${node.basename ?? node.path}":`,
      "",
    );
    if (message === null) {
      // User cancelled the prompt.
      return null;
    }
    if (message.trim() === "") {
      window.alert("A commit message is required.");
      return false;
    }

    try {
      const response = await axios.post(generateOcsUrl("apps/gitcloud/commit"), {
        files: [node.path],
        message,
      });
      window.alert(response.data.ocs.data.message);
      return true;
    } catch (error) {
      const errorMessage =
        error.response?.data?.ocs?.data?.message ??
        "Failed to commit changes to GitCloud.";
      window.alert(errorMessage);
      return false;
    }
  },

  order: 100,
};

export function registerAddToGitCloudAction() {
  registerFileAction(addToGitCloudAction);
}
