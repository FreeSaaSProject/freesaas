<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$path = '/recons/day';
$today = date('Y-m-d');
$flashError = '';

// Prefer explicit day from query; POST writes may also pass day in the body (assistant tools).
$dayParam = trim((string)($_GET['day'] ?? ''));
if ($dayParam === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $dayParam = trim((string)($_POST['day'] ?? ''));
}
$landing = ($dayParam === '');
$showCreateTodayCta = false;

if ($landing) {
    $resolved = ReconsDayBoard::resolveLandingDay();
    $day = $resolved['day'];
    $showCreateTodayCta = !empty($resolved['show_create_today_cta']);
} else {
    $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayParam) ? $dayParam : $today;
    if ($day !== $today) {
        $todayStart = mktime(0, 0, 0) ?: time();
        $todayEnd = $todayStart + 86400 - 1;
        $showCreateTodayCta = LedgerReconciliationsRepository::countActiveForDay($todayStart, $todayEnd) < 1;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['recon_action'] ?? '') !== '') {
    if (!Auth::can('ledger.reconcile')) {
        if ($wantsJson) {
            AgentHttp::error(t('recons.day.err.forbidden'), 403);
        }
        $flashError = t('recons.day.err.forbidden');
    } else {
        $action = (string)$_POST['recon_action'];
        try {
            $boardGate = ReconsDayBoard::build($day);
            if (empty($boardGate['is_editable'])) {
                throw new InvalidArgumentException(t('recons.day.err.not_editable'));
            }
            $dayStart = (int)$boardGate['day_start'];
            $dayEnd = (int)$boardGate['day_end'];
            $isToday = !empty($boardGate['is_today']);
            $reconAccountId = (int)($_POST['recon_account_id'] ?? 0);
            $reconId = 0;
            if ($action === 'save') {
                $raw = LedgerReconciliationsRepository::normalizeBankBalance((string)($_POST['bank_balance'] ?? ''));
                if ($raw === '' || !is_numeric($raw)) {
                    throw new InvalidArgumentException(t('ledger.recon.err.bank'));
                }
                $dt = null;
                $systemSnap = null;
                if (!$isToday) {
                    // Keep recon on the editable past day; SYSTEM = balance as of that day.
                    $todayStart = mktime(0, 0, 0) ?: time();
                    $offset = time() - $todayStart;
                    if ($offset < 0) {
                        $offset = 0;
                    }
                    if ($offset > 86399) {
                        $offset = 86399;
                    }
                    $dt = $dayStart + $offset;
                    $asOf = LedgerMovesRepository::balancesByAccount($dayEnd);
                    $systemSnap = (float)($asOf[$reconAccountId] ?? 0.0);
                }
                $reconId = LedgerReconciliationsRepository::create(
                    $reconAccountId,
                    (float)$raw,
                    Auth::userId(),
                    $dt,
                    $systemSnap
                );
            } elseif ($action === 'update') {
                $raw = LedgerReconciliationsRepository::normalizeBankBalance((string)($_POST['bank_balance'] ?? ''));
                if ($raw === '' || !is_numeric($raw)) {
                    throw new InvalidArgumentException(t('ledger.recon.err.bank'));
                }
                $reconId = (int)($_POST['recon_id'] ?? 0);
                // Past latest day: keep SYSTEM snapshot from recon date; today may refresh live.
                LedgerReconciliationsRepository::updateBank(
                    $reconId,
                    $reconAccountId,
                    (float)$raw,
                    $isToday
                );
            } elseif ($action === 'delete') {
                $reconId = (int)($_POST['recon_id'] ?? 0);
                LedgerReconciliationsRepository::deleteInDay($reconId, $reconAccountId, $dayStart, $dayEnd);
            } else {
                throw new InvalidArgumentException(t('ledger.recon.err.action'));
            }
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'action' => $action,
                    'recon_id' => $reconId,
                    'account_id' => $reconAccountId,
                    'board' => ReconsDayBoard::build($day),
                ]);
                return;
            }
            redirect(ListQuery::url($path, ['day' => $day], []));
        } catch (Throwable $e) {
            if ($wantsJson) {
                AgentHttp::error($e->getMessage(), 400);
            }
            $flashError = $e->getMessage();
        }
    }
}

$board = ReconsDayBoard::build($day);
$canRecon = Auth::can('ledger.reconcile');
$canEdit = $canRecon && !empty($board['is_editable']);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'board' => $board,
        'show_create_today_cta' => $showCreateTodayCta,
        'can_recon' => $canRecon,
        'can_edit' => $canEdit,
    ]);
    return;
}

Assets::need('ledger');

View::render('day', [
    'title' => t('recons.day.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('recons.menu.day'), 'current' => true],
    ]),
    'path' => $path,
    'day' => $board['day'],
    'prevDay' => $board['prev_day'],
    'nextDay' => $board['next_day'],
    'today' => $board['today'],
    'isToday' => $board['is_today'],
    'isLatestReconDay' => !empty($board['is_latest_recon_day']),
    'staleVsToday' => !empty($board['stale_vs_today']),
    'rows' => $board['rows'],
    'summary' => $board['summary'],
    'showCreateTodayCta' => $showCreateTodayCta,
    'canRecon' => $canRecon,
    'canEdit' => $canEdit,
    'flashError' => $flashError,
], 'layout', 'recons');
