<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Class palette shared with video_label defaults (same hex order).
 */
final class VideoColors
{
    /** @var list<string> */
    public const PALETTE = [
        '#e6194b',
        '#3cb44b',
        '#ffe119',
        '#4363d8',
        '#f58231',
        '#911eb4',
        '#46f0f0',
        '#f032e6',
    ];

    public static function defaultColor(int $i): string
    {
        return self::PALETTE[$i % count(self::PALETTE)];
    }

    /**
     * Normalize class list to [{id,name,color}, …]. Accepts strings or objects.
     *
     * @param list<mixed>|array<string, mixed> $classes
     * @return list<array{id:int,name:string,color:string}>
     */
    public static function normalizeClasses(array $classes): array
    {
        $norm = [];
        $i = 0;
        foreach ($classes as $c) {
            if (is_string($c)) {
                $name = trim($c);
                if ($name === '') {
                    continue;
                }
                $norm[] = [
                    'id' => $i,
                    'name' => $name,
                    'color' => self::defaultColor($i),
                ];
                $i++;
                continue;
            }
            if (!is_array($c)) {
                continue;
            }
            $name = trim((string)($c['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $id = isset($c['id']) ? (int)$c['id'] : $i;
            $color = trim((string)($c['color'] ?? ''));
            if ($color === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $color = self::defaultColor($id >= 0 ? $id : $i);
            }
            $norm[] = [
                'id' => $id,
                'name' => $name,
                'color' => strtolower($color),
            ];
            $i++;
        }

        return $norm;
    }

    /**
     * @return list<array{id:int,name:string,color:string}>
     */
    public static function fromJson(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::normalizeClasses($decoded);
    }

    /**
     * Parse textarea: "name" or "name #rrggbb" per line / comma-separated names.
     *
     * @return list<array{id:int,name:string,color:string}>
     */
    public static function fromTextarea(string $raw): array
    {
        $lines = preg_split('/[\r\n]+/', $raw) ?: [];
        $items = [];
        $i = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $color = '';
            $name = $line;
            if (preg_match('/^(.+?)\s+(#[0-9A-Fa-f]{6})$/', $line, $m)) {
                $name = trim($m[1]);
                $color = strtolower($m[2]);
            } elseif (strpos($line, ',') !== false && !preg_match('/^#/', $line)) {
                // comma list on one line without colors
                foreach (preg_split('/\s*,\s*/', $line) ?: [] as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $items[] = ['id' => $i, 'name' => $part, 'color' => self::defaultColor($i)];
                    $i++;
                }
                continue;
            }
            $items[] = [
                'id' => $i,
                'name' => $name,
                'color' => $color !== '' ? $color : self::defaultColor($i),
            ];
            $i++;
        }

        return self::normalizeClasses($items);
    }
}
