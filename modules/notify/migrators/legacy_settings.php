<?php
declare(strict_types=1);

/**
 * Turn the per-module notify settings (orders Max group, procedures operator pings, vacation group
 * reminders, backups, edge hosts, day payroll issuers) into base_notify_rules.
 * With notify enabled and no matching rule nothing is sent, so this keeps behaviour equivalent.
 * Idempotent: a rule is skipped when one with the same name already exists. Video alerts had no
 * working recipient before, so there is nothing to convert.
 *
 * @return array{ok:bool,message:string,created:int,skipped:int}
 */
return static function (): array {
    if (!class_exists('NotifyRulesRepository', false) || !NotifyRulesRepository::tableReady()) {
        return ['ok' => false, 'message' => 'notify tables missing', 'created' => 0, 'skipped' => 0];
    }

    $names = [];
    foreach (NotifyRulesRepository::listAll() as $r) {
        $names[(string)$r['name']] = true;
    }
    $created = 0;
    $skipped = 0;

    $add = static function (string $name, string $event, array $conditions, array $targets, string $template, bool $enabled) use (&$names, &$created, &$skipped): void {
        if ($targets === []) {
            return;
        }
        if (isset($names[$name])) {
            $skipped++;
            return;
        }
        NotifyRulesRepository::save(0, [
            'name' => $name,
            'event' => $event,
            'enabled' => $enabled ? 1 : 0,
            'sort' => 50,
            'stop_on_match' => 0,
            'kind' => 'notify',
            'conditions' => $conditions,
            'targets' => $targets,
            'template' => $template,
            'stages' => [],
        ]);
        $names[$name] = true;
        $created++;
    };

    $chatTargets = static function (string $chatId, string $platform): array {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return [];
        }
        $platforms = $platform === 'both' ? ['max', 'telegram'] : [$platform === 'telegram' ? 'telegram' : 'max'];
        $out = [];
        foreach ($platforms as $p) {
            $out[] = ['kind' => 'chat', 'chat_id' => $chatId, 'platform' => $p, 'channels' => ['bot'], 'strategy' => 'all'];
        }
        return $out;
    };
    $staffTarget = static function (array $ids): array {
        return $ids === [] ? [] : [['kind' => 'staff', 'user_ids' => array_values($ids), 'channels' => ['bot'], 'strategy' => 'all']];
    };
    $titled = static function (string $title, string $defaultKey): string {
        if ($title === '') {
            return '';
        }
        $lines = explode("\n", t($defaultKey));
        $lines[0] = '<b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b>';
        return implode("\n", $lines);
    };

    // --- orders: Max group on create / client cancel -----------------------------------------
    if (class_exists('OrdersConfig', false) && OrdersConfig::maxNotifyChatId() !== '') {
        $chatId = OrdersConfig::maxNotifyChatId();
        $enabled = OrdersConfig::maxNotifyEnabled();
        $target = $chatTargets($chatId, 'max');
        if (class_exists('BotChatsRepository', false) && BotChatsRepository::tableReady()) {
            $botChatId = BotChatsRepository::upsert([
                'platform' => 'max',
                'chat_id' => $chatId,
                'title' => t('bots.recipients.chat.orders'),
                'purpose' => 'orders',
                'enabled' => 1,
            ]);
            if ($botChatId > 0) {
                $target = [['kind' => 'chat', 'bot_chat_id' => $botChatId, 'platform' => 'max', 'channels' => ['bot'], 'strategy' => 'all']];
            }
        }
        $add('legacy orders.created', 'orders.created', [], $target, $titled(OrdersConfig::maxNotifyTitle(), 'orders.notify.tpl.created'), $enabled);
        // The legacy path only announced cancellations made by the client.
        $add(
            'legacy orders.cancelled',
            'orders.cancelled',
            [['field' => 'cancelled_by', 'op' => 'eq', 'value' => 'client']],
            $target,
            $titled(OrdersConfig::maxNotifyTitleCancel(), 'orders.notify.tpl.cancelled'),
            $enabled
        );
    }

    // --- procedures: ping the operator about procedures starting today ------------------------
    if (class_exists('ProceduresNotifyProvider', false)) {
        $today = [['field' => 'is_today', 'op' => 'eq', 'value' => '1']];
        $operator = [['kind' => 'subject', 'subject' => 'operator', 'channels' => ['bot'], 'strategy' => 'all']];
        $add('legacy procedures.operator.today', 'procedures.created', $today, $operator, '', true);
        $add('legacy procedures.updated.operator.today', 'procedures.updated', $today, $operator, '', true);
    }

    // --- vacations: group reminder -------------------------------------------------------------
    if (class_exists('VacationsConfig', false) && VacationsConfig::remindChatId() !== '') {
        $add(
            'legacy vacations.remind',
            'vacations.remind',
            [],
            $chatTargets(VacationsConfig::remindChatId(), VacationsConfig::remindPlatform()),
            VacationsConfig::remindMessage(),
            VacationsConfig::remindEnabled()
        );
    }

    // --- backups: staff + group -----------------------------------------------------------------
    if (class_exists('BackupConfig', false)) {
        $targets = array_merge(
            $staffTarget(BackupConfig::notifyStaffIds()),
            $chatTargets(BackupConfig::notifyChatId(), BackupConfig::notifyPlatform())
        );
        $on = BackupConfig::notifyBots();
        $add('legacy backups.failed', 'backups.failed', [], $targets, '', $on && BackupConfig::notifyOnFail());
        $add('legacy backups.succeeded', 'backups.succeeded', [], $targets, '', $on && BackupConfig::notifyOnSuccess());
        $add('legacy backups.stale', 'backups.stale', [], $targets, '', $on && BackupConfig::notifyOnStale());
        $add('legacy backups.test', 'backups.test', [], $targets, '', $on);
    }

    // --- edge hosts: link lost / restored --------------------------------------------------------
    if (class_exists('EdgeHostsNotify', false)) {
        $targets = array_merge(
            $staffTarget(EdgeHostsNotify::staffIds()),
            $chatTargets(EdgeHostsNotify::chatId(), EdgeHostsNotify::platform())
        );
        // edge_notify_enabled stays the producer-side master switch.
        $add('legacy core.edge_down', 'core.edge_down', [], $targets, '', true);
        $add('legacy core.edge_up', 'core.edge_up', [], $targets, '', true);
        $add('legacy core.edge_test', 'core.edge_test', [], $targets, '', true);
    }

    // --- payments: day payroll → cash issuers on shift (resolved by the producer) ----------------
    if (class_exists('DayPayroll', false)) {
        $issuer = [['kind' => 'subject', 'subject' => 'issuer', 'channels' => ['bot'], 'strategy' => 'all']];
        $add('legacy payments.day_payroll_requested', 'payments.day_payroll_requested', [], $issuer, '', true);
        $add('legacy payments.day_payroll_cancelled', 'payments.day_payroll_cancelled', [], $issuer, '', true);
    }

    return [
        'ok' => true,
        'message' => 'created ' . $created . ' rules, skipped ' . $skipped,
        'created' => $created,
        'skipped' => $skipped,
    ];
};
