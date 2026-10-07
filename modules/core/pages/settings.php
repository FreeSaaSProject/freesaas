<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/SystemRepository.php');
require_once base_path('modules/core/lib/SystemLogo.php');

$message = '';
$error = '';
$row = SystemRepository::get();
$agentTokenPlain = '';
$agentTokenJustGenerated = false;
$action = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'agent_token_generate') {
            $token = bin2hex(random_bytes(32));
            Config::set('app', 'agent_token', $token);
            $agentTokenPlain = $token;
            $agentTokenJustGenerated = true;
            $message = t('core.settings.agent_token.generated');
        } else {
            SystemRepository::updateBasics([
                'company' => (string)($_POST['company'] ?? ''),
            ]);

            if (!empty($_POST['logo_remove'])) {
                SystemLogo::remove();
            }

            $upload = $_FILES['logo'] ?? null;
            if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                SystemLogo::saveFromUpload($upload);
            }

            EventBus::emit('core.settings.save', [
                'post' => $_POST,
                'files' => $_FILES,
                'row' => SystemRepository::get(),
            ]);

            $message = t('core.common.saved');
            $row = SystemRepository::get();
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $agentTokenJustGenerated = false;
        $row = SystemRepository::get();
    }
}

$sections = EventBus::emit('core.settings.sections', [
    'html' => '',
    'row' => $row,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';

$agentConfigured = AgentAuth::isConfigured();
if ($agentTokenPlain === '' && $agentConfigured) {
    $agentTokenPlain = AgentAuth::configuredToken();
}
$agentTokenMasked = '';
if ($agentConfigured && $agentTokenPlain !== '') {
    $len = strlen($agentTokenPlain);
    $agentTokenMasked = $len <= 12
        ? str_repeat('•', $len)
        : (substr($agentTokenPlain, 0, 4) . '…' . substr($agentTokenPlain, -4));
}

View::render('settings', [
    'title' => t('core.menu.settings'),
    'row' => $row,
    'message' => $message,
    'error' => $error,
    'logoUrl' => SystemLogo::url(SystemLogo::VARIANT_AUTH),
    'hasLogo' => SystemLogo::has(),
    'extraHtml' => $extraHtml,
    'agentTokenConfigured' => $agentConfigured,
    'agentTokenMasked' => $agentTokenMasked,
    'agentTokenPlain' => $agentTokenPlain,
    'agentTokenJustGenerated' => $agentTokenJustGenerated,
]);
