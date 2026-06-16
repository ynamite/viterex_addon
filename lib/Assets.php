<?php

namespace Ynamite\ViteRex;

use rex_file;
use rex_path;
use Ynamite\ViteRex\Svg\IdPrefixer;

final class Assets
{
    /**
     * Render the full asset block for a list of entries (preload + CSS links + HMR client + JS).
     * Pass `null` to use the configured default entries (CSS + JS).
     */
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

    /**
     * Default entries for `REX_VITE` placeholders without an explicit `src=` attribute.
     * Returns the JS entry and the CSS entry, both from `Config`.
     *
     * @return list<string>
     */
    public static function getDefaultEntries(): array
    {
        return [
            trim(Config::get('js_entry'), '/'),
            trim(Config::get('css_entry'), '/'),
        ];
    }

    /**
     * Absolute filesystem path to a static asset under `<assets_source_dir>`.
     *
     *   dev:  <base>/<assets_source_dir>/<rel>
     *   prod: <base>/<out_dir>/<assets_sub_dir>/<rel>
     */
    public static function path(string $relativePath): string
    {
        $rel = ltrim($relativePath, '/');
        if (Server::isDevMode()) {
            return rex_path::base(trim(Config::get('assets_source_dir'), '/') . '/' . $rel);
        }
        $outDir = trim(Config::get('out_dir'), '/');
        $subDir = trim(Config::get('assets_sub_dir'), '/');
        $segments = array_filter([$outDir, $subDir, $rel], static fn(string $s): bool => $s !== '');
        return rex_path::base(implode('/', $segments));
    }

    /**
     * Browser URL to a static asset under `<assets_source_dir>`.
     *
     *   dev:  <devUrl>/<assets_source_dir>/<rel>
     *   prod: <build_url_path>/<assets_sub_dir>/<rel>
     */
    public static function url(string $relativePath): string
    {
        $rel = ltrim($relativePath, '/');
        if (Server::isDevMode()) {
            $devUrl = Server::getDevUrl() ?? '';
            return $devUrl . '/' . trim(Config::get('assets_source_dir'), '/') . '/' . $rel;
        }
        $buildUrl = '/' . trim(Config::get('build_url_path'), '/');
        $subDir = trim(Config::get('assets_sub_dir'), '/');
        return $buildUrl . ($subDir !== '' ? '/' . $subDir : '') . '/' . $rel;
    }

    /**
     * Read raw file content for inlining (SVG, JSON, raw HTML fragment, …).
     * Resolves via `Assets::path()` so dev reads source, prod reads from
     * the rollup-plugin-copy'd build output location.
     *
     * SVGs are run through `IdPrefixer` to scope-isolate their `id`/`class`
     * attributes — without this, two inlined SVGs sharing `.cls-1`-style
     * selectors (typical Figma/Illustrator export) collide because their
     * `<style>` blocks have document-level scope when inlined into HTML.
     * Result is cached at `rex_path::addonCache('viterex_addon', 'inline-svg/')`
     * keyed on `sha1(path + ':' + content + ':v' + IdPrefixer::VERSION)` —
     * the version segment invalidates old cache entries when the prefixer's
     * behavior changes. Old entries become orphaned cruft on disk; the cache
     * is non-critical and wiped on uninstall. Bypassed when `svg_optimize_enabled`
     * is off, or when the file contains `<!-- viterex:no-prefix -->`.
     */
    public static function inline(string $relativePath): string
    {
        $content = rex_file::get(self::path($relativePath));
        if (!is_string($content) || $content === '') {
            return '';
        }
        if (!self::isSvgPath($relativePath) || !Config::isEnabled('svg_optimize_enabled')) {
            return $content;
        }
        $prefixer = new IdPrefixer();
        if ($prefixer->isOptedOut($content)) {
            return $content;
        }
        $cachePath = rex_path::addonCache(
            'viterex_addon',
            'inline-svg/' . sha1($relativePath . ':' . $content . ':v' . IdPrefixer::VERSION) . '.svg',
        );
        $cached = rex_file::get($cachePath);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $prefixed = $prefixer->prefix($content, $prefixer->deriveStablePrefix($relativePath));
        rex_file::put($cachePath, $prefixed);
        return $prefixed;
    }

    private static function isCssPath(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css';
    }

    private static function isSvgPath(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
    }

    private static function normalizeEntries(?array $entries): array
    {
        if ($entries === null) {
            $entries = self::getDefaultEntries();
        }
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $trimmed = trim($entry, "/ \t\n\r");
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }
        return array_values(array_unique($normalized));
    }
}
