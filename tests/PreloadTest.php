<?php

namespace Ynamite\ViteRex\Tests;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Preload;

final class PreloadTest extends TestCase
{
    private const BUILD = '/dist';

    /** Regression: woff2 fonts attached as `assets` to a CSS entry must be preloaded. */
    public function testCssEntryFontAssetsArePreloaded(): void
    {
        $manifest = [
            'src/assets/css/style.css' => [
                'file'    => 'assets/style-DDs1J1uh.css',
                'name'    => 'style',
                'src'     => 'src/assets/css/style.css',
                'isEntry' => true,
                'assets'  => [
                    'assets/inter-400-COLGFB3M.woff2',
                    'assets/inter-600-BAEEcJ4E.woff2',
                    'assets/inter-800-BUaDDWMS.woff2',
                ],
            ],
        ];

        $lines = Preload::buildLinesForManifest($manifest, self::BUILD, ['src/assets/css/style.css']);

        $this->assertContains(
            '<link rel="preload" href="/dist/assets/inter-400-COLGFB3M.woff2" as="font" type="font/woff2" crossorigin>',
            $lines,
        );
        $this->assertContains(
            '<link rel="preload" href="/dist/assets/inter-600-BAEEcJ4E.woff2" as="font" type="font/woff2" crossorigin>',
            $lines,
        );
        $this->assertContains(
            '<link rel="preload" href="/dist/assets/inter-800-BUaDDWMS.woff2" as="font" type="font/woff2" crossorigin>',
            $lines,
        );
        foreach ($lines as $line) {
            $this->assertStringNotContainsString('style-DDs1J1uh.css', $line);
        }
    }

    public function testJsEntryEmitsModulePreloadAndWalksImports(): void
    {
        $manifest = [
            'src/assets/js/main.js' => [
                'file'    => 'assets/main-Xnwt5yXd.js',
                'src'     => 'src/assets/js/main.js',
                'isEntry' => true,
                'imports' => ['_chunk-AbCd.js'],
            ],
            '_chunk-AbCd.js' => [
                'file' => 'assets/chunk-AbCdEf.js',
            ],
        ];

        $lines = Preload::buildLinesForManifest($manifest, self::BUILD, ['src/assets/js/main.js']);

        $this->assertContains('<link rel="modulepreload" href="/dist/assets/main-Xnwt5yXd.js">', $lines);
        $this->assertContains('<link rel="modulepreload" href="/dist/assets/chunk-AbCdEf.js">', $lines);
    }

    public function testJsEntryEmitsCssSiblingAsStylePreload(): void
    {
        $manifest = [
            'src/assets/js/main.js' => [
                'file'    => 'assets/main-X.js',
                'isEntry' => true,
                'css'     => ['assets/main-X.css'],
            ],
        ];

        $lines = Preload::buildLinesForManifest($manifest, self::BUILD, ['src/assets/js/main.js']);

        $this->assertContains('<link rel="preload" href="/dist/assets/main-X.css" as="style">', $lines);
    }

    public function testCssEntryImageAssetEmitsAsImagePreload(): void
    {
        $manifest = [
            'src/assets/css/style.css' => [
                'file'    => 'assets/style-X.css',
                'isEntry' => true,
                'assets'  => ['assets/hero-Y.webp'],
            ],
        ];

        $lines = Preload::buildLinesForManifest($manifest, self::BUILD, ['src/assets/css/style.css']);

        $this->assertContains('<link rel="preload" href="/dist/assets/hero-Y.webp" as="image">', $lines);
    }

    public function testJsEntryImportedAssetIsPreloaded(): void
    {
        $manifest = [
            'src/assets/js/main.js' => [
                'file'    => 'assets/main-X.js',
                'isEntry' => true,
                'assets'  => ['assets/logo-Z.png'],
            ],
        ];

        $lines = Preload::buildLinesForManifest($manifest, self::BUILD, ['src/assets/js/main.js']);

        $this->assertContains('<link rel="preload" href="/dist/assets/logo-Z.png" as="image">', $lines);
    }

    public function testCrossEntryAssetIsDeduped(): void
    {
        $manifest = [
            'src/assets/css/style.css' => [
                'file'    => 'assets/style-X.css',
                'isEntry' => true,
                'assets'  => ['assets/inter-400-A.woff2'],
            ],
            'src/assets/js/main.js' => [
                'file'    => 'assets/main-Y.js',
                'isEntry' => true,
                'assets'  => ['assets/inter-400-A.woff2'],
            ],
        ];

        $lines = Preload::buildLinesForManifest($manifest, self::BUILD, [
            'src/assets/css/style.css',
            'src/assets/js/main.js',
        ]);

        $matches = array_filter($lines, static fn(string $l): bool => str_contains($l, 'inter-400-A.woff2'));
        $this->assertCount(1, $matches);
    }

    public function testUnknownExtensionIsOmitted(): void
    {
        $manifest = [
            'src/assets/css/style.css' => [
                'file'    => 'assets/style-X.css',
                'isEntry' => true,
                'assets'  => ['assets/data-Z.xml'],
            ],
        ];

        $lines = Preload::buildLinesForManifest($manifest, self::BUILD, ['src/assets/css/style.css']);

        $this->assertSame([], array_filter($lines, static fn(string $l): bool => str_contains($l, 'data-Z.xml')));
    }

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
}
