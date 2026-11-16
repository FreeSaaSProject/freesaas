<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** egisz module ini helpers (cfg/modules/egisz.ini). */
final class EgiszConfig
{
    public const OPERATORS = ['none', 'n3', 'medflex', 'medme', 'gigtest'];

    public const N3_ENVS = ['demo', 'prod'];

    public static function operator(): string
    {
        $v = strtolower(trim((string)Config::get('egisz', 'operator', 'none')));
        return in_array($v, self::OPERATORS, true) ? $v : 'none';
    }

    public static function moOid(): string
    {
        return trim((string)Config::get('egisz', 'mo_oid', ''));
    }

    public static function departmentOid(): string
    {
        return trim((string)Config::get('egisz', 'department_oid', ''));
    }

    public static function departmentName(): string
    {
        return trim((string)Config::get('egisz', 'department_name', ''));
    }

    public static function semdConsultOid(): string
    {
        $v = trim((string)Config::get('egisz', 'semd_consult_oid', ''));
        return $v !== '' ? $v : '10.0.0.4.172.16.1.5.2.227';
    }

    public static function semdManipOid(): string
    {
        $v = trim((string)Config::get('egisz', 'semd_manip_oid', ''));
        return $v !== '' ? $v : '10.0.0.4.172.16.1.5.2.283';
    }

    public static function n3Env(): string
    {
        $v = strtolower(trim((string)Config::get('egisz', 'n3_env', 'demo')));
        return in_array($v, self::N3_ENVS, true) ? $v : 'demo';
    }

    public static function n3Guid(): string
    {
        return trim((string)Config::get('egisz', 'n3_guid', ''));
    }

    public static function n3IdLpu(): string
    {
        return trim((string)Config::get('egisz', 'n3_id_lpu', ''));
    }

    public static function n3EventlogToken(): string
    {
        return trim((string)Config::get('egisz', 'n3_eventlog_token', ''));
    }

    public static function n3EmkUrl(): string
    {
        $v = trim((string)Config::get('egisz', 'n3_emk_url', ''));
        return $v !== '' ? $v : 'http://b2b-demo.n3health.ru/emk/EMKService.svc';
    }

    public static function n3PixUrl(): string
    {
        $v = trim((string)Config::get('egisz', 'n3_pix_url', ''));
        return $v !== '' ? $v : 'http://b2b-demo.n3health.ru/emk/PixService.svc';
    }

    public static function n3TermUrl(): string
    {
        $v = trim((string)Config::get('egisz', 'n3_term_url', ''));
        return $v !== '' ? rtrim($v, '/') . '/' : 'http://b2b-demo.n3health.ru/nsi/fhir/term/';
    }

    public static function n3EventlogUrl(): string
    {
        $v = trim((string)Config::get('egisz', 'n3_eventlog_url', ''));
        return $v !== '' ? rtrim($v, '/') . '/' : 'https://api.n3health.ru/eventlog/';
    }

    public static function n3FnsiUrl(): string
    {
        $v = trim((string)Config::get('egisz', 'n3_fnsi_url', ''));
        return $v !== '' ? rtrim($v, '/') . '/' : 'https://api.n3health.ru/fnsi/';
    }

    public static function n3Configured(): bool
    {
        return self::operator() === 'n3'
            && self::n3Guid() !== ''
            && self::n3IdLpu() !== '';
    }

    /** @return array<string,string|bool> */
    public static function all(): array
    {
        $guid = self::n3Guid();
        return [
            'operator' => self::operator(),
            'mo_oid' => self::moOid(),
            'department_oid' => self::departmentOid(),
            'department_name' => self::departmentName(),
            'semd_consult_oid' => self::semdConsultOid(),
            'semd_manip_oid' => self::semdManipOid(),
            'n3_env' => self::n3Env(),
            'n3_guid' => $guid,
            'n3_guid_masked' => self::maskSecret($guid),
            'n3_guid_set' => $guid !== '',
            'n3_id_lpu' => self::n3IdLpu(),
            'n3_eventlog_token_set' => self::n3EventlogToken() !== '',
            'n3_emk_url' => self::n3EmkUrl(),
            'n3_pix_url' => self::n3PixUrl(),
            'n3_term_url' => self::n3TermUrl(),
            'n3_eventlog_url' => self::n3EventlogUrl(),
            'n3_fnsi_url' => self::n3FnsiUrl(),
            'n3_configured' => self::n3Configured(),
            'worker_enabled' => (string)Config::get('egisz', 'worker_enabled', '0') === '1',
            'worker_token_set' => class_exists('EgiszWorkerAuth', false) && EgiszWorkerAuth::isConfigured(),
            'worker_token_masked' => class_exists('EgiszWorkerAuth', false) ? EgiszWorkerAuth::mask() : '',
            'worker_dispatch' => class_exists('EgiszWorkerDispatch', false) && EgiszWorkerDispatch::enabled(),
        ];
    }

    /** Safe for agent/UI JSON (no raw GUID). */
    public static function allPublic(): array
    {
        $all = self::all();
        unset($all['n3_guid']);
        return $all;
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        $op = strtolower(trim((string)($post['operator'] ?? 'none')));
        if (!in_array($op, self::OPERATORS, true)) {
            $op = 'none';
        }
        Config::set('egisz', 'operator', $op);
        Config::set('egisz', 'mo_oid', mb_substr(trim((string)($post['mo_oid'] ?? '')), 0, 128));
        Config::set('egisz', 'department_oid', mb_substr(trim((string)($post['department_oid'] ?? '')), 0, 128));
        Config::set('egisz', 'department_name', mb_substr(trim((string)($post['department_name'] ?? '')), 0, 255));

        $consult = trim((string)($post['semd_consult_oid'] ?? ''));
        $manip = trim((string)($post['semd_manip_oid'] ?? ''));
        if ($consult !== '') {
            Config::set('egisz', 'semd_consult_oid', mb_substr($consult, 0, 128));
        }
        if ($manip !== '') {
            Config::set('egisz', 'semd_manip_oid', mb_substr($manip, 0, 128));
        }

        $env = strtolower(trim((string)($post['n3_env'] ?? 'demo')));
        if (!in_array($env, self::N3_ENVS, true)) {
            $env = 'demo';
        }
        Config::set('egisz', 'n3_env', $env);

        if (array_key_exists('n3_guid', $post)) {
            $guid = trim((string)$post['n3_guid']);
            // Empty or masked placeholder keeps previous secret.
            if ($guid !== '' && strpos($guid, '…') === false && strpos($guid, '...') === false) {
                Config::set('egisz', 'n3_guid', mb_substr($guid, 0, 64));
            }
        }
        if (array_key_exists('n3_id_lpu', $post)) {
            Config::set('egisz', 'n3_id_lpu', mb_substr(trim((string)$post['n3_id_lpu']), 0, 64));
        }
        if (array_key_exists('n3_eventlog_token', $post)) {
            $tok = trim((string)$post['n3_eventlog_token']);
            if ($tok !== '' && strpos($tok, '…') === false && strpos($tok, '...') === false) {
                Config::set('egisz', 'n3_eventlog_token', mb_substr($tok, 0, 128));
            }
        }
        foreach (['n3_emk_url', 'n3_pix_url', 'n3_term_url', 'n3_eventlog_url', 'n3_fnsi_url'] as $urlKey) {
            if (!array_key_exists($urlKey, $post)) {
                continue;
            }
            $url = trim((string)$post[$urlKey]);
            if ($url !== '') {
                Config::set('egisz', $urlKey, mb_substr($url, 0, 512));
            }
        }

        if (array_key_exists('worker_enabled', $post) || array_key_exists('worker_enabled_present', $post)) {
            $on = !empty($post['worker_enabled']) && (string)$post['worker_enabled'] !== '0';
            Config::set('egisz', 'worker_enabled', $on ? '1' : '0');
        }
        if (array_key_exists('worker_token', $post) && empty($post['worker_token_regen'])) {
            $tok = trim((string)$post['worker_token']);
            if ($tok !== '' && strpos($tok, '…') === false && strpos($tok, '...') === false) {
                Config::set('egisz', 'worker_token', mb_substr($tok, 0, 128));
            }
        }
    }

    public static function transportReady(): bool
    {
        if (self::operator() === 'n3') {
            return self::n3Configured();
        }
        return self::operator() !== 'none' && self::moOid() !== '';
    }

    private static function maskSecret(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }
        $len = strlen($secret);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($secret, 0, 4) . '…' . substr($secret, -4);
    }
}
