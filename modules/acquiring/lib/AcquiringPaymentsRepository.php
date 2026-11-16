<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
final class AcquiringPaymentsRepository
{
    public static function ready(): bool
    {
        return Database::tableExists('base_acquiring_payments');
    }

    /**
     * @param array{order_id?:int,client_id?:int,status?:string,provider?:string,limit?:int,offset?:int} $opts
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function list(array $opts = []): array
    {
        if (!self::ready()) {
            return ['items' => [], 'total' => 0];
        }
        $where = ['1=1'];
        $params = [];
        if (($oid = (int)($opts['order_id'] ?? 0)) > 0) {
            $where[] = 'ORDER_ID = ?';
            $params[] = $oid;
        }
        if (($cid = (int)($opts['client_id'] ?? 0)) > 0) {
            $where[] = 'CLIENT_ID = ?';
            $params[] = $cid;
        }
        $status = trim((string)($opts['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'STATUS = ?';
            $params[] = $status;
        }
        $provider = trim((string)($opts['provider'] ?? ''));
        if ($provider !== '') {
            $where[] = 'PROVIDER = ?';
            $params[] = $provider;
        }
        $sqlWhere = implode(' AND ', $where);
        $pdo = Database::pdo();
        $stc = $pdo->prepare("SELECT COUNT(*) FROM base_acquiring_payments WHERE $sqlWhere");
        $stc->execute($params);
        $total = (int)$stc->fetchColumn();
        $limit = max(1, min(200, (int)($opts['limit'] ?? 50)));
        $offset = max(0, (int)($opts['offset'] ?? 0));
        $st = $pdo->prepare(
            "SELECT ID AS id, ORDER_ID AS order_id, CLIENT_ID AS client_id, PROVIDER AS provider,
                    PROVIDER_REF AS provider_ref, AMOUNT AS amount, CURRENCY AS currency, STATUS AS status,
                    PAY_URL AS pay_url, META_JSON AS meta_json, DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_acquiring_payments WHERE $sqlWhere
             ORDER BY ID DESC LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        $items = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $items[] = self::normalize($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::ready()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ORDER_ID AS order_id, CLIENT_ID AS client_id, PROVIDER AS provider,
                    PROVIDER_REF AS provider_ref, AMOUNT AS amount, CURRENCY AS currency, STATUS AS status,
                    PAY_URL AS pay_url, META_JSON AS meta_json, DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_acquiring_payments WHERE ID = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalize($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByProviderRef(string $provider, string $ref): ?array
    {
        if ($provider === '' || $ref === '' || !self::ready()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT ID AS id, ORDER_ID AS order_id, CLIENT_ID AS client_id, PROVIDER AS provider,
                    PROVIDER_REF AS provider_ref, AMOUNT AS amount, CURRENCY AS currency, STATUS AS status,
                    PAY_URL AS pay_url, META_JSON AS meta_json, DT_CREATE AS dt_create, DT_UPDATE AS dt_update
             FROM base_acquiring_payments WHERE PROVIDER = ? AND PROVIDER_REF = ? ORDER BY ID DESC LIMIT 1'
        );
        $st->execute([$provider, $ref]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? self::normalize($row) : null;
    }

    public static function isOrderPaid(int $orderId): bool
    {
        if ($orderId < 1 || !self::ready()) {
            return false;
        }
        $st = Database::pdo()->prepare(
            "SELECT 1 FROM base_acquiring_payments WHERE ORDER_ID = ? AND STATUS = 'succeeded' LIMIT 1"
        );
        $st->execute([$orderId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): int
    {
        $st = Database::pdo()->prepare(
            'INSERT INTO base_acquiring_payments
             (ORDER_ID, CLIENT_ID, PROVIDER, PROVIDER_REF, AMOUNT, CURRENCY, STATUS, PAY_URL, META_JSON, DT_CREATE, DT_UPDATE)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $now = time();
        $meta = $data['meta'] ?? null;
        $st->execute([
            (int)($data['order_id'] ?? 0),
            (int)($data['client_id'] ?? 0),
            (string)($data['provider'] ?? ''),
            (string)($data['provider_ref'] ?? ''),
            round((float)($data['amount'] ?? 0), 2),
            (string)($data['currency'] ?? 'RUB'),
            (string)($data['status'] ?? 'pending'),
            (string)($data['pay_url'] ?? ''),
            is_array($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $now,
            $now,
        ]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function updateStatus(int $id, string $status, ?array $meta = null): void
    {
        if ($id < 1) {
            return;
        }
        if ($meta !== null) {
            $st = Database::pdo()->prepare(
                'UPDATE base_acquiring_payments SET STATUS = ?, META_JSON = ?, DT_UPDATE = ? WHERE ID = ?'
            );
            $st->execute([$status, json_encode($meta, JSON_UNESCAPED_UNICODE), time(), $id]);
            return;
        }
        $st = Database::pdo()->prepare(
            'UPDATE base_acquiring_payments SET STATUS = ?, DT_UPDATE = ? WHERE ID = ?'
        );
        $st->execute([$status, time(), $id]);
    }

    public static function logWebhook(string $provider, int $paymentId, string $payload): void
    {
        if (!Database::tableExists('base_acquiring_webhook_log')) {
            return;
        }
        $st = Database::pdo()->prepare(
            'INSERT INTO base_acquiring_webhook_log (PROVIDER, PAYMENT_ID, PAYLOAD, DT_CREATE) VALUES (?,?,?,?)'
        );
        $st->execute([$provider, $paymentId, $payload, time()]);
    }

    /** @param array<string,mixed> $row */
    private static function normalize(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['order_id'] = (int)$row['order_id'];
        $row['client_id'] = (int)$row['client_id'];
        $row['amount'] = (float)$row['amount'];
        $row['dt_create'] = (int)$row['dt_create'];
        $row['dt_update'] = (int)$row['dt_update'];
        $meta = $row['meta_json'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $row['meta'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['meta'] = [];
        }
        unset($row['meta_json']);
        return $row;
    }
}
