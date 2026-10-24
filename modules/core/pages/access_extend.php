<?php
declare(strict_types=1);

if (!Auth::capability('SUPERADMIN_ACCESS')) {
    http_response_code(403);
    echo t('core.access_extend.forbidden');
    return;
}

$field = NewsRepository::normalizeAccessGrantField((string)($_REQUEST['field'] ?? 'ACCESS_GROUP'))
    ?? 'ACCESS_GROUP';
$findId = (int)($_REQUEST['find_id'] ?? 0);
$addId = (int)($_REQUEST['add_id'] ?? 0);

$message = '';
$error = '';
$preview = null;

$staffStatuses = UsersRepository::statusNamesFiltered(false);
$partnerStatuses = UsersRepository::statusNamesFiltered(true);
$staffUsers = UsersRepository::staffNamesIncluding([]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? 'preview');
    if ($findId < 1 || $addId < 1) {
        $error = t('core.access_extend.err.ids');
    } elseif ($findId === $addId) {
        $error = t('core.access_extend.err.same');
    } elseif ($action === 'apply') {
        $r = NewsRepository::applyAccessGrant($field, $findId, $addId);
        $message = t('core.access_extend.flash.applied', [
            'updated' => (string)(int)$r['updated'],
            'skipped' => (string)(int)$r['skipped_already'],
            'total' => (string)(int)$r['total'],
        ]);
        $preview = NewsRepository::previewAccessGrant($field, $findId, $addId, 50);
    } else {
        $preview = NewsRepository::previewAccessGrant($field, $findId, $addId, 50);
        if ((int)$preview['total'] === 0) {
            $message = t('core.access_extend.flash.none');
        }
    }
} elseif ($findId > 0 && $addId > 0 && $findId !== $addId) {
    $preview = NewsRepository::previewAccessGrant($field, $findId, $addId, 50);
}

$pickerKind = $field === 'ACCESS_USER' ? 'user' : ($field === 'ACCESS_GROUP2' ? 'partner' : 'staff');
$pickerOptions = $pickerKind === 'user'
    ? $staffUsers
    : ($pickerKind === 'partner' ? $partnerStatuses : $staffStatuses);

View::render('access_extend', [
    'title' => t('core.menu.access_extend'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.access_extend'), 'current' => true],
    ]),
    'field' => $field,
    'find_id' => $findId,
    'add_id' => $addId,
    'picker_kind' => $pickerKind,
    'picker_options' => $pickerOptions,
    'preview' => $preview,
    'message' => $message,
    'error' => $error,
], 'layout', 'core');
