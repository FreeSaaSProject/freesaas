<?php
declare(strict_types=1);

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$isNew = (bool)preg_match('#/llm/tokens/new/?$#', $path);
$idParam = (string)(Router::param('id') ?? '');
$editId = $isNew ? '' : LlmConfig::normalizeId($idParam);
$profiles = LlmConfig::listForUi();
$row = (!$isNew && $editId !== '' && isset($profiles[$editId])) ? $profiles[$editId] : null;

if (!$isNew && $row === null) {
    if (AgentHttp::wantsJson()) {
        AgentHttp::error(t('llm.err.not_found'), 404);
    }
    flash_set('error', t('llm.err.not_found'));
    redirect('/llm/tokens');
}

$error = '';
$message = !empty($_GET['saved']) ? t('core.common.saved') : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $id = $isNew ? (string)($_POST['id'] ?? '') : $editId;
    $input = [
        'label' => (string)($_POST['label'] ?? ''),
        'api_base' => (string)($_POST['api_base'] ?? LlmConfig::DEFAULT_API_BASE),
        'model_chat' => (string)($_POST['model_chat'] ?? LlmConfig::DEFAULT_MODEL),
        'api_key' => (string)($_POST['api_key'] ?? ''),
        'enabled' => !empty($_POST['enabled']),
        'set_default' => !empty($_POST['set_default']),
    ];
    $res = LlmConfig::saveProfile($id, $input, $isNew);
    if ($res['ok']) {
        if (AgentHttp::wantsJson()) {
            AgentHttp::emit(['ok' => true, 'id' => $res['id'], 'profile' => LlmConfig::listForUi()[$res['id']] ?? null]);
        }
        redirect('/llm/tokens/' . rawurlencode($res['id']) . '?saved=1');
    }
    $error = $res['error'];
    if (AgentHttp::wantsJson()) {
        AgentHttp::error($error !== '' ? $error : t('llm.err.id_invalid'));
    }
    $row = [
        'id' => LlmConfig::normalizeId($id) ?: $id,
        'label' => $input['label'],
        'api_base' => $input['api_base'],
        'model_chat' => $input['model_chat'],
        'enabled' => $input['enabled'],
        'api_key_masked' => LlmConfig::maskKey(''),
        'has_key' => false,
        'is_default' => $input['set_default'],
    ];
}

if ($row === null) {
    $row = [
        'id' => '',
        'label' => 'DeepSeek',
        'api_base' => LlmConfig::DEFAULT_API_BASE,
        'model_chat' => LlmConfig::DEFAULT_MODEL,
        'enabled' => true,
        'api_key_masked' => t('llm.tokens.masked_empty'),
        'has_key' => false,
        'is_default' => LlmConfig::listForUi() === [],
    ];
}

$title = $isNew ? t('llm.tokens.new') : t('llm.tokens.edit');

View::render('tokens_edit', [
    'title' => $title,
    'isNew' => $isNew,
    'row' => $row,
    'error' => $error,
    'message' => $message,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('llm.menu.tokens'), 'href' => '/llm/tokens'],
        ['label' => $title, 'current' => true],
    ]),
], 'layout', 'llm');
