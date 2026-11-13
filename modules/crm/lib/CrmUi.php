<?php
declare(strict_types=1);

/** CRM view/page helpers: staff names, money labels, PATCH-style merge of posted fields. */
final class CrmUi
{
    /** @return array<int,string> enabled staff + extra ids (e.g. current responsible) */
    public static function staffOptions(array $extraIds = []): array
    {
        if (!class_exists('UsersRepository')) {
            return [];
        }
        return UsersRepository::staffNamesIncluding(array_values(array_filter(array_map('intval', $extraIds))));
    }

    /**
     * @param list<int> $ids
     * @return array<int,string>
     */
    public static function userNames(array $ids): array
    {
        $out = [];
        if (!class_exists('UsersRepository')) {
            return $out;
        }
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id < 1) {
                continue;
            }
            $u = UsersRepository::find($id);
            $out[$id] = $u === null
                ? '#' . $id
                : (trim((string)$u['name']) !== '' ? (string)$u['name'] : (string)$u['login']);
        }
        return $out;
    }

    /**
     * Adds responsible_name to each row.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function withResponsibleNames(array $rows): array
    {
        $names = self::userNames(array_column($rows, 'responsible_user_id'));
        foreach ($rows as &$r) {
            $rid = (int)($r['responsible_user_id'] ?? 0);
            $r['responsible_name'] = $rid > 0 ? ($names[$rid] ?? '#' . $rid) : '';
        }
        unset($r);
        return $rows;
    }

    public static function money(float $amount, string $currency = ''): string
    {
        $s = number_format($amount, (abs($amount - round($amount)) > 0.001) ? 2 : 0, ',', "\u{202F}");
        return $currency !== '' ? $s . ' ' . $currency : $s;
    }

    /** @param array<string,float> $sums currency => amount */
    public static function sumsLabel(array $sums): string
    {
        $parts = [];
        foreach ($sums as $cur => $amount) {
            if ($amount != 0.0) {
                $parts[] = self::money((float)$amount, (string)$cur);
            }
        }
        return $parts === [] ? '0' : implode(' · ', $parts);
    }

    /**
     * PATCH semantics: start from stored values, override only keys present in the request.
     * Agents/assistant send only changed fields; absent keys must not be blanked.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $post
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    public static function patch(array $existing, array $post, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = array_key_exists($k, $post) ? $post[$k] : ($existing[$k] ?? null);
        }
        return $out;
    }

    /** @return list<int>|null null when client_ids was not sent */
    public static function postedClientIds(array $post): ?array
    {
        if (!array_key_exists('client_ids', $post)) {
            return null;
        }
        $raw = $post['client_ids'];
        $parts = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string)$raw) ?: []);
        $ids = [];
        foreach ($parts as $part) {
            $cid = (int)$part;
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }
        return $ids;
    }

    /** responsible filter: '' | 'me' | user id → int user id (0 = any) */
    public static function responsibleFilter(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === 'me') {
            return (int)Auth::userId();
        }
        return max(0, (int)$raw);
    }
}
