<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Collects expected remittance amounts for a calendar day via EventBus.
 */

final class RemittanceExpected
{
    /**
     * @param array<string,mixed> $agreement
     * @return array{amount:float,channels:list<array{code:string,amount:float,source?:string}>}
     */
    public static function forDay(string $dateYmd, array $agreement): array
    {
        $channelsFilter = self::channelCodes($agreement);
        $payload = EventBus::emit('remittances.day_expected', [
            'date_ymd' => $dateYmd,
            'agreement_id' => (int)($agreement['id'] ?? 0),
            'agreement' => $agreement,
            'channels_filter' => $channelsFilter,
            'amount' => 0.0,
            'channels' => [],
        ]);
        $channels = [];
        $sum = 0.0;
        foreach (is_array($payload['channels'] ?? null) ? $payload['channels'] : [] as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $code = trim((string)($ch['code'] ?? ''));
            $amt = round((float)($ch['amount'] ?? 0), 2);
            if ($code === '') {
                continue;
            }
            if ($channelsFilter !== [] && !in_array($code, $channelsFilter, true)) {
                continue;
            }
            $channels[] = [
                'code' => $code,
                'amount' => $amt,
                'source' => (string)($ch['source'] ?? ''),
            ];
            $sum += $amt;
        }
        if ($channels === [] && isset($payload['amount'])) {
            $sum = round((float)$payload['amount'], 2);
        }
        return ['amount' => round($sum, 2), 'channels' => $channels];
    }

    /**
     * Sum expected across inclusive Y-m-d period.
     *
     * @param array<string,mixed> $agreement
     * @return array{amount:float,by_day:array<string,float>}
     */
    public static function forPeriod(string $fromYmd, string $toYmd, array $agreement): array
    {
        $byDay = [];
        $total = 0.0;
        $t = strtotime($fromYmd . ' 00:00:00');
        $end = strtotime($toYmd . ' 00:00:00');
        if ($t === false || $end === false) {
            return ['amount' => 0.0, 'by_day' => []];
        }
        while ($t <= $end) {
            $ymd = date('Y-m-d', $t);
            $day = self::forDay($ymd, $agreement);
            $byDay[$ymd] = $day['amount'];
            $total += $day['amount'];
            $t = strtotime('+1 day', $t);
            if ($t === false) {
                break;
            }
        }
        return ['amount' => round($total, 2), 'by_day' => $byDay];
    }

    /**
     * @param array<string,mixed> $agreement
     * @return list<string>
     */
    public static function channelCodes(array $agreement): array
    {
        $raw = trim((string)($agreement['expected_channels'] ?? 'spa_ops'));
        if ($raw === '') {
            return ['spa_ops'];
        }
        $parts = preg_split('/[\\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p !== '') {
                $out[] = $p;
            }
        }
        return $out !== [] ? $out : ['spa_ops'];
    }
}
