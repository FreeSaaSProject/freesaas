<?php
declare(strict_types=1);

/**
 * Row selection + group operations for list tables.
 * Modules register actions in bootstrap; dependency checks via EventBus core.bulk.check_item.
 */
final class BulkActions
{
    private const FLASH_KEY = 'freesaas_bulk_flash';

    /** @var array<string, array<string, array<string,mixed>>> list_id => action_id => def */
    private static $actions = [];

    /**
     * @param array{
     *   label:string,
     *   group?:string,
     *   permission?:string,
     *   capability?:string,
     *   confirm?:string,
     *   max_selection?:int,
     *   fields?:list<array{name:string,type?:string,label?:string,options?:array<int|string,string>|callable}>,
     *   run:callable
     * } $def
     * Optional `group` is an i18n key for an <optgroup> label (oper-style action buckets).
     * Optional `max_selection` (e.g. 1) greys out the option when more rows are checked;
     * server rejects oversize selections. Run may return `redirect` for a post-action jump.
     */
    public static function register(string $listId, string $actionId, array $def): void
    {
        $listId = trim($listId);
        $actionId = trim($actionId);
        if ($listId === '' || $actionId === '') {
            throw new InvalidArgumentException('BulkActions: list_id and action_id required');
        }
        if (empty($def['label']) || !is_callable($def['run'] ?? null)) {
            throw new InvalidArgumentException('BulkActions: label and run required');
        }
        if (!isset(self::$actions[$listId])) {
            self::$actions[$listId] = [];
        }
        self::$actions[$listId][$actionId] = $def;
    }

    /**
     * Machine-readable catalog (no callables). Optional module filter by list_id prefix.
     *
     * @return list<array{list_id:string,action:string,label:string,permission:?string,capability:?string,group:?string}>
     */
    public static function catalog(?string $moduleId = null): array
    {
        $moduleId = $moduleId !== null ? trim($moduleId) : '';
        $out = [];
        foreach (self::$actions as $listId => $actions) {
            $listId = (string)$listId;
            if ($moduleId !== '' && !self::listBelongsToModule($listId, $moduleId)) {
                continue;
            }
            foreach ($actions as $actionId => $def) {
                if (!is_array($def)) {
                    continue;
                }
                $out[] = [
                    'list_id' => $listId,
                    'action' => (string)$actionId,
                    'label' => (string)($def['label'] ?? ''),
                    'permission' => isset($def['permission']) ? (string)$def['permission'] : null,
                    'capability' => isset($def['capability']) ? (string)$def['capability'] : null,
                    'group' => isset($def['group']) ? (string)$def['group'] : null,
                ];
            }
        }
        return $out;
    }

    private static function listBelongsToModule(string $listId, string $moduleId): bool
    {
        if ($listId === $moduleId) {
            return true;
        }
        if (strpos($listId, $moduleId . '_') === 0) {
            return true;
        }
        if (strpos($listId, $moduleId . '.') === 0) {
            return true;
        }
        // e.g. audio_scripts owned by audio_dicts
        $compact = str_replace('_', '', $moduleId);
        $listCompact = str_replace('_', '', $listId);
        return $compact !== '' && strpos($listCompact, $compact) === 0;
    }

    public static function hasVisible(string $listId): bool
    {
        return self::visibleActions($listId) !== [];
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public static function visibleActions(string $listId): array
    {
        $out = [];
        foreach (self::$actions[$listId] ?? [] as $id => $def) {
            if (!self::allowed($def)) {
                continue;
            }
            $out[$id] = $def;
        }
        return $out;
    }

    /**
     * Registered action definition, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function actionDef(string $listId, string $actionId): ?array
    {
        $listId = trim($listId);
        $actionId = trim($actionId);
        if ($listId === '' || $actionId === '') {
            return null;
        }
        $def = self::$actions[$listId][$actionId] ?? null;
        return is_array($def) ? $def : null;
    }

    /** @param array<string,mixed> $def */
    public static function isAllowed(array $def): bool
    {
        return self::allowed($def);
    }

    /** @param array<string,mixed> $def */
    private static function allowed(array $def): bool
    {
        $cap = trim((string)($def['capability'] ?? ''));
        if ($cap !== '') {
            return Auth::capability($cap);
        }
        $perm = trim((string)($def['permission'] ?? ''));
        if ($perm !== '') {
            return Auth::can($perm);
        }
        return Auth::check();
    }

    /**
     * Run check_item EventBus for one id. Returns reason string if blocked, else ''.
     *
     * @param array<string,mixed> $params
     */
    public static function checkItem(string $listId, string $actionId, int $id, array $params = []): string
    {
        $payload = EventBus::emit('core.bulk.check_item', [
            'list_id' => $listId,
            'action' => $actionId,
            'id' => $id,
            'params' => $params,
            'blocked' => false,
            'reason' => '',
        ]);
        if (!is_array($payload)) {
            return '';
        }
        if (!empty($payload['blocked'])) {
            $reason = trim((string)($payload['reason'] ?? ''));
            return $reason !== '' ? $reason : t('core.bulk.blocked');
        }
        return '';
    }

    /**
     * Handle bulk POST. Returns null if request is not a bulk action for this list.
     *
     * @param array<string,mixed> $post
     * @return array{done:int,skipped:list<array{id:int,reason:string}},action:string}|null
     */
    public static function handlePost(string $listId, array $post): ?array
    {
        if (!isset($post['bulk_action'])) {
            return null;
        }
        $actionId = trim((string)$post['bulk_action']);
        if ($actionId === '' || $actionId === '0') {
            return [
                'done' => 0,
                'skipped' => [],
                'action' => '',
                'error' => t('core.bulk.err.no_action'),
            ];
        }
        $actions = self::visibleActions($listId);
        if (!isset($actions[$actionId])) {
            return [
                'done' => 0,
                'skipped' => [],
                'action' => $actionId,
                'error' => t('core.bulk.err.forbidden'),
            ];
        }
        $def = $actions[$actionId];
        if ($actionId === 'delete' && AgentHttp::wantsJson() && !AgentHttp::allowsDelete()) {
            return [
                'done' => 0,
                'skipped' => [],
                'action' => $actionId,
                'error' => t('core.entity.agent_delete_denied'),
            ];
        }
        // Prefer packed ids_json (avoids PHP max_input_vars dropping csrf / ids on large selects).
        $rawIds = [];
        $idsJson = trim((string)($post['ids_json'] ?? ''));
        if ($idsJson !== '') {
            $decoded = json_decode($idsJson, true);
            if (is_array($decoded)) {
                $rawIds = $decoded;
            }
        }
        if ($rawIds === [] && isset($post['ids'])) {
            $rawIds = is_array($post['ids']) ? $post['ids'] : [];
        }
        $ids = [];
        foreach ($rawIds as $raw) {
            $id = (int)$raw;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return [
                'done' => 0,
                'skipped' => [],
                'action' => $actionId,
                'error' => t('core.bulk.err.no_selection'),
            ];
        }
        $maxSelection = (int)($def['max_selection'] ?? 0);
        if ($maxSelection > 0 && count($ids) > $maxSelection) {
            return [
                'done' => 0,
                'skipped' => [],
                'action' => $actionId,
                'error' => t('core.bulk.err.max_selection', ['n' => $maxSelection]),
            ];
        }

        $params = [];
        foreach ($def['fields'] ?? [] as $field) {
            $name = (string)($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $params[$name] = $post[$name] ?? null;
        }

        $skipped = [];
        $allowedIds = [];
        foreach ($ids as $id) {
            $reason = self::checkItem($listId, $actionId, $id, $params);
            if ($reason !== '') {
                $skipped[] = ['id' => $id, 'reason' => $reason];
                continue;
            }
            $allowedIds[] = $id;
        }

        $done = 0;
        $redirect = '';
        if ($allowedIds !== []) {
            /** @var callable $run */
            $run = $def['run'];
            $result = $run($allowedIds, $params);
            if (!is_array($result)) {
                $result = ['done' => count($allowedIds), 'skipped' => []];
            }
            $done = (int)($result['done'] ?? 0);
            $redirect = trim((string)($result['redirect'] ?? ''));
            foreach ($result['skipped'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $skipped[] = [
                    'id' => (int)($row['id'] ?? 0),
                    'reason' => (string)($row['reason'] ?? t('core.bulk.blocked')),
                ];
            }
        }

        $out = [
            'done' => $done,
            'skipped' => $skipped,
            'action' => $actionId,
        ];
        if ($redirect !== '') {
            $out['redirect'] = $redirect;
        }
        return $out;
    }

    /**
     * PRG helper: store flash and redirect back to $returnPath (must be same-origin path).
     *
     * @param array{done?:int,skipped?:list<array{id:int,reason:string}>,action?:string,error?:string} $result
     */
    public static function redirectWithResult(string $returnPath, array $result): void
    {
        $_SESSION[self::FLASH_KEY] = $result;
        redirect(self::sanitizeReturn($returnPath));
    }

    /**
     * @return array{done:int,skipped:list<array{id:int,reason:string}>,action:string,error?:string}|null
     */
    public static function consumeFlash(): ?array
    {
        if (empty($_SESSION[self::FLASH_KEY]) || !is_array($_SESSION[self::FLASH_KEY])) {
            return null;
        }
        $flash = $_SESSION[self::FLASH_KEY];
        unset($_SESSION[self::FLASH_KEY]);
        return $flash;
    }

    /**
     * @param array{done?:int,skipped?:list<array{id:int,reason:string}>,action?:string,error?:string} $flash
     */
    public static function flashHtml(array $flash): string
    {
        if (!empty($flash['error'])) {
            return '<p class="flash flash-warn">' . e((string)$flash['error']) . '</p>';
        }
        $done = (int)($flash['done'] ?? 0);
        $skipped = $flash['skipped'] ?? [];
        if (!is_array($skipped)) {
            $skipped = [];
        }
        $skipN = count($skipped);
        $parts = [];
        if ($done > 0) {
            $parts[] = t('core.bulk.flash.done', ['n' => $done]);
        }
        if ($skipN > 0) {
            $parts[] = t('core.bulk.flash.skipped', ['n' => $skipN]);
        }
        if ($parts === []) {
            return '';
        }
        $cls = $skipN > 0 && $done === 0 ? 'flash-warn' : ($skipN > 0 ? 'flash-warn' : 'flash-ok');
        $html = '<div class="flash ' . $cls . '"><p>' . e(implode(' ', $parts)) . '</p>';
        if ($skipped !== []) {
            $html .= '<ul class="bulk-skip-list">';
            foreach (array_slice($skipped, 0, 20) as $row) {
                $html .= '<li>#' . (int)$row['id'] . ': ' . e((string)$row['reason']) . '</li>';
            }
            if (count($skipped) > 20) {
                $html .= '<li class="muted">…</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div>';
        return $html;
    }

    public static function beginForm(string $listId, string $actionPath): string
    {
        if (!self::hasVisible($listId)) {
            return '';
        }
        $actionPath = self::sanitizeReturn($actionPath);
        // CSRF first so it survives even if max_input_vars truncates later fields.
        return '<form method="post" action="' . e($actionPath) . '" class="bulk-form" data-bulk-form>'
            . Csrf::field()
            . '<input type="hidden" name="bulk_return" value="' . e($actionPath) . '">'
            . '<input type="hidden" name="ids_json" value="" data-bulk-ids-json>';
    }

    public static function endForm(string $listId): string
    {
        if (!self::hasVisible($listId)) {
            return '';
        }
        return '</form>';
    }

    /**
     * @param string $selectedAction Optional action id to preselect (ignored if unknown).
     * Extra trailing args are ignored (legacy call sites).
     */
    public static function bar(string $listId, string $selectedAction = '', ...$unused): string
    {
        unset($unused);
        $actions = self::visibleActions($listId);
        if ($actions === []) {
            return '';
        }
        $selectedAction = trim($selectedAction);
        if ($selectedAction !== '' && !isset($actions[$selectedAction])) {
            $selectedAction = '';
        }
        $html = '<div class="bulk-bar" data-bulk-bar>';
        $html .= '<select name="bulk_action" data-bulk-action aria-label="' . te('core.bulk.with_selected') . '">';
        $html .= '<option value="0"' . ($selectedAction === '' ? ' selected' : '') . '>'
            . te('core.bulk.with_selected') . '</option>';

        $ungrouped = [];
        $grouped = [];
        foreach ($actions as $id => $def) {
            $groupKey = trim((string)($def['group'] ?? ''));
            if ($groupKey === '') {
                $ungrouped[$id] = $def;
                continue;
            }
            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [];
            }
            $grouped[$groupKey][$id] = $def;
        }

        $renderOption = static function (string $id, array $def) use ($selectedAction): string {
            $confirm = trim((string)($def['confirm'] ?? ''));
            $attrs = ' value="' . e($id) . '"';
            if ($selectedAction !== '' && $id === $selectedAction) {
                $attrs .= ' selected';
            }
            if ($confirm !== '') {
                $attrs .= ' data-confirm="' . te($confirm) . '"';
            }
            $maxSelection = (int)($def['max_selection'] ?? 0);
            if ($maxSelection > 0) {
                $attrs .= ' data-bulk-max-selection="' . $maxSelection . '"';
            }
            return '<option' . $attrs . '>' . te((string)$def['label']) . '</option>';
        };

        foreach ($ungrouped as $id => $def) {
            $html .= $renderOption($id, $def);
        }
        foreach ($grouped as $groupKey => $items) {
            $html .= '<optgroup label="' . te($groupKey) . '">';
            foreach ($items as $id => $def) {
                $html .= $renderOption($id, $def);
            }
            $html .= '</optgroup>';
        }

        $html .= '</select>';

        foreach ($actions as $id => $def) {
            foreach ($def['fields'] ?? [] as $field) {
                $name = (string)($field['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $type = (string)($field['type'] ?? 'select');
                $label = (string)($field['label'] ?? $name);
                $html .= '<span class="bulk-field" data-bulk-field-for="' . e($id) . '" hidden>';
                $html .= '<label class="bulk-field-label">' . te($label);
                if ($type === 'select') {
                    $opts = $field['options'] ?? [];
                    if (is_callable($opts)) {
                        $opts = $opts();
                    }
                    if (!is_array($opts)) {
                        $opts = [];
                    }
                    $html .= ' <select name="' . e($name) . '">';
                    $html .= '<option value="0">—</option>';
                    foreach ($opts as $oid => $olabel) {
                        $html .= '<option value="' . e((string)$oid) . '">' . e((string)$olabel) . '</option>';
                    }
                    $html .= '</select>';
                } else {
                    $html .= ' <input type="' . e($type) . '" name="' . e($name) . '">';
                }
                $html .= '</label></span>';
            }
        }

        $html .= '<button type="submit" class="btn-secondary" data-bulk-submit disabled>'
            . te('core.bulk.apply') . '</button>';
        $html .= '</div>';
        return $html;
    }

    public static function th(string $listId): string
    {
        if (!self::hasVisible($listId)) {
            return '';
        }
        return '<th class="bulk-check-col">'
            . '<label class="bulk-check-hit">'
            . '<input type="checkbox" data-bulk-select-all title="' . te('core.bulk.select_all') . '" '
            . 'aria-label="' . te('core.bulk.select_all') . '">'
            . '</label>'
            . '</th>';
    }

    public static function td(string $listId, int $id, bool $checked = false): string
    {
        if (!self::hasVisible($listId) || $id < 1) {
            return '';
        }
        return '<td class="bulk-check-col">'
            . '<label class="bulk-check-hit">'
            . '<input type="checkbox" name="ids[]" value="' . $id . '" data-bulk-id'
            . ($checked ? ' checked' : '')
            . '>'
            . '</label>'
            . '</td>';
    }

    /** Extra column count for colspan when bulk UI is visible. */
    public static function colSpanExtra(string $listId): int
    {
        return self::hasVisible($listId) ? 1 : 0;
    }

    public static function sanitizeReturn(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/') {
            return '/';
        }
        if (strpos($path, '://') !== false || strpos($path, '//') === 0) {
            return '/';
        }
        return $path;
    }

    /**
     * Typical list-page POST + flash redirect. Returns true if handled.
     *
     * @param array<string,mixed> $filters Current list filters for return URL
     */
    public static function tryHandle(string $listId, string $listPath, array $filters = []): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return false;
        }
        if (!isset($_POST['bulk_action'])) {
            return false;
        }
        $result = self::handlePost($listId, $_POST);
        if ($result === null) {
            return false;
        }
        $jump = trim((string)($result['redirect'] ?? ''));
        if ($jump !== '') {
            redirect(self::sanitizeReturn($jump));
            return true;
        }
        $return = trim((string)($_POST['bulk_return'] ?? ''));
        if ($return === '') {
            $return = ListQuery::url($listPath, $filters, []);
        }
        self::redirectWithResult($return, $result);
        return true;
    }
}
