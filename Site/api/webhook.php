<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../functions.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../includes/rate_limiter.php';

$rawBody = file_get_contents('php://input') ?: '';
$pdo = db();

if (!$pdo instanceof PDO) {
    json_out(['status' => 'error', 'message' => 'Database unavailable'], 503);
}

rate_limit_check($pdo, client_rate_limit_key('webhook'), 60, 60);

$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    json_out(['status' => 'error', 'message' => 'Invalid JSON body'], 400);
}

$eventName = isset($payload['event']) ? trim((string) $payload['event']) : '';
$data = $payload['data'] ?? null;
$timestamp = $payload['ts'] ?? null;

if ($eventName === '') {
    json_out(['status' => 'error', 'message' => 'event is required'], 422);
}

if ($data !== null && !is_array($data) && !is_object($data)) {
    json_out(['status' => 'error', 'message' => 'data must be an object or null'], 422);
}

if (!is_int($timestamp)) {
    if (is_string($timestamp) && ctype_digit($timestamp)) {
        $timestamp = (int) $timestamp;
    } else {
        json_out(['status' => 'error', 'message' => 'ts must be an integer timestamp'], 422);
    }
}

$signatureHeader = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

if (!is_string($signatureHeader) || $signatureHeader === '') {
    json_out(['status' => 'error', 'message' => 'Missing signature'], 401);
}

$expectedSignature = hash_hmac('sha256', $rawBody, WEBHOOK_SECRET);

if (!hash_equals($expectedSignature, $signatureHeader)) {
    json_out(['status' => 'error', 'message' => 'Invalid signature'], 403);
}

$insert = $pdo->prepare('INSERT INTO webhook_events (name, payload, signature, handled) VALUES (:name, :payload, :signature, 0)');
$insert->execute([
    'name' => $eventName,
    'payload' => $rawBody,
    'signature' => $signatureHeader,
]);

handle_webhook_event($pdo, $eventName);

audit_log(null, 'webhook_received', ['event' => $eventName], ['signature' => substr($signatureHeader, 0, 12)]);

json_out([
    'status' => 'ok',
    'message' => 'Webhook received',
]);

function handle_webhook_event(PDO $pdo, string $eventName): void
{
    $counters = [
        'player_login' => 'webhook.player_login_count',
        'player_logout' => 'webhook.player_logout_count',
    ];

    if (!isset($counters[$eventName])) {
        return;
    }

    $key = $counters[$eventName];

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $select = $pdo->prepare('SELECT value FROM settings WHERE `key` = :key LIMIT 1 FOR UPDATE');
        $select->execute(['key' => $key]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        $current = 0;
        if ($row !== false) {
            $current = (int) $row['value'];
        }

        $newValue = (string) ($current + 1);

        if ($row === false) {
            $insert = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value)');
            $insert->execute(['key' => $key, 'value' => $newValue]);
        } else {
            $update = $pdo->prepare('UPDATE settings SET value = :value WHERE `key` = :key');
            $update->execute(['value' => $newValue, 'key' => $key]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        audit_log(null, 'webhook_handler_error', ['event' => $eventName]);
    }
}
