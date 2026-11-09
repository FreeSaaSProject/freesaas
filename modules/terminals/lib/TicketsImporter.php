<?php
declare(strict_types=1);

/**
 * Shared 1C club_workload_detail → base_fullstats day import
 * (cron, HTTP jobs, LongJob UI).
 */
final class TicketsImporter
{
    /**
     * Fetch and safely replace one calendar day.
     *
     * @return array{
     *   ok:bool,
     *   day:string,
     *   imported:int,
     *   skipped:int,
     *   error?:string,
     *   message:string
     * }
     */
    public static function importDay(string $day): array
    {
        $day = trim($day);
        $out = [
            'ok' => false,
            'day' => $day,
            'imported' => 0,
            'skipped' => 0,
            'message' => '',
        ];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $out['error'] = 'invalid day';
            $out['message'] = $out['error'];
            return $out;
        }
        if (!TerminalsConfig::syncEnabled()) {
            $out['ok'] = true;
            $out['skipped'] = 1;
            $out['message'] = 'sync disabled';
            return $out;
        }
        if (!TerminalsConfig::onecLive()) {
            $out['ok'] = true;
            $out['skipped'] = 1;
            $out['message'] = TerminalsConfig::onecEnabled() ? '1c config incomplete' : '1c disabled';
            return $out;
        }
        if (!Database::tableExists('base_fullstats')) {
            $out['error'] = 'base_fullstats missing';
            $out['message'] = $out['error'];
            return $out;
        }

        $res = OnecClient::workloadDetail($day);
        if (empty($res['ok'])) {
            $out['error'] = (string)($res['error'] ?? '1c failed');
            $out['message'] = $out['error'];
            return $out;
        }

        $dayStart = (int)strtotime($day . ' 00:00:00');
        $dayEnd = $dayStart + 86400 - 1;
        $rawRows = is_array($res['rows'] ?? null) ? $res['rows'] : [];
        $valid = [];
        $skipped = 0;
        foreach ($rawRows as $r) {
            if (!is_array($r)) {
                $skipped++;
                continue;
            }
            $dtStart = (int)($r['dtstart'] ?? 0);
            $id1c = trim((string)($r['id1c_user'] ?? ''));
            if ($dtStart < 1 || $id1c === '') {
                $skipped++;
                continue;
            }
            if ($dtStart < $dayStart || $dtStart > $dayEnd) {
                $skipped++;
                continue;
            }
            $valid[] = $r;
        }

        // Refuse destructive wipe when nothing valid arrived (parser/API glitch guard).
        if ($valid === []) {
            $out['ok'] = true;
            $out['imported'] = 0;
            $out['skipped'] = $skipped;
            $out['message'] = 'day=' . $day . ' no valid rows (kept existing)';
            return $out;
        }

        $n = FullstatsRepository::replaceDay($dayStart, $valid, true);
        $out['ok'] = true;
        $out['imported'] = $n;
        $out['skipped'] = $skipped;
        $out['message'] = 'day=' . $day . ' imported=' . $n;
        return $out;
    }
}
