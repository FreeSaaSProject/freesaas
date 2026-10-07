<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$uid = Auth::userId();
$action = (string)($_POST['action'] ?? '');
$commentId = (int)($_POST['comment_id'] ?? 0);
$newsId = (int)($_POST['news_id'] ?? 0);

try {
    if ($commentId < 1) {
        throw new InvalidArgumentException(t('contracts.err.no_comment'));
    }
    $c = CommentsRepository::find($commentId);
    if (!$c) {
        throw new RuntimeException(t('comments.err.not_found'));
    }
    $newsId = $newsId > 0 ? $newsId : (int)$c['ID_OBJECT'];
    $news = NewsRepository::find($newsId);
    if (!$news || !NewsRepository::canView($news, $uid)) {
        throw new RuntimeException(t('contracts.err.ticket_unavailable'));
    }

    switch ($action) {
        case 'accept_offer':
            ContractsRepository::acceptOffer($commentId, $uid);
            break;
        case 'accept_work':
            ContractsRepository::acceptWork($commentId, $uid);
            break;
        case 'refuse':
            ContractsRepository::refuse($commentId, $uid);
            break;
        case 'cancel_work_accept':
            ContractsRepository::cancelWorkAccept($commentId, $uid);
            break;
        default:
            throw new InvalidArgumentException(t('contracts.err.unknown_action'));
    }
    if ($wantsJson) {
        AgentHttp::emit([
            'ok' => true,
            'action' => $action,
            'comment_id' => $commentId,
            'news_id' => $newsId,
        ]);
        return;
    }
    redirect('/news/' . $newsId . '?ok=1#c' . $commentId);
} catch (Throwable $e) {
    if ($wantsJson) {
        AgentHttp::error($e->getMessage(), 400);
        return;
    }
    $nid = $newsId > 0 ? $newsId : 0;
    if ($nid > 0) {
        redirect('/news/' . $nid . '?err=' . rawurlencode($e->getMessage()) . '#c' . $commentId);
    }
    http_response_code(400);
    View::render('error', [
        'title' => t('contracts.error_title'),
        'message' => $e->getMessage(),
    ]);
}
