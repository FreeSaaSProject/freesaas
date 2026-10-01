<?php
declare(strict_types=1);

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;

$client = [];
$selectedTagIds = [];
if (!$isNew) {
    $found = ClientsRepository::find($id);
    if (!$found) {
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('clients.err.not_found', ['id' => $id])]);
        return;
    }
    $client = $found;
    $selectedTagIds = ClientsRepository::tagIdsForClient($id);
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
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
            redirect('/clients/' . $newId . '/edit?saved=1');
            return;
        }
        ClientsRepository::update($id, $data);
        ClientsRepository::setTagIds($id, $tagIds);
        $message = t('core.common.saved');
        $client = ClientsRepository::find($id) ?: $client;
        $selectedTagIds = ClientsRepository::tagIdsForClient($id);
    } catch (Throwable $e) {
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
], 'layout', 'clients');
