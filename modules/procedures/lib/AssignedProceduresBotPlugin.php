<?php
declare(strict_types=1);

/** Staff bot: procedures assigned for the rest of today (oper assigned_procedures). */
final class AssignedProceduresBotPlugin
{
    public static function register(): void
    {
        if (!ModuleManager::isEnabled('bots') || !class_exists('BotPlugins', false)) {
            return;
        }

        BotPlugins::register('assigned_procedures', [
            'priority' => 25,
            'audiences' => ['staff'],
            'label' => 'bots.catalog.staff.assigned_procedures',
            'module' => 'procedures',
            'cabinet_buttons' => static function (array $ctx): array {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return [];
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return [];
                }
                if (!class_exists('WorkweeksRepository', false)
                    || !WorkweeksRepository::onShiftToday($uid)
                    || !WorkweeksRepository::isMasseurOrParttime($uid)
                ) {
                    return [];
                }
                return [[[
                    'text' => t('bots.catalog.staff.assigned_procedures'),
                    'callback_data' => 'assigned_procedures',
                ]]];
            },
            'handle_callback' => static function (array $ctx) {
                if (($ctx['audience'] ?? '') !== 'staff') {
                    return null;
                }
                if ((string)($ctx['callback_data'] ?? '') !== 'assigned_procedures') {
                    return null;
                }
                $user = is_array($ctx['user'] ?? null) ? $ctx['user'] : null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid < 1) {
                    return true;
                }
                return Auth::runAs($uid, static function () use ($ctx, $uid) {
                    self::show($ctx, $uid);
                    return true;
                });
            },
        ]);
    }

    /** @param array<string,mixed> $ctx */
    private static function show(array $ctx, int $uid): void
    {
        $kb = ['inline_keyboard' => [[[
            'text' => t('bots.cabinet.refresh'),
            'callback_data' => 'cabinet',
        ]]]];

        if (!class_exists('WorkweeksRepository', false)
            || !WorkweeksRepository::onShiftToday($uid)
            || !WorkweeksRepository::isMasseurOrParttime($uid)
        ) {
            BotClient::replyUi($ctx, t('procedures.bot.assigned.denied'), $kb);
            return;
        }

        $now = time();
        $todayEnd = (int)mktime(23, 59, 59);
        $rows = ProceduresRepository::listForDayRange($now, $todayEnd + 1, $uid);
        $lines = [];
        foreach ($rows as $row) {
            if (class_exists('ProceduresChain', false) && ProceduresChain::isBreak($row)) {
                continue;
            }
            if (isset($row['enabled']) && (int)$row['enabled'] === 0) {
                continue;
            }
            $time = date('H:i', (int)($row['dtstart'] ?? 0));
            $product = trim((string)($row['product_name'] ?? ''));
            if ($product === '') {
                $product = trim((string)($row['type_name'] ?? ''));
            }
            if ($product === '') {
                $product = '#' . (int)($row['id_good'] ?? 0);
            }
            $client = trim((string)($row['client_name'] ?? ''));
            if ($client === '') {
                $client = '—';
            }
            $lines[] = t('procedures.bot.assigned.line', [
                'time' => $time,
                'product' => $product,
                'client' => $client,
            ]);
        }

        // Soft: website orders for this specialist today (cashComplete).
        foreach (self::orderLines($uid, $now, $todayEnd) as $ol) {
            $lines[] = $ol;
        }

        if ($lines === []) {
            BotClient::replyUi($ctx, t('procedures.bot.assigned.empty'), $kb);
            return;
        }
        $text = t('procedures.bot.assigned.title') . "\n" . implode("\n", $lines);
        BotClient::replyUi($ctx, $text, $kb);
    }

    /**
     * @return list<string>
     */
    private static function orderLines(int $uid, int $from, int $to): array
    {
        if (!Database::tableExists('base_orders')) {
            return [];
        }
        $out = [];
        try {
            $st = Database::pdo()->prepare(
                "SELECT o.ID AS id, o.DT_EVENT_NEW AS dt, o.CART AS cart, o.ID_USER AS id_user,
                        COALESCE(c.name, '') AS client_name
                 FROM base_orders o
                 LEFT JOIN base_clients c ON c.id = o.ID_USER
                 WHERE o.ID_COSMETOLOG = ?
                   AND o.STATUS = 'cashComplete'
                   AND o.DT_EVENT_NEW >= ? AND o.DT_EVENT_NEW <= ?
                 ORDER BY o.DT_EVENT_NEW ASC
                 LIMIT 40"
            );
            $st->execute([$uid, $from, $to]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $names = self::cartNames((string)($row['cart'] ?? ''));
                $client = trim((string)$row['client_name']);
                if ($client === '') {
                    $client = '—';
                }
                $out[] = t('procedures.bot.assigned.order_line', [
                    'time' => date('H:i', (int)$row['dt']),
                    'products' => $names !== '' ? $names : ('#' . (int)$row['id']),
                    'client' => $client,
                ]);
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    private static function cartNames(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $cart = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($cart)) {
            $json = json_decode($raw, true);
            $cart = is_array($json) ? $json : null;
        }
        if (!is_array($cart)) {
            return '';
        }
        $names = [];
        foreach ($cart as $item) {
            if (!is_array($item)) {
                continue;
            }
            $n = trim((string)($item['name'] ?? $item['NAME'] ?? ''));
            if ($n !== '') {
                $names[] = $n;
            }
        }
        return implode(', ', $names);
    }
}
