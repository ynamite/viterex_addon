import viterex, { fixTailwindFullReload } from "__VITEREX_PLUGIN_IMPORT_PATH__";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";

// Customize via:
//   - viterex({ input: [...], refresh: [...], detectTls: false, injectConfig: false })
//   - any defineConfig() field below — your values win via Vite's mergeConfig
//
// To switch to a downstream addon's preset (e.g. redaxo-massif), replace `viterex` with
// e.g. `import massif from "./public/assets/addons/massif/massif-vite-plugin.js";`
// and call `massif()` in the plugins array — it wraps `viterex()` internally.
export default defineConfig({
	plugins: [tailwindcss(), fixTailwindFullReload(), viterex()],
});
