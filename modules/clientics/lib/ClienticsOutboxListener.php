<?php
declare(strict_types=1);

/** Enqueue FreeSaaS changes for Clientics push; skip echo when sync_origin=clientics. */
final class ClienticsOutboxListener
{
    /** @param array<string,mixed> $payload */
    public static function onClientsChanged(array $payload): array
    {
        if (!ClienticsConfig::pushEnabled() || self::isEcho($payload)) {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        if ($id < 1 || !class_exists('ClientsRepository', false)) {
            return $payload;
        }
        $row = ClientsRepository::find($id);
        if ($row === null) {
            return $payload;
        }
        ClienticsOutboxRepository::enqueue('client', $id, 'upsert', [
            'fields' => [
                'phone' => (string)($row['tel'] ?? ''),
                'first_name' => (string)($row['pname'] ?? ''),
                'second_name' => (string)($row['surname'] ?? ''),
                'patron_name' => (string)($row['patronymic'] ?? ''),
                'email' => (string)($row['mail'] ?? ''),
            ],
        ]);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    public static function onCalendarEventSaved(array $payload): array
    {
        if (!ClienticsConfig::pushEnabled() || !ClienticsConfig::appointmentSotIsFreesaas() || self::isEcho($payload)) {
            return $payload;
        }
        $id = (int)($payload['id'] ?? 0);
        if ($id < 1 || !class_exists('EventsRepository', false)) {
            return $payload;
        }
        $row = EventsRepository::find($id);
        if ($row === null || !empty($row['is_block'])) {
            return $payload;
        }
        $accountId = ClienticsConfig::accountId();
        $clientExt = null;
        $clientLocal = (int)($row['id_user'] ?? 0);
        if ($clientLocal > 0) {
            $clientExt = ClienticsIdMapRepository::findExtId($accountId, 'client', $clientLocal);
        }
        $executorExt = null;
        $opLocal = (int)($row['id_operator'] ?? 0);
        if ($opLocal > 0) {
            $executorExt = ClienticsIdMapRepository::findExtId($accountId, 'user', $opLocal);
        }
        ClienticsOutboxRepository::enqueue('appointment', $id, 'upsert', [
            'client_ext_id' => $clientExt,
            'executor_ext_id' => $executorExt,
            'dtstart' => (int)($row['dtstart'] ?? 0),
            'dtend' => (int)($row['dtend'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'descr' => (string)($row['descr'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'status' => 'confirmed',
        ]);
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private static function isEcho(array $payload): bool
    {
        if ((string)($payload['sync_origin'] ?? '') === 'clientics') {
            return true;
        }
        return ClienticsSyncContext::isExternal() && ClienticsSyncContext::origin() === 'clientics';
    }
}
