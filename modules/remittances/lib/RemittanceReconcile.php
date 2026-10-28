<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

final class RemittanceReconcile
{
    private const TOLERANCE = 0.02;

    /**
     * Sync / refresh batch for a move. Returns batch row or null if no agreement.
     *
     * Badge / edit chrome must pass $applySupplier=false: never mutate ledger moves on GET.
     * Explicit remittance actions (POST) keep the default and may fill empty counterparty.
     *
     * @param array<string,mixed>|null $move
     * @return array<string,mixed>|null
     */
    public static function syncMove(
        int $moveId,
        ?array $move = null,
        bool $force = false,
        bool $applySupplier = true
    ): ?array {
        if ($moveId < 1) {
            return null;
        }
        if ($move === null) {
            if (!class_exists('LedgerMovesRepository', false)) {
                return null;
            }
            $move = LedgerMovesRepository::find($moveId);
        }
        if ($move === null || (int)($move['deleted'] ?? 0) > 0) {
            return null;
        }

        $existing = RemittanceBatchesRepository::findByMoveId($moveId);
        if ($existing !== null && (int)($existing['ignored'] ?? 0) > 0 && !$force) {
            return $existing;
        }

        $agreement = RemittanceMatcher::findAgreementForMove($move);
        if ($agreement === null) {
            return $existing;
        }

        $net = round((float)($move['summ'] ?? 0), 2);
        $parsed = RemittanceParser::parse((string)($move['descr'] ?? ''), $agreement);
        if (!$parsed['ok']) {
            $batchId = RemittanceBatchesRepository::upsertForMove($moveId, [
                'agreement_id' => (int)$agreement['id'],
                'fee_move_id' => (int)($existing['fee_move_id'] ?? 0),
                'period_from' => null,
                'period_to' => null,
                'gross' => 0,
                'fee' => 0,
                'net' => $net,
                'expected' => 0,
                'delta_gross' => 0,
                'delta_net' => 0,
                'status' => 'unparsed',
                'parse_error' => (string)$parsed['error'],
                'ignored' => 0,
            ]);
            if ($applySupplier) {
                self::maybeSetSupplier($move, $agreement);
            }
            $out = RemittanceBatchesRepository::findByMoveId($moveId);
            return $out ?? ['id' => $batchId];
        }

        $period = RemittanceExpected::forPeriod(
            (string)$parsed['period_from'],
            (string)$parsed['period_to'],
            $agreement
        );
        $expected = $period['amount'];
        $gross = (float)$parsed['gross'];
        $fee = (float)$parsed['fee'];
        $deltaGross = round($expected - $gross, 2);
        $deltaNet = round($gross - $fee - $net, 2);

        $feePct = (float)($agreement['fee_percent'] ?? 0);
        $feeExpected = $feePct > 0 ? round($gross * $feePct / 100.0, 2) : 0.0;
        $feeDelta = round($fee - $feeExpected, 2);

        $status = 'ok';
        if (abs($deltaGross) > self::TOLERANCE || abs($deltaNet) > self::TOLERANCE
            || abs($feeDelta) > self::TOLERANCE) {
            $status = 'mismatch';
        }

        RemittanceBatchesRepository::upsertForMove($moveId, [
            'agreement_id' => (int)$agreement['id'],
            'fee_move_id' => (int)($existing['fee_move_id'] ?? 0),
            'period_from' => $parsed['period_from'],
            'period_to' => $parsed['period_to'],
            'gross' => $gross,
            'fee' => $fee,
            'net' => $net,
            'expected' => $expected,
            'delta_gross' => $deltaGross,
            'delta_net' => $deltaNet,
            'status' => $status,
            'parse_error' => '',
            'ignored' => 0,
        ]);
        if ($applySupplier) {
            self::maybeSetSupplier($move, $agreement);
        }
        return RemittanceBatchesRepository::findByMoveId($moveId);
    }

    /**
     * @param list<int> $moveIds
     * @return array<int,string> move_id => badge html
     */
    public static function badgesHtml(array $moveIds): array
    {
        $moveIds = array_values(array_unique(array_filter(array_map('intval', $moveIds))));
        if ($moveIds === [] || !RemittanceAgreementsRepository::tableReady()) {
            return [];
        }
        if (RemittanceMatcher::enabledAgreements() === []) {
            return [];
        }

        $existing = RemittanceBatchesRepository::byMoveIds($moveIds);
        $needLoad = [];
        foreach ($moveIds as $mid) {
            if (!isset($existing[$mid])) {
                $needLoad[] = $mid;
            }
        }
        $movesById = [];
        if ($needLoad !== [] && class_exists('LedgerMovesRepository', false)) {
            foreach ($needLoad as $mid) {
                $m = LedgerMovesRepository::find($mid);
                if ($m !== null) {
                    $movesById[$mid] = $m;
                }
            }
        }

        $out = [];
        foreach ($moveIds as $mid) {
            $batch = $existing[$mid] ?? null;
            if ($batch === null) {
                $move = $movesById[$mid] ?? null;
                if ($move === null) {
                    continue;
                }
                if (RemittanceMatcher::findAgreementForMove($move) === null) {
                    continue;
                }
                // View-only path: refresh remittance batch chip, never touch ledger supplier.
                $batch = self::syncMove($mid, $move, false, false);
            }
            if ($batch === null) {
                continue;
            }
            $html = self::badgeFromBatch($batch);
            if ($html !== '') {
                $out[$mid] = $html;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $batch */
    public static function badgeFromBatch(array $batch): string
    {
        if ((int)($batch['ignored'] ?? 0) > 0 || (string)($batch['status'] ?? '') === 'ignored') {
            return '<span class="remit-chip remit-chip-muted" title="' . e(t('remittances.badge.ignored')) . '">'
                . e(t('remittances.badge.ignored_short')) . '</span>';
        }
        $status = (string)($batch['status'] ?? '');
        if ($status === 'unparsed') {
            return '<span class="remit-chip remit-chip-warn" title="' . e(t('remittances.badge.unparsed')) . '">'
                . e(t('remittances.badge.unparsed_short')) . '</span>';
        }
        $from = (string)($batch['period_from'] ?? '');
        $to = (string)($batch['period_to'] ?? '');
        $periodLabel = '';
        if ($from !== '' && $to !== '') {
            $periodLabel = self::fmtShortDate($from) . '–' . self::fmtShortDate($to);
        }
        $delta = (float)($batch['delta_gross'] ?? 0);
        $cls = abs($delta) > self::TOLERANCE || $status === 'mismatch'
            ? 'remit-chip-bad'
            : 'remit-chip-ok';
        $deltaLabel = ($delta > 0 ? '+' : '') . self::fmtMoney($delta);
        $title = t('remittances.badge.title', [
            'period' => $periodLabel,
            'gross' => self::fmtMoney((float)($batch['gross'] ?? 0)),
            'fee' => self::fmtMoney((float)($batch['fee'] ?? 0)),
            'expected' => self::fmtMoney((float)($batch['expected'] ?? 0)),
            'delta' => $deltaLabel,
        ]);
        $body = $periodLabel !== '' ? $periodLabel . ' · Δ' . $deltaLabel : 'Δ' . $deltaLabel;
        return '<span class="remit-chip ' . e($cls) . '" title="' . e($title) . '">'
            . e(t('remittances.badge.short')) . ' ' . e($body) . '</span>';
    }

    /**
     * @param array<string,mixed> $move
     */
    public static function editSectionHtml(int $moveId, array $move): string
    {
        if ($moveId < 1 || !RemittanceAgreementsRepository::tableReady()) {
            return '';
        }
        $agreement = RemittanceMatcher::findAgreementForMove($move);
        $batch = RemittanceBatchesRepository::findByMoveId($moveId);
        if ($agreement === null && $batch === null) {
            return '';
        }
        if ($batch === null && $agreement !== null) {
            $batch = self::syncMove($moveId, $move, false, false);
        }
        if ($batch === null) {
            return '';
        }
        if ($agreement === null) {
            $agreement = RemittanceAgreementsRepository::find((int)$batch['agreement_id']);
        }

        Assets::need('remittances');
        $status = (string)($batch['status'] ?? '');
        $ignored = (int)($batch['ignored'] ?? 0) > 0;
        $byDay = [];
        if (!empty($batch['period_from']) && !empty($batch['period_to']) && is_array($agreement)) {
            $byDay = RemittanceExpected::forPeriod(
                (string)$batch['period_from'],
                (string)$batch['period_to'],
                $agreement
            )['by_day'];
        }

        $feePosting = is_array($agreement) ? (string)($agreement['fee_posting'] ?? 'none') : 'none';
        $canFee = $feePosting === 'expense_move'
            && (int)($batch['fee_move_id'] ?? 0) < 1
            && (float)($batch['fee'] ?? 0) > 0
            && !$ignored
            && $status !== 'unparsed';

        ob_start();
        $row = $move;
        $ag = $agreement;
        require base_path('modules/remittances/views/_move_section.php');
        return (string)ob_get_clean();
    }

    /**
     * @param array<string,mixed> $move
     * @param array<string,mixed> $agreement
     */
    private static function maybeSetSupplier(array $move, array $agreement): void
    {
        if (empty($agreement['auto_set_supplier'])) {
            return;
        }
        // Never write ledger moves without edit permission (badges only require ledger.view).
        if (!Auth::can('ledger.edit')) {
            return;
        }
        $moveId = (int)($move['id'] ?? 0);
        $supplierId = (int)($agreement['supplier_id'] ?? 0);
        if ($moveId < 1 || $supplierId < 1) {
            return;
        }
        if ((int)($move['supplier_id'] ?? 0) > 0) {
            return;
        }
        if (!class_exists('LedgerMovesRepository', false)) {
            return;
        }
        try {
            LedgerMovesRepository::setSupplierId($moveId, $supplierId);
        } catch (Throwable $e) {
            // Type may not allow counterparty — leave move unchanged.
        }
    }

    public static function fmtMoney(float $n): string
    {
        return number_format($n, 2, '.', ' ');
    }

    public static function fmtShortDate(string $ymd): string
    {
        $t = strtotime($ymd . ' 00:00:00');
        return $t !== false ? date('d.m', $t) : $ymd;
    }

    /** Dry-run parser against recent moves matching agreement supplier. */
    public static function dryRun(array $agreement, int $limit = 5): array
    {
        if (!class_exists('LedgerMovesRepository', false)) {
            return [];
        }
        $supplierId = (int)($agreement['supplier_id'] ?? 0);
        $q = trim((string)($agreement['supplier_inn'] ?? ''));
        if ($q === '') {
            $q = trim((string)($agreement['supplier_name'] ?? ''));
        }
        $res = LedgerMovesRepository::list([
            'q' => $q,
            'limit' => max(20, $limit * 8),
            'offset' => 0,
            'order' => 'm.DT DESC, m.ID DESC',
        ]);
        $out = [];
        foreach ($res['items'] as $move) {
            if ((int)($move['direction'] ?? 0) !== 1) {
                continue;
            }
            if ($supplierId > 0 && (int)($move['supplier_id'] ?? 0) === $supplierId) {
                // ok
            } elseif (!RemittanceMatcher::descrMatchesSupplier((string)($move['descr'] ?? ''), $agreement)) {
                continue;
            }
            $parsed = RemittanceParser::parse((string)($move['descr'] ?? ''), $agreement);
            $out[] = [
                'move_id' => (int)$move['id'],
                'summ' => (float)$move['summ'],
                'descr' => mb_substr((string)$move['descr'], 0, 180),
                'parsed' => $parsed,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}
