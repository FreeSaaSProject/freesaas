<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class EgiszRegistry
{
    /** @var EgiszTransport|null */
    private static $transport;

    /** @var EgiszSigner|null */
    private static $signer;

    public static function transport(): EgiszTransport
    {
        if (self::$transport === null) {
            self::$transport = self::makeTransport();
        }
        return self::$transport;
    }

    public static function setTransport(EgiszTransport $transport): void
    {
        self::$transport = $transport;
    }

    /** Rebuild after settings save. */
    public static function resetTransport(): void
    {
        self::$transport = null;
    }

    public static function signer(): EgiszSigner
    {
        if (self::$signer === null) {
            self::$signer = new NullSigner();
        }
        return self::$signer;
    }

    public static function setSigner(EgiszSigner $signer): void
    {
        self::$signer = $signer;
    }

    private static function makeTransport(): EgiszTransport
    {
        if (EgiszConfig::operator() === 'n3' && EgiszConfig::n3Configured()) {
            return new N3HealthTransport();
        }
        return new NullTransport();
    }
}
