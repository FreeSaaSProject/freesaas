<?php
declare(strict_types=1);

/** Shared deny messaging for web + bot assistant channels (auth/session only — assistant is base). */
final class AssistantAccess
{
    public static function deniedMessage(): string
    {
        $uid = Auth::userId();
        $login = Auth::loginName();
        $fail = Auth::lastCheckFail();
        $why = 'no_session';
        if ($uid > 0) {
            $why = $fail !== '' ? 'check_' . $fail : 'ok';
        } elseif ($fail !== '') {
            $why = 'check_' . $fail;
        }
        $probe = [
            'user_id' => $uid,
            'login' => $login,
            'why' => $why,
            'check_fail' => $fail,
        ];
        error_log('[assistant] denied ' . json_encode($probe, JSON_UNESCAPED_UNICODE));
        $whyKey = 'assistant.bot.denied_why.' . $why;
        $whyLabel = t($whyKey);
        if ($whyLabel === $whyKey) {
            $whyLabel = $why;
        }
        $replace = [
            'id' => (string)$uid,
            'login' => (string)$login,
            'why' => $whyLabel,
        ];
        return class_exists('AssistantConfig', false)
            ? AssistantConfig::t('assistant.bot.denied_detail', $replace)
            : t('assistant.bot.denied_detail', $replace);
    }

    /** @return never */
    public static function denyJson(int $http = 401): void
    {
        AgentHttp::error(self::deniedMessage(), $http);
    }
}
