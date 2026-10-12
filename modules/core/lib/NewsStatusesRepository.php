<?php
declare(strict_types=1);

/**
 * Ticket/news workflow statuses (base_news_statuses), not staff roles.
 */
final class NewsStatusesRepository
{
    /** @return list<string> */
    public static function deadlinePolicies(): array
    {
        return ['optional', 'required', 'forbidden'];
    }

    /** @return array<string,string> request sort key => SQL */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'name' => 'NAME',
            'type' => 'TYPE',
            'policy' => 'DEADLINE_POLICY',
        ];
    }

    /**
     * @param array{order?:string} $opts
     * @return list<array<string,mixed>>
     */
    public static function listAll(array $opts = []): array
    {
        if (!Database::tableExists('base_news_statuses')) {
            return [];
        }
        $order = trim((string)($opts['order'] ?? 'ID ASC'));
        if ($order === '' || !preg_match('/^[A-Z_]+(\s+(ASC|DESC))?$/i', $order)) {
            $order = 'ID ASC';
        }
        return Database::pdo()->query(
            'SELECT ID, NAME, TYPE, DEADLINE_POLICY, COLOR FROM base_news_statuses ORDER BY ' . $order
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_news_statuses')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, NAME, TYPE, DEADLINE_POLICY, COLOR FROM base_news_statuses WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{NAME?:string,TYPE?:int,DEADLINE_POLICY?:string,COLOR?:string,ID?:int} $data
     */
    public static function create(array $data): int
    {
        if (!Database::tableExists('base_news_statuses')) {
            throw new RuntimeException(t('core.news_statuses.err.no_table'));
        }
        $name = trim((string)($data['NAME'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('core.news_statuses.err.name_required'));
        }
        $type = max(0, (int)($data['TYPE'] ?? 2));
        $policy = self::normalizePolicy((string)($data['DEADLINE_POLICY'] ?? 'optional'));
        $color = self::normalizeColor((string)($data['COLOR'] ?? ''));
        $id = isset($data['ID']) ? (int)$data['ID'] : 0;

        if ($id > 0) {
            if (self::find($id)) {
                throw new InvalidArgumentException(t('core.news_statuses.err.id_taken', ['id' => $id]));
            }
            $st = Database::pdo()->prepare(
                'INSERT INTO base_news_statuses (ID, NAME, TYPE, DEADLINE_POLICY, COLOR) VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$id, mb_substr($name, 0, 50), $type, $policy, $color]);
            NewsRepository::flushStatusesCache();
            return $id;
        }

        $st = Database::pdo()->prepare(
            'INSERT INTO base_news_statuses (NAME, TYPE, DEADLINE_POLICY, COLOR) VALUES (?, ?, ?, ?)'
        );
        $st->execute([mb_substr($name, 0, 50), $type, $policy, $color]);
        NewsRepository::flushStatusesCache();
        return (int)Database::pdo()->lastInsertId();
    }

    /**
     * @param array{NAME?:string,TYPE?:int,DEADLINE_POLICY?:string,COLOR?:string} $data
     */
    public static function update(int $id, array $data): void
    {
        if ($id < 1 || !self::find($id)) {
            throw new InvalidArgumentException(t('core.news_statuses.err.not_found', ['id' => $id]));
        }
        $fields = [];
        $params = [];
        if (array_key_exists('NAME', $data)) {
            $name = trim((string)$data['NAME']);
            if ($name === '') {
                throw new InvalidArgumentException(t('core.news_statuses.err.name_required'));
            }
            $fields[] = 'NAME = ?';
            $params[] = mb_substr($name, 0, 50);
        }
        if (array_key_exists('TYPE', $data)) {
            $fields[] = 'TYPE = ?';
            $params[] = max(0, (int)$data['TYPE']);
        }
        if (array_key_exists('DEADLINE_POLICY', $data)) {
            $fields[] = 'DEADLINE_POLICY = ?';
            $params[] = self::normalizePolicy((string)$data['DEADLINE_POLICY']);
        }
        if (array_key_exists('COLOR', $data)) {
            $fields[] = 'COLOR = ?';
            $params[] = self::normalizeColor((string)$data['COLOR']);
        }
        if ($fields === []) {
            throw new InvalidArgumentException(t('core.news_statuses.err.no_fields'));
        }
        $params[] = $id;
        Database::pdo()->prepare(
            'UPDATE base_news_statuses SET ' . implode(', ', $fields) . ' WHERE ID = ?'
        )->execute($params);
        NewsRepository::flushStatusesCache();
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::find($id)) {
            throw new InvalidArgumentException(t('core.news_statuses.err.not_found', ['id' => $id]));
        }
        $used = self::usageCount($id);
        if ($used > 0) {
            throw new RuntimeException(t('core.news_statuses.err.in_use', ['n' => $used]));
        }
        Database::pdo()->prepare('DELETE FROM base_news_statuses WHERE ID = ?')->execute([$id]);
        NewsRepository::flushStatusesCache();
    }

    public static function usageCount(int $statusId): int
    {
        if ($statusId < 1 || !Database::tableExists('base_news')) {
            return 0;
        }
        $st = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM base_news WHERE STATUS = ? AND DISABLED = 0'
        );
        $st->execute([$statusId]);
        return (int)$st->fetchColumn();
    }

    /**
     * @param array<string,mixed> $post
     * @return array{NAME:string,TYPE:int,DEADLINE_POLICY:string,COLOR:string,ID?:int}
     */
    public static function dataFromPost(array $post): array
    {
        $out = [
            'NAME' => trim((string)($post['NAME'] ?? '')),
            'TYPE' => (int)($post['TYPE'] ?? 2),
            'DEADLINE_POLICY' => (string)($post['DEADLINE_POLICY'] ?? 'optional'),
            'COLOR' => (string)($post['COLOR'] ?? ''),
        ];
        if (isset($post['ID']) && (string)$post['ID'] !== '') {
            $out['ID'] = (int)$post['ID'];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public static function forAgent(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int)($row['ID'] ?? 0),
            'name' => (string)($row['NAME'] ?? ''),
            'type' => (int)($row['TYPE'] ?? 0),
            'deadline_policy' => (string)($row['DEADLINE_POLICY'] ?? 'optional'),
            'color' => (string)($row['COLOR'] ?? ''),
            'usage' => self::usageCount((int)($row['ID'] ?? 0)),
        ];
    }

    private static function normalizePolicy(string $p): string
    {
        $p = strtolower(trim($p));
        return in_array($p, self::deadlinePolicies(), true) ? $p : 'optional';
    }

    private static function normalizeColor(string $c): string
    {
        $c = trim($c);
        if ($c === '') {
            return '';
        }
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $c) || preg_match('/^[a-zA-Z]{3,20}$/', $c)) {
            return mb_substr($c, 0, 10);
        }
        return mb_substr($c, 0, 10);
    }
}
