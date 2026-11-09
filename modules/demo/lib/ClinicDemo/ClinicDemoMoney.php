<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';

/**
 * Money stage: chart entries, revenue accruals vs cash, operating expenses, payroll.
 *
 * Revenue comes from base_procedures.SUMM (the operational source of truth) and is
 * posted to the ledger as income moves. A share of income carries DT_PROMISED, which
 * is the native accrual-vs-cash mechanic: P&L recognises it now, cash flow later.
 */
final class ClinicDemoMoney
{
    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('money', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        require_once base_path('modules/ledger/lib/LedgerAccountsRepository.php');
        require_once base_path('modules/ledger/lib/AccountMoveTypesRepository.php');
        require_once base_path('modules/ledger/lib/LedgerMovesRepository.php');
        require_once base_path('modules/payments/lib/PaymentsRepository.php');
        require_once base_path('modules/payments/lib/PaymentMoveLinksRepository.php');

        $accounts = $this->ensureAccounts();
        $types = $this->ensureTypes($accounts);
        $opening = $this->openingCapital($accounts, $types);

        $revenue = $this->postRevenue($accounts, $types);
        $expenses = $this->postExpenses($accounts, $types);
        $payroll = $this->postPayroll($accounts, $types);
        $transfer = $this->sweepCash($accounts, $types);

        return [
            'accounts' => $accounts,
            'types' => count($types),
            'opening' => $opening,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'payroll' => $payroll,
            'transfer' => $transfer,
        ];
    }

    /** @return array{bank:int,cash:int} */
    private function ensureAccounts(): array
    {
        $L = ClinicDemoPlan::moneyLabels();
        $dt = (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00') - 12 * 86400;
        $out = [];
        foreach ([
            'bank' => [(string)($L['bank'] ?? 'Bank'), (string)($L['bank'] ?? 'Bank')],
            'cash' => [(string)($L['cash'] ?? 'Cash'), (string)($L['cash'] ?? 'Cash')],
        ] as $key => $pair) {
            $id = $this->findId('base_accounts', 'NAME', $pair[0]);
            if ($id < 1) {
                $id = LedgerAccountsRepository::create([
                    'name' => $pair[0],
                    'descr' => $pair[1],
                    'enabled' => 1,
                    'id_type' => 2,
                    'dt' => $dt,
                ]);
            }
            $this->m->add('accounts', $id);
            $out[$key] = $id;
        }
        $this->m->set('index.accounts', $out);
        return $out;
    }

    /**
     * @param array{bank:int,cash:int} $accounts
     * @return array<string,int>
     */
    private function ensureTypes(array $accounts): array
    {
        $L = ClinicDemoPlan::moneyLabels();
        $dt = (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00') - 12 * 86400;
        // Reports read only leaf types (PID > 0); groups are folders with PID = 0.
        $folderOps = $this->ensureFolder((string)($L['folder_ops'] ?? 'Operating'), 10);
        $folderFin = $this->ensureFolder((string)($L['folder_fin'] ?? 'Financing'), 80);
        $spec = [
            'revenue' => [(string)($L['revenue'] ?? 'Revenue'), +1, 1, 0, $accounts['bank'], 10, 0, 0, $folderOps],
            'rent' => [(string)($L['rent'] ?? 'Rent'), -1, 1, 0, $accounts['bank'], 20, 0, 0, $folderOps],
            'ads' => [(string)($L['ads'] ?? 'Ads'), -1, 1, 0, $accounts['bank'], 30, 0, 0, $folderOps],
            'supplies' => [(string)($L['supplies'] ?? 'Supplies'), -1, 1, 0, $accounts['bank'], 40, 0, 0, $folderOps],
            'equipment' => [(string)($L['equipment'] ?? 'Equipment'), -1, 1, 0, $accounts['bank'], 50, 0, 0, $folderOps],
            'salary' => [(string)($L['salary'] ?? 'Payroll bank'), -1, 1, 0, $accounts['bank'], 60, 1, 1, $folderOps],
            'salary_cash' => [(string)($L['salary_cash'] ?? 'Payroll cash'), -1, 1, 0, $accounts['cash'], 61, 2, 1, $folderOps],
            'other' => [(string)($L['other'] ?? 'Other'), -1, 1, 0, $accounts['bank'], 70, 0, 0, $folderOps],
            'capital' => [(string)($L['capital'] ?? 'Capital'), +1, 3, 1, $accounts['bank'], 80, 0, 0, $folderFin],
            'transfer' => [(string)($L['transfer'] ?? 'Transfer'), -1, 3, 1, $accounts['bank'], 90, 0, 0, $folderFin],
        ];
        $out = [];
        foreach ($spec as $key => $row) {
            [$name, $direction, $odds, $excludePl, $accountId, $num, $fot, $paymentEnabled, $pid] = $row;
            $id = $this->findId('base_account_move_types', 'NAME', $name);
            if ($id < 1) {
                $id = AccountMoveTypesRepository::create([
                    'name' => $name,
                    'descr' => str_replace('{org}', ClinicDemoPlan::org(), (string)($L['ledger_descr'] ?? '')),
                    'dt' => $dt,
                    'pid' => (int)$pid,
                    'enabled' => 1,
                    'account_id' => $accountId,
                    'num' => $num,
                    'direction' => $direction,
                    'odds_type' => $odds,
                    'payment_enabled' => $paymentEnabled,
                    'fot_kind' => $fot,
                    'user_payment_enabled' => 0,
                    'supplier_enabled' => $key === 'supplies' ? 1 : 0,
                    'linked_enabled' => $key === 'transfer' ? 1 : 0,
                    'fund_enabled' => 0,
                    'act_in_enabled' => $key === 'supplies' ? 1 : 0,
                    'exclude_pl' => $excludePl,
                ]);
            }
            $this->m->add('move_types', $id);
            $out[$key] = $id;
        }
        $this->m->set('index.move_types', $out);
        return $out;
    }

    private function ensureFolder(string $name, int $num): int
    {
        $id = $this->findId('base_account_move_types', 'NAME', $name);
        if ($id > 0) {
            return $id;
        }
        $L = ClinicDemoPlan::moneyLabels();
        $id = AccountMoveTypesRepository::create([
            'name' => $name,
            'descr' => (string)($L['folder_descr'] ?? 'Demo ledger group'),
            'dt' => (int)strtotime(ClinicDemoPlan::historyFrom() . ' 00:00:00') - 12 * 86400,
            'pid' => 0,
            'enabled' => 1,
            'account_id' => 0,
            'num' => $num,
            'direction' => 0,
            'odds_type' => 1,
            'payment_enabled' => 0,
            'fot_kind' => 0,
            'user_payment_enabled' => 0,
            'supplier_enabled' => 0,
            'linked_enabled' => 0,
            'fund_enabled' => 0,
            'act_in_enabled' => 0,
            'exclude_pl' => 0,
        ]);
        $this->m->add('move_types', $id);
        return $id;
    }

    /**
     * @param array{bank:int,cash:int} $accounts
     * @param array<string,int> $types
     */
    private function openingCapital(array $accounts, array $types): int
    {
        $exists = (int)Database::pdo()->query(
            'SELECT COUNT(*) FROM base_account_moves WHERE DESCR = ' . Database::pdo()->quote('Стартовый капитал клиники')
        )->fetchColumn();
        if ($exists > 0) {
            return 0;
        }
        $this->move($accounts['bank'], $types['capital'], 1, 400000.0, 'Стартовый капитал клиники', '2026-08-25 12:00:00');
        return 1;
    }

    /**
     * Daily revenue split between cash desk and bank, with an accrual share.
     *
     * @param array{bank:int,cash:int} $accounts
     * @param array<string,int> $types
     * @return array<string,mixed>
     */
    private function postRevenue(array $accounts, array $types): array
    {
        $pdo = Database::pdo();
        $events = $this->m->ids('events');
        if ($events === []) {
            return ['days' => 0, 'revenue' => 0.0, 'promised' => 0];
        }
        $in = implode(',', array_map('intval', $events));
        $rows = $pdo->query(
            'SELECT FROM_UNIXTIME(DTSTART, "%Y-%m-%d") AS d, SUM(SUMM) AS s, COUNT(*) AS n
               FROM base_procedures
              WHERE ID_EVENT_PARENT IN (' . $in . ') AND ENABLED = 1
              GROUP BY d ORDER BY d'
        )->fetchAll(PDO::FETCH_ASSOC);

        $finance = ClinicDemoPlan::finance();
        $share = (array)($finance['receivable_share'] ?? []);
        $byMonth = [];
        $total = 0.0;
        $promised = 0;

        foreach ($rows as $row) {
            $day = (string)$row['d'];
            $sum = (float)$row['s'];
            if ($sum <= 0) {
                continue;
            }
            $month = substr($day, 0, 7);
            $byMonth[$month] = ($byMonth[$month] ?? 0.0) + $sum;
            $total += $sum;

            $cashSum = round($sum * (0.20 + $this->rng->float() * 0.06), 2);
            $deferredSum = round($sum * (float)($share[$month] ?? 0.0), 2);
            $bankSum = round($sum - $cashSum - $deferredSum, 2);
            $promiseDate = $deferredSum > 0 ? $this->promiseDate($day) : '';

            $L = ClinicDemoPlan::moneyLabels();
            $dateLabel = $this->formatDate($day);
            if ($cashSum > 0) {
                $this->move($accounts['cash'], $types['revenue'], 1, $cashSum,
                    str_replace('{date}', $dateLabel, (string)$L['revenue_cash']), $day . ' 19:30:00');
            }
            if ($bankSum > 0) {
                $this->move($accounts['bank'], $types['revenue'], 1, $bankSum,
                    str_replace('{date}', $dateLabel, (string)$L['revenue_bank']), $day . ' 20:00:00');
            }
            if ($deferredSum > 0) {
                $this->move($accounts['bank'], $types['revenue'], 1, $deferredSum,
                    str_replace('{date}', $dateLabel, (string)$L['revenue_deferred']), $day . ' 20:00:00', $promiseDate);
                $promised++;
            }
        }
        return ['days' => count($rows), 'revenue' => round($total, 2), 'promised' => $promised, 'by_month' => $byMonth];
    }

    /** Next month, 10..22 — receivable settles in the following period. */
    private function promiseDate(string $day): string
    {
        $ts = (int)strtotime($day . ' 12:00:00');
        $next = (int)strtotime('first day of next month 12:00:00', $ts);
        $next += $this->rng->int(9, 21) * 86400;
        return date('Y-m-d 12:00:00', $next);
    }

    /**
     * Monthly operating expenses, several moves per category with vendor-like descriptions.
     *
     * @param array{bank:int,cash:int} $accounts
     * @param array<string,int> $types
     * @return array<string,mixed>
     */
    private function postExpenses(array $accounts, array $types): array
    {
        $plan = (array)ClinicDemoPlan::finance()['expenses'];
        $posted = 0;
        $total = 0.0;
        $vendors = [
            'ads' => [
                ['Яндекс.Директ: контекстная реклама', 0.55],
                ['Реклама в социальных сетях и у блогеров', 0.30],
                ['Полиграфия и наружная реклама', 0.15],
            ],
            'supplies' => [
                ['Расходные материалы: МедСнаб', 0.60],
                ['Расходные материалы: ФармЛогистик', 0.40],
            ],
            'other' => [
                ['Коммунальные платежи и связь', 0.50],
                ['Клининг, охрана и хозяйственные нужды', 0.50],
            ],
        ];
        $L = ClinicDemoPlan::moneyLabels();
        $single = [
            'rent' => (string)($L['rent_tpl'] ?? '%s'),
            'equipment' => (string)($L['equipment_tpl'] ?? '%s'),
            'admin_payroll' => '', // handled by payroll stage
        ];
        foreach ($plan as $month => $lines) {
            $monthName = $this->formatMonth((string)$month);
            foreach ($lines as $key => $sum) {
                $sum = (float)$sum;
                if ($sum <= 0 || $key === 'admin_payroll') {
                    continue;
                }
                if (isset($single[$key])) {
                    $this->move($accounts['bank'], $types[$key] ?? $types['other'], -1, $sum,
                        sprintf($single[$key], $monthName), $month . '-05 12:00:00');
                    $posted++;
                    $total += $sum;
                    continue;
                }
                foreach ($vendors[$key] ?? [['Операционные расходы, %s', 1.0]] as $vendor) {
                    $part = round($sum * (float)$vendor[1], 2);
                    if ($part <= 0) {
                        continue;
                    }
                    $descr = strpos((string)$vendor[0], '%s') !== false
                        ? sprintf((string)$vendor[0], $monthName)
                        : $vendor[0] . ', ' . $monthName;
                    $this->move($accounts['bank'], $types[$key] ?? $types['other'], -1, $part,
                        $descr, $month . '-08 12:00:00');
                    $posted++;
                    $total += $part;
                }
            }
        }
        return ['moves' => $posted, 'total' => round($total, 2)];
    }

    /**
     * Payroll: commission accrual per doctor from procedures, fixed salaries for the rest.
     * Each payout produces base_payments + ledger move + link (as the UI does).
     *
     * @param array{bank:int,cash:int} $accounts
     * @param array<string,int> $types
     * @return array<string,mixed>
     */
    private function postPayroll(array $accounts, array $types): array
    {
        $pdo = Database::pdo();
        $staff = (array)$this->m->get('index.staff', []);
        $rates = (array)$this->m->get('index.rates', []);
        $typesIdx = (array)$this->m->get('index.types', []);
        $events = $this->m->ids('events');
        if ($events === [] || $staff === []) {
            return ['payments' => 0];
        }
        $in = implode(',', array_map('intval', $events));

        // Commission base per operator per month.
        $rows = $pdo->query(
            'SELECT ID_OPERATOR, TYPE, FROM_UNIXTIME(DTSTART, "%Y-%m") AS ym, SUM(SUMM) AS s
               FROM base_procedures
              WHERE ID_EVENT_PARENT IN (' . $in . ') AND ENABLED = 1
              GROUP BY ID_OPERATOR, TYPE, ym'
        )->fetchAll(PDO::FETCH_ASSOC);

        $rateMaps = [];
        foreach (ClinicDemoPlan::rates() as $staffKey => $map) {
            $content = [];
            foreach ($map as $typeKey => $pct) {
                $tid = (int)($typesIdx[$typeKey] ?? 0);
                if ($tid > 0) {
                    $content[$tid] = (float)$pct;
                }
            }
            if (isset($staff[$staffKey])) {
                $rateMaps[(int)$staff[$staffKey]] = $content;
            }
        }

        $commission = [];
        foreach ($rows as $row) {
            $op = (int)$row['ID_OPERATOR'];
            $tid = (int)$row['TYPE'];
            $ym = (string)$row['ym'];
            $pct = (float)($rateMaps[$op][$tid] ?? 0.0);
            if ($pct <= 0) {
                continue;
            }
            $commission[$ym][$op] = ($commission[$ym][$op] ?? 0.0) + (float)$row['s'] * $pct;
        }

        $salaries = (array)(ClinicDemoPlan::finance()['salaries'] ?? []);
        $L = ClinicDemoPlan::moneyLabels();
        $months = array_keys($salaries);
        $payments = 0;
        foreach ($months as $ym) {
            $ym = (string)$ym;
            foreach ($salaries[$ym] ?? [] as $staffKey => $amount) {
                $uid = (int)($staff[$staffKey] ?? 0);
                $sum = (float)$amount;
                if ($uid < 1 || $sum <= 0) {
                    continue;
                }
                $label = str_replace('{month}', $this->formatMonth($ym), (string)$L['salary_label']);
                $payments += $this->payout($uid, $sum, $ym, $label, $accounts, $types);
            }
            foreach ($commission[$ym] ?? [] as $opId => $sum) {
                $sum = round((float)$sum, 2);
                if ($sum <= 0) {
                    continue;
                }
                $label = str_replace('{month}', $this->formatMonth($ym), (string)$L['commission_label']);
                $payments += $this->payout(
                    (int)$opId,
                    $sum,
                    $ym,
                    $label,
                    $accounts,
                    $types
                );
            }
        }
        return ['payments' => $payments];
    }

    /**
     * Accrual + payout + ledger move + link for one staff member and month.
     *
     * @param array{bank:int,cash:int} $accounts
     * @param array<string,int> $types
     */
    private function payout(int $userId, float $sum, string $ym, string $label, array $accounts, array $types): int
    {
        $lastDay = date('Y-m-t', (int)strtotime($ym . '-01 12:00:00'));
        $dt = (int)strtotime($lastDay . ' 17:00:00');
        $accrualKey = 'month:' . $ym . ':' . $userId;
        $pdo = Database::pdo();
        $exists = (int)$pdo->query(
            'SELECT COUNT(*) FROM base_payments WHERE ID_USER = ' . $userId . ' AND ACCRUAL_KEY = '
            . $pdo->quote($accrualKey)
        )->fetchColumn();
        if ($exists > 0) {
            return 0;
        }
        $base = [
            'user_id' => $userId,
            'dt' => $dt,
            'descr' => $label,
            'dt_create' => $dt,
            'user_signed' => $userId,
            'comment_id' => 0,
            'is_partner' => 0,
            'admin_inworked' => 0,
            'dt_admin_inworked' => 0,
            'admin_signed' => 0,
            'dt_admin_signed' => 0,
            'accept_user' => 0,
            'dt_accept' => 0,
            'movement_linked' => 0,
            'control_notice' => 0,
            'user_to' => 0,
            'rate_id' => 0,
        ];
        $accrualId = PaymentsRepository::create($base + [
            'direction' => '+1',
            'sum' => $sum,
            'account' => 0,
            'accrual_key' => $accrualKey,
        ]);
        $this->m->add('payments', $accrualId);

        $payoutId = PaymentsRepository::create($base + [
            'direction' => '-1',
            'sum' => $sum,
            'account' => 1,
            'accrual_key' => '',
        ]);
        $this->m->add('payments', $payoutId);

        $moveId = LedgerMovesRepository::create([
            'account_id' => $accounts['bank'],
            'type_id' => $types['salary'],
            'direction' => -1,
            'summ' => $sum,
            'descr' => $label . ' — ' . $this->userName($userId),
            'dt' => $dt,
            'dt_create' => $dt,
            'user_create' => 1,
            'unconfirmed' => 0,
            'payment_id' => $payoutId,
            'supplier_id' => 0,
            'user_payment' => $userId,
            'fund_movement_id' => 0,
            'linked_id' => 0,
            'account_to' => 0,
        ]);
        $this->m->add('moves', $moveId);
        PaymentMoveLinksRepository::link($payoutId, $moveId, $sum);
        return 1;
    }

    /** Move part of the cash desk to the bank account at month end. */
    private function sweepCash(array $accounts, array $types): array
    {
        $pdo = Database::pdo();
        $cashId = (int)($accounts['cash'] ?? 0);
        $bankId = (int)($accounts['bank'] ?? 0);
        if ($cashId < 1 || $bankId < 1) {
            return ['transfers' => 0];
        }
        $balance = (float)$pdo->query(
            'SELECT COALESCE(SUM(DIRECTION * SUMM), 0) FROM base_account_moves
              WHERE ID_ACCOUNT = ' . $cashId . ' AND DELETED = 0 AND UNCONFIRMED = 0'
        )->fetchColumn();
        $amount = round($balance * 0.7, 2);
        if ($amount < 1000) {
            return ['transfers' => 0];
        }
        $dt = (int)strtotime(ClinicDemoPlan::historyTo() . ' 12:00:00') - 3 * 86400;
        $L = ClinicDemoPlan::moneyLabels();
        $pair = LedgerMovesRepository::createTransfer(
            (int)$types['transfer'],
            $cashId,
            $bankId,
            $amount,
            $dt,
            (string)($L['cash_sweep'] ?? 'Cash sweep')
        );
        $this->m->add('moves', (int)($pair['from_id'] ?? 0));
        $this->m->add('moves', (int)($pair['to_id'] ?? 0));
        return ['transfers' => 1, 'amount' => $amount];
    }

    private function move(
        int $accountId,
        int $typeId,
        int $direction,
        float $sum,
        string $descr,
        string $dtLocal,
        string $promisedLocal = ''
    ): void {
        $dt = (int)strtotime($dtLocal);
        $promised = $promisedLocal !== '' ? (int)strtotime($promisedLocal) : 0;
        $id = LedgerMovesRepository::create([
            'account_id' => $accountId,
            'type_id' => $typeId,
            'direction' => $direction,
            'summ' => $sum,
            'descr' => $descr,
            'dt' => $dt,
            'dt_create' => $dt,
            'user_create' => 1,
            'unconfirmed' => 0,
            'payment_id' => 0,
            'supplier_id' => 0,
            'user_payment' => 0,
            'fund_movement_id' => 0,
            'linked_id' => 0,
            'account_to' => 0,
            'dt_promised' => $promised,
            'promise_executed' => ($promised > 0 && $promised < time()) ? 1 : 0,
        ]);
        $this->m->add('moves', $id);
    }

    private function userName(int $userId): string
    {
        $st = Database::pdo()->prepare('SELECT name FROM base_users WHERE id = ?');
        $st->execute([$userId]);
        return (string)($st->fetchColumn() ?: ('#' . $userId));
    }

    private function formatDate(string $ymd): string
    {
        $ts = (int)strtotime($ymd . ' 12:00:00');
        return ClinicDemoPlan::locale() === 'en'
            ? date('Y-m-d', $ts)
            : date('d.m.Y', $ts);
    }

    private function formatMonth(string $ym): string
    {
        $y = substr($ym, 0, 4);
        $m = substr($ym, 5, 2);
        if (ClinicDemoPlan::locale() === 'en') {
            $names = ['01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
                '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
                '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'];
            return ($names[$m] ?? $m) . ' ' . $y;
        }
        $months = ['01' => 'январь', '02' => 'февраль', '03' => 'март', '04' => 'апрель',
            '05' => 'май', '06' => 'июнь', '07' => 'июль', '08' => 'август',
            '09' => 'сентябрь', '10' => 'октябрь', '11' => 'ноябрь', '12' => 'декабрь'];
        return ($months[$m] ?? $m) . ' ' . $y;
    }

    private function findId(string $table, string $column, string $value): int
    {
        $st = Database::pdo()->prepare("SELECT ID FROM {$table} WHERE {$column} = ? LIMIT 1");
        $st->execute([$value]);
        return (int)$st->fetchColumn();
    }
}
