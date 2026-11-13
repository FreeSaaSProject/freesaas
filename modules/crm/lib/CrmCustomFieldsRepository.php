<?php
declare(strict_types=1);

final class CrmCustomFieldsRepository
{
    public const ENTITIES = ['company', 'deal', 'contact'];

    public static function tableReady(): bool
    {
        return Database::tableExists('base_crm_custom_fields')
            && Database::tableExists('base_crm_custom_values');
    }

    /** @return list<array<string,mixed>> */
    public static function listFields(string $entity = '', bool $enabledOnly = false): array
    {
        if (!self::tableReady()) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        if ($entity !== '') {
            $where[] = 'entity = ?';
            $params[] = $entity;
        }
        if ($enabledOnly) {
            $where[] = 'enabled = 1';
        }
        $sql = 'SELECT id, entity, code, name, field_type, enums_json, sort_order, enabled
                FROM base_crm_custom_fields WHERE ' . implode(' AND ', $where)
            . ' ORDER BY entity ASC, sort_order ASC, id ASC';
        $st = Database::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function find(int $id): ?array
    {
        if ($id < 1 || !self::tableReady()) {
            return null;
        }
        $st = Database::pdo()->prepare(
            'SELECT id, entity, code, name, field_type, enums_json, sort_order, enabled
             FROM base_crm_custom_fields WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $entity = (string)($data['entity'] ?? '');
        $code = trim((string)($data['code'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        if (!in_array($entity, self::ENTITIES, true) || $code === '' || $name === '') {
            throw new InvalidArgumentException(t('crm.err.field_required'));
        }
        $code = preg_replace('/[^a-zA-Z0-9_]/', '_', $code) ?? $code;
        $type = (string)($data['field_type'] ?? 'string');
        if (!in_array($type, ['string', 'text', 'number', 'bool', 'enum'], true)) {
            $type = 'string';
        }
        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO base_crm_custom_fields (entity, code, name, field_type, enums_json, sort_order, enabled)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $entity,
            $code,
            $name,
            $type,
            (string)($data['enums_json'] ?? ''),
            (int)($data['sort_order'] ?? 0),
            !empty($data['enabled']) ? 1 : 0,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid field id');
        }
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(t('crm.err.field_required'));
        }
        $type = (string)($data['field_type'] ?? 'string');
        if (!in_array($type, ['string', 'text', 'number', 'bool', 'enum'], true)) {
            $type = 'string';
        }
        Database::pdo()->prepare(
            'UPDATE base_crm_custom_fields SET name = ?, field_type = ?, enums_json = ?, sort_order = ?, enabled = ?
             WHERE id = ?'
        )->execute([
            $name,
            $type,
            (string)($data['enums_json'] ?? ''),
            (int)($data['sort_order'] ?? 0),
            !empty($data['enabled']) ? 1 : 0,
            $id,
        ]);
    }

    public static function delete(int $id): void
    {
        if ($id < 1 || !self::tableReady()) {
            return;
        }
        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM base_crm_custom_values WHERE field_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM base_crm_custom_fields WHERE id = ?')->execute([$id]);
    }

    /**
     * enum options from enums_json: ["a","b"] or [{"value":"a","label":"A"}] / [{"id":..,"name":..}].
     *
     * @param array<string,mixed> $field
     * @return array<string,string> value => label
     */
    public static function enumOptions(array $field): array
    {
        $raw = json_decode((string)($field['enums_json'] ?? ''), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $item) {
            if (is_array($item)) {
                $value = (string)($item['value'] ?? $item['id'] ?? $k);
                $label = (string)($item['label'] ?? $item['name'] ?? $value);
            } else {
                $value = (string)$item;
                $label = (string)$item;
            }
            if ($value !== '') {
                $out[$value] = $label;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $field */
    public static function displayValue(array $field, string $value): string
    {
        $type = (string)($field['field_type'] ?? 'string');
        if ($type === 'bool') {
            return $value === '1' ? t('core.common.yes') : ($value === '0' ? t('core.common.no') : '');
        }
        if ($type === 'enum') {
            return self::enumOptions($field)[$value] ?? $value;
        }
        return $value;
    }

    /** @return array<int,string> field_id => value */
    public static function valuesFor(string $entity, int $entityId): array
    {
        if ($entityId < 1 || !self::tableReady()) {
            return [];
        }
        $st = Database::pdo()->prepare(
            'SELECT field_id, value_text FROM base_crm_custom_values WHERE entity = ? AND entity_id = ?'
        );
        $st->execute([$entity, $entityId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int)$r['field_id']] = (string)$r['value_text'];
        }
        return $out;
    }

    /** @param array<int|string,mixed> $values field_id => value */
    public static function saveValues(string $entity, int $entityId, array $values): void
    {
        if ($entityId < 1 || !self::tableReady()) {
            return;
        }
        $pdo = Database::pdo();
        foreach ($values as $fieldId => $value) {
            $fieldId = (int)$fieldId;
            if ($fieldId < 1) {
                continue;
            }
            $text = is_scalar($value) ? (string)$value : '';
            $pdo->prepare(
                'INSERT INTO base_crm_custom_values (field_id, entity, entity_id, value_text)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE value_text = VALUES(value_text)'
            )->execute([$fieldId, $entity, $entityId, $text]);
        }
    }
}
