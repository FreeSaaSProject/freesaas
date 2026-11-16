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

        $themeView = $view === 'login' ? 'cabinet_login' : 'cabinet';
        if ($view !== 'login' && $view !== 'cabinet') {
            $data['cabinet_body'] = self::captureModule($view, $data);
            $themeView = 'cabinet';
        } elseif ($view === 'cabinet') {
            $data['cabinet_body'] = (string)($data['cabinet_body'] ?? '');
        }

        $useStorefront = class_exists('SiteTheme', false)
            && class_exists('SitesFront', false)
            && self::themeViewExists($themeView);

        if ($useStorefront) {
            $navKey = $themeView === 'cabinet_login' ? 'cabinet_login' : 'cabinet';
            if (!isset($data['nav']) || !is_array($data['nav'])) {
                $data['nav'] = SitesFront::nav($navKey);
            }
            SiteTheme::render($themeView, $data);
            return;
        }

        View::render($view === 'login' ? 'login' : 'home', $data, 'layout_auth', 'client_portal');
    }

    private static function themeViewExists(string $themeView): bool
    {
        if (class_exists('SiteTheme', false)) {
            $themeFile = SiteTheme::path('views/' . $themeView . '.php');
            if (is_file($themeFile)) {
                return true;
            }
        }
        return is_file(base_path('modules/sites/views/' . $themeView . '.php'));
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
