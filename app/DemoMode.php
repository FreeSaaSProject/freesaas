<?php
declare(strict_types=1);

/**
 * Demo stand guards (single monorepo; enabled only when cfg says so).
 *
 * Default off: absent / 0 / false / off / no → production behaviour unchanged.
 * When on: block unsafe HTTP mutations; optional auto-login.
 * Module absent-key policy is always fail-closed (see Config::moduleEnabled).
 */
final class DemoMode
{
    /** Paths allowed to POST/PUT/PATCH/DELETE while demo_readonly is on. */
    private const MUTATION_ALLOW = [
        '/login',
        '/logout',
        '/locale',
        // Token-auth ops (agent_token / cron_token) — shared-hosting bootstrap + /cron/tick.
        '/cron/tick',
        '/cron/status',
    ];

    public static function readonly(): bool
    {
        if (!class_exists('Config', false)) {
            return false;
        }
        return self::flag((string)Config::get('app', 'demo_readonly', '0'));
    }

    public static function autoLogin(): bool
    {
        if (!self::readonly()) {
            return false;
        }
        return self::flag((string)Config::get('app', 'demo_auto_login', '0'));
    }

    public static function autoLoginUser(): string
    {
        $u = trim((string)Config::get('app', 'demo_auto_login_user', 'demo'));
        return $u !== '' ? $u : 'demo';
    }

    /**
     * @deprecated Absent [modules] keys are always off (Config::moduleEnabled). Kept for callers.
     */
    public static function missingModuleDefaultsOff(): bool
    {
        return true;
    }

    public static function mutationAllowed(string $path): bool
    {
        $path = '/' . ltrim($path, '/');
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        if (in_array($path, self::MUTATION_ALLOW, true)) {
            return true;
        }
        // Entire Surface A (/agent, /agent/…) — token gate, not session CSRF.
        return $path === '/agent' || strpos($path, '/agent/') === 0;
    }

    /**
     * Skip shared DB writes from HTTP GET (and similar) under demo_readonly.
     * Router already denies POST|PUT|PATCH|DELETE; this covers GET side effects
     * (e.g. news mark-as-read). CLI seed/provision still write while the flag is on.
     */
    public static function blocksHttpSideEffects(): bool
    {
        return self::readonly() && PHP_SAPI !== 'cli';
    }

    /**
     * Business "now" for seed + today-widgets. Uses [demo] frozen_at when
     * demo_readonly is on; otherwise real wall clock. Auth/Cron keep time().
     */
    public static function now(): int
    {
        $frozen = self::frozenAtTs();
        return $frozen !== null ? $frozen : time();
    }

    public static function isFrozen(): bool
    {
        return self::frozenAtTs() !== null;
    }

    /** Start of the business day (00:00:00) for DemoMode::now(). */
    public static function dayStart(): int
    {
        $ts = self::now();
        return (int)mktime(0, 0, 0, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
    }

    /** End of the business day (23:59:59) for DemoMode::now(). */
    public static function dayEnd(): int
    {
        $ts = self::now();
        return (int)mktime(23, 59, 59, (int)date('n', $ts), (int)date('j', $ts), (int)date('Y', $ts));
    }

    /**
     * Parsed [demo] frozen_at unix ts, or null when unset / not readonly / invalid.
     * Accepts "Y-m-d H:i:s", "Y-m-d H:i", or unix integer string.
     */
    public static function frozenAtTs(): ?int
    {
        if (!self::readonly() || !class_exists('Config', false)) {
            return null;
        }
        $raw = trim((string)Config::get('demo', 'frozen_at', ''));
        if ($raw === '' || $raw === '0') {
            return null;
        }
        if (ctype_digit($raw)) {
            $ts = (int)$raw;
            return $ts > 0 ? $ts : null;
        }
        $ts = strtotime($raw);
        return ($ts !== false && $ts > 0) ? $ts : null;
    }

    /**
     * If demo_auto_login and no session: establish demo user session.
     * No-op when flags off, CLI, already logged in, or user missing/disabled.
     */
    public static function maybeAutoLogin(): void
    {
        if (!self::autoLogin()) {
            return;
        }
        if (PHP_SAPI === 'cli') {
            return;
        }
        // Token surfaces must not inherit the guest session (breaks demo provision on /cron/tick).
        if (function_exists('request_path')) {
            $p = '/' . ltrim((string)request_path(), '/');
            if ($p === '/cron' || strpos($p, '/cron/') === 0
                || $p === '/agent' || strpos($p, '/agent/') === 0
            ) {
                return;
            }
        }
        if (!class_exists('Auth', false) || Auth::check(false)) {
            return;
        }
        if (!class_exists('UsersRepository', false) || !class_exists('Database', false)) {
            return;
        }
        try {
            if (!Database::tableExists('base_users')) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }
        $login = self::autoLoginUser();
        $row = UsersRepository::findByLogin($login);
        if ($row === null || (string)($row['enabled'] ?? '') !== '1') {
            return;
        }
        // establishSession expects password-login shaped row (id, login, name, status, …).
        Auth::establishSession($row, true);
    }

    /**
     * Block unsafe methods under demo_readonly (after auth/CSRF).
     * @return bool true if request was denied (caller must return)
     */
    public static function denyUnsafeIfReadonly(string $path, string $method, bool $wantJson): bool
    {
        if (!self::readonly()) {
            return false;
        }
        $method = strtoupper($method);
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        if (self::mutationAllowed($path)) {
            return false;
        }
        $msg = class_exists('I18n', false)
            ? t('app.demo.readonly_deny')
            : 'Demo is read-only';
        if ($wantJson && class_exists('AgentHttp', false)) {
            AgentHttp::error($msg, 403, ['error_code' => 'demo_readonly']);
            return true;
        }
        http_response_code(403);
        if (class_exists('View', false)) {
            View::render('error', [
                'title' => class_exists('I18n', false) ? t('app.demo.readonly_title') : 'Read-only',
                'message' => $msg,
                'hint' => '',
            ]);
        } else {
            echo $msg;
        }
        return true;
    }

    private static function flag(string $value): bool
    {
        $v = strtolower(trim($value));
        if ($v === '' || in_array($v, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }
        return true;
    }
}
