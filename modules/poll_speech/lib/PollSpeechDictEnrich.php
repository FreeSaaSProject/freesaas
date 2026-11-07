<?php
declare(strict_types=1);

/**
 * Mine guest score phrases from cabin transcripts near oral invite creation
 * (±marginSec around DT_CREATE) and add missing keys to the score dictionary.
 */
final class PollSpeechDictEnrich
{
    /**
     * @param array{from_ts?:int,to_ts?:int,margin_sec?:int,dict_id?:int,dry_run?:bool} $opts
     * @return array{
     *   ok:bool,
     *   invites:int,
     *   records:int,
     *   candidates:array<string,int>,
     *   created:int,
     *   map_added:int,
     *   samples:list<array{invite_id:int,score:int,phrase:string,snippet:string}>
     * }
     */
    public static function enrichRange(array $opts = []): array
    {
        $from = (int)($opts['from_ts'] ?? 0);
        $to = (int)($opts['to_ts'] ?? 0);
        $margin = max(60, min(3600, (int)($opts['margin_sec'] ?? 900)));
        $dictId = max(1, (int)($opts['dict_id'] ?? PollSpeechConfig::dictId()));
        $dry = !empty($opts['dry_run']);

        $empty = [
            'ok' => false,
            'invites' => 0,
            'records' => 0,
            'candidates' => [],
            'created' => 0,
            'map_added' => 0,
            'samples' => [],
        ];
        if ($from < 1 || $to < $from
            || !Database::tableExists('base_poll_invites')
            || !Database::tableExists('base_records')
        ) {
            return $empty;
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare(
            "SELECT i.ID AS id, i.DT_CREATE AS dt_create, i.ID_OPERATOR_LINKED AS id_linked,
                    i.ID_OPERATOR_CREATE AS id_create, i.ID_PROC AS id_proc,
                    p.ID_ADMIN AS proc_admin
             FROM base_poll_invites i
             INNER JOIN base_procedures p ON p.ID = i.ID_PROC
             WHERE i.ID_PROC > 0
               AND (i.CODE = '' OR i.LOG LIKE '%[ORAL_ASKED]%')
               AND i.DT_CREATE >= ? AND i.DT_CREATE <= ?
             ORDER BY i.ID ASC"
        );
        $st->execute([$from, $to]);
        $invites = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        /** @var array<string,int> $candidates phrase => score */
        $candidates = [];
        /** @var list<array{invite_id:int,score:int,phrase:string,snippet:string}> $samples */
        $samples = [];
        $recordsSeen = [];

        foreach ($invites as $inv) {
            $inviteId = (int)$inv['id'];
            $created = (int)$inv['dt_create'];
            if ($created < 1) {
                continue;
            }
            $admins = [];
            foreach ([(int)$inv['id_linked'], (int)$inv['id_create'], (int)$inv['proc_admin']] as $aid) {
                if ($aid > 0) {
                    $admins[$aid] = $aid;
                }
            }
            if ($admins === []) {
                continue;
            }
            $winFrom = $created - $margin;
            $winTo = $created + $margin;
            $ph = implode(',', array_fill(0, count($admins), '?'));
            $sql = "SELECT ID AS id, ID_ADMIN AS id_admin, DT_RECORDED AS dt, TRANSCRIPTION AS t
                    FROM base_records
                    WHERE ID_ADMIN IN ($ph)
                      AND DT_RECORDED >= ? AND DT_RECORDED <= ?
                      AND TRANSCRIPTION IS NOT NULL AND TRANSCRIPTION <> ''";
            $params = array_merge(array_values($admins), [$winFrom, $winTo]);
            $rst = $pdo->prepare($sql);
            $rst->execute($params);
            while ($row = $rst->fetch(PDO::FETCH_ASSOC)) {
                $rid = (int)$row['id'];
                $recordsSeen[$rid] = true;
                $hits = self::extractScoresFromTranscription((string)$row['t']);
                foreach ($hits as $hit) {
                    $phrase = $hit['phrase'];
                    $score = $hit['score'];
                    if ($phrase === '' || $score < 1 || $score > 10) {
                        continue;
                    }
                    if (!isset($candidates[$phrase]) || $candidates[$phrase] === $score) {
                        $candidates[$phrase] = $score;
                    }
                    if (count($samples) < 40) {
                        $samples[] = [
                            'invite_id' => $inviteId,
                            'score' => $score,
                            'phrase' => $phrase,
                            'snippet' => $hit['snippet'],
                        ];
                    }
                }
            }
        }

        $created = 0;
        $mapAdded = 0;
        if (!$dry && $candidates !== [] && Database::tableExists('base_records_keys')) {
            $map = PollSpeechConfig::keyScoreMap();
            $find = $pdo->prepare(
                'SELECT ID FROM base_records_keys WHERE ID_DICTONARY = ? AND NAME = ? LIMIT 1'
            );
            $ins = $pdo->prepare(
                'INSERT INTO base_records_keys (DT_CREATE, NAME, DISABLED, ID_DICTONARY, ID_LOOKLIKE)
                 VALUES (?,?,0,?,0)'
            );
            foreach ($candidates as $phrase => $score) {
                $find->execute([$dictId, $phrase]);
                $kid = (int)$find->fetchColumn();
                if ($kid < 1) {
                    $ins->execute([time(), mb_substr($phrase, 0, 255), $dictId]);
                    $kid = (int)$pdo->lastInsertId();
                    $created++;
                }
                if ($kid > 0 && (!isset($map[$kid]) || (int)$map[$kid] !== $score)) {
                    $map[$kid] = $score;
                    $mapAdded++;
                }
            }
            Config::set('poll_speech', 'dict_id', (string)$dictId);
            Config::set(
                'poll_speech',
                'key_score_map',
                (string)json_encode($map, JSON_UNESCAPED_UNICODE)
            );
        }

        return [
            'ok' => true,
            'invites' => count($invites),
            'records' => count($recordsSeen),
            'candidates' => $candidates,
            'created' => $created,
            'map_added' => $mapAdded,
            'samples' => $samples,
        ];
    }

    /**
     * @return list<array{score:int,phrase:string,snippet:string}>
     */
    public static function extractScoresFromTranscription(string $transcription): array
    {
        $out = [];
        $plain = preg_replace('/\[\d{1,2}:\d{2}(?:->\d{1,2}:\d{2})?\]\s*/u', "\n", $transcription) ?? $transcription;
        $lines = preg_split('/\R+/u', $plain) ?: [];
        // Ask for a numeric rating (not generic «как вам»).
        $askRe = '/оценк|оценоч|от\s*(1|одного)\s*до\s*(10|десяти)|балльн|по\s*10-?бальн|шкал/iu';
        // Strong answer forms first (avoid «без десяти семь» clock talk).
        $strongWord = [
            'десяточка' => 10, 'десятка' => 10, 'на десятку' => 10,
            'девяточка' => 9, 'девятка' => 9,
            'восьмёрка' => 8, 'восьмерка' => 8,
            'семёрка' => 7, 'семерка' => 7,
            'шестёрка' => 6, 'шестерка' => 6,
            'пятёрка' => 5, 'пятерка' => 5,
            'четвёрка' => 4, 'четверка' => 4,
            'тройка' => 3, 'двойка' => 2, 'единица' => 1,
            'максимальную' => 10, 'максимальная' => 10,
        ];
        $softWord = [
            'отлично' => 10, 'прекрасно' => 10, 'супер' => 10,
            'десять' => 10, 'девять' => 9, 'восемь' => 8, 'семь' => 7,
            'шесть' => 6, 'пять' => 5, 'четыре' => 4, 'три' => 3, 'два' => 2, 'один' => 1,
        ];

        $n = count($lines);
        for ($i = 0; $i < $n; $i++) {
            $line = trim((string)$lines[$i]);
            if ($line === '' || !preg_match($askRe, $line)) {
                continue;
            }
            // Only guest replies AFTER the ask line (ask itself has «от 1 до 10»).
            $reply = [];
            for ($j = $i + 1; $j <= min($n - 1, $i + 4); $j++) {
                $t = trim((string)$lines[$j]);
                if ($t !== '') {
                    $reply[] = $t;
                }
            }
            // Same-line answer after ask (rare): keep text after last «до 10/десяти».
            $same = $line;
            if (preg_match('/до\s*(10|десяти)\s*[.?!]?\s*(.+)$/iu', $line, $sm)) {
                $tail = trim((string)($sm[2] ?? ''));
                if ($tail !== '' && !preg_match($askRe, $tail)) {
                    array_unshift($reply, $tail);
                }
            }
            if ($reply === []) {
                continue;
            }
            $blob = implode(' ', $reply);
            // Drop admin thanks / next topic noise markers lightly.
            $snippet = mb_substr(trim($line . ' → ' . $blob), 0, 180);

            $hit = null;
            foreach ($strongWord as $word => $score) {
                if (preg_match('/' . preg_quote($word, '/') . '/iu', $blob)) {
                    $hit = ['score' => $score, 'phrase' => self::wordPhrase($word, $score), 'snippet' => $snippet];
                    break;
                }
            }
            if ($hit === null && preg_match('/(?:^|[^\d])(10|[1-9])(?!\d)(?!\s*мин)/u', $blob, $m)) {
                // Ignore lone digits that are clearly time/price context.
                if (!preg_match('/\b(10|[1-9])\s*(мин|руб|час)/iu', $blob)
                    && !preg_match('/без\s+десяти/iu', $blob)
                ) {
                    $score = (int)$m[1];
                    $hit = ['score' => $score, 'phrase' => self::digitPhrase($score), 'snippet' => $snippet];
                }
            }
            if ($hit === null) {
                foreach ($softWord as $word => $score) {
                    if (!preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $blob)) {
                        continue;
                    }
                    if (preg_match('/\b' . preg_quote($word, '/') . '\s+минут/iu', $blob)) {
                        continue;
                    }
                    if (preg_match('/без\s+' . preg_quote($word, '/') . '/iu', $blob)) {
                        continue;
                    }
                    $hit = ['score' => $score, 'phrase' => self::wordPhrase($word, $score), 'snippet' => $snippet];
                    break;
                }
            }
            if ($hit !== null) {
                $out[] = $hit;
            }
            unset($same);
        }
        return $out;
    }

    private static function digitPhrase(int $score): string
    {
        // Distinctive keys for bare digits after ask (stem still needs context keys).
        $words = [
            1 => 'один', 2 => 'два', 3 => 'три', 4 => 'четыре', 5 => 'пять',
            6 => 'шесть', 7 => 'семь', 8 => 'восемь', 9 => 'девять', 10 => 'десять',
        ];
        $w = $words[$score] ?? (string)$score;
        return 'оценка ' . $w;
    }

    private static function wordPhrase(string $word, int $score): string
    {
        $word = mb_strtolower(trim($word));
        if (in_array($word, ['отлично', 'прекрасно', 'супер', 'хорошо', 'нормально', 'плохо', 'ужасно'], true)) {
            return $word;
        }
        if (in_array($word, ['максимальную', 'максимальная', 'на десятку', 'десяточка', 'десятка'], true)) {
            return $word;
        }
        return 'оценка ' . $word;
    }
}
