<?php

function db(): ?PDO
{
    static $pdo;
    static $failed = false;

    if ($pdo instanceof PDO) {
        nx_warn_if_account_unlinked($pdo);

        return $pdo;
    }

    if ($failed) {
        return null;
    }

    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $GLOBALS['nx_db_last_error'] = null;
    } catch (Throwable $exception) {
        $failed = true;
        $GLOBALS['nx_db_last_error'] = $exception;
        error_log('Database connection failed: ' . $exception->getMessage());

        return null;
    }

    nx_warn_if_account_unlinked($pdo);

    return $pdo;
}

function nx_database_available(): bool
{
    return db() instanceof PDO;
}

function nx_database_last_error(): ?Throwable
{
    $error = $GLOBALS['nx_db_last_error'] ?? null;

    return $error instanceof Throwable ? $error : null;
}

function nx_warn_if_account_unlinked(PDO $pdo): void
{
    static $warned = false;

    if ($warned) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['user_id'])) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT account_id FROM website_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false && ($row['account_id'] ?? null) === null) {
            error_log(sprintf('Warning: website user %d is not linked to a game account.', (int) $_SESSION['user_id']));
        }

        $warned = true;
    } catch (Throwable $exception) {
        // Ignore schema check errors; connection still usable.
        $warned = true;
    }
}
