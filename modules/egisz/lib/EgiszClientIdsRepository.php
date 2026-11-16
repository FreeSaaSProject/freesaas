<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class EgiszClientIdsRepository
{
    /** @return array<string,mixed>|null */
    public static function find(int $clientId): ?array
    {
        if ($clientId < 1 || !Database::tableExists('base_egisz_client_ids')) {
            return null;
        }
        $cols = 'ID_CLIENT, SNILS, OMS_POLICY, OMS_INSURER';
        if (Database::columnExists('base_egisz_client_ids', 'N3_ID')) {
            $cols .= ', N3_ID, ID_PATIENT_MIS';
        }
        $st = Database::pdo()->prepare(
            'SELECT ' . $cols . ' FROM base_egisz_client_ids WHERE ID_CLIENT = ? LIMIT 1'
        );
        $st->execute([$clientId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function patientMisId(int $clientId): string
    {
        $row = self::find($clientId);
        $mis = trim((string)($row['ID_PATIENT_MIS'] ?? ''));
        if ($mis !== '') {
            return $mis;
        }
        $n3 = trim((string)($row['N3_ID'] ?? ''));
        return $n3;
    }

    /**
     * Prefer module SoT; fall back to medcard_profile payload snils/oms when empty.
     *
     * @return array{snils:string,oms_policy:string,oms_insurer:string}
     */
    public static function resolveForClient(int $clientId, ?array $profilePayload = null): array
    {
        $row = self::find($clientId);
        $snils = trim((string)($row['SNILS'] ?? ''));
        $oms = trim((string)($row['OMS_POLICY'] ?? ''));
        $insurer = trim((string)($row['OMS_INSURER'] ?? ''));
        if ($profilePayload !== null) {
            if ($snils === '') {
                $snils = trim((string)($profilePayload['snils'] ?? ''));
            }
            if ($oms === '') {
                $oms = trim((string)($profilePayload['oms_policy'] ?? ''));
            }
            if ($insurer === '') {
                $insurer = trim((string)($profilePayload['insurer'] ?? ''));
            }
        }
        return [
            'snils' => $snils !== '' ? (Snils::format($snils) ?: $snils) : '',
            'oms_policy' => $oms,
            'oms_insurer' => $insurer,
        ];
    }

    /**
     * @return bool false when SNILS is non-empty but invalid
     */
    public static function upsert(int $clientId, ?string $snils, ?string $omsPolicy, ?string $omsInsurer): bool
    {
        if ($clientId < 1 || !Database::tableExists('base_egisz_client_ids')) {
            return true;
        }
        $snilsFmt = null;
        if ($snils !== null && trim($snils) !== '') {
            if (!Snils::isValid($snils)) {
                return false;
            }
            $snilsFmt = Snils::format($snils);
        }
        $oms = $omsPolicy !== null ? mb_substr(trim($omsPolicy), 0, 32) : null;
        if ($oms === '') {
            $oms = null;
        }
        $ins = $omsInsurer !== null ? mb_substr(trim($omsInsurer), 0, 255) : null;
        if ($ins === '') {
            $ins = null;
        }
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO base_egisz_client_ids (ID_CLIENT, SNILS, OMS_POLICY, OMS_INSURER, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE SNILS = VALUES(SNILS), OMS_POLICY = VALUES(OMS_POLICY),
               OMS_INSURER = VALUES(OMS_INSURER), DT_UPDATE = VALUES(DT_UPDATE)'
        )->execute([$clientId, $snilsFmt, $oms, $ins, $now]);
        return true;
    }

    public static function setN3Ids(int $clientId, string $n3Id, string $idPatientMis): void
    {
        if ($clientId < 1 || !Database::tableExists('base_egisz_client_ids')) {
            return;
        }
        if (!Database::columnExists('base_egisz_client_ids', 'N3_ID')) {
            return;
        }
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO base_egisz_client_ids (ID_CLIENT, N3_ID, ID_PATIENT_MIS, DT_UPDATE)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE N3_ID = VALUES(N3_ID), ID_PATIENT_MIS = VALUES(ID_PATIENT_MIS),
               DT_UPDATE = VALUES(DT_UPDATE)'
        )->execute([
            $clientId,
            mb_substr(trim($n3Id), 0, 64),
            mb_substr(trim($idPatientMis), 0, 64),
            $now,
        ]);
    }
}
