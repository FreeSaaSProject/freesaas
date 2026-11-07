<?php
declare(strict_types=1);

/** Default lead + sale pipelines with won/lost semantics (idempotent by pipeline name+kind). */
return static function (PDO $pdo): void {
    $now = time();
    $seeds = [
        [
            'name' => 'Leads',
            'kind' => 'lead',
            'stages' => [
                ['NEW', 'New', 'P', 10],
                ['IN_PROGRESS', 'In progress', 'P', 20],
                ['CONVERTED', 'Converted', 'S', 90],
                ['JUNK', 'Junk', 'F', 100],
            ],
        ],
        [
            'name' => 'Sales',
            'kind' => 'sale',
            'stages' => [
                ['NEW', 'New', 'P', 10],
                ['PREPARATION', 'Preparation', 'P', 20],
                ['PROPOSAL', 'Proposal', 'P', 30],
                ['WON', 'Won', 'S', 90],
                ['LOST', 'Lost', 'F', 100],
            ],
        ],
    ];

    foreach ($seeds as $pipe) {
        $st = $pdo->prepare(
            'SELECT id FROM base_crm_pipelines WHERE kind = ? AND name = ? LIMIT 1'
        );
        $st->execute([$pipe['kind'], $pipe['name']]);
        $pipeId = (int)($st->fetchColumn() ?: 0);
        if ($pipeId < 1) {
            $pdo->prepare(
                'INSERT INTO base_crm_pipelines (name, kind, enabled, sort_order, created_at)
                 VALUES (?,?,1,?,?)'
            )->execute([$pipe['name'], $pipe['kind'], $pipe['kind'] === 'lead' ? 10 : 20, $now]);
            $pipeId = (int)$pdo->lastInsertId();
        }
        if ($pipeId < 1) {
            continue;
        }
        foreach ($pipe['stages'] as [$code, $name, $semantic, $sort]) {
            $chk = $pdo->prepare(
                'SELECT id FROM base_crm_stages WHERE pipeline_id = ? AND code = ? LIMIT 1'
            );
            $chk->execute([$pipeId, $code]);
            if ((int)($chk->fetchColumn() ?: 0) > 0) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO base_crm_stages (pipeline_id, name, code, semantic, sort_order, enabled)
                 VALUES (?,?,?,?,?,1)'
            )->execute([$pipeId, $name, $code, $semantic, $sort]);
        }
    }
};
