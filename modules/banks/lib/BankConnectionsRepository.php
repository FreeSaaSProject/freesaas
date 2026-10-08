<?php
declare(strict_types=1);

final class BankConnectionsRepository
{
    public static function ready(): bool
    {
        return Database::tableExists('base_bank_connections');
    }

    /** @return list<array<string,mixed>> */
    public static function listAll(): array
    {
        if (!self::ready()) {
            return [];
        }
        $rows = Database::pdo()->query(
            'SELECT ID AS id, PROVIDER AS provider, STATUS AS status,
                    EXTERNAL_PARTY AS external_party, CONSENT_ID AS consent_id,
                    LABEL AS label, ID_USER AS user_id, EXPIRES_AT AS expires_at,
                    DT_CREATE AS dt_create, DT_UPDATE AS dt_update,
                    ACCESS_TOKEN AS access_token, REFRESH_TOKEN AS refresh_token, META AS meta
             FROM base_bank_connections
             ORDER BY DT_UPDATE DESC, ID DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([self::class, 'maskRow'], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id, bool $withSecrets = false): ?array
    {
        if ($id < 1 || !self::ready()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, PROVIDER AS provider, STATUS AS status,
                    ACCESS_TOKEN AS access_token, REFRESH_TOKEN AS refresh_token,
                    EXPIRES_AT AS expires_at, EXTERNAL_PARTY AS external_party,
                    CONSENT_ID AS consent_id, META AS meta, LABEL AS label,
                    ID_USER AS user_id, DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_bank_connections WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $withSecrets ? $row : self::maskRow($row);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $now = time();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_bank_connections
             (PROVIDER, STATUS, ACCESS_TOKEN, REFRESH_TOKEN, EXPIRES_AT,
              EXTERNAL_PARTY, CONSENT_ID, META, LABEL, ID_USER, DT_CREATE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            (string)$data['provider'],
            (string)($data['status'] ?? 'pending'),
            $data['access_token'] ?? null,
            $data['refresh_token'] ?? null,
            (int)($data['expires_at'] ?? 0),
            (string)($data['external_party'] ?? ''),
            (string)($data['consent_id'] ?? ''),
            isset($data['meta']) ? (is_string($data['meta']) ? $data['meta'] : json_encode($data['meta'], JSON_UNESCAPED_UNICODE)) : null,
            (string)($data['label'] ?? ''),
            (int)($data['user_id'] ?? 0),
            $now,
            $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        $map = [
            'status' => 'STATUS',
            'access_token' => 'ACCESS_TOKEN',
            'refresh_token' => 'REFRESH_TOKEN',
            'expires_at' => 'EXPIRES_AT',
            'external_party' => 'EXTERNAL_PARTY',
            'consent_id' => 'CONSENT_ID',
            'label' => 'LABEL',
        ];
        foreach ($map as $k => $col) {
            if (array_key_exists($k, $data)) {
                $fields[] = $col . ' = ?';
                $params[] = $data[$k];
            }
        }
        if (array_key_exists('meta', $data)) {
            $fields[] = 'META = ?';
            $params[] = is_string($data['meta']) ? $data['meta'] : json_encode($data['meta'], JSON_UNESCAPED_UNICODE);
        }
        if ($fields === []) {
            return;
        }
        $fields[] = 'DT_UPDATE = ?';
        $params[] = time();
        $params[] = $id;
        Database::pdo()->prepare(
            'UPDATE base_bank_connections SET ' . implode(', ', $fields) . ' WHERE ID = ?'
        )->execute($params);
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::ready()) {
            return;
        }
        Database::pdo()->prepare('DELETE FROM base_bank_connections WHERE ID = ?')->execute([$id]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function maskRow(array $row): array
    {
        $at = (string)($row['access_token'] ?? '');
        $rt = (string)($row['refresh_token'] ?? '');
        unset($row['access_token'], $row['refresh_token']);
        $row['has_access_token'] = $at !== '';
        $row['has_refresh_token'] = $rt !== '';
        $row['token_mask'] = $at !== '' ? (mb_substr($at, 0, 4) . '…' . mb_substr($at, -4)) : '';
        return $row;
    }
}
