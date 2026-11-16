<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class AcquiringRegistry
{
    /** @var array<string,AcquiringProvider> */
    private static array $providers = [];

    public static function register(AcquiringProvider $provider): void
    {
        self::$providers[$provider->id()] = $provider;
    }

    /** @return array<string,AcquiringProvider> */
    public static function all(): array
    {
        return self::$providers;
    }

    /** @return list<AcquiringProvider> */
    public static function enabled(): array
    {
        $out = [];
        foreach (self::$providers as $p) {
            if ($p->enabled()) {
                $out[] = $p;
            }
        }
        return $out;
    }

    public static function get(string $id): ?AcquiringProvider
    {
        return self::$providers[$id] ?? null;
    }

    public static function forCheckout(?string $preferred = null): ?AcquiringProvider
    {
        $preferred = $preferred !== null && $preferred !== ''
            ? preg_replace('/[^a-z0-9_]/', '', $preferred) ?? ''
            : '';
        if ($preferred !== '') {
            $p = self::get($preferred);
            if ($p !== null && $p->enabled()) {
                return $p;
            }
        }
        $def = AcquiringConfig::defaultProvider();
        $p = self::get($def);
        if ($p !== null && $p->enabled()) {
            return $p;
        }
        $enabled = self::enabled();
        return $enabled[0] ?? null;
    }
}
