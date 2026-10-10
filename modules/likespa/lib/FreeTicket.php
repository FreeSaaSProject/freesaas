<?php
declare(strict_types=1);

/**
 * LikeSpa FREE_TICKET overlay: threshold from base_system.FREE_TICKET.
 * Not today; sum must reach the threshold (legacy cartProcessFull).
 */
final class FreeTicket
{
    public static function threshold(): int
    {
        if (!class_exists(SystemRepository::class, false)) {
            return 0;
        }
        $row = SystemRepository::get();
        if (!is_array($row)) {
            return 0;
        }
        return max(0, (int)($row['FREE_TICKET'] ?? 0));
    }

    public static function eligible(float $sum): bool
    {
        $th = self::threshold();
        return $th > 0 && $sum + 0.0001 >= $th;
    }

    /** Same calendar day as now (local) — free ticket not allowed. */
    public static function isToday(int $dtStart): bool
    {
        if ($dtStart < 1) {
            return true;
        }
        $today = (int)mktime(0, 0, 0);
        $day = (int)mktime(0, 0, 0, (int)date('n', $dtStart), (int)date('j', $dtStart), (int)date('Y', $dtStart));
        return $day === $today;
    }

    /**
     * @param array<string,mixed> $payload EventBus sites.cart.booking
     * @return array<string,mixed>
     */
    public static function onCartBooking(array $payload): array
    {
        $total = (float)($payload['total'] ?? 0);
        $th = self::threshold();
        $eligible = self::eligible($total);
        $payload['free_ticket_threshold'] = $th;
        $payload['free_ticket_eligible'] = $eligible;
        if ($th < 1) {
            $payload['free_ticket_hint'] = '';
            $payload['free_ticket_not_today'] = '';
            return $payload;
        }
        if ($eligible) {
            $payload['free_ticket_hint'] = t('likespa.free_ticket.hint_ok', ['sum' => $th]);
        } else {
            $need = max(0, (int)ceil($th - $total));
            $payload['free_ticket_hint'] = t('likespa.free_ticket.hint_need', [
                'need' => $need,
                'sum' => $th,
            ]);
        }
        $payload['free_ticket_not_today'] = t('likespa.free_ticket.not_today');
        return $payload;
    }

    /**
     * @param array<string,mixed> $payload EventBus booking.book.before
     * @return array<string,mixed>
     */
    public static function onBookBefore(array $payload): array
    {
        $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
        $orderPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $want = !empty($input['free_ticket']) || !empty($orderPayload['free_ticket']);
        $sum = (float)($orderPayload['summ'] ?? 0);
        $dt = (int)($orderPayload['dt_event'] ?? $input['dt_start'] ?? 0);

        if (!$want) {
            $orderPayload['free_ticket'] = 0;
            $payload['payload'] = $orderPayload;
            return $payload;
        }

        if (!self::eligible($sum)) {
            $payload['error'] = t('likespa.free_ticket.err_sum', ['sum' => self::threshold()]);
            return $payload;
        }
        if (self::isToday($dt)) {
            $payload['error'] = t('likespa.free_ticket.err_today');
            return $payload;
        }

        $orderPayload['free_ticket'] = 1;
        $payload['payload'] = $orderPayload;
        return $payload;
    }
}
