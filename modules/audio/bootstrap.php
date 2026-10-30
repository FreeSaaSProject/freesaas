<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/AudioSettingsRepository.php';
require_once __DIR__ . '/lib/FileStorage.php';
require_once __DIR__ . '/lib/InboxFiles.php';
require_once __DIR__ . '/lib/BadgesRepository.php';
require_once __DIR__ . '/lib/BadgeAssignmentsRepository.php';
require_once __DIR__ . '/lib/BadgeOrphanHints.php';
require_once __DIR__ . '/lib/BadgeBotPlugin.php';
require_once __DIR__ . '/lib/ModerationBotPlugin.php';
require_once __DIR__ . '/lib/RecordsRepository.php';
require_once __DIR__ . '/lib/RecordsReviewsRepository.php';
require_once __DIR__ . '/lib/TranscriptGarbage.php';
require_once __DIR__ . '/lib/TranscriptHighlighter.php';
require_once __DIR__ . '/lib/AudioBiContrib.php';


EventBus::on('bi.datasets', [AudioBiContrib::class, 'onDatasets']);

Assets::register('audio', [
    'css' => ['audio.css'],
    'js' => ['audio.js'],
]);
ModuleManager::registerMenuGroup('audio.menu.group', 500, '/audio/calendar');

StatusesRepository::registerFieldGroup([
    'title' => 'audio.statuses.group',
    'when' => 'audio',
    'fields' => [
        ['key' => 'ID_AUDIO_POSITION', 'label' => 'audio.statuses.field.ID_AUDIO_POSITION', 'type' => 'select_status'],
        ['key' => 'DISABLE_NON_PROCEDURE_TIME', 'label' => 'audio.statuses.field.DISABLE_NON_PROCEDURE_TIME', 'type' => 'checkbox'],
    ],
]);

$pages = [
    '/audio' => ['permission' => 'audio.view', 'methods' => ['GET', 'POST'], 'file' => 'list.php'],
    '/audio/ingest' => ['permission' => 'audio.edit', 'methods' => ['GET', 'POST'], 'file' => 'ingest.php'],
    '/audio/badges' => ['permission' => 'audio.badges', 'methods' => ['GET', 'POST'], 'file' => 'badges.php'],
    '/audio/assignments' => ['permission' => 'audio.badges', 'methods' => ['GET'], 'file' => 'assignments_list.php'],
    '/audio/assignments/new' => ['permission' => 'audio.badges', 'methods' => ['GET', 'POST'], 'file' => 'assignments_edit.php'],
    '/audio/assignments/{id}/edit' => ['permission' => 'audio.badges', 'methods' => ['GET', 'POST'], 'file' => 'assignments_edit.php'],
    '/audio/settings' => ['capability' => 'SUPERADMIN_ACCESS', 'methods' => ['GET', 'POST'], 'file' => 'settings.php'],
    '/audio/play' => ['permission' => 'audio.view', 'methods' => ['GET'], 'file' => 'play.php'],
    '/audio/api/dict' => ['permission' => 'audio.edit', 'methods' => ['POST'], 'file' => 'api_dict.php'],
    '/audio/api/blocks' => ['permission' => 'audio.view', 'methods' => ['GET'], 'file' => 'api_blocks.php'],
    '/audio/api/badge' => ['permission' => '', 'methods' => ['POST'], 'file' => 'api_badge.php'],
    '/audio/{id}' => ['permission' => 'audio.view', 'methods' => ['GET', 'POST'], 'file' => 'view.php'],
];

foreach ($pages as $path => $def) {
    $file = (string)$def['file'];
    $route = [
        'module' => 'audio',
        'methods' => $def['methods'],
        'handler' => static function () use ($file): void {
            require base_path('modules/audio/pages/' . $file);
        },
    ];
    $perm = (string)($def['permission'] ?? '');
    if ($perm !== '') {
        $route['permission'] = $perm;
    }
    $capability = (string)($def['capability'] ?? '');
    if ($capability !== '') {
        $route['capability'] = $capability;
    }
    ModuleManager::registerRoute($path, $route);
}

BulkActions::register('audio_records', 'set_admin', [
    'label' => 'audio.bulk.set_admin',
    'group' => 'audio.bulk.group.setters',
    'permission' => 'audio.edit',
    'fields' => [
        [
            'name' => 'admin_id',
            'type' => 'select',
            'label' => 'audio.bulk.admin',
            'options' => static function (): array {
                $out = [];
                foreach (UsersRepository::listEnabledStaff() as $u) {
                    $label = trim((string)$u['name']);
                    if ($label === '') {
                        $label = (string)$u['login'];
                    }
                    $out[(int)$u['id']] = $label;
                }
                return $out;
            },
        ],
    ],
    'run' => static function (array $ids, array $params): array {
        $adminId = (int)($params['admin_id'] ?? 0);
        if ($adminId < 1) {
            $skipped = [];
            foreach ($ids as $id) {
                $skipped[] = ['id' => (int)$id, 'reason' => t('audio.bulk.err.admin_required')];
            }
            return ['done' => 0, 'skipped' => $skipped];
        }
        return ['done' => RecordsRepository::setAdminByIds($ids, $adminId), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'disable', [
    'label' => 'audio.bulk.disable',
    'group' => 'audio.bulk.group.enablers',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_disable',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::setDisabledByIds($ids, true), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'enable', [
    'label' => 'audio.bulk.enable',
    'group' => 'audio.bulk.group.enablers',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_enable',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::setDisabledByIds($ids, false), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'clear_text', [
    'label' => 'audio.bulk.clear_text',
    'group' => 'audio.bulk.group.resetters',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_clear_text',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::clearTranscriptionByIds($ids, false), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'clear_text_keep_rid', [
    'label' => 'audio.bulk.clear_text_keep_rid',
    'group' => 'audio.bulk.group.resetters',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_clear_text',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::clearTranscriptionByIds($ids, true), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'review', [
    'label' => 'audio.bulk.review',
    'group' => 'audio.bulk.group.reviews',
    'permission' => 'audio.edit',
    'run' => static function (array $ids, array $params): array {
        $uid = Auth::userId();
        if ($uid < 1) {
            return ['done' => 0, 'skipped' => []];
        }

        return ['done' => RecordsReviewsRepository::markReviewed($ids, $uid), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'favorite_add', [
    'label' => 'audio.bulk.favorite_add',
    'group' => 'audio.bulk.group.reviews',
    'permission' => 'audio.edit',
    'run' => static function (array $ids, array $params): array {
        $uid = Auth::userId();
        if ($uid < 1) {
            return ['done' => 0, 'skipped' => []];
        }

        return ['done' => RecordsReviewsRepository::addFavorite($ids, $uid), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'favorite_remove', [
    'label' => 'audio.bulk.favorite_remove',
    'group' => 'audio.bulk.group.reviews',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_favorite_remove',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsReviewsRepository::removeFavorite($ids), 'skipped' => []];
    },
]);

BulkActions::register('audio_records', 'reset_admin', [
    'label' => 'audio.bulk.reset_admin',
    'group' => 'audio.bulk.group.resetters',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_reset_admin',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::resetAdminByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'reset_keys', [
    'label' => 'audio.bulk.reset_keys',
    'group' => 'audio.bulk.group.resetters',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_reset_keys',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::resetKeysByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'reset_offers', [
    'label' => 'audio.bulk.reset_offers',
    'group' => 'audio.bulk.group.resetters',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_reset_offers',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::resetOffersByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'reset_strings', [
    'label' => 'audio.bulk.reset_strings',
    'group' => 'audio.bulk.group.resetters',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_reset_strings',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::resetStringsByIds($ids), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'fix_playtime', [
    'label' => 'audio.bulk.fix_playtime',
    'group' => 'audio.bulk.group.resetters',
    'permission' => 'audio.edit',
    'confirm' => 'audio.bulk.confirm_fix_playtime',
    'run' => static function (array $ids, array $params): array {
        if (!class_exists('AsrService', false) && !class_exists('AsrService')) {
            return ['done' => 0, 'skipped' => $ids];
        }

        return AsrService::fixPlaytimeFromTranscriptByIds($ids);
    },
]);
BulkActions::register('audio_records', 'delete', [
    'label' => 'audio.bulk.delete',
    'permission' => 'audio.delete',
    'group' => 'audio.bulk.group.deleter',
        'confirm' => 'audio.bulk.confirm_delete',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::deleteByIds($ids, false), 'skipped' => []];
    },
]);
BulkActions::register('audio_records', 'delete_with_files', [
    'label' => 'audio.bulk.delete_with_files',
    'group' => 'audio.bulk.group.deleter',
    'capability' => 'SUPERADMIN_ACCESS',
    'confirm' => 'audio.bulk.confirm_delete_with_files',
    'run' => static function (array $ids, array $params): array {
        return ['done' => RecordsRepository::deleteByIds($ids, true), 'skipped' => []];
    },
]);

EventBus::on('core.notices.collect', static function ($notices) {
    if (!is_array($notices)) {
        $notices = [];
    }
    if (!Auth::check() || !Auth::can('audio.view')) {
        return $notices;
    }
    $chip = RecordsReviewsRepository::noticeChip();
    if ($chip !== null) {
        $notices[] = $chip;
    }

    return $notices;
});

EventBus::on('core.user_edit.sections', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_edit_access.php';
    $payload['html_access'] = ($payload['html_access'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.user_edit.save', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];
    $data['AUDIO_ANALYTICS_ENABLED'] = !empty($post['AUDIO_ANALYTICS_ENABLED']) ? 1 : 0;
    $payload['data'] = $data;
    return $payload;
});

EventBus::on('core.user_edit.profile', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $user = $payload['user'] ?? null;
    if (!is_array($user)) {
        return $payload;
    }
    ob_start();
    require __DIR__ . '/views/_user_profile_channels.php';
    $payload['html_channels'] = ($payload['html_channels'] ?? '') . (string)ob_get_clean();
    return $payload;
});

EventBus::on('core.auth.before_logout', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $uid = (int)($payload['user_id'] ?? 0);
    if ($uid > 0) {
        BadgeAssignmentsRepository::closeOpenAssignments($uid);
    }
    return $payload;
});

EventBus::on('core.layout.gate', static function ($payload) {
    if (!is_array($payload) || !Auth::check()) {
        return $payload;
    }
    $uid = Auth::userId();
    if ($uid < 1 || !BadgeAssignmentsRepository::analyticsEnabled($uid)) {
        return $payload;
    }

    Assets::need('audio');

    // Reset → force re-pick (oper ?reset_badge=1).
    if (isset($_GET['reset_badge']) && (string)$_GET['reset_badge'] === '1') {
        BadgeAssignmentsRepository::closeOpenAssignments($uid);
        $qs = $_GET;
        unset($qs['reset_badge']);
        $qs['choose_badge'] = '1';
        $path = request_path();
        $tail = http_build_query($qs);
        redirect($path . ($tail !== '' ? ('?' . $tail) : '?choose_badge=1'));
    }

    BadgeAssignmentsRepository::syncSessionFromDb($uid);
    $need = BadgeAssignmentsRepository::needSelection($uid);
    $want = $need || (isset($_GET['choose_badge']) && (string)$_GET['choose_badge'] === '1');

    $chip = BadgeAssignmentsRepository::chipState($uid);
    if ($chip['mode'] !== 'none') {
        $hrefReset = request_path() . '?reset_badge=1';
        $hrefPick = request_path() . '?choose_badge=1';
        if ($chip['mode'] === 'badge' || $chip['mode'] === 'off') {
            $chipHtml = '<a class="audio-badge-chip audio-badge-chip--' . e($chip['mode']) . '" href="' . e($hrefReset) . '"'
                . ' title="' . te('audio.badge.chip.reset_title') . '">' . e($chip['label']) . '</a>';
        } else {
            $chipHtml = '<a class="audio-badge-chip audio-badge-chip--pick" href="' . e($hrefPick) . '"'
                . ' title="' . te('audio.badge.chip.pick_title') . '">' . e($chip['label']) . '</a>';
        }
        $payload['chrome'] = ($payload['chrome'] ?? '') . $chipHtml;
    }

    if (!$want) {
        return $payload;
    }

    // Don't nest gate on the JSON API itself.
    $path = request_path();
    if ($path === '/audio/api/badge') {
        return $payload;
    }

    $options = BadgeAssignmentsRepository::selectOptions(time(), $uid);
    ob_start();
    $forced = $need;
    require __DIR__ . '/views/_badge_gate.php';
    $payload['blocked'] = true;
    $payload['html'] = (string)ob_get_clean();
    $payload['js_i18n'] = array_merge(
        is_array($payload['js_i18n'] ?? null) ? $payload['js_i18n'] : [],
        [
            'audio.badge.pick_required' => t('audio.badge.gate.pick_required'),
            'audio.badge.error' => t('audio.api.error'),
            'audio.badge.network' => t('audio.api.network'),
        ]
    );
    return $payload;
});

$registerAudioBots = static function (): void {
    BadgeBotPlugin::register();
    ModerationBotPlugin::register();
};
if (ModuleManager::isEnabled('bots')) {
    $registerAudioBots();
}
EventBus::on('bots.ready', static function ($payload) use ($registerAudioBots) {
    $registerAudioBots();
    return is_array($payload) ? $payload : [];
});
