<?php
declare(strict_types=1);

require_once __DIR__ . '/ClinicDemoPlan.php';
require_once __DIR__ . '/ClinicDemoManifest.php';
require_once __DIR__ . '/ClinicDemoRng.php';

/**
 * Forward stage: planned visits for 09–18.11 and website-style requests with
 * cancellations. Future visits carry no procedures on purpose — they are bookings.
 */
final class ClinicDemoForward
{
    private ClinicDemoManifest $m;
    private ClinicDemoRng $rng;

    public function __construct(ClinicDemoManifest $manifest)
    {
        $this->m = $manifest;
        $this->rng = new ClinicDemoRng('forward', (int)$manifest->get('seed', ClinicDemoPlan::SEED));
    }

    /** @return array<string,mixed> */
    public function run(): array
    {
        require_once base_path('modules/calendar/lib/EventsRepository.php');
        require_once base_path('modules/orders/lib/OrdersRepository.php');

        $staff = (array)$this->m->get('index.staff', []);
        $products = (array)$this->m->get('index.products', []);
        $clients = $this->m->ids('clients');
        if ($clients === [] || $staff === []) {
            return ['events' => 0, 'orders' => 0];
        }
        $pdo = Database::pdo();
        $clientRows = $pdo->query('SELECT id, name, tel FROM base_clients WHERE id IN ('
            . implode(',', array_map('intval', $clients)) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($clientRows as $row) {
            $byId[(int)$row['id']] = $row;
        }

        $fill = ['t_a' => 0.92, 't_b' => 0.45, 'cardio' => 0.50, 'neuro' => 0.40,
            'uzi' => 0.50, 'nurse1' => 0.30, 'nurse2' => 0.30];

        $from = (int)strtotime(ClinicDemoPlan::forwardFrom() . ' 00:00:00');
        $to = (int)strtotime(ClinicDemoPlan::bookingTo() . ' 00:00:00');
        $created = 0;
        $plannedByStaff = [];
        for ($day = $from; $day <= $to; $day = (int)strtotime('+1 day', $day)) {
            $dow = (int)date('N', $day);
            if (!in_array($dow, ClinicDemoPlan::OPEN_WEEKDAYS, true)) {
                continue;
            }
            foreach (ClinicDemoPlan::performers() as $key) {
                if (!in_array($dow, ClinicDemoPlan::rosterDays($key), true)) {
                    continue;
                }
                $operatorId = (int)($staff[$key] ?? 0);
                $slots = (int)(ClinicDemoPlan::SLOTS_PER_SHIFT[$key] ?? 0);
                $target = (int)round($slots * (float)($fill[$key] ?? 0.4));
                $cursor = $day + ClinicDemoPlan::SHIFT_FROM_HOUR * 3600 + 1800;
                $shiftEnd = $day + ClinicDemoPlan::SHIFT_TO_HOUR * 3600;
                for ($i = 0; $i < $target; $i++) {
                    $code = $this->rng->weighted(ClinicDemoPlan::serviceMix()[$key] ?? []);
                    if ($code === null || !isset($products[$code])) {
                        break;
                    }
                    $svc = $this->serviceByCode($code);
                    if ($svc === null) {
                        break;
                    }
                    $duration = (int)$svc['minutes'] * 60;
                    if ($cursor + $duration > $shiftEnd) {
                        break;
                    }
                    $clientId = (int)$this->rng->pick($clients);
                    $client = $byId[$clientId] ?? [
                        'name' => ClinicDemoPlan::copyStr('patient_fallback'),
                        'tel' => '',
                    ];
                    try {
                        $eventId = EventsRepository::create([
                            'id_operator' => $operatorId,
                            'id_user' => $clientId,
                            'dtstart' => $cursor,
                            'dtend' => $cursor + $duration,
                            'name' => (string)$client['name'],
                            'phone' => (string)$client['tel'],
                            'descr' => ClinicDemoPlan::copyStr('booking_descr', ['service' => (string)$svc['name']]),
                            'is_block' => 0,
                            'items' => [[
                                'id_good' => (int)$products[$code],
                                'price' => (float)$svc['price'] * ClinicDemoPlan::OCTOBER_PRICE_FACTOR,
                                'qty' => 1,
                            ]],
                        ]);
                        $this->m->add('events_forward', $eventId);
                        $created++;
                        $plannedByStaff[$key] = ($plannedByStaff[$key] ?? 0) + 1;
                    } catch (Throwable $e) {
                        // keep going: occupancy conflicts are harmless for the demo
                    }
                    $cursor += $duration + $this->rng->int(5, 20) * 60;
                }
            }
        }

        $orders = $this->seedOrders($byId, $staff, $products);
        return ['events' => $created, 'orders' => $orders, 'by_staff' => $plannedByStaff];
    }

    /**
     * @param array<int,array<string,mixed>> $clients
     * @param array<string,int> $staff
     * @param array<string,int> $products
     */
    private function seedOrders(array $clients, array $staff, array $products): int
    {
        $doctors = ['cardio', 't_a', 't_b', 'uzi', 'neuro'];
        $statuses = ['cashComplete', 'cashComplete', 'cashComplete', 'cashComplete',
            'userCancelPay', 'userCancelPay', 'complete', 'deletedByAdmin'];
        $created = 0;
        $orderBase = (int)strtotime(ClinicDemoPlan::forwardFrom() . ' 12:00:00');
        for ($i = 0; $i < 18; $i++) {
            $day = $orderBase + ($i % 6) * 86400;
            $doctorKey = (string)$this->rng->pick($doctors);
            $code = (string)$this->rng->weighted(ClinicDemoPlan::serviceMix()[$doctorKey] ?? []);
            if ($code === '' || !isset($products[$code])) {
                continue;
            }
            $svc = $this->serviceByCode($code);
            if ($svc === null) {
                continue;
            }
            $clientId = (int)$this->rng->pick(array_keys($clients));
            $client = $clients[$clientId] ?? null;
            if ($client === null) {
                continue;
            }
            $status = (string)$this->rng->pick($statuses);
            $phone = preg_replace('/\D/', '', (string)$client['tel']);
            try {
                $id = OrdersRepository::create([
                    'phone' => (string)$phone,
                    'mail' => '',
                    'comment' => ClinicDemoPlan::copyStr('order_comment'),
                    'cart_lines' => [[
                        'id' => (int)$products[$code],
                        'name' => (string)$svc['name'],
                        'price' => (float)$svc['price'] * ClinicDemoPlan::OCTOBER_PRICE_FACTOR,
                        'price_original' => (float)$svc['price'] * ClinicDemoPlan::OCTOBER_PRICE_FACTOR,
                    ]],
                    'status' => $status,
                    'id_user' => $clientId,
                    'id_cosmetolog' => (int)($staff[$doctorKey] ?? 0),
                    'dt_event' => $day + 10 * 3600 + ($i % 8) * 1800,
                    'source' => 'site',
                ]);
                $this->m->add('orders', $id);
                $created++;
            } catch (Throwable $e) {
                continue;
            }
        }
        return $created;
    }

    /** @return array<string,mixed>|null */
    private function serviceByCode(string $code): ?array
    {
        foreach (ClinicDemoPlan::services() as $svc) {
            if ((string)$svc['code'] === $code) {
                return $svc;
            }
        }
        return null;
    }
}
