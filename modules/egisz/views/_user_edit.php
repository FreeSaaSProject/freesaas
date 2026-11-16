<?php
declare(strict_types=1);
/** @var array<string,mixed>|null $staff */
$snils = (string)($staff['SNILS'] ?? '');
$pos = (string)($staff['POSITION_CODE'] ?? '');
$spec = (string)($staff['SPECIALTY_CODE'] ?? '');
$role = (string)($staff['ROLE_CODE'] ?? '');
?>
<section class="egisz-staff-edit">
    <h3><?= e(t('egisz.staff.sec')) ?></h3>
    <p class="hint"><?= e(t('egisz.staff.hint')) ?></p>
    <label>
        <?= e(t('egisz.staff.snils')) ?>
        <input type="text" name="egisz_staff_snils" value="<?= e($snils) ?>" maxlength="14" autocomplete="off" placeholder="000-000-000 00">
    </label>
    <label>
        <?= e(t('egisz.staff.position')) ?>
        <input type="text" name="egisz_position_code" value="<?= e($pos) ?>" maxlength="32" autocomplete="off">
    </label>
    <label>
        <?= e(t('egisz.staff.specialty')) ?>
        <input type="text" name="egisz_specialty_code" value="<?= e($spec) ?>" maxlength="32" autocomplete="off">
    </label>
    <label>
        <?= e(t('egisz.staff.role')) ?>
        <input type="text" name="egisz_role_code" value="<?= e($role) ?>" maxlength="32" autocomplete="off">
    </label>
</section>
