<?php
declare(strict_types=1);

/**
 * Enable / seed CMS «Отзывы» with official Yandex + 2GIS review widgets.
 * Reuses legacy gbook row when present; otherwise inserts reviewsart + /reviews/.
 *
 * LikeSpa cards:
 * - Yandex org 103544693069
 * - 2GIS firm 70000001056369250
 *
 * @return array{updated:int,inserted:int,skipped:int}
 */
return static function (): array {
    if (!Database::tableExists('base_page_categories')) {
        return ['updated' => 0, 'inserted' => 0, 'skipped' => 1];
    }

    $html = <<<'HTML'
<p>Отзывы гостей LikeSpa на Яндекс&nbsp;Картах и в&nbsp;2ГИС. Оставить отзыв можно прямо в&nbsp;виджете или на&nbsp;странице площадки.</p>
<div class="reviews-widgets">
  <div class="reviews-widgets__col reviews-widgets__yandex">
    <h2>Яндекс&nbsp;Карты</h2>
    <iframe src="https://yandex.ru/maps-reviews-widget/103544693069?comments" width="100%" height="600" frameborder="0" title="Отзывы на Яндекс Картах" loading="lazy" style="border:none;width:100%;height:600px;"></iframe>
  </div>
  <div class="reviews-widgets__col reviews-widgets__twogis">
    <h2>2ГИС</h2>
    <p><a href="https://2gis.ru/novokujbyshevsk/firm/70000001056369250/tab/reviews" target="_blank" rel="noopener noreferrer" title="Отзывы в 2ГИС"><img src="https://widget.2gis.ru/api/content/?org_id=70000001056369250&amp;branch_id=70000001056369250&amp;rating=4.9&amp;reviews_count=184&amp;size=medium&amp;theme=light" width="150" height="50" alt="Рейтинг LikeSpa в 2ГИС" loading="lazy"></a></p>
    <p><a href="https://2gis.ru/novokujbyshevsk/firm/70000001056369250/tab/reviews" target="_blank" rel="noopener noreferrer">Все отзывы на 2ГИС</a></p>
  </div>
</div>
HTML;

    $pdo = Database::pdo();
    $hasHost = Database::columnExists('base_page_categories', 'site_host_id');

    $st = $pdo->prepare(
        'SELECT id, content, linkRW, enabled FROM base_page_categories
         WHERE link = ? OR link = ? OR linkRW = ? OR linkRW = ?
         ORDER BY id ASC LIMIT 1'
    );
    $st->execute(['gbook', 'reviewsart', '/reviews/', '/gbook/']);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (is_array($row)) {
        $id = (int)$row['id'];
        $content = trim((string)($row['content'] ?? ''));
        $sets = ['enabled = 1', 'linkRW = ?', 'parent_to = 0', 'name = ?'];
        $params = ['/reviews/', 'Отзывы'];
        // Refresh when empty, missing Yandex widget, or still on old 2GIS iframe badge.
        $needsContent = $content === ''
            || strpos($content, 'maps-reviews-widget') === false
            || strpos($content, 'widget.2gis.ru/api/widget') !== false;
        if ($needsContent) {
            $sets[] = 'content = ?';
            $params[] = $html;
        }
        $params[] = $id;
        $upd = $pdo->prepare('UPDATE base_page_categories SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $upd->execute($params);
        return ['updated' => 1, 'inserted' => 0, 'skipped' => 0];
    }

    $cols = ['name', 'num', 'parent_to', 'content', 'link', 'linkRW', 'enabled', 'datas'];
    $vals = ['Отзывы', 150, 0, $html, 'reviewsart', '/reviews/', 1, time()];
    if ($hasHost) {
        $cols[] = 'site_host_id';
        $vals[] = 0;
    }
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $ins = $pdo->prepare(
        'INSERT INTO base_page_categories (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')'
    );
    $ins->execute($vals);

    return ['updated' => 0, 'inserted' => 1, 'skipped' => 0];
};
