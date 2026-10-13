<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$client = [];
$selectedTagIds = [];
if (!$isNew) {
    $found = ClientsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('clients.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('clients.err.not_found', ['id' => $id])]);
        return;
    }
    $client = $found;
    $selectedTagIds = ClientsRepository::tagIdsForClient($id);
    if (!ClientsRepository::canAccessClient($id)) {
        if ($wantsJson) {
            AgentHttp::error(t('clients.err.forbidden_day'), 403);
            return;
        }
        http_response_code(403);
        View::render('error', ['title' => t('core.common.forbidden'), 'message' => t('clients.err.forbidden_day')]);
        return;
    }
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!$isNew && !ClientsRepository::canAccessClient($id)) {
            throw new RuntimeException(t('clients.err.forbidden_day'));
        }
        $data = ClientsRepository::dataFromPost($_POST);
        $tagIdsRaw = $_POST['tag_ids'] ?? [];
        if (!is_array($tagIdsRaw)) {
            $tagIdsRaw = [];
        }
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIdsRaw), static function (int $tid): bool {
            return $tid > 0;
        })));
        $selectedTagIds = $tagIds;

        if ($isNew) {
            $newId = ClientsRepository::create($data);
            ClientsRepository::setTagIds($newId, $tagIds);
            EventBus::emit('clients.edit.save', [
                'id' => $newId,
                'post' => $_POST,
                'client' => array_merge($data, ['id' => $newId]),
                'is_new' => true,
            ]);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'item' => ClientsRepository::forAgent(ClientsRepository::find($newId), true),
                ]);
                return;
            }
            redirect('/clients/' . $newId . '/edit?saved=1');
            return;
        }
        ClientsRepository::update($id, $data);
        ClientsRepository::setTagIds($id, $tagIds);
        EventBus::emit('clients.edit.save', [
            'id' => $id,
            'post' => $_POST,
            'client' => array_merge($client, $data, ['id' => $id]),
            'is_new' => false,
        ]);
        $client = ClientsRepository::find($id) ?: $client;
        $selectedTagIds = ClientsRepository::tagIdsForClient($id);
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'id' => $id,
                'item' => ClientsRepository::forAgent($client, true),
            ]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $client = array_merge($client, $_POST);
        $tagIdsRaw = $_POST['tag_ids'] ?? [];
        if (!is_array($tagIdsRaw)) {
            $tagIdsRaw = [];
        }
        $selectedTagIds = array_values(array_unique(array_filter(array_map('intval', $tagIdsRaw), static function (int $tid): bool {
            return $tid > 0;
        })));
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'item' => ClientsRepository::forAgent($client ?: null, true) ?? [
            'tag_ids' => $selectedTagIds,
        ],
        'is_new' => $isNew,
        'tag_ids' => $selectedTagIds,
        'statuses' => ClientStatusesRepository::names(false),
        'tags' => ClientTagsRepository::names(false),
    ]);
    return;
}

if (isset($_GET['saved']) && $message === '') {
    $message = t('core.common.saved');
}

$tagOptions = ClientTagsRepository::names(true);
$allTagNames = ClientTagsRepository::names(false);
foreach ($selectedTagIds as $tid) {
    if (!isset($tagOptions[$tid]) && isset($allTagNames[$tid])) {
        $tagOptions[$tid] = $allTagNames[$tid];
    }
}
asort($tagOptions, SORT_NATURAL | SORT_FLAG_CASE);

$sections = EventBus::emit('clients.edit.sections', [
    'html' => '',
    'client' => $client,
    'id' => $id,
    'is_new' => $isNew,
]);
$extraHtml = is_array($sections) ? (string)($sections['html'] ?? '') : '';
if (is_array($sections) && isset($sections['client']) && is_array($sections['client'])) {
    $client = $sections['client'];
}

View::render('edit', [
    'title' => $isNew
        ? t('clients.edit.title_new')
        : t('clients.edit.title', ['name' => (string)($client['name'] ?? ('#' . $id))]),
    'isNew' => $isNew,
    'client' => $client,
    'message' => $message,
    'error' => $error,
    'statuses' => ClientStatusesRepository::names(false),
    'staffOptions' => ClientsRepository::staffOptions(),
    'tagOptions' => $tagOptions,
    'selectedTagIds' => $selectedTagIds,
    'extraHtml' => $extraHtml,
], 'layout', 'clients');
