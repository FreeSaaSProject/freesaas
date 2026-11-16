<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Outbound IIS/IEMK transport. */
interface EgiszTransport
{
    public function id(): string;

    public function configured(): bool;

    /**
     * Upsert patient at the operator (N3 AddPatient).
     *
     * @param array<string,mixed> $patient
     * @return array{ok:bool, remote_id?:string, error?:string, raw?:mixed}
     */
    public function addPatient(array $patient): array;

    /**
     * Register ambulatory case + med records (N3 AddCase).
     *
     * @param array<string,mixed> $case
     * @return array{ok:bool, remote_id?:string, error?:string, raw?:mixed}
     */
    public function addCase(array $case): array;

    /**
     * @param array<string,mixed> $case
     * @return array{ok:bool, remote_id?:string, error?:string, raw?:mixed}
     */
    public function updateCase(array $case): array;

    /**
     * @param array<string,mixed> $record must include id_case_mis
     * @return array{ok:bool, remote_id?:string, error?:string, raw?:mixed}
     */
    public function updateMedRecord(array $record): array;

    /**
     * @param array<string,mixed> $packet local_uid, kind, xml, signatures…
     * @return array{ok:bool, remote_id?:string, error?:string, raw?:mixed}
     */
    public function registerDocument(array $packet): array;

    /**
     * @return array{ok:bool, status?:string, error?:string, raw?:mixed}
     */
    public function fetchStatus(string $localUid): array;
}
