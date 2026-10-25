<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Match ledger moves to remittance agreements by supplier id / DESCR fallback.
 */

final class RemittanceMatcher
{
    /** @var list<array<string,mixed>>|null */
    private static $enabledCache = null;

    public static function clearCache(): void
    {
        self::$enabledCache = null;
    }

    /** @return list<array<string,mixed>> */
    public static function enabledAgreements(): array
    {
        if (self::$enabledCache === null) {
            self::$enabledCache = RemittanceAgreementsRepository::listEnabled();
        }
        return self::$enabledCache;
    }

    /**
     * @param array<string,mixed> $move cast row from LedgerMovesRepository
     */
    public static function findAgreementForMove(array $move): ?array
    {
        $descr = (string)($move['descr'] ?? '');
        $supplierId = (int)($move['supplier_id'] ?? 0);
        $accountId = (int)($move['account_id'] ?? 0);
        $typeId = (int)($move['type_id'] ?? 0);
        $direction = (int)($move['direction'] ?? 0);
        if ($direction !== 1) {
            return null;
        }

        foreach (self::enabledAgreements() as $ag) {
            if ((int)($ag['account_id'] ?? 0) > 0 && (int)$ag['account_id'] !== $accountId) {
                continue;
            }
            if ((int)($ag['type_id'] ?? 0) > 0 && (int)$ag['type_id'] !== $typeId) {
                continue;
            }
            $agSupplier = (int)($ag['supplier_id'] ?? 0);
            if ($agSupplier < 1) {
                continue;
            }
            if ($supplierId > 0 && $supplierId === $agSupplier) {
                return $ag;
            }
            if (self::descrMatchesSupplier($descr, $ag)) {
                return $ag;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $agreement
     */
    public static function descrMatchesSupplier(string $descr, array $agreement): bool
    {
        $descrNorm = self::normalizeText($descr);
        if ($descrNorm === '') {
            return false;
        }
        $inn = trim((string)($agreement['supplier_inn'] ?? ''));
        if ($inn !== '' && mb_strpos($descrNorm, self::normalizeText($inn)) !== false) {
            return true;
        }
        foreach (['supplier_ur_name', 'supplier_name'] as $key) {
            $name = trim((string)($agreement[$key] ?? ''));
            if ($name === '') {
                continue;
            }
            $nameNorm = self::normalizeText($name);
            if (mb_strlen($nameNorm) < 4) {
                continue;
            }
            if (mb_strpos($descrNorm, $nameNorm) !== false) {
                return true;
            }
            // Drop ООО/ООО quotes noise: compare significant tokens
            $compactDescr = preg_replace('/\\s+/u', '', $descrNorm) ?? $descrNorm;
            $compactName = preg_replace('/\\s+/u', '', $nameNorm) ?? $nameNorm;
            if ($compactName !== '' && mb_strpos($compactDescr, $compactName) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function normalizeText(string $s): string
    {
        $s = mb_strtoupper($s, 'UTF-8');
        $s = str_replace(['«', '»', '"', "'", '“', '”'], '', $s);
        $s = preg_replace('/\\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
}
