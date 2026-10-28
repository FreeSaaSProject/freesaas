<?php
declare(strict_types=1);

/**
 * Cross-module dry-run before a write is queued as pending.
 * Modules fill blockers[] / suggest[] via EventBus assistant.write.preflight.
 */
final class AssistantWritePreflight
{
    /**
     * @param array<string,mixed> $args
     * @param array<string,bool> $catalogIds
     * @return array{
     *   blockers:list<array{code:string,message:string}>,
     *   suggest:list<array{op_id:string,args:array<string,mixed>,label:string}>,
     *   choices:list<array{op_id:string,args:array<string,mixed>,label:string}>,
     *   noop:bool
     * }
     */
    public static function run(string $opId, array $args, array $catalogIds = []): array
    {
        $payload = [
            'op_id' => $opId,
            'args' => $args,
            'catalog_ids' => $catalogIds,
            'blockers' => [],
            'suggest' => [],
            'choices' => [],
            'noop' => false,
        ];
        if (class_exists('EventBus', false)) {
            $payload = EventBus::emit('assistant.write.preflight', $payload);
            if (!is_array($payload)) {
                $payload = [];
            }
        }

        $blockers = [];
        foreach ((array)($payload['blockers'] ?? []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $msg = trim((string)($b['message'] ?? ''));
            if ($msg === '') {
                continue;
            }
            $blockers[] = [
                'code' => trim((string)($b['code'] ?? 'blocked')),
                'message' => $msg,
            ];
        }

        $suggest = [];
        foreach ((array)($payload['suggest'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $sid = trim((string)($s['op_id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            if ($catalogIds !== [] && empty($catalogIds[$sid])) {
                continue;
            }
            $sArgs = is_array($s['args'] ?? null) ? $s['args'] : [];
            $label = trim((string)($s['label'] ?? $sid));
            $suggest[] = [
                'op_id' => $sid,
                'args' => $sArgs,
                'label' => $label !== '' ? $label : $sid,
            ];
        }

        $choices = [];
        foreach ((array)($payload['choices'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $cid = trim((string)($c['op_id'] ?? ''));
            if ($cid === '') {
                continue;
            }
            if ($catalogIds !== [] && empty($catalogIds[$cid])) {
                continue;
            }
            $cArgs = is_array($c['args'] ?? null) ? $c['args'] : [];
            $label = trim((string)($c['label'] ?? $cid));
            if ($label === '') {
                continue;
            }
            $choices[] = [
                'op_id' => $cid,
                'args' => $cArgs,
                'label' => $label,
            ];
            if (count($choices) >= 5) {
                break;
            }
        }

        return [
            'blockers' => $blockers,
            'suggest' => $suggest,
            'choices' => $choices,
            'noop' => !empty($payload['noop']),
        ];
    }

    /**
     * @param array{blockers?:list<array{code?:string,message?:string}>,suggest?:list<array<string,mixed>>,noop?:bool} $preflight
     * @return list<string>
     */
    public static function previewLines(array $preflight): array
    {
        $lines = [];
        if (!empty($preflight['noop'])) {
            $lines[] = '⚠ ' . t('assistant.preflight.noop');
        }
        foreach ((array)($preflight['blockers'] ?? []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $msg = trim((string)($b['message'] ?? ''));
            if ($msg === '') {
                continue;
            }
            $line = '⚠ ' . $msg;
            if (!in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }
        foreach ((array)($preflight['suggest'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $label = trim((string)($s['label'] ?? $s['op_id'] ?? ''));
            if ($label === '') {
                continue;
            }
            $line = '⚠ ' . t('assistant.preflight.suggest', ['v' => $label]);
            if (!in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }
        $n = 0;
        foreach ((array)($preflight['choices'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $label = trim((string)($c['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $n++;
            $line = t('assistant.preflight.choice', ['n' => (string)$n, 'v' => $label]);
            if (!in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    /**
     * @param array{suggest?:list<array<string,mixed>>} $preflight
     */
    public static function needsFollowup(array $preflight): bool
    {
        return !empty($preflight['suggest']);
    }

    /**
     * @param array{blockers?:list<array<string,mixed>>} $preflight
     */
    public static function hasBlockers(array $preflight): bool
    {
        return !empty($preflight['blockers']);
    }

    /**
     * @param array{suggest?:list<array{op_id?:string}>} $preflight
     * @return list<string>
     */
    public static function suggestOpIds(array $preflight): array
    {
        $ids = [];
        foreach ((array)($preflight['suggest'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = trim((string)($s['op_id'] ?? ''));
            if ($id !== '') {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }
}
