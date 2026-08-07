<?php
/*
 * Redaxo CMS Vite integration
 *
 *  @author      ynamite @ GitHub <https://github.com/ynamite/viterex_addon>
 *
 *  For copyright and license information, please view the LICENSE.md
 *  file that was distributed with this source code.
 */


use Ynamite\ViteRex\Badge;
use Ynamite\ViteRex\Config;
use Ynamite\ViteRex\Media\SvgHook;
use Ynamite\ViteRex\OutputFilter;
use Ynamite\ViteRex\Server;

rex_fragment::addDirectory(rex_path::addon('project/fragments'));
rex_fragment::addDirectory(rex_path::base('src/fragments'));

// Regenerate structure.json on every cache clear. Its host_url snapshot goes
// stale when the yrewrite default domain changes after this addon was
// installed (classic case: an installer seeds the DB after package:install,
// leaving host_url as yrewrite's unconfigured "http://." placeholder — the
// Vite plugin then serves that as its CORS origin). LATE so yrewrite has
// rebuilt its own domain data first. Registered before the badge's
// clear-cache endpoint below so that path resyncs too.
rex_extension::register('CACHE_DELETED', static function (): void {
    Config::syncStructureJson();
}, rex_extension::LATE);

if ('' !== (string) rex_request::request('viterex_clear_cache', 'string', '')) {
    if (rex_backend_login::hasSession() && rex_csrf_token::factory('viterex_badge')->isValid()) {
        rex_delete_cache();
        header('Content-Type: application/json', true, 200);
        echo json_encode(['ok' => true]);
    } else {
        header('Content-Type: application/json', true, 403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']);
    }
    exit;
}

if (rex_addon::get('developer')->isAvailable()) {
    rex_developer_manager::setBasePath(rex_path::src());
}

// Vite live-reload signal. Touched only on actual admin content saves
// (not on lazy cache regeneration during frontend navigation), so the
// Vite watcher fires reloads only when something genuinely changed.
//
// IMPORTANT: handler must return `void` (not the bool from touch()), because
// Redaxo's rex_extension::registerPoint treats any non-null return value as
// the new EP subject — clobbering save-success messages, block_peek's iframe
// HTML, and other chained EP data.
$viterexReloadSignal = static function (): void {
    @touch(rex_path::base('.vite-reload-trigger'));
};
$viterexReloadEps = [
    'ART_ADDED',
    'ART_UPDATED',
    'ART_DELETED',
    'ART_MOVED',
    'ART_COPIED',
    'ART_STATUS',
    'CAT_ADDED',
    'CAT_UPDATED',
    'CAT_DELETED',
    'CAT_MOVED',
    'CAT_STATUS',
    'SLICE_ADDED',
    'SLICE_UPDATED',
    'SLICE_DELETED',
    'SLICE_MOVE',
    'MEDIA_ADDED',
    'MEDIA_UPDATED',
    'MEDIA_DELETED',
    'CLANG_ADDED',
    'CLANG_UPDATED',
    'CLANG_DELETED',
    'TEMPLATE_ADDED',
    'TEMPLATE_UPDATED',
    'TEMPLATE_DELETED',
    'MODULE_ADDED',
    'MODULE_UPDATED',
    'MODULE_DELETED',
];
if (rex_addon::get('yform')->isAvailable()) {
    $viterexReloadEps[] = 'YFORM_DATA_ADDED';
    $viterexReloadEps[] = 'YFORM_DATA_UPDATED';
    $viterexReloadEps[] = 'YFORM_DATA_DELETED';
}
foreach ($viterexReloadEps as $epName) {
    rex_extension::register($epName, $viterexReloadSignal);
}

// Optimize SVGs as they enter the media pool. Independent registration on
// the same MEDIA_* EPs as the reload-signal handler — both fire, neither
// interferes (each returns void).
SvgHook::register();

// block_peek preview iframes are rendered into the BACKEND response body.
// Our OUTPUT_FILTER bails on backend (would otherwise rewrite literal
// "REX_VITE" strings appearing in admin UIs). Hook block_peek's own
// BLOCK_PEEK_OUTPUT EP so REX_VITE inside the iframe template still gets
// rewritten. LATE so we run after block_peek's own assembly.
if (rex_addon::get('block_peek')->isAvailable()) {
    rex_extension::register('BLOCK_PEEK_OUTPUT', static function (rex_extension_point $ep): ?string {
        $content = $ep->getSubject();
        return is_string($content) ? OutputFilter::rewriteHtml($content) : null;
    }, rex_extension::LATE);
}

/** @var rex_addon_interface $addon */
$addon = $this;

// noindex on dev/staging — only when ydeploy is installed (we have no
// reliable stage signal otherwise; absent ydeploy, treat as untouched).
if (rex_addon::get('ydeploy')->isAvailable()) {
    rex_extension::register('YREWRITE_SEO_TAGS', static function (rex_extension_point $ep): void {
        $tags = $ep->getSubject();
        if (!Server::isProductionDeployment()) {
            $tags['robots'] = '<meta name="robots" content="noindex, nofollow" />';
        }
        $ep->setSubject($tags);
    });
}

rex_extension::register(
    'OUTPUT_FILTER',
    [OutputFilter::class, 'register'],
    rex_extension::EARLY,
);

if (rex_backend_login::hasSession() && !Server::isProductionDeployment() && !Server::isStagingDeployment()) {
    rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep) use ($addon): void {
        $content = $ep->getSubject();
        if (!is_string($content)) {
            return;
        }
        $badge = Badge::get($addon);
        $content = str_ireplace('</body>', $badge . '</body>', $content);
        $ep->setSubject($content);
    });
}
