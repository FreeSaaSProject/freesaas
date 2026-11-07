<?php
declare(strict_types=1);

BitrixJobsAuth::assertWorkerToken();
$report = BitrixOutboxPusher::flush(50);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($report, JSON_UNESCAPED_UNICODE);
