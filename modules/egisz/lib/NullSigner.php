<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class NullSigner implements EgiszSigner
{
    public function id(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function signXml(string $xml, array $context = []): array
    {
        return [
            'ok' => false,
            'error' => t('egisz.err.signer_none'),
        ];
    }
}
