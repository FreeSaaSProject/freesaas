<?php
declare(strict_types=1);
/** @var array<string,mixed> $cfg */
/** @var list<string> $operators */
/** @var list<string> $n3Envs */
?>
<form method="post" action="/egisz/settings" class="form-grid form-wide egisz-settings">
    <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>

    <p class="form-hint"><?= e(t('egisz.settings.deploy_hint')) ?></p>

    <fieldset>
        <legend><?= e(t('egisz.settings.sec_operator')) ?></legend>
        <label>
            <?= e(t('egisz.settings.operator')) ?>
            <select name="operator">
                <?php foreach ($operators as $op): ?>
                    <option value="<?= e($op) ?>"<?= ($cfg['operator'] ?? '') === $op ? ' selected' : '' ?>>
                        <?= e(t('egisz.settings.op.' . $op)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="form-hint"><?= e(t('egisz.settings.operator_help')) ?></p>
    </fieldset>

    <fieldset>
        <legend><?= e(t('egisz.settings.sec_org')) ?></legend>
        <p class="form-hint"><?= e(t('egisz.settings.org_hint')) ?></p>
        <label>
            <?= e(t('egisz.settings.mo_oid')) ?>
            <input type="text" name="mo_oid" value="<?= e((string)($cfg['mo_oid'] ?? '')) ?>" maxlength="128" autocomplete="off" spellcheck="false">
        </label>
        <label>
            <?= e(t('egisz.settings.department_oid')) ?>
            <input type="text" name="department_oid" value="<?= e((string)($cfg['department_oid'] ?? '')) ?>" maxlength="128" autocomplete="off" spellcheck="false">
        </label>
        <label>
            <?= e(t('egisz.settings.department_name')) ?>
            <input type="text" name="department_name" value="<?= e((string)($cfg['department_name'] ?? '')) ?>" maxlength="255">
        </label>
        <label>
            <?= e(t('egisz.settings.semd_consult_oid')) ?>
            <input type="text" name="semd_consult_oid" value="<?= e((string)($cfg['semd_consult_oid'] ?? '')) ?>" maxlength="128" spellcheck="false">
        </label>
        <label>
            <?= e(t('egisz.settings.semd_manip_oid')) ?>
            <input type="text" name="semd_manip_oid" value="<?= e((string)($cfg['semd_manip_oid'] ?? '')) ?>" maxlength="128" spellcheck="false">
        </label>
    </fieldset>

    <fieldset>
        <legend><?= e(t('egisz.settings.n3_sec')) ?></legend>
        <p class="form-hint"><?= e(t('egisz.settings.n3_ukep_note')) ?></p>
        <label>
            <?= e(t('egisz.settings.n3_env')) ?>
            <select name="n3_env">
                <?php foreach ($n3Envs as $env): ?>
                    <option value="<?= e($env) ?>"<?= ($cfg['n3_env'] ?? '') === $env ? ' selected' : '' ?>><?= e($env) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <?= e(t('egisz.settings.n3_guid')) ?>
            <input type="password" name="n3_guid" value="" maxlength="64" autocomplete="new-password" spellcheck="false" placeholder="<?= e(t('egisz.settings.secret_keep')) ?>">
            <?php if (!empty($cfg['n3_guid_masked'])): ?>
                <span class="muted"><?= e((string)$cfg['n3_guid_masked']) ?></span>
            <?php endif; ?>
        </label>
        <label>
            <?= e(t('egisz.settings.n3_id_lpu')) ?>
            <input type="text" name="n3_id_lpu" value="<?= e((string)($cfg['n3_id_lpu'] ?? '')) ?>" maxlength="64" autocomplete="off" spellcheck="false">
        </label>
        <label>
            <?= e(t('egisz.settings.n3_eventlog_token')) ?>
            <input type="password" name="n3_eventlog_token" value="" maxlength="128" autocomplete="new-password" spellcheck="false" placeholder="<?= !empty($cfg['n3_eventlog_token_set']) ? e(t('egisz.settings.secret_keep')) : '' ?>">
        </label>
        <label>
            <?= e(t('egisz.settings.n3_emk_url')) ?>
            <input type="url" name="n3_emk_url" value="<?= e((string)($cfg['n3_emk_url'] ?? '')) ?>" maxlength="512" spellcheck="false">
        </label>
        <label>
            <?= e(t('egisz.settings.n3_pix_url')) ?>
            <input type="url" name="n3_pix_url" value="<?= e((string)($cfg['n3_pix_url'] ?? '')) ?>" maxlength="512" spellcheck="false">
        </label>
        <label>
            <?= e(t('egisz.settings.n3_term_url')) ?>
            <input type="url" name="n3_term_url" value="<?= e((string)($cfg['n3_term_url'] ?? '')) ?>" maxlength="512" spellcheck="false">
        </label>
        <label>
            <?= e(t('egisz.settings.n3_eventlog_url')) ?>
            <input type="url" name="n3_eventlog_url" value="<?= e((string)($cfg['n3_eventlog_url'] ?? '')) ?>" maxlength="512" spellcheck="false">
        </label>
    </fieldset>

    <fieldset>
        <legend><?= e(t('egisz.settings.worker_sec')) ?></legend>
        <input type="hidden" name="worker_enabled_present" value="1">
        <p class="form-hint"><?= e(t('egisz.settings.worker_help')) ?></p>
        <label class="checkbox">
            <input type="checkbox" name="worker_enabled" value="1"<?= !empty($cfg['worker_enabled']) ? ' checked' : '' ?>>
            <?= e(t('egisz.settings.worker_enabled')) ?>
        </label>
        <p class="form-hint">
            <?= e(t('egisz.settings.worker_token')) ?>:
            <code><?= e((string)($cfg['worker_token_masked'] ?? '')) ?></code>
            <?php if (!empty($cfg['worker_dispatch'])): ?>
                · <?= e(t('egisz.settings.worker_dispatch_on')) ?>
            <?php endif; ?>
        </p>
        <label class="checkbox">
            <input type="checkbox" name="worker_token_regen" value="1">
            <?= e(t('egisz.settings.worker_token_regen')) ?>
        </label>
    </fieldset>

    <div class="page-actions">
        <button type="submit" class="btn-primary"><?= e(t('core.common.save')) ?></button>
        <a class="btn-secondary" href="/egisz/status"><?= e(t('egisz.menu.status')) ?></a>
    </div>
</form>
