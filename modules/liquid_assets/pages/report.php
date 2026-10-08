<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();
$ym = (string)($_GET['ym'] ?? $_POST['ym'] ?? date('Y-m'));
$period = LiquidAssetsReport::parseYm($ym);
$ym = $period['ym'];

$syncFlash = null;
$syncPending = [];
$do = (string)($_GET['do'] ?? $_POST['do'] ?? '');

if ($do === 'sync' || $do === 'sync_confirm') {
    if (!Auth::can('liquid_assets.edit')) {
        if ($wantsJson) {
            AgentHttp::error(t('liquid_assets.err.forbidden'), 403);
            return;
        }
        http_response_code(403);
        View::render('error', ['title' => t('liquid_assets.err.forbidden'), 'message' => t('liquid_assets.err.forbidden')]);
        return;
    }
    if (!$period['is_current']) {
        $syncFlash = ['ok' => false, 'message' => t('liquid_assets.sync.past_only')];
        if ($wantsJson) {
            AgentHttp::error((string)$syncFlash['message'], 400);
            return;
        }
    } elseif ($do === 'sync_confirm') {
        $r = TinvestSyncService::applyFundRevals(Auth::userId(), $period['month_start'], $period['month_end']);
        $syncFlash = [
            'ok' => !empty($r['ok']),
            'message' => t('liquid_assets.sync.funds') . ":\n" . implode("\n", $r['msgs']),
            'msgs' => $r['msgs'],
        ];
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => !empty($r['ok']),
                'action' => 'sync_confirm',
                'ym' => $ym,
                'message' => (string)$syncFlash['message'],
                'msgs' => $r['msgs'],
            ]);
            return;
        }
    } else {
        TinvestApiClient::resetStats();
        $r = TinvestSyncService::syncAll(Auth::userId(), $period['month_start'], $period['month_end']);
        $syncPending = $r['pending'] ?? [];
        $pendingLite = [];
        foreach ($syncPending as $p) {
            $pendingLite[] = [
                'id_tinvest' => (int)($p['id_tinvest'] ?? 0),
                'id_fund' => (int)($p['id_fund'] ?? 0),
                'label' => (string)($p['label'] ?? ''),
                'asset_x' => (float)($p['asset_x'] ?? 0),
                'fund_y' => (float)($p['fund_y'] ?? 0),
                'existing_reval_id' => (int)($p['existing_reval_id'] ?? 0),
                'snapshot_id' => (int)($p['snapshot_id'] ?? 0),
                'total' => (float)($p['total'] ?? 0),
            ];
        }
        $syncFlash = [
            'ok' => !empty($r['ok']),
            'message' => t('liquid_assets.sync.snapshots')
                . ' (≤ ' . date('d.m.Y', TinvestSyncService::snapshotDt($period['month_end'])) . "):\n"
                . implode("\n", $r['lines'] !== [] ? $r['lines'] : $r['msgs']),
        ];
        if ($wantsJson) {
            AgentHttp::emit([
                'ok' => !empty($r['ok']),
                'action' => 'sync',
                'ym' => $ym,
                'message' => $syncFlash['message'],
                'lines' => $r['lines'],
                'pending' => $pendingLite,
                'need_confirm' => $pendingLite !== [],
                'api_stats' => TinvestApiClient::stats(),
            ]);
            return;
        }
        $syncPending = $r['pending'] ?? [];
    }
}

$report = LiquidAssetsReport::build($ym);

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'ym' => $ym,
        'period' => $period,
        'report' => LiquidAssetsReport::forAgent($report),
    ]);
    return;
}

View::render('report', [
    'title' => t('liquid_assets.report.title') . ' — ' . $period['title'],
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('liquid_assets.menu.report'), 'current' => true],
    ]),
    'period' => $period,
    'report' => $report,
    'syncFlash' => $syncFlash,
    'syncPending' => $syncPending,
    'canEdit' => Auth::can('liquid_assets.edit'),
], 'layout', 'liquid_assets');
