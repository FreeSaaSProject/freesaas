<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Build SEMD + AddCase without UKEP (signatures empty — REMD reject expected).
 */

final class EgiszCaseSync
{
    /**
     * @return array{ok:bool, outbox_id?:int, document?:array<string,mixed>, error?:string, raw?:mixed, note?:string}
     */
    public static function sendFromInstance(int $instanceId, string $kind = 'consult', bool $update = false): array
    {
        $kind = $kind === 'manip' ? 'manip' : 'consult';
        $doc = SemdFactory::fromInstance($instanceId, $kind);
        $note = t('egisz.note.unsigned');

        if (!$doc->ok()) {
            $outboxId = EgiszOutboxRepository::insert([
                'local_uid' => $doc->localUid,
                'kind' => $doc->kind,
                'id_instance' => $doc->instanceId,
                'id_client' => $doc->clientId,
                'id_event' => $doc->eventId,
                'id_doctor' => $doc->doctorId,
                'status' => 'draft',
                'semd_xml' => null,
                'validation' => [
                    'errors' => $doc->errors,
                    'warnings' => $doc->warnings,
                ],
                'error_text' => implode('; ', $doc->errors),
                'operator' => EgiszConfig::operator(),
                'id_admin' => (int)(Auth::userId() ?? 0),
            ]);
            return [
                'ok' => false,
                'outbox_id' => $outboxId,
                'document' => $doc->toArray(),
                'error' => implode('; ', $doc->errors),
                'note' => $note,
            ];
        }

        $patientMis = EgiszClientIdsRepository::patientMisId($doc->clientId);
        if ($patientMis === '') {
            $patientMis = 'fs-client-' . $doc->clientId;
        }

        $caseMis = SemdXml::uuid();
        $staff = EgiszStaffRepository::resolve($doc->doctorId);
        $doctorName = '';
        if ($doc->doctorId > 0 && class_exists('UsersRepository', false)) {
            $u = UsersRepository::find($doc->doctorId);
            $doctorName = (string)($u['name'] ?? '');
        }
        $nameParts = preg_split('/\s+/u', trim($doctorName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $packet = [
            'id_case_mis' => $caseMis,
            'id_patient_mis' => $patientMis,
            'local_uid' => $doc->localUid,
            'document_oid' => $doc->documentOid,
            'xml' => $doc->xml,
            'doctor_sign_b64' => '',
            'org_sign_b64' => '',
            'open_date' => date('c'),
            'close_date' => date('c'),
            'doctor' => [
                'id' => $doc->doctorId,
                'id_mis' => 'fs-staff-' . $doc->doctorId,
                'name' => $doctorName,
                'surname' => (string)($nameParts[0] ?? ''),
                'pname' => (string)($nameParts[1] ?? ''),
                'patronymic' => (string)($nameParts[2] ?? ''),
                'snils' => $staff['snils'],
                'position_code' => $staff['position_code'],
                'specialty_code' => $staff['specialty_code'],
            ],
            'med_records' => [[
                'id_document_mis' => $doc->localUid,
                'kind_oid' => $doc->documentOid,
                'xml' => $doc->xml,
                'doctor_sign_b64' => '',
                'org_sign_b64' => '',
                'creation_date' => date('c'),
            ]],
        ];

        if (EgiszWorkerDispatch::enabled()) {
            $outboxId = EgiszOutboxRepository::insert([
                'local_uid' => $doc->localUid,
                'id_case_mis' => $caseMis,
                'id_patient_mis' => $patientMis,
                'kind' => $doc->kind,
                'id_instance' => $doc->instanceId,
                'id_client' => $doc->clientId,
                'id_event' => $doc->eventId,
                'id_doctor' => $doc->doctorId,
                'status' => 'queued',
                'semd_xml' => $doc->xml,
                'validation' => [
                    'errors' => $doc->errors,
                    'warnings' => $doc->warnings,
                    'diagnoses' => $doc->diagnoses,
                    'unsigned' => true,
                    'via' => 'worker',
                    'update' => $update,
                ],
                'error_text' => null,
                'operator' => EgiszConfig::operator(),
                'id_admin' => (int)(Auth::userId() ?? 0),
            ]);
            $jobId = EgiszWorkerJobsRepository::enqueue(
                EgiszWorkerJobsRepository::TYPE_ADD_CASE,
                [
                    'update' => $update,
                    'packet' => $packet,
                ],
                $outboxId
            );
            return [
                'ok' => true,
                'queued' => true,
                'job_id' => $jobId,
                'outbox_id' => $outboxId,
                'document' => $doc->toArray(),
                'id_case_mis' => $caseMis,
                'note' => $note,
            ];
        }

        $transport = EgiszRegistry::transport();
        $res = $update
            ? $transport->updateCase($packet)
            : $transport->addCase($packet);

        $status = !empty($res['ok']) ? 'sent' : 'error';
        $outboxId = EgiszOutboxRepository::insert([
            'local_uid' => $doc->localUid,
            'id_case_mis' => $caseMis,
            'id_patient_mis' => $patientMis,
            'kind' => $doc->kind,
            'id_instance' => $doc->instanceId,
            'id_client' => $doc->clientId,
            'id_event' => $doc->eventId,
            'id_doctor' => $doc->doctorId,
            'status' => $status,
            'semd_xml' => $doc->xml,
            'validation' => [
                'errors' => $doc->errors,
                'warnings' => $doc->warnings,
                'diagnoses' => $doc->diagnoses,
                'unsigned' => true,
            ],
            'error_text' => !empty($res['ok']) ? null : (string)($res['error'] ?? ''),
            'raw_response' => isset($res['raw']) ? json_encode($res['raw'], JSON_UNESCAPED_UNICODE) : null,
            'operator' => EgiszConfig::operator(),
            'remote_id' => (string)($res['remote_id'] ?? ''),
            'id_admin' => (int)(Auth::userId() ?? 0),
        ]);

        return [
            'ok' => !empty($res['ok']),
            'outbox_id' => $outboxId,
            'document' => $doc->toArray(),
            'id_case_mis' => $caseMis,
            'error' => !empty($res['ok']) ? null : (string)($res['error'] ?? ''),
            'raw' => $res['raw'] ?? null,
            'note' => $note,
        ];
    }

    /**
     * @return array{ok:bool, updated?:int, error?:string, queued?:bool, job_id?:int}
     */
    public static function pollEventlog(int $limit = 20): array
    {
        $list = EgiszOutboxRepository::list(['status' => 'sent', 'limit' => $limit]);
        $uids = [];
        foreach ($list['rows'] as $row) {
            $uid = (string)($row['LOCAL_UID'] ?? '');
            if ($uid !== '') {
                $uids[] = $uid;
            }
        }
        if ($uids === []) {
            return ['ok' => true, 'updated' => 0];
        }

        if (EgiszWorkerDispatch::enabled()) {
            $jobId = EgiszWorkerJobsRepository::enqueue(
                EgiszWorkerJobsRepository::TYPE_EVENTLOG_POLL,
                ['local_uids' => $uids],
                0
            );
            return ['ok' => true, 'queued' => true, 'job_id' => $jobId, 'updated' => 0];
        }

        $transport = EgiszRegistry::transport();
        $updated = 0;
        foreach ($list['rows'] as $row) {
            $uid = (string)($row['LOCAL_UID'] ?? '');
            if ($uid === '') {
                continue;
            }
            $st = $transport->fetchStatus($uid);
            if (empty($st['ok'])) {
                continue;
            }
            $mapped = EgiszOutboxRepository::mapRemoteStatus((string)($st['status'] ?? ''));
            EgiszOutboxRepository::updateStatus((int)$row['ID'], $mapped, null, isset($st['raw'])
                ? json_encode($st['raw'], JSON_UNESCAPED_UNICODE)
                : null);
            $updated++;
        }
        return ['ok' => true, 'updated' => $updated];
    }
}
