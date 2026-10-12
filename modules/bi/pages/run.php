<?php
declare(strict_types=1);

$familyId = trim((string)($_GET['family'] ?? $_POST['family'] ?? ''));
$family = $familyId !== '' ? ReportRegistry::get($familyId) : null;
if ($family === null) {
    redirect('/bi');
}

$params = ReportParams::normalize(array_merge($_GET, $_POST));
$report = null;
$error = '';
$autoRun = !empty($_GET['autorun']) || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($autoRun && AgentHttp::wantsJson()) {
    // JSON runs go through /bi/work
    redirect('/bi/run?family=' . rawurlencode($familyId));
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
    'operators' => BiFilterOptions::staffOperators(),
    'admins' => BiFilterOptions::staffAdmins(),
    'products' => BiFilterOptions::products(),
], 'layout', 'bi');
