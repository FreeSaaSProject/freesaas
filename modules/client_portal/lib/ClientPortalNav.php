<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * One storefront link: Sign in → Cabinet. Strips CMS duplicates (/lk/, «кабинет», «вход»).
 */
final class ClientPortalNav
{
    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public static function mergeInto(array $items, string $current = ''): array
    {
        ClientPortalAuth::ensureBag();
        $loggedIn = ClientPortalAuth::check();
        $label = $loggedIn ? t('client_portal.nav.cabinet') : t('client_portal.nav.sign_in');
        $href = $loggedIn ? '/cabinet' : '/cabinet/login';
        $isCurrent = $current === 'cabinet'
            || $current === 'cabinet_login'
            || self::pathIsPortal((string)(request_path()));

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (self::isPortalAliasItem($item)) {
                continue;
            }
            $children = is_array($item['children'] ?? null) ? $item['children'] : null;
            if ($children !== null) {
                $filtered = [];
                foreach ($children as $child) {
                    if (is_array($child) && !self::isPortalAliasItem($child)) {
                        $filtered[] = $child;
                    }
                }
                $item['children'] = $filtered;
            }
            $out[] = $item;
        }

        $portal = [
            'label' => $label,
            'href' => $href,
            'current' => $isCurrent,
            'cabinet' => true,
        ];

        // Insert before cart when present, else append.
        $inserted = false;
        $final = [];
        foreach ($out as $item) {
            if (!$inserted && (!empty($item['cart']) || (string)($item['href'] ?? '') === '/cart')) {
                $final[] = $portal;
                $inserted = true;
            }
            $final[] = $item;
        }
        if (!$inserted) {
            $final[] = $portal;
        }
        return $final;
    }

    /** @param array<string,mixed> $item */
    public static function isPortalAliasItem(array $item): bool
    {
        if (!empty($item['cabinet'])) {
            return true;
        }
        $href = strtolower(trim((string)($item['href'] ?? '')));
        $href = strtok($href, '?') ?: $href;
        $href = rtrim($href, '/');
        if ($href === '/cabinet' || $href === '/cabinet/login' || $href === '/cabinet/logout'
            || $href === '/lk' || $href === '/login' || $href === '/account') {
            return true;
        }
        if (str_starts_with($href, '/cabinet/') || str_starts_with($href, '/lk/')) {
            return true;
        }
        $label = mb_strtolower(trim((string)($item['label'] ?? '')), 'UTF-8');
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        foreach (['кабинет', 'вход', 'войти', 'личный кабинет', 'cabinet', 'sign in', 'login', 'account'] as $needle) {
            if ($label === $needle) {
                return true;
            }
        }
        return false;
    }

    public static function pathIsPortal(string $path): bool
    {
        $path = strtolower(rtrim(strtok($path, '?') ?: $path, '/'));
        return $path === '/cabinet'
            || $path === '/cabinet/login'
            || $path === '/cabinet/forgot'
            || $path === '/cabinet/reset'
            || str_starts_with($path, '/cabinet/')
            || $path === '/lk'
            || str_starts_with($path, '/lk/');
    }
}
