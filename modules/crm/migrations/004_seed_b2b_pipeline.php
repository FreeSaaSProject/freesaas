<?php
declare(strict_types=1);

/** B2B sale pipeline: meeting → questionnaire → proposal → pilot (idempotent by kind + code set). */
return static function (PDO $pdo): void {
    $ru = !class_exists('I18n', false) || I18n::locale() !== 'en';
    $pipeName = 'B2B';
    $stages = [
        ['NEW', $ru ? 'Новый лид' : 'New lead', 'P', 10],
        ['MEETING', $ru ? 'Встреча назначена' : 'Meeting scheduled', 'P', 20],
        ['QUESTIONNAIRE_SENT', $ru ? 'Анкета отправлена' : 'Questionnaire sent', 'P', 30],
        ['QUESTIONNAIRE_DONE', $ru ? 'Анкета получена' : 'Questionnaire received', 'P', 40],
        ['PROPOSAL', $ru ? 'Предложение' : 'Proposal', 'P', 50],
        ['PILOT', $ru ? 'Пилот / договор' : 'Pilot / contract', 'P', 60],
        ['WON', $ru ? 'Выиграна' : 'Won', 'S', 90],
        ['LOST', $ru ? 'Проиграна' : 'Lost', 'F', 100],
    ];

    $st = $pdo->prepare("SELECT id FROM base_crm_pipelines WHERE kind = 'sale' AND name = ? LIMIT 1");
    $st->execute([$pipeName]);
    $pipeId = (int)($st->fetchColumn() ?: 0);
    if ($pipeId < 1) {
        $pdo->prepare(
            "INSERT INTO base_crm_pipelines (name, kind, enabled, sort_order, created_at) VALUES (?, 'sale', 1, 5, ?)"
        )->execute([$pipeName, time()]);
        $pipeId = (int)$pdo->lastInsertId();
    }
    if ($pipeId < 1) {
        return;
    }
    foreach ($stages as [$code, $name, $semantic, $sort]) {
        $chk = $pdo->prepare('SELECT id FROM base_crm_stages WHERE pipeline_id = ? AND code = ? LIMIT 1');
        $chk->execute([$pipeId, $code]);
        if ((int)($chk->fetchColumn() ?: 0) > 0) {
            continue;
        }
        $pdo->prepare(
            'INSERT INTO base_crm_stages (pipeline_id, name, code, semantic, sort_order, enabled) VALUES (?,?,?,?,?,1)'
        )->execute([$pipeId, $name, $code, $semantic, $sort]);
    }
};
