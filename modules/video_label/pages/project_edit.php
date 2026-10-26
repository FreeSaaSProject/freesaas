<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$id = (int)(Router::param('id') ?? 0);
$isNew = $id <= 0;
$row = $isNew ? null : VideoLabelProjectsRepository::find($id);
if (!$isNew && $row === null) {
    http_response_code(404);
    echo t('core.common.not_found');
    return;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete' && $id > 0) {
        VideoLabelProjectsRepository::delete($id);
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'deleted' => true]);
            return;
        }
        flash_set('ok', t('video_label.flash.deleted'));
        redirect('/video-label');
    }
    $classesRaw = trim((string)($_POST['classes'] ?? ''));
    $classes = class_exists(VideoColors::class, false)
        ? VideoColors::fromTextarea($classesRaw)
        : array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $classesRaw) ?: [])));
    try {
        $newId = VideoLabelProjectsRepository::save(
            $id,
            trim((string)($_POST['name'] ?? '')),
            $classes,
            [],
            (int)($_POST['model_id'] ?? 0),
            (float)($_POST['conf_threshold'] ?? 0.25),
            (float)($_POST['iou_threshold'] ?? 0.45)
        );
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $newId]);
            return;
        }
        flash_set('ok', t('core.common.saved'));
        redirect('/video-label/' . $newId . '/workspace');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($wantsJson) {
            AgentHttp::error($error, 400);
            return;
        }
    }
}

$models = class_exists(VideoModelsRepository::class, false) ? VideoModelsRepository::listAll() : [];
$classText = '';
if ($row && !empty($row['classes'])) {
    $lines = [];
    foreach ($row['classes'] as $c) {
        if (!is_array($c)) {
            $lines[] = (string)$c;
            continue;
        }
        $line = (string)($c['name'] ?? '');
        $label = trim((string)($c['label'] ?? ''));
        if ($label !== '' && $label !== $line) {
            $line .= '|' . $label;
        }
        $color = trim((string)($c['color'] ?? ''));
        if ($color !== '') {
            $line .= ' ' . $color;
        }
        $lines[] = $line;
    }
    $classText = implode("\n", $lines);
}

if ($wantsJson) {
    AgentHttp::emit(['ok' => true, 'project' => $row, 'models' => $models]);
    return;
}

View::render('project_edit', [
    'title' => $isNew ? t('video_label.projects.new') : t('video_label.projects.edit'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('video_label.menu.projects'), 'href' => '/video-label'],
        ['label' => $isNew ? t('video_label.projects.new') : (string)($row['NAME'] ?? ''), 'current' => true],
    ]),
    'row' => $row,
    'models' => $models,
    'classText' => $classText,
    'error' => $error,
    'isNew' => $isNew,
], 'layout', 'video_label');
