# CSP nonce support — design

**Status:** approved
**Date:** 2026-06-16
**Target version:** v3.5.0 (minor — new feature)
**Scope:** new `lib/Csp.php`; nonce injection in `lib/Assets.php`, `lib/Preload.php`, `lib/Badge.php`; PHPUnit cases; docs (README, CLAUDE.md, CHANGELOG); version bump.

## Problem

Every `<script>` / `<link>` tag viterex emits is built without a `nonce`
attribute. A project that enforces a strict Content-Security-Policy on the
frontend (`script-src 'self' 'nonce-…'`, `style-src 'self' 'nonce-…'`) will
have viterex's assets blocked by the browser. This is already documented as a
known limitation (README §"Bekannte Einschränkungen", the dev-HMR case in
particular).

Tags affected, across three emitters:

- `Assets::renderBlock()` — `<script type="module">` for built/dev entries and
  the dev `@vite/client` HMR client; `<link rel="stylesheet">` for CSS.
- `Preload::renderForEntries()` — `<link rel="modulepreload">`,
  `<link rel="preload" as="style">`, and `<link rel="preload" as="font|image|…">`.
- `Badge::get()` — the dev badge's `<script type="module">` and
  `<link rel="stylesheet">`.

## Goal

Stamp the canonical per-request nonce onto every viterex-emitted tag that CSP
nonces actually govern, so a project-defined strict CSP works with viterex's
assets out of the box. No configuration required.

## Non-goals

- **viterex never builds or sends a `Content-Security-Policy` header.** The
  policy is page-global (fonts, analytics, embeds, inline handlers in
  modules/slices) and is the project's responsibility. This mirrors core, which
  ships only the nonce primitive (`rex_response::getNonce()`) plus a
  backend-only `frame-ancestors 'self'` header — no frontend policy.
- **No nonce generator of our own, no `rex` property, no Config key.** Core owns
  the nonce. Introducing a parallel source would split the single-source-of-truth.
- **No backend toggle.** Stamping is always-on (see Approach). A stray `nonce`
  attribute is inert without a CSP, exactly as core's unconditional stamping in
  `top.php`, login, setup, media_manager, phpmailer, error handlers.
- **No document-wide rewrite.** We only stamp tags viterex itself emits, not
  arbitrary `<script>`/`<style>` authored in the project's templates.
- **No Node/Vite change.** This is a PHP-runtime-only feature; `structure.json`
  and the Vite plugin are untouched.

## Approach

### Nonce source — `lib/Csp.php`

A new `final class Ynamite\ViteRex\Csp` is the single seam between viterex and
core's nonce:

```php
public static function nonce(): string;          // rex_response::getNonce(), with fallback
public static function attr(): string;            // ' nonce="<nonce>"' (leading space)
public static function buildAttr(string $nonce): string;  // @internal, pure: '' when $nonce === ''
```

- `nonce()` returns `rex_response::getNonce()` when that method exists, otherwise
  a static-cached `bin2hex(random_bytes(16))` computed once per request. The
  guard is `method_exists(rex_response::class, 'getNonce')`. Reason: `getNonce()`
  landed in core **5.15.0** (2023-02-28) but the addon floor is `redaxo: ^5.13.0`.
  The fallback produces the same hex shape and keeps the floor at 5.13.
- `attr()` delegates to `buildAttr(self::nonce())`.
- `buildAttr()` is pure and unit-testable: returns `' nonce="' . $nonce . '"'`
  for a non-empty nonce, `''` for an empty string. The nonce is hex
  (`[0-9a-f]{32}`) so no escaping is required; `buildAttr` does not call
  `htmlspecialchars` (documented assumption: the value is always hex).

Stamping is **always-on** — `attr()` always returns the nonce attribute. There
is no "only when a CSP is present" branch, matching core. No Config key, no
`structure.json` field, no toggle.

### Where we stamp

Injected at tag-build time (string concatenation), never via a post-pass regex:

| Emitter | Tag | Stamp? |
|---|---|---|
| `Assets::renderBlock` | `<script type="module">` (entries + `@vite/client`) | ✅ `script-src` |
| `Assets::renderBlock` | `<link rel="stylesheet">` | ✅ `style-src` (CSP3 honors nonce on link) |
| `Preload` | `<link rel="modulepreload">` | ✅ `script-src` |
| `Preload` | `<link rel="preload" as="style">` | ✅ `style-src` |
| `Preload` | `<link rel="preload" as="font\|image\|video\|audio">` | ❌ those directives don't honor nonces — a nonce there is misleading clutter |
| `Badge::get` | `<script type="module">`, `<link rel="stylesheet">` | ✅ same nonce as core's backend CSP |

Because all stamping flows through `OutputFilter::rewriteHtml`, the block_peek
backend preview path gets the correct nonce for free (it runs inside a backend
response where core has already established the same nonce via `top.php`).

### Testability seam

Mirrors the codebase's existing "pure helper + thin shim" idiom
(`rewriteHtml` → `rewriteHtmlWithBlock`, `build` → `buildLinesForManifest`).
The nonce attribute is threaded as a parameter through the pure builders so
placement is testable without a Redaxo bootstrap:

- `Preload::buildLinesForManifest(array $manifest, string $buildUrlPath, array $entries, string $nonceAttr = '')`
  — appends `$nonceAttr` to modulepreload and style-preload lines, not to
  font/image/media preloads.
- `Assets::renderBlock(?array $entries = null, ?string $nonceAttr = null)` —
  when `$nonceAttr` is `null`, resolves `Csp::attr()` once; passes it down to
  `Preload::renderForEntries($entries, $nonceAttr)`. The `OUTPUT_FILTER`
  callable `[Assets::class, 'renderBlock']` is invoked with a single argument,
  so the added optional parameter is signature-compatible.
- `Preload::renderForEntries(array $entries, string $nonceAttr = '')` and
  `Preload::build(...)` thread the attr through to `buildLinesForManifest` and
  to the dev-mode line builders. The `VITEREX_PRELOAD` extension-point output is
  appended verbatim and **not** stamped (it's caller-owned HTML).
- `Badge::get()` injects `Csp::attr()` inline; it already depends on the Redaxo
  runtime (`rex_csrf_token`), so no pure helper is extracted there.

### Public API

- `Csp::nonce()` and `Csp::attr()` are public (a project may reuse `Csp::nonce()`
  but is equally free to call `rex_response::getNonce()` directly — same value).
- `Csp::buildAttr()` is `@internal`, exposed only for unit tests.
- `Assets::renderBlock()` and `Preload::renderForEntries()` gain a trailing
  optional parameter (back-compatible).

## Tests

New `tests/CspTest.php`:

- `buildAttr('a1b2…')` → `' nonce="a1b2…"'`.
- `buildAttr('')` → `''`.

Extend `tests/PreloadTest.php` (exists):

- `buildLinesForManifest(..., ' nonce="X"')` → modulepreload and `as="style"`
  preload lines contain `nonce="X"`; `as="font"` / `as="image"` lines do **not**.

Extend `tests/` coverage for `Assets` if a pure builder is introduced there:

- rendered block with a known `$nonceAttr` stamps every `<script>` and
  `<link rel="stylesheet">`; dev `@vite/client` script is stamped.

## Known limitations (documented, not handled)

- **Dev / HMR is best-effort.** viterex's own dev tags are stamped, but Vite's
  HMR client injects its own runtime `<script>`/`<style>` (error overlay,
  injected styles) that viterex cannot reach, and Vite's `html.cspNonce` is a
  static build-time value, not per-request. Recommendation: relax CSP in dev.
  **Production is fully clean** (a known, finite set of emitted tags).
- **Inline SVG `<style>`.** `Assets::inline()` returns SVG markup that may carry
  a `<style>` element. Under a strict `style-src 'nonce-…'` without
  `'unsafe-inline'`, the browser blocks it. Not handled — would require
  rewriting SVG internals; rare in practice.

## Documentation & version touchpoints

- **README** — replace the CSP/nonce known-limitation entry with a proper
  "CSP / Nonces" section: viterex stamps `rex_response::getNonce()` on all
  emitted tags automatically; show the project-side header recipe; state the
  dev/HMR caveat and the inline-SVG-`<style>` edge.
- **CLAUDE.md** — rewrite the "CSP/nonce limitation" bullet under "Things to be
  careful about" (it currently says dev scripts are emitted without nonces) and
  add a one-line architecture note about `Csp` + always-on stamping.
- **CHANGELOG.md** — feature entry.
- **package.yml** — bump to `3.5.0`, then `npm run version:sync`. Badge sources
  are unchanged, so **no** `npm run build` (per the release checklist: skip the
  build when no asset sources changed).
