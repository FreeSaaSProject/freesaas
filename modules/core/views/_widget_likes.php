<?php
declare(strict_types=1);
/** @var list<array{type:string,obj_id:int,href:string,news_type:int,title:string,snippet:string,likers:list<int>}> $groups */
/** @var int $beforeTs */
$beforeTs = (int)($beforeTs ?? time());
$groups = is_array($groups ?? null) ? $groups : [];
?>
<div class="widget-likes" data-likes-root data-before="<?= (int)$beforeTs ?>">
  <div class="widget-likes-head">
    <button type="button" class="btn likes-mark-viewed"><?= te('core.widget.likes.mark_viewed') ?></button>
  </div>
  <ul class="widget-likes-list">
    <?php foreach ($groups as $g): ?>
      <?php
        $href = (string)($g['href'] ?? '#');
        $likers = is_array($g['likers'] ?? null) ? $g['likers'] : [];
        $badges = '';
        $seen = [];
        foreach ($likers as $uid) {
            $uid = (int)$uid;
            if ($uid < 1 || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $badges .= UsersRepository::badgeHtml($uid, true, false) . ' ';
        }
        $badges = trim($badges);
      ?>
      <li class="widget-likes-item">
        <?php if (($g['type'] ?? '') === 'base_comments'): ?>
          <?= t('core.widget.likes.line_comment', [
              'link' => '<a href="' . e($href) . '">' . te('core.widget.likes.to_object') . '</a>',
              'snippet' => e((string)($g['snippet'] ?? '')),
              'likers' => $badges,
          ]) ?>
        <?php else: ?>
          <?php
            $typeId = (int)($g['news_type'] ?? 0);
            $kind = in_array($typeId, [0, 1, 2, 3], true)
                ? t('core.news.type.' . $typeId)
                : t('core.news.type_unknown', ['n' => $typeId]);
            $title = (string)($g['title'] ?? '');
          ?>
          <?= t('core.widget.likes.line_news', [
              'kind' => e($kind),
              'link' => '<a href="' . e($href) . '">' . e($title !== '' ? $title : ('#' . (int)($g['obj_id'] ?? 0))) . '</a>',
              'likers' => $badges,
          ]) ?>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <p class="widget-likes-status muted" hidden></p>
</div>
