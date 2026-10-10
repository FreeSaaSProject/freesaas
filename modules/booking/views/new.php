<?php
declare(strict_types=1);
/** @var string $date */
/** @var int $operatorId */
/** @var list<int> $productIds */
/** @var list<array{id:int,name:string,products:list<array<string,mixed>>}> $productGroups */
/** @var string $phone */
/** @var string $clientName */
/** @var int $clientId */
/** @var string $comment */
/** @var int $dtStart */
/** @var array<string,mixed>|null $availability */
/** @var string $flash */
/** @var string $error */
/** @var string $ret */
$productIds = $productIds ?? [];
$productGroups = $productGroups ?? [];
$availability = $availability ?? null;
$clientName = $clientName ?? '';
$clientId = (int)($clientId ?? 0);
$phone = $phone ?? '';
?>
<section class="card">
  <h1><?= te('booking.menu.new') ?></h1>
  <p class="muted"><?= te('booking.new.hint') ?></p>
  <ol class="muted booking-steps">
    <li><?= te('booking.new.step1') ?></li>
    <li><?= te('booking.new.step2') ?></li>
    <li><?= te('booking.new.step3') ?></li>
  </ol>

  <?php if ($flash !== ''): ?><div class="flash flash-ok"><?= e($flash) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

  <form method="get" action="/booking/new" class="form-grid" style="margin-bottom:1.5rem">
    <?php if ($ret !== ''): ?><input type="hidden" name="ret" value="<?= e($ret) ?>"><?php endif; ?>
    <label><?= te('booking.new.date') ?>
      <input type="date" name="date" value="<?= e($date) ?>" required>
    </label>

    <fieldset class="booking-products">
      <legend><?= te('booking.new.products') ?></legend>
      <p class="muted"><?= te('booking.new.products_hint') ?></p>
      <?php if ($productGroups === []): ?>
        <p class="muted"><?= te('booking.new.no_products') ?></p>
      <?php else: ?>
        <div class="booking-product-groups">
          <?php foreach ($productGroups as $group): ?>
            <details class="booking-cat"<?= count(array_intersect($productIds, array_map(static function ($p) {
                return (int)($p['id'] ?? 0);
            }, $group['products']))) > 0 ? ' open' : '' ?>>
              <summary><?= e((string)$group['name']) ?>
                <span class="muted">(<?= count($group['products']) ?>)</span>
              </summary>
              <ul class="booking-product-list">
                <?php foreach ($group['products'] as $p): ?>
                  <?php $pid = (int)($p['id'] ?? 0); ?>
                  <li>
                    <label>
                      <input type="checkbox" name="product_ids[]" value="<?= $pid ?>"
                        <?= in_array($pid, $productIds, true) ? ' checked' : '' ?>>
                      <?= e((string)($p['name'] ?? '')) ?>
                      <span class="muted">#<?= $pid ?> · <?= (int)round(((int)($p['time_sec'] ?? 0)) / 60) ?> <?= te('booking.new.min') ?></span>
                    </label>
                  </li>
                <?php endforeach; ?>
              </ul>
            </details>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </fieldset>

    <div class="form-actions">
      <button type="submit" class="btn-primary"><?= te('booking.new.show_slots') ?></button>
      <a class="btn-secondary" href="/calendar/events/new?date=<?= e($date) ?><?= $operatorId > 0 ? '&staff=' . (int)$operatorId : '' ?><?= $ret !== '' ? '&ret=' . e(rawurlencode($ret)) : '' ?>"><?= te('booking.new.link_event') ?></a>
    </div>
  </form>

  <?php if ($availability !== null): ?>
    <h2><?= te('booking.new.slots_title') ?></h2>
    <?php
    $emptyReason = (string)($availability['empty_reason'] ?? '');
    $availOps = array_values(array_filter(
        $availability['operators'] ?? [],
        static function ($op): bool {
            return !empty($op['available']) && !empty($op['slots']);
        }
    ));
    ?>
    <?php if ($availOps === []): ?>
      <?php if ($emptyReason === 'no_shifts'): ?>
        <p class="flash flash-warn"><?= te('booking.new.empty_no_shifts') ?></p>
      <?php elseif ($emptyReason === 'none_can_do'): ?>
        <p class="flash flash-warn"><?= te('booking.new.empty_none_can_do') ?></p>
      <?php else: ?>
        <p class="flash flash-warn"><?= te('booking.new.empty_no_slots') ?></p>
      <?php endif; ?>
    <?php else: ?>
      <form method="post" action="/booking/new" class="form-grid" id="booking-book-form">
        <input type="hidden" name="action" value="book">
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <input type="hidden" name="client_id" id="booking-client-id" value="<?= (int)$clientId ?>">
        <?php if ($ret !== ''): ?><input type="hidden" name="ret" value="<?= e($ret) ?>"><?php endif; ?>
        <?php foreach ($productIds as $pid): ?>
          <input type="hidden" name="product_ids[]" value="<?= (int)$pid ?>">
        <?php endforeach; ?>

        <label><?= te('booking.new.operator') ?>
          <select name="operator_id" required id="booking-operator">
            <option value="">—</option>
            <?php foreach ($availOps as $op): ?>
              <option value="<?= (int)$op['id'] ?>"
                      data-slots="<?= e(implode(',', array_map('strval', $op['slots'] ?? []))) ?>"
                      data-hhmm="<?= e(implode(',', $op['slots_hhmm'] ?? [])) ?>"
                <?= (int)$op['id'] === $operatorId ? ' selected' : '' ?>>
                <?= e((string)$op['name']) ?> (#<?= (int)$op['id'] ?>)
                — <?= count($op['slots'] ?? []) ?> <?= te('booking.new.slots_count') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label><?= te('booking.new.slot') ?>
          <select name="dt_start" required id="booking-slot">
            <option value="">—</option>
          </select>
        </label>

        <label><?= te('booking.new.phone') ?>
          <input type="tel" name="phone" id="booking-phone" value="<?= e($phone) ?>" required autocomplete="tel"
                 inputmode="numeric" placeholder="79…">
        </label>
        <div id="booking-client-suggest" class="booking-client-suggest" hidden></div>
        <p id="booking-client-new" class="muted booking-client-new"<?= $clientId > 0 ? ' hidden' : '' ?>><?= te('booking.new.client_will_create') ?></p>
        <p id="booking-client-picked" class="muted"<?= $clientId > 0 ? '' : ' hidden' ?>></p>

        <label><?= te('booking.new.client_name') ?>
          <input type="text" name="client_name" id="booking-client-name" value="<?= e($clientName) ?>"
                 placeholder="<?= te('booking.new.client_name_ph') ?>">
        </label>
        <label><?= te('booking.new.comment') ?>
          <input type="text" name="comment" value="<?= e($comment) ?>">
        </label>

        <div class="form-actions">
          <button type="submit" class="btn-primary"><?= te('booking.new.submit') ?></button>
        </div>
      </form>
      <script>
      (function () {
        var op = document.getElementById('booking-operator');
        var slot = document.getElementById('booking-slot');
        var preferred = <?= (int)$dtStart ?>;
        function refill() {
          slot.innerHTML = '<option value="">—</option>';
          var opt = op.options[op.selectedIndex];
          if (!opt || !opt.value) return;
          var slots = (opt.getAttribute('data-slots') || '').split(',').filter(Boolean);
          var hhmm = (opt.getAttribute('data-hhmm') || '').split(',');
          slots.forEach(function (ts, i) {
            var o = document.createElement('option');
            o.value = ts;
            o.textContent = hhmm[i] || ts;
            if (String(preferred) === String(ts)) o.selected = true;
            slot.appendChild(o);
          });
        }
        op.addEventListener('change', refill);
        refill();

        var phone = document.getElementById('booking-phone');
        var clientId = document.getElementById('booking-client-id');
        var clientName = document.getElementById('booking-client-name');
        var suggest = document.getElementById('booking-client-suggest');
        var willCreate = document.getElementById('booking-client-new');
        var picked = document.getElementById('booking-client-picked');
        var timer = null;
        var msgNew = <?= json_encode(t('booking.new.client_will_create'), JSON_UNESCAPED_UNICODE) ?>;
        var msgPicked = <?= json_encode(t('booking.new.client_picked'), JSON_UNESCAPED_UNICODE) ?>;
        var msgEmpty = <?= json_encode(t('booking.new.client_no_match'), JSON_UNESCAPED_UNICODE) ?>;

        function digits(v) { return String(v || '').replace(/\D+/g, ''); }
        function setNewState() {
          clientId.value = '0';
          willCreate.hidden = false;
          willCreate.textContent = msgNew;
          picked.hidden = true;
          picked.textContent = '';
        }
        function setPicked(item) {
          clientId.value = String(item.id || 0);
          if (item.tel) phone.value = digits(item.tel);
          if (item.name) clientName.value = item.name;
          willCreate.hidden = true;
          picked.hidden = false;
          picked.textContent = msgPicked.replace('{name}', item.name || '').replace('{tel}', item.tel || '').replace('{id}', String(item.id || ''));
          suggest.hidden = true;
          suggest.innerHTML = '';
        }
        function renderList(items) {
          suggest.innerHTML = '';
          if (!items.length) {
            suggest.hidden = true;
            setNewState();
            willCreate.textContent = msgEmpty + ' ' + msgNew;
            return;
          }
          willCreate.hidden = true;
          var ul = document.createElement('ul');
          items.forEach(function (item) {
            var li = document.createElement('li');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-secondary btn-sm';
            btn.textContent = (item.name || '') + ' · ' + (item.tel || '') + ' (#' + item.id + ')';
            btn.addEventListener('click', function () { setPicked(item); });
            li.appendChild(btn);
            ul.appendChild(li);
          });
          suggest.appendChild(ul);
          suggest.hidden = false;
        }
        function search() {
          var q = digits(phone.value);
          if (q.length < 3) {
            suggest.hidden = true;
            suggest.innerHTML = '';
            setNewState();
            return;
          }
          fetch('/booking/clients?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
          }).then(function (r) { return r.json(); }).then(function (data) {
            if (digits(phone.value) !== q) return;
            renderList((data && data.items) || []);
          }).catch(function () {
            setNewState();
          });
        }
        phone.addEventListener('input', function () {
          clientId.value = '0';
          clearTimeout(timer);
          timer = setTimeout(search, 250);
        });
        if (digits(phone.value).length >= 3) search();
      })();
      </script>
    <?php endif; ?>
  <?php elseif ($productIds === []): ?>
    <p class="muted"><?= te('booking.new.pick_products') ?></p>
  <?php endif; ?>
</section>
