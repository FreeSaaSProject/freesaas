<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class EgiszStaffRepository
{
    /** @return array<string,mixed>|null */
    public static function find(int $userId): ?array
    {
        if ($userId < 1 || !Database::tableExists('base_egisz_staff')) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID_USER, SNILS, POSITION_CODE, SPECIALTY_CODE, ROLE_CODE
             FROM base_egisz_staff WHERE ID_USER = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array{snils:string,position_code:string,specialty_code:string,role_code:string}
     */
    public static function resolve(int $userId): array
    {
        $row = self::find($userId);
        $snils = trim((string)($row['SNILS'] ?? ''));
        return [
            'snils' => $snils !== '' ? (Snils::format($snils) ?: $snils) : '',
            'position_code' => trim((string)($row['POSITION_CODE'] ?? '')),
            'specialty_code' => trim((string)($row['SPECIALTY_CODE'] ?? '')),
            'role_code' => trim((string)($row['ROLE_CODE'] ?? '')),
        ];
    }

    /**
     * @return bool false when SNILS is non-empty but invalid
     */
    public static function upsert(
        int $userId,
        ?string $snils,
        ?string $positionCode,
        ?string $specialtyCode,
        ?string $roleCode
    ): bool {
        if ($userId < 1 || !Database::tableExists('base_egisz_staff')) {
            return true;
        }
        $snilsFmt = null;
        if ($snils !== null && trim($snils) !== '') {
            if (!Snils::isValid($snils)) {
                return false;
            }
            $snilsFmt = Snils::format($snils);
        }
        $pos = self::nullCode($positionCode, 32);
        $spec = self::nullCode($specialtyCode, 32);
        $role = self::nullCode($roleCode, 32);
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO base_egisz_staff (ID_USER, SNILS, POSITION_CODE, SPECIALTY_CODE, ROLE_CODE, DT_UPDATE)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE SNILS = VALUES(SNILS), POSITION_CODE = VALUES(POSITION_CODE),
               SPECIALTY_CODE = VALUES(SPECIALTY_CODE), ROLE_CODE = VALUES(ROLE_CODE),
               DT_UPDATE = VALUES(DT_UPDATE)'
        )->execute([$userId, $snilsFmt, $pos, $spec, $role, $now]);
        return true;
    }

    private static function nullCode(?string $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = mb_substr(trim($v), 0, $max);
        return $t === '' ? null : $t;
    }
}
