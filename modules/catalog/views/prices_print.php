<?php
declare(strict_types=1);
/** @var array<string,mixed> $opts */
/** @var array{left:list<array<string,mixed>>,right:list<array<string,mixed>>,product_count:int} $data */
/** @var list<array{id:int,label:string,title:string,roots:list<int>,href:string}> $presets */
/** @var array<int,string> $root_choices */
/** @var string $query_string */
/** @var string $print_query_string */
/** @var bool $procedures */
$opts = is_array($opts ?? null) ? $opts : [];
$data = is_array($data ?? null) ? $data : ['left' => [], 'right' => [], 'product_count' => 0];
$presets = is_array($presets ?? null) ? $presets : [];
$rootChoices = is_array($root_choices ?? null) ? $root_choices : [];
$printMode = !empty($opts['print_mode']);
$cols = (int)($opts['cols'] ?? 2);
$hideDescr = !empty($opts['hide_descr']);
$selectedRoots = array_fill_keys(array_map('intval', $opts['roots'] ?? []), true);

$renderSection = static function (array $sec) use ($hideDescr, $printMode): void {
    $hasStrike = !empty($sec['has_strike']);
    $products = is_array($sec['products'] ?? null) ? $sec['products'] : [];
    ?>
    <table class="price-list-table">
      <thead>
        <tr>
          <th class="price-list-cat" colspan="<?= $hasStrike ? 3 : 2 ?>"><?= e(mb_strtoupper((string)($sec['category_name'] ?? ''))) ?></th>
        </tr>
        <tr>
          <th class="price-list-h-name"><?= te('catalog.prices.col.name') ?></th>
          <th class="price-list-h-price"><?= te('catalog.prices.col.price') ?></th>
          <?php if ($hasStrike): ?>
            <th class="price-list-h-sale"><?= te('catalog.prices.col.sale') ?></th>
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
              <b><?= e((string)($p['label'] ?? '')) ?></b>
              <?php if (!$printMode): ?>
                <button type="button" class="price-list-hide-btn no-print" data-good-id="<?= $pid ?>"><?= te('catalog.prices.hide_item') ?></button>
              <?php endif; ?>
              <?php if (!$hideDescr && trim((string)($p['description'] ?? '')) !== ''): ?>
                <br><i class="price-list-desc"><?= e((string)$p['description']) ?></i>
              <?php endif; ?>
            </td>
            <?php if ($strike !== null && $main !== null): ?>
              <td class="price-list-strike">
                <span class="price-list-strike-wrap"><?= e((string)(int)$strike) ?>
                  <?php if ((int)($p['discount_pct'] ?? 0) > 0): ?>
                    <small class="price-list-pct">−<?= (int)$p['discount_pct'] ?>%</small>
                  <?php endif; ?>
                </span>
              </td>
              <td class="price-list-main"><b><?= e((string)(int)$main) ?></b></td>
            <?php else: ?>
              <td class="price-list-main"<?= $hasStrike ? ' colspan="2"' : '' ?>>
                <b><?= $main !== null ? e((string)(int)$main) : '—' ?></b>
              </td>
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
  <p class="muted"><?= te('catalog.prices.lede', ['n' => (int)$data['product_count']]) ?></p>
  <div class="page-actions">
    <a class="btn-primary" href="/catalog/prices/print?<?= e($print_query_string) ?>"><?= te('catalog.prices.print_mode') ?></a>
    <button type="button" class="btn-secondary" onclick="window.print()"><?= te('catalog.prices.print_now') ?></button>
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
    <label class="check"><input type="checkbox" name="hide_descr" value="1"<?= $hideDescr ? ' checked' : '' ?>> <?= te('catalog.prices.hide_descr') ?></label>
    <label class="check"><input type="checkbox" name="show_discount" value="1"<?= !empty($opts['show_discount']) ? ' checked' : '' ?>> <?= te('catalog.prices.show_discount') ?></label>
    <fieldset class="price-list-orient">
      <legend><?= te('catalog.prices.orientation') ?></legend>
      <label class="check"><input type="radio" name="cols" value="1"<?= $cols === 1 ? ' checked' : '' ?>> <?= te('catalog.prices.orient_portrait') ?></label>
      <label class="check"><input type="radio" name="cols" value="2"<?= $cols === 2 ? ' checked' : '' ?>> <?= te('catalog.prices.orient_landscape') ?></label>
    </fieldset>
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
<section class="page-head no-print">
  <p class="muted"><?= te('catalog.prices.print_mode_hint') ?></p>
  <div class="page-actions">
    <button type="button" class="btn-primary" onclick="window.print()"><?= te('catalog.prices.print_now') ?></button>
    <a class="btn-secondary" href="/catalog/prices/print?<?= e(PriceListPrinter::queryString($opts, ['print_mode' => false])) ?>"><?= te('catalog.prices.exit_print') ?></a>
  </div>
</section>
<?php endif; ?>

<div class="price-list-sheet<?= $cols === 1 ? ' price-list-sheet--portrait' : ' price-list-sheet--landscape' ?><?= $printMode ? ' price-list-sheet--print' : '' ?>"
     data-price-list
     data-hidden="<?= e(implode(',', array_map('intval', $opts['hidden'] ?? []))) ?>"
     data-base-qs="<?= e($query_string) ?>">
  <header class="price-list-heading">
    <h1 class="price-list-brand"><?= e((string)($opts['title'] ?? '')) ?></h1>
    <p class="price-list-subtitle"><?= te('catalog.prices.sheet_subtitle') ?></p>
  </header>

  <?php if ((int)$data['product_count'] < 1): ?>
    <p class="muted"><?= te('catalog.prices.empty') ?></p>
  <?php elseif ($cols === 2): ?>
    <div class="price-list-columns">
      <div class="price-list-col price-list-col--left">
        <?php foreach ($data['left'] as $sec): ?>
          <?php $renderSection($sec); ?>
        <?php endforeach; ?>
      </div>
      <div class="price-list-col price-list-col--right">
        <?php foreach ($data['right'] as $sec): ?>
          <?php $renderSection($sec); ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="price-list-col price-list-col--single">
      <?php foreach (array_merge($data['left'], $data['right']) as $sec): ?>
        <?php $renderSection($sec); ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
