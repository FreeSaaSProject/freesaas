<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Separate PHP session cookie for storefront clients (not staff freesaas).
 */
final class ClientPortalAuth
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Staff session may already be open on admin; portal uses named array key inside same session
            // only when cookie names match. Prefer dedicated session name on storefront.
            return;
        }
        $name = ClientPortalConfig::cookieName();
        if (session_name() !== $name) {
            session_name($name);
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    /** Ensure portal bag exists even if staff session is already active (shared request rare). */
    public static function ensureBag(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::boot();
        }
        if (!isset($_SESSION['_client_portal']) || !is_array($_SESSION['_client_portal'])) {
            $_SESSION['_client_portal'] = [];
        }
    }

    public static function check(): bool
    {
        self::ensureBag();
        $id = (int)($_SESSION['_client_portal']['client_id'] ?? 0);
        return $id > 0;
    }

    public static function clientId(): int
    {
        self::ensureBag();
        return (int)($_SESSION['_client_portal']['client_id'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public static function client(): ?array
    {
        $id = self::clientId();
        if ($id < 1 || !class_exists('ClientsRepository', false)) {
            return null;
        }
        $row = ClientsRepository::find($id);
        return is_array($row) ? $row : null;
    }

    public static function login(int $clientId): void
    {
        self::ensureBag();
        session_regenerate_id(true);
        $_SESSION['_client_portal'] = [
            'client_id' => $clientId,
            'login_at' => time(),
        ];
        self::auditLogin($clientId);
    }

    public static function logout(): void
    {
        self::ensureBag();
        $id = self::clientId();
        if ($id > 0) {
            self::auditLogout($id);
        }
        unset($_SESSION['_client_portal']);
    }

    /**
     * @return array{ok:bool,error?:string,client_id?:int}
     */
    public static function attempt(string $phone, string $password): array
    {
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($phoneDigits === '' || $password === '') {
            return ['ok' => false, 'error' => t('client_portal.login.error')];
        }
        if (!class_exists('ClientsRepository', false)) {
            return ['ok' => false, 'error' => t('client_portal.login.error')];
        }
        $row = ClientsRepository::findByPhoneDigits($phoneDigits);
        if ($row === null) {
            return ['ok' => false, 'error' => t('client_portal.login.error')];
        }
        if ((int)($row['enabled'] ?? 0) !== 1) {
            return ['ok' => false, 'error' => t('client_portal.login.disabled')];
        }
        $stored = (string)($row['password'] ?? '');
        if (!self::verifyPassword($password, $stored)) {
            return ['ok' => false, 'error' => t('client_portal.login.error')];
        }
        $clientId = (int)$row['id'];
        if (self::needsRehash($stored)) {
            self::storePassword($clientId, $password);
        }
        self::login($clientId);
        return ['ok' => true, 'client_id' => $clientId];
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $plain, string $stored): bool
    {
        if ($stored === '') {
            return false;
        }
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon')) {
            return password_verify($plain, $stored);
        }
        // Legacy staff-managed plaintext in base_clients.password
        return hash_equals($stored, $plain);
    }

    public static function needsRehash(string $stored): bool
    {
        if ($stored === '') {
            return false;
        }
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon')) {
            return password_needs_rehash($stored, PASSWORD_DEFAULT);
        }
        return true;
    }

    public static function storePassword(int $clientId, string $plain): void
    {
        if ($clientId < 1 || $plain === '' || !Database::tableExists('base_clients')) {
            return;
        }
        $st = Database::pdo()->prepare('UPDATE base_clients SET password = ? WHERE id = ?');
        $st->execute([self::hashPassword($plain), $clientId]);
    }

    private static function auditLogin(int $clientId): void
    {
        if (!Database::tableExists('base_client_portal_sessions')) {
            return;
        }
        $key = bin2hex(random_bytes(16));
        $_SESSION['_client_portal']['audit_key'] = $key;
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'INSERT INTO base_client_portal_sessions
             (CLIENT_ID, SESSION_KEY, IP, USER_AGENT, DT_LOGIN, DT_LAST, DT_LOGOUT)
             VALUES (?,?,?,?,?,?,0)'
        );
        $st->execute([
            $clientId,
            $key,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            time(),
            time(),
        ]);
    }

    private static function auditLogout(int $clientId): void
    {
        if (!Database::tableExists('base_client_portal_sessions')) {
            return;
        }
        $key = (string)($_SESSION['_client_portal']['audit_key'] ?? '');
        if ($key === '') {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_client_portal_sessions SET DT_LOGOUT = ?, DT_LAST = ? WHERE SESSION_KEY = ? AND CLIENT_ID = ?'
        );
        $now = time();
        $st->execute([$now, $now, $key, $clientId]);
    }
}
