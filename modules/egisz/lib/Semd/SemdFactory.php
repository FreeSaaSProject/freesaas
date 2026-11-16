<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Resolve builders and assemble context from docs / clients / egisz identity. */
final class SemdFactory
{
    public static function builder(string $kind): SemdBuilder
    {
        if ($kind === 'manip') {
            return new ManipulationProtocolBuilder();
        }
        return new ConsultationProtocolBuilder();
    }

    /**
     * Build SEMD from a docs instance id (medcard_visit / specialty visit).
     *
     * @return SemdDocument
     */
    public static function fromInstance(int $instanceId, string $kind = 'consult'): SemdDocument
    {
        if ($instanceId < 1 || !class_exists('DocsInstancesRepository', false)) {
            return self::emptyError($kind, t('egisz.err.instance'));
        }
        $row = DocsInstancesRepository::find($instanceId);
        if ($row === null) {
            return self::emptyError($kind, t('egisz.err.instance'));
        }
        $kindDoc = (string)($row['kind'] ?? '');
        if (!in_array($kindDoc, ['medcard_visit', 'medcard_cosmo'], true)
            && strpos($kindDoc, 'medcard_') !== 0
        ) {
            // allow any visit-like medical kind with payload
        }

        $payload = [];
        if (!empty($row['payload']) && is_array($row['payload'])) {
            $payload = $row['payload'];
        } elseif (!empty($row['PAYLOAD'])) {
            $decoded = json_decode((string)$row['PAYLOAD'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $clientId = (int)($row['id_client'] ?? $row['ID_CLIENT'] ?? 0);
        $eventId = (int)($row['id_event'] ?? $row['ID_EVENT'] ?? 0);
        $doctorId = (int)($payload['doctor_id'] ?? 0);

        $client = $clientId > 0 && class_exists('ClientsRepository', false)
            ? ClientsRepository::find($clientId)
            : null;
        $profilePayload = self::profilePayload($clientId);
        $ids = EgiszClientIdsRepository::resolveForClient($clientId, $profilePayload);

        $patient = [
            'surname' => (string)($client['surname'] ?? ''),
            'pname' => (string)($client['pname'] ?? ''),
            'patronymic' => (string)($client['patronymic'] ?? ''),
            'name' => (string)($client['name'] ?? ''),
            'birthday' => (string)($client['birthday'] ?? ''),
            'gender' => isset($client['gender']) ? (string)$client['gender'] : '',
            'snils' => $ids['snils'],
            'oms_policy' => $ids['oms_policy'],
            'oms_insurer' => $ids['oms_insurer'],
        ];

        $doctor = ['id' => $doctorId, 'name' => '', 'snils' => '', 'position_code' => '', 'specialty_code' => ''];
        if ($doctorId > 0 && class_exists('UsersRepository', false)) {
            $u = UsersRepository::find($doctorId);
            $staff = EgiszStaffRepository::resolve($doctorId);
            $doctor['name'] = (string)($u['name'] ?? '');
            $doctor['snils'] = $staff['snils'];
            $doctor['position_code'] = $staff['position_code'];
            $doctor['specialty_code'] = $staff['specialty_code'];
            $doctor['role_code'] = $staff['role_code'];
        }

        $org = class_exists('DocsConfig', false) ? DocsConfig::org() : [];
        $event = ['id' => $eventId, 'date' => ''];
        if ($eventId > 0 && class_exists('EventsRepository', false) && method_exists('EventsRepository', 'find')) {
            $ev = EventsRepository::find($eventId);
            if (is_array($ev)) {
                $ts = (int)($ev['dtstart'] ?? 0);
                if ($ts > 0) {
                    $event['date'] = date('Y-m-d H:i:s', $ts);
                }
            }
        }
        if ($event['date'] === '' && !empty($row['dt_create'])) {
            $event['date'] = date('Y-m-d', (int)$row['dt_create']);
        } elseif ($event['date'] === '' && !empty($row['DT_CREATE'])) {
            $event['date'] = date('Y-m-d', (int)$row['DT_CREATE']);
        }

        $instance = [
            'id' => (int)($row['id'] ?? $row['ID'] ?? $instanceId),
            'id_client' => $clientId,
            'id_event' => $eventId,
            'kind' => $kindDoc,
            'payload' => $payload,
        ];
        $ctx = [
            'client_id' => $clientId,
            'event_id' => $eventId,
            'patient' => $patient,
            'doctor' => $doctor,
            'org' => $org,
            'event' => $event,
            'payload' => $payload,
        ];

        $doc = self::builder($kind)->build($instance, $ctx);
        EventBus::emit('egisz.semd.built', [
            'document' => $doc->toArray(),
            'instance_id' => $instanceId,
            'kind' => $kind,
        ]);
        return $doc;
    }

    /** @return array<string,mixed>|null */
    private static function profilePayload(int $clientId): ?array
    {
        if ($clientId < 1 || !class_exists('DocsInstancesRepository', false)) {
            return null;
        }
        if (!method_exists('DocsInstancesRepository', 'latestForClient')) {
            return null;
        }
        $prof = DocsInstancesRepository::latestForClient($clientId, 'medcard_profile');
        if (!is_array($prof)) {
            return null;
        }
        return !empty($prof['payload']) && is_array($prof['payload']) ? $prof['payload'] : null;
    }

    private static function emptyError(string $kind, string $error): SemdDocument
    {
        return new SemdDocument(
            SemdXml::uuid(),
            $kind,
            $kind === 'manip' ? EgiszConfig::semdManipOid() : EgiszConfig::semdConsultOid(),
            '',
            [],
            [$error],
            [],
            0,
            0,
            0,
            0
        );
    }
}
