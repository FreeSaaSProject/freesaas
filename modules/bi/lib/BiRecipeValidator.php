<?php
declare(strict_types=1);

/**
 * Validate KPI JSON recipes (schema + dry-run via engine).
 */
final class BiRecipeValidator
{
    /**
     * @param array<string,mixed>|string $recipe
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,recipe?:array<string,mixed>,dry_run?:array<string,mixed>}
     */
    public static function validate($recipe, string $expectedId = '', bool $dryRun = true): array
    {
        $errors = [];
        $warnings = [];

        if (is_string($recipe)) {
            $recipe = trim($recipe);
            if ($recipe === '') {
                return ['ok' => false, 'errors' => ['empty_recipe'], 'warnings' => []];
            }
            $decoded = json_decode($recipe, true);
            if (!is_array($decoded)) {
                return ['ok' => false, 'errors' => ['json_invalid'], 'warnings' => []];
            }
            $recipe = $decoded;
        }
        if (!is_array($recipe)) {
            return ['ok' => false, 'errors' => ['recipe_not_object'], 'warnings' => []];
        }

        $v = (int)($recipe['v'] ?? 0);
        if ($v !== 1) {
            $errors[] = 'v: must be 1';
        }

        $id = BiFamilyDefsRepository::safeId((string)($recipe['id'] ?? ''));
        if ($id === '' || strlen($id) < 2) {
            $errors[] = 'id: invalid';
        }
        if ($expectedId !== '' && $id !== BiFamilyDefsRepository::safeId($expectedId)) {
            $errors[] = 'id_mismatch: recipe id "' . $id . '", expected "' . $expectedId . '"';
        }
        if (ReportRegistry::isBuiltinId($id)) {
            $warnings[] = 'id_shadows_builtin';
        }

        $title = trim((string)($recipe['title'] ?? ''));
        if ($title === '') {
            $warnings[] = 'title_empty';
        }

        $requires = is_array($recipe['requires'] ?? null) ? $recipe['requires'] : [];
        foreach ($requires as $i => $req) {
            $req = (string)$req;
            $ds = BiDatasetCatalog::get($req);
            if ($ds === null) {
                $errors[] = 'requires[' . $i . ']: unknown dataset ' . $req;
            } elseif (!BiDatasetCatalog::isReady($req)) {
                $warnings[] = 'requires_unavailable:' . $req;
            }
        }

        if (trim((string)($recipe['runner'] ?? '')) !== '') {
            $errors[] = 'runner: removed — use queries/derive instead';
        }

        $frameNames = [];
        $queries = is_array($recipe['queries'] ?? null) ? $recipe['queries'] : [];
        foreach ($queries as $i => $q) {
            if (!is_array($q)) {
                $errors[] = 'queries[' . $i . ']: not object';
                continue;
            }
            $as = (string)($q['as'] ?? '');
            if ($as === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $as)) {
                $errors[] = 'queries[' . $i . '].as: invalid';
            } else {
                $frameNames[$as] = true;
            }
            self::validateQuery($q, 'queries[' . $i . ']', $errors);
        }

        $derive = is_array($recipe['derive'] ?? null) ? $recipe['derive'] : [];
        foreach ($derive as $i => $d) {
            if (!is_array($d)) {
                $errors[] = 'derive[' . $i . ']: not object';
                continue;
            }
            $as = (string)($d['as'] ?? '');
            $from = (string)($d['from'] ?? '');
            if ($as === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $as)) {
                $errors[] = 'derive[' . $i . '].as: invalid';
            }
            if ($from === '' || !isset($frameNames[$from])) {
                $okFrom = isset($frameNames[$from]);
                if (!$okFrom) {
                    for ($j = 0; $j < $i; $j++) {
                        if (is_array($derive[$j] ?? null) && (string)($derive[$j]['as'] ?? '') === $from) {
                            $okFrom = true;
                            break;
                        }
                    }
                }
                if (!$okFrom) {
                    $errors[] = 'derive[' . $i . '].from: unknown frame ' . $from;
                }
            }
            if ($as !== '') {
                $frameNames[$as] = true;
            }
            $joinFrame = $d['join_frame'] ?? null;
            if (is_array($joinFrame)) {
                $jf = (string)($joinFrame['frame'] ?? '');
                if ($jf === '' || !isset($frameNames[$jf])) {
                    $errors[] = 'derive[' . $i . '].join_frame.frame: unknown';
                }
                $jt = strtolower((string)($joinFrame['type'] ?? 'left'));
                if (!in_array($jt, ['left', 'cross'], true)) {
                    $errors[] = 'derive[' . $i . '].join_frame.type: bad';
                }
            }
            if (isset($d['filter'])) {
                BiExpr::validate($d['filter'], 'derive[' . $i . '].filter', $errors);
            }
            if (isset($d['having'])) {
                BiExpr::validate($d['having'], 'derive[' . $i . '].having', $errors);
            }
            foreach (['compute', 'compute_after'] as $ck) {
                $compute = is_array($d[$ck] ?? null) ? $d[$ck] : [];
                foreach ($compute as $ci => $c) {
                    if (!is_array($c) || !isset($c['expr'])) {
                        $errors[] = 'derive[' . $i . '].' . $ck . '[' . $ci . ']: need expr';
                        continue;
                    }
                    BiExpr::validate($c['expr'], 'derive[' . $i . '].' . $ck . '[' . $ci . '].expr', $errors);
                }
            }
            if (array_key_exists('group_by', $d) && !is_array($d['group_by'])) {
                $errors[] = 'derive[' . $i . '].group_by: need array';
            }
            $aggs = is_array($d['aggs'] ?? null) ? $d['aggs'] : [];
            $allowedAggs = ['count', 'count_where', 'sum', 'avg', 'min', 'max', 'first', 'join', 'join_where', 'collect'];
            foreach ($aggs as $ai => $agg) {
                if (!is_array($agg)) {
                    $errors[] = 'derive[' . $i . '].aggs[' . $ai . ']: not object';
                    continue;
                }
                $op = strtolower((string)($agg['op'] ?? ''));
                if (!in_array($op, $allowedAggs, true)) {
                    $errors[] = 'derive[' . $i . '].aggs[' . $ai . '].op: bad';
                }
                if (isset($agg['where'])) {
                    BiExpr::validate($agg['where'], 'derive[' . $i . '].aggs[' . $ai . '].where', $errors);
                }
            }
            $windows = is_array($d['window'] ?? null) ? $d['window'] : [];
            foreach ($windows as $wi => $w) {
                if (!is_array($w)) {
                    $errors[] = 'derive[' . $i . '].window[' . $wi . ']: not object';
                    continue;
                }
                $fn = strtolower((string)($w['fn'] ?? ''));
                if (!in_array($fn, ['rank', 'row_number', 'dense_rank'], true)) {
                    $errors[] = 'derive[' . $i . '].window[' . $wi . '].fn: bad';
                }
            }
        }

        $sections = is_array($recipe['sections'] ?? null) ? $recipe['sections'] : [];
        if ($sections === []) {
            $errors[] = 'sections: empty';
        }
        foreach ($sections as $i => $sec) {
            if (!is_array($sec)) {
                $errors[] = 'sections[' . $i . ']: not object';
                continue;
            }
            $type = (string)($sec['type'] ?? '');
            if (!in_array($type, ['metrics', 'table', 'pivot', 'text'], true)) {
                $errors[] = 'sections[' . $i . '].type: bad';
                continue;
            }
            if ($type === 'table') {
                $from = (string)($sec['from'] ?? '');
                if ($from === '' || !isset($frameNames[$from])) {
                    $errors[] = 'sections[' . $i . '].from: unknown frame';
                }
            }
            if ($type === 'metrics') {
                $items = is_array($sec['items'] ?? null) ? $sec['items'] : [];
                foreach ($items as $ii => $item) {
                    $from = (string)($item['from'] ?? '');
                    if ($from === '' || !isset($frameNames[$from])) {
                        $errors[] = 'sections[' . $i . '].items[' . $ii . '].from: unknown';
                    }
                }
            }
            if ($type === 'pivot') {
                $grain = (string)($sec['grain'] ?? 'month_to_date');
                if (!in_array($grain, ['month_to_date', 'days_in_month'], true)) {
                    $errors[] = 'sections[' . $i . '].grain: bad';
                }
                $metrics = is_array($sec['metrics'] ?? null) ? $sec['metrics'] : [];
                if ($metrics === []) {
                    $errors[] = 'sections[' . $i . '].metrics: empty';
                }
                foreach ($metrics as $mi => $m) {
                    $from = (string)($m['from'] ?? '');
                    if (BiDatasetCatalog::get($from) === null) {
                        $errors[] = 'sections[' . $i . '].metrics[' . $mi . '].from: unknown dataset';
                    }
                }
            }
            if ($type === 'text') {
                $cleaned = BiText::sanitizeBody($sec['body'] ?? null, 'sections[' . $i . '].body', $errors);
                if ($cleaned !== null) {
                    $recipe['sections'][$i]['body'] = $cleaned;
                }
            }
            if ($type === 'html') {
                $errors[] = 'sections[' . $i . ']: html sections forbidden — use type=text';
            }
        }

        $dry = null;
        if ($dryRun && $errors === []) {
            ReportProgress::beginCollect();
            try {
                foreach ($requires as $req) {
                    if (!BiDatasetCatalog::isReady((string)$req)) {
                        $warnings[] = 'dry_run_skipped_unavailable:' . $req;
                    }
                }
                $params = ReportParams::normalize([
                    'year' => (int)date('Y'),
                    'month' => (int)date('n'),
                ]);
                $result = BiRecipeEngine::run($recipe, $params);
                if (!is_array($result['sections'] ?? null)) {
                    $errors[] = 'dry_run_missing_sections';
                } else {
                    $dry = [
                        'sections' => count($result['sections']),
                        'title' => (string)($result['title'] ?? ''),
                        'stages' => count(ReportProgress::collectStages()),
                    ];
                }
            } catch (Throwable $e) {
                $errors[] = 'dry_run: ' . $e->getMessage();
            }
            ReportProgress::reset();
        }

        $recipe['id'] = $id;
        if ($title !== '') {
            $recipe['title'] = $title;
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'recipe' => $recipe,
            'dry_run' => $dry,
        ];
    }

    /**
     * @param array<string,mixed> $q
     * @param list<string> $errors
     */
    private static function validateQuery(array $q, string $path, array &$errors): void
    {
        $from = (string)($q['from'] ?? '');
        if (BiDatasetCatalog::get($from) === null) {
            $errors[] = $path . '.from: unknown dataset ' . $from;
            return;
        }
        $joins = is_array($q['join'] ?? null) ? $q['join'] : [];
        foreach ($joins as $ji => $j) {
            if (!is_array($j)) {
                $errors[] = $path . '.join[' . $ji . ']: not object';
                continue;
            }
            $to = (string)($j['dataset'] ?? '');
            $left = (string)($j['left'] ?? '');
            $right = (string)($j['right'] ?? '');
            if (BiDatasetCatalog::findJoin($from, $to, $left, $right) === null) {
                $errors[] = $path . '.join[' . $ji . ']: not allowed';
            }
        }
        $select = is_array($q['select'] ?? null) ? $q['select'] : [];
        if ($select === []) {
            $errors[] = $path . '.select: empty';
        }
        foreach ($select as $si => $sel) {
            if (!is_array($sel)) {
                $errors[] = $path . '.select[' . $si . ']: not object';
                continue;
            }
            if (isset($sel['agg'])) {
                $agg = strtolower((string)$sel['agg']);
                if (!in_array($agg, ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'], true)) {
                    $errors[] = $path . '.select[' . $si . '].agg: bad';
                }
            } elseif (!isset($sel['field'])) {
                $errors[] = $path . '.select[' . $si . ']: need field or agg';
            } else {
                self::validateFieldRef((string)$sel['field'], $from, $joins, $path . '.select[' . $si . '].field', $errors);
            }
        }
        $where = is_array($q['where'] ?? null) ? $q['where'] : [];
        foreach ($where as $wi => $w) {
            if (!is_array($w) || !isset($w['field'], $w['op'])) {
                $errors[] = $path . '.where[' . $wi . ']: need field+op';
                continue;
            }
            $op = (string)$w['op'];
            if (!in_array($op, ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in'], true)) {
                $errors[] = $path . '.where[' . $wi . '].op: bad';
            }
            self::validateFieldRef((string)$w['field'], $from, $joins, $path . '.where[' . $wi . '].field', $errors);
        }
    }

    /**
     * @param list<array<string,mixed>> $joins
     * @param list<string> $errors
     */
    private static function validateFieldRef(string $field, string $from, array $joins, string $path, array &$errors): void
    {
        $field = trim($field);
        if ($field === '') {
            $errors[] = $path . ': empty';
            return;
        }
        if (strpos($field, '.') !== false) {
            [$dsId, $fid] = explode('.', $field, 2);
            $joined = false;
            foreach ($joins as $j) {
                if ((string)($j['dataset'] ?? '') === $dsId) {
                    $joined = true;
                    break;
                }
            }
            if (!$joined) {
                $errors[] = $path . ': dataset not joined ' . $dsId;
                return;
            }
            $ds = BiDatasetCatalog::get($dsId);
            if ($ds === null || BiDatasetCatalog::fieldMeta($ds, $fid) === null) {
                $errors[] = $path . ': unknown ' . $field;
            }
            return;
        }
        $ds = BiDatasetCatalog::get($from);
        if ($ds === null || BiDatasetCatalog::fieldMeta($ds, $field) === null) {
            $errors[] = $path . ': unknown ' . $field;
        }
    }
}
