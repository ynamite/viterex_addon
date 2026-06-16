# CSP Nonce Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stamp the canonical per-request CSP nonce (`rex_response::getNonce()`) onto every `<script>`/`<link>` tag viterex emits, so a project-defined strict CSP works with viterex assets out of the box.

**Architecture:** A new `Ynamite\ViteRex\Csp` class is the single seam to core's nonce (with a `bin2hex` fallback for cores older than 5.15.0). The nonce attribute is threaded as a string parameter through the existing pure builders in `Assets` and `Preload` (mirroring the codebase's `rewriteHtml`→`rewriteHtmlWithBlock` idiom) and injected inline in `Badge`. Always-on, PHP-runtime-only — no Config key, no CSP header ownership, no Node/Vite change.

**Tech Stack:** PHP 8.3, PHPUnit 10.5 (`vendor/bin/phpunit`), Redaxo `^5.13`.

**Spec:** `docs/superpowers/specs/2026-06-16-csp-nonce-support-design.md`

**Preconditions:** Dev dependencies installed (`composer install` if `vendor/bin/phpunit` is missing). All work happens on branch `csp-nonce-support` (already created; the spec commit lives there).

---

## File Structure

- **Create** `lib/Csp.php` — the nonce seam (`nonce()`, `attr()`, pure `buildAttr()`).
- **Modify** `lib/Preload.php` — thread `$nonceAttr` through `renderForEntries` → `build` → `buildLinesForManifest` → `walkEntry` → `modulePreload`/`stylePreload`/`assetPreload` (js arm only).
- **Modify** `lib/Assets.php` — extract `scriptTag()`/`styleTag()` helpers (DRY: each string currently repeats), thread `$nonceAttr` through `renderBlock`, default to `Csp::attr()`.
- **Modify** `lib/Badge.php` — inject `Csp::attr()` into the badge's `<link>` and `<script>`.
- **Create** `tests/CspTest.php` — pure `buildAttr` cases.
- **Modify** `tests/PreloadTest.php` — nonce placement cases.
- **Create** `tests/AssetsTest.php` — pure `scriptTag`/`styleTag` cases.
- **Modify** `README.md`, `CLAUDE.md`, `CHANGELOG.md`, `package.yml`, `package.json` (via `version:sync`).

---

## Task 1: `Csp` nonce seam

**Files:**
- Create: `tests/CspTest.php`
- Create: `lib/Csp.php`

- [ ] **Step 1: Write the failing test**

Create `tests/CspTest.php`:

```php
<?php

namespace Ynamite\ViteRex\Tests;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Csp;

/**
 * Pins the pure attribute formatter. `nonce()`/`attr()` delegate to
 * `rex_response::getNonce()` and need a Redaxo bootstrap, so only the pure
 * `buildAttr` seam is unit-tested here.
 */
final class CspTest extends TestCase
{
    public function testBuildAttrWrapsNonce(): void
    {
        $this->assertSame(' nonce="a1b2c3"', Csp::buildAttr('a1b2c3'));
    }

    public function testBuildAttrEmptyNonceYieldsEmptyString(): void
    {
        $this->assertSame('', Csp::buildAttr(''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/CspTest.php`
Expected: FAIL — `Error: Class "Ynamite\ViteRex\Csp" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `lib/Csp.php`:

```php
<?php

namespace Ynamite\ViteRex;

use rex_response;

/**
 * CSP nonce seam between viterex and Redaxo core.
 *
 * Core owns the per-request nonce (`rex_response::getNonce()`, added in core
 * 5.15.0 — `bin2hex(random_bytes(16))`, generated lazily, shared across the
 * whole request). viterex consumes it and stamps it onto every tag it emits,
 * so a project-defined strict CSP works with viterex assets. viterex never
 * builds or sends the CSP header — the policy is page-global and belongs to
 * the project. Stamping is unconditional, mirroring core (a stray `nonce`
 * attribute is inert without a CSP).
 */
final class Csp
{
    private static ?string $fallbackNonce = null;

    /**
     * The current request's CSP nonce. Delegates to `rex_response::getNonce()`
     * when available (core >= 5.15.0); otherwise generates and statically
     * caches a hex nonce, keeping the addon's floor at redaxo ^5.13.0.
     */
    public static function nonce(): string
    {
        if (method_exists(rex_response::class, 'getNonce')) {
            return rex_response::getNonce();
        }
        return self::$fallbackNonce ??= bin2hex(random_bytes(16));
    }

    /**
     * The nonce as a ready-to-concatenate HTML attribute: ` nonce="<value>"`
     * (note the leading space). Used by the asset emitters.
     */
    public static function attr(): string
    {
        return self::buildAttr(self::nonce());
    }

    /**
     * @internal Pure helper for unit tests. Returns ` nonce="<nonce>"` for a
     * non-empty nonce, `''` otherwise. The nonce is always hex `[0-9a-f]{32}`,
     * so no escaping is applied.
     */
    public static function buildAttr(string $nonce): string
    {
        if ($nonce === '') {
            return '';
        }
        return ' nonce="' . $nonce . '"';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/CspTest.php`
Expected: PASS (2 tests, 2 assertions).

- [ ] **Step 5: Commit**

```bash
git add lib/Csp.php tests/CspTest.php
git commit -m "feat: add Csp nonce seam (rex_response::getNonce wrapper)"
```

---

## Task 2: Thread the nonce through `Preload`

**Files:**
- Modify: `tests/PreloadTest.php`
- Modify: `lib/Preload.php`

- [ ] **Step 1: Write the failing tests**

Append these methods to the `PreloadTest` class in `tests/PreloadTest.php` (before the closing `}`):

```php
    public function testNonceIsStampedOnModulePreloadAndStylePreload(): void
    {
        $manifest = [
            'src/assets/js/main.js' => [
                'file'    => 'assets/main-X.js',
                'isEntry' => true,
                'css'     => ['assets/main-X.css'],
                'imports' => ['_chunk-AbCd.js'],
            ],
            '_chunk-AbCd.js' => [
                'file' => 'assets/chunk-AbCdEf.js',
            ],
        ];

        $lines = Preload::buildLinesForManifest(
            $manifest,
            self::BUILD,
            ['src/assets/js/main.js'],
            ' nonce="N"',
        );

        $this->assertContains('<link rel="modulepreload" href="/dist/assets/main-X.js" nonce="N">', $lines);
        $this->assertContains('<link rel="modulepreload" href="/dist/assets/chunk-AbCdEf.js" nonce="N">', $lines);
        $this->assertContains('<link rel="preload" href="/dist/assets/main-X.css" as="style" nonce="N">', $lines);
    }

    public function testNonceIsNotStampedOnFontOrImagePreloads(): void
    {
        $manifest = [
            'src/assets/css/style.css' => [
                'file'    => 'assets/style-X.css',
                'isEntry' => true,
                'assets'  => ['assets/inter-400-A.woff2', 'assets/hero-Y.webp'],
            ],
        ];

        $lines = Preload::buildLinesForManifest(
            $manifest,
            self::BUILD,
            ['src/assets/css/style.css'],
            ' nonce="N"',
        );

        $this->assertContains(
            '<link rel="preload" href="/dist/assets/inter-400-A.woff2" as="font" type="font/woff2" crossorigin>',
            $lines,
        );
        $this->assertContains('<link rel="preload" href="/dist/assets/hero-Y.webp" as="image">', $lines);
        foreach ($lines as $line) {
            if (str_contains($line, 'as="font"') || str_contains($line, 'as="image"')) {
                $this->assertStringNotContainsString('nonce=', $line);
            }
        }
    }

    public function testNonceDefaultsToEmptyAndLeavesTagsUnchanged(): void
    {
        $manifest = [
            'src/assets/js/main.js' => ['file' => 'assets/main-X.js', 'isEntry' => true],
        ];

        $lines = Preload::buildLinesForManifest($manifest, self::BUILD, ['src/assets/js/main.js']);

        $this->assertContains('<link rel="modulepreload" href="/dist/assets/main-X.js">', $lines);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/PreloadTest.php`
Expected: FAIL — the new `nonce="N"` assertions don't match (4th arg is currently ignored / signature mismatch). The pre-existing tests still pass.

- [ ] **Step 3: Implement — thread `$nonceAttr` through `Preload`**

In `lib/Preload.php`, make these edits:

Replace `renderForEntries`:

```php
    public static function renderForEntries(array $entries, string $nonceAttr = ''): string
    {
        return self::factory()->build($entries, $nonceAttr);
    }
```

Replace the `build` signature line and its first statement:

```php
    private function build(array $entries, string $nonceAttr = ''): string
    {
        $lines = Server::isDevMode()
            ? []
            : self::buildLinesForManifest($this->manifest, $this->buildUrlPath, $entries, $nonceAttr);
```

(The `VITEREX_PRELOAD` extension-point block below it is unchanged — caller-owned HTML is not stamped.)

Replace the `buildLinesForManifest` signature and its `walkEntry` call:

```php
    public static function buildLinesForManifest(
        array $manifest,
        string $buildUrlPath,
        array $entries,
        string $nonceAttr = '',
    ): array {
        $base = '/' . trim($buildUrlPath, '/');
        $lines = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $key = trim($entry, '/');
            if ($key === '' || !isset($manifest[$key])) {
                continue;
            }
            $visited = [];
            $lines = array_merge($lines, self::walkEntry($manifest, $manifest[$key], $base, $visited, $nonceAttr));
        }
        return array_values(array_unique($lines));
    }
```

Replace `walkEntry` (signature gains `$nonceAttr`; the three helper calls forward it):

```php
    private static function walkEntry(array $manifest, array $entry, string $base, array &$visited, string $nonceAttr = ''): array
    {
        $file = $entry['file'] ?? null;
        if (!is_string($file) || isset($visited[$file])) {
            return [];
        }
        $visited[$file] = true;

        $isCss = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'css';
        $lines = [];

        // CSS entries are emitted as <link rel="stylesheet"> by Assets::renderBlock();
        // skip modulepreload + import-walking for them, but still preload sibling assets.
        if (!$isCss) {
            $lines[] = self::modulePreload($base, $file, $nonceAttr);

            foreach (($entry['css'] ?? []) as $cssFile) {
                if (is_string($cssFile)) {
                    $lines[] = self::stylePreload($base, $cssFile, $nonceAttr);
                }
            }

            foreach (['imports', 'dynamicImports'] as $importType) {
                foreach (($entry[$importType] ?? []) as $importKey) {
                    if (is_string($importKey) && isset($manifest[$importKey])) {
                        $lines = array_merge(
                            $lines,
                            self::walkEntry($manifest, $manifest[$importKey], $base, $visited, $nonceAttr),
                        );
                    }
                }
            }
        }

        foreach (($entry['assets'] ?? []) as $asset) {
            if (is_string($asset)) {
                $preload = self::assetPreload($base, $asset, $nonceAttr);
                if ($preload !== null) {
                    $lines[] = $preload;
                }
            }
        }

        return $lines;
    }
```

Replace `modulePreload` and `stylePreload`:

```php
    private static function modulePreload(string $base, string $file, string $nonceAttr = ''): string
    {
        return '<link rel="modulepreload" href="' . htmlspecialchars(self::url($base, $file)) . '"' . $nonceAttr . '>';
    }

    private static function stylePreload(string $base, string $file, string $nonceAttr = ''): string
    {
        return '<link rel="preload" href="' . htmlspecialchars(self::url($base, $file)) . '" as="style"' . $nonceAttr . '>';
    }
```

Replace `assetPreload` (only the `$ext === 'js'` modulepreload arm is nonced; font/image/video/audio arms are not):

```php
    private static function assetPreload(string $base, string $asset, string $nonceAttr = ''): ?string
    {
        $url = self::url($base, $asset);
        $ext = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
        return match (true) {
            in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)
                => '<link rel="preload" href="' . htmlspecialchars($url) . '" as="font" type="font/' . $ext . '" crossorigin>',
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif'], true)
                => '<link rel="preload" href="' . htmlspecialchars($url) . '" as="image">',
            in_array($ext, ['mp4', 'webm', 'ogg'], true)
                => '<link rel="preload" href="' . htmlspecialchars($url) . '" as="video">',
            in_array($ext, ['mp3', 'wav', 'flac'], true)
                => '<link rel="preload" href="' . htmlspecialchars($url) . '" as="audio">',
            $ext === 'js'
                => '<link rel="modulepreload" href="' . htmlspecialchars($url) . '"' . $nonceAttr . '>',
            default => null,
        };
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/PreloadTest.php`
Expected: PASS — all pre-existing tests plus the 3 new ones (10 tests total).

- [ ] **Step 5: Commit**

```bash
git add lib/Preload.php tests/PreloadTest.php
git commit -m "feat: stamp CSP nonce on Preload module/style links"
```

---

## Task 3: Stamp the nonce in `Assets` (with DRY tag helpers)

**Files:**
- Create: `tests/AssetsTest.php`
- Modify: `lib/Assets.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/AssetsTest.php`. Note: only the pure tag helpers are exercised — `renderBlock` needs a Redaxo bootstrap (manifest/dev-URL/Config). Loading the `Assets` class without Redaxo is safe because its `use` statements (`rex_file`, `rex_path`, `IdPrefixer`) are only resolved inside methods that the test never calls.

```php
<?php

namespace Ynamite\ViteRex\Tests;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Assets;

/**
 * Pins the pure tag builders used by Assets::renderBlock. `renderBlock` itself
 * needs the Redaxo runtime (manifest, dev URL, Config) and is verified manually.
 */
final class AssetsTest extends TestCase
{
    public function testScriptTagStampsNonce(): void
    {
        $this->assertSame(
            '<script type="module" src="https://x/y.js" nonce="N"></script>',
            Assets::scriptTag('https://x/y.js', ' nonce="N"'),
        );
    }

    public function testScriptTagWithoutNonce(): void
    {
        $this->assertSame(
            '<script type="module" src="https://x/y.js"></script>',
            Assets::scriptTag('https://x/y.js'),
        );
    }

    public function testStyleTagStampsNonce(): void
    {
        $this->assertSame(
            '<link rel="stylesheet" href="https://x/y.css" nonce="N">',
            Assets::styleTag('https://x/y.css', ' nonce="N"'),
        );
    }

    public function testStyleTagWithoutNonce(): void
    {
        $this->assertSame(
            '<link rel="stylesheet" href="https://x/y.css">',
            Assets::styleTag('https://x/y.css'),
        );
    }

    public function testTagsEscapeHref(): void
    {
        $this->assertStringContainsString('href="https://x/a&amp;b.css"', Assets::styleTag('https://x/a&b.css'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/AssetsTest.php`
Expected: FAIL — `Error: Call to undefined method ...::scriptTag()`.

- [ ] **Step 3: Implement — add helpers and thread `$nonceAttr`**

In `lib/Assets.php`, replace the `renderBlock` method (from its signature through its closing brace) with the version below. It adds the optional `$nonceAttr` parameter (defaulting to `Csp::attr()` when `null`), routes every script/stylesheet string through the two new helpers, and passes the nonce into `Preload`:

```php
    public static function renderBlock(?array $entries = null, ?string $nonceAttr = null): string
    {
        $entries = self::normalizeEntries($entries);
        if (empty($entries)) {
            return '';
        }

        $nonceAttr ??= Csp::attr();

        $server   = Server::factory();
        $manifest = $server->getManifestArray();
        $isDev    = Server::isDevMode();
        $devUrl   = Server::getDevUrl();
        $buildUrl = '/' . trim(Config::get('build_url_path'), '/');

        $preloadHtml = Preload::renderForEntries($entries, $nonceAttr);
        $cssLinks    = [];
        $jsScripts   = [];
        $hmrEmitted  = false;

        foreach ($entries as $entry) {
            if ($isDev && $devUrl !== null) {
                $url = $devUrl . '/' . $entry;
                if (self::isCssPath($entry)) {
                    $cssLinks[] = self::styleTag($url, $nonceAttr);
                } else {
                    if (!$hmrEmitted) {
                        $jsScripts[] = self::scriptTag($devUrl . '/@vite/client', $nonceAttr);
                        $hmrEmitted = true;
                    }
                    $jsScripts[] = self::scriptTag($url, $nonceAttr);
                }
                continue;
            }

            if (!isset($manifest[$entry]['file']) || !is_string($manifest[$entry]['file'])) {
                continue;
            }
            $file = $manifest[$entry]['file'];
            $url  = $buildUrl . '/' . ltrim($file, '/');

            if (self::isCssPath($file)) {
                $cssLinks[] = self::styleTag($url, $nonceAttr);
                continue;
            }

            $jsScripts[] = self::scriptTag($url, $nonceAttr);

            foreach ($manifest[$entry]['css'] ?? [] as $cssChunk) {
                if (!is_string($cssChunk) || $cssChunk === '') {
                    continue;
                }
                $cssLinks[] = self::styleTag($buildUrl . '/' . ltrim($cssChunk, '/'), $nonceAttr);
            }
        }

        $parts = [];
        if ($preloadHtml !== '') {
            $parts[] = $preloadHtml;
        }
        foreach (array_values(array_unique($cssLinks)) as $link) {
            $parts[] = $link;
        }
        foreach ($jsScripts as $script) {
            $parts[] = $script;
        }
        return implode("\n", $parts);
    }

    /**
     * @internal A `<script type="module">` tag for `$url`, optionally carrying
     * a pre-built nonce attribute (` nonce="…"` from {@see Csp::attr()}).
     */
    public static function scriptTag(string $url, string $nonceAttr = ''): string
    {
        return '<script type="module" src="' . htmlspecialchars($url) . '"' . $nonceAttr . '></script>';
    }

    /**
     * @internal A `<link rel="stylesheet">` tag for `$url`, optionally carrying
     * a pre-built nonce attribute (` nonce="…"` from {@see Csp::attr()}).
     */
    public static function styleTag(string $url, string $nonceAttr = ''): string
    {
        return '<link rel="stylesheet" href="' . htmlspecialchars($url) . '"' . $nonceAttr . '>';
    }
```

(`Csp` is in the same `Ynamite\ViteRex` namespace as `Assets`, so no `use` statement is needed.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/AssetsTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add lib/Assets.php tests/AssetsTest.php
git commit -m "feat: stamp CSP nonce on Assets script/style tags"
```

---

## Task 4: Stamp the nonce in `Badge`

**Files:**
- Modify: `lib/Badge.php`

No unit test — `Badge::get` depends on `rex_csrf_token`, `rex::getVersion`, etc., and the suite has never tested it. Verified by the full-suite regression run and manual inspection.

- [ ] **Step 1: Implement**

In `lib/Badge.php`, replace the `$style` assignment:

```php
        $style = '<link rel="stylesheet" href="' . htmlspecialchars($addon->getAssetsUrl('badge/viterex-badge.css')) . '"' . Csp::attr() . '>';
```

Then add a nonce placeholder to the `$script` sprintf — change the format string's tail from `. '></script>'` to `. '%s></script>'` and add `Csp::attr()` as the final sprintf argument. The full statement becomes:

```php
        $script = sprintf(
            '<script type="module" src="%s" id="viterex-badge-script"'
                . ' data-version="%s"'
                . ' data-rex-version="%s"'
                . ' data-git-branch="%s"'
                . ' data-stage="%s"'
                . ' data-vite-running="%s"'
                . ' data-vite-url="%s"'
                . ' data-csrf-token="%s"'
                . '%s></script>',
            htmlspecialchars($addon->getAssetsUrl('badge/viterex-badge.js')),
            htmlspecialchars($version),
            htmlspecialchars($rexVersion),
            htmlspecialchars($gitBranch),
            htmlspecialchars($stage),
            $viteRunning ? 'true' : 'false',
            htmlspecialchars($viteUrl),
            htmlspecialchars($csrfToken),
            Csp::attr(),
        );
```

(`Csp` is in the same namespace as `Badge`, so no `use` statement is needed. The nonce is hex, so it carries no `%` that could disturb `sprintf`.)

- [ ] **Step 2: Run the full suite to verify no regressions**

Run: `vendor/bin/phpunit`
Expected: PASS — entire suite green (Csp, Preload, Assets, Deploy, Svg, OutputFilter, CheckboxValue).

- [ ] **Step 3: Commit**

```bash
git add lib/Badge.php
git commit -m "feat: stamp CSP nonce on the dev badge tags"
```

---

## Task 5: Documentation

**Files:**
- Modify: `README.md` (the `## Bekannte Einschränkungen` section, ~line 548)
- Modify: `CLAUDE.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update README**

In `README.md`, replace the single CSP bullet under `## Bekannte Einschränkungen` (the line beginning `- **CSP / Nonces:** Im Dev-Modus emittiert der Output-Filter`) with:

```markdown
- **CSP / Nonces:** ViteRex versieht alle selbst ausgegebenen Tags (Script-, Stylesheet- und relevante Preload-Tags aus `Assets`/`Preload` sowie das Dev-Badge) automatisch mit dem Request-Nonce aus `rex_response::getNonce()`. Eine strikte CSP deines Projekts funktioniert damit out of the box mit den ViteRex-Assets — du musst den Header nur selbst setzen. ViteRex setzt bewusst **keine** CSP: die Policy ist projektweit (Fonts, Analytics, Embeds, Inline-Handler in Modulen/Slices) und gehört dir.

  ```php
  // z. B. im Config-Template oder in der boot.php deines project-Addons
  $nonce = rex_response::getNonce();
  rex_response::setHeader(
      'Content-Security-Policy',
      "default-src 'self'; "
      . "script-src 'self' 'nonce-$nonce'; "
      . "style-src 'self' 'nonce-$nonce'",
  );
  ```

  Zwei Einschränkungen bleiben:

  - **Dev/HMR ist best effort.** ViteRex nonced seine eigenen Dev-Tags, aber der Vite-HMR-Client injiziert zur Laufzeit eigene `<script>`/`<style>` (Error-Overlay, eingespritzte Styles), die ViteRex nicht erreicht; Vites `html.cspNonce` ist statisch, nicht pro Request. Empfehlung: CSP im Dev lockern. **Produktion ist sauber.**
  - **Inline-SVG `<style>`.** `Assets::inline()` kann SVG-Markup mit einem `<style>`-Element zurückgeben. Unter striktem `style-src 'nonce-…'` ohne `'unsafe-inline'` blockiert der Browser dieses. Nicht behandelt.
```

- [ ] **Step 2: Update CLAUDE.md — careful-about bullet**

In `CLAUDE.md`, replace the bullet that begins `- **CSP/nonce limitation**: dev-mode` (under "Things to be careful about") with:

```markdown
- **CSP nonces are stamped on every emitted tag** via `Csp::attr()` (`lib/Csp.php`), which wraps `rex_response::getNonce()` (core ≥5.15.0; falls back to a static `bin2hex(random_bytes(16))` on older cores so the `^5.13` floor holds). Always-on — a stray `nonce` is inert without a CSP, mirroring core's unconditional stamping. ViteRex never sends a CSP header; the policy is the project's. Dev/HMR is best-effort (Vite injects its own untouchable runtime tags) and inline-SVG `<style>` under strict `style-src` is unhandled — both documented in README. Placement is unit-tested via the pure seams `Csp::buildAttr`, `Assets::scriptTag`/`styleTag`, and `Preload::buildLinesForManifest($manifest, $base, $entries, $nonceAttr)`. Font/image/media preloads are intentionally **not** nonced (those directives don't honor nonces).
```

- [ ] **Step 3: Update CLAUDE.md — architecture note**

In `CLAUDE.md`, immediately after the `## Architecture: REX_VITE placeholder` section (before the next `## Architecture:` heading), insert:

```markdown
## Architecture: CSP nonces

Every tag ViteRex emits carries `Csp::attr()` (` nonce="<rex_response::getNonce()>"`), stamped at build time in `Assets::renderBlock` (via the `scriptTag`/`styleTag` helpers), `Preload` (modulepreload + `as="style"` only), and `Badge` — never via a post-pass regex over the document. `lib/Csp.php` is the only seam to core's nonce and is the single place that falls back to a local hex nonce when `rex_response::getNonce()` is absent (core <5.15.0). The nonce flows through `OutputFilter::rewriteHtml`, so the block_peek backend preview inherits the correct nonce automatically. ViteRex owns no `Content-Security-Policy` header — that's the project's, and it's deliberately out of scope (see the design spec `docs/superpowers/specs/2026-06-16-csp-nonce-support-design.md`).
```

- [ ] **Step 4: Update CHANGELOG**

In `CHANGELOG.md`, insert a new entry directly below the `# Changelog` heading and above `## **Version 3.4.2**`:

```markdown
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
```

- [ ] **Step 5: Commit**

```bash
git add README.md CLAUDE.md CHANGELOG.md
git commit -m "docs: document CSP nonce support"
```

---

## Task 6: Version bump

**Files:**
- Modify: `package.yml`
- Modify: `package.json` (via `version:sync`)

- [ ] **Step 1: Bump `package.yml`**

In `package.yml`, change line 2 from `version: '3.4.2'` to:

```yaml
version: '3.5.0'
```

- [ ] **Step 2: Mirror into `package.json`**

Run: `npm run version:sync`
Expected: `package.json`'s `version` field becomes `3.5.0`. (No `npm run build` — no badge sources changed, per the release checklist.)

- [ ] **Step 3: Verify the two versions agree**

Run: `grep '"version"' package.json && grep '^version' package.yml`
Expected: both report `3.5.0`.

- [ ] **Step 4: Final full-suite run**

Run: `vendor/bin/phpunit`
Expected: PASS — entire suite green.

- [ ] **Step 5: Commit**

```bash
git add package.yml package.json
git commit -m "chore: bump version to 3.5.0"
```

---

## Self-Review (completed by plan author)

**Spec coverage:**
- Nonce source / `Csp.php` (`nonce`/`attr`/`buildAttr`, `method_exists` fallback) → Task 1. ✅
- Stamp on Preload modulepreload + style-preload, **not** font/image/media → Task 2 (incl. the `assetPreload` js-arm modulepreload, which **is** nonced). ✅
- Stamp on Assets scripts/stylesheets + dev `@vite/client` → Task 3. ✅
- Stamp on Badge → Task 4. ✅
- Always-on, no Config key, no header, no Node change → reflected throughout (no Config/structure.json edits anywhere). ✅
- Testability seam (pure builders threaded with `$nonceAttr`) → Tasks 1–3. ✅
- Docs (README/CLAUDE.md/CHANGELOG) + known limitations → Task 5. ✅
- Version bump 3.5.0 + `version:sync`, no badge build → Task 6. ✅

**Placeholder scan:** none — every code/edit step shows complete content.

**Type/signature consistency:** `$nonceAttr` is `string` everywhere; `renderForEntries(array, string)`, `buildLinesForManifest(array, string, array, string)`, `walkEntry(..., string)`, `modulePreload/stylePreload/assetPreload(..., string)`, `Assets::renderBlock(?array, ?string)`, `scriptTag/styleTag(string, string)`, `Csp::buildAttr(string)` — all aligned with the existing tests' call sites (existing tests use the 3-arg `buildLinesForManifest`, satisfied by the default).
