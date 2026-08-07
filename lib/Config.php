<?php

namespace Ynamite\ViteRex;

use rex_addon;
use rex_config;
use rex_file;
use rex_path;
use rex_yrewrite;

/**
 * Single source of truth for ViteRex paths and runtime config.
 *
 * Persisted via rex_config (table `rex_config`, namespace `viterex_addon`).
 * Mirrored to a JSON cache at `rex_path::addonData('viterex_addon', 'structure.json')`
 * that the Vite plugin reads on the Node side. All paths in the JSON are
 * relative to project root — the plugin resolves with `path.resolve(cwd, ...)`.
 */
final class Config
{
    public const DEFAULT_REFRESH_GLOBS = "src/fragments/**/*.php\nsrc/modules/**/*.php\nsrc/templates/**/*.php\nsrc/addons/project/fragments/**/*.php\nsrc/addons/project/lib/**/*.php\nsrc/assets/**/(*.svg|*.png|*.jpg|*.jpeg|*.webp|*.avif|*.gif|*.woff|*.woff2)\n.vite-reload-trigger";

    public const HOT_FILE_REL = '.vite-hot';

    /** @var array<string,string> */
    private const DEFAULTS = [
        'js_entry'             => 'src/assets/js/main.js',
        'css_entry'            => 'src/assets/css/style.css',
        'public_dir'           => 'public',
        'out_dir'              => 'public/dist',
        'assets_source_dir'    => 'src/assets',
        'assets_sub_dir'       => 'assets',
        'build_url_path'       => '/dist',
        'copy_dirs'            => 'img',
        'https_enabled'        => '0',
        'svg_optimize_enabled' => '1',
    ];

    public static function get(string $key): string
    {
        return (string) rex_config::get('viterex_addon', $key, self::defaultFor($key));
    }

    public static function set(string $key, string $value): void
    {
        rex_config::set('viterex_addon', $key, $value);
        self::syncStructureJson();
    }

    /** Default value for a single key (refresh_globs handled separately). */
    public static function defaultFor(string $key): string
    {
        if ($key === 'refresh_globs') {
            return self::DEFAULT_REFRESH_GLOBS;
        }
        return self::DEFAULTS[$key] ?? '';
    }

    /**
     * Seed rex_config with defaults for any keys that are unset OR currently empty.
     * Run on install + on the "Reset to defaults" button on the Settings page.
     * Empty strings are seeded too because rex_config_form writes empty inputs as ''
     * — without seeding, the form would render empty placeholders forever.
     */
    public static function seedDefaults(): void
    {
        foreach (self::keys() as $key) {
            $current = rex_config::get('viterex_addon', $key);
            if ($current === null || $current === '') {
                rex_config::set('viterex_addon', $key, self::defaultFor($key));
            }
        }
        self::syncStructureJson();
    }

    /**
     * @return array<string,string>
     */
    public static function all(): array
    {
        $merged = [];
        foreach (self::keys() as $key) {
            $merged[$key] = self::get($key);
        }
        return $merged;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            ...array_keys(self::DEFAULTS),
            'refresh_globs',
        ];
    }

    public static function getHotFilePath(): string
    {
        return rex_path::base(self::HOT_FILE_REL);
    }

    public static function getHostUrl(): string
    {
        if (rex_addon::get('yrewrite')->isAvailable()) {
            // NOT getDefaultDomain(): that returns the synthetic catch-all
            // rex_yrewrite::init() registers under the name 'default' (host
            // null). Its getUrl() builds from $_SERVER — fine in a web
            // request, but in CLI (console cache:clear) it yields "http://.".
            // The first real domain (host !== null) is the configured one.
            foreach (rex_yrewrite::getDomains() as $domain) {
                if (null === $domain->getHost()) {
                    continue;
                }
                $url = rtrim($domain->getUrl(), '/');
                if ($url !== '') {
                    return $url;
                }
            }
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $protocol . '://' . $host;
    }

    /**
     * Write the structure.json file the Vite plugin reads. Path resolves correctly
     * per Redaxo structure (modern → var/data, classic+theme → redaxo/data).
     *
     * All paths are relative to project root; `https_enabled` is a real bool.
     */
    public static function syncStructureJson(): void
    {
        $cfg = self::all();
        $base = rex_path::base();
        $payload = [
            'js_entry'          => $cfg['js_entry'],
            'css_entry'         => $cfg['css_entry'],
            'public_dir'        => $cfg['public_dir'],
            'out_dir'           => $cfg['out_dir'],
            'assets_source_dir' => $cfg['assets_source_dir'],
            'assets_sub_dir'    => $cfg['assets_sub_dir'],
            'build_url_path'    => $cfg['build_url_path'],
            'copy_dirs'         => $cfg['copy_dirs'],
            'media_dir'         => self::makeRelative(rex_path::media(), $base),
            'cache_dir'         => self::makeRelative(rex_path::addonCache('viterex_addon'), $base),
            'https_enabled'        => self::isEnabled('https_enabled'),
            'svg_optimize_enabled' => self::isEnabled('svg_optimize_enabled'),
            'refresh_globs'        => $cfg['refresh_globs'],
            'hot_file'             => self::HOT_FILE_REL,
            'host_url'             => self::getHostUrl(),
        ];
        rex_file::put(
            rex_path::addonData('viterex_addon', 'structure.json'),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Strip `$base` from `$absolute` so the result is project-root-relative,
     * matching how every other path in `structure.json` is expressed. Falls
     * back to the original absolute path if `$absolute` doesn't live under
     * `$base` (extremely unusual; defensive).
     */
    private static function makeRelative(string $absolute, string $base): string
    {
        $base = rtrim($base, '/');
        $absolute = rtrim($absolute, '/');
        if ($base !== '' && str_starts_with($absolute, $base)) {
            return ltrim(substr($absolute, \strlen($base)), '/');
        }
        return $absolute;
    }

    /**
     * `rex_form_checkbox_element` POSTs as `field[<value>]=<value>`, which
     * `rex_form_element::setValue()` then wraps in pipes (`|1|` checked,
     * `||` unchecked) before `rex_config::set` writes it. The seeded default
     * (`'0'`) and any programmatic `Config::set('…','1')` skip that wrapping.
     * Treat all forms uniformly so `=== '1'` doesn't silently miss `|1|`.
     */
    public static function isCheckboxChecked(string $value): bool
    {
        return in_array('1', explode('|', trim($value, '|')), true);
    }

    /**
     * Read a checkbox-style key, honoring an explicit "off" save.
     *
     * `Config::get()` falls through to `DEFAULTS` when the stored value is
     * `null` — exactly what `rex_form_checkbox_element` writes for an
     * unchecked save (`setValue(null) → getSaveValue() → null`). For a
     * default-OFF checkbox like `https_enabled` that's harmless (null and
     * the seeded `'0'` both resolve to "off"). For a default-ON checkbox
     * like `svg_optimize_enabled` it would silently flip the user's
     * explicit "off" back to "on" on every read.
     *
     * `array_key_exists` (instead of `isset`/`??`) is the only way to
     * distinguish "explicitly set to null" from "never written to
     * rex_config" — `isset(null)` is false so `rex_config::has()` can't
     * tell the difference either.
     */
    public static function isEnabled(string $key): bool
    {
        $all = rex_config::get('viterex_addon');
        $stored = is_array($all) && array_key_exists($key, $all)
            ? (string) $all[$key]
            : self::defaultFor($key);
        return self::isCheckboxChecked($stored);
    }
}
