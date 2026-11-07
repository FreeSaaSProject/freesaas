<?php
declare(strict_types=1);

final class CrmDealsRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_crm_deals');
    }

    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'd.id',
            'title' => 'd.title',
            'amount' => 'd.amount',
            'updated_at' => 'd.updated_at',
            'pipeline_id' => 'd.pipeline_id',
            'stage_id' => 'd.stage_id',
        ];
    }

    /**
     * @param array{q?:string,pipeline_id?:int,stage_id?:int,company_id?:int,client_id?:int,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['1=1'];
        $params = [];
        $join = '';
        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(d.title LIKE ? OR CAST(d.id AS CHAR) = ? OR d.source LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $q, $like);
        }
        $pipelineId = (int)($opts['pipeline_id'] ?? 0);
        if ($pipelineId > 0) {
            $where[] = 'd.pipeline_id = ?';
            $params[] = $pipelineId;
        }
        $stageId = (int)($opts['stage_id'] ?? 0);
        if ($stageId > 0) {
            $where[] = 'd.stage_id = ?';
            $params[] = $stageId;
        }
        $companyId = (int)($opts['company_id'] ?? 0);
        if ($companyId > 0) {
            $where[] = 'd.company_id = ?';
            $params[] = $companyId;
        }
        $clientId = (int)($opts['client_id'] ?? 0);
        if ($clientId > 0 && Database::tableExists('base_crm_deal_contacts')) {
            $join .= ' INNER JOIN base_crm_deal_contacts dct ON dct.deal_id = d.id AND dct.client_id = ?';
            array_unshift($params, $clientId);
        }
        $sqlWhere = implode(' AND ', $where);
        $from = "base_crm_deals d $join";
        $stc = $pdo->prepare("SELECT COUNT(DISTINCT d.id) FROM $from WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'd.updated_at DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $sql = "SELECT DISTINCT d.id, d.title, d.pipeline_id, d.stage_id, d.company_id, d.amount, d.currency,
                       d.responsible_user_id, d.source, d.closed_at, d.created_at, d.updated_at,
                       p.name AS pipeline_name, p.kind AS pipeline_kind,
                       s.name AS stage_name, s.semantic AS stage_semantic,
                       co.name AS company_name
                FROM $from
                LEFT JOIN base_crm_pipelines p ON p.id = d.pipeline_id
                LEFT JOIN base_crm_stages s ON s.id = d.stage_id
                LEFT JOIN base_crm_companies co ON co.id = d.company_id
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return ['items' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT d.id, d.title, d.pipeline_id, d.stage_id, d.company_id, d.amount, d.currency,
                    d.responsible_user_id, d.source, d.notes, d.closed_at, d.created_at, d.updated_at,
                    p.name AS pipeline_name, p.kind AS pipeline_kind,
                    s.name AS stage_name, s.code AS stage_code, s.semantic AS stage_semantic
             FROM base_crm_deals d
             LEFT JOIN base_crm_pipelines p ON p.id = d.pipeline_id
             LEFT JOIN base_crm_stages s ON s.id = d.stage_id
             WHERE d.id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array{client_id:int,is_primary:int,name?:string,tel?:string}> */
    public static function contactLinks(int $dealId): array
    {
        if ($dealId < 1 || !Database::tableExists('base_crm_deal_contacts')) {
            return [];
        }
        if (Database::tableExists('base_clients')) {
            $st = Database::pdo()->prepare(
                'SELECT dc.client_id, dc.is_primary, cl.name, cl.tel
                 FROM base_crm_deal_contacts dc
                 LEFT JOIN base_clients cl ON cl.id = dc.client_id
                 WHERE dc.deal_id = ?
                 ORDER BY dc.is_primary DESC, dc.client_id ASC'
            );
        } else {
            $st = Database::pdo()->prepare(
                'SELECT client_id, is_primary FROM base_crm_deal_contacts WHERE deal_id = ?'
            );
        }
        $st->execute([$dealId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $data
     * @param list<int> $clientIds
     */
    public static function create(array $data, array $clientIds = [], int $primaryClientId = 0): int
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException(t('crm.err.deal_title'));
        }
        $pipelineId = (int)($data['pipeline_id'] ?? 0);
        $stageId = (int)($data['stage_id'] ?? 0);
        if ($pipelineId < 1) {
            throw new InvalidArgumentException(t('crm.err.pipeline_required'));
        }
        if ($stageId < 1) {
            $stageId = CrmPipelinesRepository::firstStageId($pipelineId);
        }
        self::assertStageInPipeline($pipelineId, $stageId);
        $now = time();
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_crm_deals
             (title, pipeline_id, stage_id, company_id, amount, currency, responsible_user_id, source, notes, closed_at, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $title,
            $pipelineId,
            $stageId,
            (int)($data['company_id'] ?? 0),
            (float)($data['amount'] ?? 0),
            trim((string)($data['currency'] ?? 'RUB')) ?: 'RUB',
            (int)($data['responsible_user_id'] ?? 0),
            trim((string)($data['source'] ?? '')),
            (string)($data['notes'] ?? ''),
            (int)($data['closed_at'] ?? 0),
            $now,
            $now,
        ]);
        $id = (int)$pdo->lastInsertId();
        self::setContacts($id, $clientIds, $primaryClientId);
        self::recordStageHistory($id, 0, $stageId);
        $row = self::find($id);
        EventBus::emit('crm.deal.created', CrmSyncContext::withOrigin([
            'id' => $id,
            'row' => $row,
        ]));
        return $id;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<int>|null $clientIds
     */
    public static function update(int $id, array $data, ?array $clientIds = null, int $primaryClientId = 0): void
    {
        $prev = self::find($id);
        if ($prev === null) {
            throw new InvalidArgumentException('Invalid deal id');
        }
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException(t('crm.err.deal_title'));
        }
        $pipelineId = (int)($data['pipeline_id'] ?? $prev['pipeline_id']);
        $stageId = (int)($data['stage_id'] ?? $prev['stage_id']);
        if ($pipelineId < 1) {
            throw new InvalidArgumentException(t('crm.err.pipeline_required'));
        }
        self::assertStageInPipeline($pipelineId, $stageId);
        $fromStage = (int)$prev['stage_id'];
        $closedAt = (int)($data['closed_at'] ?? $prev['closed_at']);
        $stage = CrmPipelinesRepository::findStage($stageId);
        if ($stage !== null && in_array((string)$stage['semantic'], ['S', 'F'], true) && $closedAt < 1) {
            $closedAt = time();
        }
        if ($stage !== null && (string)$stage['semantic'] === 'P') {
            $closedAt = 0;
        }
        Database::pdo()->prepare(
            'UPDATE base_crm_deals SET
             title = ?, pipeline_id = ?, stage_id = ?, company_id = ?, amount = ?, currency = ?,
             responsible_user_id = ?, source = ?, notes = ?, closed_at = ?, updated_at = ?
             WHERE id = ?'
        )->execute([
            $title,
            $pipelineId,
            $stageId,
            (int)($data['company_id'] ?? 0),
            (float)($data['amount'] ?? 0),
            trim((string)($data['currency'] ?? 'RUB')) ?: 'RUB',
            (int)($data['responsible_user_id'] ?? 0),
            trim((string)($data['source'] ?? '')),
            (string)($data['notes'] ?? ''),
            $closedAt,
            time(),
            $id,
        ]);
        if ($clientIds !== null) {
            self::setContacts($id, $clientIds, $primaryClientId);
        }
        $row = self::find($id);
        EventBus::emit('crm.deal.updated', CrmSyncContext::withOrigin([
            'id' => $id,
            'row' => $row,
        ]));
        if ($fromStage !== $stageId) {
            self::recordStageHistory($id, $fromStage, $stageId);
            EventBus::emit('crm.deal.stage_changed', CrmSyncContext::withOrigin([
                'id' => $id,
                'row' => $row,
                'from_stage_id' => $fromStage,
                'to_stage_id' => $stageId,
            ]));
        }
    }

    public static function moveStage(int $id, int $stageId): void
    {
        $row = self::find($id);
        if ($row === null) {
            throw new InvalidArgumentException('Invalid deal id');
        }
        self::update($id, array_merge($row, ['stage_id' => $stageId]));
    }

    /** @param list<int> $clientIds */
    public static function setContacts(int $dealId, array $clientIds, int $primaryClientId = 0): void
    {
        if ($dealId < 1 || !Database::tableExists('base_crm_deal_contacts')) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_crm_deal_contacts WHERE deal_id = ?')->execute([$dealId]);
        $seen = [];
        foreach ($clientIds as $cid) {
            $cid = (int)$cid;
            if ($cid < 1 || isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $pdo->prepare(
                'INSERT INTO base_crm_deal_contacts (deal_id, client_id, is_primary) VALUES (?,?,?)'
            )->execute([$dealId, $cid, ($primaryClientId === $cid || ($primaryClientId < 1 && count($seen) === 1)) ? 1 : 0]);
        }
    }

    private static function assertStageInPipeline(int $pipelineId, int $stageId): void
    {
        $stage = CrmPipelinesRepository::findStage($stageId);
        if ($stage === null || (int)$stage['pipeline_id'] !== $pipelineId) {
            throw new InvalidArgumentException(t('crm.err.stage_pipeline_mismatch'));
        }
    }

    private static function recordStageHistory(int $dealId, int $from, int $to): void
    {
        if ($dealId < 1 || $to < 1 || !Database::tableExists('base_crm_stage_history')) {
            return;
        }
        $by = 0;
        if (class_exists('Auth', false) && method_exists('Auth', 'userId')) {
            $by = (int)Auth::userId();
        }
        Database::pdo()->prepare(
            'INSERT INTO base_crm_stage_history (deal_id, from_stage_id, to_stage_id, changed_at, changed_by)
             VALUES (?,?,?,?,?)'
        )->execute([$dealId, $from, $to, time(), $by]);
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $row = self::find($id);
        $pdo = Database::pdo();
        if (Database::tableExists('base_crm_deal_contacts')) {
            $pdo->prepare('DELETE FROM base_crm_deal_contacts WHERE deal_id = ?')->execute([$id]);
        }
        if (Database::tableExists('base_crm_stage_history')) {
            $pdo->prepare('DELETE FROM base_crm_stage_history WHERE deal_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM base_crm_deals WHERE id = ?')->execute([$id]);
        EventBus::emit('crm.deal.deleted', CrmSyncContext::withOrigin([
            'id' => $id,
            'row' => $row,
        ]));
    }

    /** @param list<int> $ids */
    public static function deleteByIds(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }
            self::delete($id);
            $n++;
        }
        return $n;
    }

    /** @return list<array<string,mixed>> */
    public static function stageHistory(int $dealId, int $limit = 50): array
    {
        if ($dealId < 1 || !Database::tableExists('base_crm_stage_history')) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $st = Database::pdo()->prepare(
            'SELECT h.id, h.deal_id, h.from_stage_id, h.to_stage_id, h.changed_at, h.changed_by,
                    fs.name AS from_name, ts.name AS to_name
             FROM base_crm_stage_history h
             LEFT JOIN base_crm_stages fs ON fs.id = h.from_stage_id
             LEFT JOIN base_crm_stages ts ON ts.id = h.to_stage_id
             WHERE h.deal_id = ?
             ORDER BY h.changed_at DESC, h.id DESC
             LIMIT ' . $limit
        );
        $st->execute([$dealId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
