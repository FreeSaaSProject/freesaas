<?php
declare(strict_types=1);

$path = '/audio/scripts';
$wantsJson = AgentHttp::wantsJson();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJson) {
        AgentHttp::error(t('audio_dicts.scripts.pack.err_post_required'), 405);
        return;
    }
    redirect($path);
    return;
}

try {
    $raw = '';
    $upload = $_FILES['pack'] ?? null;
    if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $err = (int)($upload['error'] ?? UPLOAD_ERR_OK);
        if ($err !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_upload'));
        }
        $tmp = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_upload'));
        }
        if ($size > ScriptPack::MAX_BYTES) {
            throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_too_large'));
        }
        $raw = (string)file_get_contents($tmp);
    } else {
        // Agent JSON body: { "pack": {…} } or raw pack object
        $body = file_get_contents('php://input') ?: '';
        if ($body !== '' && strpos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
            $decoded = json_decode($body, true);
            $agentName = '';
            if (is_array($decoded)) {
                $agentName = trim((string)($decoded['name'] ?? ''));
            }
            if (is_array($decoded) && isset($decoded['pack']) && is_array($decoded['pack'])) {
                $result = ScriptPack::import($decoded['pack'], $agentName !== '' ? $agentName : null);
            } elseif (is_array($decoded)) {
                $result = ScriptPack::import($decoded, $agentName !== '' ? $agentName : null);
            } else {
                throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_invalid_json'));
            }
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $result['id'],
                    'warnings' => $result['warnings'],
                    'tree' => RecordsScriptsRepository::findWithParts($result['id']),
                ]);
                return;
            }
            redirect($path . '/' . $result['id']);
            return;
        }
        throw new InvalidArgumentException(t('audio_dicts.scripts.pack.err_upload'));
    }

    $pack = ScriptPack::decodeJson($raw);
    $nameOverride = trim((string)($_POST['name'] ?? ''));
    $result = ScriptPack::import($pack, $nameOverride !== '' ? $nameOverride : null);

    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'id' => $result['id'],
            'warnings' => $result['warnings'],
            'tree' => RecordsScriptsRepository::findWithParts($result['id']),
        ]);
        return;
    }

    if ($result['warnings'] !== []) {
        $_SESSION['audio_dicts_script_import_warnings'] = $result['warnings'];
    }
    redirect($path . '/' . $result['id']);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    redirect($path . '?err=' . rawurlencode($e->getMessage()));
}
