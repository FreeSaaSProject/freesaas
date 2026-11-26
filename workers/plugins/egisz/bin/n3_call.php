#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone N3 SOAP/Eventlog runner for the egisz edge worker.
 * stdin JSON → stdout JSON. No FreeSaaS bootstrap (runs on Mac with OpenVPN).
 *
 * Input:
 * {
 *   "type": "add_patient"|"add_case"|"eventlog_poll",
 *   "n3": { "guid", "id_lpu", "pix_url", "emk_url", "eventlog_url", "eventlog_token?" },
 *   "patient"?: {...},
 *   "packet"?: {...}, "update"?: bool,
 *   "local_uids"?: ["..."]
 * }
 */

if (!extension_loaded('soap')) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'php soap extension missing'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(2);
}

$raw = stream_get_contents(STDIN);
$in = json_decode((string)$raw, true);
if (!is_array($in)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'invalid json stdin'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(2);
}

$type = (string)($in['type'] ?? '');
$n3 = is_array($in['n3'] ?? null) ? $in['n3'] : [];
$guid = trim((string)($n3['guid'] ?? ''));
$idLpu = trim((string)($n3['id_lpu'] ?? ''));
$pixUrl = rtrim(trim((string)($n3['pix_url'] ?? '')), '/');
$emkUrl = rtrim(trim((string)($n3['emk_url'] ?? '')), '/');
$eventlogUrl = rtrim(trim((string)($n3['eventlog_url'] ?? '')), '/') . '/';
$eventlogToken = trim((string)($n3['eventlog_token'] ?? ''));
if ($eventlogToken === '') {
    $eventlogToken = $guid;
}

if ($guid === '' || $idLpu === '') {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'n3.guid / n3.id_lpu required'], JSON_UNESCAPED_UNICODE) . "\n");
    exit(2);
}

function snils_digits(string $raw): string
{
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function date_only(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : $raw;
}

function date_time(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return date('c');
    }
    $ts = strtotime($raw);
    return $ts ? date('c', $ts) : $raw;
}

/**
 * @return SoapClient|null
 */
function soap_client(string $serviceUrl, string &$error)
{
    $error = '';
    if ($serviceUrl === '') {
        $error = 'empty service url';
        return null;
    }
    $wsdl = (stripos($serviceUrl, '?wsdl') !== false) ? $serviceUrl : $serviceUrl . '?wsdl';
    try {
        return new SoapClient($wsdl, [
            'trace' => true,
            'exceptions' => true,
            'connection_timeout' => 8,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        ]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return null;
    }
}

/**
 * N3 demo WSDL wraps ops as AddPatient{ guid, idLPU, patient } (capital PU).
 *
 * @param array<string,mixed> $params named request body
 * @return array{ok:bool,result?:mixed,error?:string,raw?:mixed}
 */
function soap_try(SoapClient $client, array $methods, array $params): array
{
    $last = ['ok' => false, 'error' => 'no method matched'];
    foreach ($methods as $m) {
        try {
            $result = $client->__soapCall($m, [$params]);
            return [
                'ok' => true,
                'result' => $result,
                'raw' => [
                    'method' => $m,
                    'request' => $client->__getLastRequest(),
                    'response' => $client->__getLastResponse(),
                ],
            ];
        } catch (SoapFault $e) {
            $last = [
                'ok' => false,
                'error' => trim($e->faultstring !== '' ? $e->faultstring : $e->getMessage()),
                'raw' => [
                    'method' => $m,
                    'faultcode' => $e->faultcode ?? null,
                    'request' => @$client->__getLastRequest(),
                    'response' => @$client->__getLastResponse(),
                ],
            ];
        } catch (Throwable $e) {
            $last = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    return $last;
}

/**
 * @param array<string,mixed> $patient
 * @return array<string,mixed>
 */
function build_patient_dto(array $patient): array
{
    $sex = (string)($patient['gender'] ?? '');
    $sexCode = $sex === '1' ? 1 : ($sex === '0' ? 2 : 0);
    $docs = [];
    $snils = snils_digits((string)($patient['snils'] ?? ''));
    if ($snils !== '') {
        $docs[] = ['IdDocumentType' => 223, 'DocN' => $snils, 'ProviderName' => 'ПФР'];
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
    $idMis = (string)($patient['id_mis'] ?? '');
    $birth = date_only((string)($patient['birthday'] ?? ''));
    return [
        'IdPatientMIS' => $idMis,
        'FamilyName' => (string)($patient['surname'] ?? ''),
        'GivenName' => (string)($patient['pname'] ?? $patient['name'] ?? ''),
        'MiddleName' => (string)($patient['patronymic'] ?? ''),
        'BirthDate' => $birth !== '' ? ($birth . 'T00:00:00') : null,
        'Sex' => $sexCode,
        // .NET ArrayOfDocumentDto expects DocumentDto item key
        'Documents' => $docs !== [] ? ['DocumentDto' => $docs] : null,
    ];
}

/**
 * @param array<string,mixed> $case
 * @return array<string,mixed>
 */
function build_case_dto(array $case): array
{
    $records = [];
    foreach (is_array($case['med_records'] ?? null) ? $case['med_records'] : [] as $r) {
        if (!is_array($r)) {
            continue;
        }
        $xml = (string)($r['xml'] ?? '');
        $records[] = [
            'IdDocumentMis' => (string)($r['id_document_mis'] ?? ''),
            'IdMedDocumentType' => (string)($r['kind_oid'] ?? ''),
            'CreationDate' => date_time((string)($r['creation_date'] ?? date('c'))),
            'Document' => [
                'Data' => $xml !== '' ? base64_encode($xml) : '',
                'MimeType' => 'text/xml',
            ],
            'DoctorSign' => '',
            'OrganizationSign' => '',
            'PersonalSigns' => [],
            'OrgSign' => '',
        ];
    }
    $doctor = is_array($case['doctor'] ?? null) ? $case['doctor'] : [];
    return [
        'IdCaseMis' => (string)($case['id_case_mis'] ?? ''),
        'IdPatientMis' => (string)($case['id_patient_mis'] ?? ''),
        'OpenDate' => date_time((string)($case['open_date'] ?? '')),
        'CloseDate' => date_time((string)($case['close_date'] ?? '')),
        'HistoryNumber' => (string)($case['history_number'] ?? $case['id_case_mis'] ?? ''),
        'IdCaseType' => (int)($case['case_type'] ?? 2),
        'Doctor' => [
            'IdPersonMis' => (string)($doctor['id_mis'] ?? $doctor['id'] ?? ''),
            'FamilyName' => (string)($doctor['surname'] ?? ''),
            'GivenName' => (string)($doctor['pname'] ?? $doctor['name'] ?? ''),
            'MiddleName' => (string)($doctor['patronymic'] ?? ''),
            'Snils' => snils_digits((string)($doctor['snils'] ?? '')),
            'IdPosition' => (string)($doctor['position_code'] ?? ''),
            'IdSpeciality' => (string)($doctor['specialty_code'] ?? ''),
        ],
        'MedRecords' => $records,
    ];
}

/**
 * @param mixed $result
 */
function remote_id_from($result, string $fallback): string
{
    if (is_string($result) && $result !== '') {
        return $result;
    }
    if (is_object($result) && isset($result->IdPatientMis)) {
        return (string)$result->IdPatientMis;
    }
    if (is_object($result) && isset($result->IdCaseMis)) {
        return (string)$result->IdCaseMis;
    }
    if (is_array($result) && isset($result['IdPatientMis'])) {
        return (string)$result['IdPatientMis'];
    }
    if (is_array($result) && isset($result['IdCaseMis'])) {
        return (string)$result['IdCaseMis'];
    }
    return $fallback;
}

function http_get(string $url, string $token): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-Auth-Token: ' . $token,
        ],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        return ['ok' => false, 'error' => $err !== '' ? $err : 'curl failed'];
    }
    if ($code >= 400) {
        return ['ok' => false, 'error' => 'HTTP ' . $code, 'body' => $body];
    }
    return ['ok' => true, 'body' => $body];
}

$out = ['ok' => false, 'error' => 'unknown type'];

if ($type === 'add_patient') {
    $patient = is_array($in['patient'] ?? null) ? $in['patient'] : [];
    $dto = build_patient_dto($patient);
    // Prefer AndGetId — response carries guid/global id.
    $params = [
        'guid' => $guid,
        'idLPU' => $idLpu,
        'patient' => $dto,
    ];
    $err = '';
    $pix = soap_client($pixUrl, $err);
    if ($pix === null) {
        $res = ['ok' => false, 'error' => 'pix: ' . $err];
    } else {
        $res = soap_try($pix, ['AddPatientAndGetId', 'AddPatient'], $params);
    }
    if (!empty($res['ok'])) {
        $remote = remote_id_from($res['result'] ?? null, (string)($patient['id_mis'] ?? ''));
        // AddPatientAndGetIdResult may be a guid object/string
        $r = $res['result'] ?? null;
        if (is_object($r) && isset($r->AddPatientAndGetIdResult)) {
            $remote = (string)$r->AddPatientAndGetIdResult;
        } elseif (is_array($r) && isset($r['AddPatientAndGetIdResult'])) {
            $remote = (string)$r['AddPatientAndGetIdResult'];
        }
        $out = [
            'ok' => true,
            'remote_id' => $remote !== '' ? $remote : (string)($patient['id_mis'] ?? ''),
            'raw' => $res['raw'] ?? null,
        ];
    } else {
        $out = ['ok' => false, 'error' => (string)($res['error'] ?? 'AddPatient failed'), 'raw' => $res['raw'] ?? null];
    }
} elseif ($type === 'get_version') {
    $svc = strtolower((string)($in['service'] ?? 'pix'));
    $url = $svc === 'emk' ? $emkUrl : $pixUrl;
    $err = '';
    $client = soap_client($url, $err);
    if ($client === null) {
        $out = ['ok' => false, 'error' => $svc . ': ' . $err];
    } else {
        $res = soap_try($client, ['GetVersion'], ['guid' => $guid]);
        $out = !empty($res['ok'])
            ? ['ok' => true, 'result' => $res['result'] ?? null, 'raw' => $res['raw'] ?? null]
            : ['ok' => false, 'error' => (string)($res['error'] ?? 'GetVersion failed'), 'raw' => $res['raw'] ?? null];
    }
} elseif ($type === 'add_case') {
    // Demo WSDL: AddCase{ guid, caseDto: CaseAmb } — idLPU lives inside CaseBase.IdLpu.
    $packet = is_array($in['packet'] ?? null) ? $in['packet'] : [];
    $update = !empty($in['update']);
    $nsCase = 'http://schemas.datacontract.org/2004/07/N3.EMK.Dto.Case';
    $nsMedDoc = 'http://schemas.datacontract.org/2004/07/N3.EMK.Dto.MedRec.MedDoc';
    $dto = build_case_dto($packet);
    $dto['IdLpu'] = $idLpu;
    $dto['IdPatientMis'] = (string)($packet['id_patient_mis'] ?? $dto['IdPatientMis'] ?? '');
    // Prefer ambulatory CaseAmb; attach MedDocument via SoapVar when XML present.
    $caseAmb = $dto;
    $caseAmb['IdCaseType'] = (int)($packet['case_type'] ?? 1);
    $caseAmb['IsActive'] = true;
    if (!empty($dto['MedRecords']) && is_array($dto['MedRecords'])) {
        $meds = [];
        foreach ($dto['MedRecords'] as $mr) {
            if (!is_array($mr)) {
                continue;
            }
            // WSDL uses dotted element names MedDocumentDto.DocumentAttachment.
            // Data is xs:base64Binary — pass raw XML bytes (SoapClient base64-encodes).
            $xmlB64 = is_array($mr['Document'] ?? null) ? (string)($mr['Document']['Data'] ?? '') : '';
            $xmlRaw = $xmlB64 !== '' ? (base64_decode($xmlB64, true) ?: $xmlB64) : '';
            $att = new stdClass();
            $att->Data = $xmlRaw;
            $att->MimeType = 'text/xml';
            $docSign = (string)($mr['DoctorSign'] ?? '');
            $orgSign = (string)($mr['OrganizationSign'] ?? '');
            if ($docSign !== '') {
                $ps = new stdClass();
                $ps->Sign = base64_decode($docSign, true) ?: $docSign;
                $ps->Doctor = $dto['Doctor'] ?? null;
                $signs = new stdClass();
                $signs->{'MedDocumentDto.PersonalSign'} = $ps;
                $att->PersonalSigns = $signs;
            }
            if ($orgSign !== '') {
                $att->OrganizationSign = base64_decode($orgSign, true) ?: $orgSign;
            }
            $atts = new stdClass();
            $atts->{'MedDocumentDto.DocumentAttachment'} = $att;
            $medDoc = [
                'CreationDate' => $mr['CreationDate'] ?? date('c'),
                'Header' => 'SEMD',
                'IdDocumentMis' => $mr['IdDocumentMis'] ?? '',
                // Regional dict 1.2.643.2.69.1.1.1.195 — demo stand accepts 7.
                'IdMedDocumentType' => 7,
                'Attachments' => $atts,
                'Author' => $dto['Doctor'] ?? null,
            ];
            $meds[] = new SoapVar($medDoc, SOAP_ENC_OBJECT, 'MedDocument', $nsMedDoc);
        }
        if ($meds !== []) {
            $caseAmb['MedRecords'] = ['MedRecord' => count($meds) === 1 ? $meds[0] : $meds];
        }
    }
    $caseDto = new SoapVar($caseAmb, SOAP_ENC_OBJECT, 'CaseAmb', $nsCase);
    $err = '';
    $emk = soap_client($emkUrl, $err);
    if ($emk === null) {
        $out = ['ok' => false, 'error' => 'emk: ' . $err];
    } else {
        $methods = $update ? ['UpdateCase'] : ['AddCase'];
        $res = soap_try($emk, $methods, ['guid' => $guid, 'caseDto' => $caseDto]);
        if (!empty($res['ok'])) {
            $out = [
                'ok' => true,
                'remote_id' => remote_id_from($res['result'] ?? null, (string)($packet['id_case_mis'] ?? '')),
                'raw' => $res['raw'] ?? null,
            ];
        } else {
            $out = ['ok' => false, 'error' => (string)($res['error'] ?? 'AddCase failed'), 'raw' => $res['raw'] ?? null];
        }
    }
} elseif ($type === 'eventlog_poll') {
    $uids = is_array($in['local_uids'] ?? null) ? $in['local_uids'] : [];
    $items = [];
    foreach ($uids as $uid) {
        $uid = trim((string)$uid);
        if ($uid === '') {
            continue;
        }
        $url = $eventlogUrl . 'GetStatus?' . http_build_query([
            'IdDocumentMis' => $uid,
            'idLpu' => $idLpu,
        ]);
        $res = http_get($url, $eventlogToken);
        $status = '';
        if (!empty($res['ok'])) {
            $json = json_decode((string)($res['body'] ?? ''), true);
            if (is_array($json)) {
                $status = (string)($json['Status'] ?? $json['status'] ?? $json['Result'] ?? '');
            }
        }
        $items[] = [
            'local_uid' => $uid,
            'status' => $status !== '' ? $status : (string)($res['error'] ?? ''),
            'ok' => !empty($res['ok']),
        ];
    }
    $out = ['ok' => true, 'items' => $items];
}

fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
exit(!empty($out['ok']) ? 0 : 1);
