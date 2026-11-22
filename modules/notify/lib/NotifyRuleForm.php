<?php
declare(strict_types=1);

/**
 * Rule edit form ⇄ repository input. The form uses flat rows (field/op/value, kind/value/channels,
 * minute-based stage timing); agents may post conditions/targets/stages as JSON strings instead.
 */
final class NotifyRuleForm
{
    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function fromPost(array $post): array
    {
        $in = $post;
        foreach (['conditions', 'targets', 'stages'] as $k) {
            if (isset($post[$k]) && is_string($post[$k])) {
                continue; // JSON from agents is validated by the repository
            }
            $rows = is_array($post[$k] ?? null) ? $post[$k] : [];
            if ($k === 'targets') {
                $in[$k] = self::targetsFromRows($rows);
            } elseif ($k === 'stages') {
                $in[$k] = self::stagesFromRows($rows);
            } else {
                $in[$k] = array_values(array_filter($rows, static fn ($r) => is_array($r) && trim((string)($r['field'] ?? '')) !== ''));
            }
        }
        $in['enabled'] = !empty($post['enabled']) ? 1 : 0;
        $in['stop_on_match'] = !empty($post['stop_on_match']) ? 1 : 0;
        return $in;
    }

    /**
     * @param array<int|string,mixed> $rows
     * @return list<array<string,mixed>>
     */
    public static function targetsFromRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $t = is_array($r) ? self::targetFromRow($r) : null;
            if ($t !== null) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $r kind, value, platform, channels[], strategy
     * @return array<string,mixed>|null
     */
    public static function targetFromRow(array $r): ?array
    {
        $kind = (string)($r['kind'] ?? '');
        $value = trim((string)($r['value'] ?? ''));
        if ($kind === '' || ($value === '' && $kind !== 'on_shift')) {
            return null;
        }
        $t = ['kind' => $kind, 'channels' => (array)($r['channels'] ?? ['bot']), 'strategy' => (string)($r['strategy'] ?? 'all'), 'platform' => (string)($r['platform'] ?? '')];
        switch ($kind) {
            case 'chat':
                if (preg_match('/^#(\d+)$/', $value, $m)) {
                    $t['bot_chat_id'] = (int)$m[1];
                } else {
                    $t['chat_id'] = $value;
                }
                break;
            case 'staff':
                $t['user_ids'] = $value;
                break;
            case 'role':
            case 'on_shift':
                $t['status_id'] = (int)$value;
                break;
            case 'flag':
                $t['flag'] = $value;
                break;
            case 'subject':
                $t['subject'] = $value;
                break;
        }
        return $t;
    }

    /**
     * Stored target → form row.
     *
     * @param array<string,mixed> $t
     * @return array<string,mixed>
     */
    public static function targetToRow(array $t): array
    {
        $kind = (string)($t['kind'] ?? 'chat');
        $value = '';
        if ($kind === 'chat') {
            $value = (int)($t['bot_chat_id'] ?? 0) > 0 ? '#' . (int)$t['bot_chat_id'] : (string)($t['chat_id'] ?? '');
        } elseif ($kind === 'staff') {
            $value = implode(',', (array)($t['user_ids'] ?? []));
        } elseif ($kind === 'role' || $kind === 'on_shift') {
            $value = (int)($t['status_id'] ?? 0) > 0 ? (string)(int)$t['status_id'] : '';
        } elseif ($kind === 'flag') {
            $value = (string)($t['flag'] ?? '');
        } elseif ($kind === 'subject') {
            $value = (string)($t['subject'] ?? '');
        }
        return [
            'kind' => $kind,
            'value' => $value,
            'platform' => (string)($t['platform'] ?? ''),
            'channels' => (array)($t['channels'] ?? ['bot']),
            'strategy' => (string)($t['strategy'] ?? 'all'),
        ];
    }

    /**
     * @param array<int|string,mixed> $rows
     * @return list<array<string,mixed>>
     */
    public static function stagesFromRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || (trim((string)($r['key'] ?? '')) === '' && trim((string)($r['template'] ?? '')) === ''
                && (string)($r['offset_min'] ?? '') === '')) {
                continue;
            }
            $out[] = [
                'key' => (string)($r['key'] ?? ''),
                'anchor' => (string)($r['anchor'] ?? 'event'),
                'offset_sec' => (int)($r['offset_min'] ?? 0) * 60,
                'retry_sec' => (int)($r['retry_min'] ?? 0) * 60,
                'max_attempts' => (int)($r['max_attempts'] ?? 1),
                'expire_sec' => max(1, (int)($r['expire_min'] ?? 120)) * 60,
                'template' => (string)($r['template'] ?? ''),
                'outcomes' => (string)($r['outcomes'] ?? ''),
                'escalate' => self::targetsFromRows(is_array($r['escalate'] ?? null) ? $r['escalate'] : []),
            ];
        }
        return $out;
    }

    /**
     * Stored stage → form row (minutes).
     *
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    public static function stageToRow(array $s): array
    {
        return [
            'key' => (string)($s['key'] ?? ''),
            'anchor' => (string)($s['anchor'] ?? 'event'),
            'offset_min' => (int)round(((int)($s['offset_sec'] ?? 0)) / 60),
            'retry_min' => (int)round(((int)($s['retry_sec'] ?? 0)) / 60),
            'max_attempts' => (int)($s['max_attempts'] ?? 1),
            'expire_min' => (int)round(((int)($s['expire_sec'] ?? 7200)) / 60),
            'template' => (string)($s['template'] ?? ''),
            'outcomes' => implode(',', (array)($s['outcomes'] ?? [])),
            'escalate' => array_map([self::class, 'targetToRow'], (array)($s['escalate'] ?? [])),
        ];
    }

    /** @return array<string,mixed> empty rule for the "new" form */
    public static function blank(string $event = ''): array
    {
        return [
            'id' => 0, 'name' => '', 'event' => $event, 'enabled' => 1, 'sort' => 100, 'stop_on_match' => 0,
            'kind' => 'notify', 'conditions' => [], 'targets' => [], 'template' => '', 'stages' => [],
            'quiet_from' => '', 'quiet_to' => '',
        ];
    }
}
