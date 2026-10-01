<?php
declare(strict_types=1);

final class ProcedureTypesRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'ID',
            'type' => 'TYPE',
            'subtype' => 'SUBTYPE',
            'bonus' => 'BONUS_PROCENT',
            'parallels' => 'PARALLELS',
        ];
    }

    /**
     * @param array{order?:string} $opts
     * @return list<array<string,mixed>>
     */
    public static function listAll(array $opts = []): array
    {
        if (!Database::tableExists('base_procedure_types')) {
            return [];
        }
        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'TYPE ASC';
        }
        $st = Database::pdo()->query(
            'SELECT ID AS id, TYPE AS type, SUBTYPE AS subtype, DESCR AS descr,
                    BONUS_PROCENT AS bonus_procent, MULTIWORK AS multiwork,
                    PHOTO_REQ AS photo_req, IS_MEDICAL AS is_medical,
                    PARALLELS AS parallels, BONUS_PROCENT_OUT AS bonus_procent_out,
                    SITE_AMO_SHOW AS site_amo_show, CONS_REQ_AMO AS cons_req_amo
             FROM base_procedure_types
             ORDER BY ' . $order
        );
        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /** @return array<int,string> */
    public static function names(): array
    {
        if (!Database::tableExists('base_procedure_types')) {
            return [];
        }
        $out = [];
        $rows = Database::pdo()->query(
            'SELECT ID, TYPE, DESCR FROM base_procedure_types ORDER BY TYPE ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $label = trim((string)$r['TYPE']);
            $descr = trim((string)($r['DESCR'] ?? ''));
            if ($descr !== '') {
                $label .= ' — ' . $descr;
            }
            $out[(int)$r['ID']] = $label !== '' ? $label : ('#' . (int)$r['ID']);
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_procedure_types')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, TYPE AS type, SUBTYPE AS subtype, DESCR AS descr,
                    BONUS_PROCENT AS bonus_procent, MULTIWORK AS multiwork,
                    PHOTO_REQ AS photo_req, IS_MEDICAL AS is_medical,
                    PARALLELS AS parallels, BONUS_PROCENT_OUT AS bonus_procent_out,
                    SITE_AMO_SHOW AS site_amo_show, CONS_REQ_AMO AS cons_req_amo
             FROM base_procedure_types WHERE ID = ? LIMIT 1'
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
        $type = trim((string)($post['type'] ?? ''));
        if ($type === '') {
            throw new InvalidArgumentException(t('procedures.type.err.name_required'));
        }
        return [
            'type' => mb_substr($type, 0, 255),
            'subtype' => max(0, (int)($post['subtype'] ?? 0)),
            'descr' => mb_substr(trim((string)($post['descr'] ?? '')), 0, 255),
            'bonus_procent' => (float)($post['bonus_procent'] ?? 0),
            'multiwork' => !empty($post['multiwork']) ? 1 : 0,
            'photo_req' => !empty($post['photo_req']) ? 1 : 0,
            'is_medical' => !empty($post['is_medical']) ? 1 : 0,
            'parallels' => max(0, (int)($post['parallels'] ?? 0)),
            'bonus_procent_out' => max(0.0, (float)($post['bonus_procent_out'] ?? 0)),
            'site_amo_show' => !empty($post['site_amo_show']) ? 1 : 0,
            'cons_req_amo' => max(0, (int)($post['cons_req_amo'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_procedure_types
             (TYPE, SUBTYPE, DESCR, BONUS_PROCENT, MULTIWORK, PHOTO_REQ, IS_MEDICAL,
              PARALLELS, BONUS_PROCENT_OUT, SITE_AMO_SHOW, CONS_REQ_AMO)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $data['type'],
            $data['subtype'],
            $data['descr'],
            $data['bonus_procent'],
            $data['multiwork'],
            $data['photo_req'],
            $data['is_medical'],
            $data['parallels'],
            $data['bonus_procent_out'],
            $data['site_amo_show'],
            $data['cons_req_amo'],
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid procedure type id');
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_procedure_types SET
             TYPE = ?, SUBTYPE = ?, DESCR = ?, BONUS_PROCENT = ?, MULTIWORK = ?,
             PHOTO_REQ = ?, IS_MEDICAL = ?, PARALLELS = ?, BONUS_PROCENT_OUT = ?,
             SITE_AMO_SHOW = ?, CONS_REQ_AMO = ?
             WHERE ID = ?'
        );
        $st->execute([
            $data['type'],
            $data['subtype'],
            $data['descr'],
            $data['bonus_procent'],
            $data['multiwork'],
            $data['photo_req'],
            $data['is_medical'],
            $data['parallels'],
            $data['bonus_procent_out'],
            $data['site_amo_show'],
            $data['cons_req_amo'],
            $id,
        ]);
    }

    /**
     * @param list<int> $ids
     * @return int deleted count
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_procedure_types')) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_procedure_types WHERE ID IN ($placeholders)");
        $st->execute($ids);
        return $st->rowCount();
    }
}
