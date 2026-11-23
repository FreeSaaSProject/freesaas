<?php
declare(strict_types=1);

/**
 * Quiet call stub when a calendar event is created for a phone
 * (no second click required for booked visits).
 */
final class AcquisitionCalendarHooks
{
    public static function register(): void
    {
        EventBus::on('calendar.event.saved', [self::class, 'onEventSaved']);
    }

    /** @param mixed $payload @return mixed */
    public static function onEventSaved($payload)
    {
        if (!is_array($payload) || !CallsRepository::tableReady()) {
            return $payload;
        }
        if (empty($payload['is_new'])) {
            return $payload;
        }
        $eventId = (int)($payload['id'] ?? 0);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        if ($eventId < 1) {
            return $payload;
        }
        $phone = CallsRepository::normalizePhone((string)($data['phone'] ?? $data['PHONE'] ?? ''));
        $clientId = (int)($data['client_id'] ?? $data['ID_USER'] ?? 0);
        if ($phone === '' && $clientId < 1) {
            return $payload;
        }
        try {
            CallsRepository::upsertIngest([
                'provider' => 'calendar',
                'provider_call_id' => 'event:' . $eventId,
                'phone' => $phone,
                'client_id' => $clientId,
                'admin_id' => (int)($data['admin_id'] ?? $data['ID_ADMIN'] ?? Auth::userId()),
                'direction' => CallsRepository::TYPE_IN,
                'channel' => 'calendar',
                'result_id' => CallsRepository::RESULT_BOOKED,
                'dt_filled' => time(),
                'dt_call' => (int)($data['dt_start'] ?? $data['DT_START'] ?? time()),
                'request' => t('acquisition.calls.auto.order_request'),
                'result_text' => t('acquisition.calls.auto.order_result'),
                'event_id' => $eventId,
                'client_kind' => CallsRepository::KIND_UNKNOWN,
            ]);
        } catch (Throwable $e) {
            // Soft: never break event save.
        }
        return $payload;
    }
}
