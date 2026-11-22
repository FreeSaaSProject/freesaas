<?php
declare(strict_types=1);

/** BotPlugins registration: confirm buttons pressed in private staff/client chats. */
final class NotifyBotPlugin
{
    public static function register(): void
    {
        if (!class_exists('BotPlugins', false)) {
            return;
        }
        BotPlugins::register('notify', [
            'module' => 'notify',
            'label' => 'notify.bot.plugin',
            'priority' => 40,
            'audiences' => ['staff', 'client'],
            'handle_callback' => [NotifyInbound::class, 'pluginCallback'],
        ]);
    }
}
