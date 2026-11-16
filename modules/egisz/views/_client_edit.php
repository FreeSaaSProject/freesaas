<?php
declare(strict_types=1);
/** @var array<string,mixed>|null $ids */
/** @var array<string,mixed> $client */
/** @var bool $canSync */
$snils = (string)($ids['SNILS'] ?? '');
$oms = (string)($ids['OMS_POLICY'] ?? '');
$insurer = (string)($ids['OMS_INSURER'] ?? '');
$n3 = (string)($ids['N3_ID'] ?? $ids['ID_PATIENT_MIS'] ?? '');
$clientId = (int)($client['id'] ?? 0);
?>
<section class="egisz-client-edit">
    <h3><?= e(t('egisz.client.sec')) ?></h3>
    <p class="hint"><?= e(t('egisz.client.hint')) ?></p>
    <label>
        <?= e(t('egisz.client.snils')) ?>
        <input type="text" name="egisz_snils" value="<?= e($snils) ?>" maxlength="14" autocomplete="off" placeholder="000-000-000 00">
    </label>
    <label>
        <?= e(t('egisz.client.oms_policy')) ?>
        <input type="text" name="egisz_oms_policy" value="<?= e($oms) ?>" maxlength="32" autocomplete="off">
    </label>
    <label>
        <?= e(t('egisz.client.oms_insurer')) ?>
        <input type="text" name="egisz_oms_insurer" value="<?= e($insurer) ?>" maxlength="255">
    </label>
    <?php if ($n3 !== ''): ?>
        <p><?= e(t('egisz.client.n3_id')) ?>: <code><?= e($n3) ?></code></p>
    <?php endif; ?>
    <?php if (!empty($canSync) && $clientId > 0): ?>
        <form method="post" action="/egisz/patient/sync" class="egisz-inline-sync" onsubmit="return confirm(<?= json_encode(t('egisz.patient.confirm'), JSON_UNESCAPED_UNICODE) ?>);">
            <?= class_exists('Csrf', false) ? Csrf::field() : '' ?>
            <input type="hidden" name="client_id" value="<?= $clientId ?>">
            <button type="submit" class="btn-secondary"><?= e(t('egisz.patient.sync')) ?></button>
        </form>
    <?php endif; ?>
</section>
