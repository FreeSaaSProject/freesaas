<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProcedureTypesRepository.php';
require_once __DIR__ . '/lib/ProceduresRepository.php';
require_once __DIR__ . '/lib/ProcedureBotNotify.php';
require_once __DIR__ . '/lib/AssignedProceduresBotPlugin.php';

ModuleManager::registerMenuGroup('procedures.menu.group', 30, '/procedures');

$pages = [
    '/procedures' => ['permission' => 'procedures.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/procedures/day-stats' => ['permission' => 'procedures.view', 'methods' => ['GET'], 'file' => 'day_stats.php'],
    '/procedures/new' => ['permission' => 'procedures.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/procedures/{id}' => ['permission' => 'procedures.edit', 'methods' => ['GET'], 'file' => 'edit_redirect.php'],
    '/procedures/{id}/edit' => ['permission' => 'procedures.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/procedure-types' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'types.php'],
    '/procedure-types/new' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'type_edit.php'],
    '/procedure-types/{id}/edit' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'type_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'procedures',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/procedures/pages/' . $file);
        },
    ]);
}

BulkActions::register('procedures', 'delete', [
    'label' => 'core.bulk.delete',
    'capability' => 'SUPERADMIN_ACCESS',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ProceduresRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('procedures', 'set_admin', [
    'label' => 'procedures.bulk.set_admin',
    'capability' => 'SUPERADMIN_ACCESS',
    'fields' => [
        [
            'name' => 'admin_id',
            'type' => 'select',
            'label' => 'procedures.bulk.admin',
            'options' => static function (): array {
                $out = [];
                foreach (UsersRepository::listEnabledStaff() as $u) {
                    $label = trim((string)$u['name']);
                    if ($label === '') {
                        $label = (string)$u['login'];
                    }
                    $out[(int)$u['id']] = $label;
                }
                return $out;
            },
        ],
    ],
    'run' => static function (array $ids, array $params): array {
        $adminId = (int)($params['admin_id'] ?? 0);
        if ($adminId < 1) {
            $skipped = [];
            foreach ($ids as $id) {
                $skipped[] = ['id' => (int)$id, 'reason' => t('procedures.bulk.err.admin_required')];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
        return ['done' => ProceduresRepository::setAdminByIds($ids, $adminId), 'skipped' => []];
    },
]);

BulkActions::register('procedure_types', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'procedures.types',
    'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ProcedureTypesRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

EventBus::on('core.bulk.check_item', static function (array $payload): array {
    $listId = (string)($payload['list_id'] ?? '');
    $action = (string)($payload['action'] ?? '');
    if (!empty($payload['blocked'])) {
        return $payload;
    }
    $id = (int)($payload['id'] ?? 0);

    if ($listId === 'procedure_types' && $action === 'delete') {
        $n = ProceduresRepository::countByTypeId($id);
        if ($n > 0) {
            $payload['blocked'] = true;
            $payload['reason'] = t('procedures.bulk.type_in_use', ['n' => $n]);
        }
        return $payload;
    }

    if ($listId === 'clients' && $action === 'delete') {
        $n = ProceduresRepository::countByClientId($id);
        if ($n > 0) {
            $payload['blocked'] = true;
            $payload['reason'] = t('procedures.bulk.client_in_use', ['n' => $n]);
        }
    }
    return $payload;
});

EventBus::on('calendar.day.stats', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    if (!Auth::can('procedures.view') || !Database::tableExists('base_procedures')) {
        return $payload;
    }
    $ctx = is_array($payload['context'] ?? null) ? $payload['context'] : [];
    $canMoney = !empty($ctx['can_money']);
    $isSuper = !empty($ctx['is_super']);
    $myOnly = !empty($ctx['my_only']);
    $showAdmin = !empty($ctx['show_admin_data']);
    $days = is_array($payload['days'] ?? null) ? $payload['days'] : [];

    foreach ($days as $date => $day) {
        if (!is_array($day)) {
            continue;
        }
        $tsStart = (int)($day['ts_start'] ?? 0);
        $tsEnd = (int)($day['ts_end'] ?? 0);
        if ($tsStart <= 0 || !empty($day['is_future'])) {
            continue;
        }
        $staffFilter = isset($day['staff_filter']) ? (int)$day['staff_filter'] : null;
        if ($staffFilter !== null && $staffFilter < 1) {
            $staffFilter = null;
        }
        $stats = ProceduresRepository::dayColumnStats($tsStart, $tsEnd, $staffFilter);
        $pay = [];
        if (class_exists('ProcentsRepository', false) && ModuleManager::isEnabled('procents')) {
            $pay = ProcentsRepository::dayStaffPay($tsStart, $tsEnd, $stats['operators']);
        }
        $colors = class_exists('CalendarTimeline', false)
            ? CalendarTimeline::operatorColors(array_keys($stats['operators']))
            : [];

        $avgBase = 0.0;
        $avgN = 0;
        foreach ($pay as $oid => $p) {
            if (!empty($stats['operators'][$oid]['is_hidden'])) {
                continue;
            }
            if ((float)($p['salary_fix'] ?? 0) > 0) {
                continue;
            }
            if ((float)($p['salary'] ?? 0) > 0) {
                $avgBase += (float)$p['salary'];
                $avgN++;
            }
        }
        $avgSalary = $avgN > 0 ? ($avgBase / $avgN) : 1.0;

        $lines = [];
        $salaryTotal = 0.0;
        foreach ($stats['operators'] as $oid => $op) {
            if (!empty($op['is_hidden'])) {
                continue;
            }
            if ($myOnly && (int)$oid !== Auth::userId()) {
                continue;
            }
            $p = $pay[$oid] ?? null;
            $isAdminLine = $p && (float)($p['salary_fix'] ?? 0) > 0;
            // Admin/fix lines: only with SHOW_ADMINISTRATOR_DATA (or self / super).
            if ($isAdminLine && !$showAdmin && !$isSuper && (int)$oid !== Auth::userId()) {
                continue;
            }
            if ($myOnly && $isAdminLine) {
                continue;
            }
            $text = (int)$op['proc_count'] . t('procedures.stats.proc_unit');
            if ($isSuper && (float)$op['summ'] > 0) {
                $text .= ' [' . number_format((float)$op['summ'], 0, '.', '') . ']';
            }
            if ($p && ($canMoney || $isSuper)) {
                $bits = [];
                if ((float)$p['salary_fix'] > 0) {
                    $bits[] = 'Ф' . (int)round((float)$p['salary_fix']);
                }
                if ((float)$p['salary'] > 0) {
                    $part = '';
                    if ($isSuper) {
                        $part .= (int)round((float)$p['salary']);
                    }
                    $part .= ' ' . (int)round(((float)$p['salary'] / $avgSalary) * 100) . '%';
                    $bits[] = trim($part);
                }
                if ((float)$p['salary_terminal'] > 0) {
                    $bits[] = 'Т' . (int)round((float)$p['salary_terminal']);
                }
                if ((float)$p['salary_sales'] > 0) {
                    $bits[] = 'П' . (int)round((float)$p['salary_sales']);
                }
                if ($bits) {
                    $text .= ' ' . t('procedures.stats.salary_abbr') . ' ' . implode('+', $bits);
                }
                $salaryTotal += (float)$p['total'];
            }
            $c = $colors[$oid] ?? null;
            $lines[] = [
                'acronym' => (string)$op['acronym'],
                'text' => $text,
                'is_admin' => $isAdminLine,
                'bg' => $c['bg'] ?? '',
                'fg' => $c['fg'] ?? '',
            ];
        }

        usort($lines, static function (array $a, array $b): int {
            return ((int)!empty($a['is_admin']) <=> (int)!empty($b['is_admin']))
                ?: strcmp((string)$a['acronym'], (string)$b['acronym']);
        });

        $sections = is_array($day['sections'] ?? null) ? $day['sections'] : [];

        if ($isSuper && (float)$stats['revenue'] > 0) {
            $sections[] = [
                'id' => 'procedures.revenue_bar',
                'module' => 'procedures',
                'order' => 10,
                'html' => View::capture('_day_stats_bar', [
                    'pct' => (float)$stats['revenue'] / 1000.0,
                    'labelShort' => t('procedures.stats.bar_short'),
                    'labelLong' => t('procedures.stats.bar_long'),
                ], 'procedures'),
            ];
        }

        if ($lines) {
            $sections[] = [
                'id' => 'procedures.staff',
                'module' => 'procedures',
                'order' => 20,
                'html' => View::capture('_day_stats_staff', [
                    'lines' => $lines,
                    'myOnly' => $myOnly,
                ], 'procedures'),
            ];
        }

        if ($canMoney || $isSuper) {
            $sections[] = [
                'id' => 'procedures.totals',
                'module' => 'procedures',
                'order' => 30,
                'html' => View::capture('_day_stats_totals', [
                    'revenue' => (float)$stats['revenue'],
                    'procCount' => (int)$stats['proc_count'],
                    'promoCount' => (int)$stats['promo_count'],
                    'restored' => (float)$stats['restored'],
                    'salaryTotal' => $salaryTotal,
                    'showSalary' => $isSuper && $salaryTotal > 0,
                ], 'procedures'),
                'data' => [
                    'revenue' => (float)$stats['revenue'],
                    'restored' => (float)$stats['restored'],
                    'proc_count' => (int)$stats['proc_count'],
                ],
            ];
        }

        $day['sections'] = $sections;
        $payload['days'][$date] = $day;
    }

    return $payload;
});

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    $procedureTypes = [];
    foreach (ProcedureTypesRepository::listAll() as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $label = trim((string)($r['type'] ?? ''));
        $descr = trim((string)($r['descr'] ?? ''));
        if ($descr !== '') {
            $label .= ' — ' . $descr;
        }
        $procedureTypes[$id] = $label;
    }
    $rtSet = [];
    $rtoSet = [];
    foreach (UsersRepository::parseHashIds((string)($user['RESTRICT_TYPES'] ?? '')) as $tid) {
        $rtSet[(int)$tid] = true;
    }
    foreach (UsersRepository::parseHashIds((string)($user['RESTRICT_TYPES_ONLINE'] ?? '')) as $tid) {
        $rtoSet[(int)$tid] = true;
    }
    $products = [];
    $rpSet = [];
    if (class_exists('ProductsRepository', false)) {
        $listed = ProductsRepository::list([
            'enabled' => '1',
            'is_material' => '0',
            'limit' => 2000,
            'order' => 'p.category ASC, p.name ASC',
        ]);
        foreach ($listed['items'] as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $products[$id] = (string)($r['name'] ?? ('#' . $id));
        }
        foreach (UsersRepository::parseHashIds((string)($user['RESTRICT_PROCS'] ?? '')) as $pid) {
            $rpSet[(int)$pid] = true;
        }
    }
    ob_start();
    require __DIR__ . '/views/_user_edit_restrict.php';
    $payload['html'] = ($payload['html'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $data['RESTRICT_TYPES'] = UsersRepository::encodeHashIds(array_map('intval', (array)($post['RESTRICT_TYPES'] ?? [])));
    $data['RESTRICT_TYPES_ONLINE'] = UsersRepository::encodeHashIds(array_map('intval', (array)($post['RESTRICT_TYPES_ONLINE'] ?? [])));
    $data['RESTRICT_PROCS'] = UsersRepository::encodeHashIds(array_map('intval', (array)($post['RESTRICT_PROCS'] ?? [])));
    $payload['data'] = $data;
    return $payload;
});

$registerProceduresBots = static function (): void {
    AssignedProceduresBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerProceduresBots();
}
EventBus::on('bots.ready', static function ($payload) use ($registerProceduresBots) {
    $registerProceduresBots();
    return is_array($payload) ? $payload : [];
});
