<?php
declare(strict_types=1);

/**
 * Enrich assistant Confirm drafts for clients.* writes.
 */
final class ClientsAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId === '' || strpos($opId, 'clients.') !== 0) {
            return $payload;
        }
        if (strpos($opId, 'clients.statuses.') === 0 || strpos($opId, 'clients.tags.') === 0) {
            return self::merge($payload, self::linesForDict($opId, is_array($payload['args'] ?? null) ? $payload['args'] : []));
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        return self::merge($payload, self::linesForClient($opId, $args));
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $lines
     * @return array<string,mixed>
     */
    private static function merge(array $payload, array $lines): array
    {
        if ($lines === []) {
            return $payload;
        }
        $existing = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $existing[] = $line;
            }
        }
        $payload['lines'] = $existing;
        $payload['handled'] = true;
        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesForClient(string $opId, array $args): array
    {
        $id = (int)($args['id'] ?? 0);
        $current = null;
        if ($id > 0) {
            try {
                $current = ClientsRepository::find($id);
            } catch (Throwable $e) {
                $current = null;
            }
        }

        $lines = [];
        if (is_array($current) && $current !== []) {
            $now = self::formatClient($current);
            if ($now !== '') {
                $lines[] = t('clients.assistant.preview.now', ['v' => $now]);
            }
        }

        if ($opId === 'clients.delete' || strtolower((string)($args['action'] ?? '')) === 'delete') {
            if ($lines === []) {
                $lines[] = t('clients.assistant.preview.delete');
            }
            return $lines;
        }

        $next = is_array($current) ? $current : [];
        foreach (['name', 'tel', 'mail', 'phone'] as $k) {
            if (array_key_exists($k, $args) && $args[$k] !== '' && $args[$k] !== null) {
                if ($k === 'phone') {
                    $next['tel'] = $args[$k];
                } else {
                    $next[$k] = $args[$k];
                }
            }
        }
        $will = self::formatClient($next);
        if ($will === '') {
            return $lines;
        }
        if ($opId === 'clients.create' || $current === null) {
            $lines[] = t('clients.assistant.preview.create', ['v' => $will]);
        } else {
            $nowLine = is_array($current) ? self::formatClient($current) : '';
            if ($will !== $nowLine) {
                $lines[] = t('clients.assistant.preview.will', ['v' => $will]);
            }
        }
        return $lines;
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesForDict(string $opId, array $args): array
    {
        $name = trim((string)($args['name'] ?? ''));
        if ($name === '') {
            $id = (int)($args['id'] ?? 0);
            return $id > 0 ? ['#' . $id] : [];
        }
        $kind = strpos($opId, 'tags') !== false
            ? t('clients.assistant.preview.tag', ['v' => $name])
            : t('clients.assistant.preview.status', ['v' => $name]);
        return [$kind];
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function formatClient(array $row): string
    {
        $name = trim((string)($row['name'] ?? ''));
        $tel = trim((string)($row['tel'] ?? $row['phone'] ?? ''));
        $mail = trim((string)($row['mail'] ?? ''));
        $bits = array_filter([$name, $tel, $mail], static function (string $s): bool {
            return $s !== '';
        });
        return implode(' · ', $bits);
    }
}
