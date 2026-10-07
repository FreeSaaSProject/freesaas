<?php
declare(strict_types=1);

/**
 * Expected bot cabinet menus (oper employeeBot / client) vs FreeSaaS BotPlugins + modules.
 *
 * Status:
 * - active: BotPlugins::register present (live cabinet button)
 * - pending: target FreeSaaS module enabled, adapter not ported yet
 * - module_off: target module disabled
 * - missing: no FreeSaaS home yet
 */
final class BotMenuCatalog
{
    /**
     * Staff cabinet features from oper employee_bot_config (+ labels as in Max UI).
     *
     * @return list<array{id:string,label_key:string,module:string,aliases:list<string>,kind:string}>
     */
    public static function staffExpected(): array
    {
        return [
            [
                'id' => 'chat_with_max',
                'label_key' => 'bots.catalog.staff.chat_with_max',
                'module' => 'assistant',
                'aliases' => ['assistant', 'chat_with_max'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'account_moves',
                'label_key' => 'bots.catalog.staff.account_moves',
                'module' => 'ledger',
                'aliases' => ['account_moves'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'audio_reports',
                'label_key' => 'bots.catalog.staff.audio_reports',
                'module' => 'audio_reports',
                'aliases' => ['audio_reports'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'moderation',
                'label_key' => 'bots.catalog.staff.moderation',
                'module' => 'audio',
                'aliases' => ['moderation'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'my_tasks',
                'label_key' => 'bots.catalog.staff.my_tasks',
                'module' => 'core',
                'aliases' => ['my_tasks'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'needed_people',
                'label_key' => 'bots.catalog.staff.needed_people',
                'module' => 'workweeks',
                'aliases' => ['needed_people'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'news',
                'label_key' => 'bots.catalog.staff.news',
                'module' => 'core',
                'aliases' => ['news'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'password_restore',
                'label_key' => 'bots.catalog.staff.password_restore',
                'module' => 'core',
                'aliases' => ['password_restore'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'voice_transcribe',
                'label_key' => 'bots.catalog.staff.voice_transcribe',
                'module' => 'audio_pipeline',
                'aliases' => ['voice_transcribe'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'badge',
                'label_key' => 'bots.catalog.staff.badge',
                'module' => 'audio',
                'aliases' => ['badge'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'shifts',
                'label_key' => 'bots.catalog.staff.shifts',
                'module' => 'workweeks',
                'aliases' => ['shifts'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'assigned_procedures',
                'label_key' => 'bots.catalog.staff.assigned_procedures',
                'module' => 'procedures',
                'aliases' => ['assigned_procedures'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'shift_salary',
                'label_key' => 'bots.catalog.staff.shift_salary',
                'module' => 'payments',
                'aliases' => ['shift_salary'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'freelance_payout_admin',
                'label_key' => 'bots.catalog.staff.freelance_payout_admin',
                'module' => 'payments',
                'aliases' => ['freelance_payout_admin'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'writeoff',
                'label_key' => 'bots.catalog.staff.writeoff',
                'module' => 'stickers',
                'aliases' => ['writeoff'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'appeals',
                'label_key' => 'bots.catalog.staff.appeals',
                'module' => '',
                'aliases' => ['appeals'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'procedure_notify',
                'label_key' => 'bots.catalog.staff.procedure_notify',
                'module' => 'procedures',
                'aliases' => ['procedure_notify'],
                'kind' => 'outbound',
            ],
        ];
    }

    /**
     * @return list<array{id:string,label_key:string,module:string,aliases:list<string>,kind:string}>
     */
    public static function clientExpected(): array
    {
        return [
            [
                'id' => 'client_cabinet',
                'label_key' => 'bots.catalog.client.profile',
                'module' => 'clients',
                'aliases' => ['client_cabinet'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'booking',
                'label_key' => 'bots.catalog.client.booking',
                'module' => 'calendar',
                'aliases' => ['booking'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'orders',
                'label_key' => 'bots.catalog.client.orders',
                'module' => 'orders',
                'aliases' => ['orders'],
                'kind' => 'cabinet',
            ],
            [
                'id' => 'polls',
                'label_key' => 'bots.catalog.client.polls',
                'module' => 'bonuses',
                'aliases' => ['polls'],
                'kind' => 'cabinet',
            ],
        ];
    }

    /**
     * @return list<array{
     *   id:string,label:string,module:string,kind:string,status:string,
     *   status_label:string,plugin_id:?string,handlers:list<string>,extra:bool
     * }>
     */
    public static function resolve(string $audience): array
    {
        $audience = strtolower(trim($audience));
        $expected = $audience === 'client' ? self::clientExpected() : self::staffExpected();
        $live = BotPlugins::listForUi($audience);
        $byId = [];
        foreach ($live as $p) {
            $byId[(string)$p['id']] = $p;
        }

        $usedLive = [];
        $rows = [];
        foreach ($expected as $feat) {
            $match = null;
            $matchId = null;
            foreach ($feat['aliases'] as $alias) {
                if (isset($byId[$alias])) {
                    $match = $byId[$alias];
                    $matchId = $alias;
                    $usedLive[$alias] = true;
                    break;
                }
            }
            $module = (string)$feat['module'];
            $kind = (string)$feat['kind'];
            if ($kind === 'outbound') {
                $activeOutbound = $module !== ''
                    && ModuleManager::isEnabled($module)
                    && ($feat['id'] !== 'procedure_notify' || class_exists('ProcedureBotNotify', false));
                $status = $activeOutbound
                    ? 'active'
                    : ($module === '' ? 'missing' : (ModuleManager::isEnabled($module) ? 'pending' : 'module_off'));
                $rows[] = [
                    'id' => (string)$feat['id'],
                    'label' => t((string)$feat['label_key']),
                    'module' => $module,
                    'kind' => $kind,
                    'status' => $status,
                    'status_label' => t('bots.catalog.status.' . $status),
                    'plugin_id' => null,
                    'handlers' => [],
                    'extra' => false,
                ];
                continue;
            }

            if ($match !== null) {
                $status = 'active';
            } elseif ($module === '') {
                $status = 'missing';
            } elseif (!ModuleManager::isEnabled($module)) {
                $status = 'module_off';
            } else {
                $status = 'pending';
            }

            $rows[] = [
                'id' => (string)$feat['id'],
                'label' => t((string)$feat['label_key']),
                'module' => $module,
                'kind' => $kind,
                'status' => $status,
                'status_label' => t('bots.catalog.status.' . $status),
                'plugin_id' => $matchId,
                'handlers' => $match !== null ? (array)$match['handlers'] : [],
                'extra' => false,
            ];
        }

        // Live plugins not in catalog (new FreeSaaS-only features).
        foreach ($live as $p) {
            $id = (string)$p['id'];
            if (!empty($usedLive[$id])) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'label' => (string)$p['label'],
                'module' => (string)($p['module'] ?? ''),
                'kind' => 'cabinet',
                'status' => 'active',
                'status_label' => t('bots.catalog.status.active'),
                'plugin_id' => $id,
                'handlers' => (array)$p['handlers'],
                'extra' => true,
            ];
        }

        return $rows;
    }

    /**
     * Live cabinet button labels for a sample context (what BotPlugins would emit).
     *
     * @return list<string>
     */
    public static function previewCabinetLabels(string $audience): array
    {
        $markup = BotPlugins::collectCabinet([
            'audience' => $audience,
            'platform' => 'max',
            'user' => ['id' => 0, 'name' => 'preview'],
            'chat_id' => '0',
        ]);
        $labels = [];
        foreach ($markup['inline_keyboard'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $btn) {
                if (!is_array($btn)) {
                    continue;
                }
                $text = trim((string)($btn['text'] ?? ''));
                if ($text !== '') {
                    $labels[] = $text;
                }
            }
        }
        return $labels;
    }

    /**
     * @return array{active:int,pending:int,module_off:int,missing:int,total:int}
     */
    public static function counts(array $rows): array
    {
        $c = ['active' => 0, 'pending' => 0, 'module_off' => 0, 'missing' => 0, 'total' => count($rows)];
        foreach ($rows as $r) {
            $st = (string)($r['status'] ?? '');
            if (isset($c[$st])) {
                $c[$st]++;
            }
        }
        return $c;
    }
}
