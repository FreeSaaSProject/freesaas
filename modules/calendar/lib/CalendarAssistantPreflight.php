<?php
declare(strict_types=1);

/**
 * Dry-run calendar.events.create / update drafts (EventBus assistant.write.preflight).
 * A draft that would fail on Confirm is not queued (replan) or is blocked with choice buttons.
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
            $myOnly = !empty(Auth::statusFlags()['EVT_MY_ONLY']);
            $checkOperator = !$myOnly || (int)$data['id_operator'] === Auth::userId();
            EventsRepository::validateDraft($data, $row, $checkOperator);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $choices = is_array($payload['choices'] ?? null) ? $payload['choices'] : [];
            if ($data !== null && $message === t('calendar.event.err.operator_not_allowed')) {
                $fix = self::explainNotAllowed($data, $row, $args, (string)($payload['user_text'] ?? ''), $opId);
                $message = $fix['message'];
                foreach ($fix['choices'] as $c) {
                    $choices[] = $c;
                }
            }
            $blockers = is_array($payload['blockers'] ?? null) ? $payload['blockers'] : [];
            $blockers[] = ['code' => 'calendar_invalid', 'message' => $message];
            $payload['blockers'] = $blockers;
            $payload['choices'] = $choices;
            // Choices → Confirm hidden, buttons shown. No choices → model must re-plan this turn.
            $payload['replan'] = $choices === [];
        }
        return $payload;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $row
     * @param array<string,mixed> $args
     * @return array{message:string,choices:list<array{op_id:string,args:array<string,mixed>,label:string}>}
     */
    private static function explainNotAllowed(
        array $data,
        ?array $row,
        array $args,
        string $userText,
        string $opId
    ): array {
        $opUserId = (int)$data['id_operator'];
        $dayStart = (int)strtotime(date('Y-m-d 00:00:00', (int)$data['dtstart']));
        $who = self::acronym($opUserId);
        $parts = [t('calendar.preflight.not_on_shift', [
            'who' => $who,
            'date' => date('d.m.Y', $dayStart),
        ])];

        $keep = $row !== null ? (int)($row['id_operator'] ?? 0) : 0;
        $onShift = EventsRepository::staffChoicesForDay($dayStart, $keep);
        $onShiftAcronyms = [];
        foreach (array_keys($onShift) as $id) {
            $onShiftAcronyms[(int)$id] = self::acronym((int)$id);
        }
        if ($onShiftAcronyms !== []) {
            $parts[] = t('calendar.preflight.on_shift', ['list' => implode(', ', array_values($onShiftAcronyms))]);
        }

        $next = self::nextShiftDay($opUserId, $dayStart);
        if ($next > 0) {
            $parts[] = t('calendar.preflight.next_shift', ['who' => $who, 'date' => date('d.m.Y', $next)]);
        }
        if ($opUserId === Auth::userId()) {
            $parts[] = t('calendar.preflight.speaker_as_operator');
        }

        $needles = self::acronymNeedles($userText, $args);
        $matched = [];
        foreach ($onShiftAcronyms as $id => $acro) {
            $acroU = self::upper($acro);
            foreach ($needles as $n) {
                if ($n !== '' && $n === $acroU) {
                    $matched[$id] = $acro;
                    break;
                }
            }
        }

        $choices = [];
        if ($matched !== []) {
            $parts[] = t('calendar.preflight.use_matched', [
                'list' => implode(', ', array_map(
                    static fn (int $id, string $a): string => $a . ' (id_operator=' . $id . ')',
                    array_keys($matched),
                    array_values($matched)
                )),
            ]);
            foreach ($matched as $id => $acro) {
                $fixed = $args;
                $fixed['id_operator'] = $id;
                // Acronym was a person label, not event text.
                if (isset($fixed['descr']) && self::upper(trim((string)$fixed['descr'])) === self::upper($acro)) {
                    unset($fixed['descr']);
                }
                if (isset($fixed['name']) && self::upper(trim((string)$fixed['name'])) === self::upper($acro)) {
                    unset($fixed['name']);
                }
                $choices[] = [
                    'op_id' => $opId,
                    'args' => $fixed,
                    'label' => t('calendar.preflight.choice_block', [
                        'who' => $acro,
                        'range' => self::rangeLabel($data),
                    ]),
                ];
                if (count($choices) >= 5) {
                    break;
                }
            }
            $parts[] = t('calendar.preflight.pick_or_recall');
        } else {
            $parts[] = t('calendar.preflight.resolve_hint');
        }

        return ['message' => implode(' ', $parts), 'choices' => $choices];
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string> uppercase acronyms / short tokens
     */
    private static function acronymNeedles(string $userText, array $args): array
    {
        $chunks = [$userText];
        foreach (['descr', 'name'] as $k) {
            if (isset($args[$k]) && is_scalar($args[$k])) {
                $chunks[] = (string)$args[$k];
            }
        }
        if (isset($args['content']) && is_array($args['content'])) {
            foreach ($args['content'] as $c) {
                if (is_array($c) && isset($c['name'])) {
                    $chunks[] = (string)$c['name'];
                } elseif (is_scalar($c)) {
                    $chunks[] = (string)$c;
                }
            }
        }
        $out = [];
        foreach ($chunks as $chunk) {
            if (preg_match_all('/[A-Za-zА-Яа-яЁё]{2,5}/u', $chunk, $m)) {
                foreach ($m[0] as $tok) {
                    $u = self::upper($tok);
                    // Skip common Russian filler words that look like acronyms.
                    if (in_array($u, ['С', 'НА', 'ДО', 'ОТ', 'ИЗ', 'ПО', 'БЛОК', 'СМЕН', 'СМЕНУ', 'СМЕНЫ', 'ПОСТАВЬ', 'УБЕРИ'], true)) {
                        continue;
                    }
                    if (!in_array($u, $out, true)) {
                        $out[] = $u;
                    }
                }
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function rangeLabel(array $data): string
    {
        $a = (int)($data['dtstart'] ?? 0);
        $b = (int)($data['dtend'] ?? 0);
        if ($a < 1) {
            return '';
        }
        return date('H:i', $a) . '–' . ($b > $a ? date('H:i', $b) : '…');
    }

    private static function upper(string $s): string
    {
        $s = trim($s);
        return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    }

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
