<?php
declare(strict_types=1);

/**
 * Single-entity delete on edit screens (danger-zone).
 * Reuses BulkActions registration (permission, run) and core.bulk.check_item blockers.
 */
final class EntityDelete
{
    /**
     * @param array{
     *   confirm?:string,
     *   action?:string,
     *   bulk_action?:string,
     *   label?:string,
     *   class?:string
     * } $opts
     */
    public static function form(string $listId, int $id, array $opts = []): string
    {
        if ($id < 1) {
            return '';
        }
        $bulkAction = trim((string)($opts['bulk_action'] ?? 'delete'));
        if ($bulkAction === '') {
            $bulkAction = 'delete';
        }
        $def = BulkActions::actionDef($listId, $bulkAction);
        if ($def === null || !BulkActions::isAllowed($def)) {
            return '';
        }

        $reason = BulkActions::checkItem($listId, $bulkAction, $id);
        $confirmKey = trim((string)($opts['confirm'] ?? ''));
        if ($confirmKey === '') {
            $confirmKey = 'core.entity.confirm_delete';
        }
        $label = trim((string)($opts['label'] ?? ''));
        if ($label === '') {
            $label = t('core.common.delete');
        }
        $action = trim((string)($opts['action'] ?? 'delete'));
        if ($action === '') {
            $action = 'delete';
        }
        $class = trim((string)($opts['class'] ?? 'danger-zone'));
        if ($class === '') {
            $class = 'danger-zone';
        }

        $html = '';
        $postErr = self::consumeError();
        if ($postErr !== '') {
            $html .= '<div class="flash flash-warn">' . e($postErr) . '</div>';
        }
        $html .= '<div class="' . e($class) . '">';
        if ($reason !== '') {
            $html .= '<p class="muted entity-delete-blocked">' . e($reason) . '</p>';
            $html .= '<button class="btn-danger" type="button" disabled>' . e($label) . '</button>';
        } else {
            $html .= '<form method="post" class="entity-delete-form" onsubmit="return confirm(' . tj($confirmKey) . ');">';
            $html .= NavContext::hiddenField();
            $html .= '<input type="hidden" name="action" value="' . e($action) . '">';
            $html .= '<button class="btn-danger" type="submit">' . e($label) . '</button>';
            $html .= '</form>';
        }
        $html .= '</div>';
        return $html;
    }

    public static function consumeError(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        $msg = trim((string)($_SESSION['entity_delete_error'] ?? ''));
        unset($_SESSION['entity_delete_error']);
        return $msg;
    }

    /**
     * Handle POST action=delete on an edit page.
     * Returns true if the request was consumed (redirect / AgentHttp emit/error).
     *
     * @param array{confirm?:string,action?:string,bulk_action?:string,stay_path?:string} $opts
     */
    public static function tryHandle(string $listId, int $id, string $redirectListPath, array $opts = []): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return false;
        }
        $postAction = trim((string)($_POST['action'] ?? ''));
        $expectAction = trim((string)($opts['action'] ?? 'delete'));
        if ($expectAction === '') {
            $expectAction = 'delete';
        }
        if ($postAction !== $expectAction) {
            return false;
        }

        $bulkAction = trim((string)($opts['bulk_action'] ?? 'delete'));
        if ($bulkAction === '') {
            $bulkAction = 'delete';
        }

        $wantsJson = AgentHttp::wantsJson();
        if ($wantsJson && !AgentHttp::allowsDelete()) {
            AgentHttp::error(t('core.entity.agent_delete_denied'), 403);
            return true;
        }

        if ($id < 1) {
            self::fail($wantsJson, t('core.entity.err.not_found'), (string)($opts['stay_path'] ?? ''), $redirectListPath);
            return true;
        }

        $def = BulkActions::actionDef($listId, $bulkAction);
        if ($def === null || !BulkActions::isAllowed($def)) {
            self::fail($wantsJson, t('core.bulk.err.forbidden'), (string)($opts['stay_path'] ?? ''), $redirectListPath);
            return true;
        }

        $reason = BulkActions::checkItem($listId, $bulkAction, $id);
        if ($reason !== '') {
            self::fail($wantsJson, $reason, (string)($opts['stay_path'] ?? ''), $redirectListPath);
            return true;
        }

        /** @var callable $run */
        $run = $def['run'];
        $result = $run([$id], []);
        if (!is_array($result)) {
            $result = ['done' => 1, 'skipped' => []];
        }
        $done = (int)($result['done'] ?? 0);
        $skipped = $result['skipped'] ?? [];
        if (!is_array($skipped)) {
            $skipped = [];
        }
        if ($done < 1) {
            $skipReason = '';
            if ($skipped !== [] && is_array($skipped[0] ?? null)) {
                $skipReason = trim((string)($skipped[0]['reason'] ?? ''));
            }
            self::fail(
                $wantsJson,
                $skipReason !== '' ? $skipReason : t('core.entity.err.delete_failed'),
                (string)($opts['stay_path'] ?? ''),
                $redirectListPath
            );
            return true;
        }

        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'action' => 'delete',
                'id' => $id,
                'list_id' => $listId,
            ]);
            return true;
        }

        $customRedirect = trim((string)($result['redirect'] ?? ''));
        if ($customRedirect !== '') {
            redirect($customRedirect);
            return true;
        }
        redirect($redirectListPath);
        return true;
    }

    private static function fail(bool $wantsJson, string $message, string $stayPath, string $listPath): void
    {
        if ($wantsJson) {
            AgentHttp::error($message, 409);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['entity_delete_error'] = $message;
        }
        flash_set('warn', $message);
        $target = $stayPath !== '' ? $stayPath : $listPath;
        redirect($target);
    }
}
