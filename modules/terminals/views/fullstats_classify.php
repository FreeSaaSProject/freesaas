<?php
declare(strict_types=1);
/** @var list<array<string,mixed>> $types */
/** @var list<array<string,mixed>> $proposals */
/** @var bool $llmReady */
/** @var string $q */
/** @var string $message */
/** @var string $error */
$hasProposals = $proposals !== [];
$q = (string)($q ?? '');
?>
<section class="page-head">
  <h1><?= te('terminals.fullstats.classify.title') ?></h1>
  <p class="muted"><?= te('terminals.fullstats.classify.hint') ?></p>
  <div class="page-actions">
    <a class="btn-secondary" href="/terminals/fullstats?mark=unclassified"><?= te('terminals.fullstats.mark.unclassified') ?></a>
    <a class="btn-secondary" href="/terminals/fullstats?q=оазис"><?= te('terminals.fullstats.classify.search_oasis') ?></a>
    <a class="btn-secondary" href="/terminals/fullstats"><?= te('terminals.menu.fullstats') ?></a>
  </div>
</section>

<?php if ($message !== ''): ?><div class="flash flash-ok"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="flash flash-warn"><?= e($error) ?></div><?php endif; ?>

<?php if (!$llmReady): ?>
  <p class="muted"><?= te('terminals.fullstats.classify.llm_off') ?></p>
<?php else: ?>
  <p class="muted"><?= te('terminals.fullstats.classify.llm_on') ?></p>
<?php endif; ?>

<form class="filters" method="get" action="/terminals/fullstats/classify" data-auto-filter>
  <label><?= te('terminals.fullstats.search_q') ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= te('terminals.fullstats.search_q_ph') ?>">
  </label>
</form>

<?php if (!$hasProposals): ?>
<section>
  <h2><?= te('terminals.fullstats.classify.unique_title', ['n' => (string)count($types)]) ?></h2>
  <?php if ($types === []): ?>
    <p class="muted"><?= te('terminals.fullstats.classify.empty') ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th><?= te('terminals.fullstats.col.ticket_type') ?></th>
            <th><?= te('terminals.fullstats.col.title') ?></th>
            <th><?= te('terminals.fullstats.classify.col.count') ?></th>
            <th><?= te('terminals.fullstats.classify.col.avg_min') ?></th>
            <th><?= te('terminals.fullstats.classify.col.max_min') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($types as $u): ?>
          <tr>
            <td><?= e((string)$u['ticket_type']) ?></td>
            <td class="muted"><?= e((string)$u['title_sample']) ?></td>
            <td><?= (int)$u['cnt'] ?></td>
            <td><?= e((string)round((float)($u['avg_min'] ?? 0))) ?></td>
            <td><?= (int)($u['max_min'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" class="page-actions" style="margin-top:1rem">
      <input type="hidden" name="action" value="propose">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <button type="submit" class="btn-primary"><?= te('terminals.fullstats.classify.propose') ?></button>
    </form>
  <?php endif; ?>
</section>
<?php else: ?>
<section>
  <h2><?= te('terminals.fullstats.classify.review_title', ['n' => (string)count($proposals)]) ?></h2>
  <p class="muted"><?= te('terminals.fullstats.classify.review_hint') ?></p>
  <form method="post">
    <input type="hidden" name="action" value="apply">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th><?= te('terminals.fullstats.col.ticket_type') ?></th>
            <th><?= te('terminals.fullstats.col.title') ?></th>
            <th><?= te('terminals.fullstats.classify.col.count') ?></th>
            <th><?= te('terminals.fullstats.classify.col.avg_min') ?></th>
            <th><?= te('terminals.fullstats.classify.col.max_min') ?></th>
            <th><?= te('terminals.fullstats.col.id_type') ?></th>
            <th><?= te('terminals.fullstats.col.is_limited') ?></th>
            <th><?= te('terminals.fullstats.col.is_payer') ?></th>
            <th><?= te('terminals.fullstats.col.is_oasis') ?></th>
            <th><?= te('terminals.fullstats.classify.col.source') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($proposals as $i => $p): ?>
          <?php
            $i = (int)$i;
            $idType = (int)($p['id_type'] ?? 0);
            $isLimited = (int)($p['is_limited'] ?? 0);
            $isPayer = (int)($p['is_payer'] ?? 0);
            $isOasis = (int)($p['is_oasis'] ?? 0);
            $complete = $idType > 0 && $isLimited > 0 && $isPayer > 0 && $isOasis > 0;
          ?>
          <tr<?= $complete ? '' : ' class="warn-row"' ?>>
            <td>
              <?= e((string)$p['ticket_type']) ?>
              <input type="hidden" name="rows[<?= $i ?>][ticket_type]" value="<?= e((string)$p['ticket_type']) ?>">
            </td>
            <td class="muted" title="<?= e((string)($p['rationale'] ?? '')) ?>"><?= e((string)$p['title_sample']) ?></td>
            <td><?= (int)($p['cnt'] ?? 0) ?></td>
            <td><?= e((string)round((float)($p['avg_min'] ?? 0))) ?></td>
            <td><?= (int)($p['max_min'] ?? 0) ?></td>
            <td>
              <select name="rows[<?= $i ?>][id_type]"><?= TicketTypeEnums::optionsHtml(TicketTypeEnums::idTypeKeys(), $idType) ?></select>
            </td>
            <td>
              <select name="rows[<?= $i ?>][is_limited]"><?= TicketTypeEnums::optionsHtml(TicketTypeEnums::isLimitedKeys(), $isLimited) ?></select>
            </td>
            <td>
              <select name="rows[<?= $i ?>][is_payer]"><?= TicketTypeEnums::optionsHtml(TicketTypeEnums::isPayerKeys(), $isPayer) ?></select>
            </td>
            <td>
              <select name="rows[<?= $i ?>][is_oasis]"><?= TicketTypeEnums::optionsHtml(TicketTypeEnums::isOasisKeys(), $isOasis) ?></select>
            </td>
            <td class="muted"><?= e((string)($p['source'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="page-actions" style="margin-top:1rem">
      <button type="submit" class="btn-primary"><?= te('terminals.fullstats.classify.apply') ?></button>
    </div>
  </form>
  <form method="post" class="page-actions">
    <input type="hidden" name="action" value="clear">
    <input type="hidden" name="q" value="<?= e($q) ?>">
    <button type="submit" class="btn-secondary"><?= te('terminals.fullstats.classify.clear') ?></button>
  </form>
</section>
<?php endif; ?>
