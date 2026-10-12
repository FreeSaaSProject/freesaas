<?php
declare(strict_types=1);

/**
 * Allowlisted datasets / fields / joins for KPI JSON recipes (no live SHOW TABLES).
 */
final class BiDatasetCatalog
{
    /**
     * Full legend payload for agents + constructor sidebar.
     *
     * @return array<string,mixed>
     */
    public static function legend(): array
    {
        return [
            'v' => 1,
            'agent_howto' => [
                '1. GET /bi?format=json — list families (id, title, ready)',
                '2. GET /bi/legend?format=json — datasets / expr / derive / section types / examples',
                '3. Draft recipe JSON (plain-text titles/labels/text.body; no HTML in text)',
                '4. POST /bi/families/new?format=json action=validate + recipe_json — fix errors',
                '5. POST /bi/families/new?format=json action=save — persist (shipped id = DB override)',
                '6. POST /bi/work?format=json action=run&family=&year=&month= — read report.sections',
                '7. Optional: GET /bi/families/{id}?format=json — load recipe; POST shares for live links',
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
            'group_aggs' => ['count', 'count_where', 'sum', 'avg', 'min', 'max', 'first', 'join', 'join_where', 'collect'],
            'window_fns' => ['rank', 'row_number', 'dense_rank'],
            'section_types' => ['metrics', 'table', 'pivot', 'text'],
            'pivot_grains' => ['month_to_date', 'days_in_month'],
            'derive_steps' => ['join_frame', 'filter', 'select', 'compute', 'group_by', 'aggs', 'compute_after', 'having', 'window', 'sort', 'limit'],
            'run_params' => ['year', 'month', 'dayshow', 'id_operator', 'id_admin', 'id_good', 'variant'],
            'text_rules' => 'sections type=text body = plain UTF-8 (no HTML/PHP/event handlers); escaped on render via e_nl',
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
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function datasets(): array
    {
        return [
            self::ds('procedures', 'Procedures', 'base_procedures', 'procedures', [
                'period_field' => 'DTSTART',
                'period_kind' => 'inclusive_dt',
                'filters' => [
                    'operator' => 'ID_OPERATOR',
                    'admin' => 'ID_ADMIN',
                    'good' => 'ID_GOOD',
                ],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                    ['id' => 'dtstart', 'col' => 'DTSTART', 'type' => 'datetime'],
                    ['id' => 'summ', 'col' => 'SUMM', 'type' => 'float', 'agg' => true],
                    ['id' => 'enabled', 'col' => 'ENABLED', 'type' => 'int'],
                    ['id' => 'id_operator', 'col' => 'ID_OPERATOR', 'type' => 'int'],
                    ['id' => 'id_admin', 'col' => 'ID_ADMIN', 'type' => 'int'],
                    ['id' => 'id_good', 'col' => 'ID_GOOD', 'type' => 'int'],
                ],
                'joins' => [
                    ['dataset' => 'users_staff', 'left' => 'id_operator', 'right' => 'id'],
                    ['dataset' => 'users_staff', 'left' => 'id_admin', 'right' => 'id'],
                    ['dataset' => 'products', 'left' => 'id_good', 'right' => 'id'],
                ],
            ]),
            self::ds('guests', 'Guests (fullstats)', 'base_fullstats', 'terminals', [
                'period_field' => 'DTSTART',
                'period_kind' => 'half_open',
                'filters' => [],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                    ['id' => 'dtstart', 'col' => 'DTSTART', 'type' => 'datetime'],
                ],
                'joins' => [],
            ]),
            self::ds('workweeks', 'Workweeks / shifts', 'base_workweeks', 'workweeks', [
                'period_field' => 'DT',
                // base_workweeks.DT is unix timestamp (legacy oper), not Y-m-d.
                'period_kind' => 'inclusive_dt',
                'filters' => [
                    'operator' => 'ID_OPERATOR',
                ],
                'fields' => [
                    ['id' => 'dt', 'col' => 'DT', 'type' => 'datetime'],
                    ['id' => 'id_operator', 'col' => 'ID_OPERATOR', 'type' => 'int'],
                    ['id' => 'price_col', 'col' => 'PRICE_COL', 'type' => 'int'],
                    ['id' => 'mas_max', 'col' => 'MAS_MAX', 'type' => 'int'],
                    ['id' => 'id_table', 'col' => 'ID_TABLE', 'type' => 'int'],
                ],
                'joins' => [
                    ['dataset' => 'users_staff', 'left' => 'id_operator', 'right' => 'id'],
                ],
            ]),
            self::ds('users_staff', 'Staff users', 'base_users', 'core', [
                'period_field' => null,
                'period_kind' => null,
                'filters' => [],
                'fields' => [
                    ['id' => 'id', 'col' => 'id', 'type' => 'int'],
                    ['id' => 'name', 'col' => 'name', 'type' => 'string'],
                    ['id' => 'login', 'col' => 'login', 'type' => 'string'],
                    ['id' => 'enabled', 'col' => 'enabled', 'type' => 'string'],
                    ['id' => 'id_status', 'col' => 'ID_STATUS', 'type' => 'int', 'schema_alt' => ['name_enabled']],
                    ['id' => 'is_hidden', 'col' => 'IS_HIDDEN', 'type' => 'int'],
                    ['id' => 'assist_team', 'col' => 'ASSIST_TEAM', 'type' => 'int'],
                ],
                'joins' => [],
            ]),
            self::ds('products', 'Products / services', 'base_products', 'catalog', [
                'period_field' => null,
                'period_kind' => null,
                'filters' => [],
                'fields' => [
                    ['id' => 'id', 'col' => 'id', 'type' => 'int'],
                    ['id' => 'type_id', 'col' => 'type_id', 'type' => 'int', 'schema_alt' => ['TYPE']],
                    ['id' => 'duration', 'col' => 'time_sec', 'type' => 'int', 'schema_alt' => ['time_net', 'TIME_NET', 'TIME']],
                ],
                'joins' => [],
            ]),
            self::ds('vacations', 'Vacations', 'base_vacations', 'vacations', [
                'period_field' => 'DT_START',
                'period_end_field' => 'DT_END',
                'period_kind' => 'overlap_dt',
                'filters' => [
                    'operator' => 'ID_USER',
                ],
                'fields' => [
                    ['id' => 'id_user', 'col' => 'ID_USER', 'type' => 'int'],
                    ['id' => 'id_type', 'col' => 'ID_TYPE', 'type' => 'int'],
                    ['id' => 'dt_start', 'col' => 'DT_START', 'type' => 'datetime'],
                    ['id' => 'dt_end', 'col' => 'DT_END', 'type' => 'datetime'],
                ],
                'joins' => [
                    ['dataset' => 'users_staff', 'left' => 'id_user', 'right' => 'id'],
                ],
            ]),
            self::ds('events', 'Calendar events', 'base_events', 'calendar', [
                'period_field' => 'DTSTART',
                'period_kind' => 'inclusive_dt',
                'filters' => [],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                    ['id' => 'dtstart', 'col' => 'DTSTART', 'type' => 'datetime'],
                    ['id' => 'dtend', 'col' => 'DTEND', 'type' => 'datetime'],
                ],
                'joins' => [],
            ]),
            self::ds('records', 'Audio records', 'base_records', 'audio', [
                'period_field' => 'DT_RECORDED',
                'period_kind' => 'inclusive_dt',
                'filters' => [
                    'admin' => 'ID_ADMIN',
                ],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                    ['id' => 'playtime', 'col' => 'PLAYTIME', 'type' => 'float', 'agg' => true],
                    ['id' => 'id_admin', 'col' => 'ID_ADMIN', 'type' => 'int'],
                    ['id' => 'dt_recorded', 'col' => 'DT_RECORDED', 'type' => 'datetime'],
                ],
                'joins' => [
                    ['dataset' => 'users_staff', 'left' => 'id_admin', 'right' => 'id'],
                ],
            ]),
            self::ds('poll_invites', 'Poll invites', 'base_poll_invites', 'poll_invites', [
                'period_field' => 'DT_CREATE',
                'period_kind' => 'inclusive_dt',
                'filters' => [],
                'fields' => [
                    ['id' => 'id', 'col' => 'ID', 'type' => 'int', 'agg' => true],
                    ['id' => 'dt_create', 'col' => 'DT_CREATE', 'type' => 'datetime'],
                    ['id' => 'status', 'col' => 'STATUS', 'type' => 'int'],
                ],
                'joins' => [],
            ]),
        ];
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
            'date_label', 'format_ts', 'acronym',
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
     * Default blank recipe for constructor.
     *
     * @return array<string,mixed>
     */
    public static function blankRecipe(string $familyId = 'my_report', string $title = 'My report'): array
    {
        $ex = self::examples()[0]['recipe'];
        $ex['id'] = $familyId;
        $ex['title'] = $title;
        return $ex;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function ds(string $id, string $label, string $table, string $module, array $extra): array
    {
        return array_merge([
            'id' => $id,
            'label' => $label,
            'requires_table' => $table,
            'requires_module' => $module,
            'available' => Database::tableExists($table),
        ], $extra);
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
