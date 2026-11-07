<?php
declare(strict_types=1);

/** Ini-backed settings for poll_speech ([poll_speech] in cfg/modules/poll_speech.ini). */
final class PollSpeechConfig
{
    public const ADMIN_LINKED = 'linked';
    public const ADMIN_PROC = 'proc_admin';
    public const ADMIN_BOTH = 'both';

    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return [
            'oral_btn_enabled' => 1,
            'oral_poll_id' => 0,
            'dict_id' => 50,
            'window_sec' => 1800,
            'admin_field' => self::ADMIN_BOTH,
            'key_score_map' => '{}',
            'qid_staff' => 0,
            'qid_service' => 0,
            'answers_staff' => '',
            'answers_service' => '',
        ];
    }

    /** @return array<string, string> */
    public static function formValues(): array
    {
        $qid = (string)self::qidStaff();
        $answers = self::answersStaffRaw();
        return [
            'oral_btn_enabled' => self::oralBtnEnabled() ? '1' : '0',
            'oral_poll_id' => (string)self::oralPollId(),
            'dict_id' => (string)self::dictId(),
            'window_sec' => (string)self::windowSec(),
            'admin_field' => self::adminField(),
            'key_score_map' => self::keyScoreMapRaw(),
            // Unified score (UI); staff/service kept for agents/compat.
            'qid' => $qid,
            'answers' => $answers,
            'qid_staff' => $qid,
            'qid_service' => (string)self::qidService(),
            'answers_staff' => $answers,
            'answers_service' => self::answersServiceRaw(),
        ];
    }

    /**
     * @param array<string,mixed> $post
     */
    public static function saveFromPost(array $post): void
    {
        $btn = !empty($post['oral_btn_enabled']) ? 1 : 0;
        Config::set('poll_speech', 'oral_btn_enabled', (string)$btn);

        $pollId = max(0, (int)($post['oral_poll_id'] ?? 0));
        if ($pollId > 0 && class_exists('PollsRepository', false)) {
            $poll = PollsRepository::find($pollId);
            if ($poll === null || (int)($poll['pid'] ?? 0) !== 0) {
                throw new InvalidArgumentException(t('poll_speech.err.poll_missing', ['id' => $pollId]));
            }
        }
        Config::set('poll_speech', 'oral_poll_id', (string)$pollId);
        // Keep poll_invites resolver in sync for markOralAsked().
        Config::set('poll_invites', 'oral_poll_id', (string)$pollId);

        Config::set('poll_speech', 'dict_id', (string)max(0, (int)($post['dict_id'] ?? 0)));
        Config::set('poll_speech', 'window_sec', (string)max(60, min(86400, (int)($post['window_sec'] ?? 1800))));

        $field = (string)($post['admin_field'] ?? self::ADMIN_LINKED);
        if (!in_array($field, [self::ADMIN_LINKED, self::ADMIN_PROC, self::ADMIN_BOTH], true)) {
            $field = self::ADMIN_LINKED;
        }
        Config::set('poll_speech', 'admin_field', $field);

        Config::set(
            'poll_speech',
            'key_score_map',
            (string)json_encode(self::parseKeyScoreMapFromPost($post), JSON_UNESCAPED_UNICODE)
        );

        // One spoken score → one poll question; mirror into staff+service slots for legacy dual columns.
        $qid = 0;
        if (array_key_exists('qid', $post)) {
            $qid = max(0, (int)$post['qid']);
        } elseif (array_key_exists('qid_staff', $post)) {
            $qid = max(0, (int)$post['qid_staff']);
        } else {
            $qid = self::qidStaff();
        }
        $answersRaw = '';
        if (array_key_exists('answers', $post)) {
            $answersRaw = (string)$post['answers'];
        } elseif (array_key_exists('answers_staff', $post)) {
            $answersRaw = (string)$post['answers_staff'];
        } else {
            $answersRaw = self::answersStaffRaw();
        }
        $answersCsv = self::normalizeAnswersCsv($answersRaw);
        Config::set('poll_speech', 'qid_staff', (string)$qid);
        Config::set('poll_speech', 'qid_service', (string)$qid);
        Config::set('poll_speech', 'answers_staff', $answersCsv);
        Config::set('poll_speech', 'answers_service', $answersCsv);
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,int> key_id => score 1..10
     */
    public static function parseKeyScoreMapFromPost(array $post): array
    {
        $clean = [];
        // Preferred UI: key_assign[key_id] = score (0 = unmapped)
        if (isset($post['key_assign']) && is_array($post['key_assign'])) {
            foreach ($post['key_assign'] as $keyRaw => $scoreRaw) {
                $kid = (int)$keyRaw;
                $score = (int)$scoreRaw;
                if ($kid > 0 && $score >= 1 && $score <= 10) {
                    $clean[(string)$kid] = $score;
                }
            }
            return $clean;
        }
        // Legacy checkbox groups: key_score[score][] = key_id
        if (isset($post['key_score']) && is_array($post['key_score'])) {
            foreach ($post['key_score'] as $scoreRaw => $ids) {
                $score = (int)$scoreRaw;
                if ($score < 1 || $score > 10) {
                    continue;
                }
                if (!is_array($ids)) {
                    $ids = [$ids];
                }
                foreach ($ids as $idRaw) {
                    $kid = (int)$idRaw;
                    if ($kid > 0) {
                        $clean[(string)$kid] = $score;
                    }
                }
            }
            return $clean;
        }
        $mapRaw = trim((string)($post['key_score_map'] ?? ''));
        if ($mapRaw === '') {
            return self::keyScoreMap();
        }
        $decoded = json_decode($mapRaw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(t('poll_speech.err.key_score_map'));
        }
        foreach ($decoded as $k => $v) {
            $kid = (int)$k;
            $score = (int)$v;
            if ($kid > 0 && $score >= 1 && $score <= 10) {
                $clean[(string)$kid] = $score;
            }
        }
        return $clean;
    }

    /**
     * Active (+ disabled) keys of the configured dictionary for settings UI.
     *
     * @return list<array{id:int,name:string,disabled:int,score:int}>
     */
    public static function dictKeysForUi(?int $dictId = null): array
    {
        $dictId = $dictId !== null ? max(0, $dictId) : self::dictId();
        if ($dictId < 1 || !class_exists('RecordsKeysRepository', false)) {
            return [];
        }
        $result = RecordsKeysRepository::list([
            'dict' => $dictId,
            'limit' => 0,
            'offset' => 0,
            'order' => 'k.DISABLED ASC, k.NAME ASC, k.ID ASC',
        ]);
        $map = self::keyScoreMap();
        $out = [];
        foreach ($result['items'] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string)($row['name'] ?? ''),
                'disabled' => (int)($row['disabled'] ?? 0),
                'score' => (int)($map[$id] ?? 0),
            ];
        }
        return $out;
    }

    public static function oralBtnEnabled(): bool
    {
        return (int)Config::get('poll_speech', 'oral_btn_enabled', 1) === 1;
    }

    public static function oralPollId(): int
    {
        return max(0, (int)Config::get('poll_speech', 'oral_poll_id', 0));
    }

    public static function dictId(): int
    {
        return max(0, (int)Config::get('poll_speech', 'dict_id', 50));
    }

    public static function windowSec(): int
    {
        return max(60, min(86400, (int)Config::get('poll_speech', 'window_sec', 1800)));
    }

    public static function adminField(): string
    {
        $field = (string)Config::get('poll_speech', 'admin_field', self::ADMIN_LINKED);
        if (!in_array($field, [self::ADMIN_LINKED, self::ADMIN_PROC, self::ADMIN_BOTH], true)) {
            return self::ADMIN_LINKED;
        }
        return $field;
    }

    public static function keyScoreMapRaw(): string
    {
        $raw = trim((string)Config::get('poll_speech', 'key_score_map', '{}'));
        return $raw !== '' ? $raw : '{}';
    }

    /** @return array<int,int> key_id => score 1..10 */
    public static function keyScoreMap(): array
    {
        $decoded = json_decode(self::keyScoreMapRaw(), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $kid = (int)$k;
            $score = (int)$v;
            if ($kid > 0 && $score >= 1 && $score <= 10) {
                $out[$kid] = $score;
            }
        }
        return $out;
    }

    public static function qidStaff(): int
    {
        return max(0, (int)Config::get('poll_speech', 'qid_staff', 0));
    }

    public static function qidService(): int
    {
        return max(0, (int)Config::get('poll_speech', 'qid_service', 0));
    }

    public static function answersStaffRaw(): string
    {
        return trim((string)Config::get('poll_speech', 'answers_staff', ''));
    }

    public static function answersServiceRaw(): string
    {
        return trim((string)Config::get('poll_speech', 'answers_service', ''));
    }

    /** @return array<int,int> score 1..10 => answer_id */
    public static function answersStaff(): array
    {
        return self::parseAnswersCsv(self::answersStaffRaw());
    }

    /** @return array<int,int> score 1..10 => answer_id */
    public static function answersService(): array
    {
        return self::parseAnswersCsv(self::answersServiceRaw());
    }

    /**
     * Persist mapping after oral poll migrator / seed.
     *
     * @param array{
     *   oral_poll_id:int,
     *   qid_staff:int,
     *   qid_service:int,
     *   answers_staff:list<int>,
     *   answers_service:list<int>
     * } $ids
     */
    public static function writePollMapping(array $ids): void
    {
        $pollId = max(0, (int)($ids['oral_poll_id'] ?? 0));
        Config::set('poll_speech', 'oral_poll_id', (string)$pollId);
        Config::set('poll_invites', 'oral_poll_id', (string)$pollId);
        Config::set('poll_speech', 'qid_staff', (string)max(0, (int)($ids['qid_staff'] ?? 0)));
        Config::set('poll_speech', 'qid_service', (string)max(0, (int)($ids['qid_service'] ?? 0)));
        $staff = is_array($ids['answers_staff'] ?? null) ? $ids['answers_staff'] : [];
        $service = is_array($ids['answers_service'] ?? null) ? $ids['answers_service'] : [];
        Config::set('poll_speech', 'answers_staff', implode(',', array_map('intval', $staff)));
        Config::set('poll_speech', 'answers_service', implode(',', array_map('intval', $service)));
    }

    private static function normalizeAnswersCsv(string $raw): string
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $ids = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $ids[] = max(0, (int)$p);
        }
        return implode(',', $ids);
    }

    /** @return array<int,int> score => answer_id */
    private static function parseAnswersCsv(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $out = [];
        $score = 1;
        foreach ($parts as $p) {
            if ($p === '' || $score > 10) {
                continue;
            }
            $aid = (int)$p;
            if ($aid > 0) {
                $out[$score] = $aid;
            }
            $score++;
        }
        return $out;
    }
}
