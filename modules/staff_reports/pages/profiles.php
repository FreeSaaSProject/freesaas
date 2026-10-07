<?php
declare(strict_types=1);

$position = max(0, (int)($_GET['position'] ?? $_POST['position'] ?? 0));
$wantsJson = AgentHttp::wantsJson();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Auth::can('staff_reports.edit') || !Auth::capability('SUPERADMIN_ACCESS')) {
        if ($wantsJson) {
            AgentHttp::error('forbidden', 403);
        }
        http_response_code(403);
        echo 'forbidden';
        return;
    }
    $position = max(0, (int)($_POST['position'] ?? $position));
    $enabled = !empty($_POST['enabled']);
    $configRaw = $_POST['config'] ?? null;
    if (is_string($configRaw)) {
        $decoded = json_decode($configRaw, true);
        $config = is_array($decoded) ? $decoded : [];
    } elseif (is_array($configRaw)) {
        $config = $configRaw;
    } else {
        $config = [];
    }
    if ($position < 1) {
        if ($wantsJson) {
            AgentHttp::error('position required');
        }
        http_response_code(400);
        echo 'position required';
        return;
    }
    $saved = StaffReportProfiles::save($position, $enabled, $config);
    if ($wantsJson) {
        AgentHttp::emit(['ok' => true, 'profile' => $saved]);
    }
    redirect('/audio/settings?sr_pos=' . $position);
}

if (!$wantsJson) {
    // HTML UI lives under /audio/settings; redirect humans.
    redirect('/audio/settings' . ($position > 0 ? ('?sr_pos=' . $position) : ''));
}

if ($position > 0) {
    $profile = StaffReportProfiles::findByPosition($position);
    AgentHttp::emit([
        'ok' => true,
        'profile' => $profile,
        'defaults' => StaffReportConfig::defaults(),
    ]);
}

AgentHttp::emit([
    'ok' => true,
    'profiles' => StaffReportProfiles::listAll(),
    'defaults' => StaffReportConfig::defaults(),
]);
