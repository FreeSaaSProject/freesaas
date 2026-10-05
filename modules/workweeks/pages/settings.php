<?php
declare(strict_types=1);

$message = '';
$error = '';
$loggingOn = WorkweeksRepository::commentsLoggingAvailable();
$newsId = $loggingOn ? WorkweeksRepository::scheduleNewsId() : 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($loggingOn) {
            $newId = max(0, (int)($_POST['news_schedule_id'] ?? 0));
            if ($newId > 0 && class_exists('NewsRepository', false)) {
                $news = NewsRepository::find($newId);
                if (!$news) {
                    throw new InvalidArgumentException(t('workweeks.settings.err.news'));
                }
            }
            WorkweeksRepository::setScheduleNewsId($newId);
            $newsId = WorkweeksRepository::scheduleNewsId();
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$newsLabel = '';
if ($newsId > 0 && class_exists('NewsRepository', false)) {
    $news = NewsRepository::find($newsId);
    if ($news) {
        $newsLabel = trim((string)($news['NAME'] ?? ''));
        if ($newsLabel === '') {
            $newsLabel = '#' . $newsId;
        }
    }
}

View::render('settings', [
    'title' => t('workweeks.menu.settings'),
    'message' => $message,
    'error' => $error,
    'loggingOn' => $loggingOn,
    'newsId' => $newsId,
    'newsLabel' => $newsLabel,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('workweeks.menu.list'), 'href' => '/workweeks'],
        ['label' => t('workweeks.menu.settings'), 'current' => true],
    ]),
], 'layout', 'workweeks');
