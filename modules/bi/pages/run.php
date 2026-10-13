<?php
declare(strict_types=1);

$familyId = trim((string)($_GET['family'] ?? $_POST['family'] ?? ''));
$family = $familyId !== '' ? ReportRegistry::get($familyId) : null;
if ($family === null) {
    redirect('/bi');
}

$canManage = Auth::can('bi.manage');
$shareToken = trim((string)($_GET['share'] ?? $_POST['share'] ?? ''));
$share = null;
if (!$canManage) {
    $share = BiSharesRepository::resolveStaffShareAccess($familyId, $shareToken);
    if ($share === null) {
        http_response_code(403);
        View::render('error', [
            'title' => t('bi.share.forbidden'),
            'message' => t('bi.share.forbidden'),
        ]);
        return;
    }
    $shareToken = (string)($share['TOKEN'] ?? '');
    $params = ReportParams::mergeWithConstraints(
        array_merge($_GET, $_POST),
        is_array($share['params'] ?? null) ? $share['params'] : []
    );
} else {
    $params = ReportParams::normalize(array_merge($_GET, $_POST));
}

$report = null;
$error = '';
$autoRun = !empty($_GET['autorun']) || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($autoRun && AgentHttp::wantsJson()) {
    // JSON runs go through /bi/work
    $q = '/bi/run?family=' . rawurlencode($familyId);
    if ($shareToken !== '') {
        $q .= '&share=' . rawurlencode($shareToken);
    }
    redirect($q);
}

$familyTitle = (string)$family->titleKey();
View::render('run', [
    'title' => $familyTitle,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('bi.menu.group'), 'href' => '/bi'],
        ['label' => t('bi.menu.list'), 'href' => '/bi'],
        ['label' => $familyTitle, 'current' => true],
    ]),
    'family' => $family,
    'familyId' => $familyId,
    'params' => $params,
    'ready' => $family->isReady(),
    'readyNote' => $family->readyNoteKey() !== '' ? t($family->readyNoteKey()) : '',
    'report' => $report,
    'error' => $error,
    'canManage' => $canManage,
    'shareToken' => $shareToken,
    'share' => $share,
    'operators' => BiFilterOptions::staffOperators(),
    'admins' => BiFilterOptions::staffAdmins(),
    'products' => BiFilterOptions::products(),
], 'layout', 'bi');
