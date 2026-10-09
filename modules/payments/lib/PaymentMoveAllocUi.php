<?php
declare(strict_types=1);

/** HTML block for ledger move edit: linked + hanging payments (multi-allocation). */
final class PaymentMoveAllocUi
{
    /**
     * @param array<string,mixed> $move
     * @param array<string,mixed>|null $type
     */
    public static function sectionHtml(int $moveId, array $move, ?array $type): string
    {
        if ($moveId < 1 || !PaymentMoveLinksRepository::ready()) {
            return '';
        }
        if (!Auth::can('payments.view') && !Auth::can('payments.edit')) {
            return '';
        }
        // Only types with explicit FOT_KIND on the move type row.
        if (!class_exists('LedgerMovesRepository', false) || !LedgerMovesRepository::isFotType($type)) {
            return '';
        }
        $payAccount = LedgerMovesRepository::fotKind($type) === 2 ? 2 : 1;

        $links = PaymentMoveLinksRepository::forMove($moveId);
        $canEdit = Auth::can('payments.edit') && Auth::can('ledger.edit');
        $hanging = PaymentMoveLinksRepository::hangingPayments(200, $payAccount);
        $linkedIds = [];
        foreach ($links as $l) {
            $linkedIds[(int)$l['payment_id']] = true;
        }

        $linkedSum = 0.0;
        $linkedRows = [];
        foreach ($links as $l) {
            $pid = (int)$l['payment_id'];
            $pay = PaymentsRepository::find($pid);
            $sum = (float)$l['summ'];
            $linkedSum += $sum;
            $linkedRows[] = [
                'payment_id' => $pid,
                'summ' => $sum,
                'user_id' => $pay ? (int)$pay['user_id'] : 0,
                'dt' => $pay ? (int)$pay['dt'] : 0,
                'descr' => $pay ? (string)$pay['descr'] : '',
                'account' => $pay ? (int)$pay['account'] : 0,
            ];
        }

        $hangingRows = [];
        foreach ($hanging as $h) {
            if (isset($linkedIds[(int)$h['id']])) {
                continue;
            }
            $hangingRows[] = $h;
        }
        if ($linkedRows === [] && $hangingRows === []) {
            return '';
        }

        $moveSum = abs((float)($move['summ'] ?? 0));
        $delta = round($moveSum - $linkedSum, 2);

        ob_start();
        ?>
<section class="payments-alloc" data-payments-alloc="1">
  <h2><?= te('payments.alloc.title') ?></h2>
  <p class="muted"><?= te('payments.alloc.hint') ?></p>
  <p class="payments-alloc-totals">
    <?= te('payments.alloc.move_sum') ?>:
    <strong><?= e(number_format($moveSum, 2, '.', ' ')) ?></strong>
    · <?= te('payments.alloc.linked_sum') ?>:
    <strong><?= e(number_format($linkedSum, 2, '.', ' ')) ?></strong>
    · <?= te('payments.alloc.delta') ?>:
    <strong class="<?= $delta == 0.0 ? 'payments-alloc-ok' : 'payments-alloc-warn' ?>">
      <?= e(number_format($delta, 2, '.', ' ')) ?>
    </strong>
  </p>

  <?php if ($linkedRows !== []): ?>
  <h3><?= te('payments.alloc.linked') ?></h3>
  <div class="table-wrap"><table class="data payments-alloc-table">
    <thead><tr>
      <th>#</th>
      <th><?= te('payments.col.user') ?></th>
      <th><?= te('payments.col.dt') ?></th>
      <th><?= te('payments.col.sum') ?></th>
      <th><?= te('payments.col.account') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($linkedRows as $lr): ?>
      <tr>
        <td><a href="/payments/<?= (int)$lr['payment_id'] ?>/edit">#<?= (int)$lr['payment_id'] ?></a></td>
        <td><?= UsersRepository::badgeHtml((int)$lr['user_id'], true, true) ?></td>
        <td class="muted"><?= (int)$lr['dt'] > 0 ? e(date('d.m.Y', (int)$lr['dt'])) : '—' ?></td>
        <td><?= e(number_format((float)$lr['summ'], 2, '.', ' ')) ?></td>
        <td class="muted"><?= e(PaymentsRepository::accountLabel((int)$lr['account'])) ?></td>
        <td>
          <?php if ($canEdit): ?>
          <form method="post" action="/payments/move-links/<?= (int)$moveId ?>" class="payments-alloc-inline">
            <?= NavContext::hiddenField() ?>
            <input type="hidden" name="action" value="unlink">
            <input type="hidden" name="payment_id" value="<?= (int)$lr['payment_id'] ?>">
            <button type="submit" class="btn-secondary"><?= te('payments.alloc.unlink') ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <?php if ($hangingRows !== []): ?>
  <h3><?= te('payments.alloc.hanging') ?></h3>
  <?php if ($canEdit):
    $hangingTotal = 0.0;
    foreach ($hangingRows as $h) {
        $hangingTotal += abs((float)$h['sum']);
    }
    ?>
  <form method="post" action="/payments/move-links/<?= (int)$moveId ?>" class="payments-alloc-form">
    <?= NavContext::hiddenField() ?>
    <input type="hidden" name="action" value="link">
    <div class="table-wrap"><table class="data payments-alloc-table">
      <thead><tr>
        <th>
          <label class="payments-alloc-checkall" title="<?= te('payments.alloc.select_all') ?>">
            <input type="checkbox" data-payments-alloc-all>
            <span class="muted"><?= te('payments.alloc.select_all_short') ?></span>
          </label>
        </th>
        <th>#</th>
        <th><?= te('payments.col.user') ?></th>
        <th><?= te('payments.col.dt') ?></th>
        <th><?= te('payments.col.sum') ?></th>
        <th><?= te('payments.col.account') ?></th>
        <th><?= te('payments.col.descr') ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($hangingRows as $h): ?>
        <tr>
          <td><input type="checkbox" name="payment_ids[]" value="<?= (int)$h['id'] ?>" data-payments-alloc-sum="<?= e((string)abs((float)$h['sum'])) ?>"></td>
          <td><a href="/payments/<?= (int)$h['id'] ?>/edit">#<?= (int)$h['id'] ?></a></td>
          <td><?= UsersRepository::badgeHtml((int)$h['user_id'], true, true) ?></td>
          <td class="muted"><?= (int)$h['dt'] > 0 ? e(date('d.m.Y', (int)$h['dt'])) : '—' ?></td>
          <td class="payments-alloc-sum"><?= e(number_format(abs((float)$h['sum']), 2, '.', ' ')) ?></td>
          <td class="muted"><?= e(PaymentsRepository::accountLabel((int)$h['account'])) ?></td>
          <td class="muted"><?= e(mb_strimwidth(trim((string)$h['descr']), 0, 80, '…')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4"><?= te('payments.alloc.hanging_total') ?></td>
          <td class="payments-alloc-sum"><strong><?= e(number_format($hangingTotal, 2, '.', ' ')) ?></strong></td>
          <td colspan="2"></td>
        </tr>
        <tr>
          <td colspan="4"><?= te('payments.alloc.selected_sum') ?></td>
          <td class="payments-alloc-sum"><strong data-payments-alloc-selected>0.00</strong></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table></div>
    <button type="submit" class="btn-primary"><?= te('payments.alloc.link') ?></button>
  </form>
  <?php else: ?>
    <p class="muted"><?= te('payments.alloc.need_edit') ?></p>
  <?php endif; ?>
  <?php endif; ?>
</section>
        <?php
        return (string)ob_get_clean();
    }
}
