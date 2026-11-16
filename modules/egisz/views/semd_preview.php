<?php
declare(strict_types=1);
/** @var SemdDocument $doc */
/** @var int $instanceId */
/** @var string $kind */
?>
<section class="egisz-preview">
    <form method="get" action="/egisz/semd/preview" class="egisz-filters">
        <label>
            instance_id
            <input type="number" name="instance_id" value="<?= (int)$instanceId ?>" min="1" required>
        </label>
        <label>
            kind
            <select name="kind">
                <option value="consult"<?= $kind === 'consult' ? ' selected' : '' ?>>consult</option>
                <option value="manip"<?= $kind === 'manip' ? ' selected' : '' ?>>manip</option>
            </select>
        </label>
        <button type="submit"><?= e(t('egisz.preview.title')) ?></button>
    </form>

    <?php if ($doc->errors !== []): ?>
        <div class="egisz-errors">
            <ul>
                <?php foreach ($doc->errors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if ($doc->warnings !== []): ?>
        <div class="egisz-warnings">
            <ul>
                <?php foreach ($doc->warnings as $w): ?>
                    <li><?= e($w) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <p><code>localUid=<?= e($doc->localUid) ?></code> · <code><?= e($doc->documentOid) ?></code></p>

    <?php if ($doc->diagnoses !== []): ?>
        <h3>ICD</h3>
        <ul>
            <?php foreach ($doc->diagnoses as $d): ?>
                <li><code><?= e($d['code']) ?></code>
                    <?= e($d['name']) ?>
                    <?= empty($d['known']) ? '⚠' : '' ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($doc->xml !== ''): ?>
        <pre class="egisz-xml"><?= e($doc->xml) ?></pre>
        <form method="post" action="/egisz/semd/preview">
            <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
            <input type="hidden" name="instance_id" value="<?= (int)$instanceId ?>">
            <input type="hidden" name="kind" value="<?= e($kind) ?>">
            <input type="hidden" name="save_outbox" value="1">
            <button type="submit"><?= e(t('egisz.preview.save_outbox')) ?></button>
        </form>
    <?php endif; ?>
</section>
