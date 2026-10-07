<?php
declare(strict_types=1);

/**
 * Encode/decode DATA_OPTS / DATA_TEXT. Reads legacy PHP serialize and JSON; writes JSON.
 */
final class PollAnswersCodec
{
    /**
     * @return array<int|string,mixed>
     */
    public static function decode(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if ($raw[0] === '{' || $raw[0] === '[') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
        }
        $un = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($un)) {
            return $un;
        }
        return [];
    }

    /**
     * @param array<int|string,mixed> $data
     */
    public static function encode(array $data): string
    {
        if ($data === []) {
            return '{}';
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }
}
