<?php
declare(strict_types=1);

/**
 * Definition version schema: sections → fields with stable ids.
 * Parses/validates SCHEMA_JSON and normalizes payload values from forms / autofill.
 */
final class DocsSchema
{
    public const TYPES = ['text', 'textarea', 'number', 'date', 'checkbox', 'select', 'multiselect', 'staff', 'segments'];

    /**
     * @param mixed $raw JSON string or decoded array
     * @return array{sections:list<array{id:string,title:string,fields:list<array<string,mixed>>}>}
     */
    public static function parse($raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $sections = [];
        foreach ((array)($raw['sections'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $fields = [];
            foreach ((array)($s['fields'] ?? []) as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $id = (string)($f['id'] ?? '');
                $type = (string)($f['type'] ?? 'text');
                if (!self::validFieldId($id) || !in_array($type, self::TYPES, true)) {
                    continue;
                }
                $field = [
                    'id' => $id,
                    'type' => $type,
                    'label' => (string)($f['label'] ?? $id),
                    'required' => !empty($f['required']),
                    'index' => !empty($f['index']),
                ];
                if ($type === 'select' || $type === 'multiselect') {
                    $field['options'] = self::parseOptions($f['options'] ?? []);
                }
                if (isset($f['hint']) && is_string($f['hint']) && $f['hint'] !== '') {
                    $field['hint'] = $f['hint'];
                }
                $fields[] = $field;
            }
            $sections[] = [
                'id' => (string)($s['id'] ?? ('s' . count($sections))),
                'title' => (string)($s['title'] ?? ''),
                'fields' => $fields,
            ];
        }
        return ['sections' => $sections];
    }

    /**
     * Strict validation for the definition editor.
     *
     * @return list<string> human errors (empty = ok)
     */
    public static function validate(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [t('docs.def.err.schema_json', ['msg' => json_last_error_msg()])];
        }
        $errors = [];
        $seen = [];
        foreach ((array)($decoded['sections'] ?? []) as $si => $s) {
            if (!is_array($s)) {
                $errors[] = t('docs.def.err.section', ['n' => (string)$si]);
                continue;
            }
            foreach ((array)($s['fields'] ?? []) as $f) {
                $id = is_array($f) ? (string)($f['id'] ?? '') : '';
                $type = is_array($f) ? (string)($f['type'] ?? 'text') : '';
                if (!self::validFieldId($id)) {
                    $errors[] = t('docs.def.err.field_id', ['id' => $id]);
                    continue;
                }
                if (isset($seen[$id])) {
                    $errors[] = t('docs.def.err.field_dup', ['id' => $id]);
                }
                $seen[$id] = true;
                if (!in_array($type, self::TYPES, true)) {
                    $errors[] = t('docs.def.err.field_type', ['id' => $id, 'type' => $type]);
                }
            }
        }
        return $errors;
    }

    public static function validFieldId(string $id): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id) === 1;
    }

    /**
     * @param array{sections:list<array<string,mixed>>} $schema
     * @return array<string,array<string,mixed>> field id => field
     */
    public static function fieldMap(array $schema): array
    {
        $out = [];
        foreach ($schema['sections'] as $s) {
            foreach ($s['fields'] as $f) {
                $out[(string)$f['id']] = $f;
            }
        }
        return $out;
    }

    /**
     * Coerce one raw value to the stored shape for its field type.
     *
     * @param array<string,mixed> $field
     * @param mixed $raw
     * @return mixed
     */
    public static function normalizeValue(array $field, $raw)
    {
        $type = (string)$field['type'];
        switch ($type) {
            case 'number':
                if (is_string($raw)) {
                    $raw = str_replace([' ', ','], ['', '.'], trim($raw));
                }
                if ($raw === '' || $raw === null || !is_numeric($raw)) {
                    return null;
                }
                return $raw + 0;
            case 'checkbox':
                return !empty($raw) ? 1 : 0;
            case 'staff':
                $id = (int)$raw;
                return $id > 0 ? $id : null;
            case 'date':
                $s = trim((string)$raw);
                return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : '';
            case 'select':
                $s = trim((string)$raw);
                return self::optionAllowed($field, $s) ? $s : '';
            case 'multiselect':
                $list = is_array($raw) ? $raw : ($raw === '' || $raw === null ? [] : [$raw]);
                $out = [];
                foreach ($list as $v) {
                    $v = trim((string)$v);
                    if ($v !== '' && self::optionAllowed($field, $v) && !in_array($v, $out, true)) {
                        $out[] = $v;
                    }
                }
                return $out;
            case 'segments':
                return self::normalizeSegments($raw);
            case 'textarea':
                return mb_substr(trim((string)(is_array($raw) ? '' : $raw)), 0, 20000);
            default:
                return mb_substr(trim((string)(is_array($raw) ? '' : $raw)), 0, 1000);
        }
    }

    /**
     * @param mixed $raw list of {from,to,note} or form rows seg_from[]/seg_to[]/seg_note[]
     * @return list<array{from:string,to:string,note:string}>
     */
    public static function normalizeSegments($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        if (isset($raw['from']) && is_array($raw['from'])) {
            foreach ($raw['from'] as $i => $from) {
                $rows[] = [
                    'from' => $from,
                    'to' => $raw['to'][$i] ?? '',
                    'note' => $raw['note'][$i] ?? '',
                ];
            }
        } else {
            $rows = $raw;
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $from = trim((string)($r['from'] ?? ''));
            $to = trim((string)($r['to'] ?? ''));
            if (!preg_match('/^\d{1,2}:\d{2}$/', $from) || !preg_match('/^\d{1,2}:\d{2}$/', $to)) {
                continue;
            }
            $out[] = [
                'from' => $from,
                'to' => $to,
                'note' => mb_substr(trim((string)($r['note'] ?? '')), 0, 255),
            ];
        }
        return $out;
    }

    /**
     * Key-order independent JSON for equality checks (stored JSON columns reorder object keys).
     *
     * @param mixed $value
     */
    public static function canon($value): string
    {
        $sort = static function ($v) use (&$sort) {
            if (!is_array($v)) {
                return $v;
            }
            $isList = $v === [] || array_keys($v) === range(0, count($v) - 1);
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = $sort($item);
            }
            if (!$isList) {
                ksort($out);
            }
            return $out;
        };
        return (string)json_encode($sort($value), JSON_UNESCAPED_UNICODE);
    }

    /** @param mixed $value */
    public static function isEmpty(array $field, $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        if ((string)$field['type'] === 'checkbox') {
            return (int)$value !== 1;
        }
        return false;
    }

    /**
     * @param array{sections:list<array<string,mixed>>} $schema
     * @param array<string,mixed> $payload
     * @return list<string> required field ids still empty
     */
    public static function missingRequired(array $schema, array $payload): array
    {
        $missing = [];
        foreach (self::fieldMap($schema) as $id => $f) {
            if (!empty($f['required']) && self::isEmpty($f, $payload[$id] ?? null)) {
                $missing[] = $id;
            }
        }
        return $missing;
    }

    /**
     * Plain-text representation (print / index / list chips).
     *
     * @param array<string,mixed> $field
     * @param mixed $value
     * @param array<int,string> $staffNames
     */
    public static function displayValue(array $field, $value, array $staffNames = []): string
    {
        $type = (string)$field['type'];
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        switch ($type) {
            case 'checkbox':
                return (int)$value === 1 ? t('docs.value.yes') : t('docs.value.no');
            case 'staff':
                $id = (int)$value;
                if (isset($staffNames[$id])) {
                    return $staffNames[$id];
                }
                $names = class_exists('UsersRepository', false) ? UsersRepository::staffNamesIncluding([$id]) : [];
                return (string)($names[$id] ?? ('#' . $id));
            case 'date':
                $ts = strtotime((string)$value . ' 00:00:00');
                return $ts ? I18n::formatDate((int)$ts, 'date') : (string)$value;
            case 'select':
                return self::optionLabel($field, (string)$value);
            case 'multiselect':
                $labels = [];
                foreach ((array)$value as $v) {
                    $labels[] = self::optionLabel($field, (string)$v);
                }
                return implode(', ', $labels);
            case 'segments':
                $bits = [];
                foreach ((array)$value as $seg) {
                    if (!is_array($seg)) {
                        continue;
                    }
                    $bit = (string)($seg['from'] ?? '') . '–' . (string)($seg['to'] ?? '');
                    if (($seg['note'] ?? '') !== '') {
                        $bit .= ' (' . (string)$seg['note'] . ')';
                    }
                    $bits[] = $bit;
                }
                return implode('; ', $bits);
            default:
                return is_scalar($value) ? (string)$value : '';
        }
    }

    /**
     * @param mixed $raw
     * @return list<array{value:string,label:string}>
     */
    private static function parseOptions($raw): array
    {
        $out = [];
        foreach ((array)$raw as $o) {
            if (is_array($o)) {
                $v = trim((string)($o['value'] ?? ''));
                if ($v === '') {
                    continue;
                }
                $out[] = ['value' => $v, 'label' => (string)($o['label'] ?? $v)];
            } elseif (is_scalar($o) && trim((string)$o) !== '') {
                $out[] = ['value' => trim((string)$o), 'label' => trim((string)$o)];
            }
        }
        return $out;
    }

    private static function optionAllowed(array $field, string $value): bool
    {
        if ($value === '') {
            return true;
        }
        foreach ((array)($field['options'] ?? []) as $o) {
            if ((string)$o['value'] === $value) {
                return true;
            }
        }
        return false;
    }

    private static function optionLabel(array $field, string $value): string
    {
        foreach ((array)($field['options'] ?? []) as $o) {
            if ((string)$o['value'] === $value) {
                return (string)$o['label'];
            }
        }
        return $value;
    }
}
