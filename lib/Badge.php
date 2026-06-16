<?php

namespace Ynamite\ViteRex;

use rex;
use rex_addon;
use rex_addon_interface;
use rex_csrf_token;
use rex_extension;
use rex_extension_point;

final class Badge
{
    public static function get(rex_addon_interface $addon): string
    {
        $version     = (string) $addon->getVersion();
        $rexVersion  = (string) rex::getVersion();
        $gitBranch   = Server::getGitBranch();
        $stage       = Server::getDeploymentStage();
        $viteRunning = Server::isDevMode();
        $viteUrl     = Server::getDevUrl() ?? '';
        $csrfToken   = rex_csrf_token::factory('viterex_badge')->getValue();

        $extras = rex_extension::registerPoint(new rex_extension_point('VITEREX_BADGE', []));
        $extrasHtml = '';
        if (is_array($extras)) {
            foreach ($extras as $panel) {
                if (is_string($panel) && $panel !== '') {
                    $extrasHtml .= $panel;
                }
            }
        }

        $style = '<link rel="stylesheet" href="' . htmlspecialchars($addon->getAssetsUrl('badge/viterex-badge.css')) . '"' . Csp::attr() . '>';

        $script = sprintf(
            '<script type="module" src="%s" id="viterex-badge-script"'
                . ' data-version="%s"'
                . ' data-rex-version="%s"'
                . ' data-git-branch="%s"'
                . ' data-stage="%s"'
                . ' data-vite-running="%s"'
                . ' data-vite-url="%s"'
                . ' data-csrf-token="%s"'
                . '%s></script>',
            htmlspecialchars($addon->getAssetsUrl('badge/viterex-badge.js')),
            htmlspecialchars($version),
            htmlspecialchars($rexVersion),
            htmlspecialchars($gitBranch),
            htmlspecialchars($stage),
            $viteRunning ? 'true' : 'false',
            htmlspecialchars($viteUrl),
            htmlspecialchars($csrfToken),
            Csp::attr(),
        );

        $extrasMarkup = $extrasHtml !== ''
            ? '<template id="viterex-badge-extras">' . $extrasHtml . '</template>'
            : '';

        return $style . $script . $extrasMarkup;
    }
}
