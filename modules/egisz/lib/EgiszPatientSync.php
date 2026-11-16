<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Map FreeSaaS client → N3 AddPatient and store IdPatientMis. */
final class EgiszPatientSync
{
    /**
     * @return array{ok:bool, id_patient_mis?:string, error?:string, raw?:mixed, queued?:bool, job_id?:int, outbox_id?:int}
     */
    public static function sync(int $clientId): array
    {
        if ($clientId < 1 || !class_exists('ClientsRepository', false)) {
            return ['ok' => false, 'error' => t('egisz.err.client')];
        }
        $client = ClientsRepository::find($clientId);
        if ($client === null) {
            return ['ok' => false, 'error' => t('egisz.err.client')];
        }

        $profile = null;
        if (class_exists('DocsInstancesRepository', false) && method_exists('DocsInstancesRepository', 'latestForClient')) {
            $prof = DocsInstancesRepository::latestForClient($clientId, 'medcard_profile');
            if (is_array($prof) && is_array($prof['payload'] ?? null)) {
                $profile = $prof['payload'];
            }
        }
        $ids = EgiszClientIdsRepository::resolveForClient($clientId, $profile);
        $idMis = EgiszClientIdsRepository::patientMisId($clientId);
        if ($idMis === '') {
            $idMis = 'fs-client-' . $clientId;
        }

        $patient = [
            'id_mis' => $idMis,
            'surname' => (string)($client['surname'] ?? ''),
            'pname' => (string)($client['pname'] ?? ''),
            'patronymic' => (string)($client['patronymic'] ?? ''),
            'name' => (string)($client['name'] ?? ''),
            'birthday' => (string)($client['birthday'] ?? ''),
            'gender' => isset($client['gender']) ? (string)$client['gender'] : '',
            'snils' => $ids['snils'],
            'oms_policy' => $ids['oms_policy'],
            'oms_insurer' => $ids['oms_insurer'],
            'passport_serie' => (string)($client['passport_serie'] ?? ''),
            'passport_number' => (string)($client['passport_number'] ?? ''),
            'passport_issued_by' => (string)($client['passport_issued_by'] ?? ''),
            'passport_issued_at' => (string)($client['passport_issued_at'] ?? ''),
        ];

        if ($patient['snils'] === '' || !Snils::isValid($patient['snils'])) {
            return ['ok' => false, 'error' => t('egisz.err.patient_snils')];
        }

        if (!EgiszConfig::n3Configured()) {
            return ['ok' => false, 'error' => t('egisz.err.n3_not_configured')];
        }

        if (EgiszWorkerDispatch::enabled()) {
            $outboxId = EgiszOutboxRepository::insert([
                'local_uid' => SemdXml::uuid(),
                'kind' => 'patient',
                'id_instance' => 0,
                'id_client' => $clientId,
                'id_patient_mis' => $idMis,
                'status' => 'queued',
                'semd_xml' => null,
                'validation' => ['patient' => $patient, 'via' => 'worker'],
                'error_text' => null,
                'operator' => 'n3',
                'id_admin' => (int)(Auth::userId() ?? 0),
            ]);
            $jobId = EgiszWorkerJobsRepository::enqueue(
                EgiszWorkerJobsRepository::TYPE_ADD_PATIENT,
                ['client_id' => $clientId, 'patient' => $patient],
                $outboxId
            );
            return [
                'ok' => true,
                'queued' => true,
                'job_id' => $jobId,
                'outbox_id' => $outboxId,
                'id_patient_mis' => $idMis,
            ];
        }

        $transport = EgiszRegistry::transport();
        if (!$transport->configured()) {
            return ['ok' => false, 'error' => t('egisz.err.n3_not_configured')];
        }

        $res = $transport->addPatient($patient);
        if (!empty($res['ok'])) {
            $remote = (string)($res['remote_id'] ?? $idMis);
            EgiszClientIdsRepository::setN3Ids($clientId, $remote, $idMis);
            EgiszOutboxRepository::insert([
                'local_uid' => SemdXml::uuid(),
                'kind' => 'patient',
                'id_instance' => 0,
                'id_client' => $clientId,
                'id_patient_mis' => $idMis,
                'status' => 'sent',
                'semd_xml' => null,
                'validation' => ['patient' => $patient],
                'error_text' => null,
                'raw_response' => isset($res['raw']) ? json_encode($res['raw'], JSON_UNESCAPED_UNICODE) : null,
                'operator' => 'n3',
                'remote_id' => $remote,
                'id_admin' => (int)(Auth::userId() ?? 0),
            ]);
            return ['ok' => true, 'id_patient_mis' => $idMis, 'remote_id' => $remote, 'raw' => $res['raw'] ?? null];
        }

        EgiszOutboxRepository::insert([
            'local_uid' => SemdXml::uuid(),
            'kind' => 'patient',
            'id_instance' => 0,
            'id_client' => $clientId,
            'id_patient_mis' => $idMis,
            'status' => 'error',
            'error_text' => (string)($res['error'] ?? ''),
            'raw_response' => isset($res['raw']) ? json_encode($res['raw'], JSON_UNESCAPED_UNICODE) : null,
            'operator' => 'n3',
            'id_admin' => (int)(Auth::userId() ?? 0),
        ]);

        return [
            'ok' => false,
            'error' => (string)($res['error'] ?? t('egisz.err.n3_call')),
            'raw' => $res['raw'] ?? null,
        ];
    }
}
