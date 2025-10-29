<?php

declare(strict_types=1);

if (!function_exists('rate_limit_check')) {
    /**
     * @throws void This function will exit via json_out on rate limit errors.
     */
    function rate_limit_check(PDO $pdo, string $key, int $limit, int $windowSeconds = 60): void
    {
        $now = time();
        $resetAt = $now + $windowSeconds;

        $startedTransaction = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }

            $select = $pdo->prepare('SELECT requests, reset_at FROM api_rate_limits WHERE rate_key = :rate_key LIMIT 1 FOR UPDATE');
            $select->execute(['rate_key' => $key]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if ($row === false || (int) $row['reset_at'] <= $now) {
                $upsert = $pdo->prepare('REPLACE INTO api_rate_limits (rate_key, requests, reset_at) VALUES (:rate_key, :requests, :reset_at)');
                $upsert->execute([
                    'rate_key' => $key,
                    'requests' => 1,
                    'reset_at' => $resetAt,
                ]);
                if ($startedTransaction) {
                    $pdo->commit();
                }
                return;
            }

            $requests = (int) $row['requests'];

            if ($requests >= $limit) {
                $retryAfter = max(1, (int) $row['reset_at'] - $now);
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                header('Retry-After: ' . $retryAfter);
                json_out([
                    'status' => 'error',
                    'message' => 'Rate limit exceeded',
                ], 429);
            }

            $update = $pdo->prepare('UPDATE api_rate_limits SET requests = :requests WHERE rate_key = :rate_key');
            $update->execute([
                'requests' => $requests + 1,
                'rate_key' => $key,
            ]);

            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (PDOException $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            json_out([
                'status' => 'error',
                'message' => 'Database error enforcing rate limit',
            ], 500);
        }
    }
}

if (!function_exists('client_rate_limit_key')) {
    function client_rate_limit_key(string $prefix): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (!is_string($ip) || $ip === '') {
            $ip = 'unknown';
        }

        $ip = substr($ip, 0, 45);

        return $prefix . ':' . $ip;
    }
}
