<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** SNILS normalize / checksum helpers (Russian insurance number). */
final class Snils
{
    /** Digits only (11) or empty. */
    public static function digits(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw) ?? '';
    }

    /** Canonical display: XXX-XXX-XXX YY or empty. */
    public static function format(string $raw): string
    {
        $d = self::digits($raw);
        if (strlen($d) !== 11) {
            return '';
        }
        return substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6, 3) . ' ' . substr($d, 9, 2);
    }

    public static function isValid(string $raw): bool
    {
        $d = self::digits($raw);
        if (strlen($d) !== 11) {
            return false;
        }
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$d[$i] * (9 - $i);
        }
        $check = $sum % 101;
        if ($check === 100) {
            $check = 0;
        }
        return $check === (int)substr($d, 9, 2);
    }

    /**
     * @return string formatted SNILS or empty
     * @throws InvalidArgumentException
     */
    public static function normalizeOrEmpty(string $raw, bool $requireValid = true): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $fmt = self::format($raw);
        if ($fmt === '' || ($requireValid && !self::isValid($raw))) {
            throw new InvalidArgumentException(t('egisz.err.bad_snils'));
        }
        return $fmt;
    }
}
