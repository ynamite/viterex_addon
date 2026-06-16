<?php

namespace Ynamite\ViteRex;

use rex_extension;
use rex_extension_point;

final class Preload
{
    private static ?self $instance = null;

    private array $manifest;
    private string $buildUrlPath;

    public function __construct()
    {
        $this->manifest = Server::factory()->getManifestArray();
        $this->buildUrlPath = '/' . trim(Config::get('build_url_path'), '/');
    }

    public static function factory(): self
    {
        return self::$instance ??= new self();
    }

    public static function renderForEntries(array $entries, string $nonceAttr = ''): string
    {
        return self::factory()->build($entries, $nonceAttr);
    }

    private function build(array $entries, string $nonceAttr = ''): string
    {
        $lines = Server::isDevMode()
            ? []
            : self::buildLinesForManifest($this->manifest, $this->buildUrlPath, $entries, $nonceAttr);

        $extra = rex_extension::registerPoint(
            new rex_extension_point('VITEREX_PRELOAD', [], [
                'entries' => $entries,
                'dev'     => Server::isDevMode(),
            ]),
        );
        if (is_array($extra)) {
            foreach ($extra as $item) {
                if (is_string($item) && $item !== '') {
                    $lines[] = $item;
                }
            }
        }

        return implode("\n", array_values(array_unique($lines)));
    }

    /**
     * @internal Pure helper so tests can exercise manifest walking without a Redaxo bootstrap.
     *
     * @param array<string,array<string,mixed>> $manifest
     * @param list<string>                       $entries
     *
     * @return list<string>
     */
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

    /**
     * @param array<string,array<string,mixed>> $manifest
     * @param array<string,mixed>               $entry
     * @param array<string,bool>                $visited
     *
     * @return list<string>
     */
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

    private static function modulePreload(string $base, string $file, string $nonceAttr = ''): string
    {
        return '<link rel="modulepreload" href="' . htmlspecialchars(self::url($base, $file)) . '"' . $nonceAttr . '>';
    }

    private static function stylePreload(string $base, string $file, string $nonceAttr = ''): string
    {
        return '<link rel="preload" href="' . htmlspecialchars(self::url($base, $file)) . '" as="style"' . $nonceAttr . '>';
    }

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

    private static function url(string $base, string $file): string
    {
        return $base . '/' . ltrim($file, '/');
    }
}
