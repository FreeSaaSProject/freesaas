<?php
declare(strict_types=1);

/**
 * TEMP (phpshop cutoff): map oper flat keys → FreeSaaS module.perm.
 * Lives under migrators/ on purpose — delete with the phpshop_* data migrators
 * after legacy tables are gone (not a runtime platform service).
 *
 * OR semantics: legacy ON and target empty → set 1. Legacy keys kept for re-runs.
 */
final class OperPermissionsRemap
{
    /**
     * oper key → list of FreeSaaS permission ids granted when that key is ON.
     *
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        return [
            'enter' => ['core.home'],

            // Core / team
            'show_admins' => ['core.users'],
            'edit_admin' => ['core.users'],
            'edit_admin_new' => ['core.users', 'core.news'],
            'edit_admin_view' => ['core.users'],
            'edit_admin_comment' => ['core.users'],
            'show_admin_statuses' => ['core.statuses'],
            'edit_admin_status' => ['core.statuses'],
            'show_admin_news' => ['core.news'],
            'show_news' => ['core.news'],
            'edit_new' => ['core.news'],
            'show_news_statuses' => ['core.news_statuses'],
            'edit_news_status' => ['core.news_statuses'],
            'show_site_sets' => ['core.settings', 'likespa.view'],

            // Clients (oper "users" = CRM shopusers)
            'show_users' => ['clients.view'],
            'edit_user' => ['clients.edit'],
            'show_users_statuses' => ['clients.statuses'],
            'edit_users_status' => ['clients.statuses'],
            'show_shopusers_tags' => ['clients.tags'],
            'edit_shopusers_tag' => ['clients.tags'],

            // Calendar / events
            'show_events' => ['calendar.view'],
            'show_events2' => ['calendar.view'],
            'show_events_changes' => ['calendar.view'],
            'show_event_cancellations' => ['calendar.view'],
            'edit_event' => ['calendar.edit'],
            'edit_event_cancellation' => ['calendar.edit'],

            // Workweeks
            'edit_work' => ['workweeks.view', 'workweeks.edit'],
            'edit_work2' => ['workweeks.view', 'workweeks.edit'],
            'show_weekload' => ['workweeks.view'],

            // Procedures
            'show_procedures' => ['procedures.view'],
            'edit_procedure' => ['procedures.edit'],
            'show_proctypes' => ['procedures.types'],
            'edit_proctype' => ['procedures.types'],
            'show_procedure_restores' => ['likespa.restores'],
            'edit_procedure_restore' => ['likespa.restores'],

            // Orders
            'show_orders' => ['orders.view'],
            'show_oldorders' => ['orders.view'],
            'edit_order' => ['orders.edit'],
            'edit_oldorder' => ['orders.edit'],

            // Payments
            'show_payments' => ['payments.view'],
            'show_paymentsoperator' => ['payments.view'],
            'show_client_payments' => ['payments.view'],
            'edit_payment' => ['payments.edit'],
            'edit_payment_movement' => ['payments.edit'],
            'edit_client_payment' => ['payments.edit'],
            'show_payment_types' => ['payments.types'],
            'edit_payment_type' => ['payments.types'],

            // Rates / procents
            'show_procent_historys' => ['rates.view'],
            'edit_procent_history' => ['rates.edit'],
            'show_rates' => ['rates.view'],
            'edit_rate' => ['rates.edit'],
            'show_procents' => ['procents.view'],
            'show_procentsoperator' => ['procents.view'],

            // Cabinets / tables / units / suppliers
            'show_cabinets' => ['cabinets.view'],
            'edit_cabinet' => ['cabinets.edit'],
            'show_tables' => ['tables.view'],
            'edit_table' => ['tables.edit'],
            'show_units' => ['units.view'],
            'edit_unit' => ['units.edit'],
            'show_suppliers' => ['suppliers.view'],
            'edit_supplier' => ['suppliers.edit'],

            // Catalog / stocks / norms / stickers
            'show_categories' => ['catalog.view'],
            'edit_category' => ['catalog.edit'],
            'show_prices' => ['catalog.view'],
            'show_matrix_prices' => ['catalog.view'],
            'edit_matrix_price' => ['catalog.edit'],
            'show_stocks' => ['stocks.view'],
            'edit_stock' => ['stocks.edit'],
            'show_futurestocks' => ['stocks.view'],
            'show_stock_analyze' => ['stocks.view'],
            'show_good_defaults' => ['norms.view'],
            'edit_good_default' => ['norms.edit'],
            'show_good_sticker_ins' => ['stickers.view'],
            'show_good_sticker_outs' => ['stickers.view'],
            'show_acts_in' => ['stickers.view'],
            'show_acts_out' => ['stickers.view'],
            'show_acts2_in' => ['stickers.view'],
            'show_acts2_out' => ['stickers.view'],
            'show_acts_movements' => ['stickers.view'],
            'edit_good_sticker_in' => ['stickers.edit'],
            'edit_good_sticker_out' => ['stickers.edit'],
            'edit_act_in' => ['stickers.edit'],
            'edit_act_out' => ['stickers.edit'],
            'edit_act2_in' => ['stickers.edit'],
            'edit_act2_out' => ['stickers.edit'],
            'edit_act_movement' => ['stickers.edit'],
            'edit_act_out_sm' => ['stickers.writeoff'],
            'edit_act_out_sm2' => ['stickers.writeoff'],
            'edit_good_out' => ['stickers.writeoff'],

            // Sheets
            'show_sheet_accounts' => ['sheets.view'],
            'show_sheet_moves' => ['sheets.view'],
            'edit_sheet_account' => ['sheets.edit'],
            'edit_sheet_move' => ['sheets.edit'],
            'edit_sheet_movement' => ['sheets.edit'],

            // Contracts / vacations / polls
            'show_contracts' => ['contracts.list'],
            'edit_contract' => ['contracts.manage'],
            'show_vacations' => ['vacations.view'],
            'show_vacation' => ['vacations.view'],
            'edit_vacation' => ['vacations.edit'],
            'show_polls' => ['polls.view'],
            'edit_poll' => ['polls.edit'],
            'show_poll_invites' => ['poll_invites.view'],
            'edit_poll_invite' => ['poll_invites.edit'],
            'edit_poll_invite2' => ['poll_invites.edit'],

            // Certificates / bonuses
            'show_sertificates' => ['certificates.view'],
            'show_sert_movements' => ['certificates.view'],
            'edit_sertificate' => ['certificates.edit'],
            'show_bonuses' => ['bonuses.view'],
            'edit_bonus' => ['bonuses.edit'],
            'bonus.php' => ['bonuses.apply'],

            // Terminals / bracelets / floor
            'show_terminals' => ['terminals.view'],
            'show_devices' => ['terminals.view'],
            'show_bracelets' => ['terminals.view'],
            'show_uids' => ['terminals.view'],
            'show_fullstats' => ['terminals.view'],
            'show_term_scans' => ['terminals.view', 'likespa.view'],
            'term_scans' => ['terminals.view', 'likespa.view'],
            'show_terminals_activations' => ['terminals.view'],
            'edit_terminal' => ['terminals.edit'],
            'edit_device' => ['terminals.edit'],
            'edit_bracelet' => ['terminals.edit'],
            'edit_uids' => ['terminals.edit'],
            'edit_uid2' => ['terminals.edit'],
            'edit_fullstat' => ['terminals.edit'],
            'edit_terminals_activation' => ['terminals.edit'],
            'api.php' => ['terminals.sync'],

            // Sites / CMS
            'show_pages' => ['sites.pages', 'sites.view'],
            'edit_page' => ['sites.pages', 'sites.edit'],
            'show_promopages' => ['sites.promopages'],
            'edit_promopage' => ['sites.promopages'],
            'show_redirects' => ['sites.redirects'],
            'edit_redirect' => ['sites.redirects'],
            'show_promos' => ['likespa.promo', 'sites.view'],
            'edit_promo' => ['likespa.promo', 'likespa.edit'],

            // LikeSpa pack
            'show_assistants' => ['likespa.view'],
            'show_chairstarts' => ['likespa.view'],

            // Audio stack
            'show_records' => ['audio.view'],
            'edit_record' => ['audio.edit'],
            'show_badge_assignments' => ['audio.badges'],
            'show_records_types' => ['audio_dicts.view'],
            'show_records_dictonarys' => ['audio_dicts.view'],
            'show_records_keys' => ['audio_dicts.view'],
            'show_records_scripts' => ['audio_dicts.view'],
            'show_records_trashes' => ['audio_dicts.view'],
            'edit_records_type' => ['audio_dicts.edit'],
            'edit_records_dictonary' => ['audio_dicts.edit'],
            'edit_records_key' => ['audio_dicts.edit'],
            'edit_records_script' => ['audio_dicts.edit'],
            'edit_records_trashe' => ['audio_dicts.edit'],
            'show_records_calendars' => ['audio_reports.view'],
            'show_records_calendars2' => ['audio_reports.view'],
            'show_massage_reports' => ['audio_reports.view', 'staff_reports.view'],
            'show_massage_reports_summarys' => ['audio_reports.view', 'staff_reports.view'],
            'massage_reports_engine' => ['audio_reports.edit', 'staff_reports.edit'],
            'show_records_llm_studies' => ['audio_llm.view'],
            'edit_records_llm_study' => ['audio_llm.edit'],
            'show_records_llm_moderations' => ['audio_llm.view'],
            'records_llm_moderation' => ['audio_llm.edit'],
            'show_records_collections' => ['audio_pipeline.view'],
            'show_records_instances' => ['audio_pipeline.view'],
            'show_records_telemetrys' => ['asr.view'],

            // Bots / gcal
            'show_telegram_sends' => ['bots.view'],
            'edit_telegram_send' => ['bots.manage'],
            'show_employee_bot_gcal_settings' => ['gcal.manage', 'bots.view'],

            // Ledger / funds / finance / liquid / banks
            'show_accounts' => ['ledger.view'],
            'show_account_moves' => ['ledger.view'],
            'show_account_move_types' => ['ledger.types'],
            'edit_account' => ['ledger.edit'],
            'edit_account_move' => ['ledger.edit'],
            'edit_account_movement' => ['ledger.edit'],
            'edit_account_move_type' => ['ledger.types'],
            'show_funds' => ['funds.view'],
            'show_fund_movements' => ['funds.view'],
            'edit_fund' => ['funds.edit'],
            'edit_fund_movement' => ['funds.edit'],
            'show_balance' => ['finance.view'],
            'show_balance2' => ['finance.view'],
            'show_balance3' => ['finance.view'],
            'show_balance4' => ['finance.view'],
            'show_balance5' => ['finance.view'],
            'show_balance6' => ['finance.view'],
            'show_actives' => ['liquid_assets.view'],
            'show_actives2' => ['liquid_assets.view'],
            'show_active_prices' => ['liquid_assets.view'],
            'edit_active' => ['liquid_assets.edit'],
            'edit_active_price' => ['liquid_assets.edit'],
            'show_money_moves' => ['banks.view'],
            'edit_money_move' => ['banks.sync'],
            'edit_money_move2' => ['banks.sync'],

            // BI / staff reports quality KPIs (best-effort)
            'show_kpi' => ['bi.manage'],
            'show_quality' => ['staff_reports.view'],
            'show_quality2' => ['staff_reports.view'],
            'show_quality3' => ['staff_reports.view'],
            'show_qualityoperator' => ['staff_reports.view'],
        ];
    }

    /**
     * All permission ids from enabled modules (migrator-only helper).
     *
     * @return list<string>
     */
    private static function allPermissionIds(): array
    {
        if (!class_exists(UsersRepository::class, false)) {
            return [];
        }
        $ids = [];
        foreach (UsersRepository::availablePermissionKeys() as $group) {
            foreach ($group['keys'] as $row) {
                $pid = trim((string)($row['id'] ?? ''));
                if ($pid !== '') {
                    $ids[] = $pid;
                }
            }
        }
        return $ids;
    }

    /**
     * SUPERADMIN: turn ON every enabled-module permission. Null if unchanged.
     */
    private static function grantAllPermissionsBlob(?string $raw): ?string
    {
        if (!class_exists(UsersRepository::class, false)) {
            return null;
        }
        $ids = self::allPermissionIds();
        if ($ids === []) {
            return null;
        }
        $grant = [];
        foreach ($ids as $id) {
            $grant[$id] = 1;
        }
        $before = UsersRepository::decodePermissions(is_string($raw) ? $raw : '');
        foreach ($grant as $id => $_) {
            if (empty($before[$id])) {
                return UsersRepository::encodeStatusBlob($raw, $grant);
            }
        }
        return null;
    }

    /**
     * Apply map to a serialized status blob. Returns null if unchanged / unreadable.
     */
    public static function remapBlob(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $blob = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($blob) || !isset($blob['permissions']) || !is_array($blob['permissions'])) {
            return null;
        }
        $perms = [];
        foreach ($blob['permissions'] as $k => $v) {
            $perms[(string)$k] = (int)$v ? 1 : 0;
        }
        $changed = false;
        foreach (self::map() as $from => $targets) {
            if (empty($perms[$from])) {
                continue;
            }
            foreach ($targets as $to) {
                $to = (string)$to;
                if ($to === '' || strpos($to, '.') === false) {
                    continue;
                }
                if (empty($perms[$to])) {
                    $perms[$to] = 1;
                    $changed = true;
                }
            }
        }
        // Also copy already-namespaced likespa CMS → sites (parity with schema migrator).
        foreach ([
            'likespa.pages' => 'sites.pages',
            'likespa.promopages' => 'sites.promopages',
            'likespa.redirects' => 'sites.redirects',
        ] as $from => $to) {
            if (!empty($perms[$from]) && empty($perms[$to])) {
                $perms[$to] = 1;
                $changed = true;
            }
        }
        if (!$changed) {
            return null;
        }
        $blob['permissions'] = $perms;
        return serialize($blob);
    }

    /**
     * Remap all base_users.status blobs. Idempotent.
     * SUPERADMIN_ACCESS roles get every enabled-module permission (no legacy analysis).
     *
     * @return array{ok:bool,scanned:int,updated:int,superadmin:int,message:string}
     */
    public static function remapAllUsers(PDO $pdo): array
    {
        if (!Database::tableExists('base_users') || !Database::columnExists('base_users', 'status')) {
            return [
                'ok' => false,
                'scanned' => 0,
                'updated' => 0,
                'superadmin' => 0,
                'message' => 'base_users.status missing',
            ];
        }
        $hasStatus = Database::columnExists('base_users', 'ID_STATUS');
        $sql = $hasStatus
            ? 'SELECT id, status, ID_STATUS FROM base_users'
            : 'SELECT id, status FROM base_users';
        $st = $pdo->query($sql);
        if (!$st) {
            return [
                'ok' => false,
                'scanned' => 0,
                'updated' => 0,
                'superadmin' => 0,
                'message' => 'query failed',
            ];
        }
        $upd = $pdo->prepare('UPDATE base_users SET status = ? WHERE id = ?');
        $scanned = 0;
        $updated = 0;
        $superadmin = 0;
        $canSa = $hasStatus && class_exists(UsersRepository::class, false);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $scanned++;
            $raw = (string)($row['status'] ?? '');
            $isSa = $canSa && UsersRepository::statusIsSuperadmin((int)($row['ID_STATUS'] ?? 0));
            if ($isSa) {
                $next = self::grantAllPermissionsBlob($raw !== '' ? $raw : null);
                if ($next !== null) {
                    $superadmin++;
                }
            } else {
                $next = self::remapBlob($raw);
            }
            if ($next === null) {
                continue;
            }
            $upd->execute([$next, (int)$row['id']]);
            $updated++;
        }
        return [
            'ok' => true,
            'scanned' => $scanned,
            'updated' => $updated,
            'superadmin' => $superadmin,
            'message' => "permissions remapped (scanned={$scanned}, updated={$updated}, superadmin_all={$superadmin})",
        ];
    }
}
