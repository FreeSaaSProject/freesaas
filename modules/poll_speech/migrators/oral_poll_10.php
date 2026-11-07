<?php
declare(strict_types=1);

/**
 * Create root poll «Оценка посещения (устная, 10)» with two 1–10 rating questions
 * if missing; write ids into poll_speech (+ poll_invites oral_poll_id).
 *
 * Idempotent by poll NAME.
 *
 * @return array{ok:bool,message:string,oral_poll_id:int,qid_staff:int,qid_service:int,answers_staff:list<int>,answers_service:list<int>,created:bool}
 */
return static function (PDO $pdo, array $spec): array {
    if (!Database::tableExists('base_polls') || !class_exists('PollsRepository', false)) {
        return [
            'ok' => false,
            'message' => 'polls unavailable',
            'oral_poll_id' => 0,
            'qid_staff' => 0,
            'qid_service' => 0,
            'answers_staff' => [],
            'answers_service' => [],
            'created' => false,
        ];
    }

    $pollName = 'Оценка посещения (устная, 10)';
    $st = $pdo->prepare(
        'SELECT ID FROM base_polls WHERE PID = 0 AND NAME = ? ORDER BY ID ASC LIMIT 1'
    );
    $st->execute([$pollName]);
    $existingId = (int)$st->fetchColumn();
    $created = false;

    $emptyNode = static function (array $over): array {
        $base = [
            'pid' => 0,
            'el_type' => PollsRepository::EL_POLL,
            'name' => '',
            'descr_admin' => '',
            'descr_site' => '',
            'resp_groups' => '',
            'bonus_default' => 0,
            'bonus_auto' => 0,
            'enabled' => 1,
            'req_text_len' => 0,
            'num' => 0,
            'qty' => 0,
            'store_to_user' => 0,
            'multy_opts' => 0,
            'types' => '',
            'procs' => '',
            'next_qid' => 0,
            'who_can_pass' => 0,
            'show2user' => 0,
            'add2dtnext' => 0,
            'status_on_sel' => 0,
            'show_in_tray' => 0,
            'tray_code' => '',
            'statuses' => '',
            'negative' => 0,
            'multy_answer' => 0,
            'ans_end_poll' => 0,
            'after_event' => 0,
            'after_event_stop_rew' => 0,
            'after_event_time' => 0,
            'after_event_expire_time' => 0,
            'show_history' => 0,
            'not_repass' => 0,
            'is_special' => 0,
            'is_special_friend' => 0,
            'is_result' => 0,
            'notice_sms' => 0,
            'notice_email' => 0,
            'notice_sms_tpl_id' => 0,
            'notice_email_tpl_id' => 0,
        ];
        return array_merge($base, $over);
    };

    $labels = [
        1 => '1 — ужасно',
        2 => '2 — очень плохо',
        3 => '3 — плохо',
        4 => '4 — слабо',
        5 => '5 — удовлетворительно',
        6 => '6 — ниже среднего',
        7 => '7 — нормально',
        8 => '8 — хорошо',
        9 => '9 — отлично',
        10 => '10 — превосходно',
    ];

    if ($existingId > 0) {
        $pollId = $existingId;
        $qStaff = 0;
        $qService = 0;
        $stQ = $pdo->prepare(
            'SELECT ID, NAME FROM base_polls WHERE PID = ? AND EL_TYPE = ? ORDER BY NUM ASC, ID ASC'
        );
        $stQ->execute([$pollId, PollsRepository::EL_QUESTION]);
        foreach ($stQ->fetchAll(PDO::FETCH_ASSOC) ?: [] as $q) {
            $name = (string)($q['NAME'] ?? '');
            if ($qStaff < 1 && mb_stripos($name, 'сотрудник') !== false) {
                $qStaff = (int)$q['ID'];
            }
            if ($qService < 1 && (mb_stripos($name, 'услуг') !== false || mb_stripos($name, 'процедур') !== false)) {
                $qService = (int)$q['ID'];
            }
        }
        $loadAnswers = static function (int $qid) use ($pdo): array {
            if ($qid < 1) {
                return [];
            }
            $st = $pdo->prepare(
                'SELECT ID FROM base_polls WHERE PID = ? AND EL_TYPE = ? ORDER BY NUM ASC, ID ASC'
            );
            $st->execute([$qid, PollsRepository::EL_ANSWER]);
            $ids = [];
            $score = 1;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $aid) {
                if ($score > 10) {
                    break;
                }
                $ids[] = (int)$aid;
                $score++;
            }
            return $ids;
        };
        $ansStaff = $loadAnswers($qStaff);
        $ansService = $loadAnswers($qService);
    } else {
        $pollId = PollsRepository::create($emptyNode([
            'name' => $pollName,
            'descr_admin' => 'Устный опрос 1–10 (речевая аналитика / poll_speech)',
            'is_result' => 1,
            'enabled' => 1,
            'num' => 100,
        ]));
        $created = true;

        $qStaff = PollsRepository::create($emptyNode([
            'pid' => $pollId,
            'el_type' => PollsRepository::EL_QUESTION,
            'name' => 'Как справился сотрудник?',
            'num' => 1,
            'enabled' => 1,
        ]));
        $qService = PollsRepository::create($emptyNode([
            'pid' => $pollId,
            'el_type' => PollsRepository::EL_QUESTION,
            'name' => 'Оцените саму услугу / процедуру',
            'num' => 2,
            'enabled' => 1,
        ]));

        $ansStaff = [];
        $ansService = [];
        for ($score = 1; $score <= 10; $score++) {
            $ansStaff[] = PollsRepository::create($emptyNode([
                'pid' => $qStaff,
                'el_type' => PollsRepository::EL_ANSWER,
                'name' => $labels[$score],
                'num' => $score,
                'enabled' => 1,
            ]));
            $ansService[] = PollsRepository::create($emptyNode([
                'pid' => $qService,
                'el_type' => PollsRepository::EL_ANSWER,
                'name' => $labels[$score],
                'num' => $score,
                'enabled' => 1,
            ]));
        }
    }

    if (class_exists('PollSpeechConfig', false) && $pollId > 0) {
        PollSpeechConfig::writePollMapping([
            'oral_poll_id' => $pollId,
            'qid_staff' => $qStaff,
            'qid_service' => $qService,
            'answers_staff' => $ansStaff,
            'answers_service' => $ansService,
        ]);
    }

    return [
        'ok' => $pollId > 0 && $qStaff > 0 && $qService > 0 && count($ansStaff) === 10,
        'message' => $created ? 'created oral 10-point poll' : 'reused existing oral 10-point poll',
        'oral_poll_id' => $pollId,
        'qid_staff' => $qStaff,
        'qid_service' => $qService,
        'answers_staff' => $ansStaff,
        'answers_service' => $ansService,
        'created' => $created,
    ];
};
