<?php
declare(strict_types=1);

/**
 * Public share entry: guest (no login) or staff (session required).
 * Live recompute of the whole family with allowlisted params.
 */

$token = trim((string)Router::param('token'));
$share = $token !== '' ? BiSharesRepository::findByToken($token) : null;
$wantsJson = AgentHttp::wantsJson();

$renderShareError = static function (string $titleKey, string $bodyKey, int $code = 403) use ($wantsJson): void {
    if ($wantsJson) {
        AgentHttp::error(t($bodyKey), $code);
        return;
    }
    http_response_code($code);
    View::render('share_error', [
        'title' => t($titleKey),
        'message' => t($bodyKey),
    ], 'layout_auth', 'bi');
};

if ($share === null || !BiSharesRepository::isUsable($share)) {
    $renderShareError('bi.share.expired_title', 'bi.share.expired_body', 410);
    return;
}

$audience = (string)($share['AUDIENCE'] ?? 'guest');
if ($audience === 'staff') {
        if (!Auth::check()) {
        if ($wantsJson) {
            AgentHttp::error(t('bi.share.login_body'), 401);
            return;
        }
        redirect('/login?ret=' . rawurlencode('/bi/s/' . $token));
        return;
    }
    if (!BiSharesRepository::audienceAllows($share)) {
        $renderShareError('bi.share.forbidden', 'bi.share.forbidden', 403);
        return;
    }
}

$familyId = (string)($share['FAMILY'] ?? '');
$family = ReportRegistry::get($familyId);
if ($family === null || !$family->isReady()) {
    $renderShareError('bi.err.not_ready', 'bi.err.not_ready', 422);
    return;
}

$req = array_merge($_GET, $_POST);
$doRun = !empty($req['run']) || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' || !empty($req['action']);
$action = trim((string)($req['action'] ?? ''));

// Work iframe / JSON run under share token.
if ($action === 'run' || ($doRun && ($wantsJson || !empty($req['stream'])))) {
    if (!BiSharesRepository::checkRateLimit($share)) {
        if ($wantsJson) {
            AgentHttp::error(t('bi.share.rate_limit'), 429);
            return;
        }
        ReportProgress::start(t($family->titleKey()));
        ReportProgress::note(t('bi.share.rate_limit'), '#fbbf24');
        ReportProgress::done(['ok' => false, 'error' => 'rate_limit']);
        return;
    }
    $params = ReportParams::mergeWithConstraints($req, is_array($share['params'] ?? null) ? $share['params'] : []);
    BiSharesRepository::touchRun($share);

    if ($wantsJson) {
        ReportProgress::beginCollect();
        try {
            $report = ReportRegistry::run($familyId, $params);
            AgentHttp::emit([
                'ok' => true,
                'share_id' => (int)$share['ID'],
                'family' => $familyId,
                'report' => $report,
                'html' => ReportRenderer::sectionsHtml($report),
                'stages' => ReportProgress::collectStages(),
                'expires_at' => (int)$share['DT_EXPIRE'],
            ]);
        } catch (Throwable $e) {
            AgentHttp::error(t('bi.err.run'), 500, ['detail' => $e->getMessage()]);
        }
        return;
    }

    ReportProgress::start(t($family->titleKey()));
    try {
        $report = ReportRegistry::run($familyId, $params);
        ReportProgress::done([
            'ok' => true,
            'title' => (string)($report['title'] ?? ''),
            'html' => ReportRenderer::sectionsHtml($report),
            'report' => $report,
        ]);
    } catch (Throwable $e) {
        ReportProgress::note('Error: ' . $e->getMessage(), '#f87171');
        ReportProgress::done(['ok' => false, 'error' => $e->getMessage()]);
    }
    return;
}

$params = ReportParams::mergeWithConstraints($req, is_array($share['params'] ?? null) ? $share['params'] : []);

View::render('share_view', [
    'title' => t($family->titleKey()),
    'family' => $family,
    'familyId' => $familyId,
    'params' => $params,
    'share' => $share,
    'token' => $token,
    'ready' => true,
    'readyNote' => '',
    'operators' => BiFilterOptions::staffOperators(),
    'admins' => BiFilterOptions::staffAdmins(),
    'products' => BiFilterOptions::products(),
], 'layout_auth', 'bi');
