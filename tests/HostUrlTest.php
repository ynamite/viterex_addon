<?php

namespace Ynamite\ViteRex\Tests;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Config;

/**
 * Config::hostUrlFromDomainRows() derives host_url straight from
 * rex_yrewrite_domain table rows (the source of truth), because yrewrite's
 * in-process static domain state is one boot behind when the DB is seeded
 * after yrewrite's cache was generated (e.g. create-viterex installs).
 * Normalization mirrors rex_yrewrite::generateConfig() + rex_yrewrite_domain.
 */
final class HostUrlTest extends TestCase
{
    private static function row(string $domain, int $startId = 1, int $notfoundId = 1): array
    {
        return ['domain' => $domain, 'start_id' => $startId, 'notfound_id' => $notfoundId];
    }

    public function testFullUrlWithTrailingSlashIsTrimmed(): void
    {
        $this->assertSame(
            'http://gastrozentrum.test',
            Config::hostUrlFromDomainRows([self::row('http://gastrozentrum.test/')]),
        );
    }

    public function testSchemelessHostDefaultsToHttp(): void
    {
        $this->assertSame(
            'http://gastrozentrum.test',
            Config::hostUrlFromDomainRows([self::row('gastrozentrum.test')]),
        );
    }

    public function testHttpsSchemeIsPreserved(): void
    {
        $this->assertSame(
            'https://example.com',
            Config::hostUrlFromDomainRows([self::row('https://example.com/')]),
        );
    }

    public function testPortIsPreserved(): void
    {
        $this->assertSame(
            'http://example.com:8080',
            Config::hostUrlFromDomainRows([self::row('example.com:8080')]),
        );
    }

    public function testPathIsKeptWithoutTrailingSlash(): void
    {
        $this->assertSame(
            'http://example.com/sub',
            Config::hostUrlFromDomainRows([self::row('http://example.com/sub/')]),
        );
    }

    public function testSkipsInvalidRowsAndPicksFirstValid(): void
    {
        $this->assertSame(
            'http://real.test',
            Config::hostUrlFromDomainRows([
                self::row(''),
                self::row('unmounted.test', 0, 0),
                self::row('http://real.test/'),
                self::row('http://second.test/'),
            ]),
        );
    }

    public function testReturnsNullWhenNoValidRows(): void
    {
        $this->assertNull(Config::hostUrlFromDomainRows([]));
        $this->assertNull(Config::hostUrlFromDomainRows([self::row('', 0, 0)]));
    }
}
