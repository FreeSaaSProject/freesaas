<?php
declare(strict_types=1);

final class TinvestAccountsRepository
{
    public static function tableExists(): bool
    {
        return Database::tableExists('base_tinvest_accounts');
    }

    /** @return list<array{id:int,fund_id:int,user_id:int,token:string,dt_create:int,user_create:int,dt_update:int,user_update:int}> */
    public static function all(): array
    {
        if (!self::tableExists()) {
            return [];
        }
        $rows = Database::pdo()->query(
            'SELECT ID AS id, ID_FUND AS fund_id, ID_USER AS user_id, TOKEN AS token,
                    DT_CREATE AS dt_create, ID_USER_CREATE AS user_create,
                    DT_UPDATE AS dt_update, ID_USER_UPDATE AS user_update
             FROM base_tinvest_accounts ORDER BY ID ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r['id'],
                'fund_id' => (int)$r['fund_id'],
                'user_id' => (int)$r['user_id'],
                'token' => (string)$r['token'],
                'dt_create' => (int)$r['dt_create'],
                'user_create' => (int)$r['user_create'],
                'dt_update' => (int)$r['dt_update'],
                'user_update' => (int)$r['user_update'],
            ];
        }

        return $out;
    }

    /** @return list<int> */
    public static function ids(): array
    {
        if (!self::tableExists()) {
            return [];
        }
        $ids = [];
        foreach (Database::pdo()->query('SELECT ID FROM base_tinvest_accounts ORDER BY ID ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $ids[] = (int)$id;
        }

        return $ids;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableExists()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_FUND AS fund_id, ID_USER AS user_id, TOKEN AS token,
                    DT_CREATE AS dt_create, ID_USER_CREATE AS user_create,
                    DT_UPDATE AS dt_update, ID_USER_UPDATE AS user_update
             FROM base_tinvest_accounts WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            return null;
        }

        return [
            'id' => (int)$r['id'],
            'fund_id' => (int)$r['fund_id'],
            'user_id' => (int)$r['user_id'],
            'token' => (string)$r['token'],
            'dt_create' => (int)$r['dt_create'],
            'user_create' => (int)$r['user_create'],
            'dt_update' => (int)$r['dt_update'],
            'user_update' => (int)$r['user_update'],
        ];
    }

    public static function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '—';
        }
        $tail = strlen($token) >= 4 ? substr($token, -4) : $token;

        return '••••' . $tail;
    }

    /**
     * Agent/JSON-safe row: never expose full Bearer token.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    public static function toAgentItem(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $out = $row;
        $token = (string)($row['token'] ?? '');
        unset($out['token']);
        $out['token_mask'] = self::maskToken($token);
        $out['has_token'] = $token !== '';

        return $out;
    }

    public static function label(array $row, ?array $fundNames = null): string
    {
        $fundId = (int)($row['fund_id'] ?? $row['ID_FUND'] ?? 0);
        if ($fundId > 0) {
            if ($fundNames === null && class_exists('FundsRepository', false) && ModuleManager::isEnabled('funds')) {
                $fundNames = FundsRepository::names();
            }
            if (is_array($fundNames) && isset($fundNames[$fundId]) && $fundNames[$fundId] !== '') {
                return ltrim((string)$fundNames[$fundId], '— ');
            }
        }
        $id = (int)($row['id'] ?? $row['ID'] ?? 0);

        return $id > 0 ? ('T-Invest #' . $id) : 'T-Invest';
    }

    /** @param array{fund_id:int,user_id:int,token:string} $data */
    public static function create(array $data): int
    {
        $token = trim((string)($data['token'] ?? ''));
        if ($token === '') {
            throw new InvalidArgumentException(t('liquid_assets.accounts.err.token'));
        }
        $now = time();
        $uid = Auth::userId();
        $st = Database::pdo()->prepare(
            'INSERT INTO base_tinvest_accounts
             (ID_FUND, ID_USER, TOKEN, DT_CREATE, ID_USER_CREATE, DT_UPDATE, ID_USER_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            max(0, (int)($data['fund_id'] ?? 0)),
            max(0, (int)($data['user_id'] ?? $uid)),
            $token,
            $now,
            $uid,
            0,
            0,
        ]);

        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array{fund_id:int,user_id:int,token?:string} $data */
    public static function update(int $id, array $data): void
    {
        $row = self::find($id);
        if (!$row) {
            throw new InvalidArgumentException(t('liquid_assets.accounts.err.not_found', ['id' => $id]));
        }
        $token = array_key_exists('token', $data) ? trim((string)$data['token']) : '';
        if ($token === '') {
            $token = $row['token'];
        }
        if ($token === '') {
            throw new InvalidArgumentException(t('liquid_assets.accounts.err.token'));
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_tinvest_accounts SET
             ID_FUND = ?, ID_USER = ?, TOKEN = ?,
             DT_UPDATE = ?, ID_USER_UPDATE = ?
             WHERE ID = ?'
        );
        $st->execute([
            max(0, (int)($data['fund_id'] ?? 0)),
            max(0, (int)($data['user_id'] ?? 0)),
            $token,
            time(),
            Auth::userId(),
            $id,
        ]);
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !self::tableExists()) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_tinvest_accounts WHERE ID IN ($ph)");
        $st->execute($ids);

        return $st->rowCount();
    }
}
