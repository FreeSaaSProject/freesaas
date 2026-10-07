<?php
declare(strict_types=1);

$canGenerate = Auth::capability('SUPERADMIN_ACCESS') && Auth::can('staff_reports.edit');
$day = trim((string)($_GET['day'] ?? $_POST['day'] ?? ''));
$emp = max(0, (int)($_GET['emp'] ?? $_POST['emp'] ?? 0));
$year = max(2000, (int)($_GET['year'] ?? date('Y')));
$monthNum = max(1, min(12, (int)($_GET['month_num'] ?? date('n'))));
$doMonth = !empty($_GET['month']) || !empty($_POST['month']);
$run = !empty($_GET['run']) || !empty($_POST['run']) || AgentHttp::wantsJson();

if ($run) {
    if (!$canGenerate) {
        if (AgentHttp::wantsJson()) {
            AgentHttp::error(t('staff_reports.generate.denied'), 403);
        }
        http_response_code(403);
        echo '<p>' . e(t('staff_reports.generate.denied')) . '</p>';
        return;
    }

    if (AgentHttp::wantsJson()) {
        StaffReportRunner::resetLog();
        if ($doMonth) {
            $out = StaffReportRunner::runMonth($year, $monthNum);
            AgentHttp::emit(['ok' => true, 'mode' => 'month', 'result' => $out, 'log' => StaffReportRunner::logLines()]);
        }
        if ($day === '') {
            AgentHttp::error(t('staff_reports.err.bad_day'));
        }
        $out = StaffReportRunner::runDay($day, $emp);
        if (!empty($out['error'])) {
            AgentHttp::error((string)$out['error'], 400, ['result' => $out]);
        }
        AgentHttp::emit(['ok' => true, 'mode' => 'day', 'result' => $out, 'log' => StaffReportRunner::logLines()]);
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('X-Accel-Buffering: no');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
        . e(t('staff_reports.generate.title')) . '</title></head><body>';
    echo '<h1>' . e(t('staff_reports.generate.title')) . '</h1>';
    echo '<pre style="white-space:pre-wrap;font:14px/1.4 monospace">';
    @ob_implicit_flush(true);
    StaffReportRunner::setLogSink(static function (string $msg): void {
        echo e($msg) . "\n";
        @flush();
    });
    StaffReportRunner::resetLog();
    echo e(t('staff_reports.generate.step_start')) . "\n";
    @flush();
    if ($doMonth) {
        $out = StaffReportRunner::runMonth($year, $monthNum);
        echo "\n" . e(json_encode($out['stats'] ?? [], JSON_UNESCAPED_UNICODE)) . "\n";
    } else {
        if ($day === '') {
            echo e(t('staff_reports.err.bad_day'));
        } else {
            $out = StaffReportRunner::runDay($day, $emp);
            if (!empty($out['error'])) {
                echo e((string)$out['error']) . "\n";
            } else {
                echo "\n" . e(json_encode($out['stats'] ?? [], JSON_UNESCAPED_UNICODE)) . "\n";
            }
        }
    }
    StaffReportRunner::setLogSink(null);
    echo '</pre><p><b>' . e(t('staff_reports.generate.step_done')) . '</b></p>';
    echo '<p><a href="/audio/calendar">' . e(t('audio_reports.menu.calendar')) . '</a></p>';
    echo '</body></html>';
    return;
}

View::render('generate', [
    'title' => t('staff_reports.generate.title'),
    'day' => $day !== '' ? $day : date('Y-m-d', StaffReportRunner::lastAllowedDayStart()),
    'emp' => $emp,
    'year' => $year,
    'month' => $monthNum,
    'canGenerate' => $canGenerate,
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('audio.menu.group'), 'href' => '/audio'],
        ['label' => t('staff_reports.menu.generate'), 'current' => true],
    ]),
], 'layout', 'staff_reports');
