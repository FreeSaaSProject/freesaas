<?php
declare(strict_types=1);

/**
 * Public respondent channel: personal invite link /q/{code} (no login), personalisation,
 * re-fill as a new invite with prefilled answers, answers summary for other modules.
 * Public codes are long random tokens; short legacy codes are never served publicly.
 */
final class PollPublic
{
    public const CODE_LEN = 24;
    public const MIN_PUBLIC_CODE_LEN = 16;
    public const SOURCE = 'public';

    public static function findByCode(string $code): ?array
    {
        $code = trim($code);
        if (strlen($code) < self::MIN_PUBLIC_CODE_LEN || !preg_match('/^[A-Za-z0-9]+$/', $code)) {
            return null;
        }
        $st = Database::pdo()->prepare('SELECT ID FROM base_poll_invites WHERE CODE = ? LIMIT 2');
        $st->execute([$code]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (count($ids) !== 1) {
            return null;
        }
        return PollInvitesRepository::find((int)$ids[0]);
    }

    public static function url(string $code): string
    {
        $base = rtrim((string)Config::get('app', 'base_url', ''), '/');
        if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            $base = ($https ? 'https://' : 'http://') . (string)$_SERVER['HTTP_HOST'];
        }
        return $base . '/q/' . rawurlencode($code);
    }

    /** @return array{skip_qids:list<int>,hints:array<int,string>,vars:array<string,string>} */
    public static function personal(array $invite): array
    {
        $raw = Database::columnExists('base_poll_invites', 'PERSONAL_JSON')
            ? (string)(self::rawPersonal((int)$invite['id']) ?? '')
            : '';
        $d = json_decode($raw, true);
        $d = is_array($d) ? $d : [];
        $hints = [];
        foreach ((array)($d['hints'] ?? []) as $qid => $text) {
            if ((int)$qid > 0 && trim((string)$text) !== '') {
                $hints[(int)$qid] = (string)$text;
            }
        }
        $vars = [];
        foreach ((array)($d['vars'] ?? []) as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z_.]{1,40}$/', $k) && is_scalar($v)) {
                $vars[$k] = (string)$v;
            }
        }
        return [
            'skip_qids' => array_values(array_unique(array_filter(array_map('intval', (array)($d['skip_qids'] ?? []))))),
            'hints' => $hints,
            'vars' => $vars,
        ];
    }

    private static function rawPersonal(int $id): ?string
    {
        $st = Database::pdo()->prepare('SELECT PERSONAL_JSON FROM base_poll_invites WHERE ID = ?');
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    /** @param array{skip_qids?:list<int>,hints?:array<int,string>,vars?:array<string,string>} $personal */
    public static function setPersonal(int $inviteId, array $personal): void
    {
        if (!Database::columnExists('base_poll_invites', 'PERSONAL_JSON')) {
            return;
        }
        Database::pdo()->prepare('UPDATE base_poll_invites SET PERSONAL_JSON = ? WHERE ID = ?')->execute([
            json_encode([
                'skip_qids' => array_values(array_map('intval', (array)($personal['skip_qids'] ?? []))),
                'hints' => (object)(array)($personal['hints'] ?? []),
                'vars' => (object)(array)($personal['vars'] ?? []),
            ], JSON_UNESCAPED_UNICODE),
            $inviteId,
        ]);
    }

    /**
     * Walk tree for this invite: skipped questions removed, default "next" re-chained.
     *
     * @return array{poll:?array,questions:array<int,array<string,mixed>>,first_qid:int}
     */
    public static function treeFor(array $invite, array $personal): array
    {
        $tree = PollsRepository::buildWalkTree((int)$invite['id_poll']);
        if ($tree['poll'] === null) {
            return $tree;
        }
        $skip = array_flip($personal['skip_qids']);
        $questions = [];
        foreach ($tree['questions'] as $qid => $q) {
            if (isset($skip[$qid])) {
                continue;
            }
            foreach ($q['_ANSWERS_'] ?? [] as $aid => $a) {
                if (isset($skip[(int)($a['next_qid'] ?? 0)])) {
                    $q['_ANSWERS_'][$aid]['next_qid'] = 0;
                }
            }
            $questions[$qid] = $q;
        }
        $qids = array_keys($questions);
        foreach ($qids as $i => $qid) {
            $questions[$qid]['NEXT_QID_DEFAULT'] = $qids[$i + 1] ?? 0;
        }
        return ['poll' => $tree['poll'], 'questions' => $questions, 'first_qid' => (int)($qids[0] ?? 0)];
    }

    /**
     * Placeholders {client.name} etc.; modules add more via EventBus `poll_invites.public.context` ({invite, vars}).
     *
     * @return array<string,string>
     */
    public static function vars(array $invite, array $personal): array
    {
        $vars = [];
        $clientId = (int)($invite['id_user'] ?? 0);
        if ($clientId > 0 && class_exists('ClientsRepository')) {
            $c = ClientsRepository::find($clientId);
            if ($c !== null) {
                $name = trim((string)($c['name'] ?? ''));
                $vars['client.name'] = $name;
                $vars['client.first_name'] = trim((string)($c['pname'] ?? '')) !== ''
                    ? (string)$c['pname']
                    : (string)(preg_split('/\s+/', $name)[0] ?? '');
            }
        }
        $ctx = EventBus::emit('poll_invites.public.context', ['invite' => $invite, 'vars' => $vars]);
        if (is_array($ctx) && is_array($ctx['vars'] ?? null)) {
            $vars = $ctx['vars'];
        }
        return array_merge($vars, $personal['vars']);
    }

    /** @param array<string,string> $vars */
    public static function render(string $text, array $vars): string
    {
        return (string)preg_replace_callback('/\{([a-z_.]{1,40})\}/', static function (array $m) use ($vars): string {
            return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
        }, $text);
    }

    /** @return 'not_yet'|'open'|'started'|'completed'|'expired' */
    public static function state(array $invite): string
    {
        $now = time();
        if ((int)$invite['dt_avail'] > $now) {
            return 'not_yet';
        }
        $expired = (int)$invite['dt_expire'] > 0 && (int)$invite['dt_expire'] < $now;
        if ((int)$invite['dt_completed'] > 0) {
            return $expired ? 'expired' : 'completed';
        }
        if ($expired) {
            return 'expired';
        }
        return (int)$invite['dt_started'] > 0 ? 'started' : 'open';
    }

    public static function canAnswer(array $invite): bool
    {
        $s = self::state($invite);
        return $s === 'open' || $s === 'started' || $s === 'completed';
    }

    /**
     * Personal invite with a public link.
     *
     * @param array{poll_id:int,client_id?:int,text?:string,expire_days?:int,resp_user_ids?:list<int>,
     *   skip_qids?:list<int>,hints?:array<int,string>,vars?:array<string,string>,
     *   data_opts?:array,data_text?:array,source?:string,log?:string} $opts
     */
    public static function create(array $opts): int
    {
        $days = max(1, min(365, (int)($opts['expire_days'] ?? 14)));
        $data = PollInvitesRepository::dataFromPost([
            'id_poll' => (int)$opts['poll_id'],
            'id_user' => (int)($opts['client_id'] ?? 0),
            'id_operator_linked' => (int)Auth::userId(),
            'text_admin_to_user' => (string)($opts['text'] ?? ''),
            'dt_expire' => (string)(time() + $days * 86400),
            'log' => (string)($opts['log'] ?? ''),
        ]);
        $resp = array_values(array_unique(array_filter(array_map('intval', $opts['resp_user_ids'] ?? [(int)Auth::userId()]))));
        $data['code'] = self::newCode();
        $data['resp_users'] = $resp !== [] ? '#' . implode('#,#', $resp) . '#' : '';
        $data['source'] = (string)($opts['source'] ?? self::SOURCE);
        $id = PollInvitesRepository::create($data);
        self::setPersonal($id, [
            'skip_qids' => $opts['skip_qids'] ?? [],
            'hints' => $opts['hints'] ?? [],
            'vars' => $opts['vars'] ?? [],
        ]);
        if (!empty($opts['data_opts']) || !empty($opts['data_text'])) {
            Database::pdo()->prepare('UPDATE base_poll_invites SET DATA_OPTS = ?, DATA_TEXT = ? WHERE ID = ?')->execute([
                PollAnswersCodec::encode((array)($opts['data_opts'] ?? [])),
                PollAnswersCodec::encode((array)($opts['data_text'] ?? [])),
                $id,
            ]);
        }
        return $id;
    }

    /** New invite for the same respondent with the previous answers prefilled; the old one stays as history. */
    public static function refill(int $inviteId, int $expireDays = 14): int
    {
        $old = PollInvitesRepository::find($inviteId);
        if ($old === null) {
            throw new InvalidArgumentException(t('poll_invites.err.not_found', ['id' => $inviteId]));
        }
        $personal = self::personal($old);
        preg_match_all('/#(\d+)#/', (string)($old['resp_users'] ?? ''), $m);
        $resp = array_values(array_unique(array_map('intval', $m[1] ?? [])));
        $newId = self::create([
            'poll_id' => (int)$old['id_poll'],
            'client_id' => (int)$old['id_user'],
            'text' => (string)$old['text_admin_to_user'],
            'expire_days' => $expireDays,
            'resp_user_ids' => $resp !== [] ? $resp : [(int)Auth::userId()],
            'skip_qids' => $personal['skip_qids'],
            'hints' => $personal['hints'],
            'vars' => $personal['vars'],
            'data_opts' => PollAnswersCodec::decode((string)($old['data_opts'] ?? '')),
            'data_text' => PollAnswersCodec::decode((string)($old['data_text'] ?? '')),
            'source' => 'refill',
            'log' => '[' . date('d.m.y H:i') . '][' . (int)Auth::userId() . ']: refill of #' . $inviteId . "\n",
        ]);
        EventBus::emit('poll_invites.refilled', ['invite_id' => $newId, 'from_invite_id' => $inviteId]);
        return $newId;
    }

    /**
     * Answered path as readable rows (question, chosen labels, free text).
     *
     * @return list<array{qid:int,question:string,answer:string,text:string}>
     */
    public static function answersSummary(array $invite): array
    {
        $personal = self::personal($invite);
        $tree = self::treeFor($invite, $personal);
        if ($tree['poll'] === null) {
            return [];
        }
        $opts = PollAnswersCodec::decode((string)($invite['data_opts'] ?? ''));
        $texts = PollAnswersCodec::decode((string)($invite['data_text'] ?? ''));
        $out = [];
        foreach ($tree['questions'] as $qid => $q) {
            $hasOpt = array_key_exists($qid, $opts) || array_key_exists((string)$qid, $opts);
            $text = trim((string)($texts[$qid] ?? $texts[(string)$qid] ?? ''));
            if (!$hasOpt && $text === '') {
                continue;
            }
            $out[] = [
                'qid' => (int)$qid,
                'question' => (string)$q['name'],
                'answer' => $hasOpt ? PollWalkEngine::answerLabel($q, $opts[$qid] ?? $opts[(string)$qid]) : '',
                'text' => $text,
            ];
        }
        return $out;
    }

    private static function newCode(): string
    {
        for ($i = 0; $i < 5; $i++) {
            $code = PollInvitesRepository::generateCode(self::CODE_LEN);
            if (strlen($code) === self::CODE_LEN) {
                return $code;
            }
        }
        throw new RuntimeException('code generation failed');
    }
}
