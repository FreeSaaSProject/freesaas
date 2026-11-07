<?php
declare(strict_types=1);

BitrixJobsAuth::assertWorkerToken();
$entity = (string)($_GET['entity'] ?? $_POST['entity'] ?? 'contact');
$dry = ((string)($_GET['dry_run'] ?? $_POST['dry_run'] ?? '0')) === '1';
$report = BitrixImporter::run($entity, $dry);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($report, JSON_UNESCAPED_UNICODE);
