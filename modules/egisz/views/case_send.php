<?php
declare(strict_types=1);
/** @var int $instanceId */
/** @var string $kind */
?>
<section class="egisz-case-send">
    <p class="egisz-alpha"><?= e(t('egisz.note.unsigned')) ?></p>
    <form method="post" action="/egisz/case/send">
        <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
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
        <label class="check">
            <input type="checkbox" name="update" value="1">
            <?= e(t('egisz.case.update')) ?>
        </label>
        <button type="submit"><?= e(t('egisz.case.send')) ?></button>
    </form>
</section>
