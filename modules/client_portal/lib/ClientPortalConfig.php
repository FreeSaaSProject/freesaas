<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Visibility matrix: section_id => show (1/0), weight overrides.
 */
final class ClientPortalConfig
{
    public const COOKIE = 'freesaas_client';

    public static function cookieName(): string
    {
        $n = trim((string)Config::get('client_portal', 'session_name', self::COOKIE));
        return $n !== '' ? $n : self::COOKIE;
    }

    /** @return array<string,bool> */
    public static function visibility(): array
    {
        $raw = trim((string)Config::get('client_portal', 'section_visibility', ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id => $show) {
            $id = preg_replace('/[^a-z0-9_]/', '', (string)$id) ?? '';
            if ($id === '') {
                continue;
            }
            $out[$id] = !empty($show);
        }
        return $out;
    }

    /** @return array<string,int> */
    public static function weights(): array
    {
        $raw = trim((string)Config::get('client_portal', 'section_weights', ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $id => $w) {
            $id = preg_replace('/[^a-z0-9_]/', '', (string)$id) ?? '';
            if ($id === '') {
                continue;
            }
            $out[$id] = (int)$w;
        }
        return $out;
    }

    /**
     * @param array<string,bool> $visibility
     * @param array<string,int> $weights
     */
    public static function save(array $visibility, array $weights): void
    {
        $vis = [];
        foreach ($visibility as $id => $show) {
            $id = preg_replace('/[^a-z0-9_]/', '', (string)$id) ?? '';
            if ($id === '') {
                continue;
            }
            $vis[$id] = $show ? 1 : 0;
        }
        $wOut = [];
        foreach ($weights as $id => $w) {
            $id = preg_replace('/[^a-z0-9_]/', '', (string)$id) ?? '';
            if ($id === '') {
                continue;
            }
            $wOut[$id] = (int)$w;
        }
        Config::set('client_portal', 'section_visibility', json_encode($vis, JSON_UNESCAPED_UNICODE));
        Config::set('client_portal', 'section_weights', json_encode($wOut, JSON_UNESCAPED_UNICODE));
    }

    public static function isSectionVisible(string $id): bool
    {
        $vis = self::visibility();
        if (!array_key_exists($id, $vis)) {
            return true;
        }
        return $vis[$id];
    }
}
