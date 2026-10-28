<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProcedureTypesRepository.php';
require_once __DIR__ . '/lib/ProceduresRepository.php';
require_once __DIR__ . '/lib/ProceduresChain.php';
require_once __DIR__ . '/lib/ProceduresEditAcl.php';
require_once __DIR__ . '/lib/ProceduresDayLoad.php';
require_once __DIR__ . '/lib/ProcedureBotNotify.php';
require_once __DIR__ . '/lib/AssignedProceduresBotPlugin.php';
require_once __DIR__ . '/lib/ProceduresAssistantPreview.php';
require_once __DIR__ . '/lib/ProceduresAssignPreflight.php';
require_once __DIR__ . '/lib/ProceduresBiContrib.php';

EventBus::on('bi.datasets', [ProceduresBiContrib::class, 'onDatasets']);
EventBus::on('bi.recipes', [ProceduresBiContrib::class, 'onRecipes']);

EventBus::on('assistant.write.preview', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    return ProceduresAssistantPreview::onWritePreview($payload);
});

EventBus::on('assistant.write.preflight', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    return ProceduresAssignPreflight::onWritePreflight($payload);
});

ModuleManager::registerMenuGroup('procedures.menu.group', 300, '/procedures');

$pages = [
    '/procedures' => ['permission' => 'procedures.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/procedures/day-stats' => ['permission' => 'procedures.view', 'methods' => ['GET'], 'file' => 'day_stats.php'],
    '/procedures/set-admin' => [
        // SUPERADMIN always passes Auth::capability; partners / partner-candidates
        // get PARTNER_STA via secondary status (ID_STATUS2).
        'capability' => 'PARTNER_STA',
        'methods' => ['POST'],
        'file' => 'set_admin.php',
    ],
    '/procedures/new' => ['permission' => 'procedures.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/procedures/{id}' => ['permission' => 'procedures.edit', 'methods' => ['GET'], 'file' => 'edit_redirect.php'],
    '/procedures/{id}/edit' => ['permission' => 'procedures.edit', 'methods' => ['GET', 'POST'], 'file' => 'edit.php'],
    '/procedure-types' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'types.php'],
    '/procedure-types/new' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'type_edit.php'],
    '/procedure-types/{id}/edit' => ['permission' => 'procedures.types', 'methods' => ['GET', 'POST'], 'file' => 'type_edit.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'procedures',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/procedures/pages/' . $file);
        },
    ];
    if (!empty($def['capability'])) {
        $route['capability'] = (string)$def['capability'];
    } else {
        $route['permission'] = (string)($def['permission'] ?? '');
    }
    ModuleManager::registerRoute($path, $route);
}

EventBus::on('assistant.system.hints', static function ($payload) {
    if (!is_array($payload)) {
        return [];
    }
    $ids = is_array($payload['op_ids'] ?? null) ? $payload['op_ids'] : [];
    $hints = is_array($payload['hints'] ?? null) ? $payload['hints'] : [];
    if (isset($ids['procedures.set_admin'])) {
        $hints[] = t('procedures.assistant.hint.set_admin');
    }
    if (isset($ids['procedures.update']) || isset($ids['procedures.list'])) {
        $hints[] = t('procedures.assistant.hint.move_time');
        $hints[] = t('procedures.assistant.hint.reassign_operator');
    }
    $payload['hints'] = $hints;
    return $payload;
});

BulkActions::register('procedures', 'delete', [
    'label' => 'core.bulk.delete',
    'permission' => 'procedures.delete',
        'confirm' => 'core.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => ProceduresRepository::deleteByIds($ids), 'skipped' => []];
    },
]);

BulkActions::register('procedures', 'set_admin', [
    'label' => 'procedures.bulk.set_admin',
    'capability' => 'PARTNER_STA',
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
    'permission' => 'procedures.types.delete',
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
        // One bundle: dayColumnStats + dayStaffPay + load % (request-memoized; week reuse).
        $bundle = ProceduresDayLoad::bundleForDay($tsStart, $tsEnd, $staffFilter);
        $stats = $bundle['stats'];
        $pay = $bundle['pay'];
        $loads = $bundle['loads'];
        $colors = class_exists('CalendarTimeline', false)
            ? CalendarTimeline::operatorColors(array_keys($stats['operators']))
            : [];

        $lines = [];
        $salaryTotal = 0.0;
        foreach ($stats['operators'] as $oid => $op) {
            // Equipment (IS_HIDDEN): show proc count only — never load % / pay bits.
            $isEquipment = !empty($op['is_hidden']);
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
            // Performer count (ID_OPERATOR); if none, desk/terminal count (ID_ADMIN).
            // Omit "0п" — show only pay bits / load when there are no procedures yet.
            $procShown = (int)($op['proc_count'] ?? 0);
            $summShown = (float)($op['summ'] ?? 0);
            if ($procShown < 1 && (int)($op['admin_proc_count'] ?? 0) > 0) {
                $procShown = (int)$op['admin_proc_count'];
                $summShown = (float)($op['admin_summ'] ?? 0);
            }
            $parts = [];
            if ($procShown > 0) {
                $part = $procShown . t('procedures.stats.proc_unit');
                if ($isSuper && $summShown > 0) {
                    $part .= ' [' . number_format($summShown, 0, '.', '') . ']';
                }
                $parts[] = $part;
            }
            $loadPct = null;
            $loadMin = false;
            if (!$isEquipment && !$isAdminLine && isset($loads[$oid])) {
                $loadPct = (int)$loads[$oid]['pct'];
                $loadMin = !empty($loads[$oid]['is_min']);
                // Load % is for team balancing — visible without money capability.
                $parts[] = $loadPct . '%';
            }
            if (!$isEquipment && $p && ($canMoney || $isSuper)) {
                // Oper: Ф30+Т140+А1316=1486 (sum when more than one element).
                $bits = [];
                $bitAmounts = [];
                $pushBit = static function (string $label, float $amount) use (&$bits, &$bitAmounts): void {
                    $n = (int)round($amount);
                    if ($n === 0 && $amount == 0.0) {
                        return;
                    }
                    $bits[] = $label . $n;
                    $bitAmounts[] = $n;
                };
                if ((float)$p['salary_fix'] > 0) {
                    $pushBit('Ф', (float)$p['salary_fix']);
                }
                if ((float)$p['salary'] > 0 && ($canMoney || $isSuper)) {
                    $pushBit('', (float)$p['salary']);
                }
                if ((float)$p['salary_terminal'] > 0) {
                    $pushBit('Т', (float)$p['salary_terminal']);
                }
                if ((float)$p['salary_sales'] > 0) {
                    $pushBit('П', (float)$p['salary_sales']);
                }
                if ((float)($p['salary_audio'] ?? 0) > 0) {
                    $pushBit('А', (float)($p['salary_audio'] ?? 0));
                }
                if ((float)($p['salary_scripts'] ?? 0) > 0) {
                    $pushBit('С', (float)($p['salary_scripts'] ?? 0));
                }
                if ($bits) {
                    $bitsText = implode('+', $bits);
                    if (count($bits) > 1) {
                        $bitsText .= '=' . (int)array_sum($bitAmounts);
                    }
                    $parts[] = t('procedures.stats.salary_abbr') . ' ' . $bitsText;
                }
                $salaryTotal += (float)$p['total'];
            }
            $text = implode(' ', $parts);
            $c = $colors[$oid] ?? null;
            // Oper footer buckets: mass → admin → equipment (fish chairs last).
            $sortGroup = $isEquipment ? 2 : ($isAdminLine ? 1 : 0);
            $lines[] = [
                'acronym' => (string)$op['acronym'],
                'text' => $text,
                'is_admin' => $isAdminLine,
                'is_equipment' => $isEquipment,
                'sort_group' => $sortGroup,
                'load_pct' => $loadPct,
                'load_min' => $loadMin,
                'bg' => $c['bg'] ?? '',
                'fg' => $c['fg'] ?? '',
            ];
        }

        usort($lines, static function (array $a, array $b): int {
            return ((int)($a['sort_group'] ?? 0) <=> (int)($b['sort_group'] ?? 0))
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
                    'showSalary' => ($canMoney || $isSuper) && $salaryTotal > 0,
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

// Skill bans moved to rates (ban_booking / ban_online). Legacy RESTRICT_* still
// dual-read in BookingOperatorGate until migrator restrict_to_bans; UI removed.

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
