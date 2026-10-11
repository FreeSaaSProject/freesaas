<?php
declare(strict_types=1);

/**
 * One-time staff login links (admin/CLI). Token stored as SHA-256 hex only.
 */
final class LoginLinksRepository
{
    public const DEFAULT_TTL_MINUTES = 15;

    /**
     * @return array{token:string,url:string,expires_at:string,id:int}
     */
    public static function create(int $userId, int $createdBy = 0, int $ttlMinutes = self::DEFAULT_TTL_MINUTES): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('user id required');
        }
        if (!Database::tableExists('base_user_login_links')) {
            throw new RuntimeException('base_user_login_links missing — run migrate');
        }
        $user = UsersRepository::find($userId);
        if ($user === null) {
            throw new RuntimeException('user not found');
        }
        if ((string)($user['enabled'] ?? '') !== '1') {
            throw new RuntimeException('user disabled');
        }

        $ttlMinutes = max(1, min(1440, $ttlMinutes));
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + $ttlMinutes * 60);
        $now = date('Y-m-d H:i:s');

        // Only one active link at a time.
        self::invalidateForUser($userId);

        $st = Database::pdo()->prepare(
            'INSERT INTO base_user_login_links
                (id_user, token_hash, expires_at, used_at, created_by, created_at)
             VALUES (?, ?, ?, NULL, ?, ?)'
        );
        $st->execute([$userId, $hash, $expires, max(0, $createdBy), $now]);
        $id = (int)Database::pdo()->lastInsertId();

        return [
            'id' => $id,
            'token' => $token,
            'url' => self::absoluteUrl($token),
            'expires_at' => $expires,
            'ttl_minutes' => $ttlMinutes,
            'login' => (string)($user['login'] ?? ''),
        ];
    }

    public static function invalidateForUser(int $userId): void
    {
        if ($userId < 1 || !Database::tableExists('base_user_login_links')) {
            return;
        }
        Database::pdo()->prepare(
            'UPDATE base_user_login_links SET used_at = COALESCE(used_at, NOW())
             WHERE id_user = ? AND used_at IS NULL'
        )->execute([$userId]);
    }

    /**
     * Consume token and return user row for session, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function consume(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[0-9a-f]{64}$/i', $token)) {
            return null;
        }
        if (!Database::tableExists('base_user_login_links') || !Database::tableExists('base_users')) {
            return null;
        }
        $hash = hash('sha256', strtolower($token));
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT id, id_user, expires_at, used_at FROM base_user_login_links
                 WHERE token_hash = ? LIMIT 1 FOR UPDATE'
            );
            $st->execute([$hash]);
            $link = $st->fetch(PDO::FETCH_ASSOC);
            if (!$link || $link['used_at'] !== null) {
                $pdo->rollBack();
                return null;
            }
            $exp = strtotime((string)$link['expires_at']);
            if ($exp === false || $exp < time()) {
                $pdo->prepare('UPDATE base_user_login_links SET used_at = NOW() WHERE id = ?')
                    ->execute([(int)$link['id']]);
                $pdo->commit();
                return null;
            }

            $cols = 'id, login, password, name, ID_STATUS, status, enabled, expire';
            if (Database::columnExists('base_users', 'ID_STATUS2')) {
                $cols .= ', ID_STATUS2';
            }
            if (Database::columnExists('base_users', 'locale')) {
                $cols .= ', locale';
            }
            if (Database::columnExists('base_users', 'auth_epoch')) {
                $cols .= ', auth_epoch';
            }
            $ust = $pdo->prepare("SELECT $cols FROM base_users WHERE id = ? LIMIT 1");
            $ust->execute([(int)$link['id_user']]);
            $user = $ust->fetch(PDO::FETCH_ASSOC);
            if (!$user || (string)($user['enabled'] ?? '') !== '1') {
                $pdo->prepare('UPDATE base_user_login_links SET used_at = NOW() WHERE id = ?')
                    ->execute([(int)$link['id']]);
                $pdo->commit();
                return null;
            }
            $expire = (int)($user['expire'] ?? 0);
            if ($expire > 0 && $expire < time()) {
                $pdo->prepare('UPDATE base_user_login_links SET used_at = NOW() WHERE id = ?')
                    ->execute([(int)$link['id']]);
                $pdo->commit();
                return null;
            }

            $pdo->prepare('UPDATE base_user_login_links SET used_at = NOW() WHERE id = ?')
                ->execute([(int)$link['id']]);
            // Invalidate siblings.
            $pdo->prepare(
                'UPDATE base_user_login_links SET used_at = COALESCE(used_at, NOW())
                 WHERE id_user = ? AND id <> ? AND used_at IS NULL'
            )->execute([(int)$link['id_user'], (int)$link['id']]);

            $pdo->commit();

            return $user;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function absoluteUrl(string $token): string
    {
        $fromConfig = false;
        $host = '';
        if (class_exists('Config', false)) {
            $host = trim((string)Config::get('app', 'admin_host', ''));
            if ($host === '') {
                $host = trim((string)Config::get('app', 'public_host', ''));
            }
            $fromConfig = $host !== '';
        }
        $host = preg_replace('#^https?://#i', '', $host) ?? '';
        $host = rtrim($host, '/');
        if ($host === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        }
        if ($host === '') {
            return '/login/t/' . $token;
        }
        $https = $fromConfig
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
        $scheme = $https ? 'https' : 'http';

        return $scheme . '://' . $host . '/login/t/' . $token;
    }
}

