<?php
declare(strict_types=1);

/** One-shot LLM call for pending-review moderation package. */
final class ReviewModerationLlm
{
    public static function isReady(): bool
    {
        if (!class_exists('ModuleManager', false)
            || !ModuleManager::isEnabled('audio_llm')
            || !ModuleManager::isEnabled('llm')
        ) {
            return false;
        }
        if (!class_exists('LlmConfig', false) || !class_exists('LlmClient', false)) {
            return false;
        }
        return LlmConfig::hasReadyProfile();
    }

    /**
     * @return array{ok:bool,text:string,error:string,pending_count:int,context_count:int}
     */
    public static function run(int $adminId, int $year, int $month, int $day): array
    {
        if (!self::isReady()) {
            return [
                'ok' => false,
                'text' => '',
                'error' => t('audio_llm.moderation.err.llm_not_ready'),
                'pending_count' => 0,
                'context_count' => 0,
            ];
        }

        $built = ReviewModerationBuilder::build($adminId, $year, $month, $day);
        if (empty($built['ok'])) {
            return [
                'ok' => false,
                'text' => '',
                'error' => (string)($built['error'] ?? t('audio_llm.moderation.err.none')),
                'pending_count' => (int)($built['pending_count'] ?? 0),
                'context_count' => (int)($built['context_count'] ?? 0),
            ];
        }

        $system = ReviewModerationConfig::systemPrompt();
        $user = ReviewModerationConfig::applyUserTemplate(
            ReviewModerationConfig::userPrompt(),
            [
                'employee_name' => (string)$built['employee_name'],
                'date' => (string)$built['date'],
                'transcripts' => (string)$built['transcripts'],
            ]
        );

        $res = LlmClient::complete([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], [
            'temperature' => 0.2,
            'max_tokens' => 4000,
            'timeout' => 180,
        ]);

        if (!$res['ok']) {
            return [
                'ok' => false,
                'text' => '',
                'error' => $res['error'] !== '' ? $res['error'] : t('audio_llm.err.llm'),
                'pending_count' => (int)$built['pending_count'],
                'context_count' => (int)$built['context_count'],
            ];
        }

        return [
            'ok' => true,
            'text' => trim((string)$res['text']),
            'error' => '',
            'pending_count' => (int)$built['pending_count'],
            'context_count' => (int)$built['context_count'],
        ];
    }
}
