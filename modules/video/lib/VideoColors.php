<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Class palette shared with video_label defaults (same hex order).
 * Class shape: {id, name, label?, color} — label is display (e.g. Russian).
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

    public static function displayName(array $class): string
    {
        $label = trim((string)($class['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        return trim((string)($class['name'] ?? ''));
    }

    /**
     * @param list<mixed>|array<string, mixed> $classes
     * @return list<array{id:int,name:string,label:string,color:string}>
     */
    public static function normalizeClasses(array $classes): array
    {
        $norm = [];
        $i = 0;
        foreach ($classes as $c) {
            if (is_string($c)) {
                $parsed = self::parseLine($c, $i);
                if ($parsed !== null) {
                    $norm[] = $parsed;
                    $i++;
                }
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
            $label = trim((string)($c['label'] ?? ''));
            if ($label === '') {
                $label = $name;
            }
            $color = trim((string)($c['color'] ?? ''));
            if ($color === '' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $color = self::defaultColor($id >= 0 ? $id : $i);
            }
            $norm[] = [
                'id' => $id,
                'name' => $name,
                'label' => $label,
                'color' => strtolower($color),
            ];
            $i++;
        }

        return $norm;
    }

    /**
     * @return list<array{id:int,name:string,label:string,color:string}>
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
     * Lines: "name", "name #rrggbb", "name|label", "name|label #rrggbb"
     *
     * @return list<array{id:int,name:string,label:string,color:string}>
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
            if (strpos($line, ',') !== false && strpos($line, '|') === false && !preg_match('/#/', $line)) {
                foreach (preg_split('/\s*,\s*/', $line) ?: [] as $part) {
                    $p = self::parseLine(trim($part), $i);
                    if ($p !== null) {
                        $items[] = $p;
                        $i++;
                    }
                }
                continue;
            }
            $p = self::parseLine($line, $i);
            if ($p !== null) {
                $items[] = $p;
                $i++;
            }
        }

        return self::normalizeClasses($items);
    }

    /** @return array{id:int,name:string,label:string,color:string}|null */
    private static function parseLine(string $line, int $i): ?array
    {
        if ($line === '') {
            return null;
        }
        $color = '';
        if (preg_match('/^(.*)\s+(#[0-9A-Fa-f]{6})$/', $line, $m)) {
            $line = trim($m[1]);
            $color = strtolower($m[2]);
        }
        $name = $line;
        $label = '';
        if (strpos($line, '|') !== false) {
            [$name, $label] = array_map('trim', explode('|', $line, 2));
        }
        if ($name === '') {
            return null;
        }
        if ($label === '') {
            $label = $name;
        }

        return [
            'id' => $i,
            'name' => $name,
            'label' => $label,
            'color' => $color !== '' ? $color : self::defaultColor($i),
        ];
    }
}
