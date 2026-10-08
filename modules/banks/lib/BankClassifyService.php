<?php
declare(strict_types=1);

/** Resolve ledger move type for a bank transaction. */
final class BankClassifyService
{
    /**
     * @param array{purpose?:string,counterparty_name?:string,counterparty_inn?:string,direction?:int,amount?:float} $tx
     */
    public static function resolve(array $tx): int
    {
        $default = BanksConfig::defaultMoveTypeId();
        if (ModuleManager::isEnabled('llm') && class_exists('LlmClient')) {
            $typed = self::viaLlm($tx);
            if ($typed > 0) {
                return $typed;
            }
        }
        if ($default < 1) {
            // Fall back to first enabled type if any
            $names = AccountMoveTypesRepository::names(true);
            $ids = array_keys($names);
            return $ids !== [] ? (int)$ids[0] : 0;
        }
        return $default;
    }

    /**
     * @param array{purpose?:string,counterparty_name?:string,counterparty_inn?:string,direction?:int,amount?:float} $tx
     */
    private static function viaLlm(array $tx): int
    {
        try {
            $types = AccountMoveTypesRepository::names(true);
            if ($types === []) {
                return 0;
            }
            $catalog = [];
            foreach ($types as $id => $name) {
                $catalog[] = ['id' => (int)$id, 'name' => (string)$name];
            }
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'You classify bank transactions into ledger move types. '
                        . 'Reply with JSON only: {"type_id":N,"confidence":0..1}. '
                        . 'Pick type_id only from the catalog. If unsure, use confidence < 0.55.',
                ],
                [
                    'role' => 'user',
                    'content' => (string)json_encode([
                        'catalog' => $catalog,
                        'transaction' => [
                            'purpose' => (string)($tx['purpose'] ?? ''),
                            'counterparty_name' => (string)($tx['counterparty_name'] ?? ''),
                            'counterparty_inn' => (string)($tx['counterparty_inn'] ?? ''),
                            'direction' => (int)($tx['direction'] ?? 0),
                            'amount' => (float)($tx['amount'] ?? 0),
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ];
            $res = LlmClient::complete($messages, ['temperature' => 0.1, 'max_tokens' => 120, 'json' => true]);
            if (empty($res['ok'])) {
                return 0;
            }
            $parsed = is_array($res['json'] ?? null) ? $res['json'] : [];
            if ($parsed === []) {
                $pj = LlmClient::parseMessageJson($res['text'] ?? '');
                $parsed = !empty($pj['ok']) && is_array($pj['data'] ?? null) ? $pj['data'] : [];
            }
            $typeId = (int)($parsed['type_id'] ?? 0);
            $confidence = (float)($parsed['confidence'] ?? 0);
            if ($typeId < 1 || $confidence < 0.55 || !isset($types[$typeId])) {
                return 0;
            }
            return $typeId;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
