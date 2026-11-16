<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Small XML helpers for CDA-like SEMD builders. */
final class SemdXml
{
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function snilsDigits(string $snils): string
    {
        return Snils::digits($snils);
    }

    /** @param array<string,string> $attrs */
    public static function tag(string $name, string $body = '', array $attrs = []): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= ' ' . $k . '="' . self::esc($v) . '"';
        }
        if ($body === '') {
            return '<' . $name . $a . '/>';
        }
        return '<' . $name . $a . '>' . $body . '</' . $name . '>';
    }
}
