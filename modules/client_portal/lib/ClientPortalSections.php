<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class ClientPortalSections
{
    /**
     * Raw registry from modules (before visibility filter).
     *
     * @return list<array{id:string,module:string,title:string,weight:int,nav:bool,home_card:bool}>
     */
    public static function collect(): array
    {
        $payload = EventBus::emit('client_portal.sections', ['sections' => []]);
        $raw = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9_]/', '', (string)($row['id'] ?? '')) ?? '';
            $module = preg_replace('/[^a-z0-9_]/', '', (string)($row['module'] ?? '')) ?? '';
            if ($id === '' || $module === '') {
                continue;
            }
            if (!ModuleManager::isEnabled($module) && $module !== 'client_portal') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'module' => $module,
                'title' => (string)($row['title'] ?? $id),
                'weight' => (int)($row['weight'] ?? 100),
                'nav' => !isset($row['nav']) || !empty($row['nav']),
                'home_card' => !isset($row['home_card']) || !empty($row['home_card']),
            ];
        }
        return $out;
    }

    /**
     * @return list<array{id:string,module:string,title:string,weight:int,nav:bool,home_card:bool}>
     */
    public static function visible(): array
    {
        $weights = ClientPortalConfig::weights();
        $list = [];
        foreach (self::collect() as $row) {
            if (!ClientPortalConfig::isSectionVisible($row['id'])) {
                continue;
            }
            if (isset($weights[$row['id']])) {
                $row['weight'] = $weights[$row['id']];
            }
            $list[] = $row;
        }
        usort($list, static function (array $a, array $b): int {
            return $a['weight'] <=> $b['weight'] ?: strcmp($a['id'], $b['id']);
        });
        return $list;
    }

    /** @return array{id:string,module:string,title:string,weight:int,nav:bool,home_card:bool}|null */
    public static function findVisible(string $id): ?array
    {
        foreach (self::visible() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @return list<array{id:string,label:string,href:string,current?:bool}>
     */
    public static function nav(string $current = ''): array
    {
        $items = [
            [
                'id' => 'home',
                'label' => t('client_portal.nav.home'),
                'href' => '/cabinet',
                'current' => $current === '' || $current === 'home',
            ],
        ];
        foreach (self::visible() as $row) {
            if (!$row['nav']) {
                continue;
            }
            $items[] = [
                'id' => $row['id'],
                'label' => $row['title'],
                'href' => '/cabinet/' . rawurlencode($row['id']),
                'current' => $current === $row['id'],
            ];
        }
        $payload = EventBus::emit('client_portal.nav', [
            'items' => $items,
            'current' => $current,
            'client_id' => ClientPortalAuth::clientId(),
        ]);
        $out = is_array($payload['items'] ?? null) ? $payload['items'] : $items;
        $norm = [];
        foreach ($out as $it) {
            if (!is_array($it) || empty($it['href']) || empty($it['label'])) {
                continue;
            }
            $norm[] = [
                'id' => (string)($it['id'] ?? ''),
                'label' => (string)$it['label'],
                'href' => (string)$it['href'],
                'current' => !empty($it['current']),
            ];
        }
        return $norm;
    }

    /**
     * @return list<array{id:string,title:string,html:string,href:string}>
     */
    public static function homeCards(int $clientId): array
    {
        $cards = [];
        foreach (self::visible() as $row) {
            if (!$row['home_card']) {
                continue;
            }
            $cards[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'html' => '',
                'href' => '/cabinet/' . rawurlencode($row['id']),
            ];
        }
        $payload = EventBus::emit('client_portal.home', [
            'cards' => $cards,
            'client_id' => $clientId,
        ]);
        $out = is_array($payload['cards'] ?? null) ? $payload['cards'] : $cards;
        $norm = [];
        foreach ($out as $c) {
            if (!is_array($c) || empty($c['id'])) {
                continue;
            }
            $norm[] = [
                'id' => (string)$c['id'],
                'title' => (string)($c['title'] ?? $c['id']),
                'html' => (string)($c['html'] ?? ''),
                'href' => (string)($c['href'] ?? ('/cabinet/' . rawurlencode((string)$c['id']))),
            ];
        }
        return $norm;
    }

    public static function renderSection(string $sectionId, int $clientId): string
    {
        $payload = EventBus::emit('client_portal.render', [
            'section' => $sectionId,
            'client_id' => $clientId,
            'html' => '',
        ]);
        return trim((string)($payload['html'] ?? ''));
    }
}
