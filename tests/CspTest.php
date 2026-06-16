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
