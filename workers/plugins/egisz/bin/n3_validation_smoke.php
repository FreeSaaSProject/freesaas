#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * N3 check-methods smoke for Ticket validation (b2b-demo).
 * Covers form methods: AddPatient, UpdatePatient, GetPatient,
 * AddCase, CreateCase, AddStepToCase, CloseCase, UpdateCase, AddMedRecord.
 * Also AndGetId / GetPatientByGlobalId / GetVersion / Eventlog for journal completeness.
 *
 * Writes:
 *   logs/n3_validation_YYYYMMDD_HHMMSS.jsonl
 *   logs/n3_pack_YYYYMMDD_HHMMSS/{NN_Method_req.xml, NN_Method_resp.xml, summary.txt}
 *
 * Usage: php n3_validation_smoke.php /path/to/local.json
 */

if (!extension_loaded('soap')) {
    fwrite(STDERR, "soap extension required\n");
    exit(2);
}

$cfgPath = $argv[1] ?? '';
if ($cfgPath === '' || !is_file($cfgPath)) {
    fwrite(STDERR, "usage: php n3_validation_smoke.php configs/local.json\n");
    exit(2);
}
$cfg = json_decode((string)file_get_contents($cfgPath), true);
if (!is_array($cfg)) {
    fwrite(STDERR, "bad config json\n");
    exit(2);
}
$n3 = is_array($cfg['n3'] ?? null) ? $cfg['n3'] : [];
$guid = trim((string)($n3['guid'] ?? ''));
$idLpu = trim((string)($n3['id_lpu'] ?? ''));
$pixUrl = rtrim(trim((string)($n3['pix_url'] ?? '')), '/');
$emkUrl = rtrim(trim((string)($n3['emk_url'] ?? '')), '/');
$eventlogUrl = rtrim(trim((string)($n3['eventlog_url'] ?? 'https://api.n3health.ru/eventlog/')), '/') . '/';
if ($guid === '' || $idLpu === '' || strpos($guid, 'CHANGE') !== false) {
    fwrite(STDERR, "n3.guid / n3.id_lpu required in config\n");
    exit(2);
}

$nsCase = 'http://schemas.datacontract.org/2004/07/N3.EMK.Dto.Case';
$nsStep = 'http://schemas.datacontract.org/2004/07/N3.EMK.Dto.Step';
$nsMedDoc = 'http://schemas.datacontract.org/2004/07/N3.EMK.Dto.MedRec.MedDoc';
$nsDiag = 'http://schemas.datacontract.org/2004/07/N3.EMK.Dto.MedRec.Diag';
$nsDto = 'http://schemas.datacontract.org/2004/07/N3.EMK.Dto';

$stamp = date('Ymd_His');
$logDir = dirname($cfgPath) . '/../logs';
$packDir = $logDir . '/n3_pack_' . $stamp;
$logFile = $logDir . '/n3_validation_' . $stamp . '.jsonl';
@mkdir($packDir, 0775, true);

$seq = 0;

/** @param mixed $raw */
function log_row(string $file, string $method, bool $ok, $raw, ?string $error = null): void
{
    $row = [
        'ts' => date('c'),
        'method' => $method,
        'ok' => $ok,
        'error' => $error,
        'raw' => $raw,
    ];
    file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    $mark = $ok ? 'OK' : 'FAIL';
    fwrite(STDOUT, sprintf("[%s] %s %s%s\n", date('c'), $mark, $method, $error ? (' — ' . $error) : ''));
}

function soap_client(string $url): SoapClient
{
    $wsdl = (stripos($url, '?wsdl') !== false) ? $url : ($url . '?wsdl');
    return new SoapClient($wsdl, [
        'trace' => true,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE,
        'connection_timeout' => 12,
        'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
    ]);
}

/**
 * @return array{ok:bool,result?:mixed,error?:string,request?:string,response?:string}
 */
function call(SoapClient $c, string $method, array $params): array
{
    try {
        $result = $c->__soapCall($method, [$params]);
        return [
            'ok' => true,
            'result' => $result,
            'request' => (string)$c->__getLastRequest(),
            'response' => (string)$c->__getLastResponse(),
        ];
    } catch (Throwable $e) {
        $response = method_exists($c, '__getLastResponse') ? (string)@$c->__getLastResponse() : '';
        $msg = $e->getMessage();
        if ($response !== '' && preg_match_all('/<Message>([^<]+)<\\/Message>/', $response, $mm)) {
            $msg = implode('; ', $mm[1]);
        }
        return [
            'ok' => false,
            'error' => $msg,
            'request' => method_exists($c, '__getLastRequest') ? (string)@$c->__getLastRequest() : '',
            'response' => $response,
        ];
    }
}

/**
 * Log + dump SOAP XML into pack dir for N3 ticket upload.
 *
 * @param mixed $extra
 */
function emit(
    string $logFile,
    string $packDir,
    int &$seq,
    string $method,
    array $r,
    $extra = null
): void {
    $seq++;
    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $method) ?: 'method';
    $base = sprintf('%s/%02d_%s', $packDir, $seq, $safe);
    $req = (string)($r['request'] ?? '');
    $resp = (string)($r['response'] ?? '');
    if ($req !== '') {
        file_put_contents($base . '_req.xml', $req);
    }
    if ($resp !== '') {
        file_put_contents($base . '_resp.xml', $resp);
    }
    $raw = [
        'pack' => basename($base),
        'extra' => $extra,
        'result' => $r['result'] ?? null,
        'request_bytes' => strlen($req),
        'response_bytes' => strlen($resp),
    ];
    // Keep truncated XML in jsonl too (for quick grep); full copy is in pack/.
    if ($req !== '') {
        $raw['request_head'] = mb_substr($req, 0, 1500);
    }
    if ($resp !== '') {
        $raw['response_head'] = mb_substr($resp, 0, 1500);
    }
    log_row($logFile, $method, !empty($r['ok']), $raw, $r['error'] ?? null);
    $line = sprintf(
        "%s\t%s\t%s\t%s\n",
        date('c'),
        !empty($r['ok']) ? 'OK' : 'FAIL',
        $method,
        $r['error'] ?? ''
    );
    file_put_contents($packDir . '/summary.txt', $line, FILE_APPEND);
}

$suffix = date('YmdHis');
$idPatientMis = 'fs-val-patient-' . $suffix;
$idPatientMis2 = 'fs-val-patient2-' . $suffix; // separate MIS for AndGetId (no duplicate)
$idCaseAdd = 'fs-val-case-add-' . $suffix;
$idCaseCreate = 'fs-val-case-crt-' . $suffix;
$idDocMis = 'fs-val-doc-' . $suffix;
$idDocMis2 = 'fs-val-doc2-' . $suffix;
$idStaffMis = 'fs-val-staff-' . $suffix;
$idStepMis = 'fs-val-step-' . $suffix;
$idStepMis2 = 'fs-val-step2-' . $suffix;

$patientBase = [
    'FamilyName' => 'Тестов',
    'GivenName' => 'Иван',
    'MiddleName' => 'Иванович',
    'BirthDate' => '1990-01-15T00:00:00',
    'Sex' => 1,
    'Documents' => [
        'DocumentDto' => [
            ['IdDocumentType' => 223, 'DocN' => '10000000110', 'ProviderName' => 'ПФР'],
        ],
    ],
];
$patient = $patientBase + ['IdPatientMIS' => $idPatientMis];
$patient2 = $patientBase + [
    'IdPatientMIS' => $idPatientMis2,
    'GivenName' => 'Пётр',
    'Documents' => [
        'DocumentDto' => [
            ['IdDocumentType' => 223, 'DocN' => '10000000111', 'ProviderName' => 'ПФР'],
        ],
    ],
];

$pix = soap_client($pixUrl);
$emk = soap_client($emkUrl);

$makeDoctor = static function () use ($idStaffMis, $idLpu, $nsDto): stdClass {
    $human = (object)[
        'FamilyName' => 'Врачов',
        'GivenName' => 'Пётр',
        'MiddleName' => 'Петрович',
    ];
    $ident = (object)[
        'IdDocumentType' => 223,
        'DocN' => '10000000211',
        'ProviderName' => 'ПФР',
    ];
    $personDoctor = (object)[
        'IdPersonMis' => $idStaffMis,
        'Sex' => 1,
        'Birthdate' => '1980-05-01T00:00:00',
        'HumanName' => $human,
        'Documents' => (object)['IdentityDocument' => $ident],
    ];
    $doctor = new stdClass();
    $doctor->Person = new SoapVar($personDoctor, SOAP_ENC_OBJECT, 'PersonWithIdentity', $nsDto);
    $doctor->IdLpu = $idLpu;
    $doctor->IdSpeciality = 1;
    $doctor->IdPosition = 1;
    $doctor->IdMedicalStaff = 1;
    $doctor->SpecialityName = 'Терапия';
    $doctor->PositionName = 'Врач-терапевт';
    return $doctor;
};

$now = date('c');
$idMedDocumentType = 7; // demo dict 1.2.643.2.69.1.1.1.195

/**
 * Detached CMS (S/MIME) for DocumentAttachment seals.
 * Demo stand needs nested PersonalSigns/OrganizationSign in the SOAP journal.
 * This is RSA-CMS for structure visibility — real REMD path still needs GOST UKEP later.
 */
$makeDetachedCms = static function (string $payload): string {
    $dir = sys_get_temp_dir() . '/freesaas_egisz_cms_' . getmypid();
    @mkdir($dir, 0700, true);
    $keyFile = $dir . '/key.pem';
    $crtFile = $dir . '/crt.pem';
    $dataFile = $dir . '/data.bin';
    $outFile = $dir . '/out.p7s';
    file_put_contents($dataFile, $payload);
    $prev = error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
    try {
        $ok = openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $keyPem);
        if (!$ok) {
            return 'FreeSaaS-demo-unsigned';
        }
        file_put_contents($keyFile, $keyPem);
        $dn = ['countryName' => 'RU', 'organizationName' => 'FreeSaaS Demo', 'commonName' => 'FreeSaaS N3 Demo'];
        $csr = openssl_csr_new($dn, $keyPem, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $keyPem, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x509, $crtPem);
        file_put_contents($crtFile, $crtPem);
        // PKCS7 detached signature → binary CMS
        $signed = openssl_pkcs7_sign(
            $dataFile,
            $outFile,
            'file://' . $crtFile,
            ['file://' . $keyFile, ''],
            [],
            PKCS7_DETACHED | PKCS7_BINARY
        );
        if (!$signed || !is_file($outFile)) {
            return 'FreeSaaS-demo-unsigned';
        }
        $raw = (string)file_get_contents($outFile);
        // openssl_pkcs7_sign writes PEM S/MIME; extract base64 body if needed
        if (strpos($raw, '-----BEGIN') !== false) {
            $raw = preg_replace('/-----[^-]+-----/', '', $raw) ?? '';
            $raw = base64_decode(preg_replace('/\s+/', '', $raw) ?? '', true) ?: 'FreeSaaS-demo-unsigned';
        }
        return $raw !== '' ? $raw : 'FreeSaaS-demo-unsigned';
    } finally {
        error_reporting($prev);
        foreach ([$keyFile, $crtFile, $dataFile, $outFile] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
};

$makeMedDocument = static function (string $docId) use (
    $makeDoctor,
    $makeDetachedCms,
    $now,
    $nsMedDoc,
    $idMedDocumentType,
    $idPatientMis
): SoapVar {
    // Fuller CDA shell so N3 sees a real SEMD body (not an empty Attachments node).
    $semdXml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<realmCode code="RU"/>'
        . '<typeId root="192.168.1.3.113883.1.3" extension="POCD_MT000040"/>'
        . '<templateId root="10.0.0.4.172.16.1.5.2.227"/>'
        . '<id root="10.0.0.4.172.16.0.2.2.1" extension="' . htmlspecialchars($docId, ENT_XML1) . '"/>'
        . '<code code="227" codeSystem="10.0.0.4.192.168.1.3.1520" '
        . 'codeSystemName="Электронные медицинские документы" displayName="Протокол консультации"/>'
        . '<title>Протокол консультации FreeSaaS (demo)</title>'
        . '<effectiveTime value="' . date('YmdHisO') . '"/>'
        . '<confidentialityCode code="N" codeSystem="192.168.1.3.113883.5.25"/>'
        . '<languageCode code="ru-RU"/>'
        . '<recordTarget><patientRole><id root="PAT" extension="'
        . htmlspecialchars($idPatientMis, ENT_XML1) . '"/>'
        . '<patient><name><family>Тестов</family><given>Иван</given>'
        . '<given>Иванович</given></name>'
        . '<administrativeGenderCode code="1"/><birthTime value="19900115"/></patient>'
        . '</patientRole></recordTarget>'
        . '<author><time value="' . date('YmdHisO') . '"/>'
        . '<assignedAuthor><id root="DOC" extension="fs-val-staff"/>'
        . '<assignedPerson><name><family>Врачов</family><given>Пётр</given></name></assignedPerson>'
        . '</assignedAuthor></author>'
        . '<custodian><assignedCustodian><representedCustodianOrganization>'
        . '<id root="10.0.0.4.172.16.0.2.2.1"/><name>FreeSaaS Demo LPU</name>'
        . '</representedCustodianOrganization></assignedCustodian></custodian>'
        . '<component><structuredBody><component><section>'
        . '<code code="COMPL"/><title>Жалобы</title><text>Тестовые жалобы FreeSaaS</text>'
        . '</section></component></structuredBody></component>'
        . '</ClinicalDocument>';

    $doctor = $makeDoctor();
    $cms = $makeDetachedCms($semdXml);

    // WSDL element names are dotted: MedDocumentDto.DocumentAttachment / PersonalSign
    $personal = new stdClass();
    $personal->Sign = $cms; // Soap maps base64Binary from raw binary
    $personal->Doctor = $doctor;
    $signs = new stdClass();
    $signs->{'MedDocumentDto.PersonalSign'} = $personal;

    $att = new stdClass();
    $att->Data = $semdXml;
    $att->MimeType = 'text/xml';
    // Skip OrganizationSign until real GOST UKEP for MO is available —
    // RSA PKCS7 is rejected as «Поле заполнено некорректно».
    $att->PersonalSigns = $signs;

    $atts = new stdClass();
    $atts->{'MedDocumentDto.DocumentAttachment'} = $att;

    $med = new stdClass();
    $med->Author = $makeDoctor();
    $med->CreationDate = $now;
    $med->Header = 'Протокол консультации FreeSaaS (demo)';
    $med->IdDocumentMis = $docId;
    $med->IdMedDocumentType = $idMedDocumentType;
    $med->Attachments = $atts;
    return new SoapVar($med, SOAP_ENC_OBJECT, 'MedDocument', $nsMedDoc);
};

$makeClinicMainDiagnosis = static function () use ($makeDoctor, $now, $nsDiag): SoapVar {
    $info = (object)[
        'MkbCode' => 'J06.9',
        'DiagnosedDate' => $now,
        'IdDiagnosisType' => 1,
        'IdDiseaseType' => 1,
        'DiseaseStatus' => 1,
        'Comment' => 'FreeSaaS validation diagnosis',
    ];
    $diag = (object)[
        'DiagnosisInfo' => $info,
        'Doctor' => $makeDoctor(),
    ];
    return new SoapVar($diag, SOAP_ENC_OBJECT, 'ClinicMainDiagnosis', $nsDiag);
};

$makeStep = static function (string $stepId, string $comment) use ($makeDoctor, $now, $nsStep): SoapVar {
    $step = new stdClass();
    $step->DateStart = $now;
    $step->DateEnd = $now;
    $step->IdStepMis = $stepId;
    $step->IdPaymentType = 1;
    $step->Doctor = $makeDoctor();
    $step->IdVisitPlace = 1;
    $step->IdVisitPurpose = 1;
    $step->Comment = $comment;
    return new SoapVar($step, SOAP_ENC_OBJECT, 'StepAmb', $nsStep);
};

/**
 * @param bool $closed include CloseDate / result fields
 * @param bool $withComment CreateCase on demo rejects Comment; Add/Close/Update allow it
 * @param bool $withDiagnosis ClinicMainDiagnosis in MedRecords
 */
$makeCaseAmb = static function (
    string $caseId,
    bool $closed,
    ?SoapVar $embeddedStep,
    string $comment,
    bool $withComment = true,
    bool $withDiagnosis = true,
    ?SoapVar $medDocument = null
) use (
    $makeDoctor,
    $makeClinicMainDiagnosis,
    $now,
    $idLpu,
    $idPatientMis,
    $nsCase
): SoapVar {
    $case = new stdClass();
    $case->OpenDate = $now;
    if ($closed) {
        $case->CloseDate = $now;
        $case->IdCaseResult = 1;
        $case->IdAmbResult = 1;
    }
    $case->HistoryNumber = $caseId;
    $case->IdCaseMis = $caseId;
    $case->IdLpu = $idLpu;
    $case->IdPatientMis = $idPatientMis;
    if ($withComment && $comment !== '') {
        $case->Comment = $comment;
    }
    $case->IdPaymentType = 1;
    $case->CaseVisitType = 1;
    $case->Confidentiality = 1;
    $case->DoctorConfidentiality = 1;
    $case->CuratorConfidentiality = 1;
    $case->IdCaseAidType = 1;
    $case->IdCaseType = 2;
    $case->IdCasePurpose = 1;
    $case->IsActive = !$closed;
    $case->DoctorInCharge = $makeDoctor();
    $case->Author = (object)['Doctor' => $makeDoctor(), 'IdRole' => 1];
    $case->Authenticator = (object)['Doctor' => $makeDoctor(), 'IdRole' => 1];
    $case->LegalAuthenticator = (object)['Doctor' => $makeDoctor(), 'IdRole' => 1];
    if ($embeddedStep !== null) {
        $case->Steps = (object)['StepAmb' => $embeddedStep];
    }
    $records = [];
    if ($withDiagnosis) {
        $records[] = $makeClinicMainDiagnosis();
    }
    if ($medDocument !== null) {
        $records[] = $medDocument;
    }
    if ($records !== []) {
        $case->MedRecords = (object)['MedRecord' => count($records) === 1 ? $records[0] : $records];
    }
    return new SoapVar($case, SOAP_ENC_OBJECT, 'CaseAmb', $nsCase);
};

// --- PIX ---
$r = call($pix, 'GetVersion', ['guid' => $guid]);
emit($logFile, $packDir, $seq, 'Pix.GetVersion', $r);

$r = call($emk, 'GetVersion', ['guid' => $guid]);
emit($logFile, $packDir, $seq, 'Emk.GetVersion', $r);

// Form method: AddPatient (not AndGetId)
$r = call($pix, 'AddPatient', [
    'guid' => $guid,
    'idLPU' => $idLpu,
    'patient' => $patient,
]);
emit($logFile, $packDir, $seq, 'Pix.AddPatient', $r, ['id_patient_mis' => $idPatientMis]);

// AndGetId on a second patient (form may only list AddPatient; both land in journal)
$r = call($pix, 'AddPatientAndGetId', [
    'guid' => $guid,
    'idLPU' => $idLpu,
    'patient' => $patient2,
]);
$globalId2 = '';
if (!empty($r['ok']) && is_object($r['result'] ?? null) && isset($r['result']->AddPatientAndGetIdResult)) {
    $globalId2 = (string)$r['result']->AddPatientAndGetIdResult;
}
emit($logFile, $packDir, $seq, 'Pix.AddPatientAndGetId', $r, [
    'id_patient_mis' => $idPatientMis2,
    'global_id' => $globalId2,
]);

$r = call($pix, 'GetPatient', [
    'guid' => $guid,
    'idLPU' => $idLpu,
    'patient' => ['IdPatientMIS' => $idPatientMis],
    'idSource' => 'Reg',
]);
emit($logFile, $packDir, $seq, 'Pix.GetPatient', $r);
$globalId = '';
if (!empty($r['ok']) && is_object($r['result'] ?? null)) {
    $gp = $r['result']->GetPatientResult->PatientDto ?? null;
    if (is_array($gp) && isset($gp[0]) && is_object($gp[0])) {
        $gp = $gp[0];
    }
    if (is_object($gp) && isset($gp->IdGlobal)) {
        $globalId = (string)$gp->IdGlobal;
    }
}

$lookupGlobal = $globalId !== '' ? $globalId : $globalId2;
if ($lookupGlobal !== '') {
    $r = call($pix, 'GetPatientByGlobalId', [
        'guid' => $guid,
        'patientId' => $lookupGlobal,
        'idLpu' => $idLpu,
    ]);
    emit($logFile, $packDir, $seq, 'Pix.GetPatientByGlobalId', $r, ['global_id' => $lookupGlobal]);
}

// Keep MiddleName in sync with SEMD CDA (N3 compares ЭМД vs PIX card).
$patientUpd = $patient;
$patientUpd['MiddleName'] = 'Иванович';
$r = call($pix, 'UpdatePatient', [
    'guid' => $guid,
    'idLPU' => $idLpu,
    'patient' => $patientUpd,
]);
emit($logFile, $packDir, $seq, 'Pix.UpdatePatient', $r);

$r = call($pix, 'UpdatePatientAndGetId', [
    'guid' => $guid,
    'idLPU' => $idLpu,
    'patient' => $patientUpd,
]);
emit($logFile, $packDir, $seq, 'Pix.UpdatePatientAndGetId', $r);

// --- EMK path A: AddCase (with SEMD+seals) → AddMedRecord → UpdateCase ---
$stepEmbedded = $makeStep($idStepMis, 'FreeSaaS validation step (AddCase)');
$caseAdd = $makeCaseAmb(
    $idCaseAdd,
    true,
    $stepEmbedded,
    'FreeSaaS AddCase ' . $suffix,
    true,
    true,
    $makeMedDocument($idDocMis)
);
$r = call($emk, 'AddCase', ['guid' => $guid, 'caseDto' => $caseAdd]);
emit($logFile, $packDir, $seq, 'Emk.AddCase', $r, ['id_case_mis' => $idCaseAdd, 'id_document_mis' => $idDocMis]);

$r = call($emk, 'AddMedRecord', [
    'guid' => $guid,
    'idLpu' => $idLpu,
    'idPatientMis' => $idPatientMis,
    'idCaseMis' => $idCaseAdd,
    'medRecord' => $makeMedDocument($idDocMis . '-amr'),
    'confidentiality' => 1,
]);
emit($logFile, $packDir, $seq, 'Emk.AddMedRecord', $r, [
    'id_document_mis' => $idDocMis . '-amr',
    'id_case_mis' => $idCaseAdd,
]);

$caseAddUpd = $makeCaseAmb(
    $idCaseAdd,
    true,
    $makeStep($idStepMis, 'FreeSaaS step after UpdateCase'),
    'FreeSaaS UpdateCase ' . $suffix,
    true,
    true,
    $makeMedDocument($idDocMis . '-upd')
);
$r = call($emk, 'UpdateCase', ['guid' => $guid, 'caseDto' => $caseAddUpd]);
emit($logFile, $packDir, $seq, 'Emk.UpdateCase', $r, ['id_case_mis' => $idCaseAdd]);

// --- EMK path B: CreateCase → AddStepToCase → AddMedRecord → CloseCase ---
// Demo CreateCase: Comment forbidden; Steps required (at least one).
$idStepCreate = 'fs-val-step-crt-' . $suffix;
$caseCreate = $makeCaseAmb(
    $idCaseCreate,
    false,
    $makeStep($idStepCreate, 'FreeSaaS CreateCase initial step'),
    '',
    false,
    true
);
$r = call($emk, 'CreateCase', [
    'guid' => $guid,
    'createCaseDto' => $caseCreate,
]);
emit($logFile, $packDir, $seq, 'Emk.CreateCase', $r, ['id_case_mis' => $idCaseCreate]);

$r = call($emk, 'AddStepToCase', [
    'guid' => $guid,
    'idLpu' => $idLpu,
    'idPatientMis' => $idPatientMis,
    'idCaseMis' => $idCaseCreate,
    'step' => $makeStep($idStepMis2, 'FreeSaaS AddStepToCase'),
]);
emit($logFile, $packDir, $seq, 'Emk.AddStepToCase', $r, [
    'id_case_mis' => $idCaseCreate,
    'id_step_mis' => $idStepMis2,
]);

$r = call($emk, 'AddMedRecord', [
    'guid' => $guid,
    'idLpu' => $idLpu,
    'idPatientMis' => $idPatientMis,
    'idCaseMis' => $idCaseCreate,
    'medRecord' => $makeMedDocument($idDocMis2),
    'confidentiality' => 1,
]);
emit($logFile, $packDir, $seq, 'Emk.AddMedRecord.CreatePath', $r, [
    'id_document_mis' => $idDocMis2,
    'id_case_mis' => $idCaseCreate,
]);

// CloseCase on demo: do NOT send IdCaseType / Steps (ErrorCode 12).
$caseCloseObj = new stdClass();
$caseCloseObj->OpenDate = $now;
$caseCloseObj->CloseDate = $now;
$caseCloseObj->HistoryNumber = $idCaseCreate;
$caseCloseObj->IdCaseMis = $idCaseCreate;
$caseCloseObj->IdLpu = $idLpu;
$caseCloseObj->IdPatientMis = $idPatientMis;
$caseCloseObj->Comment = 'FreeSaaS CloseCase ' . $suffix;
$caseCloseObj->IdPaymentType = 1;
$caseCloseObj->IdCaseResult = 1;
$caseCloseObj->IdAmbResult = 1;
$caseCloseObj->CaseVisitType = 1;
$caseCloseObj->Confidentiality = 1;
$caseCloseObj->DoctorConfidentiality = 1;
$caseCloseObj->CuratorConfidentiality = 1;
$caseCloseObj->IdCaseAidType = 1;
$caseCloseObj->IdCasePurpose = 1;
$caseCloseObj->IsActive = false;
$caseCloseObj->DoctorInCharge = $makeDoctor();
$caseCloseObj->Author = (object)['Doctor' => $makeDoctor(), 'IdRole' => 1];
$caseCloseObj->Authenticator = (object)['Doctor' => $makeDoctor(), 'IdRole' => 1];
$caseCloseObj->LegalAuthenticator = (object)['Doctor' => $makeDoctor(), 'IdRole' => 1];
$caseCloseObj->MedRecords = (object)['MedRecord' => $makeClinicMainDiagnosis()];
$caseCloseDto = new SoapVar($caseCloseObj, SOAP_ENC_OBJECT, 'CaseAmb', $nsCase);
$r = call($emk, 'CloseCase', [
    'guid' => $guid,
    'caseDto' => $caseCloseDto,
]);
emit($logFile, $packDir, $seq, 'Emk.CloseCase', $r, ['id_case_mis' => $idCaseCreate]);

// Eventlog
$ch = curl_init($eventlogUrl . 'GetStatus?' . http_build_query([
    'IdDocumentMis' => $idDocMis,
    'idLpu' => $idLpu,
]));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer ' . $guid,
        'X-Auth-Token: ' . $guid,
    ],
]);
$body = curl_exec($ch);
$cerr = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$evOk = ($body !== false && $code > 0 && $code < 500);
$evRaw = [
    'http' => $code,
    'id_document_mis' => $idDocMis,
    'body' => $body !== false ? mb_substr((string)$body, 0, 2000) : null,
];
file_put_contents($packDir . '/eventlog_GetStatus.json', (string)$body);
log_row($logFile, 'Eventlog.GetStatus', $evOk, $evRaw, $evOk ? null : ($cerr !== '' ? $cerr : ('HTTP ' . $code)));
file_put_contents(
    $packDir . '/summary.txt',
    sprintf("%s\t%s\t%s\t%s\n", date('c'), $evOk ? 'OK' : 'FAIL', 'Eventlog.GetStatus', $evOk ? '' : ($cerr ?: 'HTTP ' . $code)),
    FILE_APPEND
);

$readme = "FreeSaaS N3 validation pack\n"
    . "UTC stamp: {$stamp}\n"
    . "patient_AddPatient={$idPatientMis} global={$globalId}\n"
    . "patient_AndGetId={$idPatientMis2} global2={$globalId2}\n"
    . "case_add={$idCaseAdd}\n"
    . "case_create={$idCaseCreate}\n"
    . "doc={$idDocMis}\n"
    . "doc2={$idDocMis2}\n"
    . "Upload zip (or *_req.xml / *_resp.xml + summary.txt) to check-methods ticket.\n";
file_put_contents($packDir . '/README.txt', $readme);

$zipPath = $logDir . '/n3_pack_' . $stamp . '.zip';
$zipped = false;
if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach (glob($packDir . '/*') ?: [] as $f) {
            $zip->addFile($f, basename($f));
        }
        $zip->close();
        $zipped = is_file($zipPath);
    }
}

fwrite(STDOUT, "\nLog: {$logFile}\n");
fwrite(STDOUT, "Pack: {$packDir}\n");
if ($zipped) {
    fwrite(STDOUT, "Zip:  {$zipPath}\n");
}
fwrite(STDOUT, "Ids: patient={$idPatientMis} case_add={$idCaseAdd} case_create={$idCaseCreate} global={$globalId}\n");
fwrite(STDOUT, "Next: https://lk.n3health.ru/n3h-auth-client/app/check-methods — attach Zip/XML logs.\n");
exit(0);
