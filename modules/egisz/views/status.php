<?php
declare(strict_types=1);
/** @var array<string,mixed> $cfg */
/** @var array{id:string,configured:bool} $transport */
/** @var array{id:string,available:bool,note?:string} $signer */
/** @var array<string,mixed> $soap */
/** @var array{ok?:bool,error?:string,http_code?:int,probed?:bool,hint?:string} $term */
/** @var array<string,int> $counts */
/** @var bool $live */
?>
<section class="egisz-status">
    <p class="egisz-alpha"><?= e(t('egisz.status.maturity')) ?></p>
    <p class="hint"><?= e(t('egisz.settings.deploy_hint')) ?></p>
    <?php if (empty($live)): ?>
        <p class="hint"><?= e(t('egisz.status.probe_hint')) ?>
            <a href="/egisz/status?live=1"><?= e(t('egisz.status.probe_link')) ?></a>
        </p>
    <?php endif; ?>
    <dl class="egisz-status-dl">
        <dt><?= e(t('egisz.status.mo_oid')) ?></dt>
        <dd><?= ($cfg['mo_oid'] ?? '') !== '' ? e((string)$cfg['mo_oid']) : e(t('egisz.status.not_ready')) ?></dd>
        <dt><?= e(t('egisz.status.operator')) ?></dt>
        <dd><?= e((string)($cfg['operator'] ?? '')) ?></dd>
        <dt><?= e(t('egisz.status.transport')) ?></dt>
        <dd><?= e($transport['id']) ?> — <?= e($transport['configured'] ? t('egisz.status.ready') : t('egisz.status.not_ready')) ?></dd>
        <dt><?= e(t('egisz.status.signer')) ?></dt>
        <dd><?= e($signer['id']) ?> — <?= e($signer['available'] ? t('egisz.status.ready') : t('egisz.status.not_ready')) ?>
            <span class="hint"><?= e((string)($signer['note'] ?? '')) ?></span>
        </dd>
        <dt><?= e(t('egisz.status.term')) ?></dt>
        <dd><?php if (empty($term['probed'])): ?>
                <?= e(t('egisz.status.probe_skipped')) ?>
            <?php else: ?>
                <?= !empty($term['ok']) ? e(t('egisz.status.ready')) : e(t('egisz.status.not_ready')) ?>
                <?php if (!empty($term['error'])): ?> (<?= e((string)$term['error']) ?>)<?php endif; ?>
            <?php endif; ?>
        </dd>
        <?php if (!empty($soap['probed'])): ?>
            <dt><?= e(t('egisz.status.soap')) ?></dt>
            <dd>
                PIX: <?= !empty($soap['pix_ready']) ? e(t('egisz.status.ready')) : e(t('egisz.status.not_ready')) ?>
                <?php if (!empty($soap['pix_error'])): ?> — <?= e((string)$soap['pix_error']) ?><?php endif; ?>
                <br>
                EMK: <?= !empty($soap['emk_ready']) ? e(t('egisz.status.ready')) : e(t('egisz.status.not_ready')) ?>
                <?php if (!empty($soap['emk_error'])): ?> — <?= e((string)$soap['emk_error']) ?><?php endif; ?>
            </dd>
        <?php endif; ?>
    </dl>

    <h2><?= e(t('egisz.status.outbox')) ?></h2>
    <ul>
        <?php foreach ($counts as $st => $n): ?>
            <li><code><?= e($st) ?></code>: <?= (int)$n ?></li>
        <?php endforeach; ?>
    </ul>

    <h2><?= e(t('egisz.status.checklist')) ?></h2>
    <ul class="egisz-checklist">
        <li><?= e(t('egisz.status.check.vpn')) ?></li>
        <li><?= e(t('egisz.status.check.frmo')) ?></li>
        <li><?= e(t('egisz.status.check.frmr')) ?></li>
        <li><?= e(t('egisz.status.check.snils')) ?></li>
        <li><?= e(t('egisz.status.check.ukep')) ?></li>
    </ul>
</section>
