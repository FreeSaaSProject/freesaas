<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Built SEMD package ready for outbox / future transport. */
final class SemdDocument
{
    /** @var string */
    public $localUid;
    /** @var string */
    public $kind;
    /** @var string */
    public $documentOid;
    /** @var string */
    public $xml;
    /** @var list<array{code:string,name:string,known:bool}> */
    public $diagnoses;
    /** @var list<string> */
    public $errors;
    /** @var list<string> */
    public $warnings;
    /** @var int */
    public $instanceId;
    /** @var int */
    public $clientId;
    /** @var int */
    public $eventId;
    /** @var int */
    public $doctorId;

    /**
     * @param list<array{code:string,name:string,known:bool}> $diagnoses
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public function __construct(
        string $localUid,
        string $kind,
        string $documentOid,
        string $xml,
        array $diagnoses,
        array $errors,
        array $warnings,
        int $instanceId,
        int $clientId,
        int $eventId,
        int $doctorId
    ) {
        $this->localUid = $localUid;
        $this->kind = $kind;
        $this->documentOid = $documentOid;
        $this->xml = $xml;
        $this->diagnoses = $diagnoses;
        $this->errors = $errors;
        $this->warnings = $warnings;
        $this->instanceId = $instanceId;
        $this->clientId = $clientId;
        $this->eventId = $eventId;
        $this->doctorId = $doctorId;
    }

    public function ok(): bool
    {
        return $this->errors === [] && $this->xml !== '';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'local_uid' => $this->localUid,
            'kind' => $this->kind,
            'document_oid' => $this->documentOid,
            'xml' => $this->xml,
            'diagnoses' => $this->diagnoses,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'ok' => $this->ok(),
            'id_instance' => $this->instanceId,
            'id_client' => $this->clientId,
            'id_event' => $this->eventId,
            'id_doctor' => $this->doctorId,
        ];
    }
}
