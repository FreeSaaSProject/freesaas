<?php
declare(strict_types=1);

final class CourseEnrollService
{
    public static function enrollFromOrder(int $orderId, int $clientId): int
    {
        if ($orderId < 1 || $clientId < 1 || !class_exists('OrdersRepository', false)) {
            return 0;
        }
        $order = OrdersRepository::find($orderId);
        if ($order === null) {
            return 0;
        }
        $productIds = self::productIdsFromOrder($order);
        $granted = 0;
        foreach ($productIds as $pid) {
            $prog = CourseProgramsRepository::findByProductId($pid);
            if ($prog === null) {
                continue;
            }
            $aud = (string)$prog['audience'];
            if ($aud === 'staff') {
                continue;
            }
            CourseEnrollmentsRepository::grant([
                'program_id' => (int)$prog['id'],
                'subject_type' => 'client',
                'subject_id' => $clientId,
                'source' => 'order',
                'order_id' => $orderId,
                'access_days' => (int)$prog['access_days'],
            ]);
            $granted++;
        }
        return $granted;
    }

    /** @param array<string,mixed> $order @return list<int> */
    private static function productIdsFromOrder(array $order): array
    {
        $ids = [];
        $lines = $order['cart_lines'] ?? null;
        if (!is_array($lines)) {
            $cart = $order['cart'] ?? '';
            if (is_string($cart) && $cart !== '') {
                $decoded = json_decode($cart, true);
                $lines = is_array($decoded) ? $decoded : [];
            } else {
                $lines = [];
            }
        }
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $id = (int)($line['id'] ?? $line['id_good'] ?? $line['product_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}
