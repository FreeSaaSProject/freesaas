<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CommentsRepository.php';
require_once base_path('modules/core/lib/LikesRepository.php');

Assets::register('comments', [
    'js' => ['comments.js'],
]);

EventBus::on('comments.counts', static function ($payload) {
    if (!is_array($payload)) {
        $payload = [];
    }
    $ids = $payload['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $payload['counts'] = CommentsRepository::countsForNews($ids);
    return $payload;
});

EventBus::on('core.news_item.meta', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $row = $payload['row'] ?? null;
    if (!is_array($row)) {
        return $payload;
    }
    $id = (int)($row['ID'] ?? 0);
    $uid = (int)($payload['uid'] ?? 0);
    $isFolder = !empty($payload['is_folder']);

    $commentsFlat = CommentsRepository::forNews($id, true);
    $visible = [];
    $userIdsWarm = [];
    foreach ($commentsFlat as $c) {
        $hidden = (int)$c['DISABLED'] === 1;
        if ($hidden && !CommentsRepository::canModerate($c, $uid)) {
            continue;
        }
        $visible[] = $c;
        $userIdsWarm[] = (int)$c['ID_USER'];
        if ((int)($c['ID_ACCEPTOR'] ?? 0) > 0) {
            $userIdsWarm[] = (int)$c['ID_ACCEPTOR'];
        }
    }
    if ($userIdsWarm) {
        UsersRepository::preload($userIdsWarm);
    }
    // Sibling modules may batch-enrich (e.g. contracts↔payments mismatch) before tree render.
    EventBus::emit('comments.visible', [
        'news_id' => $id,
        'comments' => $visible,
        'uid' => $uid,
    ]);
    $commentIds = array_map(static function ($c) {
        return (int)$c['ID'];
    }, $visible);
    $commentCount = count($visible);
    $payload['_comments'] = [
        'tree' => CommentsRepository::buildTree($visible),
        'count' => $commentCount,
        'likes' => LikesRepository::counts('base_comments', $commentIds),
        'liked' => LikesRepository::likedByUser('base_comments', $commentIds, $uid),
    ];
    $payload['comment_count'] = $commentCount;

    if (!$isFolder) {
        $href = !empty($row['COMMENTS_LOCKED']) ? '#comments' : '#comment-form';
        $payload['actions_html'] = ($payload['actions_html'] ?? '')
            . '<a class="btn-secondary" href="' . e($href) . '" id="jump-comments">↓ ' . te('comments.jump') . '</a>';
    }

    return $payload;
});

EventBus::on('core.news_item.section', static function ($payload) {
    if (!is_array($payload)) {
        return $payload;
    }
    $row = $payload['row'] ?? null;
    if (!is_array($row) || !empty($payload['is_folder'])) {
        return $payload;
    }

    $meta = $payload['_comments_meta'] ?? null;
    $bundle = is_array($meta) ? ($meta['_comments'] ?? null) : null;
    if (!is_array($bundle)) {
        $bundle = [
            'tree' => [],
            'count' => 0,
            'likes' => [],
            'liked' => [],
        ];
    }

    $data = [
        'row' => $row,
        'tree' => $bundle['tree'],
        'commentCount' => (int)$bundle['count'],
        'commentLikes' => $bundle['likes'],
        'commentLiked' => $bundle['liked'],
        'uid' => (int)($payload['uid'] ?? 0),
        'canManageTicket' => !empty($payload['can_manage_ticket']),
        'statuses' => $payload['statuses'] ?? [],
        'status' => $payload['status'] ?? null,
        'respIds' => $payload['resp_ids'] ?? [],
        'staff' => $payload['staff'] ?? [],
        'contractLocked' => !empty($payload['contract_locked']),
        'needsSign' => !empty($payload['needs_sign']),
    ];

    extract($data, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/views/section.php';
    $html = (string)ob_get_clean();
    $payload['html'] = ($payload['html'] ?? '') . $html;
    return $payload;
});

EventBus::on('core.news_item.handle_post', static function ($payload) {
    if (!is_array($payload) || !empty($payload['handled'])) {
        return $payload;
    }

    $action = (string)($payload['action'] ?? '');
    $row = $payload['row'] ?? null;
    $id = (int)($payload['id'] ?? 0);
    $uid = (int)($payload['uid'] ?? 0);
    $isFolder = !empty($payload['is_folder']);
    $post = is_array($payload['post'] ?? null) ? $payload['post'] : [];

    if (!is_array($row) || $id < 1 || $uid < 1) {
        return $payload;
    }

    try {
        if ($action === 'like') {
            $likeType = (string)($post['like_type'] ?? '');
            $likeId = (int)($post['like_id'] ?? 0);
            if ($likeType !== 'base_comments' || $likeId < 1) {
                return $payload;
            }
            $c = CommentsRepository::find($likeId);
            if ($c && (int)$c['ID_OBJECT'] === $id) {
                LikesRepository::toggle('base_comments', $likeId, $uid, (int)$c['ID_USER']);
            }
            $payload['handled'] = true;
            $payload['redirect'] = '/news/' . $id . '#c' . $likeId;
            return $payload;
        }

        if ($action === 'hide_comment' || $action === 'show_comment') {
            $cid = (int)($post['comment_id'] ?? 0);
            $c = CommentsRepository::find($cid);
            if (!$c || (int)$c['ID_OBJECT'] !== $id) {
                throw new RuntimeException(t('comments.err.not_found'));
            }
            if (!CommentsRepository::canModerate($c, $uid)) {
                throw new RuntimeException(t('comments.err.moderate_denied'));
            }
            CommentsRepository::setDisabled($cid, $action === 'hide_comment');
            $payload['handled'] = true;
            $payload['comment_id'] = $cid;
            $payload['comment'] = CommentsRepository::forAgent(CommentsRepository::find($cid));
            $payload['redirect'] = '/news/' . $id . '#c' . $cid;
            return $payload;
        }

        if ($action === 'color_comment') {
            $cid = (int)($post['comment_id'] ?? 0);
            $c = CommentsRepository::find($cid);
            if (!$c || (int)$c['ID_OBJECT'] !== $id) {
                throw new RuntimeException(t('comments.err.not_found'));
            }
            if (!CommentsRepository::canModerate($c, $uid)) {
                throw new RuntimeException(t('comments.err.moderate_denied'));
            }
            CommentsRepository::cycleColor($cid);
            $payload['handled'] = true;
            $payload['comment_id'] = $cid;
            $payload['comment'] = CommentsRepository::forAgent(CommentsRepository::find($cid));
            $payload['redirect'] = '/news/' . $id . '#c' . $cid;
            return $payload;
        }

        if ($action === 'delete_comment') {
            $cid = (int)($post['comment_id'] ?? 0);
            $c = CommentsRepository::find($cid);
            if (!$c || (int)$c['ID_OBJECT'] !== $id) {
                throw new RuntimeException(t('comments.err.not_found'));
            }
            $del = CommentsRepository::deleteIfAllowed($cid, $uid);
            if ($del !== true) {
                throw new RuntimeException(is_string($del) ? $del : t('comments.err.delete_denied'));
            }
            $payload['handled'] = true;
            $payload['comment_id'] = $cid;
            $payload['comment'] = null;
            $payload['redirect'] = '/news/' . $id . '#comments';
            return $payload;
        }

        if ($action === 'edit_comment') {
            $cid = (int)($post['comment_id'] ?? 0);
            $text = (string)($post['text'] ?? '');
            $c = CommentsRepository::find($cid);
            if (!$c || (int)$c['ID_OBJECT'] !== $id) {
                throw new RuntimeException(t('comments.err.not_found'));
            }
            if (!Auth::isSuperAdmin() && (int)$c['ID_USER'] !== $uid) {
                throw new RuntimeException(t('comments.err.edit_denied'));
            }
            $extra = [];
            $prepared = EventBus::emit('comments.prepare_edit', [
                'comment' => $c,
                'row' => $row,
                'id' => $id,
                'uid' => $uid,
                'post' => $post,
                'text' => $text,
                'extra' => $extra,
                'error' => null,
            ]);
            if (is_array($prepared)) {
                if (!empty($prepared['error'])) {
                    throw new RuntimeException((string)$prepared['error']);
                }
                $text = (string)($prepared['text'] ?? $text);
                $extra = is_array($prepared['extra'] ?? null) ? $prepared['extra'] : $extra;
            }
            if (html_ugc_reject_reason($text) !== null) {
                throw new InvalidArgumentException(t('comments.err.unsafe_html'));
            }
            if ($extra !== []) {
                CommentsRepository::updateFields($cid, $text, $extra);
            } else {
                CommentsRepository::updateText($cid, $text);
            }
            $payload['handled'] = true;
            $payload['comment_id'] = $cid;
            $payload['comment'] = CommentsRepository::forAgent(CommentsRepository::find($cid));
            $payload['redirect'] = '/news/' . $id . '#c' . $cid;
            return $payload;
        }

        if ($action === 'comment') {
            if ($isFolder) {
                return $payload;
            }
            if (!empty($row['COMMENTS_LOCKED'])) {
                throw new RuntimeException(t('comments.err.locked'));
            }

            $text = (string)($post['text'] ?? '');
            $replyPid = (int)($post['reply_pid'] ?? 0);
            $extra = ['PID' => $replyPid];

            $prepared = EventBus::emit('comments.prepare_add', [
                'row' => $row,
                'id' => $id,
                'uid' => $uid,
                'post' => $post,
                'text' => $text,
                'extra' => $extra,
                'status_up' => false,
                'error' => null,
            ]);
            if (!is_array($prepared)) {
                $prepared = [];
            }
            if (!empty($prepared['error'])) {
                throw new RuntimeException((string)$prepared['error']);
            }

            $text = (string)($prepared['text'] ?? $text);
            $extra = is_array($prepared['extra'] ?? null) ? $prepared['extra'] : $extra;
            $statusUp = !empty($prepared['status_up']);
            if (!empty($prepared['row']) && is_array($prepared['row'])) {
                $payload['row'] = $prepared['row'];
                $row = $prepared['row'];
            }

            if (html_ugc_reject_reason($text) !== null) {
                throw new InvalidArgumentException(t('comments.err.unsafe_html'));
            }
            if (trim($text) === '' && !$statusUp) {
                throw new InvalidArgumentException(t('comments.err.text_required'));
            }
            if (trim($text) === '') {
                $text = '[обновление]';
            }
            $extra['STATUS_UPDATE'] = $statusUp ? 1 : 0;
            $newCid = CommentsRepository::add($id, $uid, $text, $extra);

            // Oper newSign: acknowledge SIGN_REQ via a comment + checkbox
            if (!empty($post['new_sign'])
                && !empty($row['SIGN_REQ'])
                && (int)($row['HAS_CHILDREN'] ?? 0) !== 1
                && class_exists('NewsRepository', false)
            ) {
                NewsRepository::markSigned($id, $uid);
            }

            $payload['handled'] = true;
            $payload['comment_id'] = $newCid;
            $payload['comment'] = CommentsRepository::forAgent(CommentsRepository::find($newCid));
            $payload['redirect'] = '/news/' . $id . '?ok=1#c' . $newCid;
            return $payload;
        }
    } catch (Throwable $e) {
        $payload['handled'] = true;
        $payload['error'] = $e->getMessage();
    }

    return $payload;
});
