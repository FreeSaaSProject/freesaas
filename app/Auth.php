<?php
declare(strict_types=1);

final class Auth
{
    private const SESSION_NAME_PREFIX = 'freesaas';
    private const GC_MIN_SECONDS = 86400;
    private const REMEMBER_DAYS_DEFAULT = 7;
    private const IDLE_MINUTES_DEFAULT = 120;
    private const GUARD_SECONDS_DEFAULT = 60;
    private const REGEN_SECONDS = 1800;

    /** Nesting depth of Auth::runAs — skips idle/guard logout while impersonating. */
    private static int $runAsDepth = 0;

    /** Last Auth::check / runSessionGuard failure reason (empty when ok). */
    private static string $lastCheckFail = '';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!empty($_SESSION['user_id']) && !empty($_SESSION['remember'])) {
                self::touchSessionCookie();
            }
            return;
        }
        $name = self::sessionCookieName();
        session_name($name);
        // Drop legacy flat "freesaas" cookie so parent-domain leftovers cannot fight the host cookie.
        if ($name !== self::SESSION_NAME_PREFIX) {
            self::expireLegacySessionCookie();
        }
        // Own dir per host: shared-hosting /tmp GC and multi-vhost installs.
        // UpdateEngine never overwrites var/.
        self::ensureSessionSavePath();
        // GC must cover the longest cookie; remember flag is known only after start.
        self::applySessionIni(self::rememberLifetimeSeconds());
        session_set_cookie_params(self::cookieParams(0));
        session_start();
        if (!empty($_SESSION['user_id']) && !empty($_SESSION['remember'])) {
            self::touchSessionCookie();
        }
    }

    /**
     * Cookie name scoped to this install/host (e.g. freesaas_mf_likespa_ru).
     * Priority: app.session_name → slug(session_cookie_domain) → slug(HTTP_HOST) → freesaas.
     * Parent Domain cookies still share one name across admin/public of the same install.
     */
    public static function sessionCookieName(): string
    {
        if (class_exists('Config', false)) {
            $custom = strtolower(trim((string)Config::get('app', 'session_name', '')));
            if ($custom !== '' && preg_match('/^[a-z0-9_]{1,48}$/', $custom)) {
                return $custom;
            }
        }
        $slug = self::sessionScopeSlug();
        if ($slug === '') {
            return self::SESSION_NAME_PREFIX;
        }
        return self::SESSION_NAME_PREFIX . '_' . $slug;
    }

    /**
     * Scope for cookie name + session files: cookie Domain (if set) else HTTP_HOST.
     * Using Domain keeps admin_host + public_host on one install sharing a session.
     */
    private static function sessionScopeSlug(): string
    {
        $raw = '';
        if (class_exists('Config', false)) {
            $raw = trim((string)Config::get('app', 'session_cookie_domain', ''));
        }
        if ($raw === '') {
            $raw = (string)($_SERVER['HTTP_HOST'] ?? '');
        }
        return self::hostSlug($raw);
    }

    /** Sanitize host/domain to a-z0-9_ (max ~40 chars). */
    private static function hostSlug(string $host = ''): string
    {
        if ($host === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        }
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        if ($host[0] === '.') {
            $host = substr($host, 1);
        }
        if (strpos($host, ':') !== false) {
            $host = explode(':', $host, 2)[0];
        }
        $slug = preg_replace('/[^a-z0-9]+/', '_', $host) ?? '';
        $slug = trim($slug, '_');
        if ($slug === '') {
            return '';
        }
        if (strlen($slug) > 40) {
            $slug = substr($slug, 0, 24) . '_' . substr(hash('sha256', $host), 0, 8);
        }
        return $slug;
    }

    /** Expire pre-host-scoped session cookie (name "freesaas"). */
    private static function expireLegacySessionCookie(): void
    {
        if (!isset($_COOKIE[self::SESSION_NAME_PREFIX])) {
            return;
        }
        $params = self::cookieParams(0);
        $opts = [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ];
        if ($params['domain'] !== '') {
            $opts['domain'] = $params['domain'];
        }
        setcookie(self::SESSION_NAME_PREFIX, '', $opts);
        unset($_COOKIE[self::SESSION_NAME_PREFIX]);
    }

    /** Put PHP session files under var/sessions[/host] (isolated from system /tmp). */
    private static function ensureSessionSavePath(): void
    {
        if (!function_exists('base_path')) {
            return;
        }
        $slug = self::sessionScopeSlug();
        $dir = $slug !== ''
            ? base_path('var/sessions/' . $slug)
            : base_path('var/sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
    }

    /** @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string} */
    private static function cookieParams(int $lifetime): array
    {
        $domain = '';
        if (class_exists('Config', false)) {
            $raw = trim((string)Config::get('app', 'session_cookie_domain', ''));
            // Leading dot optional; reject hostnames with path/scheme.
            if ($raw !== '' && preg_match('/^\.?[a-z0-9.-]+$/i', $raw) && strpos($raw, '/') === false) {
                $domain = $raw;
            }
        }
        return [
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => $domain,
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $fwd === 'https';
    }

    private static function rememberDays(): int
    {
        $raw = class_exists('Config', false)
            ? (int)Config::get('app', 'session_remember_days', (string)self::REMEMBER_DAYS_DEFAULT)
            : self::REMEMBER_DAYS_DEFAULT;
        return max(1, min(365, $raw > 0 ? $raw : self::REMEMBER_DAYS_DEFAULT));
    }

    private static function rememberLifetimeSeconds(): int
    {
        return self::rememberDays() * 86400;
    }

    /** Idle timeout (seconds). 0 = disabled. */
    private static function idleSeconds(): int
    {
        $raw = class_exists('Config', false)
            ? (int)Config::get('app', 'session_idle_minutes', (string)self::IDLE_MINUTES_DEFAULT)
            : self::IDLE_MINUTES_DEFAULT;
        if ($raw < 0) {
            $raw = self::IDLE_MINUTES_DEFAULT;
        }
        // Cap at 7 days; 0 disables idle check.
        return max(0, min(7 * 86400, $raw * 60));
    }

    private static function guardIntervalSeconds(): int
    {
        $raw = class_exists('Config', false)
            ? (int)Config::get('app', 'session_guard_seconds', (string)self::GUARD_SECONDS_DEFAULT)
            : self::GUARD_SECONDS_DEFAULT;

        return max(5, min(600, $raw > 0 ? $raw : self::GUARD_SECONDS_DEFAULT));
    }

    private static function applySessionIni(int $lifetimeSeconds): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // File GC must cover remember cookie; idle is enforced in check().
        $gc = max(self::GC_MIN_SECONDS, $lifetimeSeconds);
        ini_set('session.gc_maxlifetime', (string)$gc);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
    }

    /** Sliding refresh of the session cookie when "remember me" is on. */
    public static function touchSessionCookie(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            return;
        }
        $lifetime = self::rememberLifetimeSeconds();
        $params = self::cookieParams($lifetime);
        setcookie(session_name(), session_id(), [
            'expires' => time() + $lifetime,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    private static function clearSessionCookie(): void
    {
        $params = self::cookieParams(0);
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }

    /** Why the last Auth::check() returned false (empty when the last check passed). */
    public static function lastCheckFail(): string
    {
        return self::$lastCheckFail;
    }

    /**
     * @param bool $forceGuard Skip throttle (unsafe methods / capability routes).
     */
    public static function check(bool $forceGuard = false): bool
    {
        self::$lastCheckFail = '';
        if (empty($_SESSION['user_id'])) {
            self::$lastCheckFail = 'no_session';

            return false;
        }
        // Bot / agent impersonation just loaded the user from DB — do not idle-logout
        // or re-run session guard (auth_epoch / cookie regen) mid-callback.
        if (self::$runAsDepth > 0) {
            return true;
        }
        $now = time();
        $idle = self::idleSeconds();
        if ($idle > 0) {
            $seen = (int)($_SESSION['_seen_at'] ?? 0);
            if ($seen > 0 && ($now - $seen) > $idle) {
                self::$lastCheckFail = 'idle';
                self::logout();

                return false;
            }
        }

        $guardAt = (int)($_SESSION['_guard_at'] ?? 0);
        $due = $forceGuard || ($now - $guardAt) >= self::guardIntervalSeconds();
        if ($due) {
            if (!self::runSessionGuard()) {
                return false;
            }
        }

        $_SESSION['_seen_at'] = $now;

        // Periodic session id rotation (fixation). Skip on mutating requests even when
        // Auth::can()/capability() call check(false) after Router's check(true) — otherwise
        // a mid-POST regenerate would race cookie/session state before Csrf::verify.
        // CSRF token is NOT rotated here (login/logout only) so multi-tab forms stay valid.
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $unsafeMethod = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if (!$forceGuard && !$unsafeMethod) {
            $regenAt = (int)($_SESSION['_regen_at'] ?? 0);
            if ($regenAt > 0 && ($now - $regenAt) >= self::REGEN_SECONDS) {
                // After output (CLI checks / early echo) regenerate_id cannot run — still bump stamp.
                if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                    session_regenerate_id(true);
                }
                $_SESSION['_regen_at'] = $now;
            }
        }

        if (empty($_SESSION['user_id'])) {
            self::$lastCheckFail = 'session_cleared';

            return false;
        }

        return true;
    }

    /**
     * Reload enabled / auth_epoch / permissions from DB. Returns false → logged out.
     */
    private static function runSessionGuard(): bool
    {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid < 1) {
            self::$lastCheckFail = 'no_session';
            self::logout();

            return false;
        }
        try {
            $row = self::fetchGuardRow($uid);
        } catch (Throwable $e) {
            // DB blip: keep session, retry next interval.
            error_log('[auth] session guard: ' . $e->getMessage());

            return true;
        }
        if ($row === null || (string)($row['enabled'] ?? '') !== '1') {
            self::$lastCheckFail = $row === null ? 'user_missing' : 'disabled';
            self::logout();

            return false;
        }
        $expire = (int)($row['expire'] ?? 0);
        if ($expire > 0 && $expire < time()) {
            self::$lastCheckFail = 'expired';
            self::logout();

            return false;
        }
        if (Database::columnExists('base_users', 'auth_epoch')) {
            $dbEpoch = (int)($row['auth_epoch'] ?? 0);
            $sessEpoch = (int)($_SESSION['auth_epoch'] ?? 0);
            if ($dbEpoch !== $sessEpoch) {
                self::$lastCheckFail = 'auth_epoch';
                self::logout();

                return false;
            }
            $_SESSION['auth_epoch'] = $dbEpoch;
        }

        // Soft refresh: revoked permissions / role flags apply without epoch bump.
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
        $_SESSION['permissions'] = $permissions;
        $_SESSION['login'] = (string)($row['login'] ?? $_SESSION['login'] ?? '');
        $_SESSION['user_name'] = (string)($row['name'] ?? $_SESSION['user_name'] ?? '');
        self::setStatusContext((int)($row['ID_STATUS'] ?? 0), (int)($row['ID_STATUS2'] ?? 0));
        $_SESSION['_guard_at'] = time();

        return true;
    }

    /** @return array<string,mixed>|null */
    public static function fetchGuardRow(int $userId): ?array
    {
        if ($userId < 1 || !Database::tableExists('base_users')) {
            return null;
        }
        $cols = 'id, login, name, ID_STATUS, status, enabled, expire';
        if (Database::columnExists('base_users', 'ID_STATUS2')) {
            $cols .= ', ID_STATUS2';
        }
        if (Database::columnExists('base_users', 'auth_epoch')) {
            $cols .= ', auth_epoch';
        }
        $st = Database::pdo()->prepare(
            "SELECT $cols FROM base_users WHERE id = ? LIMIT 1"
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Invalidate all live sessions for this user (auth_epoch++).
     * If the actor is that user, keep their current session by syncing the new epoch.
     */
    public static function bumpAuthEpoch(int $userId): int
    {
        if ($userId < 1 || !Database::tableExists('base_users')) {
            return 0;
        }
        if (!Database::columnExists('base_users', 'auth_epoch')) {
            return 0;
        }
        Database::pdo()->prepare(
            'UPDATE base_users SET auth_epoch = auth_epoch + 1 WHERE id = ?'
        )->execute([$userId]);
        $st = Database::pdo()->prepare('SELECT auth_epoch FROM base_users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $epoch = (int)$st->fetchColumn();
        if (session_status() === PHP_SESSION_ACTIVE && self::userId() === $userId) {
            $_SESSION['auth_epoch'] = $epoch;
            $_SESSION['_guard_at'] = time();
        }

        return $epoch;
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
            $fetched = self::mergedStatusFlags($sid, self::statusId2());
            $_SESSION['status_flags'] = $fetched;
            return $fetched;
        }
        return is_array($f) ? $f : [];
    }

    /** Load role flags into session: primary status widened by secondary (ID_STATUS2). */
    public static function setStatusContext(int $statusId, int $statusId2 = 0): void
    {
        $_SESSION['status_id'] = $statusId;
        $_SESSION['status_id2'] = $statusId2;
        $_SESSION['status_flags'] = self::mergedStatusFlags($statusId, $statusId2);
    }

    /**
     * Secondary status only grants: a flag set there is added to the primary set.
     * Never grants SUPERADMIN_ACCESS. Restriction flags lift only when both statuses lack them
     * (EVT_MY_ONLY); other restrictions, ENABLED and ID_* columns stay from the primary status.
     *
     * @return array<string,int>
     */
    public static function mergedStatusFlags(int $statusId, int $statusId2 = 0): array
    {
        $flags = self::fetchStatusFlags($statusId);
        if ($statusId2 < 1 || $statusId2 === $statusId || $flags === []) {
            return $flags;
        }
        $extra = self::fetchStatusFlags($statusId2);
        if ($extra === []) {
            return $flags;
        }
        $primaryOnly = ['SUPERADMIN_ACCESS', 'ENABLED', 'FASTAUTH_WORKTIME_ONLY', 'DISABLE_NON_PROCEDURE_TIME', 'PAY_DAILY'];
        foreach ($extra as $key => $value) {
            if (in_array($key, $primaryOnly, true) || strncmp($key, 'ID_', 3) === 0) {
                continue;
            }
            if ($key === 'EVT_MY_ONLY') {
                $flags[$key] = min((int)($flags[$key] ?? 0), $value);
                continue;
            }
            if ($value > 0 && (int)($flags[$key] ?? 0) < 1) {
                $flags[$key] = $value;
            }
        }
        return $flags;
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
     * Why can($permission) would fail — for bot/agent diagnostics (no side effects).
     * Call while the candidate session is still intact (e.g. inside Auth::runAs).
     *
     * @return array{user_id:int,login:string,check:bool,superadmin:bool,personal:bool,why:string}
     */
    public static function accessProbe(string $permission): array
    {
        $uid = self::userId();
        $login = self::loginName();
        $fail = self::$lastCheckFail;
        $checkOk = $uid > 0 && (self::$runAsDepth > 0 || $fail === '');
        if ($uid > 0 && self::$runAsDepth === 0 && $fail === '') {
            // Do not re-enter check() here (would logout again); trust caller state.
            $checkOk = true;
        }
        $super = $uid > 0 && self::isSuperAdmin();
        $personal = $uid > 0 && self::hasPersonalPermission($permission);
        $why = 'ok';
        if ($uid < 1) {
            $why = $fail !== '' ? 'check_' . $fail : 'no_session';
        } elseif ($fail !== '') {
            $why = 'check_' . $fail;
        } elseif (!$super && !$personal) {
            $why = 'no_perm';
        }
        return [
            'user_id' => $uid,
            'login' => $login,
            'check' => $checkOk,
            'superadmin' => $super,
            'personal' => $personal,
            'why' => $why,
            'check_fail' => $fail,
        ];
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

    /**
     * True when the session blob already has FreeSaaS screen permissions (`module.perm`).
     * Shape check only — any key with a bare "." (e.g. `api.php`) must not count.
     */
    public static function hasNamespacedPermissions(): bool
    {
        foreach (self::permissions() as $k => $_) {
            if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', (string)$k) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Failed attempts before temporary lockout. */
    public const LOCK_THRESHOLD = 5;

    /** Lockout duration in minutes. */
    public const LOCK_MINUTES = 15;

    /** Minimum password length for ordinary staff. */
    public const PASSWORD_MIN = 8;

    /** Minimum password length for SUPERADMIN_ACCESS roles. */
    public const PASSWORD_MIN_SUPER = 10;

    /**
     * Preferred password_hash algorithm (argon2id when available).
     *
     * @return string|int
     */
    public static function preferredAlgo()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /**
     * Legacy FreeSaaS / oper hash: sha1(md5(plain . salts.site)).
     * Read-only — never write new passwords with this.
     * Do not rotate salts.site: it is required to verify remaining legacy rows.
     */
    public static function legacyHash(string $plain): string
    {
        $salt = (string)Config::get('salts', 'site', '');

        return sha1(md5($plain . $salt));
    }

    public static function isLegacyHash(string $stored): bool
    {
        return (bool)preg_match('/^[0-9a-f]{40}$/i', $stored);
    }

    /** Write path: modern password_hash (argon2id or bcrypt). */
    public static function hashPassword(string $plain): string
    {
        $hash = password_hash($plain, self::preferredAlgo());
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('password_hash failed');
        }

        return $hash;
    }

    public static function verifyPassword(string $plain, string $stored): bool
    {
        $stored = (string)$stored;
        if ($stored === '') {
            return false;
        }
        if (self::isLegacyHash($stored)) {
            return hash_equals(strtolower($stored), self::legacyHash($plain));
        }

        return password_verify($plain, $stored);
    }

    public static function needsRehash(string $stored): bool
    {
        if (self::isLegacyHash($stored)) {
            return true;
        }

        return password_needs_rehash($stored, self::preferredAlgo());
    }

    /**
     * Server-side password policy. Returns null when OK, else i18n key suffix
     * under core.password.policy.* (too_short / too_short_super).
     */
    public static function passwordPolicyError(string $plain, bool $requireStrong = false): ?string
    {
        $min = $requireStrong ? self::PASSWORD_MIN_SUPER : self::PASSWORD_MIN;
        if (strlen($plain) < $min) {
            return $requireStrong ? 'too_short_super' : 'too_short';
        }

        return null;
    }

    /**
     * Dummy hash using preferredAlgo() so missing-login timing matches
     * modern wrong-password verifies (not a fixed bcrypt string).
     */
    private static function dummyPasswordHash(): string
    {
        static $hash = null;
        if (!is_string($hash) || $hash === '') {
            $hash = password_hash("\0freesaas-dummy-never-match", self::preferredAlgo());
            if (!is_string($hash) || $hash === '') {
                $hash = password_hash("\0freesaas-dummy-never-match", PASSWORD_BCRYPT);
            }
        }

        return is_string($hash) ? $hash : '';
    }

    /**
     * @return 'ok'|'bad_credentials'|'locked'|'expired'|'disabled'
     */
    public static function attemptStatus(string $login, string $password, bool $remember = true): string
    {
        if (!Database::tableExists('base_users')) {
            return 'bad_credentials';
        }
        $login = trim($login);
        $password = trim($password);
        if ($login === '' || $password === '') {
            return 'bad_credentials';
        }

        $cols = 'id, login, password, name, ID_STATUS, status, enabled, expire, retries';
        if (Database::columnExists('base_users', 'ID_STATUS2')) {
            $cols .= ', ID_STATUS2';
        }
        if (Database::columnExists('base_users', 'locale')) {
            $cols .= ', locale';
        }
        if (Database::columnExists('base_users', 'locked_until')) {
            $cols .= ', locked_until';
        }
        if (Database::columnExists('base_users', 'auth_epoch')) {
            $cols .= ', auth_epoch';
        }
        $st = Database::pdo()->prepare(
            "SELECT $cols FROM base_users WHERE login = ? LIMIT 1"
        );
        $st->execute([$login]);
        $row = $st->fetch();

        // Always verify (dummy hash when login missing) before revealing account state.
        $dummy = self::dummyPasswordHash();
        $stored = is_array($row) ? (string)$row['password'] : $dummy;
        if ($stored === '') {
            $stored = $dummy;
        }
        $passwordOk = self::verifyPassword($password, $stored);

        if (!is_array($row) || !$passwordOk) {
            if (is_array($row) && !$passwordOk && !self::isLockedRow($row)) {
                self::recordFailedLogin((int)$row['id']);
            }

            return 'bad_credentials';
        }

        // Password correct — safe to explain why login cannot proceed.
        if ((string)$row['enabled'] !== '1') {
            return 'disabled';
        }
        if (self::isLockedRow($row)) {
            return 'locked';
        }
        $expire = (int)$row['expire'];
        if ($expire > 0 && $expire < time()) {
            return 'expired';
        }

        self::clearLoginFailures((int)$row['id']);

        if (self::needsRehash((string)$row['password'])) {
            try {
                if (class_exists('UsersRepository', false)) {
                    UsersRepository::updatePasswordHash((int)$row['id'], self::hashPassword($password));
                } else {
                    $upd = Database::pdo()->prepare('UPDATE base_users SET password = ? WHERE id = ?');
                    $upd->execute([self::hashPassword($password), (int)$row['id']]);
                }
            } catch (Throwable $e) {
                // Login still succeeds; rehash can retry next time.
                error_log('[auth] rehash failed user=' . (int)$row['id'] . ' ' . $e->getMessage());
            }
        }

        self::establishSession($row, $remember);

        return 'ok';
    }

    public static function attempt(string $login, string $password, bool $remember = true): bool
    {
        return self::attemptStatus($login, $password, $remember) === 'ok';
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function isLockedRow(array $row): bool
    {
        if (!isset($row['locked_until']) || $row['locked_until'] === null || $row['locked_until'] === '') {
            return false;
        }
        $ts = strtotime((string)$row['locked_until']);

        return $ts !== false && $ts > time();
    }

    /** @return array{retries:int,locked_until:?string}|null */
    private static function fetchLockState(int $userId): ?array
    {
        if ($userId <= 0 || !Database::columnExists('base_users', 'locked_until')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT retries, locked_until FROM base_users WHERE id = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    private static function recordFailedLogin(int $userId): void
    {
        if ($userId <= 0 || !Database::tableExists('base_users')) {
            return;
        }
        $hasLock = Database::columnExists('base_users', 'locked_until');
        $hasLast = Database::columnExists('base_users', 'last_failed_at');
        $threshold = self::LOCK_THRESHOLD;
        $minutes = self::LOCK_MINUTES;
        if ($hasLock && $hasLast) {
            $sql = 'UPDATE base_users SET
                retries = LEAST(255, retries + 1),
                last_failed_at = NOW(),
                locked_until = IF(retries + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until)
                WHERE id = ?';
            Database::pdo()->prepare($sql)->execute([$threshold, $minutes, $userId]);
        } elseif ($hasLock) {
            $sql = 'UPDATE base_users SET
                retries = LEAST(255, retries + 1),
                locked_until = IF(retries + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until)
                WHERE id = ?';
            Database::pdo()->prepare($sql)->execute([$threshold, $minutes, $userId]);
        } else {
            Database::pdo()->prepare(
                'UPDATE base_users SET retries = LEAST(255, retries + 1) WHERE id = ?'
            )->execute([$userId]);
        }
    }

    private static function clearLoginFailures(int $userId): void
    {
        if ($userId <= 0 || !Database::tableExists('base_users')) {
            return;
        }
        $sets = ['retries = 0'];
        if (Database::columnExists('base_users', 'locked_until')) {
            $sets[] = 'locked_until = NULL';
        }
        if (Database::columnExists('base_users', 'last_failed_at')) {
            $sets[] = 'last_failed_at = NULL';
        }
        Database::pdo()->prepare(
            'UPDATE base_users SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute([$userId]);
    }

    /**
     * Establish session from a loaded user row (password login or one-time link).
     *
     * @param array<string,mixed> $row
     */
    public static function establishSession(array $row, bool $remember = true): void
    {
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

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['login'] = (string)$row['login'];
        $_SESSION['user_name'] = (string)$row['name'];
        $_SESSION['permissions'] = $permissions;
        $_SESSION['remember'] = $remember ? 1 : 0;
        $_SESSION['auth_epoch'] = Database::columnExists('base_users', 'auth_epoch')
            ? (int)($row['auth_epoch'] ?? 0)
            : 0;
        $now = time();
        $_SESSION['_seen_at'] = $now;
        $_SESSION['_guard_at'] = $now;
        $_SESSION['_regen_at'] = $now;
        self::setStatusContext((int)$row['ID_STATUS'], (int)($row['ID_STATUS2'] ?? 0));

        $userLocale = I18n::normalize(isset($row['locale']) ? (string)$row['locale'] : null);
        if ($userLocale !== null) {
            $_SESSION['locale'] = $userLocale;
            I18n::setLocale($userLocale);
            I18n::persistCookie($userLocale);
        }

        if ($remember) {
            self::touchSessionCookie();
        } else {
            $params = self::cookieParams(0);
            setcookie(session_name(), session_id(), [
                'expires' => 0,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        if (class_exists('Csrf', false)) {
            Csrf::rotate();
        }

        EventBus::emit('core.auth.after_login', [
            'user_id' => (int)$row['id'],
            'login' => (string)$row['login'],
        ]);
    }

    /** Clear lockout counters (CLI / admin). */
    public static function unlockUser(int $userId): bool
    {
        if ($userId <= 0 || !Database::tableExists('base_users')) {
            return false;
        }
        self::clearLoginFailures($userId);

        return true;
    }

    public static function logout(): void
    {
        $uid = self::userId();
        if ($uid > 0) {
            EventBus::emit('core.auth.before_logout', ['user_id' => $uid]);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies') && !headers_sent()) {
                self::clearSessionCookie();
            }
            session_destroy();
        }
    }

    /**
     * Temporarily assume another staff user for in-request work (e.g. bot harness).
     * Restores previous session identity afterwards. Does not emit login/logout events.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function runAs(int $userId, callable $fn)
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Auth::runAs requires user id');
        }
        self::startSession();

        $cols = 'id, login, name, ID_STATUS, status, enabled';
        if (Database::columnExists('base_users', 'ID_STATUS2')) {
            $cols .= ', ID_STATUS2';
        }
        if (Database::columnExists('base_users', 'locale')) {
            $cols .= ', locale';
        }
        if (Database::columnExists('base_users', 'auth_epoch')) {
            $cols .= ', auth_epoch';
        }
        $st = Database::pdo()->prepare(
            "SELECT $cols FROM base_users WHERE id = ? AND enabled = '1' LIMIT 1"
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Auth::runAs: user not found or disabled');
        }

        $permissions = [];
        $raw = $row['status'] ?? null;
        if (is_string($raw) && $raw !== '' && class_exists('UsersRepository', false)) {
            $permissions = UsersRepository::decodePermissions($raw);
        } elseif (is_string($raw) && $raw !== '') {
            $un = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($un) && isset($un['permissions']) && is_array($un['permissions'])) {
                foreach ($un['permissions'] as $k => $v) {
                    $permissions[(string)$k] = (int)$v;
                }
            }
        }

        // Include auth_epoch / activity stamps so Auth::can() → check() → session guard
        // does not treat a fresh runAs session as stale (auth_epoch 0 vs DB) and logout.
        $keys = [
            'user_id', 'login', 'user_name', 'permissions', 'status_id', 'status_id2',
            'status_flags', 'locale', 'auth_epoch', '_seen_at', '_guard_at',
        ];
        $backup = [];
        foreach ($keys as $k) {
            $backup[$k] = array_key_exists($k, $_SESSION) ? $_SESSION[$k] : null;
        }
        $hadLocale = array_key_exists('locale', $_SESSION);

        $now = time();
        $_SESSION['user_id'] = (int)$row['id'];
        $_SESSION['login'] = (string)$row['login'];
        $_SESSION['user_name'] = (string)$row['name'];
        $_SESSION['permissions'] = $permissions;
        $_SESSION['auth_epoch'] = Database::columnExists('base_users', 'auth_epoch')
            ? (int)($row['auth_epoch'] ?? 0)
            : 0;
        $_SESSION['_seen_at'] = $now;
        $_SESSION['_guard_at'] = $now;
        self::setStatusContext((int)$row['ID_STATUS'], (int)($row['ID_STATUS2'] ?? 0));
        if (isset($row['locale']) && class_exists('I18n', false)) {
            $userLocale = I18n::normalize((string)$row['locale']);
            if ($userLocale !== null) {
                $_SESSION['locale'] = $userLocale;
                I18n::setLocale($userLocale);
            }
        }

        self::$runAsDepth++;
        try {
            return $fn();
        } finally {
            self::$runAsDepth = max(0, self::$runAsDepth - 1);
            foreach ($keys as $k) {
                if ($backup[$k] === null && ($k !== 'locale' || !$hadLocale)) {
                    unset($_SESSION[$k]);
                } else {
                    $_SESSION[$k] = $backup[$k];
                }
            }
            if ($hadLocale && isset($backup['locale']) && is_string($backup['locale']) && class_exists('I18n', false)) {
                I18n::setLocale($backup['locale']);
            }
        }
    }
}
