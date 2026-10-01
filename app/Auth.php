<?php
declare(strict_types=1);

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('freesaas');
        session_start();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function userId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function loginName(): string
    {
        return (string)($_SESSION['login'] ?? '');
    }

    public static function displayName(): string
    {
        return (string)($_SESSION['user_name'] ?? self::loginName());
    }

    public static function statusId(): int
    {
        return (int)($_SESSION['status_id'] ?? 0);
    }

    public static function statusId2(): int
    {
        return (int)($_SESSION['status_id2'] ?? 0);
    }

    /** @return array<string,int> */
    public static function statusFlags(): array
    {
        $f = $_SESSION['status_flags'] ?? null;
        if (is_array($f) && $f !== []) {
            return $f;
        }
        // Lazy hydrate for sessions started before status_flags existed.
        $sid = self::statusId();
        if ($sid > 0) {
            $fetched = self::fetchStatusFlags($sid);
            $_SESSION['status_flags'] = $fetched;
            return $fetched;
        }
        return is_array($f) ? $f : [];
    }

    /** Load status row flags into session (primary role). */
    public static function setStatusContext(int $statusId, int $statusId2 = 0): void
    {
        $_SESSION['status_id'] = $statusId;
        $_SESSION['status_id2'] = $statusId2;
        $_SESSION['status_flags'] = self::fetchStatusFlags($statusId);
    }

    /** @return array<string,int> */
    public static function fetchStatusFlags(int $statusId): array
    {
        if ($statusId < 1 || !Database::tableExists('base_admin_statuses')) {
            return [];
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_admin_statuses WHERE ID = ? LIMIT 1');
        $st->execute([$statusId]);
        $row = $st->fetch();
        if (!$row) {
            return [];
        }
        $out = [];
        foreach ($row as $k => $v) {
            if (!is_string($k) || $k === 'ID' || $k === 'NAME') {
                continue;
            }
            if (is_numeric($v) || $v === null || $v === '') {
                $out[$k] = (int)$v;
            }
        }
        return $out;
    }

    public static function isSuperAdmin(): bool
    {
        $flags = self::statusFlags();
        if ($flags !== []) {
            return !empty($flags['SUPERADMIN_ACCESS']);
        }
        $sid = self::statusId();
        if ($sid < 1 || !Database::tableExists('base_admin_statuses')) {
            return false;
        }
        $fetched = self::fetchStatusFlags($sid);
        return !empty($fetched['SUPERADMIN_ACCESS']);
    }

    /**
     * Role capability from base_admin_statuses.
     * Superadmin always passes. Otherwise requires flag > 0 in session status_flags.
     */
    public static function capability(string $flag): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::isSuperAdmin()) {
            return true;
        }
        $flags = self::statusFlags();
        return !empty($flags[$flag]);
    }

    /** @return array<string,int> */
    public static function permissions(): array
    {
        $p = $_SESSION['permissions'] ?? [];
        return is_array($p) ? $p : [];
    }

    /**
     * Route access from personal settings blob (module.perm keys).
     * Superadmin always passes, even when personal module permissions are empty or off.
     */
    public static function can(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::isSuperAdmin()) {
            return true;
        }
        $perms = self::permissions();
        return !empty($perms[$permission]);
    }

    /**
     * True if personal blob grants this key (no superadmin bypass).
     * Used for menu filtering and “opened via superadmin” notices.
     */
    public static function hasPersonalPermission(string $permission): bool
    {
        return !empty(self::permissions()[$permission]);
    }

    /**
     * Sidebar / menu visibility. Unlike can(), respects personal checkboxes even for superadmin,
     * so high-privilege users can hide unused modules from the rail.
     * Until namespaced permissions are saved, superadmin sees all; others see nothing.
     */
    public static function canSeeInMenu(string $permission): bool
    {
        if (!self::check()) {
            return false;
        }
        if (self::hasNamespacedPermissions()) {
            return self::hasPersonalPermission($permission);
        }
        return self::isSuperAdmin();
    }

    /** True when personal settings already store any module.perm keys. */
    public static function hasNamespacedPermissions(): bool
    {
        foreach (self::permissions() as $k => $_) {
            if (strpos((string)$k, '.') !== false) {
                return true;
            }
        }
        return false;
    }

    public static function hashPassword(string $plain): string
    {
        $salt = (string)Config::get('salts', 'site', '');
        return sha1(md5($plain . $salt));
    }

    public static function attempt(string $login, string $password): bool
    {
        if (!Database::tableExists('base_users')) {
            return false;
        }
        $cols = 'id, login, password, name, name_enabled, status, enabled, expire';
        if (Database::columnExists('base_users', 'STATUS2')) {
            $cols .= ', STATUS2';
        }
        if (Database::columnExists('base_users', 'locale')) {
            $cols .= ', locale';
        }
        $st = Database::pdo()->prepare(
            "SELECT $cols FROM base_users WHERE login = ? AND enabled = '1' LIMIT 1"
        );
        $st->execute([trim($login)]);
        $row = $st->fetch();
        if (!$row) {
            return false;
        }
        $hash = self::hashPassword(trim($password));
        if (!hash_equals((string)$row['password'], $hash)) {
            return false;
        }
        $expire = (int)$row['expire'];
        if ($expire > 0 && $expire < time()) {
            return false;
        }

        $permissions = [];
        $raw = $row['status'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $un = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($un) && isset($un['permissions']) && is_array($un['permissions'])) {
                foreach ($un['permissions'] as $k => $v) {
                    $permissions[(string)$k] = (int)$v;
                }
            }
        }

        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['login'] = (string)$row['login'];
        $_SESSION['user_name'] = (string)$row['name'];
        $_SESSION['permissions'] = $permissions;
        self::setStatusContext((int)$row['name_enabled'], (int)($row['STATUS2'] ?? 0));

        $userLocale = I18n::normalize(isset($row['locale']) ? (string)$row['locale'] : null);
        if ($userLocale !== null) {
            $_SESSION['locale'] = $userLocale;
            I18n::setLocale($userLocale);
            I18n::persistCookie($userLocale);
        }

        EventBus::emit('core.auth.after_login', [
            'user_id' => (int)$row['id'],
            'login' => (string)$row['login'],
        ]);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }
}
