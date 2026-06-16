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
