<?php
declare(strict_types=1);

/** Authorization codes + access/refresh tokens. */
final class OAuthTokensRepository
{
    public const CODE_TTL = 600;
    public const ACCESS_TTL = 3600;
    public const REFRESH_TTL = 2592000; // 30d

    public static function codesReady(): bool
    {
        return Database::tableExists('base_oauth_codes');
    }

    public static function tokensReady(): bool
    {
        return Database::tableExists('base_oauth_tokens');
    }

    /**
     * @return array{code:string}|null
     */
    public static function createCode(
        string $clientId,
        int $userId,
        string $redirectUri,
        string $scope,
        string $codeChallenge,
        string $codeChallengeMethod
    ): ?array {
        if (!self::codesReady() || $userId < 1 || $clientId === '') {
            return null;
        }
        $code = bin2hex(random_bytes(32));
        $st = Database::pdo()->prepare(
            'INSERT INTO base_oauth_codes
             (CODE_HASH, CLIENT_ID, USER_ID, REDIRECT_URI, SCOPE, CODE_CHALLENGE, CODE_CHALLENGE_METHOD, EXPIRES_AT)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            OAuthServer::hashSecret($code),
            $clientId,
            $userId,
            $redirectUri,
            $scope,
            $codeChallenge,
            $codeChallengeMethod,
            time() + self::CODE_TTL,
        ]);
        return ['code' => $code];
    }

    /** @return array<string,mixed>|null */
    public static function consumeCode(string $code): ?array
    {
        if (!self::codesReady() || $code === '') {
            return null;
        }
        $hash = OAuthServer::hashSecret($code);
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_oauth_codes WHERE CODE_HASH = ? LIMIT 1'
        );
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ((int)($row['USED_AT'] ?? 0) > 0) {
            return null;
        }
        if ((int)($row['EXPIRES_AT'] ?? 0) < time()) {
            return null;
        }
        $upd = Database::pdo()->prepare(
            'UPDATE base_oauth_codes SET USED_AT = ? WHERE ID = ? AND USED_AT = 0'
        );
        $upd->execute([time(), (int)$row['ID']]);
        if ($upd->rowCount() < 1) {
            return null;
        }
        return [
            'client_id' => (string)$row['CLIENT_ID'],
            'user_id' => (int)$row['USER_ID'],
            'redirect_uri' => (string)$row['REDIRECT_URI'],
            'scope' => (string)$row['SCOPE'],
            'code_challenge' => (string)$row['CODE_CHALLENGE'],
            'code_challenge_method' => (string)$row['CODE_CHALLENGE_METHOD'],
        ];
    }

    /**
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:string}|null
     */
    public static function issueTokens(string $clientId, int $userId, string $scope): ?array
    {
        if (!self::tokensReady() || $userId < 1) {
            return null;
        }
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_oauth_tokens
             (ACCESS_TOKEN_HASH, REFRESH_TOKEN_HASH, CLIENT_ID, USER_ID, SCOPE,
              ACCESS_EXPIRES_AT, REFRESH_EXPIRES_AT, CREATED_AT)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            OAuthServer::hashSecret($access),
            OAuthServer::hashSecret($refresh),
            $clientId,
            $userId,
            $scope,
            $now + self::ACCESS_TTL,
            $now + self::REFRESH_TTL,
            $now,
        ]);
        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => self::ACCESS_TTL,
            'scope' => $scope,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function findAccess(string $accessToken): ?array
    {
        if (!self::tokensReady() || $accessToken === '') {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_oauth_tokens WHERE ACCESS_TOKEN_HASH = ? LIMIT 1'
        );
        $st->execute([OAuthServer::hashSecret($accessToken)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ((int)($row['REVOKED_AT'] ?? 0) > 0) {
            return null;
        }
        if ((int)($row['ACCESS_EXPIRES_AT'] ?? 0) < time()) {
            return null;
        }
        return [
            'id' => (int)$row['ID'],
            'client_id' => (string)$row['CLIENT_ID'],
            'user_id' => (int)$row['USER_ID'],
            'scope' => (string)$row['SCOPE'],
            'access_expires_at' => (int)$row['ACCESS_EXPIRES_AT'],
        ];
    }

    /**
     * Rotate refresh token: lock row, verify client_id before any mutation, claim once (REVOKED_AT).
     *
     * @return array{access_token:string,refresh_token:string,expires_in:int,scope:string,client_id:string}|null
     */
    public static function refresh(string $refreshToken, string $clientId): ?array
    {
        if (!self::tokensReady() || $refreshToken === '' || $clientId === '') {
            return null;
        }
        $pdo = Database::pdo();
        $started = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $started = true;
        }
        try {
            $hash = OAuthServer::hashSecret($refreshToken);
            $st = $pdo->prepare(
                'SELECT * FROM base_oauth_tokens WHERE REFRESH_TOKEN_HASH = ? LIMIT 1 FOR UPDATE'
            );
            $st->execute([$hash]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                if ($started) {
                    $pdo->rollBack();
                }
                return null;
            }
            // Client must match before revoke/rotate (wrong client must not invalidate the grant).
            if (!hash_equals((string)$row['CLIENT_ID'], $clientId)) {
                if ($started) {
                    $pdo->rollBack();
                }
                return null;
            }
            if ((int)($row['REVOKED_AT'] ?? 0) > 0) {
                if ($started) {
                    $pdo->rollBack();
                }
                return null;
            }
            if ((int)($row['REFRESH_EXPIRES_AT'] ?? 0) < time()) {
                if ($started) {
                    $pdo->rollBack();
                }
                return null;
            }
            // Atomic one-shot claim — concurrent refresh of the same token loses here.
            $upd = $pdo->prepare(
                'UPDATE base_oauth_tokens SET REVOKED_AT = ? WHERE ID = ? AND REVOKED_AT = 0'
            );
            $upd->execute([time(), (int)$row['ID']]);
            if ($upd->rowCount() < 1) {
                if ($started) {
                    $pdo->rollBack();
                }
                return null;
            }

            $tokens = self::issueTokens(
                $clientId,
                (int)$row['USER_ID'],
                (string)$row['SCOPE']
            );
            if ($tokens === null) {
                if ($started) {
                    $pdo->rollBack();
                }
                return null;
            }
            $tokens['client_id'] = $clientId;
            if ($started) {
                $pdo->commit();
            }
            return $tokens;
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function revokeAccess(string $token): bool
    {
        if (!self::tokensReady() || $token === '') {
            return false;
        }
        $hash = OAuthServer::hashSecret($token);
        $st = Database::pdo()->prepare(
            'UPDATE base_oauth_tokens SET REVOKED_AT = ?
             WHERE (ACCESS_TOKEN_HASH = ? OR REFRESH_TOKEN_HASH = ?) AND REVOKED_AT = 0'
        );
        $st->execute([time(), $hash, $hash]);
        return $st->rowCount() > 0;
    }

    public static function revokeByClientUser(string $clientId, int $userId): int
    {
        if (!self::tokensReady()) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_oauth_tokens SET REVOKED_AT = ?
             WHERE CLIENT_ID = ? AND USER_ID = ? AND REVOKED_AT = 0'
        );
        $st->execute([time(), $clientId, $userId]);
        return $st->rowCount();
    }
}
