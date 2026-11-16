<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class AcquiringConfig
{
    public static function defaultProvider(): string
    {
        $id = trim((string)Config::get('acquiring', 'default_provider', 'yookassa'));
        return $id !== '' ? $id : 'yookassa';
    }

    public static function allowClientChoice(): bool
    {
        return (int)Config::get('acquiring', 'allow_client_choice', 0) === 1;
    }

    public static function currency(): string
    {
        $c = strtoupper(trim((string)Config::get('acquiring', 'currency', 'RUB')));
        return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'RUB';
    }

    /** @return array<string,mixed> */
    public static function providerSettings(string $id): array
    {
        if ($id === 'yookassa') {
            return [
                'enabled' => (int)Config::get('acquiring', 'yookassa_enabled', 0) === 1,
                'shop_id' => trim((string)Config::get('acquiring', 'yookassa_shop_id', '')),
                'secret_key' => trim((string)Config::get('acquiring', 'yookassa_secret_key', '')),
            ];
        }
        if ($id === 'tbank') {
            return [
                'enabled' => (int)Config::get('acquiring', 'tbank_enabled', 0) === 1,
                'terminal_key' => trim((string)Config::get('acquiring', 'tbank_terminal_key', '')),
                'password' => trim((string)Config::get('acquiring', 'tbank_password', '')),
            ];
        }
        return ['enabled' => false];
    }

    /** @param array<string,mixed> $post */
    public static function saveFromPost(array $post): void
    {
        Config::set('acquiring', 'default_provider', preg_replace('/[^a-z0-9_]/', '', (string)($post['default_provider'] ?? 'yookassa')) ?? 'yookassa');
        Config::set('acquiring', 'allow_client_choice', !empty($post['allow_client_choice']) ? '1' : '0');
        Config::set('acquiring', 'currency', strtoupper(substr(trim((string)($post['currency'] ?? 'RUB')), 0, 3)));
        Config::set('acquiring', 'yookassa_enabled', !empty($post['yookassa_enabled']) ? '1' : '0');
        Config::set('acquiring', 'yookassa_shop_id', trim((string)($post['yookassa_shop_id'] ?? '')));
        if (trim((string)($post['yookassa_secret_key'] ?? '')) !== '') {
            Config::set('acquiring', 'yookassa_secret_key', trim((string)$post['yookassa_secret_key']));
        }
        Config::set('acquiring', 'tbank_enabled', !empty($post['tbank_enabled']) ? '1' : '0');
        Config::set('acquiring', 'tbank_terminal_key', trim((string)($post['tbank_terminal_key'] ?? '')));
        if (trim((string)($post['tbank_password'] ?? '')) !== '') {
            Config::set('acquiring', 'tbank_password', trim((string)$post['tbank_password']));
        }
    }
}
