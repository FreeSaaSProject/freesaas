<?php
declare(strict_types=1);

/**
 * Allowlisted datasets / fields / joins for KPI JSON recipes.
 * Product datasets come from EventBus bi.datasets (owning modules). BI keeps no product tables.
 */
final class BiDatasetCatalog
{
    /** @var list<array<string,mixed>>|null */
    private static $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Full legend payload for agents + constructor sidebar.
     *
     * @return array<string,mixed>
     */
    public static function legend(): array
    {
        $coreDerive = [
            'join_frame', 'filter', 'select', 'compute',
            'group_by', 'aggs', 'compute_after', 'having', 'window', 'sort', 'limit',
        ];
        $contribIds = class_exists('BiDeriveRegistry', false) ? BiDeriveRegistry::ids() : [];
        $deriveSteps = array_values(array_unique(array_merge($contribIds, $coreDerive)));

        $legend = [
            'v' => 1,
            'agent_howto' => [
                '1. GET /bi?format=json — list families (id, title, ready)',
                '2. GET /bi/legend?format=json — datasets / expr / derive / section types / examples',
                '3. Draft recipe JSON (plain-text titles/labels/text.body; no HTML in text)',
                '4. POST /bi/families/new?format=json action=validate + recipe_json — fix errors',
                '5. POST /bi/families/new?format=json action=save — persist (shipped id = DB override)',
                '6. POST /bi/work?format=json action=run&family=&year=&month= — read report.sections',
                '7. Optional: GET /bi/families/{id}?format=json — load recipe; POST shares for live links',
                'Datasets and domain derive steps are contributed by enabled modules (EventBus bi.datasets / bi.derive_steps / bi.recipes).',
            ],
            'recipe_shape' => [
                'v' => 1,
                'id' => 'snake_case id',
                'title' => 'plain human title (not i18n key)',
                'requires' => ['dataset ids that must be available'],
                'queries' => [['as' => 'frame', 'from' => 'dataset', 'join' => [], 'where' => [], 'select' => [], 'group_by' => [], 'order_by' => [], 'limit' => 500]],
                'derive' => [['as' => 'out', 'from' => 'frame', 'join_frame' => null, 'filter' => null, 'compute' => [], 'group_by' => [], 'aggs' => [], 'compute_after' => [], 'having' => null, 'sort' => []]],
                'sections' => [
                    ['type' => 'metrics|table|pivot|text', 'title' => 'plain', 'from' => 'frame', 'columns' => [['id' => 'col', 'label' => 'plain']], 'items' => [], 'body' => 'plain text only'],
                ],
            ],
            'datasets' => self::datasets(),
            'expr_ops' => self::exprOps(),
            'compare_ops' => ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in'],
            'aggs' => ['count', 'sum', 'avg', 'min', 'max', 'count_distinct'],
            'group_aggs' => [
                'count', 'count_where', 'count_distinct', 'sum', 'avg', 'min', 'max',
                'first', 'join', 'join_where', 'collect',
            ],
            'window_fns' => ['rank', 'row_number', 'dense_rank'],
            'section_types' => ['metrics', 'table', 'pivot', 'text'],
            'pivot_grains' => ['month_to_date', 'days_in_month'],
            'derive_steps' => $deriveSteps,
            'join_frame_types' => ['left', 'inner', 'cross'],
            'join_frame_range' => [
                'left' => 'timestamp field on left rows',
                'start' => 'window_start on right',
                'end' => 'window_end on right',
                'note' => 'Applied after equality keys (left/right or on[])',
            ],
            'engine_opts' => [
                'join_group_fuse' => 'join-only derive followed by group_by (sole consumer) is fused; join product is not materialised',
                'streaming_aggs' => 'group_by accumulates without storing member row lists',
                'badge' => 'expr badge(user_id) → safe token; ReportRenderer expands via UsersRepository::badgeHtml. Column format user_badge + user_field also supported',
                'contrib' => 'Product datasets/recipes/derive via EventBus bi.datasets, bi.recipes, bi.derive_steps',
            ],
            'column_formats' => ['user_badge'],
            'run_params' => ['year', 'month', 'dayshow', 'id_operator', 'id_admin', 'id_good', 'variant'],
            'text_rules' => 'sections type=text body = plain UTF-8 (no HTML/PHP/event handlers); escaped on render via e_nl. badge tokens are expanded to user-badge HTML',
            'examples' => self::examples(),
            'ops' => [
                'bi.families.list',
                'bi.legend',
                'bi.family.get',
                'bi.family.validate',
                'bi.family.save',
                'bi.family.delete',
                'bi.report.run',
                'bi.share.list',
                'bi.share.create',
                'bi.share.revoke',
            ],
        ];

        if (class_exists('BiDeriveRegistry', false)) {
            foreach (BiDeriveRegistry::legendMeta() as $id => $meta) {
                $legend[$id] = $meta;
            }
        }

        return $legend;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function datasets(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $payload = EventBus::emit('bi.datasets', ['datasets' => []]);
        $list = is_array($payload['datasets'] ?? null) ? $payload['datasets'] : [];
        $byId = [];
        $order = [];
        foreach ($list as $ds) {
            if (!is_array($ds)) {
                continue;
            }
            $id = trim((string)($ds['id'] ?? ''));
            if ($id === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
                throw new RuntimeException('bi.datasets: invalid id');
            }
            $module = (string)($ds['requires_module'] ?? $ds['module'] ?? '');
            if (isset($byId[$id])) {
                // Same module may enrich (replace) its own dataset; different module = conflict.
                $prevMod = (string)($byId[$id]['requires_module'] ?? '');
                if ($module !== '' && $prevMod !== '' && $module !== $prevMod) {
                    throw new RuntimeException(
                        'bi.datasets conflict: ' . $id . ' (' . $prevMod . ' vs ' . $module . ')'
                    );
                }
                $byId[$id] = self::normalizeDataset($ds);
                continue;
            }
            $byId[$id] = self::normalizeDataset($ds);
            $order[] = $id;
        }
        $out = [];
        foreach ($order as $id) {
            $out[] = $byId[$id];
        }
        self::$cache = $out;
        return self::$cache;
    }

    /**
     * Build a dataset definition for contrib listeners (public helper).
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function define(string $id, string $label, string $table, string $module, array $extra): array
    {
        return self::normalizeDataset(array_merge([
            'id' => $id,
            'label' => $label,
            'requires_table' => $table,
            'requires_module' => $module,
        ], $extra));
    }

    /**
     * @param array<string,mixed> $ds
     * @return array<string,mixed>
     */
    private static function normalizeDataset(array $ds): array
    {
        $table = (string)($ds['requires_table'] ?? '');
        $ds['available'] = $table !== '' && Database::tableExists($table);
        if (!isset($ds['fields']) || !is_array($ds['fields'])) {
            $ds['fields'] = [];
        }
        if (!isset($ds['joins']) || !is_array($ds['joins'])) {
            $ds['joins'] = [];
        }
        if (!isset($ds['filters']) || !is_array($ds['filters'])) {
            $ds['filters'] = [];
        }
        return $ds;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $id): ?array
    {
        foreach (self::datasets() as $ds) {
            if ($ds['id'] === $id) {
                return $ds;
            }
        }
        return null;
    }

    public static function isReady(string $id): bool
    {
        $ds = self::get($id);
        if ($ds === null) {
            return false;
        }
        return Database::tableExists((string)$ds['requires_table']);
    }

    /**
     * Resolve physical column for a logical field (schema-aware alts).
     */
    public static function resolveColumn(array $dataset, string $fieldId): ?string
    {
        foreach ($dataset['fields'] as $f) {
            if (($f['id'] ?? '') !== $fieldId) {
                continue;
            }
            $primary = (string)($f['col'] ?? '');
            $table = (string)$dataset['requires_table'];
            if ($primary !== '' && self::columnExists($table, $primary)) {
                return $primary;
            }
            $alts = is_array($f['schema_alt'] ?? null) ? $f['schema_alt'] : [];
            foreach ($alts as $alt) {
                if (self::columnExists($table, (string)$alt)) {
                    return (string)$alt;
                }
            }
            return $primary !== '' ? $primary : null;
        }
        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function fieldMeta(array $dataset, string $fieldId): ?array
    {
        foreach ($dataset['fields'] as $f) {
            if (($f['id'] ?? '') === $fieldId) {
                return $f;
            }
        }
        return null;
    }

    /**
     * Allowed join from $fromId to $toId matching left/right logical fields.
     *
     * @return array{dataset:string,left:string,right:string}|null
     */
    public static function findJoin(string $fromId, string $toId, string $left, string $right): ?array
    {
        $ds = self::get($fromId);
        if ($ds === null) {
            return null;
        }
        foreach ($ds['joins'] as $j) {
            if (($j['dataset'] ?? '') === $toId
                && ($j['left'] ?? '') === $left
                && ($j['right'] ?? '') === $right
            ) {
                return $j;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    public static function exprOps(): array
    {
        return [
            'add', 'sub', 'mul', 'div',
            'eq', 'neq', 'gt', 'gte', 'lt', 'lte',
            'and', 'or', 'not',
            'if', 'coalesce', 'nullif',
            'min', 'max', 'floor', 'round', 'concat', 'in',
            'date_label', 'day_start', 'day_label', 'format_ts', 'acronym', 'badge',
            'field', 'lit',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function examples(): array
    {
        return [
            [
                'name' => 'demo_metrics',
                'recipe' => [
                    'v' => 1,
                    'id' => 'demo_metrics',
                    'title' => 'Demo metrics',
                    'requires' => ['procedures'],
                    'queries' => [
                        [
                            'as' => 'proc_stats',
                            'from' => 'procedures',
                            'where' => [
                                ['field' => 'summ', 'op' => '>', 'value' => 0],
                                ['field' => 'enabled', 'op' => '>', 'value' => 0],
                            ],
                            'select' => [
                                ['agg' => 'count', 'as' => 'procs'],
                                ['agg' => 'sum', 'field' => 'summ', 'as' => 'revenue'],
                            ],
                        ],
                        [
                            'as' => 'guest_stats',
                            'from' => 'guests',
                            'select' => [
                                ['agg' => 'count', 'as' => 'guests'],
                            ],
                        ],
                    ],
                    'derive' => [],
                    'sections' => [
                        [
                            'type' => 'metrics',
                            'title' => 'Summary',
                            'items' => [
                                ['label' => 'Procedures', 'from' => 'proc_stats', 'field' => 'procs'],
                                ['label' => 'Revenue', 'from' => 'proc_stats', 'field' => 'revenue'],
                                ['label' => 'Guests', 'from' => 'guest_stats', 'field' => 'guests'],
                            ],
                        ],
                        [
                            'type' => 'text',
                            'title' => 'Note',
                            'body' => 'Plain-text note for readers (no HTML).',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'by_admin_table',
                'recipe' => [
                    'v' => 1,
                    'id' => 'by_admin',
                    'title' => 'By admin',
                    'requires' => ['procedures'],
                    'queries' => [
                        [
                            'as' => 'by_admin',
                            'from' => 'procedures',
                            'join' => [
                                ['dataset' => 'users_staff', 'left' => 'id_admin', 'right' => 'id'],
                            ],
                            'where' => [
                                ['field' => 'summ', 'op' => '>', 'value' => 0],
                                ['field' => 'enabled', 'op' => '>', 'value' => 0],
                            ],
                            'group_by' => ['id_admin', 'admin_name'],
                            'select' => [
                                ['field' => 'id_admin'],
                                ['field' => 'users_staff.name', 'as' => 'admin_name'],
                                ['agg' => 'count', 'as' => 'procs'],
                                ['agg' => 'sum', 'field' => 'summ', 'as' => 'revenue'],
                            ],
                            'order_by' => [['field' => 'revenue', 'dir' => 'desc']],
                            'limit' => 200,
                        ],
                    ],
                    'derive' => [
                        [
                            'as' => 'rated',
                            'from' => 'by_admin',
                            'select' => ['*'],
                            'window' => [
                                [
                                    'fn' => 'rank',
                                    'order_by' => [['field' => 'revenue', 'dir' => 'desc']],
                                    'as' => 'rank_rev',
                                ],
                            ],
                            'compute' => [
                                [
                                    'as' => 'rev_per_proc',
                                    'expr' => [
                                        'div' => [
                                            ['field' => 'revenue'],
                                            ['nullif' => [['field' => 'procs'], ['lit' => 0]]],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'sections' => [
                        [
                            'type' => 'table',
                            'title' => 'By admin',
                            'from' => 'rated',
                            'columns' => ['rank_rev', 'admin_name', 'procs', 'revenue', 'rev_per_proc'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'pivot_matrix',
                'recipe' => [
                    'v' => 1,
                    'id' => 'pivot_demo',
                    'title' => 'Pivot matrix',
                    'requires' => ['procedures'],
                    'queries' => [],
                    'derive' => [],
                    'sections' => [
                        [
                            'type' => 'pivot',
                            'title' => 'Matrix',
                            'grain' => 'month_to_date',
                            'metrics' => [
                                [
                                    'id' => 'procs',
                                    'label' => 'Procedures',
                                    'from' => 'procedures',
                                    'agg' => 'count',
                                    'where' => [
                                        ['field' => 'summ', 'op' => '>', 'value' => 0],
                                        ['field' => 'enabled', 'op' => '>', 'value' => 0],
                                    ],
                                ],
                                [
                                    'id' => 'guests',
                                    'label' => 'Guests',
                                    'from' => 'guests',
                                    'agg' => 'count',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Default blank recipe for constructor (no foreign datasets assumed).
     *
     * @return array<string,mixed>
     */
    public static function blankRecipe(string $familyId = 'my_report', string $title = 'My report'): array
    {
        return [
            'v' => 1,
            'id' => $familyId,
            'title' => $title,
            'requires' => [],
            'queries' => [],
            'derive' => [],
            'sections' => [
                [
                    'type' => 'text',
                    'title' => 'Note',
                    'body' => 'Edit this recipe. See /bi/legend for datasets available on this instance.',
                ],
            ],
        ];
    }

    private static function columnExists(string $table, string $col): bool
    {
        if (!Database::tableExists($table)) {
            return false;
        }
        if (method_exists('Database', 'columnExists')) {
            return Database::columnExists($table, $col);
        }
        static $cache = [];
        $key = $table;
        if (!isset($cache[$key])) {
            $cache[$key] = [];
            foreach (Database::pdo()->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cache[$key][(string)$c['Field']] = true;
            }
        }
        return isset($cache[$key][$col]);
    }
}
