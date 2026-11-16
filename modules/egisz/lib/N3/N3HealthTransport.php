<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * N3.Health IEMK transport (demo SOAP Pix/EMK + Eventlog).
 * UKEP signatures are NOT invented: unsigned MedRecord is sent for path testing;
 * REMD will reject until a real signer is wired.
 */

final class N3HealthTransport implements EgiszTransport
{
    /** @var N3SoapClient|null */
    private $pix;

    /** @var N3SoapClient|null */
    private $emk;

    /** @var N3EventlogClient */
    private $eventlog;

    public function __construct()
    {
        $this->pix = new N3SoapClient(EgiszConfig::n3PixUrl());
        $this->emk = new N3SoapClient(EgiszConfig::n3EmkUrl());
        $this->eventlog = new N3EventlogClient();
    }

    public function id(): string
    {
        return 'n3';
    }

    public function configured(): bool
    {
        return EgiszConfig::n3Configured();
    }

    public function addPatient(array $patient): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => t('egisz.err.n3_not_configured')];
        }
        $dto = $this->buildPatientDto($patient);
        $params = $this->authParams(['patient' => $dto]);

        $res = $this->callPixPrefer(['AddPatient', 'addPatient'], $params);
        if (!$res['ok'] && $this->emk !== null && $this->emk->ready()) {
            $res = $this->callEmkPrefer(['AddPatient', 'addPatient'], $params);
        }
        return $this->normalizeWrite($res, (string)($patient['id_mis'] ?? ''));
    }

    public function addCase(array $case): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => t('egisz.err.n3_not_configured')];
        }
        $dto = $this->buildCaseDto($case);
        $params = $this->authParams(['case' => $dto]);
        $res = $this->callEmkPrefer(['AddCase', 'addCase'], $params);
        return $this->normalizeWrite($res, (string)($case['id_case_mis'] ?? ''));
    }

    public function updateCase(array $case): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => t('egisz.err.n3_not_configured')];
        }
        $dto = $this->buildCaseDto($case);
        $params = $this->authParams(['case' => $dto]);
        $res = $this->callEmkPrefer(['UpdateCase', 'updateCase'], $params);
        return $this->normalizeWrite($res, (string)($case['id_case_mis'] ?? ''));
    }

    public function updateMedRecord(array $record): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => t('egisz.err.n3_not_configured')];
        }
        $idCase = trim((string)($record['id_case_mis'] ?? ''));
        if ($idCase === '') {
            return ['ok' => false, 'error' => t('egisz.err.case_mis')];
        }
        $med = $this->buildMedRecordDto($record);
        $params = $this->authParams([
            'idCaseMis' => $idCase,
            'medRecord' => $med,
        ]);
        $res = $this->callEmkPrefer(['UpdateMedRecord', 'updateMedRecord'], $params);
        return $this->normalizeWrite($res, (string)($record['id_document_mis'] ?? $idCase));
    }

    public function registerDocument(array $packet): array
    {
        // Map generic outbox packet → AddCase with one MedRecord (unsigned OK for path test).
        return $this->addCase([
            'id_case_mis' => (string)($packet['id_case_mis'] ?? $packet['local_uid'] ?? ''),
            'id_patient_mis' => (string)($packet['id_patient_mis'] ?? ''),
            'open_date' => (string)($packet['open_date'] ?? date('c')),
            'close_date' => (string)($packet['close_date'] ?? date('c')),
            'doctor' => is_array($packet['doctor'] ?? null) ? $packet['doctor'] : [],
            'med_records' => [[
                'id_document_mis' => (string)($packet['local_uid'] ?? ''),
                'kind_oid' => (string)($packet['document_oid'] ?? EgiszConfig::semdConsultOid()),
                'xml' => (string)($packet['xml'] ?? ''),
                'doctor_sign_b64' => (string)($packet['doctor_sign_b64'] ?? ''),
                'org_sign_b64' => (string)($packet['org_sign_b64'] ?? ''),
                'creation_date' => (string)($packet['creation_date'] ?? date('c')),
            ]],
        ]);
    }

    public function fetchStatus(string $localUid): array
    {
        return $this->eventlog->getStatus($localUid);
    }

    /** @return array{pix_ready:bool, emk_ready:bool, pix_error:string, emk_error:string, pix_methods:list<string>, emk_methods:list<string>} */
    public function diagnoseSoap(): array
    {
        return [
            'pix_ready' => $this->pix !== null && $this->pix->ready(),
            'emk_ready' => $this->emk !== null && $this->emk->ready(),
            'pix_error' => $this->pix !== null ? $this->pix->lastError() : '',
            'emk_error' => $this->emk !== null ? $this->emk->lastError() : '',
            'pix_methods' => $this->pix !== null ? $this->pix->methods() : [],
            'emk_methods' => $this->emk !== null ? $this->emk->methods() : [],
            'pix_wsdl' => $this->pix !== null ? $this->pix->wsdl() : '',
            'emk_wsdl' => $this->emk !== null ? $this->emk->wsdl() : '',
        ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function authParams(array $extra): array
    {
        // Demo stands commonly take guid + idLpu as the first two SOAP args or as AuthInfo.
        return array_merge([
            'guid' => EgiszConfig::n3Guid(),
            'idLpu' => EgiszConfig::n3IdLpu(),
            'authInfo' => [
                'Token' => EgiszConfig::n3Guid(),
                'IdLpu' => EgiszConfig::n3IdLpu(),
            ],
        ], $extra);
    }

    /**
     * @param array<string,mixed> $patient
     * @return array<string,mixed>
     */
    private function buildPatientDto(array $patient): array
    {
        $idMis = (string)($patient['id_mis'] ?? '');
        $sex = (string)($patient['gender'] ?? '');
        // FreeSaaS: 0=female, 1=male → N3 often 1=male 2=female
        $sexCode = $sex === '1' ? 1 : ($sex === '0' ? 2 : 0);

        $docs = [];
        $snils = Snils::digits((string)($patient['snils'] ?? ''));
        if ($snils !== '') {
            $docs[] = [
                'IdDocumentType' => 223, // SNILS in many N3 maps; stand may remap via term
                'DocN' => $snils,
                'ProviderName' => 'ПФР',
            ];
        }
        $oms = trim((string)($patient['oms_policy'] ?? ''));
        if ($oms !== '') {
            $docs[] = [
                'IdDocumentType' => 228,
                'DocN' => $oms,
                'ProviderName' => (string)($patient['oms_insurer'] ?? ''),
            ];
        }
        $passSerie = trim((string)($patient['passport_serie'] ?? ''));
        $passNumber = trim((string)($patient['passport_number'] ?? ''));
        if ($passSerie !== '' || $passNumber !== '') {
            $docs[] = [
                'IdDocumentType' => 14,
                'DocS' => $passSerie,
                'DocN' => $passNumber,
                'ProviderName' => (string)($patient['passport_issued_by'] ?? ''),
                'IssuedDate' => (string)($patient['passport_issued_at'] ?? ''),
            ];
        }

        return [
            'IdPatientMis' => $idMis,
            'FamilyName' => (string)($patient['surname'] ?? ''),
            'GivenName' => (string)($patient['pname'] ?? $patient['name'] ?? ''),
            'MiddleName' => (string)($patient['patronymic'] ?? ''),
            'BirthDate' => $this->dateOnly((string)($patient['birthday'] ?? '')),
            'Sex' => $sexCode,
            'Documents' => $docs,
            'HumanName' => [
                'FamilyName' => (string)($patient['surname'] ?? ''),
                'GivenName' => (string)($patient['pname'] ?? $patient['name'] ?? ''),
                'MiddleName' => (string)($patient['patronymic'] ?? ''),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private function buildCaseDto(array $case): array
    {
        $records = [];
        $rawRecords = is_array($case['med_records'] ?? null) ? $case['med_records'] : [];
        foreach ($rawRecords as $r) {
            if (is_array($r)) {
                $records[] = $this->buildMedRecordDto($r);
            }
        }
        $doctor = is_array($case['doctor'] ?? null) ? $case['doctor'] : [];
        return [
            'IdCaseMis' => (string)($case['id_case_mis'] ?? ''),
            'IdPatientMis' => (string)($case['id_patient_mis'] ?? ''),
            'OpenDate' => $this->dateTime((string)($case['open_date'] ?? '')),
            'CloseDate' => $this->dateTime((string)($case['close_date'] ?? '')),
            'HistoryNumber' => (string)($case['history_number'] ?? $case['id_case_mis'] ?? ''),
            'IdCaseType' => (int)($case['case_type'] ?? 2), // ambulatory default on many stands
            'Doctor' => [
                'IdPersonMis' => (string)($doctor['id_mis'] ?? $doctor['id'] ?? ''),
                'FamilyName' => (string)($doctor['surname'] ?? ''),
                'GivenName' => (string)($doctor['pname'] ?? $doctor['name'] ?? ''),
                'MiddleName' => (string)($doctor['patronymic'] ?? ''),
                'Snils' => Snils::digits((string)($doctor['snils'] ?? '')),
                'IdPosition' => (string)($doctor['position_code'] ?? ''),
                'IdSpeciality' => (string)($doctor['specialty_code'] ?? ''),
            ],
            'MedRecords' => $records,
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function buildMedRecordDto(array $record): array
    {
        $xml = (string)($record['xml'] ?? '');
        $docSign = (string)($record['doctor_sign_b64'] ?? '');
        $orgSign = (string)($record['org_sign_b64'] ?? '');
        // Never invent cryptographic signatures — empty means "path test / expect reject".
        return [
            'IdDocumentMis' => (string)($record['id_document_mis'] ?? ''),
            'IdMedDocumentType' => (string)($record['kind_oid'] ?? EgiszConfig::semdConsultOid()),
            'CreationDate' => $this->dateTime((string)($record['creation_date'] ?? date('c'))),
            'Document' => [
                'Data' => $xml !== '' ? base64_encode($xml) : '',
                'MimeType' => 'text/xml',
            ],
            'DoctorSign' => $docSign,
            'OrganizationSign' => $orgSign,
            // Alternate property names seen across WSDL revisions:
            'PersonalSigns' => $docSign !== '' ? [['Sign' => $docSign]] : [],
            'OrgSign' => $orgSign,
        ];
    }

    /**
     * @param list<string> $methods
     * @param array<string,mixed> $params
     * @return array{ok:bool, result?:mixed, error?:string, raw?:mixed}
     */
    private function callPixPrefer(array $methods, array $params): array
    {
        return $this->callPrefer($this->pix, $methods, $params);
    }

    /**
     * @param list<string> $methods
     * @param array<string,mixed> $params
     * @return array{ok:bool, result?:mixed, error?:string, raw?:mixed}
     */
    private function callEmkPrefer(array $methods, array $params): array
    {
        return $this->callPrefer($this->emk, $methods, $params);
    }

    /**
     * @param list<string> $methods
     * @param array<string,mixed> $params
     * @return array{ok:bool, result?:mixed, error?:string, raw?:mixed}
     */
    private function callPrefer(?N3SoapClient $client, array $methods, array $params): array
    {
        if ($client === null || !$client->ready()) {
            return [
                'ok' => false,
                'error' => $client !== null ? $client->lastError() : t('egisz.err.soap_missing'),
            ];
        }
        $last = ['ok' => false, 'error' => t('egisz.err.n3_method')];
        foreach ($methods as $m) {
            // Try packed single-object call and flat auth+dto call styles.
            $attempts = [
                $params,
                [
                    EgiszConfig::n3Guid(),
                    EgiszConfig::n3IdLpu(),
                    $params['patient'] ?? $params['case'] ?? $params['medRecord'] ?? $params,
                ],
            ];
            if (isset($params['idCaseMis'], $params['medRecord'])) {
                $attempts[] = [
                    EgiszConfig::n3Guid(),
                    EgiszConfig::n3IdLpu(),
                    $params['idCaseMis'],
                    $params['medRecord'],
                ];
            }
            foreach ($attempts as $args) {
                $res = $client->call($m, is_array($args) ? $args : [$args]);
                $last = $res;
                if ($res['ok']) {
                    return $res;
                }
            }
        }
        return $last;
    }

    /**
     * @param array{ok:bool, result?:mixed, error?:string, raw?:mixed} $res
     * @return array{ok:bool, remote_id?:string, error?:string, raw?:mixed}
     */
    private function normalizeWrite(array $res, string $fallbackId): array
    {
        if (!$res['ok']) {
            return [
                'ok' => false,
                'error' => (string)($res['error'] ?? t('egisz.err.n3_call')),
                'raw' => $res['raw'] ?? null,
            ];
        }
        $remote = $fallbackId;
        $result = $res['result'] ?? null;
        if (is_string($result) && $result !== '') {
            $remote = $result;
        } elseif (is_object($result) && isset($result->IdPatientMis)) {
            $remote = (string)$result->IdPatientMis;
        } elseif (is_array($result) && isset($result['IdPatientMis'])) {
            $remote = (string)$result['IdPatientMis'];
        }
        return [
            'ok' => true,
            'remote_id' => $remote,
            'raw' => $res['raw'] ?? $result,
        ];
    }

    private function dateOnly(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : $raw;
    }

    private function dateTime(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return date('c');
        }
        $ts = strtotime($raw);
        return $ts ? date('c', $ts) : $raw;
    }
}
