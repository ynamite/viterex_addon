# Changelog

## **Version 3.5.3**

### Fixed

- **Stale `host_url` (`http://localhost`) in `structure.json` after a seeded
  install** (Vite dev server answering with
  `Access-Control-Allow-Origin: http://localhost`). The 3.5.2 fix regenerated
  the file on every cache clear, but `Config::getHostUrl()` read the domain
  from `rex_yrewrite::getDomains()` — static state built at boot from
  yrewrite's *cached* `config.php`. When the database is seeded after that
  cache was generated (create-viterex: `package:install` → seed →
  `cache:clear`), the CLI cache-clear process still saw zero domains and
  wrote `localhost`; only the *next* cache clear picked up the domain — and a
  dev server started in between kept the wrong CORS origin until restarted.
  `getHostUrl()` now queries the `rex_yrewrite_domain` table directly (source
  of truth, always current), normalizing rows the same way yrewrite does
  (new pure helper `Config::hostUrlFromDomainRows()`, unit-tested).

## **Version 3.5.2**

### Fixed

- **Bogus `host_url` (`http://.`) in `structure.json`** (Vite dev server
  answering with `Access-Control-Allow-Origin: http://.`). Two defects, both
  fixed:
  - `Config::getHostUrl()` used `rex_yrewrite::getDefaultDomain()`, which
    returns yrewrite's *synthetic catch-all* (host `null`), not the configured
    domain. Its URL is built from `$_SERVER`, so any CLI context — console
    commands, an installer running `package:install` — produced `http://.`.
    `getHostUrl()` now picks the first real yrewrite domain (host set) and
    only then falls back to `$_SERVER`.
  - `structure.json` was only written on addon install and on saving the
    settings page, so yrewrite domains configured *afterwards* (e.g. an
    installer seeding the database after `package:install`) never reached the
    file. It is now regenerated on every cache clear (`CACHE_DELETED`
    extension point, `LATE` so yrewrite rebuilds its own data first) — a
    plain `bin/console cache:clear` or the dev badge's cache-clear button
    self-heals a stale `host_url`.

## **Version 3.5.1**

### Fixed

- **One-request fatal during addon update** (`Class "Ynamite\ViteRex\Csp" not
  found` in `Badge.php`): the old version's badge `OUTPUT_FILTER` closure
  lazy-loads the new `Badge.php` after files are swapped, before the autoloader
  knows about `lib/Csp.php`. `install.php` now eagerly requires `Csp` so the
  late-firing closure resolves it. Takes effect for updates *to* this release
  and later.

## **Version 3.5.0**

### Added

- **CSP nonce support.** Every tag ViteRex emits — `<script type="module">`,
  `<link rel="stylesheet">`, and the relevant `<link rel="modulepreload">` /
  `<link rel="preload" as="style">` tags, plus the dev badge — now carries the
  per-request nonce from `rex_response::getNonce()` (core ≥5.15.0, with a
  `bin2hex(random_bytes(16))` fallback on older cores). A project-defined strict
  CSP (`script-src 'self' 'nonce-…'; style-src 'self' 'nonce-…'`) now works with
  ViteRex assets out of the box. Stamping is always-on (a stray nonce is inert
  without a CSP) and requires no configuration. New `Ynamite\ViteRex\Csp` helper
  (`nonce()`, `attr()`). ViteRex deliberately does **not** build or send the CSP
  header — the policy is page-global and remains the project's responsibility.
  Dev/HMR remains best-effort (Vite injects its own runtime tags ViteRex cannot
  reach); production is fully clean.

## **Version 3.4.2**

- Remove unused imports from boot.php

## **Version 3.4.1**

- Fix formatting and lint in several files
- Add support for src fragment directories

## **Version 3.4.0**

### Fixed

- **Tailwind utility classes on inline SVG elements are no longer mangled
  by `IdPrefixer`** (`lib/Svg/IdPrefixer.php`). Previously, every token in
  a `class="..."` attribute on an SVG element got prefixed with the
  filename-derived namespace, regardless of whether the class was actually
  defined inside the SVG's own `<style>` block — so
  `class="fill-blue-500 hover:fill-blue-700"` came out as
  `class="img-foo-fill-blue-500 img-foo-hover:fill-blue-700"` and
  Tailwind's external utility CSS no longer matched. Auto-scope now:
  `IdPrefixer` walks every `<style>...</style>` block and collects class
  names that appear as selectors into a set; `prefixTokens()` only rewrites
  `class="..."` tokens that are in that set. Class definitions inside
  `<style>` continue to be prefixed (they're in the set by construction);
  external classes (Tailwind, project CSS, BEM) pass through unchanged so
  host-page CSS keeps matching them. The headline two-SVG no-collision
  scenario is unaffected — `<style>`-declared classes still get scoped
  per-SVG. Mirrors the existing `$idSet` pattern that protects hex colours
  in `<style>` from being treated as ID references.

  Cache invalidation: `Assets::inline()`'s cache key now bakes in
  `IdPrefixer::VERSION` (currently `2`), so existing v3.3.x cached SVGs
  (over-prefixed) are bypassed automatically on first inline call after
  upgrade. Old cache files become orphaned cruft on disk; the cache is
  non-critical and wiped on uninstall.

  Known limitation: attribute selectors like `[class~="foo"]` are not
  parsed; classes only reachable that way must also be defined via a
  plain `.foo` rule for the auto-scope to pick them up. Documented in the
  `IdPrefixer` class docblock.

- **`IdPrefixer::deriveStablePrefix()` no longer prepends `viterex-` to the
  derived slug** (`lib/Svg/IdPrefixer.php`). Commit `9d259f1` (post-3.3.1)
  removed the `viterex-` namespace from the docblock examples, the README,
  and the unit test, but the function body itself still returned
  `'viterex-' . $slug`. `testStablePrefixDerivation` was failing on every
  run against a function that didn't match any of its three sources of
  truth. Function now returns the bare slug
  (`img/icon-foo.svg → img-icon-foo`), restoring consistency.

### Added

- **`StubsInstaller::installFromDir()` accepts an optional `?bool $backup`
  parameter** (default `true`). When `true` and `$overwrite` is also
  `true`, existing files are backed up to `<file>.bak.<timestamp>` before
  being replaced (existing behavior). When `false`, overwrites happen
  in-place without backups — useful for downstream addons that ship their
  own update flow and don't want backup litter. Backwards-compatible:
  the default preserves the previous behavior.

### Internal

- Four new tests in `tests/Svg/IdPrefixerTest.php` for the auto-scope rule:
  external-only classes untouched, mixed local + external classes,
  Tailwind 4 escaped-colon tokens (`hover:fill-blue-700`), and multiple
  `<style>` blocks contributing to the defined-set. `testPrefixesClassAttributes`
  updated to include a matching `<style>` block — its previous fixture
  (no `<style>`, only a `class="cls-1"` attribute) would no longer be
  prefixed under the new auto-scope rule.

## **Version 3.3.0**

### ⚠️ Breaking — minimum PHP bumped to 8.3

The addon now requires PHP `>=8.3` (was `>=8.1`), to enable the new
`mathiasreker/php-svg-optimizer` runtime dependency. Active Redaxo
installs have largely moved to 8.3+ since its November 2023 release;
sites still on 8.1/8.2 should pin to v3.2.x.

### Added

- **Automatic SVG cleanup & optimization** (`lib/Svg/`, `lib/Media/SvgHook.php`,
  `assets/viterex-vite-plugin.js`). Engine selection follows
  `Server::getDeploymentStage()`:
  - **Dev** stage → SVGO (Node) everywhere. The Vite plugin walks
    `<assets_source_dir>/**/*.svg` on dev-server start and on `buildStart`
    and rewrites each file 1:1 in place; the `viteStaticCopy` transform
    optimizes SVGs en route to the build output. Media-pool uploads run
    through SVGO via shell-out (`npx --no-install svgo`) when available,
    with PHP-side fallback if `exec` is disabled or SVGO isn't installed.
  - **Staging / prod** → `mathiasreker/php-svg-optimizer ^8.5` for the
    media-pool runtime path only. Other SVGs are assumed already optimized
    in the deploy artifact (dev did it before commit).
  - Default ON (`svg_optimize_enabled='1'`); single toggle in ViteRex →
    Settings → "SVG optimization". Mirrored to `structure.json` so the
    Vite plugin honors it on the Node side.
  - Fail-open contract: any failure (malformed SVG, missing tooling,
    write error) returns the original bytes unchanged. Idempotent —
    second optimization pass round-trips identically.
  - Security side-effect for media-pool uploads: `<script>` tags and
    `on*` event handlers are stripped, closing an XSS path that exists
    by default in any Redaxo install accepting SVG uploads.
- **`StubsInstaller::syncPackageDeps()`** is now public (formerly
  `private mergePackageDeps()`). Lets `install.php` and downstream
  addons push npm deps into the user's `package.json` without doing a
  full stubs install. Additive, version-compare merge; idempotent.
  `install.php` uses it to add `svgo: ^4.0.0` on every install/update,
  so existing v3.2.x installs upgrading to v3.3.0 see SVGO appear in
  their `package.json` automatically — they just run `npm install`.
- **`IdPrefixer` scope-isolation for inlined SVGs** (`lib/Svg/IdPrefixer.php`,
  wired into `Assets::inline()`). Each inlined SVG gets its `id`/`class`
  attributes and internal references (`url(#X)`, `<use href="#X">`,
  `xlink:href="#X"`, `<style>` selectors) prefixed with a stable,
  filename-derived namespace (`viterex-<path-slug>-…`). Without this,
  two SVGs sharing `.cls-1`-style classes (typical Figma/Illustrator
  export) cross-bleed because their `<style>` blocks have document-level
  scope when inlined into HTML. Hex colour literals like `#fff` are
  protected by an id-set filter — only `#X` selectors that match a real
  `id="X"` in the document get rewritten. Result is cached at
  `rex_path::addonCache('viterex_addon', 'inline-svg/<sha1>.svg')` keyed
  on `path + content`, so the rewrite cost is paid once per (file,
  content) pair. Disk files stay generic (unchanged) — the prefix is
  applied only at inline time, not in the source-mutation pass, so the
  same source file remains usable as `<img src>` / `background-image`.
  Per-file opt-out via the magic comment `<!-- viterex:no-prefix -->`
  anywhere in the SVG. Honors the global `svg_optimize_enabled` toggle
  (off → no prefixing).

### Internal

- **Simplification follow-up (2026-05-05):**
  - **Dev-stage `MEDIA_*` hook is now a no-op.** Devs don't want SVGO
    firing on every test upload. The Vite build (`npm run build`) and
    the new `viterex:optimize-svgs` console command sweep the media
    pool when devs are ready. Production / staging behavior is
    unchanged: every uploaded SVG runs through `PhpOptimizer`, which
    strips `<script>` and `on*` handlers as a security side-effect.
    The "is dev" check requires ydeploy to be installed AND report
    `'dev'` — without ydeploy, `Server::getDeploymentStage()` falls
    through to `'dev'` by default, so a bare `=== 'dev'` check would
    silently disable the security stripping on prod installs that
    haven't installed ydeploy. Default: when ydeploy is absent, run
    the optimizer (the safe choice).
  - **`OptimizerFactory` deleted** (`lib/Svg/OptimizerFactory.php`,
    plus its 5 tests). With the dev `MEDIA_*` branch removed, `SvgHook`
    always wants `PhpOptimizer` and the new console command picks its
    engine inline (`SvgoCli::isAvailable() ? new SvgoCli() : new PhpOptimizer()`).
    The factory's `$stage` parameter no longer carried information.
  - **`viterex:optimize-svgs` console command** (`lib/Console/OptimizeSvgsCommand.php`).
    Walks `<assets_source_dir>` and `<media_dir>`, optimizes via SVGO
    if available else `PhpOptimizer`. Flags: `--dry-run` (list, don't
    write), `--force` (ignore cache). Honors `svg_optimize_enabled`.
    The constructor takes an optional `OptimizerInterface` for test
    injection; coverage is via end-to-end smoke in the test install
    rather than a unit test (mirrors `InstallStubsCommand`'s
    no-unit-test precedent — backfill candidate for a future cleanup).
  - **Vite build now walks `<media_dir>`** during `buildStart` (NOT
    `configureServer`, so dev-server start stays fast). Same SVGO
    invocation as the existing assets walk.
  - **Shared optimization cache** (`<cache_dir>/svg-optimized.json`).
    Both the Vite plugin and the console command read/write the same
    JSON sidecar — sha1 of post-optimization content keyed by
    project-relative path. Files matching the recorded sha1 are
    skipped (already in optimal form). Helper: `lib/Svg/OptimizationCache.php`,
    fail-open on corrupted JSON, 6 unit tests.
  - **`structure.json` gains `media_dir` + `cache_dir`** (both derived
    from `rex_path::*`, not user config — no settings form fields,
    no `Config::DEFAULTS` entry, just emitted at sync time).

- **SVGO config centralized to `assets/svgo-config.mjs`** — single
  source of truth for both the Vite plugin (`import` from sibling) and
  the PHP shell-out path (`SvgoCli` passes the file via `--config`).
  Previously the same config existed twice, as a JS object literal in
  `viterex-vite-plugin.js` and a heredoc string in `SvgoCli.php`, kept
  in sync by hand and a comment. The two definitions silently drifted
  during testing of v3.3.0; this fix removes the possibility entirely.
  Bonus: any future per-file extensions (e.g., scoped overrides) can
  splice into the canonical config from either runtime without
  serialization/translation.

- New PHP test suite under `tests/Svg/` (27 cases) covering each
  optimizer impl, the factory's stage-driven resolution + SVGO-fallback
  branches, malformed-input fail-open, idempotency, the canonical
  config file's existence + shape, and the `IdPrefixer` rewrite rules
  (id/class attrs, `url()`, `<use>`/`xlink:href`, `<style>` selectors,
  hex-colour false-positive guard, opt-out comment, stable prefix
  derivation, and the headline two-SVG no-collision scenario). Adds 3
  testable seams: `OptimizerFactory::for($stage, $enabled, ?$svgoAvailable)`
  takes the SVGO-availability check as an injectable parameter so the
  fallback path is unit-testable without environment setup;
  `SvgoCli::resetAvailabilityCache()` (`@internal`) clears the per-request
  cache; `Config::isCheckboxChecked()` was promoted from `private` to
  `public static` so the SvgHook can decode the toggle without duplicating
  the `|1|`/`|0|` parsing logic.
- **`Config::isEnabled()`** — new helper for reading default-ON checkbox
  toggles. `Config::get()` falls through to `DEFAULTS` when the stored
  value is `null` (which is what `rex_form_checkbox_element` writes when
  saving an unchecked box: `setValue(null) → getSaveValue → null`). For
  default-OFF checkboxes like `https_enabled` that's harmless — both
  `null` and the seeded `'0'` resolve to "off". For default-ON checkboxes
  it would silently flip the user's explicit "off" save back to "on" on
  every read. `isEnabled()` uses `array_key_exists` (instead of `isset`/`??`)
  on the full namespace array to distinguish "explicitly set to null"
  from "never written", honoring the user's intent. Both
  `lib/Media/SvgHook.php` and `Config::syncStructureJson()` now read
  `svg_optimize_enabled` and `https_enabled` through this helper.
- **`tests/CheckboxValueTest.php`** — pins `Config::isCheckboxChecked()`
  across all six storage forms a checkbox can take in `rex_config`
  (`|1|`, `'1'`, `''`, `'0'`, `|0|`, `||`). Any future regression of the
  v3.0 `https_enabled` `=== '1'` bug breaks tests immediately.
- **Vite plugin defaults to ON when `structure.svg_optimize_enabled` is
  missing** (`structure.svg_optimize_enabled !== false` instead of
  `=== true`). Robust against stale `structure.json` — e.g., if the
  user upgraded from v3.2.x and PHP-FPM opcache was holding the old
  `Config.php` when `syncStructureJson` last ran.
- **ydeploy-helper sidecar moved out of project root**
  (`lib/Deploy/Sidecar.php`). `Sidecar::path()` now resolves to
  `rex_path::addonData('viterex_addon', 'deploy.config.php')` instead
  of `rex_path::base('deploy.config.php')`, keeping deploy state inside
  the addon's data directory instead of leaking into the project root.
  v3.2.6 was never tagged, so no users are affected by the path change.

### Fixed

- **`viteStaticCopy` no longer nests the source path under `dest`.**
  `vite-plugin-static-copy` v4 (the version users on the latest stubs
  pull) preserves the matched file's directory tree under `dest` by
  default — a regression from v3's flat-copy behavior. Without
  intervention, `src/assets/img/foo.svg` landed at
  `<outDir>/assets/img/src/assets/img/foo.svg` instead of
  `<outDir>/assets/img/foo.svg`. `resolveCopyTargets()` now sets
  `rename: { stripBase: true }` on every target so the basename joins
  `dest` directly. No-op on v3. Bug existed independent of the new SVG
  optimization toggle but surfaced during v3.3 testing because the
  copied SVGs were the obvious thing to inspect.

## **Version 3.2.6**

### Added

- **ydeploy-Helper: Backend-Subpage zum Bearbeiten der Deployment-Hosts** (`pages/deploy.php`, `lib/Deploy/{Sidecar,DeployFile,Page}.php`). Wenn das `ydeploy`-Addon installiert ist, blendet viterex_addon eine **Deploy**-Subpage im Backend ein (ViteRex → Deploy). Die Seite extrahiert beim ersten Aufruf die Hosts aus dem aktuellen `deploy.php` (token-basiertes Parsen via `token_get_all()`, kein `eval`/`include`) und schreibt sie in eine Sidecar-Datei `deploy.config.php` im Projekt-Root. Auf Klick auf **Aktivieren** wird `deploy.php` umgeschrieben, sodass die Sidecar via `require` gelesen und die Hosts in einem `foreach` aufgebaut werden — innerhalb eines klar markierten Blocks (`// >>> VITEREX:DEPLOY_CONFIG ... >>>`). Spätere Formular-Saves schreiben nur noch die Sidecar; `deploy.php` bleibt unangetastet. Multi-Host wird unterstützt (z. B. stage + prod). Vor jedem Schreibvorgang wird ein zeitgestempeltes Backup erstellt (`*.bak.YYYYmmdd-HHiiss`, gleiche Konvention wie `StubsInstaller`). Beim Aktivieren werden zusätzlich redundante `set('repository', ...)`-Aufrufe ausserhalb des Markierungsblocks (z. B. im `if ($isGit)`-Zweig des Installer-Scaffolds) erkannt und durch Kommentare neutralisiert, damit der Sidecar-Wert nicht durch Deployers Last-Write-Wins-Verhalten überschrieben wird. Der Parser ignoriert verschachtelte `host()`-Aufrufe (z. B. `on(host('local'), ...)` in eigenen Tasks) korrekt — nur top-level `host(...)->setHostname(...)->...->setDeployPath(...)`-Ketten werden als Host-Definitionen erkannt. Das Repository-Feld wird beim ersten Aufruf mit dem `git remote get-url origin`-Wert vorbelegt, falls verfügbar. Add/Remove-Buttons im Formular; das Stage-Label-Feld bietet die ydeploy-gestyleten Werte als Vorschläge an (Datalist). Die ganze Page ist konditional an `rex_addon::get('ydeploy')->isAvailable()` geknüpft. Siehe README → "ydeploy-Helper".

### Internal

- **`Ynamite\ViteRex\Deploy\` namespace** (`lib/Deploy/`) mit drei Klassen, alle ohne Redaxo-Runtime in den Test-Pfaden: `Sidecar` (Pure I/O, deterministischer PHP-Output für saubere Diffs), `DeployFile` (lexikalische Operationen — `extract`, `hasMarkers`, `rewrite`, plus die `neutralizeRedundantRepositorySets` Surgery), `Page` (reine Helfer für State-Detection und POST-Validierung). 47 neue PHPUnit-Tests (`tests/Deploy/`) decken die Parser-Edge-Cases, Backup-Verhalten, Marker-Manipulation, BOM/CRLF und Idempotenz ab.

## **Version 3.2.5**

### Fixed

- **`Server::isProductionDeployment()` and `Server::isStagingDeployment()` no longer fatal without `ydeploy`** (`lib/Server.php`). Both methods called `rex_ydeploy::factory()` directly with no class-availability guard, so any project that installed `viterex_addon` without `ydeploy` got `Class "rex_ydeploy" not found` on the first call. The most visible victim was the backend dev-badge gate at `boot.php:113` (every backend page load with a logged-in user), but the same crash also fired from `Server::__construct → checkDebugMode()` — meaning every `Server::factory()` call from `OutputFilter`, `Preload`, `Assets`, and `Badge::get()` was affected. Both methods now early-return `false` when `rex_addon::get('ydeploy')->isAvailable()` is false, matching the behavior already documented in `CLAUDE.md` and the convention used at `boot.php:97` (`YREWRITE_SEO_TAGS` registration). With the guard pushed to the source, the call site at `boot.php:113` no longer needs its own ydeploy check, and future callers don't have to know about the dependency. `getDeploymentStage()` continues to fall through to `'dev'` when neither flag is true — unchanged behavior, now actually reachable on ydeploy-less installs.

## **Version 3.2.4**

### Fixed

- **Static assets attached to a CSS entry are now preloaded** (`lib/Preload.php`). When Vite emits an entry like `src/assets/css/style.css` whose manifest record has an `assets: [...]` siblings array (e.g. `@font-face` woff2 fonts referenced from CSS, or images referenced via `url()`), `Preload::walkManifestEntry` was returning early on any `.css`-extension entry — silently dropping every sibling preload tag. The early-return is meant to skip emitting a `modulepreload` for the CSS file itself (the stylesheet link is rendered by `Assets::renderBlock`), not to skip the asset loop further down. The CSS guard now scopes only the JS-only emissions (`modulepreload`, `entry.css`, `imports`/`dynamicImports` recursion); `entry.assets` runs for both CSS and JS entries and emits the appropriate `<link rel="preload" as="font|image|video|audio" …>` tags. Cross-entry dedup is preserved by the existing `array_unique` in `build()`. The bug only surfaced when a project shipped a standalone CSS entry whose `assets` siblings should be preloaded — JS entries that import CSS were unaffected because their fonts already surfaced via the JS chunk's own `assets` field.

### Internal

- **PreloadTest** (`tests/PreloadTest.php`) covers the regression plus six adjacent paths: JS modulepreload + `imports` walking, JS `css` siblings as `as=style` preload, image asset on a CSS entry, JS-entry imported asset, cross-entry dedup, and unknown-extension omission. To keep tests bootstrap-free (mirrors `OutputFilterTest`), `Preload` now exposes an `@internal` static seam `Preload::buildLinesForManifest(manifest, buildUrlPath, entries)` that the instance `build()` delegates to. Public API and behavior at all call sites (`Assets::renderBlock`, the `VITEREX_PRELOAD` extension point) are unchanged.

## **Version 3.2.3**

### Fixed

- **`viterex_addon.zip` no longer leaks into the MyREDAXO package.** The publish workflow created a `viterex_addon.zip` for the GitHub release upload, but `FriendsOfREDAXO/installer-action@1.2.0` then built its own MyREDAXO package by zipping the entire working directory (`archive.glob('**', { cwd, skip/ignore: installer_ignore + redaxo defaults })`). Redaxo's default ignore list does not include `*.zip`, so the GitHub-release zip was getting bundled into the MyREDAXO upload — a self-referential nested archive that ended up inside every install. Added `viterex_addon.zip` to `package.yml`'s `installer_ignore` list so the action's globber skips it. The GitHub-release artifact (which already used `-x "viterex_addon.zip"` in its own `zip` command) was always clean — only the MyREDAXO package was affected.

## **Version 3.2.2**

### Fixed

- **Dev badge no longer overflows on mobile.** The git-branch panel inside the badge could push the total width past narrow viewports — particularly with long branch names like `feature/<descriptive-slug>`. The `.branch` panel is now `display: none` below `max-width: 768px` (`assets-src/viterex-badge.module.css`). Other badge panels (Redaxo + ViteRex version labels, stage indicator, Vite-running dot, clear-cache button) and any `VITEREX_BADGE` extension-point panels (e.g. `redaxo-massif`'s Tailwind breakpoint indicator) are unaffected. The `data-git-branch` attribute on the script tag remains present for future use (e.g. a tooltip).

## **Version 3.2.1**

### Fixed

- **`REX_VITE` replacement scoped to `<head>`** (`lib/OutputFilter.php`). Previously `OutputFilter::rewriteHtml` replaced every `REX_VITE` occurrence anywhere in the rendered HTML, including literal mentions inside `<code>` / `<pre>` blocks on documentation pages that themselves describe how to use `viterex_addon`. The filter now finds the first `<head>...</head>` block and replaces only the first `REX_VITE` (or `REX_VITE[src="…"]`) inside it; subsequent placeholders and any `REX_VITE` text in `<body>` are left as literal text. Auto-insert before `</head>` is unchanged.

### Notes for upgraders

- If you (unusually) had multiple `REX_VITE` placeholders inside `<head>` to load different entries, only the first is now replaced. Combine them via the pipe-separated form: `REX_VITE[src="src/main.css|src/main.js"]`.

### Internal

- **PHPUnit added** as `require-dev` (`phpunit/phpunit ^10.5`). `tests/OutputFilterTest.php` covers the head-only scoping, body-untouched behavior, multiple-in-head, auto-insert, and missing-`<head>` edge cases. Run via `composer test`. Test infrastructure (`tests/`, `phpunit.xml.dist`, `.phpunit.cache`) is excluded from the release zip via `package.yml` `installer_ignore` and the publish workflow.
- For testability, the pure transformation in `OutputFilter` is split into a thin public `rewriteHtml()` shim that delegates to a new `@internal rewriteHtmlWithBlock(string, callable)` method. Public API and behavior at all callers (frontend `OUTPUT_FILTER`, `BLOCK_PEEK_OUTPUT`) are unchanged.

## **Version 3.2.0**

### Added

- **`viterex:install-stubs` Symfony Console command** (`lib/Console/InstallStubsCommand.php`, registered via `console_commands:` in `package.yml`). Programmatic counterpart of the _AddOns → ViteRex → Settings → Install stubs_ button — reuses the same `Ynamite\ViteRex\StubsInstaller::run()` path, so output, backups, and `VITEREX_INSTALL_STUBS` extension-point dispatch are identical. Intended for automated install flows that scaffold a project without a browser session (e.g. `create-viterex`). Default behaviour skips existing files; pass `--overwrite` to back them up (`.bak.<timestamp>`) and replace. Print `-v` for the list of written paths.

  ```bash
  php bin/console viterex:install-stubs            # write only missing files
  php bin/console viterex:install-stubs --overwrite # backup + replace existing
  ```

## **Version 3.1.3**

### Fixed

- Restored `rex_developer_manager::setBasePath(rex_path::src())` in `boot.php` when the `developer` addon is available. The call was dropped during the v3 refactor (commit `137f8ea`) along with the now-removed `Structure` class; without it, the developer addon writes to its default location instead of the Redaxo source directory.

## **Version 3.1.2**

- Path-naming consistency pass. No behavior changes beyond the rename. **Migration**: existing 3.0.x / 3.1.0 / 3.1.1 installs have their `structure.json` at `var/data/addons/viterex/` (or `redaxo/data/addons/viterex/`) and reference `assets/addons/viterex/viterex-vite-plugin.js` in their `vite.config.js`. After upgrading, re-save the Settings form to seed the new path, and update the `viterex-vite-plugin.js` import in `vite.config.js` from `…/viterex/…` to `…/viterex_addon/…` (or click "Install stubs" with overwrite to have the import re-baked).
- add exclude to gitignore example for the new `assets/addons/viterex_addon/` path.

### Changed

- **Addon-name normalization across all paths**: `viterex` → `viterex_addon` (matches `package.yml`'s `package:` key, which is what Redaxo's package manager actually uses on disk). Touches:
  - `assets/viterex-vite-plugin.js` — `STRUCTURE_PATH_CANDIDATES` now points at `var/data/addons/viterex_addon/structure.json` and `redaxo/data/addons/viterex_addon/structure.json`; error message updated.
  - `lib/StubsInstaller.php::resolvePluginImportPath()` — `__VITEREX_PLUGIN_IMPORT_PATH__` token is now baked as `./<public>/assets/addons/viterex_addon/viterex-vite-plugin.js` in the scaffolded `vite.config.js`.
  - `stubs/biome.jsonc` — Biome include glob updated to `**/assets/addons/viterex_addon/**/*.*`.
  - `stubs/.env.example`, `README.md`, `CLAUDE.md` — references aligned.

## **Version 3.1.1**

### Fixed

- **HTTPS dev-server checkbox** in _AddOns → ViteRex → Settings_ didn't actually enable HTTPS. `rex_form_checkbox_element` saves checked options as pipe-delimited strings (`|1|`), but `Config::syncStructureJson()` compared `=== '1'` and so always wrote `"https_enabled": false` to `structure.json`. The Vite plugin then started in plain HTTP and the hot file ended up `http://127.0.0.1:5173`. New `isCheckboxChecked()` helper in `lib/Config.php` strips outer pipes, splits on `|`, and tests for `'1'` membership — round-trips correctly across all five values the field can take (`|1|`, `||`, `''`, `'0'`, `'1'`).

## **Version 3.1.0**

Programmatic API additions for downstream addons (e.g. redaxo-massif). No breaking changes.

### Added

- **`StubsInstaller::installFromDir($sourceDir, $stubsMap, $overwrite, $packageDeps)`** — public, reusable file-installer. Downstream addons call it from their own `install.php` / Settings handlers to push files into the user's project, reusing viterex_addon's path-baking, backup-on-overwrite, and structure-aware target resolution.
- **`StubsInstaller::appendRefreshGlobs($lines)`** — public helper for downstream addons that need Vite to live-reload on additional paths (e.g. their own fragments/lib directories). Reads `rex_config('viterex','refresh_globs')`, idempotently appends only missing lines.
- **`VITEREX_INSTALL_STUBS` extension point** — fired from inside `StubsInstaller::run()` (the path triggered by viterex's "Install Stubs" button). Subject is the result array `{written, skipped, backedUp, gitignoreAction}`. Subscribers can append entries by calling `installFromDir()` themselves and merging the returned arrays into the subject. Params: `overwrite` (bool, from the user's checkbox).
- **`mergePackageDeps()` private helper** — wired into `installFromDir` via the `$packageDeps` argument. Merges npm dependencies into the user's `package.json` additively, with `version_compare` resolving conflicts (higher wins).

### Internal

- `StubsInstaller::run()` refactored to delegate file copying to `installFromDir()`. Behavior preserved; `gitignoreAction` still set via the existing `mergeGitignore()` (now only run by `run()`, not the generic `installFromDir`).
- Hardcoded install-result message in `pages/settings.php` extracted to a `viterex_install_result` lang key (`lang/en_gb.lang`, `lang/de_de.lang`). Now uses `rex_i18n::rawMsg()` like the rest of the page.
- German translation polish in `lang/de_de.lang` — leftover English fragments translated (`Entry Points` → `Einstiegspunkte`, `Tooling` → `Werkzeuge`, `Web-served` → `Vom Webserver ausgeliefertes`, `Install stubs` → `Stubs installieren`, `JS-Entry` / `CSS-Entry` → `JS-Einstiegspunkt` / `CSS-Einstiegspunkt`, `Dev-Tooling-Configs` → `Dev-Tooling-Konfigurationen`).

### Documentation

- `README.md` rewritten in German with full feature coverage matching the English version (entry points, paths, dev settings, hot-file flow, `REX_VITE` placeholder, dev badge, downstream-addon API). Earlier in the cycle, the English README was also revised for installation paths and settings.
- `docs/vite-plus-evaluation.md` added — evaluation of a potential Vite+ migration. Recommendation: monitor, no migration today.
- Hero image `viterex.png` added at repo root.

## **Version 3.0.0**

Breaking release. ViteRex is now a standalone Redaxo addon installable in **any** Redaxo setup (classic / modern / theme), with a Laravel-vite-plugin-style architecture and a Redaxo backend CRUD form for configuration.

### Added

- **Backend CRUD form** at _AddOns → ViteRex → Settings_ (`pages/settings.php`) for all paths and dev settings — entries, public dir, out dir, URL prefix, source dir, static copy dirs, HTTPS, live-reload globs. Persisted via `rex_config('viterex', ...)` and mirrored to `var/data/addons/viterex/structure.json` (modern) or `redaxo/data/addons/viterex/structure.json` (classic+theme) for the Vite plugin to read.
- **"Install stubs" button** with optional "overwrite existing" checkbox — copies `package.json`, `vite.config.js`, `.env.example`, dev-tooling configs, and entry stubs to the project root. Bakes the structure-aware `viterex-vite-plugin.js` import path at scaffold time. Also performs `.gitignore` scan-and-merge.
- **`REX_VITE` placeholder** with optional `[src="a|b"]` attribute (pipe-separated entries). Auto-inserts before `</head>` when no placeholder is present.
- **`assets/viterex-vite-plugin.js`** — Laravel-style Vite plugin shipped in the addon's `assets/` (Redaxo auto-copies to `<frontend>/assets/addons/viterex/`). Exports default `viterex(options)` returning a Plugin[] (hot-file plugin, named `viterex` plugin with `config()` hook injecting build/server/css/resolve, `viteStaticCopy` sibling, `liveReload` sibling). Also exports `fixTailwindFullReload()` for the Tailwind 4 hotUpdate workaround. Single-bind exit handlers for `exit`/`SIGINT`/`SIGTERM`/`SIGHUP`.
- **`Assets::path/url/inline`** — PHP helpers for static-asset references in templates (background images, inline SVG, etc.).
- **`Tailwind 4` shipped by default**: `@tailwindcss/vite`, `@tailwindcss/forms`, `@tailwindcss/typography`, `tailwind-clamp` are all in the scaffolded `package.json`. Users who don't want Tailwind delete two lines from `vite.config.js`.
- **`vite-plugin-static-copy`** for static-asset mirroring — replaces `rollup-plugin-copy` (which had `writeBundle` timing issues under Vite 8 + rolldown).
- **`mkcert`** as devDependency + `npm run setup-https` script — one command for local HTTPS certs.
- **Hot-file detection** at `<base>/.vite-hot` (dotfile, project root, single source of truth across all structures). Optional HTTP probe fallback when `VITE_DEV_SERVER` is set in env.
- **Reliable live-reload trigger** via PHP extension-point handlers. `boot.php` registers callbacks on ~30 content-save EPs (`ART_*`, `CAT_*`, `SLICE_*`, `MEDIA_*`, `CLANG_*`, `TEMPLATE_*`, `MODULE_*`, `YFORM_DATA_*`) that `touch()` a single signal file `<base>/.vite-reload-trigger`. The Vite plugin watches that file via `vite-plugin-live-reload`. Fixes the previous default that watched `var/cache/addons/{structure,url}/**` — those dirs are written on lazy cache regeneration during normal frontend navigation, causing spurious reloads. **Migration**: existing v3 installs that had the old default in their `Live-reload globs` setting need to manually update via Settings (replace `var/cache/addons/...` with `.vite-reload-trigger`).
- **Badge enhancements**: stage indicator (`dev`/`staging`/`prod`), Vite-running indicator with click-to-copy URL, Clear-cache button (CSRF-protected), `VITEREX_BADGE` extension point for downstream addons.
- **`VITEREX_PRELOAD`** extension point for custom dev-mode preload tags (e.g. webfonts).
- **Full manifest import-graph walk** with cycle detection in `Preload` (previously only one level deep).
- **`lib/Config.php`** — single source of truth for paths; `get/set/all/syncStructureJson/getHotFilePath/getHostUrl`.
- **`lib/StubsInstaller.php`** — handles the Install Stubs button action (copy + path-baking + gitignore merge).

### Changed

- **Composer autoload** PSR-4 (`Ynamite\ViteRex\`); `lib/Badge/Badge.php` moved to `lib/Badge.php`.
- **`package.yml`** PHP requirement `>=7.3` → `>=8.1`. Adds `page` section with subpages (settings, docs) and `installer_ignore` list.
- **Addon `assets/` layout**: badge build artifacts under `assets/badge/`, the Vite plugin file at `assets/viterex-vite-plugin.js` (top level). Internal vite.config.js outDir is `../assets/badge` so `emptyOutDir: true` doesn't wipe the committed plugin file.
- **`rex_developer_manager::setBasePath`** call removed — was structure-specific and not generally applicable now that paths are user-configured.
- **Build paths configurable via Settings** — defaults are modern (`public/dist`); classic/theme users adjust via the form. No more runtime auto-detection magic.

### Removed

- **`Assets::get()`** — templates must use `REX_VITE` placeholders.
- **`Server::getAssetsUrl/getImg/getCss/getFont/getJs/getAssetsPath/setValue`** etc. — replaced by `Assets::url/path/inline`.
- **`lib/Structure.php`** — replaced by `lib/Config.php`. No more runtime classic/modern/theme auto-detection.
- **Dev-mode font autoprobe** in `Preload` — was MASSIF-specific (hardcoded `src/assets/fonts`). Use the `VITEREX_PRELOAD` extension point for custom preloads.
- **`stubs/vite/`** subfolder — the Vite plugin file is no longer scaffolded into the user project; it ships inside the addon and is auto-copied by Redaxo.
- **`rollup-plugin-copy`** — replaced by `vite-plugin-static-copy` (rolldown-safe).

## **12.02.2024 Version 1.0.0**

- erste Version
