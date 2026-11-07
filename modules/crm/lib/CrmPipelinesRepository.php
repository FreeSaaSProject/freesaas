<?php
declare(strict_types=1);

final class CrmPipelinesRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_crm_pipelines')
            && Database::tableExists('base_crm_stages');
    }

    /** @return list<array<string,mixed>> */
    public static function listPipelines(bool $enabledOnly = false): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $sql = 'SELECT id, name, kind, enabled, sort_order, created_at
                FROM base_crm_pipelines';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        return Database::pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findPipeline(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, name, kind, enabled, sort_order, created_at
             FROM base_crm_pipelines WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public static function listStages(int $pipelineId, bool $enabledOnly = false): array
    {
        if ($pipelineId < 1 || !self::tableReady()) {
            return [];
        }
        $sql = 'SELECT id, pipeline_id, name, code, semantic, sort_order, enabled
                FROM base_crm_stages WHERE pipeline_id = ?';
        if ($enabledOnly) {
            $sql .= ' AND enabled = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute([$pipelineId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function findStage(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, pipeline_id, name, code, semantic, sort_order, enabled
             FROM base_crm_stages WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function firstStageId(int $pipelineId): int
    {
        $stages = self::listStages($pipelineId, true);
        return $stages !== [] ? (int)$stages[0]['id'] : 0;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function createPipeline(array $data): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('crm.err.pipeline_name'));
        }
        $kind = (string)($data['kind'] ?? 'sale');
        if ($kind !== 'lead' && $kind !== 'sale') {
            $kind = 'sale';
        }
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_crm_pipelines (name, kind, enabled, sort_order, created_at)
             VALUES (?,?,?,?,?)'
        )->execute([
            $name,
            $kind,
            !empty($data['enabled']) ? 1 : 0,
            (int)($data['sort_order'] ?? 0),
            time(),
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function updatePipeline(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid pipeline id');
        }
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('crm.err.pipeline_name'));
        }
        $kind = (string)($data['kind'] ?? 'sale');
        if ($kind !== 'lead' && $kind !== 'sale') {
            $kind = 'sale';
        }
        Database::pdo()->prepare(
            'UPDATE base_crm_pipelines SET name = ?, kind = ?, enabled = ?, sort_order = ? WHERE id = ?'
        )->execute([
            $name,
            $kind,
            !empty($data['enabled']) ? 1 : 0,
            (int)($data['sort_order'] ?? 0),
            $id,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function createStage(array $data): int
    {
        $pipelineId = (int)($data['pipeline_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($pipelineId < 1 || $name === '' || $code === '') {
            throw new InvalidArgumentException(t('crm.err.stage_required'));
        }
        $semantic = strtoupper(substr((string)($data['semantic'] ?? 'P'), 0, 1));
        if (!in_array($semantic, ['P', 'S', 'F'], true)) {
            $semantic = 'P';
        }
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_crm_stages (pipeline_id, name, code, semantic, sort_order, enabled)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $pipelineId,
            $name,
            $code,
            $semantic,
            (int)($data['sort_order'] ?? 0),
            !empty($data['enabled']) ? 1 : 0,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function updateStage(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid stage id');
        }
        $name = trim((string)($data['name'] ?? ''));
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($name === '' || $code === '') {
            throw new InvalidArgumentException(t('crm.err.stage_required'));
        }
        $semantic = strtoupper(substr((string)($data['semantic'] ?? 'P'), 0, 1));
        if (!in_array($semantic, ['P', 'S', 'F'], true)) {
            $semantic = 'P';
        }
        Database::pdo()->prepare(
            'UPDATE base_crm_stages SET name = ?, code = ?, semantic = ?, sort_order = ?, enabled = ?
             WHERE id = ?'
        )->execute([
            $name,
            $code,
            $semantic,
            (int)($data['sort_order'] ?? 0),
            !empty($data['enabled']) ? 1 : 0,
            $id,
        ]);
    }

    /** @return array<int,string> */
    public static function pipelineNames(bool $enabledOnly = true): array
    {
        $out = [];
        foreach (self::listPipelines($enabledOnly) as $p) {
            $out[(int)$p['id']] = (string)$p['name'];
        }
        return $out;
    }
}
