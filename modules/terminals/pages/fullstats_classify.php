<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

if (!TerminalsConfig::analyticsEnabled()) {
    if ($wantsJson) {
        AgentHttp::error(t('terminals.err.disabled'), 404);
        return;
    }
    http_response_code(404);
    View::render('error', ['title' => t('core.common.not_found'), 'message' => t('terminals.err.disabled')]);
    return;
}

if (!Auth::can('terminals.edit')) {
    if ($wantsJson) {
        AgentHttp::error(t('terminals.fullstats.classify.denied'), 403);
        return;
    }
    http_response_code(403);
    View::render('error', ['title' => t('core.common.forbidden'), 'message' => t('terminals.fullstats.classify.denied')]);
    return;
}

$message = '';
$error = '';
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$proposals = TicketTypeClassifier::loadProposals();
$types = FullstatsRepository::unclassifiedTypes($q);
$llmReady = TicketTypeClassifier::llmReady();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'propose') {
            $types = FullstatsRepository::unclassifiedTypes($q);
            $proposals = TicketTypeClassifier::propose($types);
            $message = t('terminals.fullstats.classify.proposed', ['n' => (string)count($proposals)]);
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true, 'proposals' => $proposals, 'llm' => $llmReady, 'q' => $q]);
                return;
            }
        } elseif ($action === 'apply') {
            $posted = $_POST['rows'] ?? [];
            if (!is_array($posted)) {
                $posted = [];
            }
            $applied = 0;
            $skipped = 0;
            $rowsAffected = 0;
            foreach ($posted as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $ticketType = trim((string)($row['ticket_type'] ?? ''));
                $idType = (int)($row['id_type'] ?? 0);
                $isLimited = (int)($row['is_limited'] ?? 0);
                $isPayer = (int)($row['is_payer'] ?? 0);
                $isOasis = (int)($row['is_oasis'] ?? 0);
                if ($ticketType === '' || $idType < 1 || $isLimited < 1 || $isPayer < 1 || $isOasis < 1) {
                    $skipped++;
                    continue;
                }
                $n = FullstatsRepository::classifyByType($ticketType, $idType, $isLimited, $isPayer, $isOasis);
                $rowsAffected += $n;
                $applied++;
            }
            TicketTypeClassifier::clearProposals();
            $proposals = [];
            $types = FullstatsRepository::unclassifiedTypes($q);
            $message = t('terminals.fullstats.classify.applied', [
                'types' => (string)$applied,
                'rows' => (string)$rowsAffected,
                'skipped' => (string)$skipped,
            ]);
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'applied_types' => $applied,
                    'rows' => $rowsAffected,
                    'skipped' => $skipped,
                    'remaining' => count($types),
                ]);
                return;
            }
        } elseif ($action === 'clear') {
            TicketTypeClassifier::clearProposals();
            $proposals = [];
            $message = t('terminals.fullstats.classify.cleared');
            if ($wantsJson) {
                AgentHttp::emit(['ok' => true]);
                return;
            }
        } else {
            throw new InvalidArgumentException(t('terminals.fullstats.classify.unknown_action'));
        }
    } catch (Throwable $e) {
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
    }
}

if ($wantsJson) {
    AgentHttp::emit([
        'ok' => true,
        'types' => $types,
        'proposals' => $proposals,
        'llm' => $llmReady,
        'q' => $q,
    ]);
    return;
}

View::render('fullstats_classify', [
    'title' => t('terminals.fullstats.classify.title'),
    'breadcrumbs' => Breadcrumbs::withHome([
        ['label' => t('terminals.menu.fullstats'), 'href' => '/terminals/fullstats'],
        ['label' => t('terminals.fullstats.classify.title'), 'current' => true],
    ]),
    'types' => $types,
    'proposals' => $proposals,
    'llmReady' => $llmReady,
    'q' => $q,
    'message' => $message,
    'error' => $error,
], 'layout', 'terminals');
