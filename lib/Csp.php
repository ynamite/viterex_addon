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
