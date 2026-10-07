<?php
declare(strict_types=1);

/** Enqueue staff bot notify for procedure create/update when bots module is on. */
final class ProcedureBotNotify
{
    /**
     * @param array<string,mixed> $data procedure fields (id_operator, dtstart, …)
     */
    public static function onSaved(int $procedureId, array $data, string $kind = 'new'): void
    {
        if ($procedureId < 1 || !ModuleManager::isEnabled('bots') || !class_exists('BotSend', false)) {
            return;
        }
        $operatorId = (int)($data['id_operator'] ?? 0);
        if ($operatorId < 1) {
            return;
        }
        $dtStart = (int)($data['dtstart'] ?? 0);
        if ($dtStart > 0) {
            $todayStart = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
            $todayEnd = mktime(23, 59, 59, (int)date('n'), (int)date('j'), (int)date('Y'));
            if ($dtStart < $todayStart || $dtStart > $todayEnd) {
                return;
            }
        }
        $when = $dtStart > 0 ? date('H:i', $dtStart) : '';
        $key = 'procedures.bot.notify_new';
        if ($kind === 'update') {
            $key = 'procedures.bot.notify_update';
        } elseif ($kind === 'cancel') {
            $key = 'procedures.bot.notify_cancel';
        }
        $text = t($key, [
            'id' => $procedureId,
            'when' => $when,
        ]);
        $deadline = $dtStart > 0 ? ($dtStart - 120) : 0;
        try {
            BotSend::enqueue([
                'audience' => 'staff',
                'user_id' => $operatorId,
                'text' => $text,
                'name' => 'procedure_' . $kind . '_' . $procedureId,
                'dedupe_key' => 'procedure_' . $kind . '_' . $procedureId,
                'type' => 12,
                'id_event' => $procedureId,
                'deadline' => $deadline,
            ]);
        } catch (Throwable $e) {
            error_log('[procedures.bot] ' . $e->getMessage());
        }
    }
}
