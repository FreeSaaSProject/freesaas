<?php
declare(strict_types=1);
/** @var array<string,mixed> $state */
$ok = !empty($state['ok']);
$mode = (string)($state['mode'] ?? 'error');
$error = (string)($state['error'] ?? '');
$message = (string)($state['message'] ?? '');
$q = is_array($state['query'] ?? null) ? $state['query'] : [];
$terminal = is_array($state['terminal'] ?? null) ? $state['terminal'] : [];
$guest = is_array($state['guest'] ?? null) ? $state['guest'] : [];
$catalogGroups = is_array($state['catalog_groups'] ?? null) ? $state['catalog_groups'] : [];
$slots = is_array($state['slots'] ?? null) ? $state['slots'] : [];
$operators = is_array($state['operators'] ?? null) ? $state['operators'] : [];
$gids = is_array($state['gids'] ?? null) ? $state['gids'] : [];
$history = is_array($state['history'] ?? null) ? $state['history'] : [];
$ticket = is_array($state['ticket'] ?? null) ? $state['ticket'] : [];
$diag = is_array($state['diag'] ?? null) ? $state['diag'] : [];
$dayLoad = is_array($state['day_load'] ?? null) ? $state['day_load'] : [];
$matrix = is_array($state['matrix'] ?? null) ? $state['matrix'] : ['columns' => [], 'rows' => []];
$flyer = is_array($state['flyer'] ?? null) ? $state['flyer'] : [];
$braceletNum = (int)($state['bracelet_num'] ?? 0);
$categoryId = (int)($state['category_id'] ?? 0);
$categoryName = (string)($state['category_name'] ?? '');
$operatorId = (int)($state['operator_id'] ?? 0);
$clientId = (int)($state['client_id'] ?? 0);
$baseQ = [
    'terminalid' => (string)($q['terminalid'] ?? $terminal['code'] ?? $terminal['id'] ?? ''),
    'braceletid' => (string)($q['braceletid'] ?? ''),
    'token' => (string)($q['token'] ?? ''),
];
if (!empty($q['disable1C'])) {
    $baseQ['disable1C'] = '1';
}
if (!empty($guest['force_limit']) || !empty($q['forceLimit'])) {
    $baseQ['forceLimit'] = '1';
}
if (!empty($flyer['on'])) {
    $baseQ['flyerOn'] = '1';
}
$gidsStr = implode('#', array_map('strval', $gids));
$colCount = max(1, count($dayLoad['operators'] ?? []));
$displayName = (string)($guest['display_name'] ?? $guest['name'] ?? '');
$balance = (float)($guest['balance'] ?? 0);
$showBottom = !in_array($mode, [
    'staff_claim', 'need_bracelet_num', 'need_phone', 'need_name',
    'cancel_confirm', 'cancelled', 'cancel_failed', 'booked', 'book', 'error',
], true);
$phoneHint = (string)($state['phone_hint'] ?? '');
$bookResults = is_array($state['results'] ?? null) ? $state['results'] : [];
$cart = is_array($state['cart'] ?? null) ? $state['cart'] : ['items' => [], 'sum' => 0.0];
$cancelMeta = is_array($state['cancel'] ?? null) ? $state['cancel'] : [];
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(t('terminals.t.title')) ?></title>
    <style>
        :root { --btn: #b8956a; --btn-text: #fff; --bg: #f7f4ef; --ink: #222; --muted: #777; --danger: #c0392b; --ok: #1a7a3c; }
        body { font-family: system-ui, sans-serif; margin: 0; background: var(--bg); color: var(--ink); }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 1rem; }
        h1 { font-size: 1.35rem; margin: .2rem 0 .8rem; }
        h2 { font-size: 1.05rem; margin: 0 0 .65rem; }
        .msg { padding: .75rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .msg.err { background: #fde8e8; color: #7a1f1f; }
        .msg.ok { background: #e5f6ea; color: #1f4d32; }
        .card { background: #fff; border-radius: 8px; padding: 1rem; margin-bottom: .85rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .book-stack { margin: .65rem 0 0; padding: 0; list-style: none; }
        .book-stack li { margin: .35rem 0; font-size: 1.05rem; line-height: 1.35; }
        .book-stack .mark { font-weight: 700; }
        .book-stack .mark.ok { color: var(--ok); }
        .book-stack .mark.bad { color: var(--danger); }
        .book-stack .ico { display: inline-block; width: 1.35rem; font-weight: 800; }
        .book-stack .meta-line { color: var(--muted); font-size: .95rem; font-weight: 400; }
        .scan-again { margin-top: 1.1rem; padding: .85rem 1rem; background: #fff6e8; border-radius: 8px;
            font-weight: 700; color: #7a4a00; }
        a.btn, button.btn, .site-button {
            display: inline-block; padding: .55rem .9rem; margin: .25rem .25rem .25rem 0;
            background: var(--btn); color: var(--btn-text); text-decoration: none; border: 0;
            border-radius: 10px; font-size: 1rem; font-weight: 600;
        }
        a.btn.secondary { background: #8a8074; }
        a.btn.danger, .site-button.red { background: #d4576b; }
        a.btn.busy { background: #a07070; }
        .meta, .diag { color: var(--muted); font-size: .9rem; margin: .4rem 0; }
        .diag a { color: inherit; }
        ul.cats { list-style: none; padding: 0; margin: 0; }
        ul.cats li { margin: .45rem 0; }
        ul.cats a.btn { display: block; text-align: center; }
        .num-line { margin: .25rem 0 .75rem; }
        .num-line b { color: var(--danger); }
        .ticket-name { font-weight: 700; margin: .5rem 0; }
        #clockdiv .hours, #clockdiv .minutes, #clockdiv .seconds {
            display: inline-block; min-width: 2.2rem; text-align: center;
            background: var(--btn); color: #fff; border-radius: 8px; padding: .25rem .4rem; font-weight: 700;
        }
        table.hist, table.matrix { width: 100%; border-collapse: collapse; font-size: .9rem; }
        table.hist td, table.matrix td, table.matrix th {
            border: 1px solid #ddd; padding: .4rem .45rem; vertical-align: top; text-align: center;
        }
        table.matrix th:first-child, table.matrix td:first-child { text-align: left; }
        table.matrix a.cell-btn {
            display: block; background: var(--btn); color: #fff; text-decoration: none;
            border-radius: 8px; padding: .45rem .35rem; font-weight: 700; position: relative;
        }
        table.matrix a.cell-btn sup {
            position: absolute; top: .15rem; right: .35rem; font-size: .7rem; font-weight: 600; opacity: .95;
        }
        .cell-mute { color: var(--muted); font-size: .85rem; }
        .back { display: inline-block; margin-bottom: .65rem; color: var(--muted); }
        ul.slots { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: .4rem; }
        ul.slots a { background: #2f6f4e; }
        .dayboard { position: relative; border: 2px solid #888; background: #fafafa; min-height: 420px; overflow: hidden; }
        .dayboard-ops { display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: .5rem; font-size: .9rem; }
        .dayboard-ops span { padding: .1rem .35rem; border-radius: 4px; color: #111; }
        .dayboard-ops span.is-load-min { box-shadow: 0 0 0 2px var(--ok); font-weight: 800; }
        .dayboard-ops span.is-load-high { box-shadow: 0 0 0 1px rgba(192, 57, 43, .45); font-weight: 700; }
        .timing-panel { margin: .75rem 0 0; color: var(--muted); font-size: .85rem; }
        .timing-panel summary { cursor: pointer; list-style: none; }
        .timing-panel summary::-webkit-details-marker { display: none; }
        .timing-panel summary::before { content: "▸ "; }
        .timing-panel[open] summary::before { content: "▾ "; }
        .timing-stages { margin-top: .45rem; }
        .timing-stages ul { margin: .35rem 0; padding-left: 1.1rem; }
        .timing-stages li { margin: .1rem 0; }
        .dayboard-grid { position: relative; height: 420px; display: grid; gap: 1px; background: #ccc; }
        .dayboard-col { position: relative; background: #fff; }
        .dayboard-mark { position: absolute; left: 0; right: 0; border-top: 1px dotted #bbb; font-size: .7rem; color: #888; padding-left: .2rem; pointer-events: none; }
        .dayboard-block {
            position: absolute; left: 3%; right: 3%; border-radius: 3px; color: #111;
            font-size: .68rem; line-height: 1.15; padding: .15rem .2rem; overflow: hidden;
            pointer-events: none; opacity: .92; border: 1px solid rgba(0,0,0,.15);
        }
        .num-form input, .rename-form input { font-size: 1.1rem; padding: .5rem; }
        .guest-line a.name { text-decoration: underline; color: inherit; }
        .force-warn { color: var(--danger); font-weight: 700; margin: .35rem 0; }
        .cat-bar { margin: .5rem 0 1rem; }
        .matrix-wrap { overflow-x: auto; }
    </style>
</head>
<body>
<div class="wrap">
    <?php if ($error !== ''): ?>
        <div class="msg err"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
        <div class="msg ok"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($mode === 'staff_claim'): ?>
        <div class="card"><h1><?= e(t('terminals.t.staff_claimed')) ?></h1></div>
    <?php elseif ($mode === 'need_bracelet_num'): ?>
        <div class="card">
            <h1 style="color:var(--danger)"><?= e(t('terminals.t.num_required')) ?></h1>
            <form class="num-form" method="get" action="/t/">
                <?php foreach ($baseQ as $k => $v): ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
                <?php endforeach; ?>
                <label><?= e(t('terminals.t.num_label')) ?>
                    <input type="number" name="braceletNum" min="1" required autofocus>
                </label>
                <button class="btn" type="submit"><?= e(t('core.common.save')) ?></button>
            </form>
        </div>
    <?php elseif ($mode === 'need_phone'): ?>
        <div class="card">
            <p><?= e(t('terminals.t.phone_lede')) ?></p>
            <h2><?= e(t('terminals.t.phone_title')) ?></h2>
            <?php if ($phoneHint !== ''): ?>
                <p class="meta">
                    <?= e(t('terminals.t.phone_from_1c', ['phone' => $phoneHint])) ?>
                    <a class="btn" href="/t/?<?= e(http_build_query($baseQ + ['clientPhoneSent' => $phoneHint])) ?>"><?= e(t('terminals.t.phone_use')) ?></a>
                </p>
            <?php endif; ?>
            <form class="num-form" method="get" action="/t/">
                <?php foreach ($baseQ as $k => $v): ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
                <?php endforeach; ?>
                <label><?= e(t('terminals.t.phone_label')) ?>
                    <input type="tel" name="clientPhoneSent" class="js-phone-ru" required autofocus
                           autocomplete="tel" maxlength="18"
                           placeholder="+7 (___) ___-__-__" value=""
                           data-phone-invalid="<?= e(t('terminals.t.err.phone_format')) ?>">
                </label>
                <button class="btn" type="submit"><?= e(t('core.common.save')) ?></button>
            </form>
            <script src="/modules/terminals/assets/js/phone-ru.js"></script>
        </div>
    <?php elseif ($mode === 'need_name'): ?>
        <div class="card">
            <h2><?= e(t('terminals.t.rename_label')) ?></h2>
            <form class="rename-form" method="get" action="/t/">
                <?php foreach ($baseQ as $k => $v): ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
                <?php endforeach; ?>
                <label><?= e(t('terminals.t.rename_label')) ?>
                    <input name="userNameSent" value="<?= e($displayName) ?>" required autofocus
                           placeholder="<?= e(t('terminals.t.rename_label')) ?>">
                </label>
                <button class="btn" type="submit"><?= e(t('core.common.save')) ?></button>
            </form>
        </div>
    <?php elseif ($mode === 'cancel_confirm'): ?>
        <div class="card">
            <h1 style="color:var(--danger)"><?= e(t('terminals.t.cancel_title')) ?></h1>
            <p><?= e(t('terminals.t.cancel_lede')) ?></p>
            <?php if ((string)($cancelMeta['product_name'] ?? '') !== ''): ?>
                <p class="meta"><?= e((string)$cancelMeta['product_name']) ?>
                    · <?= e(date('d.m.Y H:i', (int)($cancelMeta['dtstart'] ?? time()))) ?></p>
            <?php endif; ?>
            <form class="num-form" method="get" action="/t/">
                <?php foreach ($baseQ as $k => $v): ?>
                    <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="doStart" value="2">
                <input type="hidden" name="oldProcedureOff" value="<?= (int)($cancelMeta['procedure_id'] ?? 0) ?>">
                <label><?= e(t('terminals.t.cancel_reason')) ?>
                    <input name="doConfirm" required autofocus placeholder="<?= e(t('terminals.t.cancel_reason')) ?>">
                </label>
                <?php if (!empty($cancelMeta['need_login'])): ?>
                    <label><?= e(t('terminals.t.cancel_login')) ?>
                        <input name="loginUser" required placeholder="<?= e(t('terminals.t.cancel_login_ph')) ?>">
                    </label>
                <?php endif; ?>
                <button class="btn danger" type="submit"><?= e(t('terminals.t.cancel_submit')) ?></button>
                <a class="btn secondary" href="/t/?<?= e(http_build_query($baseQ)) ?>"><?= e(t('terminals.t.cancel_abort')) ?></a>
            </form>
        </div>
    <?php else: ?>

        <?php if ($braceletNum > 0 && !empty($terminal['is_bracelet_num_prompt'])): ?>
            <div class="num-line">
                <?= e(t('terminals.t.num_line')) ?>:
                <b><?= (int)$braceletNum ?></b>
                <a href="/t/?<?= e(http_build_query($baseQ + ['braceletNum' => -1])) ?>">[<?= e(t('terminals.t.num_change')) ?>]</a>
            </div>
        <?php endif; ?>

        <h1 class="guest-line">
            <?= e(t('terminals.t.bracelet')) ?>:
            <?= $clientId > 0 ? (int)$clientId . '.' : e((string)$baseQ['braceletid']) ?>
            <?php if ($displayName !== ''): ?>
                <?php if ($clientId > 0): ?>
                    <a class="name" href="/t/?<?= e(http_build_query($baseQ + array_filter([
                        'category' => $categoryId > 0 ? (string)$categoryId : null,
                        'rename' => '1',
                    ]))) ?>">[<?= e($displayName) ?>]</a>
                <?php else: ?>
                    [<?= e($displayName) ?>]
                <?php endif; ?>
            <?php endif; ?>
            [<?= e(number_format($balance, 0, '.', ' ')) ?> ₽]
            <a class="btn" href="/t/?<?= e(http_build_query($baseQ + array_filter([
                'category' => $categoryId > 0 ? (string)$categoryId : null,
                'forceLimit' => '1',
            ]))) ?>">Ф?</a>
        </h1>
        <?php if (!empty($guest['force_limit'])): ?>
            <div class="force-warn">
                <?= e(t('terminals.t.force_on')) ?>
                <?php
                $noForce = $baseQ;
                unset($noForce['forceLimit']);
                if ($categoryId > 0) {
                    $noForce['category'] = (string)$categoryId;
                }
                ?>
                <a class="btn danger" href="/t/?<?= e(http_build_query($noForce)) ?>"><?= e(t('terminals.t.force_off')) ?></a>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'rename' && $clientId > 0): ?>
            <div class="card">
                <form class="rename-form" method="get" action="/t/">
                    <?php foreach ($baseQ as $k => $v): ?>
                        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
                    <?php endforeach; ?>
                    <?php if ($categoryId > 0): ?>
                        <input type="hidden" name="category" value="<?= (int)$categoryId ?>">
                    <?php endif; ?>
                    <label><?= e(t('terminals.t.rename_label')) ?>
                        <input name="userNameSent" value="<?= e($displayName) ?>" required autofocus>
                    </label>
                    <button class="btn" type="submit"><?= e(t('core.common.save')) ?></button>
                    <a class="btn secondary" href="/t/?<?= e(http_build_query($baseQ + ($categoryId > 0 ? ['category' => (string)$categoryId] : []))) ?>"><?= e(t('core.common.cancel')) ?></a>
                </form>
            </div>
        <?php elseif (in_array($mode, ['booked', 'book', 'cancelled', 'cancel_failed'], true) && $bookResults !== []): ?>
            <div class="card">
                <?php
                $isCancelStack = $mode === 'cancelled' || $mode === 'cancel_failed';
                $stackTitle = $mode === 'booked'
                    ? t('terminals.t.booked')
                    : ($mode === 'cancelled'
                        ? t('terminals.t.cancelled')
                        : ($isCancelStack ? t('terminals.t.err.cancel_proc') : t('terminals.t.err.book')));
                ?>
                <h1><?= e($stackTitle) ?></h1>
                <?php foreach ($bookResults as $br): ?>
                    <?php
                    if (!is_array($br)) {
                        continue;
                    }
                    $pname = (string)($br['product_name'] ?? '');
                    $pprice = (float)($br['price'] ?? 0);
                    $stack = is_array($br['stack'] ?? null) ? $br['stack'] : [];
                    ?>
                    <?php if ($pname !== ''): ?>
                        <p class="meta-line"><?= e(t('terminals.t.stack.product', [
                            'name' => $pname,
                            'price' => rtrim(rtrim(number_format($pprice, 2, '.', ''), '0'), '.'),
                        ])) ?></p>
                    <?php endif; ?>
                    <ul class="book-stack">
                        <?php foreach ($stack as $step): ?>
                            <?php
                            if (!is_array($step)) {
                                continue;
                            }
                            $key = (string)($step['key'] ?? '');
                            $stepOk = !empty($step['ok']);
                            $markClass = $stepOk ? 'ok' : 'bad';
                            $ico = $stepOk ? '✓' : '✗';
                            $label = '';
                            if ($key === 'sale') {
                                if (!empty($step['skipped'])) {
                                    $label = t('terminals.t.stack.sale_skip');
                                } elseif ($stepOk && !empty($step['stub'])) {
                                    $label = t('terminals.t.stack.sale_stub', [
                                        'id' => (string)($step['sale_id'] ?? ''),
                                    ]);
                                } elseif ($stepOk) {
                                    $label = t('terminals.t.stack.sale_ok', [
                                        'id' => (string)($step['sale_id'] ?? ''),
                                    ]);
                                } else {
                                    $label = t('terminals.t.stack.sale_fail', [
                                        'detail' => (string)($step['detail'] ?? ''),
                                    ]);
                                }
                            } elseif ($key === 'unsale') {
                                if (!empty($step['skipped'])) {
                                    $label = t('terminals.t.stack.unsale_skip');
                                } elseif ($stepOk && !empty($step['stub'])) {
                                    $label = t('terminals.t.stack.unsale_stub', [
                                        'id' => (string)($step['sale_id'] ?? ''),
                                    ]);
                                } elseif ($stepOk) {
                                    $label = t('terminals.t.stack.unsale_ok', [
                                        'id' => (string)($step['sale_id'] ?? ''),
                                    ]);
                                } else {
                                    $label = t('terminals.t.stack.unsale_fail', [
                                        'detail' => (string)($step['detail'] ?? ''),
                                    ]);
                                }
                            } elseif ($key === 'procedure') {
                                if (!empty($step['cancel'])) {
                                    $label = $stepOk
                                        ? t('terminals.t.stack.cancel_proc_ok', [
                                            'id' => (string)(int)($step['procedure_id'] ?? 0),
                                        ])
                                        : t('terminals.t.stack.cancel_proc_fail', [
                                            'detail' => (string)($step['detail'] ?? ''),
                                        ]);
                                } elseif ($stepOk) {
                                    $until = (int)($step['dt_end'] ?? 0);
                                    $label = t('terminals.t.stack.proc_ok', [
                                        'until' => $until > 0 ? date('d.m.Y H:i', $until) : '—',
                                        'id' => (string)(int)($step['procedure_id'] ?? 0),
                                    ]);
                                } else {
                                    $label = t('terminals.t.stack.proc_fail', [
                                        'detail' => (string)($step['detail'] ?? ''),
                                    ]);
                                }
                            } elseif ($key === 'admin') {
                                if (!empty($step['cancel'])) {
                                    $label = $stepOk
                                        ? t('terminals.t.stack.cancel_admin_ok', [
                                            'id' => (string)(int)($step['procedure_id'] ?? 0),
                                        ])
                                        : t('terminals.t.stack.cancel_admin_fail', [
                                            'detail' => (string)($step['detail'] ?? ''),
                                        ]);
                                } else {
                                    $label = $stepOk
                                        ? t('terminals.t.stack.admin_ok', [
                                            'id' => (string)(int)($step['procedure_id'] ?? 0),
                                        ])
                                        : t('terminals.t.stack.admin_fail', [
                                            'detail' => (string)($step['detail'] ?? ''),
                                        ]);
                                }
                            } else {
                                continue;
                            }
                            ?>
                            <li>
                                <span class="mark <?= e($markClass) ?>">
                                    <span class="ico" aria-hidden="true"><?= e($ico) ?></span><?= e($label) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
                <?php if ($mode === 'booked' || $mode === 'cancelled'): ?>
                    <div class="scan-again"><?= e(t('terminals.t.scan_again')) ?></div>
                <?php endif; ?>
            </div>
        <?php elseif ($mode === 'pick_slot'): ?>
            <div class="card">
                <?php
                $backCat = $baseQ;
                $backCat['gids'] = $gidsStr;
                if ($categoryId > 0) {
                    $backCat['category'] = (string)$categoryId;
                }
                if ($operatorId > 0) {
                    $backCat['adminId'] = (string)$operatorId;
                }
                ?>
                <a class="back" href="/t/?<?= e(http_build_query($backCat)) ?>"><?= e(t('terminals.t.back_catalog')) ?></a>
                <h2><?= e(t('terminals.t.pick_slot')) ?></h2>
                <ul class="slots">
                    <?php foreach ($slots as $ts): ?>
                        <?php
                        $ts = (int)$ts;
                        $pq = $baseQ;
                        $pq['adminId'] = (string)$operatorId;
                        $pq['gids'] = $gidsStr;
                        $pq['getTime'] = (string)$ts;
                        if ($categoryId > 0) {
                            $pq['category'] = (string)$categoryId;
                        }
                        ?>
                        <li><a class="btn" href="/t/?<?= e(http_build_query($pq)) ?>"><?= e(date('H:i', $ts)) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($slots === []): ?>
                    <p><?= e(t('terminals.t.no_slots')) ?></p>
                <?php endif; ?>
            </div>
        <?php elseif ($mode === 'category'): ?>
            <div class="cat-bar">
                <?= e(t('terminals.t.category')) ?>: <?= e($categoryName !== '' ? $categoryName : ('#' . $categoryId)) ?>
                <a class="btn" href="/t/?<?= e(http_build_query($baseQ)) ?>"><?= e(t('terminals.t.category_change')) ?></a>
                <?php if (!empty($flyer['enabled'])): ?>
                    <?php if (!empty($flyer['on'])): ?>
                        <?php $noFlyer = $baseQ; unset($noFlyer['flyerOn']); $noFlyer['category'] = (string)$categoryId; ?>
                        <a class="btn" href="/t/?<?= e(http_build_query($noFlyer + ($operatorId > 0 ? ['adminId' => (string)$operatorId] : []))) ?>">
                            -<?= (int)$flyer['pct'] ?>% <?= e(t('terminals.t.flyer_off')) ?>
                        </a>
                    <?php else: ?>
                        <a class="btn" href="/t/?<?= e(http_build_query($baseQ + ['category' => (string)$categoryId, 'flyerOn' => '1'] + ($operatorId > 0 ? ['adminId' => (string)$operatorId] : []))) ?>">
                            -<?= (int)$flyer['pct'] ?>%?
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="card matrix-wrap">
                <?php
                $matrixRows = is_array($matrix['rows'] ?? null) ? $matrix['rows'] : [];
                $colspan = 1 + count($matrix['columns'] ?? []);
                $cartBusy = false;
                $cartBusyNote = '';
                if ($operatorId > 0) {
                    foreach (($matrix['columns'] ?? []) as $col) {
                        if ((int)($col['id'] ?? 0) === $operatorId && !empty($col['busy'])) {
                            $cartBusy = true;
                            $cartBusyNote = t('terminals.t.cell_busy', [
                                'term' => (string)$col['busy_terminal'],
                                'until' => $col['busy_until'] > 0 ? date('H:i:s', (int)$col['busy_until']) : '',
                            ]);
                            break;
                        }
                    }
                }
                $cartShown = false;
                ?>
                <table class="matrix">
                    <thead>
                    <tr>
                        <th><?= e(t('terminals.t.col_service')) ?></th>
                        <?php foreach (($matrix['columns'] ?? []) as $col): ?>
                            <th>
                                <?php if ($operatorId > 0): ?>
                                    <span style="background:<?= e((string)$col['color']) ?>;color:<?= e((string)$col['color_text']) ?>;padding:.2rem .4rem;border-radius:6px;display:inline-block">
                                        <?= e(trim((string)$col['table_name'] . ' ' . (string)$col['last'])) ?><br><?= e((string)$col['first']) ?>
                                    </span>
                                    <br><a class="btn secondary" href="/t/?<?= e(http_build_query($baseQ + ['category' => (string)$categoryId])) ?>"><?= e(t('terminals.t.op_change')) ?></a>
                                <?php elseif (!empty($col['busy'])): ?>
                                    <b style="background:<?= e((string)$col['color']) ?>;color:<?= e((string)$col['color_text']) ?>;padding:.2rem .4rem;border-radius:6px;display:inline-block">
                                        <?= e(trim((string)$col['table_name'] . ' ' . (string)$col['last'])) ?><br><?= e((string)$col['first']) ?>
                                    </b>
                                    <br><span class="cell-mute">✗ <?= e(t('terminals.t.until')) ?> <?= e(date('H:i:s', (int)$col['busy_until'])) ?> <?= e((string)$col['busy_terminal']) ?></span>
                                <?php else: ?>
                                    <a class="btn" style="background:<?= e((string)$col['color']) ?>;color:<?= e((string)$col['color_text']) ?>"
                                       href="/t/?<?= e(http_build_query($baseQ + ['category' => (string)$categoryId, 'adminId' => (string)(int)$col['id']])) ?>">
                                        <?= e(trim((string)$col['table_name'] . ' ' . (string)$col['last'])) ?><br><?= e((string)$col['first']) ?>
                                    </a>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($matrixRows as $ri => $row): ?>
                        <?php
                        $isSelected = !empty($row['selected']);
                        $nextSelected = !empty($matrixRows[$ri + 1]['selected']);
                        ?>
                        <tr>
                            <td><?= e((string)$row['name']) ?></td>
                            <?php foreach (($row['cells'] ?? []) as $cell): ?>
                                <td>
                                    <?php if (($cell['kind'] ?? '') === 'book'): ?>
                                        <?php
                                        $pq = $baseQ;
                                        $pq['category'] = (string)$categoryId;
                                        $pq['adminId'] = (string)(int)($cell['href_admin'] ?? 0);
                                        $pq['gids'] = (string)($cell['href_gids'] ?? '');
                                        ?>
                                        <a class="cell-btn" href="/t/?<?= e(http_build_query($pq)) ?>">
                                            <?= e(number_format((float)$cell['price'], 0, '.', ' ')) ?> ₽
                                            <?php if ((int)($cell['slot'] ?? 0) > 0): ?>
                                                <sup><?= e(date('H:i', (int)$cell['slot'])) ?></sup>
                                            <?php endif; ?>
                                        </a>
                                    <?php elseif (($cell['kind'] ?? '') === 'selected'): ?>
                                        <?php
                                        $rq = $baseQ;
                                        $rq['category'] = (string)$categoryId;
                                        $rq['adminId'] = (string)(int)($cell['href_admin'] ?? $operatorId);
                                        if ((string)($cell['href_gids'] ?? '') !== '') {
                                            $rq['gids'] = (string)$cell['href_gids'];
                                        }
                                        ?>
                                        <a class="cell-btn" style="background:#d4576b" href="/t/?<?= e(http_build_query($rq)) ?>">
                                            <?= e(number_format((float)$cell['price'], 0, '.', ' ')) ?> ₽
                                            <br><i><?= e(t('terminals.t.cart_remove')) ?></i>
                                        </a>
                                    <?php elseif (($cell['kind'] ?? '') === 'none'): ?>
                                        <span class="cell-mute"><?= e(t('terminals.t.cell_none')) ?></span>
                                    <?php else: ?>
                                        <span class="cell-mute">
                                            <?= e(number_format((float)($cell['price'] ?? 0), 0, '.', ' ')) ?> ₽
                                            <?php if (!empty($cell['note'])): ?><br><?= e((string)$cell['note']) ?><?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php if ($isSelected && !$nextSelected && $operatorId > 0 && !$cartShown): ?>
                            <?php $cartShown = true; ?>
                            <tr class="cart-sum-row">
                                <td style="text-align:right;vertical-align:middle">
                                    <h4 style="margin:.2rem 0"><?= e(t('terminals.t.cart_sum')) ?></h4>
                                    <a class="btn danger" href="/t/?<?= e(http_build_query($baseQ + [
                                        'category' => (string)$categoryId,
                                        'adminId' => (string)$operatorId,
                                    ])) ?>"><?= e(t('terminals.t.cart_clear')) ?></a>
                                </td>
                                <td colspan="<?= max(1, $colspan - 1) ?>" style="vertical-align:middle">
                                    <h4 style="margin:.2rem 0;display:inline">
                                        <?= e(number_format((float)($cart['sum'] ?? 0), 0, '.', ' ')) ?>
                                    </h4>
                                    <span class="cell-mute"> <?= e(t('terminals.t.cart_of_balance', [
                                        'balance' => number_format($balance, 0, '.', ' '),
                                    ])) ?></span>
                                    <?php if ($cartBusy): ?>
                                        <div class="cell-mute"><?= e($cartBusyNote) ?></div>
                                    <?php else: ?>
                                        <?php
                                        $contQ = $baseQ + [
                                            'category' => (string)$categoryId,
                                            'adminId' => (string)$operatorId,
                                            'gids' => $gidsStr,
                                            'getTime' => '1',
                                        ];
                                        ?>
                                        <a class="btn" style="background:#2a9d8f" href="/t/?<?= e(http_build_query($contQ)) ?>"><?= e(t('terminals.t.cart_continue')) ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($matrixRows === []): ?>
                        <tr><td colspan="<?= $colspan ?>" class="cell-mute"><?= e(t('terminals.t.catalog_empty')) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($ok && $mode === 'home'): ?>
            <div class="card">
                <ul class="cats">
                    <?php foreach ($catalogGroups as $group): ?>
                        <?php if (($group['products'] ?? []) === []) { continue; } ?>
                        <?php
                        $pq = $baseQ;
                        $pq['category'] = (string)(int)($group['id'] ?? 0);
                        ?>
                        <li><a class="btn" href="/t/?<?= e(http_build_query($pq)) ?>"><?= e((string)($group['name'] ?? '')) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($catalogGroups === []): ?>
                    <p><?= e(t('terminals.t.catalog_empty')) ?></p>
                <?php endif; ?>
            </div>
            <?php if ($ticket !== []): ?>
                <div class="card">
                    <div class="ticket-name"><?= e((string)($ticket['ticket_name'] ?? '')) ?></div>
                    <div id="clockdiv">
                        <?= e(t('terminals.t.time_left')) ?>:
                        <span class="hours"><?= sprintf('%02d', (int)($ticket['h'] ?? 0)) ?></span> :
                        <span class="minutes"><?= sprintf('%02d', (int)($ticket['m'] ?? 0)) ?></span> :
                        <span class="seconds"><?= sprintf('%02d', (int)($ticket['s'] ?? 0)) ?></span>
                        <br><?= e(t('terminals.t.leave_by')) ?>: <?= e(date('H:i', (int)($ticket['deadline'] ?? time()))) ?>
                    </div>
                </div>
                <script>
                (function () {
                    var end = <?= (int)($ticket['deadline'] ?? 0) ?> * 1000;
                    var root = document.getElementById('clockdiv');
                    if (!root || !end) return;
                    var h = root.querySelector('.hours'), m = root.querySelector('.minutes'), s = root.querySelector('.seconds');
                    function tick() {
                        var total = end - Date.now();
                        if (total < 0) total = 0;
                        var sec = Math.floor(total / 1000);
                        h.textContent = ('0' + Math.floor(sec / 3600)).slice(-2);
                        m.textContent = ('0' + Math.floor((sec % 3600) / 60)).slice(-2);
                        s.textContent = ('0' + (sec % 60)).slice(-2);
                    }
                    tick();
                    setInterval(tick, 1000);
                })();
                </script>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($showBottom && $history !== []): ?>
            <div class="card">
                <h2><?= e(t('terminals.t.history')) ?></h2>
                <table class="hist">
                    <?php foreach ($history as $hrow): ?>
                        <tr>
                            <td>
                                <?= e(date('d.m.Y H:i', (int)$hrow['dtstart'])) ?>
                                [<?= (int)$hrow['minutes'] ?> <?= e(t('terminals.t.minutes')) ?>]
                                <?php if ((string)($hrow['operator_acronym'] ?? '') !== ''): ?>
                                    [<?= e((string)$hrow['operator_acronym']) ?>]
                                <?php endif; ?>
                                <?php if (empty($hrow['enabled'])): ?>
                                    <br><b style="color:var(--danger)"><?= e(t('terminals.t.proc_cancelled')) ?></b>
                                <?php elseif (!empty($hrow['can_cancel'])): ?>
                                    <?php
                                    $cq = $baseQ;
                                    $cq['doStart'] = '2';
                                    $cq['oldProcedureOff'] = (string)(int)$hrow['id'];
                                    if ($categoryId > 0) {
                                        $cq['category'] = (string)$categoryId;
                                    }
                                    $label = !empty($hrow['cancel_staff_only'])
                                        ? t('terminals.t.cancel_staff')
                                        : t('terminals.t.cancel');
                                    ?>
                                    <a class="btn danger" href="/t/?<?= e(http_build_query($cq)) ?>"><?= e($label) ?></a>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string)$hrow['product_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($showBottom && (($dayLoad['operators'] ?? []) !== [] || ($dayLoad['blocks'] ?? []) !== [])): ?>
            <div class="card">
                <div class="dayboard-ops">
                    <?php foreach (($dayLoad['operators'] ?? []) as $op): ?>
                        <?php
                        $loadClass = '';
                        if (isset($op['load_pct']) && empty($op['is_hidden'])) {
                            $loadClass = !empty($op['load_min']) ? ' is-load-min' : ' is-load-high';
                        }
                        ?>
                        <span class="<?= e(trim($loadClass)) ?>" style="background:<?= e((string)$op['color']) ?>">[<?= e((string)$op['acronym']) ?>]<?php
                            if (isset($op['load_pct']) && empty($op['is_hidden'])): ?> <?= (int)$op['load_pct'] ?>%<?php endif;
                        ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="dayboard">
                    <div class="dayboard-grid" style="grid-template-columns: repeat(<?= (int)$colCount ?>, 1fr);">
                        <?php foreach (($dayLoad['operators'] ?? []) as $idx => $op): ?>
                            <div class="dayboard-col">
                                <?php foreach (($dayLoad['hour_marks'] ?? []) as $mark): ?>
                                    <div class="dayboard-mark" style="top:<?= e((string)$mark['top_pct']) ?>%"><?= e((string)$mark['label']) ?></div>
                                <?php endforeach; ?>
                                <?php foreach (($dayLoad['blocks'] ?? []) as $block): ?>
                                    <?php if ((int)($block['col'] ?? -1) !== (int)$idx) { continue; } ?>
                                    <div class="dayboard-block"
                                         style="top:<?= e((string)$block['top_pct']) ?>%;height:<?= e((string)$block['height_pct']) ?>%;background:<?= e((string)$block['color']) ?>;"
                                         title="<?= e((string)$block['label']) ?>"><?= e((string)$block['label']) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($showBottom && $diag !== []): ?>
            <p class="diag">
                [<?= e(t('terminals.t.diag.onec_req')) ?>: <?= (int)($diag['onec_requests'] ?? 0) ?>]
                [<?= e(date('H:i:s d.m.y', (int)($diag['accessed_at'] ?? time()))) ?>]
                <?php if ((int)($diag['cache_ttl'] ?? 0) > 0): ?>
                    [<?= e(t('terminals.t.diag.cache')) ?>:
                    <a href="/t/?<?= e(http_build_query($baseQ + ['resetCache' => 1] + ($categoryId > 0 ? ['category' => (string)$categoryId] : []))) ?>"><?= (int)$diag['cache_ttl'] ?>c</a>]
                <?php endif; ?>
                [<?= e((string)($diag['bracelet_uid'] ?? '')) ?>]
                [T:<?= e((string)($diag['terminal_short'] ?? '')) ?>]
                <?php if ((string)($diag['admin_acronym'] ?? '') !== ''): ?>
                    <?= e(t('terminals.t.diag.admin')) ?>: [<?= e((string)$diag['admin_acronym']) ?>]
                <?php endif; ?>
                <?php if (!empty($state['show_unlink'])): ?>
                    <a href="/t/?<?= e(http_build_query($baseQ + ['unlinkClient' => 1])) ?>">[<?= e(t('terminals.t.unlink_client')) ?>]</a>
                <?php endif; ?>
                <?php if (!empty($diag['onec_stub'])): ?>
                    [stub]
                <?php endif; ?>
            </p>
        <?php endif; ?>

    <?php endif; ?>
    <?php
    $timingTotal = class_exists('StageTiming', false) ? StageTiming::total() : 0.0;
    $timingHtml = class_exists('StageTiming', false) ? StageTiming::reportHtml() : '';
    if ($timingHtml !== ''):
    ?>
        <details class="timing-panel">
            <summary><?= e(t('core.layout.timings', ['sec' => sprintf('%.3f', $timingTotal)])) ?></summary>
            <?= $timingHtml ?>
        </details>
    <?php endif; ?>
</div>
</body>
</html>
