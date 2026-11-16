<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** UKEP/CAdES signing — browser plugin / DSS adapters are out of this slice. */
interface EgiszSigner
{
    public function id(): string;

    public function available(): bool;

    /**
     * @return array{ok:bool, signature_b64?:string, error?:string}
     */
    public function signXml(string $xml, array $context = []): array;
}
