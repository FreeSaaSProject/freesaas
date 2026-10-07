<?php
declare(strict_types=1);

/**
 * LikeSpa procedure restores (oper show/edit_procedure_restores).
 * Table base_procedure_restores owned by procedures; UI + settlement live here.
 */
final class ProcedureRestoresRepository
{
    /** @return array<string,string> */
    public static function listSortMap(): array
    {
        return [
            'id' => 'r.ID',
            'created' => 'r.DT_CREATE',
            'summ' => 'r.SUMM',
            'procedure' => 'r.ID_PROCEDURE',
        ];
    }

    public static function tableReady(): bool
    {
        return Database::tableExists('base_procedure_restores')
            && Database::tableExists('base_procedures');
    }

    public static function candidateSinceTs(): int
    {
        $today = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y')) ?: 0;
        if (Auth::capability('SUPERADMIN_ACCESS')) {
            $days = LikespaConfig::restoresLookbackDays();
            return (int)(mktime(0, 0, 0, (int)date('n'), (int)date('j') - $days, (int)date('Y')) ?: $today);
        }
        return $today;
    }

    /**
     * @param array{q?:string,order?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::tableReady()) {
            return ['items' => [], 'total' => 0];
        }
        $pdo = Database::pdo();
        $where = ['r.ID > 0'];
        $params = [];

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(r.REASON LIKE ? OR CAST(r.ID AS CHAR) = ? OR CAST(r.ID_PROCEDURE AS CHAR) = ?)';
            $params[] = '%' . $q . '%';
            $params[] = $q;
            $params[] = $q;
        }

        $sqlWhere = implode(' AND ', $where);
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_procedure_restores r WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();

        $order = trim((string)($opts['order'] ?? ''));
        if ($order === '') {
            $order = 'r.ID DESC';
        }
        $limit = max(0, (int)($opts['limit'] ?? 50));
        $offset = max(0, (int)($opts['offset'] ?? 0));

        $joins = ' LEFT JOIN base_procedures p ON p.ID = r.ID_PROCEDURE';
        $extra = 'p.DTSTART AS procedure_dtstart, p.ID_GOOD AS id_good, p.ID_USER AS id_user,
                  p.ID_OPERATOR AS id_operator, p.ID_ADMIN AS id_admin,
                  NULL AS product_name, NULL AS client_name,
                  NULL AS operator_name, NULL AS admin_name,
                  NULL AS creator_name, NULL AS reviewer_name, NULL AS paid_name';
        if (Database::tableExists('base_products')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $extra = str_replace('NULL AS product_name', 'g.name AS product_name', $extra);
        }
        if (Database::tableExists('base_clients')) {
            $joins .= ' LEFT JOIN base_clients c ON c.id = p.ID_USER';
            $extra = str_replace('NULL AS client_name', 'c.name AS client_name', $extra);
        }
        if (Database::tableExists('base_users')) {
            $joins .= ' LEFT JOIN base_users uo ON uo.id = p.ID_OPERATOR';
            $joins .= ' LEFT JOIN base_users ua ON ua.id = p.ID_ADMIN';
            $joins .= ' LEFT JOIN base_users uc ON uc.id = r.ID_ADMIN_CREATE';
            $joins .= ' LEFT JOIN base_users ur ON ur.id = r.ID_OPERATOR_REVIEWED';
            $joins .= ' LEFT JOIN base_users up ON up.id = r.ID_OPERATOR_PAID';
            $extra = str_replace(
                'NULL AS operator_name, NULL AS admin_name,
                  NULL AS creator_name, NULL AS reviewer_name, NULL AS paid_name',
                'COALESCE(NULLIF(uo.name, \'\'), uo.login) AS operator_name,
                  COALESCE(NULLIF(ua.name, \'\'), ua.login) AS admin_name,
                  COALESCE(NULLIF(uc.name, \'\'), uc.login) AS creator_name,
                  COALESCE(NULLIF(ur.name, \'\'), ur.login) AS reviewer_name,
                  COALESCE(NULLIF(up.name, \'\'), up.login) AS paid_name',
                $extra
            );
        }

        $sql = "SELECT r.ID AS id, r.DT_CREATE AS dt_create, r.DT_REVIEW AS dt_review,
                       r.ID_PROCEDURE AS id_procedure, r.ID_ADMIN_CREATE AS id_admin_create,
                       r.ID_OPERATOR_PAID AS id_operator_paid, r.ID_OPERATOR_REVIEWED AS id_operator_reviewed,
                       r.REASON AS reason, r.SUMM AS summ,
                       $extra
                FROM base_procedure_restores r
                $joins
                WHERE $sqlWhere ORDER BY $order";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = self::castRow($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $pdo = Database::pdo();
        $joins = ' LEFT JOIN base_procedures p ON p.ID = r.ID_PROCEDURE';
        $extra = 'p.DTSTART AS procedure_dtstart, p.ID_GOOD AS id_good, p.ID_USER AS id_user,
                  p.ID_OPERATOR AS id_operator, p.ID_ADMIN AS id_admin,
                  NULL AS product_name, NULL AS client_name,
                  NULL AS operator_name, NULL AS admin_name,
                  NULL AS creator_name, NULL AS reviewer_name, NULL AS paid_name';
        if (Database::tableExists('base_products')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $extra = str_replace('NULL AS product_name', 'g.name AS product_name', $extra);
        }
        if (Database::tableExists('base_clients')) {
            $joins .= ' LEFT JOIN base_clients c ON c.id = p.ID_USER';
            $extra = str_replace('NULL AS client_name', 'c.name AS client_name', $extra);
        }
        if (Database::tableExists('base_users')) {
            $joins .= ' LEFT JOIN base_users uo ON uo.id = p.ID_OPERATOR';
            $joins .= ' LEFT JOIN base_users ua ON ua.id = p.ID_ADMIN';
            $joins .= ' LEFT JOIN base_users uc ON uc.id = r.ID_ADMIN_CREATE';
            $joins .= ' LEFT JOIN base_users ur ON ur.id = r.ID_OPERATOR_REVIEWED';
            $joins .= ' LEFT JOIN base_users up ON up.id = r.ID_OPERATOR_PAID';
            $extra = str_replace(
                'NULL AS operator_name, NULL AS admin_name,
                  NULL AS creator_name, NULL AS reviewer_name, NULL AS paid_name',
                'COALESCE(NULLIF(uo.name, \'\'), uo.login) AS operator_name,
                  COALESCE(NULLIF(ua.name, \'\'), ua.login) AS admin_name,
                  COALESCE(NULLIF(uc.name, \'\'), uc.login) AS creator_name,
                  COALESCE(NULLIF(ur.name, \'\'), ur.login) AS reviewer_name,
                  COALESCE(NULLIF(up.name, \'\'), up.login) AS paid_name',
                $extra
            );
        }
        $st = $pdo->prepare(
            "SELECT r.ID AS id, r.DT_CREATE AS dt_create, r.DT_REVIEW AS dt_review,
                    r.ID_PROCEDURE AS id_procedure, r.ID_ADMIN_CREATE AS id_admin_create,
                    r.ID_OPERATOR_PAID AS id_operator_paid, r.ID_OPERATOR_REVIEWED AS id_operator_reviewed,
                    r.REASON AS reason, r.SUMM AS summ,
                    $extra
             FROM base_procedure_restores r
             $joins
             WHERE r.ID = ?
             LIMIT 1"
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::castRow($row) : null;
    }

    /**
     * Cancelled procedures eligible for restore (ENABLED&lt;1, SUMM=0, SUMM_DEFAULT&gt;0, DTSTART window).
     *
     * @return list<array<string,mixed>>
     */
    public static function candidatesForRestore(int $keepProcedureId = 0): array
    {
        if (!Database::tableExists('base_procedures')) {
            return [];
        }
        $since = self::candidateSinceTs();
        $pdo = Database::pdo();
        $params = [$since];
        $keepSql = '';
        if ($keepProcedureId > 0) {
            $keepSql = ' OR p.ID = ?';
            $params[] = $keepProcedureId;
        }

        $joins = '';
        $extra = 'NULL AS product_name, NULL AS operator_name, NULL AS admin_name, 0 AS not_in_team';
        if (Database::tableExists('base_products')) {
            $joins .= ' LEFT JOIN base_products g ON g.id = p.ID_GOOD';
            $extra = str_replace('NULL AS product_name', 'g.name AS product_name', $extra);
        }
        if (Database::tableExists('base_users')) {
            $joins .= ' LEFT JOIN base_users uo ON uo.id = p.ID_OPERATOR';
            $joins .= ' LEFT JOIN base_users ua ON ua.id = p.ID_ADMIN';
            $extra = str_replace(
                'NULL AS operator_name, NULL AS admin_name, 0 AS not_in_team',
                'COALESCE(NULLIF(uo.name, \'\'), uo.login) AS operator_name,
                 COALESCE(NULLIF(ua.name, \'\'), ua.login) AS admin_name,
                 COALESCE(uo.NOT_IN_TEAM, 0) AS not_in_team',
                $extra
            );
        }

        $st = $pdo->prepare(
            "SELECT p.ID AS id, p.DTSTART AS dtstart, p.ID_GOOD AS id_good,
                    p.ID_OPERATOR AS id_operator, p.ID_ADMIN AS id_admin,
                    p.SUMM_DEFAULT AS summ_default, p.ENABLED AS enabled, p.SUMM AS summ,
                    $extra
             FROM base_procedures p
             $joins
             WHERE ((p.ENABLED < 1 AND p.SUMM = 0 AND p.SUMM_DEFAULT > 0 AND p.DTSTART > ?)
                    $keepSql)
             ORDER BY p.DTSTART DESC"
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            $payee = self::resolvePayeeFromRow($row);
            $label = 'ID: ' . $id
                . ' ' . date('d.m.Y H:i', (int)($row['dtstart'] ?? 0))
                . ' ' . trim((string)($row['product_name'] ?? ''))
                . ' С: ' . trim((string)($row['operator_name'] ?? ''))
                . ' А: ' . trim((string)($row['admin_name'] ?? ''))
                . ' ' . t('likespa.restores.candidate_price', ['sum' => (string)(int)($row['summ_default'] ?? 0)])
                . ' ' . t('likespa.restores.candidate_payee', ['name' => self::staffLabel($payee)]);
            $out[] = [
                'id' => $id,
                'dtstart' => (int)($row['dtstart'] ?? 0),
                'id_good' => (int)($row['id_good'] ?? 0),
                'id_operator' => (int)($row['id_operator'] ?? 0),
                'id_admin' => (int)($row['id_admin'] ?? 0),
                'summ_default' => (float)($row['summ_default'] ?? 0),
                'product_name' => (string)($row['product_name'] ?? ''),
                'operator_name' => (string)($row['operator_name'] ?? ''),
                'admin_name' => (string)($row['admin_name'] ?? ''),
                'payee_id' => $payee,
                'label' => $label,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    public static function dataFromPost(array $post, ?array $existing = null): array
    {
        $reason = trim((string)($post['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException(t('likespa.restores.err.reason_required'));
        }
        $sumRaw = trim((string)($post['summ'] ?? ''));
        if ($sumRaw === '') {
            throw new InvalidArgumentException(t('likespa.restores.err.summ_required'));
        }
        $summ = (float)str_replace(',', '.', $sumRaw);

        $idProcedure = (int)($post['id_procedure'] ?? ($existing['id_procedure'] ?? 0));
        if ($existing === null) {
            if ($idProcedure < 1) {
                throw new InvalidArgumentException(t('likespa.restores.err.procedure_required'));
            }
        } else {
            $idProcedure = (int)$existing['id_procedure'];
        }

        $isSuper = Auth::capability('SUPERADMIN_ACCESS');
        $idReviewed = $existing ? (int)$existing['id_operator_reviewed'] : 0;
        $dtReview = $existing ? (int)$existing['dt_review'] : 0;
        if ($isSuper) {
            if (!empty($post['signed'])) {
                $idReviewed = Auth::userId();
                $dtReview = time();
            } elseif ($existing === null) {
                $idReviewed = 0;
                $dtReview = 0;
            }
        }

        return [
            'dt_create' => $existing ? (int)$existing['dt_create'] : time(),
            'dt_review' => $dtReview,
            'id_procedure' => $idProcedure,
            'id_admin_create' => $existing ? (int)$existing['id_admin_create'] : Auth::userId(),
            'id_operator_paid' => $existing ? (int)$existing['id_operator_paid'] : 0,
            'id_operator_reviewed' => $idReviewed,
            'reason' => mb_substr($reason, 0, 255),
            'summ' => $summ,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,payment_id:int|null}
     */
    public static function createWithSettle(array $data): array
    {
        if (!self::tableReady()) {
            throw new RuntimeException(t('likespa.restores.err.table'));
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO base_procedure_restores
                 (DT_CREATE, DT_REVIEW, ID_PROCEDURE, ID_ADMIN_CREATE, ID_OPERATOR_PAID,
                  ID_OPERATOR_REVIEWED, REASON, SUMM)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $data['dt_create'],
                $data['dt_review'],
                $data['id_procedure'],
                $data['id_admin_create'],
                $data['id_operator_paid'],
                $data['id_operator_reviewed'],
                $data['reason'],
                $data['summ'],
            ]);
            $id = (int)$pdo->lastInsertId();
            $paymentId = self::applyAfterSave($id);
            $pdo->commit();
            return ['id' => $id, 'payment_id' => $paymentId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{payment_id:int|null}
     */
    public static function updateWithSettle(int $id, array $data): array
    {
        if ($id < 1) {
            throw new InvalidArgumentException(t('likespa.restores.err.not_found', ['id' => $id]));
        }
        if (!self::tableReady()) {
            throw new RuntimeException(t('likespa.restores.err.table'));
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'UPDATE base_procedure_restores SET
                 DT_REVIEW = ?, ID_OPERATOR_REVIEWED = ?, REASON = ?, SUMM = ?
                 WHERE ID = ?'
            );
            $st->execute([
                $data['dt_review'],
                $data['id_operator_reviewed'],
                $data['reason'],
                $data['summ'],
                $id,
            ]);
            $paymentId = self::applyAfterSave($id);
            $pdo->commit();
            return ['payment_id' => $paymentId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Oper DoAfterSaveBeforeExit: payee, settlement payment ACCOUNT=0, procedure applyRestore.
     * @return int|null payment id created (or null if skipped/exists)
     */
    public static function applyAfterSave(int $restoreId): ?int
    {
        $row = self::find($restoreId);
        if (!$row) {
            throw new InvalidArgumentException(t('likespa.restores.err.not_found', ['id' => $restoreId]));
        }
        $procId = (int)$row['id_procedure'];
        if ($procId < 1 || !class_exists('ProceduresRepository', false)) {
            throw new InvalidArgumentException(t('likespa.restores.err.procedure_required'));
        }
        $proc = ProceduresRepository::find($procId);
        if (!$proc) {
            throw new InvalidArgumentException(t('procedures.err.not_found', ['id' => $procId]));
        }

        $payee = self::resolvePayeeFromRow($proc);
        Database::pdo()->prepare(
            'UPDATE base_procedure_restores SET ID_OPERATOR_PAID = ? WHERE ID = ?'
        )->execute([$payee, $restoreId]);

        $paymentId = null;
        if (ModuleManager::isEnabled('payments') && class_exists('PaymentsRepository', false)) {
            $descr = (string)$procId;
            if (!PaymentsRepository::existsByDescr($descr)) {
                $paymentId = PaymentsRepository::create([
                    'user_id' => $payee,
                    'dt' => (int)($proc['dtstart'] ?? time()),
                    'descr' => $descr,
                    'direction' => -1,
                    'sum' => (float)$row['summ'],
                    'type_id' => LikespaConfig::restoresPaymentType(),
                    'account' => 0,
                    'dt_create' => time(),
                    'user_signed' => Auth::userId(),
                    'comment_id' => 0,
                    'is_partner' => 0,
                    'admin_inworked' => 0,
                    'dt_admin_inworked' => 0,
                    'admin_signed' => 0,
                    'dt_admin_signed' => 0,
                    'accept_user' => 0,
                    'dt_accept' => 0,
                    'movement_linked' => 0,
                ]);
            }
        }

        ProceduresRepository::applyRestore($procId);
        return $paymentId;
    }

    /**
     * @param list<int> $ids
     */
    public static function deleteByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === [] || !Database::tableExists('base_procedure_restores')) {
            return 0;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare("DELETE FROM base_procedure_restores WHERE ID IN ($ph)");
        $st->execute($ids);
        return $st->rowCount();
    }

    /** @param array<string,mixed> $row */
    public static function resolvePayeeFromRow(array $row): int
    {
        $operator = (int)($row['id_operator'] ?? 0);
        $admin = (int)($row['id_admin'] ?? 0);
        if ($operator > 0 && self::userNotInTeam($operator)) {
            return $admin > 0 ? $admin : $operator;
        }
        return $operator > 0 ? $operator : $admin;
    }

    private static function userNotInTeam(int $userId): bool
    {
        if ($userId < 1 || !Database::tableExists('base_users') || !Database::columnExists('base_users', 'NOT_IN_TEAM')) {
            return false;
        }
        $st = Database::pdo()->prepare('SELECT NOT_IN_TEAM FROM base_users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function staffLabel(int $userId): string
    {
        if ($userId < 1 || !Database::tableExists('base_users')) {
            return (string)$userId;
        }
        $st = Database::pdo()->prepare(
            'SELECT COALESCE(NULLIF(name, \'\'), login) AS n FROM base_users WHERE id = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $name = trim((string)$st->fetchColumn());
        return $name !== '' ? $name : (string)$userId;
    }

    /** @param array<string,mixed> $row */
    private static function castRow(array $row): array
    {
        foreach ([
            'id', 'dt_create', 'dt_review', 'id_procedure', 'id_admin_create',
            'id_operator_paid', 'id_operator_reviewed', 'procedure_dtstart',
            'id_good', 'id_user', 'id_operator', 'id_admin',
        ] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (int)$row[$k];
            }
        }
        $row['summ'] = (float)($row['summ'] ?? 0);
        $row['reason'] = (string)($row['reason'] ?? '');
        foreach (['product_name', 'client_name', 'operator_name', 'admin_name', 'creator_name', 'reviewer_name', 'paid_name'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (string)($row[$k] ?? '');
            }
        }
        return $row;
    }
}
