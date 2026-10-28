<?php
declare(strict_types=1);

/**
 * Shared module activate flow (UI + agent): enable → grant → setup.steps.
 */
final class ModulesActivate
{
    /**
     * Permission keys declared by a module (enabled or not).
     *
     * @return list<string>
     */
    public static function modulePermissionIds(string $moduleId): array
    {
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            return [];
        }
        $ids = [];
        foreach ($meta['permissions'] ?? [] as $p) {
            if (is_array($p)) {
                $pid = trim((string)($p['id'] ?? ''));
            } else {
                $pid = trim((string)$p);
            }
            if ($pid !== '') {
                $ids[] = $pid;
            }
        }
        return $ids;
    }

    /**
     * @return list<string>
     */
    public static function keysForAccess(string $moduleId, string $access): array
    {
        $all = self::modulePermissionIds($moduleId);
        $access = strtolower(trim($access));
        if ($access === 'full' || $access === '') {
            return $all;
        }
        $view = [];
        foreach ($all as $pid) {
            if (substr($pid, -5) === '.view') {
                $view[] = $pid;
            }
        }
        if ($view !== []) {
            return $view;
        }
        return $all !== [] ? [$all[0]] : [];
    }

    /**
     * @return array{done:list<string>,skipped:list<string>}
     */
    public static function setupProgress(string $moduleId): array
    {
        $raw = (string)Config::get($moduleId, 'setup_progress', '');
        if ($raw === '') {
            return ['done' => [], 'skipped' => []];
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return ['done' => [], 'skipped' => []];
        }
        $done = [];
        $skipped = [];
        foreach ((array)($j['done'] ?? []) as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $done[] = $id;
            }
        }
        foreach ((array)($j['skipped'] ?? []) as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $skipped[] = $id;
            }
        }
        return ['done' => array_values(array_unique($done)), 'skipped' => array_values(array_unique($skipped))];
    }

    /**
     * @param array{done?:list<string>,skipped?:list<string>} $progress
     */
    public static function saveSetupProgress(string $moduleId, array $progress): void
    {
        $payload = [
            'done' => array_values(array_unique(array_map('strval', (array)($progress['done'] ?? [])))),
            'skipped' => array_values(array_unique(array_map('strval', (array)($progress['skipped'] ?? [])))),
        ];
        try {
            Config::set($moduleId, 'setup_progress', json_encode($payload, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            // Module ini may not exist yet — create via set which writes cfg/modules/<id>.ini
            throw $e;
        }
    }

    /**
     * Raw setup.steps from module.json (may be empty).
     *
     * @return list<array<string,mixed>>
     */
    public static function declaredSetupSteps(string $moduleId): array
    {
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            return [];
        }
        $setup = $meta['setup'] ?? null;
        if (!is_array($setup)) {
            return [];
        }
        $steps = $setup['steps'] ?? [];
        if (!is_array($steps)) {
            return [];
        }
        $out = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            $id = trim((string)($step['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = $step;
        }
        return $out;
    }

    /**
     * Enrich setup steps with status, translated ask_user, choices (recommended flagged).
     *
     * @return list<array<string,mixed>>
     */
    public static function enrichedSetupSteps(string $moduleId): array
    {
        $progress = self::setupProgress($moduleId);
        $doneSet = array_flip($progress['done']);
        $skipSet = array_flip($progress['skipped']);
        $out = [];
        foreach (self::declaredSetupSteps($moduleId) as $step) {
            $id = (string)$step['id'];
            $status = 'pending';
            if (isset($doneSet[$id])) {
                $status = 'done';
            } elseif (isset($skipSet[$id])) {
                $status = 'skipped';
            }
            $choices = [];
            $rawChoices = $step['choices'] ?? null;
            if (is_array($rawChoices) && $rawChoices !== []) {
                foreach ($rawChoices as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $cid = trim((string)($c['id'] ?? ''));
                    if ($cid === '') {
                        continue;
                    }
                    $labelKey = trim((string)($c['label'] ?? ''));
                    $choices[] = [
                        'id' => $cid,
                        'label' => $labelKey !== '' ? t($labelKey) : $cid,
                        'label_key' => $labelKey,
                        'recommended' => !empty($c['recommended']),
                    ];
                }
            } else {
                $choices = [
                    [
                        'id' => 'open',
                        'label' => t('core.modules.setup.choice.open'),
                        'label_key' => 'core.modules.setup.choice.open',
                        'recommended' => true,
                    ],
                    [
                        'id' => 'defer',
                        'label' => t('core.modules.setup.choice.defer'),
                        'label_key' => 'core.modules.setup.choice.defer',
                        'recommended' => false,
                    ],
                ];
            }
            $titleKey = trim((string)($step['title'] ?? ''));
            $descKey = trim((string)($step['description'] ?? ''));
            $askKey = trim((string)($step['ask_user'] ?? ''));
            $agentCan = [];
            foreach ((array)($step['agent_can'] ?? []) as $a) {
                $a = trim((string)$a);
                if ($a !== '') {
                    $agentCan[] = $a;
                }
            }
            $out[] = [
                'id' => $id,
                'title' => $titleKey !== '' ? t($titleKey) : $id,
                'title_key' => $titleKey,
                'description' => $descKey !== '' ? t($descKey) : '',
                'description_key' => $descKey,
                'href' => trim((string)($step['href'] ?? '')),
                'ask_user' => $askKey !== '' ? t($askKey) : t('core.modules.setup.ask_default', [
                    'step' => $titleKey !== '' ? t($titleKey) : $id,
                ]),
                'ask_user_key' => $askKey,
                'choices' => $choices,
                'agent_can' => $agentCan,
                'status' => $status,
            ];
        }
        return $out;
    }

    /**
     * Grant targets for the UI/agent (statuses + recommended access).
     *
     * @return list<array{type:string,id:int,name:string,access:string,recommended:bool,superadmin:bool}>
     */
    public static function grantTargetSuggestions(): array
    {
        $out = [];
        if (!class_exists('StatusesRepository', false)) {
            return $out;
        }
        foreach (StatusesRepository::listAll() as $row) {
            if ((int)($row['ENABLED'] ?? 0) < 1) {
                continue;
            }
            $id = (int)($row['ID'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $isSuper = !empty($row['SUPERADMIN_ACCESS']);
            $out[] = [
                'type' => 'status',
                'id' => $id,
                'name' => (string)($row['NAME'] ?? ('#' . $id)),
                'access' => $isSuper ? 'full' : 'view',
                'recommended' => $isSuper || !empty($row['CONTROL_ACCESS']),
                'superadmin' => $isSuper,
            ];
        }
        return $out;
    }

    /**
     * Full activate snapshot for UI and agent.
     *
     * @return array<string,mixed>
     */
    public static function snapshot(string $moduleId, ?int $actorUserId = null): array
    {
        $meta = ModuleManager::get($moduleId);
        if ($meta === null) {
            return [
                'ok' => false,
                'error' => 'module_not_found',
                'module' => $moduleId,
                'http' => 404,
            ];
        }
        $enabled = !empty($meta['_enabled']);
        $maturity = ModuleCatalog::maturityOf($moduleId);
        $needsRisk = ModuleCatalog::requiresRiskAck($maturity);
        $steps = self::enrichedSetupSteps($moduleId);
        $pendingSetup = 0;
        foreach ($steps as $s) {
            if (($s['status'] ?? '') === 'pending') {
                $pendingSetup++;
            }
        }
        $phase = 'enable';
        if ($enabled) {
            $phase = $pendingSetup > 0 ? 'setup' : 'done';
        }
        return [
            'ok' => true,
            'module' => $moduleId,
            'name' => (function () use ($meta, $moduleId): string {
                $raw = $meta['name'] ?? $moduleId;
                if (is_array($raw)) {
                    return ModuleCatalog::display($raw, $moduleId);
                }
                $key = trim((string)$raw);
                if ($key !== '' && strpos($key, '.') !== false && function_exists('t')) {
                    $label = t($key);
                    if ($label !== $key) {
                        return $label;
                    }
                }
                return $key !== '' ? $key : $moduleId;
            })(),
            'enabled' => $enabled,
            'maturity' => $maturity,
            'accept_risk_required' => $needsRisk && !$enabled,
            'agent_note' => ModuleCatalog::agentNote($maturity),
            'maturity_policy' => ModuleCatalog::maturityPolicy(),
            'phase' => $phase,
            'permissions' => self::modulePermissionIds($moduleId),
            'grant_targets' => self::grantTargetSuggestions(),
            'grant_access_levels' => [
                ['id' => 'view', 'label' => t('core.modules.activate.access_view')],
                ['id' => 'full', 'label' => t('core.modules.activate.access_full'), 'recommended' => true],
            ],
            'setup_steps' => $steps,
            'setup_pending' => $pendingSetup,
            'actor_user_id' => $actorUserId,
            'activate_ui' => '/modules/' . rawurlencode($moduleId) . '/activate',
            'agent_hints' => [
                'Call snapshot first; do not enable blindly.',
                'Apply enable with accept_risk when required.',
                'Always grant the installing director full module permissions.',
                'For setup steps: if agent_can is empty or secrets are needed, ask the user using ask_user and list choices (mark recommended).',
                'Offer recommended choice for a fast confirm path; allow defer.',
            ],
            'http' => 200,
        ];
    }

    /**
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    public static function apply(string $moduleId, string $action, array $opts = []): array
    {
        $action = strtolower(trim($action));
        $meta = ModuleManager::get($moduleId);
        if ($meta === null && $action !== 'enable') {
            return ['ok' => false, 'error' => 'module_not_found', 'module' => $moduleId, 'http' => 404];
        }

        if ($action === 'enable') {
            $acceptRisk = !empty($opts['accept_risk']);
            $r = AgentOps::moduleSetEnabled($moduleId, true, $acceptRisk);
            if (empty($r['ok'])) {
                return $r;
            }
            $actorId = (int)($opts['actor_user_id'] ?? 0);
            if ($actorId < 1 && class_exists('Auth', false) && Auth::check()) {
                $actorId = (int)Auth::userId();
            }
            if ($actorId > 0) {
                self::grantToUsers($moduleId, [$actorId], 'full');
            }
            $snap = self::snapshot($moduleId, $actorId > 0 ? $actorId : null);
            $snap['migrations'] = $r['migrations'] ?? [];
            $snap['action'] = 'enable';
            return $snap;
        }

        if ($action === 'skip_enable' || $action === 'leave_disabled') {
            try {
                Config::setModuleEnabled($moduleId, false);
            } catch (Throwable $e) {
                // already off / absent
            }
            return [
                'ok' => true,
                'module' => $moduleId,
                'enabled' => false,
                'action' => 'leave_disabled',
                'http' => 200,
            ];
        }

        if ($action === 'grant') {
            if (empty($meta['_enabled'])) {
                return ['ok' => false, 'error' => 'enable_first', 'module' => $moduleId, 'http' => 400];
            }
            $access = strtolower(trim((string)($opts['access'] ?? 'full')));
            if ($access !== 'view' && $access !== 'full') {
                $access = 'full';
            }
            $statusIds = [];
            foreach ((array)($opts['status_ids'] ?? []) as $sid) {
                $sid = (int)$sid;
                if ($sid > 0) {
                    $statusIds[] = $sid;
                }
            }
            $userIds = [];
            foreach ((array)($opts['user_ids'] ?? []) as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    $userIds[] = $uid;
                }
            }
            $actorId = (int)($opts['actor_user_id'] ?? 0);
            if ($actorId < 1 && class_exists('Auth', false) && Auth::check()) {
                $actorId = (int)Auth::userId();
            }
            if ($actorId > 0 && !in_array($actorId, $userIds, true)) {
                // Director always gets full regardless of form access level for others.
                self::grantToUsers($moduleId, [$actorId], 'full');
            }
            $grantedStatuses = self::grantToStatuses($moduleId, $statusIds, $access);
            $grantedUsers = self::grantToUsers($moduleId, $userIds, $access);
            $snap = self::snapshot($moduleId, $actorId > 0 ? $actorId : null);
            $snap['action'] = 'grant';
            $snap['granted'] = [
                'access' => $access,
                'statuses' => $grantedStatuses,
                'users' => $grantedUsers,
            ];
            return $snap;
        }

        if ($action === 'setup_step') {
            if (empty($meta['_enabled'])) {
                return ['ok' => false, 'error' => 'enable_first', 'module' => $moduleId, 'http' => 400];
            }
            $stepId = trim((string)($opts['step_id'] ?? ''));
            $choice = trim((string)($opts['choice'] ?? 'defer'));
            if ($stepId === '') {
                return ['ok' => false, 'error' => 'step_id_required', 'http' => 400];
            }
            $known = false;
            foreach (self::declaredSetupSteps($moduleId) as $s) {
                if ((string)$s['id'] === $stepId) {
                    $known = true;
                    break;
                }
            }
            if (!$known) {
                return ['ok' => false, 'error' => 'unknown_step', 'step_id' => $stepId, 'http' => 400];
            }
            $progress = self::setupProgress($moduleId);
            $progress['done'] = array_values(array_filter($progress['done'], static function ($x) use ($stepId) {
                return (string)$x !== $stepId;
            }));
            $progress['skipped'] = array_values(array_filter($progress['skipped'], static function ($x) use ($stepId) {
                return (string)$x !== $stepId;
            }));
            if ($choice === 'defer' || $choice === 'skip') {
                $progress['skipped'][] = $stepId;
            } else {
                $progress['done'][] = $stepId;
            }
            self::saveSetupProgress($moduleId, $progress);
            $snap = self::snapshot($moduleId);
            $snap['action'] = 'setup_step';
            $snap['step_id'] = $stepId;
            $snap['choice'] = $choice;
            return $snap;
        }

        if ($action === 'setup_defer_all') {
            if (empty($meta['_enabled'])) {
                return ['ok' => false, 'error' => 'enable_first', 'module' => $moduleId, 'http' => 400];
            }
            $progress = self::setupProgress($moduleId);
            foreach (self::declaredSetupSteps($moduleId) as $s) {
                $id = (string)$s['id'];
                if (!in_array($id, $progress['done'], true) && !in_array($id, $progress['skipped'], true)) {
                    $progress['skipped'][] = $id;
                }
            }
            self::saveSetupProgress($moduleId, $progress);
            try {
                Config::set($moduleId, 'setup_deferred', '1');
            } catch (Throwable $e) {
                // ignore
            }
            $snap = self::snapshot($moduleId);
            $snap['action'] = 'setup_defer_all';
            return $snap;
        }

        if ($action === 'finish') {
            $snap = self::snapshot($moduleId);
            $snap['action'] = 'finish';
            return $snap;
        }

        return ['ok' => false, 'error' => 'unknown_action', 'action' => $action, 'http' => 400];
    }

    /**
     * @param list<int> $statusIds
     * @return list<int>
     */
    public static function grantToStatuses(string $moduleId, array $statusIds, string $access): array
    {
        $keys = self::keysForAccess($moduleId, $access);
        if ($keys === [] || !class_exists('StatusesRepository', false)) {
            return [];
        }
        if (!Database::columnExists('base_admin_statuses', 'PERMISSIONS')) {
            return [];
        }
        $done = [];
        foreach ($statusIds as $sid) {
            $sid = (int)$sid;
            if ($sid < 1) {
                continue;
            }
            $row = StatusesRepository::find($sid);
            if ($row === null) {
                continue;
            }
            $perms = StatusesRepository::decodePermissions($row['PERMISSIONS'] ?? null);
            foreach ($keys as $k) {
                $perms[$k] = 1;
            }
            StatusesRepository::update($sid, [
                'PERMISSIONS' => StatusesRepository::encodePermissions($perms),
            ]);
            $done[] = $sid;
        }
        return $done;
    }

    /**
     * @param list<int> $userIds
     * @return list<int>
     */
    public static function grantToUsers(string $moduleId, array $userIds, string $access): array
    {
        $keys = self::keysForAccess($moduleId, $access);
        if ($keys === [] || !class_exists('UsersRepository', false)) {
            return [];
        }
        $permMap = [];
        foreach ($keys as $k) {
            $permMap[$k] = 1;
        }
        $done = [];
        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            if ($uid < 1) {
                continue;
            }
            $row = UsersRepository::findForEdit($uid);
            if ($row === null) {
                continue;
            }
            $raw = $row['status'] ?? null;
            $blob = UsersRepository::encodeStatusBlob(is_string($raw) ? $raw : null, $permMap);
            UsersRepository::update($uid, ['status_blob' => $blob]);
            $done[] = $uid;
        }
        return $done;
    }
}
