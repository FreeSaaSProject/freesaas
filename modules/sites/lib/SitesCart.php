<?php
declare(strict_types=1);

/**
 * Session cart for the public storefront.
 *
 * @phpstan-type CartLine array{id:int,name:string,price:float,price_original:float,qty:int}
 */
final class SitesCart
{
    private const KEY = 'sites_cart';

    /** @return list<CartLine> */
    public static function lines(): array
    {
        $raw = $_SESSION[self::KEY] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string)($row['name'] ?? ''),
                'price' => (float)($row['price'] ?? 0),
                'price_original' => (float)($row['price_original'] ?? $row['price'] ?? 0),
                'qty' => max(1, (int)($row['qty'] ?? 1)),
            ];
        }
        return $out;
    }

    /** Flatten qty into repeated lines for OrdersRepository::encodeCart. */
    public static function orderLines(): array
    {
        $flat = [];
        foreach (self::lines() as $line) {
            for ($i = 0; $i < $line['qty']; $i++) {
                $flat[] = [
                    'id' => $line['id'],
                    'name' => $line['name'],
                    'price' => $line['price'],
                    'price_original' => $line['price_original'],
                ];
            }
        }
        return $flat;
    }

    public static function count(): int
    {
        $n = 0;
        foreach (self::lines() as $line) {
            $n += $line['qty'];
        }
        return $n;
    }

    public static function total(): float
    {
        $sum = 0.0;
        foreach (self::lines() as $line) {
            $sum += $line['price'] * $line['qty'];
        }
        return round($sum, 2);
    }

    public static function add(int $productId, string $name, float $price, float $priceOriginal = 0.0, int $qty = 1): void
    {
        if ($productId < 1) {
            return;
        }
        $qty = max(1, $qty);
        if ($priceOriginal <= 0) {
            $priceOriginal = $price;
        }
        $lines = self::lines();
        foreach ($lines as $i => $line) {
            if ($line['id'] === $productId) {
                $lines[$i]['qty'] += $qty;
                self::save($lines);
                return;
            }
        }
        $lines[] = [
            'id' => $productId,
            'name' => $name,
            'price' => $price,
            'price_original' => $priceOriginal,
            'qty' => $qty,
        ];
        self::save($lines);
    }

    public static function remove(int $productId): void
    {
        $lines = [];
        foreach (self::lines() as $line) {
            if ($line['id'] !== $productId) {
                $lines[] = $line;
            }
        }
        self::save($lines);
    }

    public static function clear(): void
    {
        self::save([]);
    }

    /**
     * One-shot confirmation card after successful booking checkout.
     *
     * @param array{
     *   id:int,
     *   when:string,
     *   date?:string,
     *   time?:string,
     *   master?:string,
     *   comment?:string,
     *   phone?:string
     * } $data
     */
    public static function setBookingConfirm(array $data): void
    {
        $_SESSION['sites_booking_confirm'] = $data;
    }

    /** @return array<string,mixed>|null */
    public static function takeBookingConfirm(): ?array
    {
        if (empty($_SESSION['sites_booking_confirm']) || !is_array($_SESSION['sites_booking_confirm'])) {
            return null;
        }
        $row = $_SESSION['sites_booking_confirm'];
        unset($_SESSION['sites_booking_confirm']);
        return $row;
    }

    /** Storefront AJAX / JSON clients (fetch, XHR, format=json). */
    public static function wantsAjax(): bool
    {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest') {
            return true;
        }
        if (isset($_POST['ajax']) || isset($_GET['ajax'])) {
            return true;
        }
        if (class_exists('AgentHttp', false) && AgentHttp::wantsJson()) {
            return true;
        }
        return false;
    }

    /**
     * @return array{count:int,total:float,lines:list<CartLine>}
     */
    public static function snapshot(): array
    {
        return [
            'count' => self::count(),
            'total' => self::total(),
            'lines' => self::lines(),
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function emitJson(array $payload, int $http = 200): void
    {
        if (!headers_sent()) {
            http_response_code($http);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** @param list<CartLine> $lines */
    private static function save(array $lines): void
    {
        $_SESSION[self::KEY] = array_values($lines);
    }
}
