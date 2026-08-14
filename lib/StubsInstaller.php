<?php

namespace Ynamite\ViteRex;

use rex_dir;
use rex_extension;
use rex_extension_point;
use rex_file;
use rex_path;

/**
 * Copies stub files from a source directory into the user's project root, with
 * structure-aware target paths, backup-on-overwrite, and optional package.json
 * dependency merging.
 *
 * Two entry points:
 *   - `run($overwrite)` — invoked from `pages/settings.php` "Install stubs" button.
 *     Installs viterex_addon's own stubs from `stubs/`, merges `.gitignore.example`,
 *     and fires the `VITEREX_INSTALL_STUBS` extension point so downstream addons
 *     (e.g., redaxo-massif) can append their own stubs to the same operation.
 *   - `installFromDir($sourceDir, $stubsMap, $overwrite, $packageDeps)` — public,
 *     reusable. Downstream addons call this from their own install.php / Settings
 *     handlers to push their files into the project, respecting the user's viterex
 *     Settings (paths, etc.).
 */
final class StubsInstaller
{
    /**
     * Run viterex_addon's own stub install. Fires VITEREX_INSTALL_STUBS hook
     * after the core install, letting subscribers contribute additional stubs.
     *
     * @return array{written: list<string>, skipped: list<string>, backedUp: list<string>, gitignoreAction: string}
     */
    public static function run(bool $overwrite = false): array
    {
        $result = self::installFromDir(self::stubsDir(), self::resolveStubs(), $overwrite);
        $result['gitignoreAction'] = self::mergeGitignore();

        // Hook for downstream addons (e.g. redaxo-massif). They can append their
        // own files by calling installFromDir() and merging the returned arrays
        // into the subject. Subject preserved as-is when no subscribers.
        $hookResult = rex_extension::registerPoint(
            new rex_extension_point('VITEREX_INSTALL_STUBS', $result, ['overwrite' => $overwrite]),
        );
        return is_array($hookResult) ? $hookResult : $result;
    }

    /**
     * Generic installer — public API for downstream addons.
     *
     * @param string $sourceDir Absolute path to the source directory (e.g., __DIR__ . '/frontend')
     * @param array<string,string> $stubsMap Map of source-relative-to-$sourceDir → target-relative-to-base
     * @param bool $overwrite If true, existing files are backed up (timestamped sibling) before being replaced
     * @param array{dependencies?: array<string,string>, devDependencies?: array<string,string>}|null $packageDeps
     *        Optional npm dependency merge. Adds these into the user's project package.json (additive,
     *        higher version wins on conflict). Pass null to skip the merge step.
     * @param bool|null $backup Whether to back up existing files when $overwrite is true. Defaults to true.
     * @return array{written: list<string>, skipped: list<string>, backedUp: list<string>, packageDepsMerged?: int}
     */
    public static function installFromDir(
        string $sourceDir,
        array $stubsMap,
        bool $overwrite = false,
        ?array $packageDeps = null,
        ?bool $backup = true
    ): array {
        $written = [];
        $skipped = [];
        $backedUp = [];
        foreach ($stubsMap as $source => $relTarget) {
            $sourcePath = rtrim($sourceDir, '/') . '/' . ltrim($source, '/');
            if (!is_file($sourcePath)) {
                continue;
            }
            $target = rex_path::base(ltrim($relTarget, '/'));
            if (file_exists($target)) {
                if (!$overwrite) {
                    $skipped[] = $relTarget;
                    continue;
                }
                if ($backup) {
                    $backupPath = $target . '.bak.' . date('Ymd-His');
                    rex_file::copy($target, $backupPath);
                    $backedUp[] = $relTarget . ' → ' . basename($backupPath);
                }
            }
            rex_dir::create(dirname($target));
            rex_file::put($target, self::transform($source, $sourcePath));
            $written[] = $relTarget;
        }
        $result = compact('written', 'skipped', 'backedUp');
        if ($packageDeps !== null) {
            $result['packageDepsMerged'] = self::syncPackageDeps($packageDeps);
        }
        return $result;
    }

    /**
     * Append lines to viterex's `refresh_globs` rex_config (idempotent — only
     * adds lines not already present). Useful for downstream addons that need
     * Vite to watch additional paths (e.g. their own fragments/lib directories).
     *
     * @param list<string> $lines
     * @return int Number of lines actually appended
     */
    public static function appendRefreshGlobs(array $lines): int
    {
        $current = Config::get('refresh_globs');
        $existing = array_map('trim', explode("\n", $current));
        $new = array_values(array_diff(array_map('trim', $lines), $existing));
        if (empty($new)) {
            return 0;
        }
        Config::set('refresh_globs', rtrim($current) . "\n" . implode("\n", $new));
        return count($new);
    }

    /**
     * Merge npm dependencies into the user's project package.json.
     * Additive — never removes user-installed deps. On version conflict, keeps
     * whichever constraint compares higher via `version_compare` on the
     * leading semver segment. Idempotent — safe to call on every addon
     * install/update.
     *
     * Public so install.php and downstream addons can push deps into the
     * user's project without doing a full stubs install.
     *
     * @param array{dependencies?: array<string,string>, devDependencies?: array<string,string>} $deps
     * @return int Number of new entries added (across both sections)
     */
    public static function syncPackageDeps(array $deps): int
    {
        $packageJsonPath = rex_path::base('package.json');
        if (!is_file($packageJsonPath)) {
            return 0;
        }
        $raw = (string) rex_file::get($packageJsonPath);
        $pkg = json_decode($raw, true);
        if (!is_array($pkg)) {
            return 0;
        }
        $added = 0;
        foreach (['dependencies', 'devDependencies'] as $section) {
            if (empty($deps[$section]) || !is_array($deps[$section])) {
                continue;
            }
            $current = is_array($pkg[$section] ?? null) ? $pkg[$section] : [];
            foreach ($deps[$section] as $name => $constraint) {
                if (!isset($current[$name])) {
                    $current[$name] = $constraint;
                    $added++;
                    continue;
                }
                // Both have it — keep the higher constraint (naive — strips ^ ~ etc.)
                $existingV = ltrim((string) $current[$name], '^~>=< ');
                $incomingV = ltrim((string) $constraint, '^~>=< ');
                if (version_compare($incomingV, $existingV, '>')) {
                    $current[$name] = $constraint;
                }
            }
            ksort($current);
            $pkg[$section] = $current;
        }
        rex_file::put($packageJsonPath, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $added;
    }

    private static function stubsDir(): string
    {
        return dirname(__DIR__) . '/stubs';
    }

    /**
     * @return array<string,string> source-relative-to-stubs/ → target-relative-to-base
     */
    private static function resolveStubs(): array
    {
        $cfg = Config::all();
        $sourceDir = trim($cfg['assets_source_dir'], '/');
        return [
            'package.json'        => '/package.json',
            'vite.config.js'      => '/vite.config.js',          // path-baked
            '.env.example'        => '/.env.example',
            '.browserslistrc'     => '/.browserslistrc',
            '.prettierrc'         => '/.prettierrc',
            // shipped with a .stub suffix so biome's tree-wide config discovery
            // in the user's project doesn't find it as a second root config
            'biome.jsonc.stub'    => '/biome.jsonc',
            'stylelint.config.js.stub' => '/stylelint.config.js',
            'jsconfig.json'       => '/jsconfig.json',
            'main.js'             => '/' . $sourceDir . '/js/main.js',
            'style.css'           => '/' . $sourceDir . '/css/style.css',
        ];
    }

    private static function transform(string $source, string $sourcePath): string
    {
        $contents = (string) rex_file::get($sourcePath);
        if (basename($source) === 'vite.config.js') {
            $contents = str_replace(
                '__VITEREX_PLUGIN_IMPORT_PATH__',
                self::resolveViterexPluginImportPath(),
                $contents,
            );
        }
        if (basename($source) === 'package.json') {
            // scope lint/format globs to the user's assets source dir so the
            // scripts never touch core/addon/vendored files
            $contents = str_replace(
                '__VITEREX_ASSETS_SOURCE_DIR__',
                trim(Config::get('assets_source_dir'), '/'),
                $contents,
            );
        }
        return $contents;
    }

    private static function resolveViterexPluginImportPath(): string
    {
        $publicDir = trim(Config::get('public_dir'), '/');
        $base = $publicDir === '' ? '.' : './' . $publicDir;
        return $base . '/assets/addons/viterex_addon/viterex-vite-plugin.js';
    }

    private static function mergeGitignore(): string
    {
        $existingPath = rex_path::base('.gitignore');
        $stubPath = self::stubsDir() . '/.gitignore.example';
        if (!is_file($stubPath)) {
            return 'no .gitignore.example stub shipped';
        }
        $required = array_values(array_filter(
            array_map('trim', explode("\n", (string) rex_file::get($stubPath))),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
        if (!file_exists($existingPath)) {
            rex_file::put($existingPath, "# Added by viterex\n" . implode("\n", $required) . "\n");
            return 'created';
        }
        $existing = (string) rex_file::get($existingPath);
        $existingLines = array_map('trim', explode("\n", $existing));
        $missing = array_values(array_diff($required, $existingLines));
        if (empty($missing)) {
            return 'already complete';
        }
        rex_file::put(
            $existingPath,
            rtrim($existing) . "\n\n# Added by viterex\n" . implode("\n", $missing) . "\n",
        );
        return 'appended ' . count($missing) . ' line(s)';
    }
}
