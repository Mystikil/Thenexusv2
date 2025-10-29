<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../functions.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../includes/rate_limiter.php';

function require_bridge_auth(): void
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!is_string($header) || $header === '') {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }

    if (stripos($header, 'Bearer ') !== 0) {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }

    $token = trim(substr($header, 7));

    if ($token === '' || !hash_equals(BRIDGE_SECRET, $token)) {
        json_out(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }
}

function decode_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?? '', true);

    if (!is_array($data)) {
        json_out(['status' => 'error', 'message' => 'Invalid JSON body'], 400);
    }

    return $data;
}

$pdo = db();

if (!$pdo instanceof PDO) {
    json_out(['status' => 'error', 'message' => 'Database unavailable'], 503);
}

rate_limit_check($pdo, client_rate_limit_key('jobs_pull'), 60, 60);

require_bridge_auth();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    if ($limit <= 0) {
        $limit = 10;
    }
    $limit = min($limit, 50);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, type, args_json FROM rcon_jobs WHERE status = :status ORDER BY id ASC LIMIT :limit FOR UPDATE');
    $stmt->bindValue(':status', 'queued');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $jobs = [];

    if ($rows !== []) {
        $updateStmt = $pdo->prepare('UPDATE rcon_jobs SET status = :status WHERE id = :id');

        foreach ($rows as $row) {
            $updateStmt->execute([
                'status' => 'in_progress',
                'id' => $row['id'],
            ]);

            $args = json_decode((string) $row['args_json'], true);
            if (!is_array($args)) {
                $args = null;
            }

            $jobs[] = [
                'id' => (int) $row['id'],
                'type' => $row['type'],
                'args' => $args,
                'args_json' => $row['args_json'],
            ];
        }
    }

    $pdo->commit();

    $jobIds = array_map(static fn ($job) => $job['id'], $jobs);
    audit_log(null, 'bridge_jobs_fetch', ['limit' => $limit], ['job_ids' => $jobIds]);

    json_out([
        'status' => 'ok',
        'jobs' => $jobs,
    ]);
}

if ($method === 'POST' && isset($_GET['complete'])) {
    $payload = decode_json_body();

    $jobId = (int) ($payload['job_id'] ?? 0);
    $status = strtolower((string) ($payload['status'] ?? ''));
    $resultText = trim((string) ($payload['result_text'] ?? ''));

    if ($jobId <= 0) {
        json_out(['status' => 'error', 'message' => 'job_id is required'], 400);
    }

    if (!in_array($status, ['ok', 'error'], true)) {
        json_out(['status' => 'error', 'message' => 'status must be ok or error'], 400);
    }

    if (mb_strlen($resultText, 'UTF-8') > 65535) {
        $resultText = mb_substr($resultText, 0, 65535, 'UTF-8');
    }

    $stmt = $pdo->prepare('SELECT id, status, args_json FROM rcon_jobs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch();

    if ($job === false) {
        json_out(['status' => 'error', 'message' => 'Job not found'], 404);
    }

    if ($job['status'] !== 'in_progress') {
        json_out(['status' => 'error', 'message' => 'Job is not in progress'], 409);
    }

    $updateStmt = $pdo->prepare('UPDATE rcon_jobs SET status = :status, result_text = :result_text WHERE id = :id');
    $updateStmt->execute([
        'status' => $status,
        'result_text' => $resultText,
        'id' => $jobId,
    ]);

    $args = json_decode((string) $job['args_json'], true);

    if (is_array($args) && isset($args['order_id'])) {
        $orderStatus = $status === 'ok' ? 'delivered' : 'failed';
        $orderUpdate = $pdo->prepare('UPDATE shop_orders SET status = :status, result_text = :result_text WHERE id = :id');
        $orderUpdate->execute([
            'status' => $orderStatus,
            'result_text' => $resultText !== '' ? $resultText : strtoupper($status),
            'id' => (int) $args['order_id'],
        ]);
    }

    audit_log(null, 'bridge_jobs_complete', ['job_id' => $jobId], [
        'status' => $status,
        'result_text' => $resultText,
    ]);

    json_out(['status' => 'ok']);
}

json_out(['status' => 'error', 'message' => 'Method not allowed'], 405);
