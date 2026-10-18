<?php
declare(strict_types=1);

/**
 * Cross-check laundry ticket comments (news id) against sheet transfers.
 */
final class SheetTicketReconcile
{
    public static function configNewsId(): int
    {
        return max(1, (int)Config::get('sheets', 'ticket_news_id', 78));
    }

    public static function configLaundryAccountId(): int
    {
        return max(1, (int)Config::get('sheets', 'laundry_account_id', 2));
    }

    public static function configTermsAccountId(): int
    {
        return max(1, (int)Config::get('sheets', 'terms_account_id', 1));
    }

    /**
     * @return array{
     *   news_id:int,
     *   from_ts:int,
     *   laundry_id:int,
     *   terms_id:int,
     *   laundry_balance:int,
     *   terms_balance:int,
     *   summary:array<string,int>,
     *   candidates:list<array<string,mixed>>
     * }
     */
    public static function analyze(?int $newsId = null, ?int $fromTs = null): array
    {
        $newsId = $newsId ?? self::configNewsId();
        $fromTs = $fromTs ?? (int)strtotime('2023-07-01');
        $laundryId = self::configLaundryAccountId();
        $termsId = self::configTermsAccountId();

        $comments = self::loadParsedComments($newsId, $fromTs);
        $recvMoves = self::loadRecvMoves($laundryId, $fromTs);

        $candidates = [];
        $summary = [
            'comments_recv' => 0,
            'matched' => 0,
            'missing_recv' => 0,
            'qty_mismatch' => 0,
            'missing_qty' => 0,
            'mismatch_delta' => 0,
        ];

        foreach ($comments as $c) {
            if ($c['kind'] !== 'recv') {
                continue;
            }
            $summary['comments_recv']++;
            $match = self::findBestMove($c, $recvMoves, 3);
            if ($match !== null && (int)$match['qty'] === (int)$c['qty']) {
                $recvMoves[$match['_idx']]['used'] = true;
                $summary['matched']++;
                continue;
            }

            $near = self::nearbyMoves($c, $recvMoves, 1);
            if ($near === []) {
                $key = 'missing_recv:' . $c['id'];
                $candidates[] = [
                    'key' => $key,
                    'kind' => 'missing_recv',
                    'comment_id' => $c['id'],
                    'dt' => $c['dt'],
                    'day' => $c['day'],
                    'qty' => $c['qty'],
                    'text' => $c['text'],
                    'move_id' => 0,
                    'move_qty' => 0,
                    'delta' => $c['qty'],
                    'suggested' => true,
                ];
                $summary['missing_recv']++;
                $summary['missing_qty'] += $c['qty'];
                continue;
            }

            // Nearby unused moves already sum to comment qty → treat as matched (split entries)
            $nearSum = 0;
            foreach ($near as $nm) {
                $nearSum += (int)$nm['qty'];
            }
            if ($nearSum === (int)$c['qty'] || self::qtyClose($nearSum, (int)$c['qty'])) {
                foreach ($near as $nm) {
                    $recvMoves[$nm['_idx']]['used'] = true;
                }
                $summary['matched']++;
                continue;
            }

            // Prefer shirt-bug: single small move qty equals secondary number in comment (e.g. 5 футболок)
            $shirtBug = self::detectShirtQtyBug($c, $near);
            if ($shirtBug !== null) {
                $recvMoves[$shirtBug['_idx']]['used'] = true;
                $delta = $c['qty'] - $shirtBug['qty'];
                $candidates[] = [
                    'key' => 'qty_mismatch:' . $c['id'] . ':' . $shirtBug['id'],
                    'kind' => 'qty_mismatch',
                    'comment_id' => $c['id'],
                    'dt' => $c['dt'],
                    'day' => $c['day'],
                    'qty' => $c['qty'],
                    'text' => $c['text'],
                    'move_id' => $shirtBug['id'],
                    'move_qty' => $shirtBug['qty'],
                    'delta' => $delta,
                    'suggested' => true,
                ];
                $summary['qty_mismatch']++;
                $summary['mismatch_delta'] += $delta;
                continue;
            }

            // Exact soft match already handled; leftover near moves without exact qty → mismatch on closest
            if ($match !== null) {
                $recvMoves[$match['_idx']]['used'] = true;
                $delta = $c['qty'] - (int)$match['qty'];
                if ($delta === 0) {
                    $summary['matched']++;
                    continue;
                }
                $candidates[] = [
                    'key' => 'qty_mismatch:' . $c['id'] . ':' . $match['id'],
                    'kind' => 'qty_mismatch',
                    'comment_id' => $c['id'],
                    'dt' => $c['dt'],
                    'day' => $c['day'],
                    'qty' => $c['qty'],
                    'text' => $c['text'],
                    'move_id' => (int)$match['id'],
                    'move_qty' => (int)$match['qty'],
                    'delta' => $delta,
                    'suggested' => abs($delta) >= 10,
                ];
                $summary['qty_mismatch']++;
                $summary['mismatch_delta'] += max(0, $delta);
                continue;
            }

            // Comment has nearby moves but none match — treat as missing if no plausible soft match
            $key = 'missing_recv:' . $c['id'];
            $candidates[] = [
                'key' => $key,
                'kind' => 'missing_recv',
                'comment_id' => $c['id'],
                'dt' => $c['dt'],
                'day' => $c['day'],
                'qty' => $c['qty'],
                'text' => $c['text'],
                'move_id' => 0,
                'move_qty' => 0,
                'delta' => $c['qty'],
                'nearby_moves' => array_map(static function (array $m): string {
                    return '#' . $m['id'] . ':' . $m['qty'];
                }, $near),
                'suggested' => empty($near),
            ];
            $summary['missing_recv']++;
            $summary['missing_qty'] += $c['qty'];
        }

        usort($candidates, static function (array $a, array $b): int {
            return ((int)$a['dt'] <=> (int)$b['dt']) ?: ((int)$a['comment_id'] <=> (int)$b['comment_id']);
        });

        return [
            'news_id' => $newsId,
            'from_ts' => $fromTs,
            'laundry_id' => $laundryId,
            'terms_id' => $termsId,
            'laundry_balance' => SheetMovesRepository::balance($laundryId),
            'terms_balance' => SheetMovesRepository::balance($termsId),
            'summary' => $summary,
            'candidates' => $candidates,
        ];
    }

    /**
     * Apply selected candidate keys. Stops creating missing_recv when laundry balance insufficient.
     *
     * @param list<string> $keys
     * @return array{fixed:int,created:int,skipped:list<string>,errors:list<string>,laundry_balance:int,terms_balance:int}
     */
    public static function apply(array $keys, int $userId, ?int $newsId = null, ?int $fromTs = null): array
    {
        $report = self::analyze($newsId, $fromTs);
        $byKey = [];
        foreach ($report['candidates'] as $c) {
            $byKey[(string)$c['key']] = $c;
        }
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        // Apply qty fixes first (don't consume laundry stock the same way), then missing by date
        $qtyKeys = [];
        $missKeys = [];
        foreach ($keys as $k) {
            if (!isset($byKey[$k])) {
                continue;
            }
            if ($byKey[$k]['kind'] === 'qty_mismatch') {
                $qtyKeys[] = $k;
            } else {
                $missKeys[] = $k;
            }
        }
        usort($missKeys, static function (string $a, string $b) use ($byKey): int {
            return ((int)$byKey[$a]['dt'] <=> (int)$byKey[$b]['dt']);
        });

        $fixed = 0;
        $created = 0;
        $skipped = [];
        $errors = [];
        $laundryId = (int)$report['laundry_id'];
        $termsId = (int)$report['terms_id'];

        foreach ($qtyKeys as $k) {
            $c = $byKey[$k];
            try {
                $descr = self::fixDescr((int)$c['comment_id'], (string)$c['text'], (int)$c['qty']);
                SheetMovesRepository::updateTransferPair((int)$c['move_id'], [
                    'summ' => (int)$c['qty'],
                    'descr' => $descr,
                    'dt' => (int)$c['dt'],
                ], $userId);
                $fixed++;
            } catch (Throwable $e) {
                $errors[] = $k . ': ' . $e->getMessage();
            }
        }

        foreach ($missKeys as $k) {
            $c = $byKey[$k];
            $qty = (int)$c['qty'];
            $left = SheetMovesRepository::balance($laundryId);
            if ($qty > $left) {
                $skipped[] = t('sheets.reconcile.skip_insufficient', [
                    'day' => (string)$c['day'],
                    'qty' => $qty,
                    'left' => $left,
                ]);
                continue;
            }
            try {
                $descr = self::fixDescr((int)$c['comment_id'], (string)$c['text'], $qty);
                SheetMovesRepository::createTransfer([
                    'id_account' => $laundryId,
                    'id_account_to' => $termsId,
                    'summ' => $qty,
                    'descr' => $descr,
                    'weight' => 0.0,
                    'dt' => (int)$c['dt'],
                ], $userId);
                $created++;
            } catch (Throwable $e) {
                $errors[] = $k . ': ' . $e->getMessage();
            }
        }

        return [
            'fixed' => $fixed,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'laundry_balance' => SheetMovesRepository::balance($laundryId),
            'terms_balance' => SheetMovesRepository::balance($termsId),
        ];
    }

    private static function fixDescr(int $commentId, string $text, int $qty): string
    {
        $short = mb_substr(trim($text), 0, 180);
        return mb_substr(t('sheets.reconcile.fix_descr', [
            'id' => $commentId,
            'qty' => $qty,
            'text' => $short,
        ]), 0, 255);
    }

    /**
     * @return list<array{id:int,dt:int,day:string,kind:string,qty:int,text:string}>
     */
    private static function loadParsedComments(int $newsId, int $fromTs): array
    {
        if ($newsId < 1 || !Database::tableExists('base_comments')) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT ID, DT, TEXT FROM base_comments
             WHERE ID_OBJECT = ? AND DISABLED = 0 AND DT >= ?
             ORDER BY DT ASC, ID ASC'
        );
        $st->execute([$newsId, $fromTs]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $parsed = self::parseComment((string)$row['TEXT']);
            if ($parsed === null) {
                continue;
            }
            $dt = (int)$row['DT'];
            $out[] = [
                'id' => (int)$row['ID'],
                'dt' => $dt,
                'day' => date('Y-m-d', $dt),
                'kind' => $parsed['kind'],
                'qty' => $parsed['qty'],
                'text' => $parsed['text'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{id:int,qty:int,dt:int,day:string,used:bool,_idx?:int}>
     */
    private static function loadRecvMoves(int $laundryAccountId, int $fromTs): array
    {
        if (!Database::tableExists('base_sheet_moves')) {
            return [];
        }
        // Return transfer debit leg: from laundry (ID_ACCOUNT = laundry, DIRECTION = -1, linked)
        $st = Database::pdo()->prepare(
            'SELECT ID, SUMM, DT FROM base_sheet_moves
             WHERE DELETED = 0 AND ID_MOVEMENT_LINKED > 0 AND DIRECTION = -1
               AND ID_ACCOUNT = ? AND DT >= ?
             ORDER BY DT ASC, ID ASC'
        );
        $st->execute([$laundryAccountId, $fromTs]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $dt = (int)$r['DT'];
            $out[] = [
                'id' => (int)$r['ID'],
                'qty' => (int)$r['SUMM'],
                'dt' => $dt,
                'day' => date('Y-m-d', $dt),
                'used' => false,
            ];
        }
        return $out;
    }

    /**
     * @param array{dt:int,qty:int} $comment
     * @param list<array{id:int,qty:int,dt:int,used:bool}> $moves
     * @return array{id:int,qty:int,dt:int,used:bool,_idx:int}|null
     */
    private static function findBestMove(array $comment, array $moves, int $windowDays): ?array
    {
        $best = null;
        $bestScore = PHP_INT_MAX;
        $bestIdx = null;
        $window = $windowDays * 86400;
        foreach ($moves as $i => $m) {
            if (!empty($m['used'])) {
                continue;
            }
            $exact = (int)$m['qty'] === (int)$comment['qty'];
            if (!$exact && !self::qtyClose((int)$m['qty'], (int)$comment['qty'])) {
                continue;
            }
            $diff = abs((int)$m['dt'] - (int)$comment['dt']);
            if ($diff > $window) {
                continue;
            }
            // Prefer exact qty, then closer in time
            $score = ($exact ? 0 : 100000) + $diff;
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $m;
                $bestIdx = $i;
            }
        }
        if ($best === null || $bestIdx === null) {
            return null;
        }
        $best['_idx'] = $bestIdx;
        return $best;
    }

    /**
     * @param array{dt:int} $comment
     * @param list<array{id:int,qty:int,dt:int,used:bool}> $moves
     * @return list<array{id:int,qty:int,dt:int,used:bool,_idx:int}>
     */
    private static function nearbyMoves(array $comment, array $moves, int $windowDays): array
    {
        $window = $windowDays * 86400;
        $out = [];
        foreach ($moves as $i => $m) {
            if (!empty($m['used'])) {
                continue;
            }
            if (abs((int)$m['dt'] - (int)$comment['dt']) <= $window) {
                $m['_idx'] = $i;
                $out[] = $m;
            }
        }
        return $out;
    }

    /**
     * @param array{qty:int,text:string} $comment
     * @param list<array{id:int,qty:int,dt:int,_idx:int}> $near
     * @return array{id:int,qty:int,dt:int,_idx:int}|null
     */
    private static function detectShirtQtyBug(array $comment, array $near): ?array
    {
        if (!preg_match_all('~\d+~u', (string)$comment['text'], $m)) {
            return null;
        }
        $nums = array_map('intval', $m[0]);
        $sheetQty = (int)$comment['qty'];
        foreach ($near as $move) {
            $sum = (int)$move['qty'];
            if ($sum === $sheetQty || $sum >= $sheetQty) {
                continue;
            }
            if (in_array($sum, $nums, true) && $sum < $sheetQty) {
                // Secondary number in comment equals recorded move (e.g. 5 футболок)
                return $move;
            }
        }
        return null;
    }

    private static function qtyClose(int $a, int $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (abs($a - $b) <= 5) {
            return true;
        }
        return abs($a - $b) / max($a, $b, 1) <= 0.05;
    }

    /** @return array{kind:string,qty:int,text:string}|null */
    public static function parseComment(string $raw): ?array
    {
        $t = self::stripHtml($raw);
        $low = mb_strtolower($t);
        $hasSheetWord = (bool)preg_match('~простын|пиостын|поостын~u', $low);
        $hasShtShortcut = (bool)preg_match('~(сдала|приняла|получила|привезли).{0,30}\d+\s*шт~u', $low);
        if (!$hasSheetWord && !$hasShtShortcut) {
            return null;
        }

        $kind = null;
        if (preg_match('~\b(сдала|сдал|сдали)\b~u', $low)) {
            $kind = 'send';
        }
        if (preg_match('~\b(приняла|принял|получила|получил|привезли|принято)\b~u', $low)) {
            if ($kind === 'send') {
                $hasRecv = (bool)preg_match('~(приняла|принял|получила|получил|привезли).{0,50}\d~u', $low);
                $hasSend = (bool)preg_match('~(сдала|сдал|сдали).{0,50}\d~u', $low);
                if ($hasRecv && !$hasSend) {
                    $kind = 'recv';
                } elseif ($hasSend && !$hasRecv) {
                    $kind = 'send';
                } else {
                    return null;
                }
            } else {
                $kind = 'recv';
            }
        }
        if ($kind === null) {
            return null;
        }

        $qty = null;
        if (preg_match('~(\d+)\s*(?:шт\.?|штук\w*)?\s*(?:простын|пиостын|поостын)~ui', $t, $m)) {
            $qty = (int)$m[1];
        } elseif (preg_match('~(сдала|сдал|сдали|приняла|принял|получила|получил|привезли)\s*(\d+)\s*\([^)]*\)\s*(?:простын|пиостын|поостын)~ui', $t, $m)) {
            $qty = (int)$m[2];
        } elseif (preg_match('~(сдала|сдал|сдали|приняла|принял|получила|получил|привезли)[^\d]{0,20}(\d+)\s*шт~ui', $t, $m)) {
            $qty = (int)$m[2];
        } elseif (preg_match('~(сдала|сдал|сдали|приняла|принял|получила|получил|привезли)[^\d]{0,15}(\d+)~ui', $t, $m)) {
            $qty = (int)$m[2];
        }
        if ($qty === null || $qty < 1 || $qty > 5000) {
            return null;
        }

        return [
            'kind' => $kind,
            'qty' => $qty,
            'text' => mb_substr($t, 0, 160),
        ];
    }

    private static function stripHtml(string $t): string
    {
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('~<br\s*/?>~i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        return trim(preg_replace('~\s+~u', ' ', $t) ?? $t);
    }
}
