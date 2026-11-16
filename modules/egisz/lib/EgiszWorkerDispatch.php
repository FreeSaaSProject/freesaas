<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * When worker_enabled=1, N3 SOAP is enqueued for the edge plugin instead of
 * running SoapClient on the PHP host (shared hosting has no VPN).
 * Disable: worker_enabled=0 and/or omit `egisz` from workers host.local.json
 * enabled_plugins.
 */

final class EgiszWorkerDispatch
{
    public static function enabled(): bool
    {
        return (string)Config::get('egisz', 'worker_enabled', '0') === '1'
            && EgiszWorkerAuth::isConfigured()
            && EgiszWorkerJobsRepository::tableReady();
    }

    /**
     * Apply a finished worker job: update outbox + client identity side-effects.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed>|null $result
     */
    public static function applyResult(array $job, ?array $result, ?string $error): void
    {
        $type = (string)($job['TYPE'] ?? '');
        $outboxId = (int)($job['OUTBOX_ID'] ?? 0);
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        if ($payload === [] && !empty($job['PAYLOAD_JSON'])) {
            $decoded = json_decode((string)$job['PAYLOAD_JSON'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        if ($error !== null && $error !== '') {
            if ($outboxId > 0) {
                EgiszOutboxRepository::updateStatus(
                    $outboxId,
                    'error',
                    $error,
                    $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null
                );
            }
            return;
        }

        if ($type === EgiszWorkerJobsRepository::TYPE_ADD_PATIENT) {
            $remoteId = (string)($result['remote_id'] ?? $result['idPatient'] ?? '');
            $clientId = (int)($payload['client_id'] ?? 0);
            $idMis = (string)($payload['patient']['id_mis'] ?? ('fs-client-' . $clientId));
            if ($clientId > 0 && $remoteId !== '') {
                EgiszClientIdsRepository::setN3Ids($clientId, $remoteId, $idMis);
            }
            if ($outboxId > 0) {
                EgiszOutboxRepository::updateStatus(
                    $outboxId,
                    'sent',
                    null,
                    $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
                    $remoteId !== '' ? $remoteId : null
                );
            }
            return;
        }

        if ($type === EgiszWorkerJobsRepository::TYPE_ADD_CASE) {
            $remoteId = (string)($result['remote_id'] ?? $result['idCase'] ?? '');
            if ($outboxId > 0) {
                EgiszOutboxRepository::updateStatus(
                    $outboxId,
                    'sent',
                    null,
                    $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : null,
                    $remoteId !== '' ? $remoteId : null
                );
            }
            return;
        }

        if ($type === EgiszWorkerJobsRepository::TYPE_EVENTLOG_POLL && is_array($result['items'] ?? null)) {
            foreach ($result['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $uid = (string)($item['local_uid'] ?? '');
                if ($uid === '') {
                    continue;
                }
                $st = EgiszOutboxRepository::mapRemoteStatus((string)($item['status'] ?? ''));
                $row = EgiszOutboxRepository::findByLocalUid($uid);
                if ($row) {
                    EgiszOutboxRepository::updateStatus(
                        (int)$row['ID'],
                        $st,
                        null,
                        json_encode($item, JSON_UNESCAPED_UNICODE)
                    );
                }
            }
        }
    }
}
