<?php
declare(strict_types=1);

AmocrmJobsAuth::assertWorkerToken();
$entity = (string)($_GET['entity'] ?? $_POST['entity'] ?? 'contact');
$dry = ((string)($_GET['dry_run'] ?? $_POST['dry_run'] ?? '0')) === '1';
$report = AmocrmImporter::run($entity, $dry);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($report, JSON_UNESCAPED_UNICODE);
