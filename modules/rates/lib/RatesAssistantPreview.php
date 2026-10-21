<?php
declare(strict_types=1);

/**
 * Enrich assistant Confirm drafts for rates.skill.* writes.
 */
final class RatesAssistantPreview
{
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function onWritePreview(array $payload): array
    {
        $opId = (string)($payload['op_id'] ?? '');
        if ($opId !== 'rates.skill.grant' && $opId !== 'rates.skill.revoke') {
            return $payload;
        }
        $args = is_array($payload['args'] ?? null) ? $payload['args'] : [];
        $lines = self::linesFor($opId, $args);
        if ($lines === []) {
            return $payload;
        }
        $existing = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $existing[] = $line;
        }
        $payload['lines'] = $existing;
        $payload['handled'] = true;
        return $payload;
    }

    /**
     * @param array<string,mixed> $args
     * @return list<string>
     */
    private static function linesFor(string $opId, array $args): array
    {
        $operatorId = (int)($args['operator_id'] ?? 0);
        $typeId = (int)($args['type_id'] ?? 0);
        $goodId = (int)($args['good_id'] ?? $args['product_id'] ?? 0);
        $opName = $operatorId > 0 && class_exists('UsersRepository', false)
            ? UsersRepository::nameById($operatorId)
            : ('#' . $operatorId);
        $typeName = '';
        if ($typeId > 0 && class_exists('ProcedureTypesRepository', false)) {
            $typeName = (string)(ProcedureTypesRepository::names()[$typeId] ?? ('#' . $typeId));
        }
        $productName = '';
        if ($goodId > 0 && class_exists('ProductsRepository', false)) {
            $p = ProductsRepository::find($goodId);
            $productName = $p ? trim((string)($p['name'] ?? '')) : ('#' . $goodId);
            if ($typeId < 1 && $p) {
                $typeId = (int)($p['type_id'] ?? 0);
                if ($typeId > 0 && class_exists('ProcedureTypesRepository', false)) {
                    $typeName = (string)(ProcedureTypesRepository::names()[$typeId] ?? ('#' . $typeId));
                }
            }
        }
        $target = $productName !== '' ? $productName : ($typeName !== '' ? $typeName : '—');
        $lines = [
            t('rates.assistant.preview.staff', ['v' => $opName]),
            t('rates.assistant.preview.target', ['v' => $target]),
        ];
        if ($opId === 'rates.skill.grant') {
            $pct = $args['percent'] ?? null;
            if ($pct !== null && $pct !== '') {
                $lines[] = t('rates.assistant.preview.percent', [
                    'v' => (string)$pct,
                ]);
            } else {
                $lines[] = t('rates.assistant.preview.percent_auto');
            }
            $lines[] = t('rates.assistant.preview.grant');
        } else {
            $lines[] = $goodId > 0
                ? t('rates.assistant.preview.revoke_product')
                : t('rates.assistant.preview.revoke_type');
        }
        return $lines;
    }
}
