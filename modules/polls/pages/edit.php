<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$presetPid = (int)($_GET['pid'] ?? ($_POST['pid'] ?? 0));
$presetEl = (int)($_GET['el_type'] ?? ($_POST['el_type'] ?? PollsRepository::EL_POLL));

$row = [];
if (!$isNew) {
    $found = PollsRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('polls.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
} else {
    $row = [
        'pid' => $presetPid,
        'el_type' => $presetEl,
        'enabled' => 1,
        'num' => 0,
        'name' => '',
    ];
    if ($presetPid > 0) {
        $parent = PollsRepository::find($presetPid);
        if ($parent) {
            if ((int)$parent['el_type'] === PollsRepository::EL_POLL) {
                $row['el_type'] = PollsRepository::EL_QUESTION;
            } elseif ((int)$parent['el_type'] === PollsRepository::EL_QUESTION) {
                $row['el_type'] = PollsRepository::EL_ANSWER;
            }
        }
    }
}

$message = '';
$error = '';
$children = !$isNew ? PollsRepository::children($id) : [];


if (!$isNew && EntityDelete::tryHandle('polls', $id, '/polls', [
    'stay_path' => '/polls/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $data = PollsRepository::dataFromPost($_POST, $isNew ? null : $row);
        if ($isNew) {
            $newId = PollsRepository::create($data);
            redirect('/polls/' . $newId . '/edit?saved=1');
            return;
        }
        PollsRepository::update($id, $data);
        $message = t('core.common.saved');
        $row = PollsRepository::find($id) ?: $row;
        $children = PollsRepository::children($id);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = array_merge($row, [
            'name' => (string)($_POST['name'] ?? $row['name'] ?? ''),
            'descr_admin' => (string)($_POST['descr_admin'] ?? ''),
            'descr_site' => (string)($_POST['descr_site'] ?? ''),
        ]);
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$elType = (int)($row['el_type'] ?? 0);
$parentCrumb = null;
if ((int)($row['pid'] ?? 0) > 0) {
    $p = PollsRepository::find((int)$row['pid']);
    if ($p) {
        $parentCrumb = $p;
    }
}

$crumbs = [
    ['label' => t('polls.menu.list'), 'href' => '/polls'],
];
if ($parentCrumb) {
    $crumbs[] = [
        'label' => (string)$parentCrumb['name'],
        'href' => '/polls/' . (int)$parentCrumb['id'] . '/edit',
    ];
}
$crumbs[] = [
    'label' => $isNew ? t('polls.edit.heading_new') : (string)($row['name'] ?? ('#' . $id)),
    'current' => true,
];

$canEdit = Auth::can('polls.edit');

if (AgentHttp::wantsJson()) {
    AgentHttp::emit([
        'ok' => true,
        'item' => $row,
        'children' => $children,
    ]);
    return;
}

View::render('edit', [
    'title' => $isNew
        ? t('polls.edit.title_new')
        : t('polls.edit.title', ['name' => (string)($row['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'children' => $children,
    'elType' => $elType,
    'canEdit' => $canEdit,
    'hasProcedures' => ModuleManager::isEnabled('procedures'),
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome($crumbs),
], 'layout', 'polls');
