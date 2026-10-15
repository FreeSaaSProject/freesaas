<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$wantsJson = AgentHttp::wantsJson();
$includeKeys = !empty($_GET['keys']) || !empty($_GET['include_keys']);

try {
    $pack = ScriptPack::export($id, $includeKeys);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 404);
        return;
    }
    http_response_code(404);
    View::render('error', [
        'title' => $e->getMessage(),
        'message' => $e->getMessage(),
    ], 'error', 'core');
    return;
}

$name = (string)($pack['script']['name'] ?? ('script-' . $id));
$filename = ScriptPack::filename($id, $name);
$json = json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    if ($wantsJson) {
        AgentHttp::error(t('audio_dicts.scripts.pack.err_invalid_json'), 500);
        return;
    }
    http_response_code(500);
    echo t('audio_dicts.scripts.pack.err_invalid_json');
    return;
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'pack' => $pack,
        'filename' => $filename,
        'include_keys' => $includeKeys,
    ]);
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
header('Cache-Control: no-store');
header('Content-Length: ' . (string)strlen($json));
echo $json;
