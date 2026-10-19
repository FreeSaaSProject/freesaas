<?php
declare(strict_types=1);

/**
 * Browser-only shadow UI: navigate + cosmetic form fill for pending writes.
 * Does not execute writes — Confirm still goes through AssistantTools::invoke.
 */
final class AssistantUiActions
{
    /**
     * Build ui_actions from the first pending write (MVP: one screen).
     *
     * @param list<array<string,mixed>> $pendingActions
     * @param list<array<string,mixed>> $ops
     * @return list<array<string,mixed>>
     */
    public static function fromPending(array $pendingActions, array $ops): array
    {
        $first = null;
        foreach ($pendingActions as $p) {
            if (is_array($p) && trim((string)($p['token'] ?? '')) !== '' && trim((string)($p['op_id'] ?? '')) !== '') {
                $first = $p;
                break;
            }
        }
        if ($first === null) {
            return [];
        }

        $opId = trim((string)$first['op_id']);
        $op = self::findOp($opId, $ops);
        if ($op === null) {
            return [];
        }

        $args = is_array($first['args'] ?? null) ? $first['args'] : [];
        $pathKeys = self::pathParamNames((string)($op['path'] ?? ''));
        $navPath = self::expandPath((string)($op['path'] ?? ''), $args);
        $fields = self::fillFields($args, $pathKeys);

        $actions = [];
        if ($navPath !== null && self::isSafeAdminPath($navPath)) {
            $actions[] = ['type' => 'navigate', 'path' => $navPath];
        }
        if ($fields !== []) {
            $actions[] = ['type' => 'fill', 'fields' => $fields];
            $actions[] = ['type' => 'highlight', 'fields' => array_keys($fields)];
        }
        return $actions;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function reload(): array
    {
        return [['type' => 'reload']];
    }

    /**
     * Open pending writes for FAB restore after navigate (Confirm buttons).
     *
     * @return list<array<string,mixed>>
     */
    public static function pendingPayloadsForUser(int $userId): array
    {
        if ($userId < 1 || !class_exists('PendingActionsRepository', false)) {
            return [];
        }
        $out = [];
        foreach (PendingActionsRepository::listPendingForUser($userId, 5) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $token = trim((string)($row['TOKEN'] ?? ''));
            $opId = trim((string)($row['OP_ID'] ?? ''));
            if ($token === '' || $opId === '') {
                continue;
            }
            $args = PendingActionsRepository::decodeArgs($row);
            $preview = class_exists('AssistantHarness', false)
                ? AssistantHarness::writePreview($opId, $args, '')
                : [
                    'summary' => (string)($row['SUMMARY'] ?? $opId),
                    'headline' => (string)($row['SUMMARY'] ?? $opId),
                    'lines' => [],
                    'args' => $args,
                ];
            $item = [
                'token' => $token,
                'op_id' => $opId,
                'summary' => (string)($preview['summary'] ?? ''),
                'headline' => (string)($preview['headline'] ?? ''),
                'lines' => is_array($preview['lines'] ?? null) ? array_values($preview['lines']) : [],
                'args' => is_array($preview['args'] ?? null) ? $preview['args'] : $args,
                'expires_at' => (int)($row['EXPIRES_AT'] ?? 0),
            ];
            $reqPerm = trim((string)($row['REQUIRED_PERMISSION'] ?? ''));
            if ($reqPerm !== '') {
                $item['required_permission'] = $reqPerm;
            }
            $confirmMode = strtolower(trim((string)($row['CONFIRM_MODE'] ?? 'self')));
            if ($confirmMode !== '') {
                $item['confirm_mode'] = $confirmMode;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $ops
     * @return array<string,mixed>|null
     */
    private static function findOp(string $opId, array $ops): ?array
    {
        foreach ($ops as $op) {
            if (is_array($op) && trim((string)($op['id'] ?? '')) === $opId) {
                return $op;
            }
        }
        if (class_exists('AssistantTools', false)) {
            foreach (AssistantTools::availableOps() as $op) {
                if (is_array($op) && trim((string)($op['id'] ?? '')) === $opId) {
                    return $op;
                }
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private static function pathParamNames(string $pattern): array
    {
        if ($pattern === '' || !preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $pattern, $m)) {
            return [];
        }
        return array_values($m[1]);
    }

    /**
     * @param array<string,mixed> $args
     */
    private static function expandPath(string $pattern, array $args): ?string
    {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern[0] !== '/') {
            return null;
        }
        if (strpos($pattern, '{') === false) {
            return self::normalizePath($pattern);
        }
        foreach (self::pathParamNames($pattern) as $name) {
            if (!array_key_exists($name, $args) || $args[$name] === '' || $args[$name] === null) {
                return null;
            }
        }
        $path = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use ($args): string {
                $v = $args[$m[1]];
                if (is_bool($v)) {
                    return $v ? '1' : '0';
                }
                return rawurlencode((string)$v);
            },
            $pattern
        );
        if (!is_string($path) || $path === '') {
            return null;
        }
        return self::normalizePath($path);
    }

    private static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return '/';
        }
        $trimmed = rtrim($path, '/');
        return $trimmed !== '' ? $trimmed : '/';
    }

    private static function isSafeAdminPath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        if (strpos($path, '//') !== false || strpos($path, '..') !== false) {
            return false;
        }
        if (!preg_match('#^/[a-zA-Z0-9/_\\-.%]*$#', $path)) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $args
     * @param list<string> $pathKeys
     * @return array<string,mixed>
     */
    private static function fillFields(array $args, array $pathKeys): array
    {
        $skip = [];
        foreach ($pathKeys as $k) {
            $skip[strtolower($k)] = true;
        }
        // Internal / non-form keys
        foreach (['action', 'csrf', '_csrf', 'format', 'token'] as $k) {
            $skip[$k] = true;
        }

        $fields = [];
        foreach ($args as $key => $value) {
            $name = trim((string)$key);
            if ($name === '' || isset($skip[strtolower($name)])) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $fields[$name] = $value ? '1' : '0';
                continue;
            }
            if (is_int($value) || is_float($value)) {
                $fields[$name] = (string)$value;
                continue;
            }
            if (is_string($value)) {
                $fields[$name] = $value;
                continue;
            }
            if (is_array($value)) {
                $ints = [];
                $allScalar = true;
                foreach ($value as $item) {
                    if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                        $ints[] = (string)(int)$item;
                    } elseif (is_string($item) || is_int($item) || is_float($item)) {
                        $allScalar = false;
                        break;
                    } else {
                        $allScalar = false;
                        break;
                    }
                }
                if ($allScalar && $ints !== []) {
                    // Checkbox groups: name="tag_ids[]"
                    $fields[$name . '[]'] = $ints;
                }
            }
        }
        return $fields;
    }
}
