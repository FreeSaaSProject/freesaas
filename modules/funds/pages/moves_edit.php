<?php
declare(strict_types=1);

$wantsJson = AgentHttp::wantsJson();

$id = (int)(Router::param('id') ?? 0);
$isNew = $id < 1;
$row = ['fund_id' => (int)($_GET['fund_id'] ?? 0), 'type_id' => 1, 'summ' => 0, 'name' => '', 'descr' => ''];

$dpsId = $isNew ? (int)($_POST['dps'] ?? ($_GET['dps'] ?? 0)) : 0;
$dps = null;
if ($dpsId > 0) {
    $dps = LedgerMovesRepository::find($dpsId);
    $dpsError = '';
    if ($dps === null || (int)$dps['deleted'] > 0) {
        $dpsError = t('funds.dps.err.not_found', ['id' => $dpsId]);
    } else {
        $dpsType = AccountMoveTypesRepository::find((int)$dps['type_id']);
        if ($dpsType === null || !FundsLedgerHooks::typeLinksFunds($dpsType)) {
            $dpsError = t('funds.dps.err.type');
        } elseif ((int)$dps['fund_movement_id'] > 0) {
            $dpsError = t('funds.dps.err.already', ['id' => (int)$dps['fund_movement_id']]);
        }
    }
    if ($dpsError !== '') {
        if ($wantsJson) {
            AgentHttp::error($dpsError, $dps === null ? 404 : 400);
            return;
        }
        flash_set('warn', $dpsError);
        redirect($dps === null ? '/ledger/moves' : '/ledger/moves/' . $dpsId . '/edit');
        return;
    }
    $isOut = (int)$dps['direction'] < 0;
    $summ = round(abs((float)$dps['summ']), 2);
    $descr = trim((string)$dps['descr']);
    $firstLine = trim((string)strtok($descr, "\n"));
    $row = array_merge($row, [
        'type_id' => $isOut ? FundMovementsRepository::TYPE_CAPUSE : FundMovementsRepository::TYPE_REFUND,
        'summ' => $summ,
        'summ_out_cap' => $isOut ? $summ : 0,
        'name' => mb_substr($firstLine !== '' ? $firstLine : t('funds.dps.default_name', ['id' => $dpsId]), 0, 250),
        'descr' => $descr,
        'dt_amort_start' => (int)$dps['dt'],
    ]);
}

if (!$isNew) {
    $found = FundMovementsRepository::find($id);
    if (!$found) {
        if ($wantsJson) {
            AgentHttp::error(t('funds.moves.err.not_found', ['id' => $id]), 404);
            return;
        }
        http_response_code(404);
        View::render('error', ['title' => t('core.common.not_found'), 'message' => t('funds.moves.err.not_found', ['id' => $id])]);
        return;
    }
    $row = $found;
}
$message = ''; $error = '';

if (!$isNew && EntityDelete::tryHandle('fund_movements', $id, '/fund-movements', [
    'stay_path' => '/fund-movements/' . $id . '/edit',
])) {
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $pdo = Database::pdo();
    $startedTx = false;
    try {
        $data = FundMovementsRepository::dataFromPost($_POST);
        if ($isNew) {
            if ($dps !== null && !$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTx = true;
            }
            $newId = FundMovementsRepository::create($data);
            if ($dps !== null) {
                LedgerMovesRepository::setFundMovement($dpsId, $newId);
            }
            if ($startedTx) {
                $pdo->commit();
                $startedTx = false;
            }
            if ($wantsJson) {
                AgentHttp::emit([
                    'ok' => true,
                    'id' => $newId,
                    'dps_id' => $dps !== null ? $dpsId : null,
                    'item' => FundMovementsRepository::attachDps([FundMovementsRepository::find($newId) ?: []])[0],
                ]);
                return;
            }
            if ($dps !== null) {
                flash_set('ok', t('funds.dps.created', ['id' => $newId]));
                redirect('/ledger/moves/' . $dpsId . '/edit');
                return;
            }
            redirect('/fund-movements/' . $newId . '/edit?saved=1');
            return;
        }
        FundMovementsRepository::update($id, $data);
        $row = FundMovementsRepository::find($id) ?: $row;
        if ($wantsJson) {
            AgentHttp::emit(['ok' => true, 'id' => $id, 'item' => FundMovementsRepository::attachDps([$row])[0]]);
            return;
        }
        $message = t('core.common.saved');
    } catch (Throwable $e) {
        if ($startedTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        AgentHttp::rethrowIfCapture($e);
        if ($wantsJson) {
            AgentHttp::error($e->getMessage(), 400);
            return;
        }
        $error = $e->getMessage();
        $row = array_merge($row, $_POST);
    }
}
if (isset($_GET['saved']) && $message === '') { $message = t('core.common.saved'); }

$linkedDps = [];
if (!$isNew) {
    $row = FundMovementsRepository::attachDps([$row])[0];
    $linkedDps = $row['dps'];
}
$fundRow = FundsRepository::allRows()[(int)($row['fund_id'] ?? 0)] ?? null;

if ($wantsJson) {
    $typeLabels = [];
    foreach (array_keys(FundMovementsRepository::typeDirections()) as $t) {
        $typeLabels[$t] = FundMovementsRepository::typeLabel($t);
    }
    AgentHttp::emit([
        'ok' => true,
        'is_new' => $isNew,
        'item' => $row,
        'dps_id' => $dps !== null ? $dpsId : null,
        'dps' => $dps,
        'fund_options' => FundsRepository::leafOptions(),
        'types' => $typeLabels,
        'funds' => FundsRepository::names(),
    ]);
    return;
}

$crumbs = [['label' => t('funds.menu.movements'), 'href' => '/fund-movements']];
if ($fundRow !== null) {
    $crumbs[] = ['label' => $fundRow['name'], 'href' => '/funds/' . $fundRow['id']];
}
$crumbs[] = ['label' => $isNew ? t('funds.moves.edit_new') : ('#' . $id), 'current' => true];

View::render('moves_edit', [
    'title' => $isNew ? t('funds.moves.edit_new') : t('funds.moves.edit', ['id' => $id]),
    'breadcrumbs' => Breadcrumbs::withHome($crumbs),
    'isNew' => $isNew, 'row' => $row, 'fundOptions' => FundsRepository::leafOptions(),
    'dps' => $dps, 'dpsId' => $dpsId, 'linkedDps' => $linkedDps, 'fundRow' => $fundRow,
    'message' => $message, 'error' => $error,
], 'layout', 'funds');
