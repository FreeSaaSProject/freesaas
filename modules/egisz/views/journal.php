<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $rows */
/** @var string $status */
/** @var list<string> $statuses */
?>
<section class="egisz-journal">
    <form method="get" action="/egisz/journal" class="egisz-filters">
        <label>
            <?= e(t('egisz.journal.filter.status')) ?>
            <select name="status">
                <option value="">—</option>
                <?php foreach ($statuses as $s): ?>
                    <option value="<?= e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit"><?= e(t('egisz.journal.apply')) ?></button>
    </form>
    <?php if (Auth::can('egisz.build')): ?>
        <form method="post" action="/egisz/eventlog/poll" class="egisz-filters">
            <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
            <button type="submit" class="btn-secondary"><?= e(t('egisz.eventlog.poll')) ?></button>
        </form>
        <p><a href="/egisz/case/send"><?= e(t('egisz.case.title')) ?></a></p>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="empty"><?= e(t('egisz.journal.empty')) ?></p>
    <?php else: ?>
        <table class="data">
            <thead>
            <tr>
                <th><?= e(t('egisz.journal.col.id')) ?></th>
                <th><?= e(t('egisz.journal.col.uid')) ?></th>
                <th><?= e(t('egisz.journal.col.kind')) ?></th>
                <th><?= e(t('egisz.journal.col.status')) ?></th>
                <th><?= e(t('egisz.journal.col.client')) ?></th>
                <th><?= e(t('egisz.journal.col.created')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['ID'] ?></td>
                    <td><code><?= e((string)$r['LOCAL_UID']) ?></code></td>
                    <td><?= e((string)$r['KIND']) ?></td>
                    <td><?= e((string)$r['STATUS']) ?></td>
                    <td><?= (int)$r['ID_CLIENT'] ?></td>
                    <td><?= (int)$r['DT_CREATE'] > 0 ? e(date('Y-m-d H:i', (int)$r['DT_CREATE'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
