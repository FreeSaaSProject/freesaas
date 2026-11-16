<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared CDA R2 skeleton for ambulatory SEMD previews.
 * Structure follows federal SEMD conventions; full Schematron parity is iterative.
 */

abstract class AbstractCdaBuilder implements SemdBuilder
{
    abstract public function kind(): string;

    abstract protected function documentOid(): string;

    abstract protected function title(): string;

    /** @param array<string,mixed> $ctx */
    abstract protected function bodySections(array $ctx, array $diagnoses): string;

    public function build(array $instance, array $ctx): SemdDocument
    {
        $payload = is_array($instance['payload'] ?? null)
            ? $instance['payload']
            : (is_array($ctx['payload'] ?? null) ? $ctx['payload'] : []);
        $ctx['payload'] = $payload;

        $codes = Icd10Repository::parseCodes((string)($payload['dia_codes'] ?? ''));
        $diagnoses = Icd10Repository::enrich($codes);
        $check = SemdValidator::checkContext($ctx, $diagnoses, $this->kind());

        $localUid = SemdXml::uuid();
        $xml = '';
        if ($check['errors'] === []) {
            $xml = $this->renderClinicalDocument($localUid, $instance, $ctx, $diagnoses);
        }

        return new SemdDocument(
            $localUid,
            $this->kind(),
            $this->documentOid(),
            $xml,
            $diagnoses,
            $check['errors'],
            $check['warnings'],
            (int)($instance['id'] ?? 0),
            (int)($instance['id_client'] ?? $ctx['client_id'] ?? 0),
            (int)($instance['id_event'] ?? $ctx['event_id'] ?? 0),
            (int)($ctx['doctor']['id'] ?? 0)
        );
    }

    /**
     * @param array<string,mixed> $instance
     * @param array<string,mixed> $ctx
     * @param list<array{code:string,name:string,known:bool}> $diagnoses
     */
    protected function renderClinicalDocument(string $localUid, array $instance, array $ctx, array $diagnoses): string
    {
        $patient = is_array($ctx['patient'] ?? null) ? $ctx['patient'] : [];
        $doctor = is_array($ctx['doctor'] ?? null) ? $ctx['doctor'] : [];
        $org = is_array($ctx['org'] ?? null) ? $ctx['org'] : [];
        $event = is_array($ctx['event'] ?? null) ? $ctx['event'] : [];

        $now = date('YmdHisO');
        $eff = trim((string)($event['date'] ?? ''));
        if ($eff !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $eff)) {
            $effTs = strtotime($eff);
            $eff = $effTs ? date('YmdHisO', $effTs) : $now;
        } else {
            $eff = $now;
        }

        $moOid = EgiszConfig::moOid();
        $docOid = $this->documentOid();
        $title = SemdXml::esc($this->title());

        $patientXml = $this->patientRoleXml($patient);
        $authorXml = $this->assignedEntityXml($doctor, $moOid, $org);
        $custodianXml = $this->custodianXml($moOid, $org);
        $encounterXml = $this->encounterXml($event, $eff, $moOid, $org);
        $body = $this->bodySections($ctx, $diagnoses);

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<ClinicalDocument xmlns="urn:hl7-org:v3" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . SemdXml::tag('realmCode', '', ['code' => 'RU'])
            . SemdXml::tag('typeId', '', ['root' => '192.168.1.3.113883.1.3', 'extension' => 'POCD_MT000040'])
            . SemdXml::tag('templateId', '', ['root' => $docOid])
            . SemdXml::tag('id', '', ['root' => $moOid, 'extension' => $localUid])
            . SemdXml::tag('code', '', [
                'code' => $this->kind() === 'manip' ? '283' : '227',
                'codeSystem' => '10.0.0.4.192.168.1.3.1520',
                'codeSystemName' => 'Электронные медицинские документы',
                'displayName' => $this->title(),
            ])
            . '<title>' . $title . '</title>'
            . SemdXml::tag('effectiveTime', '', ['value' => $eff])
            . SemdXml::tag('confidentialityCode', '', [
                'code' => 'N',
                'codeSystem' => '192.168.1.3.113883.5.25',
                'displayName' => 'Normal',
            ])
            . SemdXml::tag('languageCode', '', ['code' => 'ru-RU'])
            . '<recordTarget>' . $patientXml . '</recordTarget>'
            . '<author><time value="' . SemdXml::esc($eff) . '"/>' . $authorXml . '</author>'
            . '<custodian>' . $custodianXml . '</custodian>'
            . '<componentOf>' . $encounterXml . '</componentOf>'
            . '<component><structuredBody>' . $body . '</structuredBody></component>'
            . '</ClinicalDocument>';
    }

    /** @param array<string,mixed> $patient */
    protected function patientRoleXml(array $patient): string
    {
        $snils = SemdXml::snilsDigits((string)($patient['snils'] ?? ''));
        $gender = (string)($patient['gender'] ?? '');
        $genderCode = $gender === '0' || $gender === 'M' || $gender === '1' ? '1' : ($gender === '2' || $gender === 'F' ? '2' : '3');
        // FreeSaaS clients: gender 0/1 — map 1→male(1), 0→female(2) per common RU charts; unknown→3
        if ($gender === '1') {
            $genderCode = '1';
        } elseif ($gender === '0') {
            $genderCode = '2';
        }

        $bday = preg_replace('/\D+/', '', (string)($patient['birthday'] ?? '')) ?? '';
        if (strlen($bday) >= 8) {
            $bday = substr($bday, 0, 8);
        } else {
            $bday = '';
        }

        $family = SemdXml::esc((string)($patient['surname'] ?? ''));
        $given = SemdXml::esc((string)($patient['pname'] ?? $patient['name'] ?? ''));
        $patronymic = SemdXml::esc((string)($patient['patronymic'] ?? ''));

        $name = '<name>'
            . ($family !== '' ? '<family>' . $family . '</family>' : '')
            . ($given !== '' ? '<given>' . $given . '</given>' : '')
            . ($patronymic !== '' ? '<given>' . $patronymic . '</given>' : '')
            . '</name>';

        $ids = SemdXml::tag('id', '', ['root' => '192.168.0.3.3', 'extension' => $snils]);
        if (!empty($patient['oms_policy'])) {
            $ids .= SemdXml::tag('id', '', [
                'root' => '192.168.0.3.4',
                'extension' => (string)$patient['oms_policy'],
            ]);
        }

        return '<patientRole>'
            . $ids
            . '<patient>'
            . $name
            . SemdXml::tag('administrativeGenderCode', '', [
                'code' => $genderCode,
                'codeSystem' => '10.0.0.4.192.168.1.3.1040',
            ])
            . ($bday !== '' ? SemdXml::tag('birthTime', '', ['value' => $bday]) : '')
            . '</patient>'
            . '</patientRole>';
    }

    /**
     * @param array<string,mixed> $doctor
     * @param array<string,mixed> $org
     */
    protected function assignedEntityXml(array $doctor, string $moOid, array $org): string
    {
        $snils = SemdXml::snilsDigits((string)($doctor['snils'] ?? ''));
        $pos = trim((string)($doctor['position_code'] ?? ''));
        $nameParts = preg_split('/\s+/u', trim((string)($doctor['name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $family = SemdXml::esc((string)($nameParts[0] ?? 'Doctor'));
        $given = SemdXml::esc((string)($nameParts[1] ?? '-'));

        $code = $pos !== ''
            ? SemdXml::tag('code', '', [
                'code' => $pos,
                'codeSystem' => '10.0.0.4.172.16.0.2.1002',
                'codeSystemName' => 'Должности медицинских работников',
            ])
            : '';

        return '<assignedAuthor>'
            . SemdXml::tag('id', '', ['root' => '192.168.0.3.3', 'extension' => $snils])
            . $code
            . '<assignedPerson><name><family>' . $family . '</family><given>' . $given . '</given></name></assignedPerson>'
            . '<representedOrganization>'
            . SemdXml::tag('id', '', ['root' => $moOid])
            . '<name>' . SemdXml::esc((string)($org['name'] ?? '')) . '</name>'
            . '</representedOrganization>'
            . '</assignedAuthor>';
    }

    /** @param array<string,mixed> $org */
    protected function custodianXml(string $moOid, array $org): string
    {
        return '<assignedCustodian><representedCustodianOrganization>'
            . SemdXml::tag('id', '', ['root' => $moOid])
            . '<name>' . SemdXml::esc((string)($org['name'] ?? '')) . '</name>'
            . '</representedCustodianOrganization></assignedCustodian>';
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $org
     */
    protected function encounterXml(array $event, string $eff, string $moOid, array $org): string
    {
        $ext = (string)((int)($event['id'] ?? 0) ?: '0');
        $depOid = EgiszConfig::departmentOid();
        $depName = EgiszConfig::departmentName();
        $location = '';
        if ($depOid !== '') {
            $location = '<location><healthCareFacility>'
                . SemdXml::tag('id', '', ['root' => $depOid])
                . ($depName !== '' ? '<location><name>' . SemdXml::esc($depName) . '</name></location>' : '')
                . '</healthCareFacility></location>';
        }
        return '<encompassingEncounter>'
            . SemdXml::tag('id', '', ['root' => $moOid, 'extension' => $ext])
            . SemdXml::tag('effectiveTime', '', ['value' => $eff])
            . $location
            . '</encompassingEncounter>';
    }

    /**
     * @param list<array{code:string,name:string,known:bool}> $diagnoses
     */
    protected function diagnosisSection(array $diagnoses): string
    {
        $entries = '';
        foreach ($diagnoses as $d) {
            $entries .= '<entry><observation classCode="OBS" moodCode="EVN">'
                . SemdXml::tag('code', '', [
                    'code' => '8388707',
                    'codeSystem' => '10.0.0.4.172.16.0.2.100118',
                    'displayName' => 'Диагноз',
                ])
                . SemdXml::tag('value', '', [
                    'xsi:type' => 'CD',
                    'code' => (string)$d['code'],
                    'codeSystem' => '10.0.0.4.192.168.1.3.1005',
                    'codeSystemName' => 'МКБ-10',
                    'displayName' => (string)($d['name'] !== '' ? $d['name'] : $d['code']),
                ])
                . '</observation></entry>';
        }
        $text = '';
        foreach ($diagnoses as $d) {
            $text .= SemdXml::esc($d['code'] . ($d['name'] !== '' ? ' — ' . $d['name'] : '')) . '<br/>';
        }
        return '<section>'
            . SemdXml::tag('code', '', [
                'code' => 'SYSTEM',
                'codeSystem' => '10.0.0.4.172.16.0.2.100118',
                'displayName' => 'Диагнозы',
            ])
            . '<title>Диагнозы</title><text>' . $text . '</text>'
            . $entries
            . '</section>';
    }

    protected function textSection(string $code, string $title, string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        return '<section>'
            . SemdXml::tag('code', '', [
                'code' => $code,
                'codeSystem' => '10.0.0.4.172.16.0.2.100118',
                'displayName' => $title,
            ])
            . '<title>' . SemdXml::esc($title) . '</title>'
            . '<text>' . SemdXml::esc($body) . '</text>'
            . '</section>';
    }
}
