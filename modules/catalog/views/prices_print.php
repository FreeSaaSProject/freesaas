<?php
declare(strict_types=1);
/** @var array<string,mixed> $opts */
/** @var array{left:list<array<string,mixed>>,right:list<array<string,mixed>>,product_count:int} $price_list */
/** @var list<array{id:int,label:string,title:string,roots:list<int>,href:string}> $presets */
/** @var array<int,string> $root_choices */
/** @var string $query_string */
/** @var string $print_query_string */
/** @var bool $procedures */
$opts = is_array($opts ?? null) ? $opts : [];
$priceList = is_array($price_list ?? null) ? $price_list : ['left' => [], 'right' => [], 'product_count' => 0];
$presets = is_array($presets ?? null) ? $presets : [];
$rootChoices = is_array($root_choices ?? null) ? $root_choices : [];
$printMode = !empty($opts['print_mode']);
$cols = (int)($opts['cols'] ?? 2);
$hideDescr = !empty($opts['hide_descr']);
$scalePct = max(50, min(200, (int)($opts['scale'] ?? 100)));
$scaleAuto = !empty($opts['scale_auto']);
$selectedRoots = array_fill_keys(array_map('intval', $opts['roots'] ?? []), true);
$productCount = (int)($priceList['product_count'] ?? 0);
$leftSections = is_array($priceList['left'] ?? null) ? $priceList['left'] : [];
$rightSections = is_array($priceList['right'] ?? null) ? $priceList['right'] : [];
$scaleFactor = round($scalePct / 100, 3);

$renderSection = static function (array $sec) use ($hideDescr, $printMode): void {
    $hasStrike = !empty($sec['has_strike']);
    $products = is_array($sec['products'] ?? null) ? $sec['products'] : [];
    $catLabel = (string)($sec['category_name'] ?? '');
    $catPath = (string)($sec['category_path'] ?? $catLabel);
    ?>
    <table class="price-list-table">
      <thead>
        <tr>
          <th class="price-list-cat" title="<?= e($catPath) ?>"><?= e(mb_strtoupper($catLabel)) ?></th>
          <?php if ($hasStrike): ?>
            <th class="price-list-h-price"><?= te('catalog.prices.col.price') ?></th>
            <th class="price-list-h-sale"><?= te('catalog.prices.col.sale') ?></th>
          <?php else: ?>
            <th class="price-list-h-price"><?= te('catalog.prices.col.price') ?></th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($products as $i => $p): ?>
          <?php
            $pid = (int)($p['id'] ?? 0);
            $main = $p['main'] ?? null;
            $strike = $p['strike'] ?? null;
            $alt = ($i % 2) === 1;
          ?>
          <tr class="price-list-row<?= $alt ? ' price-list-row--alt' : '' ?>" data-good-id="<?= $pid ?>">
            <td class="price-list-name">
              <span class="price-list-label"><?= e((string)($p['label'] ?? '')) ?></span>
              <?php if (!$printMode): ?>
                <button type="button" class="price-list-hide-btn no-print" data-good-id="<?= $pid ?>"><?= te('catalog.prices.hide_item') ?></button>
              <?php endif; ?>
              <?php if (!$hideDescr && trim((string)($p['description'] ?? '')) !== ''): ?>
                <div class="price-list-desc"><?= e((string)$p['description']) ?></div>
              <?php endif; ?>
            </td>
            <?php if ($strike !== null && $main !== null): ?>
              <td class="price-list-strike">
                <span class="price-list-strike-wrap"><?= e((string)(int)$strike) ?></span>
                <?php if ((int)($p['discount_pct'] ?? 0) > 0): ?>
                  <small class="price-list-pct">−<?= (int)$p['discount_pct'] ?>%</small>
                <?php endif; ?>
              </td>
              <td class="price-list-main"><?= e((string)(int)$main) ?></td>
            <?php else: ?>
              <td class="price-list-main"<?= $hasStrike ? ' colspan="2"' : '' ?>><?= $main !== null ? e((string)(int)$main) : '—' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
};
?>

<?php if (!$printMode): ?>
<section class="page-head no-print">
  <h1><?= te('catalog.prices.title') ?></h1>
  <p class="muted"><?= te('catalog.prices.lede', ['n' => $productCount]) ?></p>
  <div class="page-actions">
    <a class="btn-primary" href="/catalog/prices/print?<?= e($print_query_string) ?>"><?= te('catalog.prices.print_mode') ?></a>
  </div>
</section>

<?php if ($presets !== []): ?>
<nav class="price-list-presets no-print" aria-label="<?= te('catalog.prices.presets_aria') ?>">
  <span class="muted"><?= te('catalog.prices.presets') ?>:</span>
  <?php foreach ($presets as $preset): ?>
    <?php
      $active = (int)($opts['host_id'] ?? 0) === (int)$preset['id']
          || ((string)($opts['title'] ?? '') === (string)$preset['title']
              && ($opts['roots'] ?? []) === ($preset['roots'] ?? []));
    ?>
    <a class="btn-secondary<?= $active ? ' is-active' : '' ?>" href="<?= e($preset['href']) ?>"><?= e($preset['label']) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<form class="price-list-config no-print" method="get" action="/catalog/prices/print" id="price-list-config">
  <fieldset>
    <legend><?= te('catalog.prices.config') ?></legend>
    <label><?= te('catalog.prices.sheet_title') ?>
      <input type="text" name="title" value="<?= e((string)($opts['title'] ?? '')) ?>" maxlength="200">
    </label>
    <?php if ((int)($opts['host_id'] ?? 0) > 0): ?>
      <input type="hidden" name="host" value="<?= (int)$opts['host_id'] ?>">
    <?php endif; ?>
    <label><?= te('catalog.prices.roots') ?>
      <select name="roots[]" multiple size="<?= min(8, max(3, count($rootChoices))) ?>">
        <?php foreach ($rootChoices as $rid => $rname): ?>
          <option value="<?= (int)$rid ?>"<?= isset($selectedRoots[(int)$rid]) ? ' selected' : '' ?>><?= e($rname) ?> (#<?= (int)$rid ?>)</option>
        <?php endforeach; ?>
      </select>
      <span class="muted"><?= te('catalog.prices.roots_hint') ?></span>
    </label>
    <?php if ($procedures): ?>
      <label><?= te('catalog.prices.kind') ?>
        <select name="kind">
          <option value="2"<?= (int)$opts['kind'] === 2 ? ' selected' : '' ?>><?= te('catalog.prices.kind_household') ?></option>
          <option value="1"<?= (int)$opts['kind'] === 1 ? ' selected' : '' ?>><?= te('catalog.prices.kind_medical') ?></option>
          <option value="0"<?= (int)$opts['kind'] === 0 ? ' selected' : '' ?>><?= te('catalog.prices.kind_all') ?></option>
        </select>
      </label>
    <?php else: ?>
      <input type="hidden" name="kind" value="0">
    <?php endif; ?>
    <label><?= te('catalog.prices.main_col') ?>
      <select name="main">
        <?php foreach (PriceListPrinter::PRICE_COLS as $col): ?>
          <option value="<?= e($col) ?>"<?= ($opts['main_col'] ?? '') === $col ? ' selected' : '' ?>><?= e($col) ?></option>
        <?php endforeach; ?>
        <option value="none"<?= ($opts['main_col'] ?? '') === 'none' ? ' selected' : '' ?>><?= te('catalog.prices.col_none') ?></option>
      </select>
    </label>
    <label><?= te('catalog.prices.strike_col') ?>
      <select name="strike">
        <option value="none"<?= ($opts['strike_col'] ?? '') === 'none' ? ' selected' : '' ?>><?= te('catalog.prices.col_none') ?></option>
        <?php foreach (PriceListPrinter::PRICE_COLS as $col): ?>
          <option value="<?= e($col) ?>"<?= ($opts['strike_col'] ?? '') === $col ? ' selected' : '' ?>><?= e($col) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <input type="hidden" name="hide_descr" value="0">
    <label class="check"><input type="checkbox" name="hide_descr" value="1"<?= $hideDescr ? ' checked' : '' ?>> <?= te('catalog.prices.hide_descr') ?></label>
    <label class="check"><input type="checkbox" name="show_discount" value="1"<?= !empty($opts['show_discount']) ? ' checked' : '' ?>> <?= te('catalog.prices.show_discount') ?></label>
    <fieldset class="price-list-orient">
      <legend><?= te('catalog.prices.orientation') ?></legend>
      <label class="check"><input type="radio" name="cols" value="1"<?= $cols === 1 ? ' checked' : '' ?>> <?= te('catalog.prices.orient_portrait') ?></label>
      <label class="check"><input type="radio" name="cols" value="2"<?= $cols === 2 ? ' checked' : '' ?>> <?= te('catalog.prices.orient_landscape') ?></label>
    </fieldset>
    <label><?= te('catalog.prices.scale') ?>
      <input type="number" name="scale" min="50" max="200" step="5" value="<?= $scaleAuto ? '' : (int)$scalePct ?>" placeholder="auto">
      <span class="muted"><?= te('catalog.prices.scale_hint') ?></span>
    </label>
    <?php if (($opts['hidden'] ?? []) !== []): ?>
      <input type="hidden" name="hidden" value="<?= e(implode(',', array_map('intval', $opts['hidden']))) ?>">
      <p class="muted"><?= te('catalog.prices.hidden_n', ['n' => count($opts['hidden'])]) ?>
        <a href="/catalog/prices/print?<?= e(PriceListPrinter::queryString($opts, ['hidden' => []])) ?>"><?= te('catalog.prices.clear_hidden') ?></a>
      </p>
    <?php endif; ?>
    <div class="page-actions">
      <button type="submit" class="btn-primary"><?= te('catalog.prices.apply') ?></button>
    </div>
  </fieldset>
</form>
<?php else: ?>
<section class="page-head no-print price-list-print-toolbar">
  <p class="muted"><?= te('catalog.prices.print_mode_hint') ?></p>
  <div class="page-actions price-list-scale-bar" data-price-scale-bar>
    <button type="button" class="btn-secondary" data-price-scale-delta="-5" title="<?= te('catalog.prices.scale_down') ?>">−</button>
    <span class="price-list-scale-label" data-price-scale-label><?= (int)$scalePct ?>%</span>
    <button type="button" class="btn-secondary" data-price-scale-delta="5" title="<?= te('catalog.prices.scale_up') ?>">+</button>
    <button type="button" class="btn-secondary" data-price-scale-autofit><?= te('catalog.prices.scale_autofit') ?></button>
    <button type="button" class="btn-primary" onclick="window.print()"><?= te('catalog.prices.print_now') ?></button>
    <a class="btn-secondary" href="/catalog/prices/print?<?= e(PriceListPrinter::queryString($opts, ['print_mode' => false, 'scale_auto' => true])) ?>"><?= te('catalog.prices.exit_print') ?></a>
  </div>
</section>
<?php endif; ?>

<div class="price-list-sheet<?= $cols === 1 ? ' price-list-sheet--portrait' : ' price-list-sheet--landscape' ?><?= $printMode ? ' price-list-sheet--print' : '' ?>"
     data-price-list
     data-hidden="<?= e(implode(',', array_map('intval', $opts['hidden'] ?? []))) ?>"
     data-base-qs="<?= e($query_string) ?>"
     data-scale="<?= (int)$scalePct ?>"
     data-scale-auto="<?= $scaleAuto ? '1' : '0' ?>"
     data-landscape="<?= $cols === 2 ? '1' : '0' ?>"
     style="--pl-scale: <?= e((string)$scaleFactor) ?>">
  <header class="price-list-heading">
    <h1 class="price-list-brand"><?= e((string)($opts['title'] ?? '')) ?></h1>
  </header>

  <?php if ($productCount < 1): ?>
    <p class="muted"><?= te('catalog.prices.empty') ?></p>
  <?php elseif ($cols === 2): ?>
    <?php
      $leftEmpty = $leftSections === [];
      $rightEmpty = $rightSections === [];
      $singleSide = ($leftEmpty && !$rightEmpty) || (!$leftEmpty && $rightEmpty);
    ?>
    <div class="price-list-columns<?= $singleSide ? ' is-single-side' : '' ?>">
      <div class="price-list-col price-list-col--left<?= $leftEmpty ? ' price-list-col--empty' : '' ?>">
        <?php foreach ($leftSections as $sec): ?>
          <?php $renderSection($sec); ?>
        <?php endforeach; ?>
      </div>
      <div class="price-list-col price-list-col--right<?= $rightEmpty ? ' price-list-col--empty' : '' ?>">
        <?php foreach ($rightSections as $sec): ?>
          <?php $renderSection($sec); ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <?php /* Portrait: CSS multi-column pack (oper kolonka) — fills one A4. */ ?>
    <div class="price-list-flow">
      <?php foreach (array_merge($leftSections, $rightSections) as $sec): ?>
        <?php $renderSection($sec); ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
