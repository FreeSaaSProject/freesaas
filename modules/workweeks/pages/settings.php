<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$message = '';
$error = '';
$loggingOn = WorkweeksRepository::commentsLoggingAvailable();
$newsId = $loggingOn ? WorkweeksRepository::scheduleNewsId() : 0;
$logTableMode = WorkweeksRepository::logTableChangesMode();
$shiftWindows = WorkweeksRepository::shiftWindows();

/**
 * @param array<string,mixed> $post
 * @return array<int,array{start:string,end:string,label:string}>
 */
$parsePostedShifts = static function (array $post): array {
    $posted = [];
    $raw = $post['shifts'] ?? null;
    if (is_array($raw)) {
        foreach ($raw as $id => $row) {
            $n = (int)$id;
            if ($n < 1 || $n > 99 || !is_array($row)) {
                continue;
            }
            $posted[$n] = [
                'start' => (string)($row['start'] ?? ''),
                'end' => (string)($row['end'] ?? ''),
                'label' => (string)($row['label'] ?? ''),
            ];
        }
    }
    // Legacy agent payload: shift_N_* keys
    if ($posted === []) {
        for ($n = 1; $n <= 99; $n++) {
            if (!isset($post['shift_' . $n . '_start']) && !isset($post['shift_' . $n . '_label'])) {
                if ($n > 5) {
                    break;
                }
                continue;
            }
            $posted[$n] = [
                'start' => (string)($post['shift_' . $n . '_start'] ?? ''),
                'end' => (string)($post['shift_' . $n . '_end'] ?? ''),
                'label' => (string)($post['shift_' . $n . '_label'] ?? ''),
            ];
        }
    }
    return $posted;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($loggingOn) {
            $newId = max(0, (int)($_POST['news_schedule_id'] ?? 0));
            if ($newId > 0 && class_exists('NewsRepository', false)) {
                $news = NewsRepository::find($newId);
                if (!$news
                    || (int)($news['DISABLED'] ?? 0) !== 0
                    || (int)($news['HAS_CHILDREN'] ?? 0) !== 0
                ) {
                    throw new InvalidArgumentException(t('workweeks.settings.err.news'));
                }
            }
            WorkweeksRepository::setScheduleNewsId($newId);
            $newsId = WorkweeksRepository::scheduleNewsId();
            // Soft tables: only persist when module is on (hidden field must not reset to default).
            if (WorkweeksRepository::tablesActive() && array_key_exists('log_table_changes', $_POST)) {
                WorkweeksRepository::setLogTableChangesMode((string)$_POST['log_table_changes']);
                $logTableMode = WorkweeksRepository::logTableChangesMode();
            }
        }

        $posted = $parsePostedShifts($_POST);
        if ($posted === []) {
            $posted = WorkweeksRepository::shiftWindows();
        }

        $action = (string)($_POST['shift_action'] ?? 'save');
        $deleteId = (int)($_POST['shift_delete'] ?? 0);
        if ($deleteId > 0) {
            if (count($posted) <= 1) {
                throw new InvalidArgumentException(t('workweeks.settings.err.shift_min'));
            }
            unset($posted[$deleteId]);
            WorkweeksRepository::setShiftWindows($posted);
            $message = t('workweeks.settings.msg.shift_deleted');
        } elseif ($action === 'add') {
            $next = WorkweeksRepository::nextShiftId();
            // If next already in posted (race), bump.
            while (isset($posted[$next]) && $next < 99) {
                $next++;
            }
            if (isset($posted[$next])) {
                throw new InvalidArgumentException(t('workweeks.settings.err.shift_max'));
            }
            $posted[$next] = [
                'start' => '10:00',
                'end' => '18:00',
                'label' => (string)$next,
            ];
            WorkweeksRepository::setShiftWindows($posted);
            $message = t('workweeks.settings.msg.shift_added');
        } else {
            WorkweeksRepository::setShiftWindows($posted);
            $message = t('core.common.saved');
        }
        $shiftWindows = WorkweeksRepository::shiftWindows();

        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => true,
                'saved' => true,
                'logging_on' => $loggingOn,
                'news_id' => $newsId,
                'log_table_changes' => $logTableMode,
                'shift_windows' => $shiftWindows,
            ]);
            return;
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

$newsLabel = '';
$newsOptions = [];
if ($loggingOn) {
    $newsOptions = WorkweeksRepository::scheduleNewsOptions($newsId);
    if ($newsId > 0) {
        $newsLabel = (string)($newsOptions[$newsId] ?? '');
        if ($newsLabel === '' && class_exists('NewsRepository', false)) {
            $news = NewsRepository::find($newsId);
            if ($news) {
                $newsLabel = trim((string)($news['NAME'] ?? ''));
            }
            if ($newsLabel === '') {
                $newsLabel = '#' . $newsId;
            }
        }
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'logging_on' => $loggingOn,
        'news_id' => $newsId,
        'news_label' => $newsLabel,
        'news_options' => $newsOptions,
        'log_table_changes' => $logTableMode,
        'shift_windows' => $shiftWindows,
        'defaults' => WorkweeksRepository::defaultShiftWindows(),
    ]);
    return;
}

View::render('settings', [
    'title' => t('workweeks.menu.settings'),
    'message' => $message,
    'error' => $error,
    'loggingOn' => $loggingOn,
    'newsId' => $newsId,
    'newsLabel' => $newsLabel,
    'newsOptions' => $newsOptions,
    'logTableMode' => $logTableMode,
    'tablesOn' => WorkweeksRepository::tablesActive(),
    'shiftWindows' => $shiftWindows,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('workweeks.menu.list'), 'href' => '/workweeks'],
        ['label' => t('workweeks.menu.settings'), 'current' => true],
    ]),
], 'layout', 'workweeks');
