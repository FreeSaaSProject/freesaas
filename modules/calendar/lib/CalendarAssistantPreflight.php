<?php
declare(strict_types=1);

/**
 * Dry-run calendar.events.create / update drafts (EventBus assistant.write.preflight).
 * A draft that would fail on Confirm is not queued; the model gets the reason and re-plans.
 */
final class CalendarAssistantPreflight
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreflight(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId !== 'calendar.events.create' && $opId !== 'calendar.events.update') {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        if (strtolower(trim((string)($args['action'] ?? ''))) === 'delete') {
            return $payload;
        }

        $row = null;
        $post = $args;
        if ($opId === 'calendar.events.update') {
            $row = EventsRepository::find((int)($args['id'] ?? 0));
            if ($row === null) {
                return $payload;
            }
            $post = EventsRepository::patchPost($args, $row);
        }

        $data = null;
        try {
            $data = EventsRepository::dataFromPost($post);
            // Speaker limited to own events: the confirmer's flags decide the operator gate.
            $myOnly = !empty(Auth::statusFlags()['EVT_MY_ONLY']);
            $checkOperator = !$myOnly || (int)$data['id_operator'] === Auth::userId();
            EventsRepository::validateDraft($data, $row, $checkOperator);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            if ($data !== null && $message === t('calendar.event.err.operator_not_allowed')) {
                $message = self::explainNotAllowed($data, $row);
            }
            $blockers = is_array($payload['blockers'] ?? null) ? $payload['blockers'] : [];
            $blockers[] = ['code' => 'calendar_invalid', 'message' => $message];
            $payload['blockers'] = $blockers;
            $payload['replan'] = true;
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $row
     */
    private static function explainNotAllowed(array $data, ?array $row): string
    {
        $opId = (int)$data['id_operator'];
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00', (int)$data['dtstart']));
        $who = self::acronym($opId);
        $parts = [t('calendar.preflight.not_on_shift', [
            'who' => $who,
            'date' => date('d.m.Y', $dayStart),
        ])];

        $keep = $row !== null ? (int)($row['id_operator'] ?? 0) : 0;
        $onShift = [];
        foreach (array_keys(EventsRepository::staffChoicesForDay($dayStart, $keep)) as $id) {
            $onShift[] = self::acronym((int)$id);
        }
        if ($onShift !== []) {
            $parts[] = t('calendar.preflight.on_shift', ['list' => implode(', ', $onShift)]);
        }

        $next = self::nextShiftDay($opId, $dayStart);
        if ($next > 0) {
            $parts[] = t('calendar.preflight.next_shift', ['who' => $who, 'date' => date('d.m.Y', $next)]);
        }
        if ($opId === Auth::userId()) {
            $parts[] = t('calendar.preflight.speaker_as_operator');
        }
        return implode(' ', $parts);
    }

    /** Next day (within two weeks) where the operator has a procedure shift; 0 if none. */
    private static function nextShiftDay(int $operatorId, int $dayStart): int
    {
        if ($operatorId < 1 || !class_exists('WorkweeksRepository', false)) {
            return 0;
        }
        $from = $dayStart + 86400;
        $byDay = WorkweeksRepository::listForDayRange($from, $from + 14 * 86400);
        ksort($byDay);
        foreach ($byDay as $day => $rows) {
            foreach ((array)$rows as $sh) {
                if ((int)($sh['id_operator'] ?? 0) === $operatorId && empty($sh['assist_team'])) {
                    return (int)$day;
                }
            }
        }
        return 0;
    }

    private static function acronym(int $userId): string
    {
        if ($userId < 1) {
            return '—';
        }
        if (class_exists('UsersRepository', false)) {
            $a = trim(UsersRepository::acronymsList([$userId]));
            if ($a !== '') {
                return $a;
            }
        }
        return '#' . $userId;
    }
}
