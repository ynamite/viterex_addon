/**
 * ViteRex — Vite plugin (Laravel-vite-plugin-style).
 *
 * Shipped INSIDE the addon at `viterex-addon/assets/viterex-vite-plugin.js`.
 * Redaxo's package manager auto-copies the addon's `assets/` tree to
 * `<frontend>/assets/addons/viterex_addon/` on every (re)install — so this file
 * lands at the location the user's `vite.config.js` imports from.
 *
 * Default usage (scaffolded vite.config.js):
 *
 *   import { defineConfig } from "vite";
 *   import tailwindcss from "@tailwindcss/vite";
 *   import viterex, { fixTailwindFullReload } from "./public/assets/addons/viterex_addon/viterex-vite-plugin.js";
 *
 *   export default defineConfig({
 *     plugins: [tailwindcss(), fixTailwindFullReload(), viterex()],
 *   });
 *
 * Customizing:
 *
 *   viterex({ input: ["src/admin/main.js"] })       // override entries
 *   viterex({ refresh: ["src/templates/**\/*.php"] }) // narrower live-reload
 *   viterex({ refresh: false })                      // disable live-reload
 *   viterex({ detectTls: false })                    // skip mkcert auto-detect
 *   viterex({ injectConfig: false })                 // ESCAPE HATCH: keep only hot-file
 *
 * Anything you put in your own `defineConfig({...})` wins via Vite's mergeConfig.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
import browserslist from "browserslist";
import { browserslistToTargets } from "lightningcss";
import liveReload from "vite-plugin-live-reload";
import { viteStaticCopy } from "vite-plugin-static-copy";
import VITEREX_SVGO_CONFIG from "./svgo-config.mjs";

const STRUCTURE_PATH_CANDIDATES = [
	"var/data/addons/viterex_addon/structure.json", // modern (ydeploy)
	"redaxo/data/addons/viterex_addon/structure.json", // classic, theme
];

const PLUGIN_DIR = path.dirname(fileURLToPath(import.meta.url));
const DEV_INDEX_HTML_PATH = path.join(PLUGIN_DIR, "dev-server-index.html");

let exitHandlersBound = false;

function loadStructureJson(cwd = process.cwd()) {
	for (const rel of STRUCTURE_PATH_CANDIDATES) {
		const abs = path.resolve(cwd, rel);
		if (fs.existsSync(abs)) {
			try {
				return JSON.parse(fs.readFileSync(abs, "utf8"));
			} catch (e) {
				throw new Error(`[viterex] Failed to parse ${rel}: ${e.message}`);
			}
		}
	}
	throw new Error(
		"[viterex] structure.json not found at var/data/addons/viterex_addon/ or redaxo/data/addons/viterex_addon/. " +
			"Open the Redaxo backend → AddOns → ViteRex → Settings, save the form to seed it.",
	);
}

function detectTlsCerts(cwd) {
	const key = path.resolve(cwd, "localhost+2-key.pem");
	const cert = path.resolve(cwd, "localhost+2.pem");
	if (fs.existsSync(key) && fs.existsSync(cert)) {
		return { key: fs.readFileSync(key), cert: fs.readFileSync(cert) };
	}
	return null;
}

export function resolveCopyTargets(structure) {
	const dirs = (structure.copy_dirs || "img")
		.split(",")
		.map((d) => d.trim())
		.filter(Boolean);
	const sourceRel = (structure.assets_source_dir || "src/assets").replace(/^\/+|\/+$/g, "");
	return dirs.map((dir) => ({
		// `**/*`, not `*`: subfolders (`img/icons/…`) must ship too, or
		// Assets::inline()/url() find nothing in a build.
		src: `${sourceRel}/${dir}/**/*`,
		dest: `${structure.assets_sub_dir}/${dir}`,
		// vite-plugin-static-copy v4 preserves the matched path's directory
		// tree under `dest`. Strip only the source base (`src/assets/img`), so
		// `src/assets/img/icons/foo.svg` lands at `<outDir>/assets/img/icons/foo.svg`.
		rename: { stripBase: `${sourceRel}/${dir}`.split("/").length },
	}));
}

function hotFilePlugin(hotFilePath) {
	return {
		name: "viterex:hot-file",
		configureServer(server) {
			server.httpServer?.once("listening", () => {
				const address = server.httpServer.address();
				if (!address || typeof address === "string") return;
				const host = address.address === "::" || address.address === "0.0.0.0" ? "localhost" : address.address;
				const protocol = server.config.server.https ? "https" : "http";
				fs.writeFileSync(hotFilePath, `${protocol}://${host}:${address.port}`);
			});

			if (!exitHandlersBound) {
				const clean = () => {
					if (fs.existsSync(hotFilePath)) fs.rmSync(hotFilePath);
				};
				process.on("exit", clean);
				process.on("SIGINT", () => process.exit());
				process.on("SIGTERM", () => process.exit());
				process.on("SIGHUP", () => process.exit());
				exitHandlersBound = true;
			}
		},
	};
}

/**
 * Walk a directory recursively and return the absolute paths of every `.svg`
 * file found under it. Tolerant: missing dir → empty list, no throw.
 */
async function walkSvgs(dir) {
	const out = [];
	let entries;
	try {
		entries = await fs.promises.readdir(dir, { withFileTypes: true });
	} catch {
		return out;
	}
	for (const ent of entries) {
		const full = path.join(dir, ent.name);
		if (ent.isDirectory()) {
			out.push(...(await walkSvgs(full)));
		} else if (ent.isFile() && ent.name.endsWith(".svg")) {
			out.push(full);
		}
	}
	return out;
}

/**
 * Lazily import svgo from the user's node_modules. Returns the `optimize`
 * function or null if the package isn't installed (e.g., user just upgraded
 * the addon and hasn't run `npm install` yet). Cached after first resolve.
 */
let _svgoLoader = null;
async function loadSvgo() {
	if (_svgoLoader === null) {
		_svgoLoader = (async () => {
			try {
				const mod = await import("svgo");
				return mod.optimize;
			} catch {
				console.warn("[viterex] svgo not installed — SVG optimization disabled. Run `npm install` to enable.");
				return null;
			}
		})();
	}
	return _svgoLoader;
}

/**
 * Optimize one SVG file in place. Uses the shared OptimizationCache JSON
 * to skip files already in optimal form (sha1 of current on-disk content
 * matches the recorded value).
 *
 * Returns one of: "optimized" (file changed + cache updated),
 * "skipped" (cache hit OR optimizer no-op'd), "error" (read/write/svgo
 * failure — warning printed).
 */
async function optimizeSvgFile(absPath, optimize, cache, cwd) {
	let raw;
	try {
		raw = await fs.promises.readFile(absPath, "utf8");
	} catch {
		return "error";
	}
	const rel = path.relative(cwd, absPath).replace(/\\/g, "/");
	const currentHash = sha1(raw);
	if (cache[rel] === currentHash) return "skipped";

	let result;
	try {
		result = optimize(raw, VITEREX_SVGO_CONFIG);
	} catch (e) {
		console.warn(`[viterex] svgo failed on ${absPath}: ${e.message}`);
		return "error";
	}
	const finalBytes = result?.data || raw;
	if (finalBytes !== raw) {
		try {
			await fs.promises.writeFile(absPath, finalBytes, "utf8");
		} catch (e) {
			console.warn(`[viterex] could not write optimized ${absPath}: ${e.message}`);
			return "error";
		}
		cache[rel] = sha1(finalBytes);
		return "optimized";
	}
	// optimizer was a no-op (file already optimal); record sha1 anyway so
	// next run skips the SVGO call
	cache[rel] = currentHash;
	return "skipped";
}

/**
 * Read the optimization cache JSON. Returns an empty object if the file
 * doesn't exist, is unreadable, or contains invalid JSON. Mirrors the
 * fail-open semantics of `lib/Svg/OptimizationCache.php`'s `load()`.
 */
function loadOptimizationCache(cachePath) {
	if (!cachePath) return {};
	try {
		const raw = fs.readFileSync(cachePath, "utf8");
		const parsed = JSON.parse(raw);
		if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) return {};
		const out = {};
		for (const [k, v] of Object.entries(parsed)) {
			if (typeof k === "string" && typeof v === "string") out[k] = v;
		}
		return out;
	} catch {
		return {};
	}
}

/**
 * Persist the optimization cache JSON. Errors are warned-but-not-thrown:
 * cache persistence is best-effort, never load-bearing. On the next run we
 * just re-derive from disk.
 */
function persistOptimizationCache(cachePath, cache) {
	if (!cachePath) return;
	try {
		fs.mkdirSync(path.dirname(cachePath), { recursive: true });
		fs.writeFileSync(cachePath, JSON.stringify(cache, null, 2));
	} catch (e) {
		console.warn(`[viterex] could not persist svg-optimized.json: ${e.message}`);
	}
}

function sha1(content) {
	return createHash("sha1").update(content).digest("hex");
}

/**
 * SVG optimization plugin. In dev (`configureServer`): walks
 * `<assets_source_dir>` and optimizes 1:1 in place. In build
 * (`buildStart`): walks `<assets_source_dir>` AND `<media_dir>` so
 * `npm run build` produces a clean media-pool too. The shared
 * OptimizationCache (sidecar JSON at `<cache_dir>/svg-optimized.json`)
 * means subsequent runs skip files that haven't changed since their
 * last optimize.
 *
 * Skipped (returns null) when toggle is disabled — caller filters nulls
 * out of the plugin array.
 */
function svgOptimizePlugin({ enabled, srcDir, mediaDir, cachePath }) {
	if (!enabled) return null;
	const scanned = new Set();
	const cache = loadOptimizationCache(cachePath);
	const cwd = process.cwd();

	async function scanDir(dir, label) {
		if (!dir || !fs.existsSync(dir)) return;
		const optimize = await loadSvgo();
		if (!optimize) return;
		const files = await walkSvgs(dir);
		const counts = { optimized: 0, skipped: 0, errors: 0 };
		for (const file of files) {
			if (scanned.has(file)) continue;
			scanned.add(file);
			const r = await optimizeSvgFile(file, optimize, cache, cwd);
			counts[r === "error" ? "errors" : r]++;
		}
		if (counts.optimized > 0 || counts.errors > 0) {
			console.log(
				`[viterex] svg ${label}: optimized=${counts.optimized} skipped=${counts.skipped} errors=${counts.errors}`,
			);
		}
	}

	return {
		name: "viterex:svg-optimize",
		async buildStart() {
			await scanDir(srcDir, "assets");
			await scanDir(mediaDir, "media");
			persistOptimizationCache(cachePath, cache);
		},
		configureServer(server) {
			server.httpServer?.once("listening", async () => {
				await scanDir(srcDir, "assets");
				persistOptimizationCache(cachePath, cache);
			});
		},
	};
}

/**
 * Vite middleware that serves a friendly landing page at "/" and "/index.html"
 * on the dev-server URL — instead of letting curious developers hit a blank
 * page when they accidentally navigate to the Vite host. The page tells them
 * the dev server is running and links back to the project's actual host_url.
 */
function devIndexPlugin(hostUrl) {
	return {
		name: "viterex:dev-index",
		apply: "serve",
		configureServer(server) {
			return () => {
				server.middlewares.use((req, res, next) => {
					const url = (req.url ?? "").split("?")[0];
					if (url !== "/" && url !== "/index.html") {
						next();
						return;
					}
					if (!fs.existsSync(DEV_INDEX_HTML_PATH)) {
						next();
						return;
					}
					try {
						const tpl = fs.readFileSync(DEV_INDEX_HTML_PATH, "utf8");
						const safeHost = (hostUrl || "/").replace(/[<>"]/g, (c) => `&#${c.charCodeAt(0)};`);
						res.statusCode = 200;
						res.setHeader("Content-Type", "text/html; charset=utf-8");
						res.end(tpl.replace(/\{\{HOST_URL\}\}/g, safeHost));
					} catch (e) {
						console.warn(`[viterex] dev-index render failed: ${e.message}`);
						next();
					}
				});
			};
		},
	};
}

/**
 * Main plugin entry. Returns an array of Vite plugins.
 *
 * @param {object} [options]
 * @param {string[]} [options.input] - Override entry points (default: [css_entry, js_entry] from structure.json).
 * @param {boolean|string[]} [options.refresh=true] - Live-reload globs. true = use refresh_globs from structure.json; false = disabled; array = override.
 * @param {boolean} [options.detectTls=true] - Auto-detect mkcert localhost+2 certs at project root when https_enabled is on.
 * @param {boolean} [options.injectConfig=true] - Inject build/server/css/resolve via Vite's config() hook. Set false to keep ONLY side effects (hot file).
 */
export default function viterex(options = {}) {
	const { input, refresh = true, detectTls = true, injectConfig = true } = options;
	const structure = loadStructureJson();
	const cwd = process.cwd();

	// All structure.json paths are relative to project root; resolve to absolutes here.
	const hotFileFs = path.resolve(cwd, structure.hot_file || ".vite-hot");
	const outDirFs = path.resolve(cwd, structure.out_dir || "public/dist");
	const assetsSourceFs = path.resolve(cwd, structure.assets_source_dir || "src/assets");

	const resolvedInputs = input
		? input.map((p) => path.resolve(cwd, p))
		: [path.resolve(cwd, structure.css_entry), path.resolve(cwd, structure.js_entry)];

	let refreshGlobs = null;
	if (refresh === true) {
		refreshGlobs = structure.refresh_globs
			? structure.refresh_globs
					.split("\n")
					.map((g) => g.trim())
					.filter(Boolean)
			: [];
	} else if (Array.isArray(refresh)) {
		refreshGlobs = refresh;
	}

	const https = detectTls && structure.https_enabled === true ? detectTlsCerts(cwd) : null;

	// Default ON: matches Config::DEFAULTS['svg_optimize_enabled'] = '1'.
	// `=== false` (not `!== true`) so a missing key in structure.json — e.g.
	// when the user upgraded from v3.2.x and the backend hasn't re-synced
	// `structure.json` yet — still gets optimization. Only an explicit
	// `false` (user toggled off in Settings) disables.
	const mediaDirFs = structure.media_dir ? path.resolve(cwd, structure.media_dir) : null;
	const cacheFs = structure.cache_dir ? path.resolve(cwd, structure.cache_dir, "svg-optimized.json") : null;
	const svgOptimize = svgOptimizePlugin({
		enabled: structure.svg_optimize_enabled !== false,
		srcDir: assetsSourceFs,
		mediaDir: mediaDirFs,
		cachePath: cacheFs,
	});

	const plugins = [hotFilePlugin(hotFileFs), devIndexPlugin(structure.host_url)];
	if (svgOptimize) plugins.push(svgOptimize);

	if (injectConfig) {
		const buildUrlPath = `/${(structure.build_url_path || "/dist").replace(/^\/+|\/+$/g, "")}`;

		plugins.push({
			name: "viterex",
			config(userConfig, { mode }) {
				return {
					publicDir: userConfig.publicDir ?? false,
					base: userConfig.base ?? (mode === "production" ? `${buildUrlPath}/` : "/"),
					css: {
						transformer: userConfig.css?.transformer ?? "lightningcss",
						lightningcss: userConfig.css?.lightningcss ?? {
							targets: browserslistToTargets(browserslist()),
						},
					},
					build: {
						outDir: userConfig.build?.outDir ?? outDirFs,
						assetsDir: userConfig.build?.assetsDir ?? structure.assets_sub_dir,
						emptyOutDir: userConfig.build?.emptyOutDir ?? true,
						manifest: userConfig.build?.manifest ?? true,
						cssMinify: userConfig.build?.cssMinify ?? "lightningcss",
						rollupOptions: {
							input: userConfig.build?.rollupOptions?.input ?? resolvedInputs,
						},
					},
					server: {
						host: userConfig.server?.host ?? "127.0.0.1",
						cors: userConfig.server?.cors ?? { origin: structure.host_url },
						...(https ? { https: userConfig.server?.https ?? https } : {}),
					},
					resolve: {
						alias: userConfig.resolve?.alias ?? [{ find: "@", replacement: assetsSourceFs }],
					},
				};
			},
		});

		const copyTargets = resolveCopyTargets(structure);
		if (copyTargets.length > 0) {
			// Optimize SVGs en route when the toggle is on. Other file types pass
			// through unchanged. Loader is shared with the source-tree mutator.
			if (svgOptimize) {
				for (const t of copyTargets) {
					t.transform = async (contents, filename) => {
						if (!filename.endsWith(".svg")) return contents;
						const optimize = await loadSvgo();
						if (!optimize) return contents;
						try {
							const result = optimize(
								typeof contents === "string" ? contents : contents.toString("utf8"),
								VITEREX_SVGO_CONFIG,
							);
							return result?.data || contents;
						} catch {
							return contents;
						}
					};
				}
			}
			plugins.push(viteStaticCopy({ targets: copyTargets }));
		}
	}

	if (refreshGlobs && refreshGlobs.length > 0) {
		plugins.push(liveReload(refreshGlobs));
	}

	return plugins;
}

/**
 * Tailwind 4 workaround for tailwindlabs/tailwindcss#19670.
 * Removes @tailwindcss/vite:generate:serve plugin's hotUpdate hook so a real
 * full-reload occurs instead of the partial that breaks CSS imports.
 *
 * Use only when you have @tailwindcss/vite in your plugins array.
 */
export function fixTailwindFullReload() {
	return {
		name: "fix-tailwind-full-reload",
		configResolved(config) {
			const plugin = config.plugins.find((p) => p.name === "@tailwindcss/vite:generate:serve");
			delete plugin?.hotUpdate;
		},
	};
}
