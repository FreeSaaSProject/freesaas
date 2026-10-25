<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class RemittanceBatchesRepository
{
    public static function tableReady(): bool
    {
        return Database::tableExists('base_remittance_batches');
    }

    public static function findByMoveId(int $moveId): ?array
    {
        if ($moveId < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ID_AGREEMENT AS agreement_id, ID_MOVE AS move_id, ID_FEE_MOVE AS fee_move_id,
                    PERIOD_FROM AS period_from, PERIOD_TO AS period_to,
                    GROSS AS gross, FEE AS fee, NET AS net, EXPECTED AS expected,
                    DELTA_GROSS AS delta_gross, DELTA_NET AS delta_net,
                    STATUS AS status, PARSE_ERROR AS parse_error, IGNORED AS ignored,
                    DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_remittance_batches WHERE ID_MOVE = ? LIMIT 1'
        );
        $st->execute([$moveId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::cast($row) : null;
    }

    /**
     * @param list<int> $moveIds
     * @return array<int,array<string,mixed>> move_id => batch
     */
    public static function byMoveIds(array $moveIds): array
    {
        $moveIds = array_values(array_unique(array_filter(array_map('intval', $moveIds), static fn (int $i): bool => $i > 0)));
        if ($moveIds === [] || !self::tableReady()) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($moveIds), '?'));
        $st = Database::pdo()->prepare(
            "SELECT ID AS id, ID_AGREEMENT AS agreement_id, ID_MOVE AS move_id, ID_FEE_MOVE AS fee_move_id,
                    PERIOD_FROM AS period_from, PERIOD_TO AS period_to,
                    GROSS AS gross, FEE AS fee, NET AS net, EXPECTED AS expected,
                    DELTA_GROSS AS delta_gross, DELTA_NET AS delta_net,
                    STATUS AS status, PARSE_ERROR AS parse_error, IGNORED AS ignored,
                    DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_remittance_batches WHERE ID_MOVE IN ($ph)"
        );
        $st->execute($moveIds);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cast = self::cast($row);
            $out[(int)$cast['move_id']] = $cast;
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function upsertForMove(int $moveId, array $data): int
    {
        $existing = self::findByMoveId($moveId);
        $now = time();
        $pdo = Database::pdo();
        $fields = [
            (int)($data['agreement_id'] ?? 0),
            $moveId,
            (int)($data['fee_move_id'] ?? ($existing['fee_move_id'] ?? 0)),
            $data['period_from'] ?? null,
            $data['period_to'] ?? null,
            round((float)($data['gross'] ?? 0), 2),
            round((float)($data['fee'] ?? 0), 2),
            round((float)($data['net'] ?? 0), 2),
            round((float)($data['expected'] ?? 0), 2),
            round((float)($data['delta_gross'] ?? 0), 2),
            round((float)($data['delta_net'] ?? 0), 2),
            (string)($data['status'] ?? 'unparsed'),
            mb_substr((string)($data['parse_error'] ?? ''), 0, 512),
            !empty($data['ignored']) ? 1 : (int)($existing['ignored'] ?? 0),
            $now,
        ];
        if ($existing !== null) {
            $st = $pdo->prepare(
                'UPDATE base_remittance_batches SET
                 ID_AGREEMENT=?, ID_MOVE=?, ID_FEE_MOVE=?, PERIOD_FROM=?, PERIOD_TO=?,
                 GROSS=?, FEE=?, NET=?, EXPECTED=?, DELTA_GROSS=?, DELTA_NET=?,
                 STATUS=?, PARSE_ERROR=?, IGNORED=?, DT_UPDATE=?
                 WHERE ID=?'
            );
            $st->execute(array_merge($fields, [(int)$existing['id']]));
            return (int)$existing['id'];
        }
        $st = $pdo->prepare(
            'INSERT INTO base_remittance_batches
             (ID_AGREEMENT, ID_MOVE, ID_FEE_MOVE, PERIOD_FROM, PERIOD_TO,
              GROSS, FEE, NET, EXPECTED, DELTA_GROSS, DELTA_NET,
              STATUS, PARSE_ERROR, IGNORED, DT_CREATE, DT_UPDATE)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute(array_merge($fields, [$now]));
        return (int)$pdo->lastInsertId();
    }

    public static function setIgnored(int $moveId, bool $ignored): void
    {
        $batch = self::findByMoveId($moveId);
        if ($batch === null) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_remittance_batches SET IGNORED = ?, STATUS = ?, DT_UPDATE = ? WHERE ID = ?'
        );
        $status = $ignored ? 'ignored' : (string)$batch['status'];
        if ($ignored) {
            $status = 'ignored';
        } elseif ($status === 'ignored') {
            $status = 'ok';
        }
        $st->execute([$ignored ? 1 : 0, $status, time(), (int)$batch['id']]);
    }

    public static function setFeeMoveId(int $batchId, int $feeMoveId): void
    {
        if ($batchId < 1) {
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_remittance_batches SET ID_FEE_MOVE = ?, DT_UPDATE = ? WHERE ID = ?'
        );
        $st->execute([$feeMoveId, time(), $batchId]);
    }

    /** @param array<string,mixed> $row */
    private static function cast(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['agreement_id'] = (int)($row['agreement_id'] ?? 0);
        $row['move_id'] = (int)($row['move_id'] ?? 0);
        $row['fee_move_id'] = (int)($row['fee_move_id'] ?? 0);
        $row['gross'] = (float)($row['gross'] ?? 0);
        $row['fee'] = (float)($row['fee'] ?? 0);
        $row['net'] = (float)($row['net'] ?? 0);
        $row['expected'] = (float)($row['expected'] ?? 0);
        $row['delta_gross'] = (float)($row['delta_gross'] ?? 0);
        $row['delta_net'] = (float)($row['delta_net'] ?? 0);
        $row['ignored'] = (int)($row['ignored'] ?? 0);
        $row['status'] = (string)($row['status'] ?? '');
        $row['parse_error'] = (string)($row['parse_error'] ?? '');
        $row['period_from'] = $row['period_from'] !== null && $row['period_from'] !== ''
            ? (string)$row['period_from'] : null;
        $row['period_to'] = $row['period_to'] !== null && $row['period_to'] !== ''
            ? (string)$row['period_to'] : null;
        return $row;
    }
}
