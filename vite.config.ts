import { createAppConfig } from "@nextcloud/vite-config";
import { join, resolve } from "path";

export default createAppConfig(
  {
    main: resolve(join("src", "main.ts")),
    "files-actions": resolve(join("src", "files-actions.js")),
    "settings-admin": resolve(join("src", "settings-admin.ts")),
    "settings-personal": resolve(join("src", "settings-personal.ts")),
  },
  {
    createEmptyCSSEntryPoints: true,
    extractLicenseInformation: {},
  },
);
