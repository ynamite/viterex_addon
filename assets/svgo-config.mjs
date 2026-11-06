/**
 * Canonical SVGO config for viterex_addon. Single source of truth — both the
 * Vite plugin (assets/viterex-vite-plugin.js) and the PHP shell-out path
 * (lib/Svg/SvgoCli.php) consume this file directly:
 *
 *   - JS: `import config from "./svgo-config.mjs"` then `optimize(svg, config)`.
 *   - PHP: `npx --no-install svgo --config <abs-path-to-this-file>`.
 *
 * Plugin notes:
 *
 *   - `preset-default` is SVGO's standard size pass, minus the steps that destroy authored structure (see below).
 *   - `removeScripts` is OFF in v4's preset-default but is the plugin that
 *     strips both `<script>` elements AND `on*` event-handler attributes —
 *     the security-relevant pieces for media-pool uploads.
 *
 * `prefixIds` is intentionally NOT here — id/class scoping for inlined SVGs
 * happens at runtime in `lib/Svg/IdPrefixer.php`, not at optimize time.
 * Reason: keeping it out of the disk-mutation pass means the source files
 * stay generic (reusable as `<img src>`, `background-image`), and the
 * optimization pass remains idempotent (re-running doesn't re-prefix).
 */
export default {
	multipass: true,
	plugins: [
		{
			name: "preset-default",
			params: {
				// Keep the authored structure: ids (CSS/JS hooks, e.g. chart groups `series` / `bars`), groups and
				// separate paths (one animated element per bar). Stripping and shortening ids does not prevent
				// collisions between inlined SVGs either, it makes them likelier (`a`, `b`, …); IdPrefixer scopes ids
				// per file at inline time instead. Costs a few percent of file size.
				overrides: { cleanupIds: false, collapseGroups: false, mergePaths: false },
			},
		},
		"removeScripts",
	],
};
