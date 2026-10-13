<?php
declare(strict_types=1);

/** Shared deny messaging for web + bot Max channels. */
final class AssistantAccess
{
    public static function deniedMessage(string $perm = 'assistant.use'): string
    {
        $probe = Auth::accessProbe($perm);
        $fail = Auth::lastCheckFail();
        if ($fail !== '' && ($probe['why'] ?? '') === 'no_session') {
            $probe['why'] = 'check_' . $fail;
        }
        error_log('[assistant] denied ' . json_encode($probe, JSON_UNESCAPED_UNICODE));
        $whyKey = 'assistant.bot.denied_why.' . (string)($probe['why'] ?? 'no_perm');
        $why = t($whyKey);
        if ($why === $whyKey) {
            $why = (string)($probe['why'] ?? 'no_perm');
        }
        return t('assistant.bot.denied_detail', [
            'id' => (string)(int)($probe['user_id'] ?? 0),
            'login' => (string)($probe['login'] ?? ''),
            'why' => $why,
        ]);
    }

    /** @return never */
    public static function denyJson(int $http = 401): void
    {
        AgentHttp::error(self::deniedMessage(), $http);
    }
}
