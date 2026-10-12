<?php
declare(strict_types=1);

require_once base_path('modules/core/lib/NewsStatusesRepository.php');

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$action = trim((string)($_POST['action'] ?? 'save'));

$row = [];
if (!$isNew) {
    $found = NewsStatusesRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('core.news_statuses.err.not_found', ['id' => $id]), 404);
        }
        http_response_code(404);
        View::render('error', [
            'title' => t('core.common.not_found'),
            'message' => t('core.news_statuses.err.not_found', ['id' => $id]),
        ]);
        return;
    }
    $row = $found;
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($action === 'delete' && !$isNew) {
            NewsStatusesRepository::delete($id);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'deleted' => true, 'id' => $id]);
            }
            flash_set('ok', t('core.news_statuses.deleted'));
            redirect('/news-statuses');
            return;
        }
        $data = NewsStatusesRepository::dataFromPost($_POST);
        if ($isNew) {
            $newId = NewsStatusesRepository::create($data);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'saved' => true,
                    'item' => NewsStatusesRepository::forAgent(NewsStatusesRepository::find($newId)),
                ]);
            }
            redirect('/news-statuses/' . $newId . '/edit?saved=1');
            return;
        }
        NewsStatusesRepository::update($id, $data);
        $row = NewsStatusesRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'saved' => true,
                'item' => NewsStatusesRepository::forAgent($row),
            ]);
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
        }
        $error = $e->getMessage();
    }
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$usage = $isNew ? 0 : NewsStatusesRepository::usageCount($id);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row ? NewsStatusesRepository::forAgent($row) : null,
        'usage' => $usage,
        'deadline_policies' => NewsStatusesRepository::deadlinePolicies(),
    ]);
}

View::render('news_status_edit', [
    'title' => $isNew
        ? t('core.news_statuses.title_new')
        : t('core.news_statuses.title_edit', ['name' => (string)($row['NAME'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'row' => $row,
    'usage' => $usage,
    'policies' => NewsStatusesRepository::deadlinePolicies(),
    'message' => $message,
    'error' => $error,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('core.menu.news'), 'href' => '/news?type=2'],
        ['label' => t('core.menu.news_statuses'), 'href' => '/news-statuses'],
        [
            'label' => $isNew
                ? t('core.news_statuses.title_new')
                : (string)($row['NAME'] ?? ('#' . $id)),
            'current' => true,
        ],
    ]),
], 'layout', 'core');
