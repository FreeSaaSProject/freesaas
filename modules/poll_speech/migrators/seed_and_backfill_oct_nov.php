<?php
declare(strict_types=1);

/**
 * Instance bootstrap for oral speech polls:
 * 1) create oral 1–10 poll + write qid/answers into ini
 * 2) seed dict 50 score keys + key_score_map
 * 3) defaults (button, window, admin_field)
 * 4) optional repoint legacy poll #1 → oral poll
 * 5) enrich dict from cabin transcripts in period
 * 6) create+fill invites for paid procedures in period (twice: after enrich)
 *
 * Period defaults: 2026-10-01 … 2026-11-30.
 * Override: POLL_SPEECH_BACKFILL_FROM / POLL_SPEECH_BACKFILL_TO (YYYY-MM-DD).
 *
 * Idempotent where possible (skips procedures that already have an oral invite).
 *
 * @return array<string,mixed>
 */
return static function (PDO $pdo, array $spec): array {
    $from = trim((string)(getenv('POLL_SPEECH_BACKFILL_FROM') ?: '2026-10-01'));
    $to = trim((string)(getenv('POLL_SPEECH_BACKFILL_TO') ?: '2026-11-30'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        return [
            'ok' => false,
            'message' => 'bad POLL_SPEECH_BACKFILL_FROM/TO (need YYYY-MM-DD)',
            'from' => $from,
            'to' => $to,
        ];
    }
    $fromTs = (int)strtotime($from . ' 00:00:00');
    $toTs = (int)strtotime($to . ' 23:59:59');
    if ($fromTs < 1 || $toTs < $fromTs) {
        return ['ok' => false, 'message' => 'invalid period', 'from' => $from, 'to' => $to];
    }

    $runChild = static function (string $id) use ($pdo, $spec): array {
        $path = dirname(__DIR__) . '/migrators/' . $id . '.php';
        if (!is_file($path)) {
            return ['ok' => false, 'message' => 'missing child migrator ' . $id];
        }
        /** @var callable $fn */
        $fn = require $path;
        if (!is_callable($fn)) {
            return ['ok' => false, 'message' => 'child migrator not callable: ' . $id];
        }
        $out = $fn($pdo, $spec);
        return is_array($out) ? $out : ['ok' => false, 'message' => 'bad child result: ' . $id];
    };

    $steps = [];

    $poll = $runChild('oral_poll_10');
    $steps['oral_poll_10'] = [
        'ok' => !empty($poll['ok']),
        'oral_poll_id' => (int)($poll['oral_poll_id'] ?? 0),
        'qid_staff' => (int)($poll['qid_staff'] ?? 0),
        'created' => !empty($poll['created']),
        'message' => (string)($poll['message'] ?? ''),
    ];
    if (empty($poll['ok'])) {
        return [
            'ok' => false,
            'message' => 'oral_poll_10 failed',
            'from' => $from,
            'to' => $to,
            'steps' => $steps,
        ];
    }

    $seed = $runChild('seed_dict50_scores');
    $steps['seed_dict50_scores'] = [
        'ok' => !empty($seed['ok']),
        'dict_id' => (int)($seed['dict_id'] ?? 0),
        'created' => (int)($seed['created'] ?? 0),
        'map_keys' => is_array($seed['map'] ?? null) ? count($seed['map']) : 0,
        'message' => (string)($seed['message'] ?? ''),
    ];
    if (empty($seed['ok'])) {
        return [
            'ok' => false,
            'message' => 'seed_dict50_scores failed (is dict #50 present?)',
            'from' => $from,
            'to' => $to,
            'steps' => $steps,
        ];
    }

    if (class_exists('Config', false)) {
        Config::set('poll_speech', 'oral_btn_enabled', '1');
        Config::set('poll_speech', 'window_sec', '900');
        Config::set('poll_speech', 'admin_field', 'both');
        Config::set('poll_speech', 'dict_id', (string)max(1, (int)($seed['dict_id'] ?? 50)));
    }
    $steps['defaults'] = [
        'ok' => true,
        'oral_btn_enabled' => 1,
        'window_sec' => 900,
        'admin_field' => 'both',
    ];

    $repoint = $runChild('repoint_oral_poll1_to_55');
    $steps['repoint_oral_poll1_to_55'] = [
        'ok' => !empty($repoint['ok']),
        'message' => (string)($repoint['message'] ?? ''),
        'updated' => (int)($repoint['updated'] ?? $repoint['moved'] ?? 0),
    ];

    $enrichOnce = static function () use ($fromTs, $toTs): array {
        if (!class_exists('PollSpeechDictEnrich', false)) {
            return ['ok' => false, 'created' => 0, 'map_added' => 0, 'invites' => 0];
        }
        return PollSpeechDictEnrich::enrichRange([
            'from_ts' => $fromTs,
            'to_ts' => $toTs,
            'margin_sec' => 900,
            'dry_run' => false,
        ]);
    };

    $fillOnce = static function () use ($from, $to): array {
        if (!class_exists('PollSpeechFiller', false)) {
            return ['ok' => false, 'created' => 0, 'filled' => 0, 'skipped' => 0, 'errors' => ['filler missing']];
        }
        return PollSpeechFiller::fillRangeFromSpeech([
            'from' => $from,
            'to' => $to,
        ]);
    };

    // Pass 1: mine phrases near any existing oral marks, then create+fill from procedures.
    $enrich1 = $enrichOnce();
    $steps['enrich_pass1'] = [
        'ok' => !empty($enrich1['ok']),
        'invites' => (int)($enrich1['invites'] ?? 0),
        'created_keys' => (int)($enrich1['created'] ?? 0),
        'map_added' => (int)($enrich1['map_added'] ?? 0),
    ];

    $fill1 = $fillOnce();
    $steps['fill_pass1'] = [
        'ok' => !empty($fill1['ok']),
        'created' => (int)($fill1['created'] ?? 0),
        'filled' => (int)($fill1['filled'] ?? 0),
        'skipped' => (int)($fill1['skipped'] ?? 0),
        'errors' => is_array($fill1['errors'] ?? null) ? count($fill1['errors']) : 0,
    ];

    // Pass 2: new keys from pass1 invites → retry procedures still without an invite.
    $enrich2 = $enrichOnce();
    $steps['enrich_pass2'] = [
        'ok' => !empty($enrich2['ok']),
        'invites' => (int)($enrich2['invites'] ?? 0),
        'created_keys' => (int)($enrich2['created'] ?? 0),
        'map_added' => (int)($enrich2['map_added'] ?? 0),
    ];

    $fill2 = $fillOnce();
    $steps['fill_pass2'] = [
        'ok' => !empty($fill2['ok']),
        'created' => (int)($fill2['created'] ?? 0),
        'filled' => (int)($fill2['filled'] ?? 0),
        'skipped' => (int)($fill2['skipped'] ?? 0),
        'errors' => is_array($fill2['errors'] ?? null) ? count($fill2['errors']) : 0,
    ];

    // Put completed oral invites for the period onto the unviewed queue (staff + directors).
    $reopened = 0;
    $pollId = (int)$steps['oral_poll_10']['oral_poll_id'];
    if ($pollId > 0 && class_exists('PollInvitesRepository', false)
        && Database::tableExists('base_poll_invites')
    ) {
        $st = $pdo->prepare(
            'SELECT ID AS id FROM base_poll_invites
             WHERE ID_POLL = ? AND DT_COMPLETED > 0 AND ID_PROC > 0
               AND DT_CREATE >= ? AND DT_CREATE <= ?
             ORDER BY ID ASC'
        );
        $st->execute([$pollId, $fromTs, $toTs]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $reopened = PollInvitesRepository::reopenForReview($ids);
    }
    $steps['reopen_review'] = [
        'ok' => true,
        'reopened' => $reopened,
        'directors' => class_exists('PollInvitesRepository', false)
            ? count(PollInvitesRepository::listDirectorUserIds())
            : 0,
    ];

    $created = (int)$steps['fill_pass1']['created'] + (int)$steps['fill_pass2']['created'];
    $filled = (int)$steps['fill_pass1']['filled'] + (int)$steps['fill_pass2']['filled'];
    $mapAdded = (int)$steps['enrich_pass1']['map_added'] + (int)$steps['enrich_pass2']['map_added'];
    $ok = !empty($steps['oral_poll_10']['ok'])
        && !empty($steps['seed_dict50_scores']['ok'])
        && !empty($steps['fill_pass1']['ok']);

    return [
        'ok' => $ok,
        'message' => sprintf(
            'period %s…%s: invites created=%d filled=%d; map_added=%d; reopened=%d; poll=#%d dict=#%d',
            $from,
            $to,
            $created,
            $filled,
            $mapAdded,
            $reopened,
            (int)$steps['oral_poll_10']['oral_poll_id'],
            (int)$steps['seed_dict50_scores']['dict_id']
        ),
        'from' => $from,
        'to' => $to,
        'created' => $created,
        'filled' => $filled,
        'map_added' => $mapAdded,
        'reopened' => $reopened,
        'oral_poll_id' => (int)$steps['oral_poll_10']['oral_poll_id'],
        'dict_id' => (int)$steps['seed_dict50_scores']['dict_id'],
        'steps' => $steps,
    ];
};
