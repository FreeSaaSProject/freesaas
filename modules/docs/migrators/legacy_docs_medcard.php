<?php
declare(strict_types=1);

/**
 * Optional one-shot import of legacy medical card data from elos/oper (run explicitly):
 * - phpshop_shopusers ANA_* / OBJ_* → medcard_profile per client
 * - medical base_procedures with ANA_* / DIA_CODE / RECOMENDS / ID_DOCTOR → medcard_visit per procedure
 * Sources and columns are probed; missing ones are skipped. Idempotent (existing rows are kept).
 * Document templates are not copied here: export them to files with bin/docs-export-legacy.php
 * and import the ones you need on /documents/defs/import.
 */
return static function (PDO $pdo, array $spec): array {
    if (!class_exists('DocsInstancesRepository', false) || !DocsInstancesRepository::tableReady()) {
        throw new RuntimeException('Enable module docs and run its schema migrations first');
    }
    $tableExists = static function (string $table) use ($pdo): bool {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    };
    $columns = static function (string $table) use ($pdo): array {
        $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$table]);
        return array_fill_keys(array_map('strtoupper', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
    };
    $ids = static function ($raw): array {
        $out = [];
        foreach (preg_split('/[#,;\s]+/', (string)$raw) ?: [] as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    };

    DocsDefsRepository::ensureSeeded();
    $report = ['profiles' => 0, 'visits' => 0];

    // 1. Client standing anamnesis → medcard_profile
    $profileDef = DocsDefsRepository::firstOfKind('medcard_profile');
    if ($profileDef !== null && $tableExists('phpshop_shopusers')) {
        $cols = $columns('phpshop_shopusers');
        $map = [
            'ANA_LIFE' => 'ana_life', 'ANA_GYNO' => 'ana_gyno', 'ANA_ALERG' => 'ana_alerg',
            'OBJ_SKIN' => 'obj_skin', 'OBJ_SLIZ' => 'obj_sliz', 'OBJ_NOGOT' => 'obj_nogot',
            'WORK_PLACE' => 'work_place', 'WORK_POSITION' => 'work_position', 'DISPANSER' => 'dispensary',
            'HEIGHT' => 'height_cm', 'WEIGHT' => 'weight_kg',
        ];
        $present = array_values(array_filter(array_keys($map), static fn(string $c): bool => isset($cols[$c])));
        $hasContras = isset($cols['ANA_CONTRAS']);
        $contraNames = [];
        if ($hasContras && $tableExists('base_contradictions') && isset($columns('base_contradictions')['ANA_LIFE_NAME'])) {
            foreach ($pdo->query('SELECT ID, ANA_LIFE_NAME FROM base_contradictions')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $contraNames[(int)$c['ID']] = trim((string)$c['ANA_LIFE_NAME']);
            }
        }
        if ($present !== [] || $hasContras) {
            $select = array_merge(['id'], $present, $hasContras ? ['ANA_CONTRAS'] : []);
            $st = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM phpshop_shopusers');
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $clientId = (int)$r['id'];
                $payload = [];
                foreach ($present as $col) {
                    $v = trim((string)($r[$col] ?? ''));
                    if ($v !== '') {
                        $payload[$map[$col]] = $v;
                    }
                }
                if ($hasContras) {
                    $names = [];
                    foreach ($ids($r['ANA_CONTRAS'] ?? '') as $cid) {
                        $names[] = $contraNames[$cid] ?? ('#' . $cid);
                    }
                    if ($names !== []) {
                        $payload['ana_contras'] = implode(', ', $names);
                    }
                }
                if ($payload === [] || DocsInstancesRepository::latestForClient($clientId, 'medcard_profile') !== null) {
                    continue;
                }
                DocsInstancesRepository::create($profileDef, ['client' => $clientId, 'payload' => $payload, 'source' => 'import']);
                $report['profiles']++;
            }
        }
    }

    // 2. Medical procedure sheets → medcard_visit
    $visitDef = DocsDefsRepository::firstOfKind('medcard_visit');
    if ($visitDef !== null && $tableExists('base_procedures') && $tableExists('base_procedure_types')) {
        $cols = $columns('base_procedures');
        $map = ['JAL' => 'complaints', 'ANA_GEN' => 'ana_gen', 'ANA_OBJ' => 'ana_obj', 'DIA_CODE' => 'dia_codes', 'RECOMENDS' => 'recommends', 'ID_DOCTOR' => 'doctor_id'];
        $present = array_values(array_filter(array_keys($map), static fn(string $c): bool => isset($cols[$c])));
        if ($present !== []) {
            $exists = $pdo->prepare("SELECT 1 FROM base_doc_instances WHERE KIND = 'medcard_visit' AND ID_PROCEDURE = ? LIMIT 1");
            $st = $pdo->query(
                'SELECT p.ID, p.ID_USER, p.ID_EVENT_PARENT, p.DTSTART, p.' . implode(', p.', $present) . '
                 FROM base_procedures p
                 JOIN base_procedure_types t ON t.ID = p.TYPE AND t.IS_MEDICAL > 0
                 ORDER BY p.DTSTART ASC'
            );
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $payload = [];
                foreach ($present as $col) {
                    $v = $r[$col] ?? '';
                    if ($col === 'ID_DOCTOR') {
                        if ((int)$v > 0) {
                            $payload['doctor_id'] = (int)$v;
                        }
                        continue;
                    }
                    $v = trim((string)$v);
                    if ($col === 'DIA_CODE') {
                        $v = trim(str_replace('#', ', ', $v), ', ');
                    }
                    if ($v !== '') {
                        $payload[$map[$col]] = $v;
                    }
                }
                $procId = (int)$r['ID'];
                $textual = array_diff_key($payload, ['doctor_id' => true]);
                if ($textual === [] || (int)$r['ID_USER'] < 1) {
                    continue;
                }
                $exists->execute([$procId]);
                if ($exists->fetchColumn()) {
                    continue;
                }
                DocsInstancesRepository::create($visitDef, [
                    'client' => (int)$r['ID_USER'],
                    'event' => (int)$r['ID_EVENT_PARENT'],
                    'procedure' => $procId,
                    'payload' => $payload,
                    'source' => 'import',
                ]);
                $report['visits']++;
            }
        }
    }

    return $report;
};
