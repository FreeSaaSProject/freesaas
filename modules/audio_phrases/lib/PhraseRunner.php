<?php
declare(strict_types=1);

final class PhraseRunner
{
    /**
     * @return array{ok:bool,day:string,admins:int,phrases:int,message:string}
     */
    public static function runDay(string $day, int $adminId = 0): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return ['ok' => false, 'day' => $day, 'admins' => 0, 'phrases' => 0, 'message' => 'bad day'];
        }
        if (!PhrasesRepository::tablesReady()) {
            return ['ok' => false, 'day' => $day, 'admins' => 0, 'phrases' => 0, 'message' => 'tables missing'];
        }
        $lem = class_exists('StemRunner', false) ? StemRunner::makeLemmatizer() : null;
        if ($lem === null) {
            return ['ok' => false, 'day' => $day, 'admins' => 0, 'phrases' => 0, 'message' => 'lemmatizer unavailable'];
        }
        $admins = $adminId > 0
            ? [$adminId]
            : (class_exists('StemRunner', false) ? StemRunner::adminIdsForDay($day) : []);
        PhrasesRepository::clearDay($day, $adminId);
        $phraseHits = 0;
        $pdo = Database::pdo();
        foreach ($admins as $aid) {
            $aid = (int)$aid;
            if ($aid < 1) {
                continue;
            }
            $hits = PhraseExtractor::extractForAdminDay($aid, $day, $lem);
            if ($hits === []) {
                continue;
            }
            // One transaction per admin: fewer fsyncs on hundreds of upserts.
            $inTx = false;
            try {
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $inTx = true;
                }
                foreach ($hits as $hit) {
                    PhrasesRepository::upsertHit($hit, $aid, $day);
                    $phraseHits++;
                }
                if ($inTx) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($inTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        return [
            'ok' => true,
            'day' => $day,
            'admins' => count($admins),
            'phrases' => $phraseHits,
            'message' => '',
        ];
    }

    /**
     * Daily cron: today−1 plus backfill of recent days.
     *
     * @return array{ok:bool,processed:int,days:list<string>}
     */
    public static function runDailyCatchup(): array
    {
        $days = [];
        $backfill = AudioPhrasesConfig::backfillDays();
        for ($i = 1; $i <= max(1, $backfill); $i++) {
            $days[] = date('Y-m-d', strtotime('-' . $i . ' day'));
        }
        $processed = 0;
        foreach ($days as $day) {
            $r = self::runDay($day);
            if (!empty($r['ok'])) {
                $processed += (int)$r['phrases'];
            }
        }
        return ['ok' => true, 'processed' => $processed, 'days' => $days];
    }
}
