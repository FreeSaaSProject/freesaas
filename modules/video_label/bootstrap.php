<?php
declare(strict_types=1);

/**
 * Copyright (C) 2026 Ilya Kleshnin
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/lib/VideoLabelProjectsRepository.php';
require_once __DIR__ . '/lib/VideoLabelImagesRepository.php';
require_once __DIR__ . '/lib/VideoLabelZipIngest.php';
require_once __DIR__ . '/lib/VideoLabelAgentOps.php';

VideoLabelAgentOps::register();

Assets::register('video_label', [
    'css' => ['assets/css/video_label.css'],
    'js' => ['assets/js/video_label.js'],
]);

// Prefer video rail group when video module is present
if (ModuleManager::isEnabled('video')) {
    // menu items use video.menu.group from video module
} else {
    ModuleManager::registerMenuGroup('video_label.menu.group', 521, '/video-label');
}

$group = ModuleManager::isEnabled('video') ? 'video.menu.group' : 'video_label.menu.group';

$pages = [
    '/video-label' => ['permission' => 'video_label.view', 'methods' => ['GET'], 'file' => 'projects.php'],
    '/video-label/new' => ['permission' => 'video_label.edit', 'methods' => ['GET', 'POST'], 'file' => 'project_edit.php'],
    '/video-label/{id}/edit' => ['permission' => 'video_label.edit', 'methods' => ['GET', 'POST'], 'file' => 'project_edit.php'],
    '/video-label/{id}/workspace' => ['permission' => 'video_label.edit', 'methods' => ['GET', 'POST'], 'file' => 'workspace.php'],
    '/video-label/media' => ['permission' => 'video_label.view', 'methods' => ['GET'], 'file' => 'media.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    ModuleManager::registerRoute($path, [
        'module' => 'video_label',
        'methods' => $def['methods'],
        'permission' => $def['permission'],
        'handler' => static function () use ($file): void {
            require base_path('modules/video_label/pages/' . $file);
        },
    ]);
}

// Patch menu group dynamically via module.json is static — update module.json to use video.menu.group
unset($group);
