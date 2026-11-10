<?php
declare(strict_types=1);

/** Calendar chip “review asked” button (moved from poll_invites). */
final class PollSpeechCalendar
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onTimelineEnrich($payload)
    {
        if (!is_array($payload) || !Auth::check() || !Auth::can('poll_invites.edit')) {
            return $payload;
        }
        if (!PollSpeechConfig::oralBtnEnabled()) {
            return $payload;
        }
        if (!Database::tableExists('base_poll_invites')
            || !class_exists('PollInvitesRepository', false)
        ) {
            return $payload;
        }
        $days = is_array($payload['days'] ?? null) ? $payload['days'] : [];
        if ($days === []) {
            return $payload;
        }

        $todayStart = (int)strtotime('today');
        $procIds = [];
        $userIds = [];
        $rangeStart = 0;
        $rangeEnd = 0;
        foreach ($days as $day) {
            if (!is_array($day)) {
                continue;
            }
            $tsStart = (int)($day['ts_start'] ?? 0);
            $tsEnd = (int)($day['ts_end'] ?? 0);
            if ($tsStart > 0 && ($rangeStart < 1 || $tsStart < $rangeStart)) {
                $rangeStart = $tsStart;
            }
            if ($tsEnd > 0 && $tsEnd > $rangeEnd) {
                $rangeEnd = $tsEnd;
            }
            foreach (($day['chips'] ?? []) as $chip) {
                if (!is_array($chip) || (string)($chip['kind'] ?? '') !== 'proc') {
                    continue;
                }
                $pid = (int)($chip['id'] ?? 0);
                $uid = (int)($chip['id_user'] ?? 0);
                if ($pid > 0 && $uid > 0 && (float)($chip['summ'] ?? 0) > 0) {
                    $procIds[$pid] = $pid;
                    $userIds[$uid] = $uid;
                }
            }
        }
        $marked = $procIds !== []
            ? PollInvitesRepository::idsMarkedByProc(array_values($procIds))
            : [];
        $markedClientDay = ($userIds !== [] && $rangeStart > 0 && $rangeEnd > $rangeStart)
            ? PollInvitesRepository::idsMarkedByClientDay(array_values($userIds), $rangeStart, $rangeEnd)
            : [];

        $view = (string)($payload['view'] ?? 'day');
        $csrf = class_exists('Csrf', false) ? Csrf::token() : '';

        $renderMarked = static function (int $inviteId): string {
            return '<div class="calendar-info-actions poll-speech-oral">'
                . '<span class="poll-speech-oral-done">' . e(t('poll_speech.oral.marked')) . '</span> '
                . '<a href="/poll-invites/' . $inviteId . '/edit" data-nav-no-ret>#' . $inviteId . '</a>'
                . '</div>';
        };

        $touched = false;
        foreach ($days as $date => $day) {
            if (!is_array($day) || !isset($day['chips']) || !is_array($day['chips'])) {
                continue;
            }
            $date = (string)$date;
            $backPath = $view === 'week'
                ? '/calendar/week/' . rawurlencode($date)
                : '/calendar/day/' . rawurlencode($date);
            foreach ($day['chips'] as $i => $chip) {
                if (!is_array($chip) || (string)($chip['kind'] ?? '') !== 'proc') {
                    continue;
                }
                $pid = (int)($chip['id'] ?? 0);
                $uid = (int)($chip['id_user'] ?? 0);
                if ($pid < 1 || $uid < 1 || (float)($chip['summ'] ?? 0) <= 0) {
                    continue;
                }
                $dtStart = (int)($chip['dtstart'] ?? 0);
                if ($dtStart > 0 && $dtStart < $todayStart) {
                    continue;
                }
                $dayKey = $uid . '|' . ($dtStart > 0 ? date('Y-m-d', $dtStart) : $date);
                $inviteId = 0;
                if (isset($marked[$pid])) {
                    $inviteId = (int)$marked[$pid];
                } elseif (isset($markedClientDay[$dayKey])) {
                    $inviteId = (int)$markedClientDay[$dayKey];
                }
                $touched = true;
                if ($inviteId > 0) {
                    $days[$date]['chips'][$i]['actions_html'] = $renderMarked($inviteId);
                    $cls = trim((string)($chip['class'] ?? ''));
                    $days[$date]['chips'][$i]['class'] = trim($cls . ' has-oral-mark');
                    continue;
                }
                $days[$date]['chips'][$i]['actions_html'] = '<div class="calendar-info-actions poll-speech-oral">'
                    . '<form method="post" action="/poll-invites/oral-asked" class="inline-form" data-nav-no-ret>'
                    . '<input type="hidden" name="csrf" value="' . e($csrf) . '">'
                    . '<input type="hidden" name="id_proc" value="' . $pid . '">'
                    . '<input type="hidden" name="oral_ret" value="' . e($backPath) . '">'
                    . '<button type="submit" class="btn-secondary btn-sm poll-speech-oral-btn">'
                    . e(t('poll_speech.oral.btn'))
                    . '</button>'
                    . '</form>'
                    . '</div>';
            }
        }

        if ($touched) {
            Assets::need('poll_speech');
        }

        $payload['days'] = $days;
        return $payload;
    }
}
