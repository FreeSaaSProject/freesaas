<?php
/** @var array{y:int,m:int,ym:string,month_start:int,month_end:int,prev_ym:string,next_ym:string,title:string,is_current:bool} $period */
/** @var array<string,mixed> $report */
/** @var array{ok:bool,message:string}|null $syncFlash */
/** @var list<array<string,mixed>> $syncPending */
/** @var bool $canEdit */

$fmt = static function (float $v): string {
    return LiquidAssetsReport::fmtMoney($v);
};
$fmtPl = static function (float $v) use ($fmt): string {
    $cls = $v < 0 ? 'liquid-assets-pl-neg' : ($v > 0 ? 'liquid-assets-pl-pos' : 'liquid-assets-pl-zero');
    return '<span class="' . $cls . '">' . e($fmt($v)) . '&nbsp;₽</span>';
};
$fmtSigned = static function (float $v, int $decimals = 0): string {
    $abs = abs($v);
    if ($decimals > 0 && abs($abs - round($abs)) > 0.0000001) {
        $s = rtrim(rtrim(number_format($abs, $decimals, '.', ' '), '0'), '.');
    } else {
        $s = number_format(round($abs), 0, '.', ' ');
    }
    if ($v > 0) {
        return '+' . $s;
    }
    if ($v < 0) {
        return '−' . $s;
    }
    return $s;
};

$ym = $period['ym'];
$flows = $report['month_flows'];
$mfInOps = $flows['in_by_cat'][1] ?? 0;
$mfInInv = $flows['in_by_cat'][2] ?? 0;
$mfInFin = $flows['in_by_cat'][3] ?? 0;
$mfOutOps = $flows['out_by_cat'][1] ?? 0;
$mfOutFin = $flows['out_by_cat'][3] ?? 0;
?>
<section class="page-head">
  <div class="page-head-row">
    <div></div>
    <div class="page-actions">
      <a class="btn-secondary" href="/liquid-assets/accounts"><?= te('liquid_assets.menu.accounts') ?></a>
    </div>
  </div>
</section>

<?php if (!empty($syncFlash)): ?>
  <div class="flash <?= !empty($syncFlash['ok']) ? 'flash-ok' : 'flash-warn' ?>" style="white-space:pre-wrap"><?= e((string)$syncFlash['message']) ?></div>
<?php endif; ?>

<?php if ($syncPending !== []): ?>
<div class="liquid-assets-confirm">
  <p><b><?= te('liquid_assets.sync.confirm_title') ?></b></p>
  <ul>
  <?php foreach ($syncPending as $p):
      $fundLabel = '';
      if (!empty($p['id_fund']) && TinvestSyncService::fundsEnabled() && class_exists('FundsRepository', false)) {
          $f = FundsRepository::find((int)$p['id_fund']);
          $fundLabel = $f ? (string)$f['name'] : '';
      }
      $ex = !empty($p['existing_reval_id'])
          ? t('liquid_assets.sync.has_reval', ['id' => (int)$p['existing_reval_id']])
          : t('liquid_assets.sync.no_reval');
  ?>
    <li>
      <b><?= e((string)$p['label']) ?></b>
      <?= $fundLabel !== '' ? ' → ' . e($fundLabel) : '' ?>:
      <?= e(t('liquid_assets.sync.asset_vs_fund', [
          'asset' => $fmt((float)$p['asset_x']),
          'fund' => $fmt((float)$p['fund_y']),
      ])) ?>
      (<?= e($ex) ?>)
    </li>
  <?php endforeach; ?>
  </ul>
  <p>
    <a class="btn-primary" href="/liquid-assets?ym=<?= e(urlencode($ym)) ?>&do=sync_confirm"><?= te('liquid_assets.sync.confirm_yes') ?></a>
    &nbsp;
    <a class="btn-secondary" href="/liquid-assets?ym=<?= e(urlencode($ym)) ?>"><?= te('liquid_assets.sync.confirm_no') ?></a>
  </p>
</div>
<?php endif; ?>

<nav class="liquid-assets-nav" aria-label="<?= te('liquid_assets.report.title') ?>">
  <a id="la-month-prev" href="/liquid-assets?ym=<?= e($period['prev_ym']) ?>"><?= te('liquid_assets.report.prev') ?></a>
  <b><?= e($period['title']) ?></b>
  <a id="la-month-next" href="/liquid-assets?ym=<?= e($period['next_ym']) ?>"><?= te('liquid_assets.report.next') ?></a>
  <small class="muted"><?= te('liquid_assets.report.nav_hint') ?></small>
</nav>
<script>
(function(){
  document.addEventListener('keydown', function(e){
    if (!e) return;
    var t = e.target || e.srcElement;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    var a = null;
    if (e.key === 'ArrowLeft' || e.keyCode === 37) a = document.getElementById('la-month-prev');
    else if (e.key === 'ArrowRight' || e.keyCode === 39) a = document.getElementById('la-month-next');
    if (a && a.href) { e.preventDefault(); window.location.href = a.href; }
  });
})();
</script>

<div class="liquid-assets-sync">
<?php if ((int)$report['tinvest_count'] < 1): ?>
  <span class="muted"><a href="/liquid-assets/accounts/new"><?= te('liquid_assets.report.no_accounts') ?></a></span>
<?php elseif ($period['is_current'] && $canEdit): ?>
  <a class="btn-primary" href="/liquid-assets?ym=<?= e(urlencode($ym)) ?>&do=sync"><?= te('liquid_assets.report.sync') ?></a>
  <span class="muted"><?= te('liquid_assets.report.sync_hint') ?></span>
<?php elseif (!$period['is_current']): ?>
  <span class="muted"><?= te('liquid_assets.report.sync_past') ?></span>
<?php endif; ?>
</div>

<h2><?= te('liquid_assets.report.summary') ?> <span class="muted">(<?= e($period['title']) ?>)</span></h2>
<table class="liquid-assets-summary">
  <tr><td><?= te('liquid_assets.report.deposits') ?></td><td class="liquid-assets-num"><b><?= e($fmt((float)$report['depo_sum'])) ?>&nbsp;₽</b></td></tr>
  <tr><td><?= te('liquid_assets.report.current') ?></td><td class="liquid-assets-num"><b><?= e($fmt((float)$report['cash_other'])) ?>&nbsp;₽</b></td></tr>
  <tr><td><?= te('liquid_assets.report.broker') ?></td><td class="liquid-assets-num"><b><?= e($fmt((float)$report['broker_total'])) ?>&nbsp;₽</b></td></tr>
  <tr><td><?= te('liquid_assets.report.total') ?></td><td class="liquid-assets-num"><b><?= e($fmt((float)$report['grand'])) ?>&nbsp;₽</b></td></tr>
</table>

<h3><?= te('liquid_assets.report.month_flows') ?> <span class="muted">(<?= e($period['title']) ?>)</span></h3>
<table class="liquid-assets-flows">
  <tr><td><?= te('liquid_assets.report.in_current') ?></td><td class="liquid-assets-num"><b><?= e($fmt((float)$flows['in_current'])) ?>&nbsp;₽</b></td></tr>
  <tr><td class="liquid-assets-indent"><?= te('liquid_assets.report.cat_ops') ?></td><td class="liquid-assets-num"><?= e($fmt((float)$mfInOps)) ?>&nbsp;₽</td></tr>
  <tr><td class="liquid-assets-indent"><?= te('liquid_assets.report.cat_fin') ?></td><td class="liquid-assets-num"><?= e($fmt((float)$mfInFin)) ?>&nbsp;₽</td></tr>
  <tr><td class="liquid-assets-indent"><?= te('liquid_assets.report.cat_inv') ?></td><td class="liquid-assets-num"><?= e($fmt((float)$mfInInv)) ?>&nbsp;₽</td></tr>
  <tr><td><?= te('liquid_assets.report.out_ops') ?></td><td class="liquid-assets-num"><b><?= e($fmt((float)$flows['out_ops'])) ?>&nbsp;₽</b></td></tr>
  <tr><td class="liquid-assets-indent"><?= te('liquid_assets.report.cat_ops') ?></td><td class="liquid-assets-num"><?= e($fmt((float)$mfOutOps)) ?>&nbsp;₽</td></tr>
  <tr><td class="liquid-assets-indent"><?= te('liquid_assets.report.cat_fin') ?></td><td class="liquid-assets-num"><?= e($fmt((float)$mfOutFin)) ?>&nbsp;₽</td></tr>
  <tr><td><?= te('liquid_assets.report.out_invest') ?></td><td class="liquid-assets-num"><b><?= e($fmt((float)$flows['out_invest'])) ?>&nbsp;₽</b></td></tr>
</table>
<p class="muted"><?= te('liquid_assets.report.flows_note') ?></p>

<h2><?= te('liquid_assets.report.deposits') ?></h2>
<?php if ($report['deposits'] === []): ?>
  <p class="muted"><?= te('liquid_assets.report.deposits_empty') ?></p>
<?php else: ?>
<table class="liquid-assets-deposits">
<thead><tr>
  <th><?= te('liquid_assets.report.col.bank') ?></th>
  <th><?= te('liquid_assets.report.col.balance') ?></th>
  <th><?= te('liquid_assets.report.col.open_summ') ?></th>
  <th><?= te('liquid_assets.report.col.income') ?></th>
  <th><?= te('liquid_assets.report.col.term') ?></th>
  <th><?= te('liquid_assets.report.col.move') ?></th>
</tr></thead>
<tbody>
<?php foreach ($report['deposits'] as $d):
    $term = '—';
    if (($d['open'] ?? '') !== '' || ($d['close'] ?? '') !== '') {
        $term = (($d['open'] ?? '') !== '' ? e((string)$d['open']) : '…')
            . ' – ' . (($d['close'] ?? '') !== '' ? e((string)$d['close']) : '…');
    }
    $incomeCell = e($fmt((float)$d['income'])) . '&nbsp;₽';
    if (!empty($d['rate'])) {
        $incomeCell .= ' <small>(' . e((string)$d['rate']) . '%)</small>';
    }
?>
<tr>
  <td><?= e((string)$d['bank']) ?></td>
  <td class="liquid-assets-num"><?= !empty($d['show_balance']) ? (e($fmt((float)$d['balance'])) . '&nbsp;₽') : '<span class="muted">—</span>' ?></td>
  <td class="liquid-assets-num"><?= e($fmt((float)$d['open_summ'])) ?>&nbsp;₽</td>
  <td class="liquid-assets-num"><?= $incomeCell ?></td>
  <td><?= $term ?></td>
  <td><a href="/ledger/moves/<?= (int)$d['id_move'] ?>/edit" target="_blank" rel="noopener">#<?= (int)$d['id_move'] ?></a></td>
</tr>
<?php endforeach; ?>
<tr>
  <td><b><?= te('liquid_assets.report.deposits_total') ?></b></td>
  <td class="liquid-assets-num"><b><?= e($fmt((float)$report['depo_sum'])) ?>&nbsp;₽</b></td>
  <td colspan="4"></td>
</tr>
</tbody></table>
<?php endif; ?>

<h2><?= te('liquid_assets.report.broker') ?></h2>
<?php if ($report['broker_blocks'] === []): ?>
  <p class="muted"><?= te('liquid_assets.report.broker_empty') ?></p>
<?php else: ?>
<?php foreach ($report['broker_blocks'] as $bb): ?>
  <?php if ($bb['source'] === 'snapshot'):
      $snap = $bb['snap'];
      $snapInfo = $snap
          ? date('d.m.Y H:i', (int)$snap['DT']) . ' · ID ' . (int)$snap['ID']
          : '—';
  ?>
  <h3><?= e((string)$bb['label']) ?> — <?= e($fmt((float)$bb['total'])) ?>&nbsp;₽
    <small class="muted">(<?= e($snapInfo) ?>)</small></h3>
  <?php if (empty($bb['accounts'])): ?>
    <p class="muted"><?= te('liquid_assets.report.no_positions') ?></p>
  <?php else: ?>
    <?php foreach ($bb['accounts'] as $accBlock): ?>
      <h4><?= e((string)$accBlock['title']) ?> — <?= e($fmt((float)$accBlock['total'])) ?>&nbsp;₽</h4>
      <?php if (empty($accBlock['positions'])): ?>
        <p class="muted"><?= te('liquid_assets.report.no_positions') ?></p>
      <?php else: ?>
      <table class="liquid-assets-pos">
      <thead><tr>
        <th><?= te('liquid_assets.report.pos.ticker') ?></th>
        <th><?= te('liquid_assets.report.pos.class') ?></th>
        <th><?= te('liquid_assets.report.pos.name') ?></th>
        <th><?= te('liquid_assets.report.pos.params') ?></th>
        <th><?= te('liquid_assets.report.pos.qty') ?></th>
        <th><?= te('liquid_assets.report.pos.price') ?></th>
        <th><?= te('liquid_assets.report.pos.avg') ?></th>
        <th><?= te('liquid_assets.report.pos.value') ?></th>
        <th><?= te('liquid_assets.report.pos.pl') ?></th>
      </tr></thead>
      <tbody>
      <?php
        $sumQty = 0.0; $sumPl = 0.0; $hasPl = false;
        foreach ($accBlock['positions'] as $p):
          $sumQty += (float)$p['QTY'];
          $plCell = '—';
          if ($p['PL'] !== null) {
              $plCell = $fmtPl((float)$p['PL']);
              $sumPl += (float)$p['PL'];
              $hasPl = true;
          }
      ?>
      <tr>
        <td><b><?= e((string)$p['TICKER']) ?></b></td>
        <td><?= e(TinvestApiClient::assetClassLabel((string)$p['ASSET_CLASS'])) ?></td>
        <td><?= e((string)$p['NAME']) ?></td>
        <td><?= !empty($p['NOTE']) ? '<span class="muted">' . e((string)$p['NOTE']) . '</span>' : '—' ?></td>
        <td class="liquid-assets-num"><?= e($fmt((float)$p['QTY'])) ?></td>
        <td class="liquid-assets-num"><?= e($fmt((float)$p['PRICE'])) ?>&nbsp;₽</td>
        <td class="liquid-assets-num"><?= e($fmt((float)$p['AVG_PRICE'])) ?>&nbsp;₽</td>
        <td class="liquid-assets-num"><?= e($fmt((float)$p['VALUE'])) ?>&nbsp;₽</td>
        <td class="liquid-assets-num"><?= $plCell ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="4"><?= te('liquid_assets.report.pos.total') ?></td>
        <td class="liquid-assets-num"><?= e($fmt($sumQty)) ?></td>
        <td class="liquid-assets-num">—</td>
        <td class="liquid-assets-num">—</td>
        <td class="liquid-assets-num"><?= e($fmt((float)$accBlock['total'])) ?>&nbsp;₽</td>
        <td class="liquid-assets-num"><?= $hasPl ? $fmtPl($sumPl) : '—' ?></td>
      </tr></tfoot>
      </table>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php elseif ($bb['source'] === 'fund' && !empty($bb['fund_summary'])):
      $fs = $bb['fund_summary'];
      $fname = ($bb['fund'] !== '') ? (string)$bb['fund'] : ('#' . (int)$bb['id_fund']);
  ?>
  <h3><?= e((string)$bb['label']) ?> — <?= e($fmt((float)$bb['total'])) ?>&nbsp;₽
    <small class="muted">(<?= e(t('liquid_assets.report.fund_fallback', ['name' => $fname])) ?>)</small></h3>
  <table class="liquid-assets-flows" style="max-width:720px">
    <tr><td><?= te('liquid_assets.report.fund_start') ?></td><td class="liquid-assets-num"><?= e($fmt((float)$fs['value_start'])) ?>&nbsp;₽</td></tr>
    <tr><td><?= te('liquid_assets.report.fund_end') ?></td><td class="liquid-assets-num"><b><?= e($fmt((float)$fs['value_end'])) ?>&nbsp;₽</b></td></tr>
    <tr><td><?= te('liquid_assets.report.fund_delta') ?></td><td class="liquid-assets-num"><?= $fmtPl((float)$fs['delta']) ?></td></tr>
    <?php if (!empty($fs['by_type'])):
        ksort($fs['by_type']);
        foreach ($fs['by_type'] as $tid => $summ): ?>
    <tr><td><?= e(TinvestSyncService::fundMoveTypeLabel((int)$tid)) ?></td><td class="liquid-assets-num"><?= e($fmt((float)$summ)) ?>&nbsp;₽</td></tr>
    <?php endforeach; else: ?>
    <tr><td colspan="2" class="muted"><?= te('liquid_assets.report.fund_no_moves') ?></td></tr>
    <?php endif; ?>
  </table>
  <?php if (!empty($report['funds_on'])): ?>
  <p><a href="/fund-movements?fund_id=<?= (int)$bb['id_fund'] ?>" target="_blank" rel="noopener"><?= te('liquid_assets.report.open_fund_moves') ?></a></p>
  <?php endif; ?>

  <?php else: ?>
  <h3><?= e((string)$bb['label']) ?> — <?= e($fmt(0)) ?>&nbsp;₽
    <small class="muted">(<?= te('liquid_assets.report.no_snap_no_fund') ?>)</small></h3>
  <?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

<h2><?= te('liquid_assets.report.month_changes') ?></h2>
<?php if ($report['month_changes'] === []): ?>
  <p class="muted"><?= te('liquid_assets.report.no_changes') ?></p>
<?php else: ?>
<?php foreach ($report['month_changes'] as $mc): ?>
  <?php if ($mc['source'] === 'snapshot'): ?>
    <p>
      <b><?= e((string)$mc['label']) ?></b>:
      <?= e($fmt((float)$mc['total'])) ?>&nbsp;₽
      <?php if (!empty($mc['snap_dt'])): ?>
        <small class="muted">(<?= e(date('d.m.Y H:i', (int)$mc['snap_dt'])) ?>)</small>
      <?php endif; ?>
      <?php if (!empty($mc['has_prev_snap']) && $mc['delta_val'] !== null): ?>
        ; <?= te('liquid_assets.report.snap_delta') ?> <?= $fmtPl((float)$mc['delta_val']) ?>
      <?php endif; ?>
      <?php if (is_array($mc['ops_summary'])): ?>
        . <?= te('liquid_assets.report.ops.in') ?> <?= e($fmt((float)($mc['ops_summary']['in'] ?? 0))) ?>&nbsp;₽;
        <?= te('liquid_assets.report.ops.out') ?> <?= e($fmt((float)($mc['ops_summary']['out'] ?? 0))) ?>&nbsp;₽
        <small class="muted"><?= te('liquid_assets.report.ops.from_snap') ?></small>
      <?php else: ?>
        . <span class="muted"><?= te('liquid_assets.report.ops.need_sync') ?></span>
      <?php endif; ?>
    </p>
    <?php
    if (is_array($mc['ops_summary'])) {
        $moves = TinvestApiClient::movesFromOpsSummary($mc['ops_summary']);
        if ($moves === []) {
            $buy = (int)($mc['ops_summary']['buy'] ?? 0);
            $sell = (int)($mc['ops_summary']['sell'] ?? 0);
            if ($buy + $sell > 0) {
                echo '<p class="muted">' . te('liquid_assets.report.ops.legacy') . '</p>';
            } else {
                echo '<p class="muted">' . te('liquid_assets.report.ops.no_trades') . '</p>';
            }
        } else {
            $inc = []; $dec = []; $flat = [];
            foreach ($moves as $m) {
                $dq = (float)($m['delta_qty'] ?? 0);
                if ($dq > 0.0000001) {
                    $inc[] = $m;
                } elseif ($dq < -0.0000001) {
                    $dec[] = $m;
                } else {
                    $flat[] = $m;
                }
            }
            foreach ($flat as $m) {
                $net = (float)($m['net'] ?? 0);
                if ($net > 0.01) {
                    $inc[] = $m;
                } elseif ($net < -0.01) {
                    $dec[] = $m;
                }
            }
            $renderOps = static function (string $title, array $rows) use ($fmt, $fmtPl, $fmtSigned): void {
                if ($rows === []) {
                    return;
                }
                echo '<h4>' . e($title) . '</h4>';
                echo '<table class="liquid-assets-ops"><thead><tr>';
                echo '<th>' . te('liquid_assets.report.ops.col.asset') . '</th>';
                echo '<th>' . te('liquid_assets.report.ops.col.delta') . '</th>';
                echo '<th>' . te('liquid_assets.report.ops.col.trades') . '</th>';
                echo '<th>' . te('liquid_assets.report.ops.col.buy') . '</th>';
                echo '<th>' . te('liquid_assets.report.ops.col.sell') . '</th>';
                echo '<th>' . te('liquid_assets.report.ops.col.net') . '</th>';
                echo '</tr></thead><tbody>';
                foreach ($rows as $m) {
                    $asset = !empty($m['ticker']) ? (string)$m['ticker']
                        : (!empty($m['name']) ? (string)$m['name']
                        : (!empty($m['figi']) ? (string)$m['figi'] : '—'));
                    $dq = (float)($m['delta_qty'] ?? 0);
                    $bn = (int)($m['buy_n'] ?? 0);
                    $sn = (int)($m['sell_n'] ?? 0);
                    if ($bn > 0 && $sn > 0) {
                        $trades = '+' . $bn . ' / −' . $sn;
                    } elseif ($bn > 0) {
                        $trades = '+' . $bn;
                    } elseif ($sn > 0) {
                        $trades = '−' . $sn;
                    } else {
                        $trades = '—';
                    }
                    $buySum = (float)($m['buy_sum'] ?? 0);
                    $sellSum = (float)($m['sell_sum'] ?? 0);
                    $net = (float)($m['net'] ?? ($buySum - $sellSum));
                    $dqDec = (abs($dq - round($dq)) < 0.000001) ? 0 : 4;
                    echo '<tr>';
                    echo '<td>' . e($asset) . '</td>';
                    echo '<td class="liquid-assets-num">' . e($fmtSigned($dq, $dqDec)) . '</td>';
                    echo '<td class="liquid-assets-num">' . e($trades) . '</td>';
                    echo '<td class="liquid-assets-num">' . ($buySum > 0.005 ? e($fmt($buySum)) : '0') . '</td>';
                    echo '<td class="liquid-assets-num">' . ($sellSum > 0.005 ? e($fmt($sellSum)) : '0') . '</td>';
                    echo '<td class="liquid-assets-num">' . $fmtPl($net) . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            };
            $renderOps(t('liquid_assets.report.ops.increased'), $inc);
            $renderOps(t('liquid_assets.report.ops.decreased'), $dec);

            $classNet = ['stock' => 0.0, 'bond' => 0.0, 'etf' => 0.0, 'futures' => 0.0];
            $futInc = [];
            foreach ($moves as $m) {
                $cls = (string)($m['asset_class'] ?? 'other');
                $net = (float)($m['net'] ?? 0);
                if (isset($classNet[$cls])) {
                    $classNet[$cls] += $net;
                } elseif ($cls === 'share') {
                    $classNet['stock'] += $net;
                }
                $dq = (float)($m['delta_qty'] ?? 0);
                if ($cls === 'futures' && $dq > 0.0000001) {
                    $tk = !empty($m['ticker']) ? (string)$m['ticker'] : (!empty($m['name']) ? (string)$m['name'] : '');
                    if ($tk !== '') {
                        $futInc[$tk] = 1;
                    }
                }
            }
            $parts = [];
            foreach (['stock', 'bond', 'etf'] as $cls) {
                $v = round($classNet[$cls], 2);
                if (abs($v) < 0.01) {
                    continue;
                }
                $parts[] = TinvestApiClient::assetClassLabel($cls) . ' ' . strip_tags($fmtPl($v));
            }
            if ($futInc !== []) {
                $futLine = TinvestApiClient::assetClassLabel('futures') . ' — ' . implode(', ', array_keys($futInc));
                if (abs($classNet['futures']) >= 0.01) {
                    $futLine .= ' (' . strip_tags($fmtPl($classNet['futures'])) . ')';
                }
                $parts[] = $futLine;
            } elseif (abs($classNet['futures']) >= 0.01) {
                $parts[] = TinvestApiClient::assetClassLabel('futures') . ' ' . strip_tags($fmtPl($classNet['futures']));
            }
            if ($parts !== []) {
                echo '<p><b>' . te('liquid_assets.report.ops.class_summary') . ':</b> ' . e(implode(', ', $parts)) . '.</p>';
            } else {
                echo '<p class="muted">' . te('liquid_assets.report.ops.class_none') . '</p>';
            }
        }
    }
    ?>
  <?php elseif ($mc['source'] === 'fund' && is_array($mc['fund_summary'])):
      $fs = $mc['fund_summary'];
      $fname = ($mc['fund_name'] !== '') ? (string)$mc['fund_name'] : ('#' . (int)$mc['id_fund']);
      $parts = [];
      if (!empty($fs['by_type'])) {
          ksort($fs['by_type']);
          foreach ($fs['by_type'] as $tid => $summ) {
              $parts[] = TinvestSyncService::fundMoveTypeLabel((int)$tid) . ': ' . $fmt((float)$summ) . ' ₽';
          }
      }
  ?>
    <p>
      <b><?= e((string)$mc['label']) ?></b>
      <small class="muted">(<?= e(t('liquid_assets.report.by_fund', ['name' => $fname])) ?>)</small>:
      <?= e($fmt((float)$fs['value_start'])) ?> → <?= e($fmt((float)$fs['value_end'])) ?> ₽,
      <?= $fmtPl((float)$fs['delta']) ?>.
      <?= $parts !== [] ? e(implode('; ', $parts)) : te('liquid_assets.report.fund_no_moves') ?>
      <?php if (!empty($report['funds_on'])): ?>
        <a href="/fund-movements?fund_id=<?= (int)$mc['id_fund'] ?>" target="_blank" rel="noopener"><?= te('liquid_assets.report.open_fund_moves') ?></a>
      <?php endif; ?>
    </p>
  <?php else: ?>
    <p><b><?= e((string)$mc['label']) ?></b>: <?= te('liquid_assets.report.no_snap_no_fund') ?></p>
  <?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>
