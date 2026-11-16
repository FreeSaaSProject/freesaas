<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class EgiszOutboxRepository
{
    public const STATUSES = [
        'draft',
        'validated',
        'queued',
        'sent',
        'accepted',
        'rejected',
        'error',
    ];

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !Database::tableExists('base_egisz_outbox')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_egisz_outbox WHERE ID = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array{status?:string,client_id?:int,limit?:int,offset?:int} $filters
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function list(array $filters = []): array
    {
        if (!Database::tableExists('base_egisz_outbox')) {
            return ['rows' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $args = [];
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 'STATUS = ?';
            $args[] = $status;
        }
        $clientId = (int)($filters['client_id'] ?? 0);
        if ($clientId > 0) {
            $where[] = 'ID_CLIENT = ?';
            $args[] = $clientId;
        }
        $sqlWhere = implode(' AND ', $where);
        $stCount = Database::pdo()->prepare('SELECT COUNT(*) FROM base_egisz_outbox WHERE ' . $sqlWhere);
        $stCount->execute($args);
        $total = (int)$stCount->fetchColumn();

        $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        $extra = '';
        if (Database::columnExists('base_egisz_outbox', 'ID_CASE_MIS')) {
            $extra = ', ID_CASE_MIS, ID_PATIENT_MIS';
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, LOCAL_UID, KIND, ID_INSTANCE, ID_CLIENT, ID_EVENT, ID_DOCTOR, STATUS,
                    OPERATOR, REMOTE_ID, ERROR_TEXT, DT_CREATE, DT_UPDATE, DT_SENT' . $extra . '
             FROM base_egisz_outbox WHERE ' . $sqlWhere . '
             ORDER BY ID DESC LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $st->execute($args);
        $rows = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = $row;
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,int> */
    public static function countsByStatus(): array
    {
        $out = [];
        foreach (self::STATUSES as $s) {
            $out[$s] = 0;
        }
        if (!Database::tableExists('base_egisz_outbox')) {
            return $out;
        }
        $st = Database::pdo()->query(
            'SELECT STATUS, COUNT(*) AS c FROM base_egisz_outbox GROUP BY STATUS'
        );
        if ($st) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $s = (string)$row['STATUS'];
                if (isset($out[$s])) {
                    $out[$s] = (int)$row['c'];
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function insert(array $data): int
    {
        $now = time();
        $hasCase = Database::columnExists('base_egisz_outbox', 'ID_CASE_MIS');
        $hasRaw = Database::columnExists('base_egisz_outbox', 'RAW_RESPONSE');

        if ($hasCase && $hasRaw) {
            Database::pdo()->prepare(
                'INSERT INTO base_egisz_outbox
                 (LOCAL_UID, ID_CASE_MIS, ID_PATIENT_MIS, KIND, ID_INSTANCE, ID_CLIENT, ID_EVENT, ID_DOCTOR,
                  STATUS, SEMD_XML, VALIDATION_JSON, ERROR_TEXT, RAW_RESPONSE, OPERATOR, REMOTE_ID,
                  DT_CREATE, DT_UPDATE, DT_SENT, ID_ADMIN_CREATE)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (string)$data['local_uid'],
                $data['id_case_mis'] ?? null,
                $data['id_patient_mis'] ?? null,
                (string)$data['kind'],
                (int)($data['id_instance'] ?? 0),
                (int)($data['id_client'] ?? 0),
                (int)($data['id_event'] ?? 0),
                (int)($data['id_doctor'] ?? 0),
                (string)($data['status'] ?? 'draft'),
                $data['semd_xml'] ?? null,
                isset($data['validation']) ? json_encode($data['validation'], JSON_UNESCAPED_UNICODE) : null,
                $data['error_text'] ?? null,
                $data['raw_response'] ?? null,
                (string)($data['operator'] ?? EgiszConfig::operator()),
                $data['remote_id'] ?? null,
                $now,
                $now,
                in_array((string)($data['status'] ?? ''), ['sent', 'accepted'], true) ? $now : 0,
                (int)($data['id_admin'] ?? 0),
            ]);
        } else {
            Database::pdo()->prepare(
                'INSERT INTO base_egisz_outbox
                 (LOCAL_UID, KIND, ID_INSTANCE, ID_CLIENT, ID_EVENT, ID_DOCTOR, STATUS, SEMD_XML,
                  VALIDATION_JSON, ERROR_TEXT, OPERATOR, DT_CREATE, DT_UPDATE, ID_ADMIN_CREATE)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (string)$data['local_uid'],
                (string)$data['kind'],
                (int)($data['id_instance'] ?? 0),
                (int)($data['id_client'] ?? 0),
                (int)($data['id_event'] ?? 0),
                (int)($data['id_doctor'] ?? 0),
                (string)($data['status'] ?? 'draft'),
                $data['semd_xml'] ?? null,
                isset($data['validation']) ? json_encode($data['validation'], JSON_UNESCAPED_UNICODE) : null,
                $data['error_text'] ?? null,
                (string)($data['operator'] ?? EgiszConfig::operator()),
                $now,
                $now,
                (int)($data['id_admin'] ?? 0),
            ]);
        }
        return (int)Database::pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public static function findByLocalUid(string $localUid): ?array
    {
        $localUid = trim($localUid);
        if ($localUid === '' || !Database::tableExists('base_egisz_outbox')) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT * FROM base_egisz_outbox WHERE LOCAL_UID = ? LIMIT 1');
        $st->execute([$localUid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function updateStatus(
        int $id,
        string $status,
        ?string $error = null,
        ?string $raw = null,
        ?string $remoteId = null
    ): void {
        if ($id < 1 || !in_array($status, self::STATUSES, true) || !Database::tableExists('base_egisz_outbox')) {
            return;
        }
        $now = time();
        $remoteSql = '';
        $remoteArgs = [];
        if ($remoteId !== null && $remoteId !== '' && Database::columnExists('base_egisz_outbox', 'REMOTE_ID')) {
            $remoteSql = ', REMOTE_ID = ?';
            $remoteArgs[] = mb_substr($remoteId, 0, 128);
        }
        if (Database::columnExists('base_egisz_outbox', 'RAW_RESPONSE')) {
            $sql = 'UPDATE base_egisz_outbox SET STATUS = ?, ERROR_TEXT = ?, RAW_RESPONSE = COALESCE(?, RAW_RESPONSE),
                 DT_UPDATE = ?, DT_SENT = IF(? IN (\'sent\',\'accepted\'), IF(DT_SENT=0, ?, DT_SENT), DT_SENT)'
                . $remoteSql . ' WHERE ID = ?';
            $args = array_merge([$status, $error, $raw, $now, $status, $now], $remoteArgs, [$id]);
            Database::pdo()->prepare($sql)->execute($args);
            return;
        }
        $sql = 'UPDATE base_egisz_outbox SET STATUS = ?, ERROR_TEXT = ?, DT_UPDATE = ?' . $remoteSql . ' WHERE ID = ?';
        $args = array_merge([$status, $error, $now], $remoteArgs, [$id]);
        Database::pdo()->prepare($sql)->execute($args);
    }

    public static function mapRemoteStatus(string $remote): string
    {
        $r = mb_strtolower(trim($remote));
        if ($r === '') {
            return 'sent';
        }
        if (strpos($r, 'accept') !== false || strpos($r, 'success') !== false || $r === 'ok' || strpos($r, 'зарегистр') !== false) {
            return 'accepted';
        }
        if (strpos($r, 'reject') !== false || strpos($r, 'error') !== false || strpos($r, 'fail') !== false || strpos($r, 'ошиб') !== false) {
            return 'rejected';
        }
        return 'sent';
    }
}
