<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$canEdit = Auth::can('audio_phrases.edit');
$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$adminId = max(0, (int)($_GET['admin'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit && isset($_POST['rebuild'])) {
    $r = PhraseRunner::runDay($date, $adminId);
    if ($wantsJson) {
        AgentHttp::emit($r);
        return;
    }
    redirect('/audio/phrases/day?' . http_build_query(['date' => $date, 'admin' => $adminId, 'done' => 1]));
}

$items = PhrasesRepository::forDay($date, $adminId);
$staffOptions = PhrasesRepository::adminsForDay($date);
// Keep current filter selectable even if that admin has no rows after a purge.
if ($adminId > 0 && !isset($staffOptions[$adminId])) {
    $u = UsersRepository::find($adminId);
    if ($u !== null) {
        $label = trim((string)($u['name'] ?? ''));
        $staffOptions[$adminId] = $label !== '' ? $label : (string)($u['login'] ?? ('#' . $adminId));
    } else {
        $staffOptions[$adminId] = '#' . $adminId;
    }
}
$pending = PhrasesRepository::pendingQueue(['day' => $date, 'admin' => $adminId, 'limit' => 40]);
if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'date' => $date, 'admin' => $adminId, 'items' => $items]);
    return;
}

$dictOptions = class_exists('RecordsDictsRepository', false)
    ? ($adminId > 0
        ? RecordsDictsRepository::optionsForAdmin($adminId, false)
        : RecordsDictsRepository::options(false))
    : [];

View::render('phrases_day', [
    'title' => t('audio_phrases.day_title') . ' — ' . $date,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('audio_phrases.menu.list'), 'href' => '/audio/phrases'],
        ['label' => $date, 'current' => true],
    ]),
    'date' => $date,
    'adminId' => $adminId,
    'staffOptions' => $staffOptions,
    'items' => $items,
    'canEdit' => $canEdit,
    'dictOptions' => $dictOptions,
    'pending' => $pending,
    'flash' => !empty($_GET['done']) ? t('audio_phrases.saved') : '',
], 'layout', 'audio_phrases');
