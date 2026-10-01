<?php
declare(strict_types=1);

final class ClientStatusesRepository
{
    /** @return array<string,string> request sort key => SQL expression */
    public static function listSortMap(): array
    {
        return [
            'id' => 'id',
            'name' => 'name',
            'discount' => 'discount',
            'price_tier' => 'price_tier',
            'bonus' => 'bonus',
            'enabled' => 'enabled',
        ];
    }

    /**
     * @param array{order?:string} $opts
     * @return list<array<string,mixed>>
     */
    public static function listAll(array $opts = []): array
    {
        if (!Database::tableExists('base_client_statuses')) {
            return [];
        }
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'enabled DESC, name ASC';
        }
        $st = Database::pdo()->query(
            'SELECT id, name, discount, price_tier, bonus, count_abs, enabled
             FROM base_client_statuses
             ORDER BY ' . $order
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<int,string> */
    public static function names(bool $enabledOnly = false): array
    {
        if (!Database::tableExists('base_client_statuses')) {
            return [];
        }
        $sql = 'SELECT id, name FROM base_client_statuses';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY name ASC';
        $out = [];
        foreach (Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_client_statuses')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, discount, price_tier, bonus, count_abs, enabled
             FROM base_client_statuses WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post): array
    {
        $name = trim((string)($post['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('clients.status.err.name_required'));
        }
        $tier = (int)($post['price_tier'] ?? 1);
        if ($tier < 1 || $tier > 5) {
            $tier = 1;
        }
        $bonusRaw = trim((string)($post['bonus'] ?? ''));
        $absRaw = trim((string)($post['count_abs'] ?? ''));

        return [
            'name' => $name,
            'discount' => (float)($post['discount'] ?? 0),
            'price_tier' => $tier,
            'bonus' => $bonusRaw === '' ? null : (float)$bonusRaw,
            'count_abs' => $absRaw === '' ? null : max(0, (int)$absRaw),
            'enabled' => !empty($post['enabled']) ? 1 : 0,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_client_statuses (name, discount, price_tier, bonus, count_abs, enabled)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['name'],
            $data['discount'],
            $data['price_tier'],
            $data['bonus'],
            $data['count_abs'],
            $data['enabled'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid status id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_client_statuses
             SET name = ?, discount = ?, price_tier = ?, bonus = ?, count_abs = ?, enabled = ?
             WHERE id = ?'
        );
        $st->execute([
            $data['name'],
            $data['discount'],
            $data['price_tier'],
            $data['bonus'],
            $data['count_abs'],
            $data['enabled'],
            $id,
        ]);
    }
}
