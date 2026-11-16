<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/MedcardAudioConfig.php';
require_once __DIR__ . '/lib/MedcardAudioFill.php';
require_once __DIR__ . '/lib/MedcardAudioBotPlugin.php';

ModuleManager::registerRoute('/medcard-audio/settings', [
    'module' => 'medcard_audio',
    'methods' => ['GET', 'POST'],
    'permission' => 'medcard_audio.edit',
    'capability' => 'SUPERADMIN_ACCESS',
    'handler' => static function (): void {
        require base_path('modules/medcard_audio/pages/settings.php');
    },
]);

$registerBot = static function (): void {
    MedcardAudioBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerBot();
}
EventBus::on('bots.ready', static function ($payload) use ($registerBot) {
    $registerBot();
    return is_array($payload) ? $payload : [];
});
