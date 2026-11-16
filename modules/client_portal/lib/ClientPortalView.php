<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class ClientPortalView
{
    /** @param array<string,mixed> $data */
    public static function render(string $view, array $data = []): void
    {
        Assets::need('client_portal');
        $site = [];
        if (class_exists('SitesFront', false)) {
            $site = SitesFront::siteMeta();
        }
        $data['site'] = $site;
        $data['cabinet_nav'] = $data['cabinet_nav'] ?? [];
        $data['client'] = $data['client'] ?? ClientPortalAuth::client();

        if (class_exists('SiteTheme', false) && class_exists('SitesHostContext', false)) {
            // Prefer theme views; fall back to module capture injected as content.
            $themeView = $view === 'login' ? 'cabinet_login' : 'cabinet';
            $themeFile = SiteTheme::path('views/' . $themeView . '.php');
            $fallback = base_path('modules/sites/views/' . $themeView . '.php');
            if (is_file($themeFile) || is_file($fallback)) {
                if ($view !== 'login' && $view !== 'cabinet') {
                    $data['cabinet_body'] = self::captureModule($view, $data);
                    $view = 'cabinet';
                    $themeView = 'cabinet';
                } elseif ($view === 'cabinet') {
                    $data['cabinet_body'] = (string)($data['cabinet_body'] ?? '');
                }
                SiteTheme::render($themeView, $data);
                return;
            }
        }

        View::render($view, $data, 'layout_auth', 'client_portal');
    }

    /** @param array<string,mixed> $data */
    public static function captureModule(string $view, array $data = []): string
    {
        $file = base_path('modules/client_portal/views/' . $view . '.php');
        if (!is_file($file)) {
            return '';
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }
}
