<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Placeholder until an IIS operator adapter is wired. */
final class NullTransport implements EgiszTransport
{
    public function id(): string
    {
        return 'none';
    }

    public function configured(): bool
    {
        return false;
    }

    public function addPatient(array $patient): array
    {
        return $this->fail();
    }

    public function addCase(array $case): array
    {
        return $this->fail();
    }

    public function updateCase(array $case): array
    {
        return $this->fail();
    }

    public function updateMedRecord(array $record): array
    {
        return $this->fail();
    }

    public function registerDocument(array $packet): array
    {
        return $this->fail();
    }

    public function fetchStatus(string $localUid): array
    {
        return $this->fail();
    }

    /** @return array{ok:bool, error:string} */
    private function fail(): array
    {
        return [
            'ok' => false,
            'error' => t('egisz.err.transport_none'),
        ];
    }
}
