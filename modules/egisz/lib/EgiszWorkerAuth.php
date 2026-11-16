<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Edge worker token for /egisz/worker/* (header X-Egisz-Token).
 */

final class EgiszWorkerAuth
{
    public static function configuredToken(): string
    {
        $token = trim((string)Config::get('egisz', 'worker_token', ''));
        if ($token === '' || $token === 'CHANGE_ME') {
            return '';
        }
        return $token;
    }

    public static function isConfigured(): bool
    {
        return self::configuredToken() !== '';
    }

    public static function mask(?string $token = null): string
    {
        $t = $token !== null ? trim($token) : self::configuredToken();
        if ($t === '') {
            return '';
        }
        $len = strlen($t);
        if ($len <= 12) {
            return str_repeat('•', $len);
        }
        return substr($t, 0, 4) . '…' . substr($t, -4);
    }

    public static function generateAndPersist(): string
    {
        $token = bin2hex(random_bytes(32));
        Config::set('egisz', 'worker_token', $token);
        return $token;
    }

    public static function ensureGenerated(): string
    {
        if (self::isConfigured()) {
            return '';
        }
        return self::generateAndPersist();
    }

    public static function assertToken(): void
    {
        $expected = self::configuredToken();
        if ($expected === '') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'worker_token not configured'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $got = (string)($_SERVER['HTTP_X_EGISZ_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? '');
        if (!hash_equals($expected, $got)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
