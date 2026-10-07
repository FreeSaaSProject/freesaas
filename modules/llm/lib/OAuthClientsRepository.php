<?php
declare(strict_types=1);

/** OAuth2 client registry for MCP / GPT Actions. */
final class OAuthClientsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_oauth_clients');
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->query(
            'SELECT * FROM base_oauth_clients ORDER BY ID DESC'
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = self::hydrate($row);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function findByClientId(string $clientId): ?array
    {
        $clientId = trim($clientId);
        if ($clientId === '' || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_oauth_clients WHERE CLIENT_ID = ? LIMIT 1'
        );
        $st->execute([$clientId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT * FROM base_oauth_clients WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param list<string> $redirectUris
     * @return array{ok:bool,id:int,client_id:string,client_secret:string,error:string}
     */
    public static function create(string $name, array $redirectUris, string $scopes, int $createdBy): array
    {
        if (!self::tableReady()) {
            return ['ok' => false, 'id' => 0, 'client_id' => '', 'client_secret' => '', 'error' => 'oauth tables missing'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'id' => 0, 'client_id' => '', 'client_secret' => '', 'error' => t('llm.oauth.err.name')];
        }
        $uris = self::normalizeUris($redirectUris);
        if ($uris === []) {
            return ['ok' => false, 'id' => 0, 'client_id' => '', 'client_secret' => '', 'error' => t('llm.oauth.err.redirect')];
        }
        $scopes = OAuthServer::normalizeScopes($scopes);
        $clientId = 'fs_' . bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(24));
        $st = Database::pdo()->prepare(
            'INSERT INTO base_oauth_clients
             (CLIENT_ID, CLIENT_SECRET_HASH, NAME, REDIRECT_URIS, SCOPES, ENABLED, CREATED_AT, CREATED_BY)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
        );
        $st->execute([
            $clientId,
            OAuthServer::hashSecret($secret),
            $name,
            json_encode($uris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $scopes,
            time(),
            max(0, $createdBy),
        ]);
        return [
            'ok' => true,
            'id' => (int)Database::pdo()->lastInsertId(),
            'client_id' => $clientId,
            'client_secret' => $secret,
            'error' => '',
        ];
    }

    /**
     * Upsert CIMD / DCR external client. Empty secret = public client (token auth none).
     *
     * @param list<string> $redirectUris
     * @return array{ok:bool,id:int,client_id:string,client_secret:string,error:string}
     */
    public static function upsertExternal(
        string $clientId,
        string $name,
        array $redirectUris,
        string $scopes,
        bool $public,
        string $plainSecret = ''
    ): array {
        if (!self::tableReady()) {
            return ['ok' => false, 'id' => 0, 'client_id' => '', 'client_secret' => '', 'error' => 'oauth tables missing'];
        }
        $clientId = trim($clientId);
        $name = trim($name);
        if ($clientId === '' || $name === '') {
            return ['ok' => false, 'id' => 0, 'client_id' => '', 'client_secret' => '', 'error' => t('llm.oauth.err.name')];
        }
        $uris = self::normalizeUris($redirectUris);
        if ($uris === []) {
            return ['ok' => false, 'id' => 0, 'client_id' => '', 'client_secret' => '', 'error' => t('llm.oauth.err.redirect')];
        }
        $scopes = OAuthServer::normalizeScopes($scopes);
        $existing = self::findByClientId($clientId);
        $secretHash = '';
        $secretOut = '';
        if (!$public) {
            if ($plainSecret !== '') {
                $secretOut = $plainSecret;
                $secretHash = OAuthServer::hashSecret($plainSecret);
            } elseif ($existing !== null) {
                $secretHash = (string)$existing['client_secret_hash'];
            } else {
                $secretOut = bin2hex(random_bytes(24));
                $secretHash = OAuthServer::hashSecret($secretOut);
            }
        }
        if ($existing !== null) {
            $st = Database::pdo()->prepare(
                'UPDATE base_oauth_clients
                 SET NAME = ?, REDIRECT_URIS = ?, SCOPES = ?, CLIENT_SECRET_HASH = ?, ENABLED = 1
                 WHERE ID = ?'
            );
            $st->execute([
                $name,
                json_encode($uris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $scopes,
                $secretHash,
                (int)$existing['id'],
            ]);
            return [
                'ok' => true,
                'id' => (int)$existing['id'],
                'client_id' => $clientId,
                'client_secret' => $secretOut,
                'error' => '',
            ];
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_oauth_clients
             (CLIENT_ID, CLIENT_SECRET_HASH, NAME, REDIRECT_URIS, SCOPES, ENABLED, CREATED_AT, CREATED_BY)
             VALUES (?, ?, ?, ?, ?, 1, ?, 0)'
        );
        $st->execute([
            $clientId,
            $secretHash,
            $name,
            json_encode($uris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $scopes,
            time(),
        ]);
        return [
            'ok' => true,
            'id' => (int)Database::pdo()->lastInsertId(),
            'client_id' => $clientId,
            'client_secret' => $secretOut,
            'error' => '',
        ];
    }

    /**
     * @param list<string> $redirectUris
     * @return array{ok:bool,error:string}
     */
    public static function update(int $id, string $name, array $redirectUris, string $scopes, bool $enabled): array
    {
        $row = self::find($id);
        if ($row === null) {
            return ['ok' => false, 'error' => t('llm.oauth.err.not_found')];
        }
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => t('llm.oauth.err.name')];
        }
        $uris = self::normalizeUris($redirectUris);
        if ($uris === []) {
            return ['ok' => false, 'error' => t('llm.oauth.err.redirect')];
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_oauth_clients
             SET NAME = ?, REDIRECT_URIS = ?, SCOPES = ?, ENABLED = ?
             WHERE ID = ?'
        );
        $st->execute([
            $name,
            json_encode($uris, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            OAuthServer::normalizeScopes($scopes),
            $enabled ? 1 : 0,
            $id,
        ]);
        return ['ok' => true, 'error' => ''];
    }

    /** @return array{ok:bool,client_secret:string,error:string} */
    public static function rotateSecret(int $id): array
    {
        $row = self::find($id);
        if ($row === null) {
            return ['ok' => false, 'client_secret' => '', 'error' => t('llm.oauth.err.not_found')];
        }
        $secret = bin2hex(random_bytes(24));
        $st = Database::pdo()->prepare(
            'UPDATE base_oauth_clients SET CLIENT_SECRET_HASH = ? WHERE ID = ?'
        );
        $st->execute([OAuthServer::hashSecret($secret), $id]);
        return ['ok' => true, 'client_secret' => $secret, 'error' => ''];
    }

    public static function delete(int $id): bool
    {
        if ($id < 1 || !self::tableReady()) {
            return false;
        }
        $st = Database::pdo()->prepare('DELETE FROM base_oauth_clients WHERE ID = ?');
        $st->execute([$id]);
        return $st->rowCount() > 0;
    }

    /**
     * @param list<string>|string $uris
     * @return list<string>
     */
    public static function normalizeUris($uris): array
    {
        if (is_string($uris)) {
            $uris = preg_split('/\r\n|\r|\n/', $uris) ?: [];
        }
        if (!is_array($uris)) {
            return [];
        }
        $out = [];
        foreach ($uris as $u) {
            $u = trim((string)$u);
            if ($u === '' || !preg_match('#^https?://#i', $u)) {
                continue;
            }
            $out[] = $u;
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $uris = [];
        $raw = (string)($row['REDIRECT_URIS'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $u) {
                    $uris[] = (string)$u;
                }
            }
        }
        $secretHash = (string)($row['CLIENT_SECRET_HASH'] ?? '');
        return [
            'id' => (int)($row['ID'] ?? 0),
            'client_id' => (string)($row['CLIENT_ID'] ?? ''),
            'client_secret_hash' => $secretHash,
            'public' => $secretHash === '',
            'name' => (string)($row['NAME'] ?? ''),
            'redirect_uris' => $uris,
            'scopes' => (string)($row['SCOPES'] ?? 'ops.read'),
            'enabled' => !empty($row['ENABLED']),
            'created_at' => (int)($row['CREATED_AT'] ?? 0),
            'created_by' => (int)($row['CREATED_BY'] ?? 0),
        ];
    }
}
