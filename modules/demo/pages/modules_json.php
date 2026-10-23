<?php
declare(strict_types=1);

/**
 * Public module enablement map for freesaas.ru marketing tiles.
 * Only registered when app.demo_readonly=1.
 */
if (!class_exists('DemoMode', false) || !DemoMode::readonly()) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'not a demo stand'], JSON_UNESCAPED_UNICODE);
    return;
}

$origin = trim((string)Config::get('demo', 'cors_origin', 'https://freesaas.ru'));
if ($origin === '' || !preg_match('#^https?://#i', $origin)) {
    $origin = 'https://freesaas.ru';
}
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Accept');
header('Vary: Origin');
header('Cache-Control: public, max-age=300');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    return;
}

$modules = [];
foreach (ModuleManager::all() as $id => $_) {
    $id = (string)$id;
    $modules[$id] = Config::moduleEnabled($id);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'stand_id' => DemoStand::configuredId(),
    'locale' => I18n::locale(),
    'currency' => I18n::currencyCode(),
    'currency_symbol' => I18n::currencySymbol(),
    'modules' => $modules,
], JSON_UNESCAPED_UNICODE);
