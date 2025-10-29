<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/rate_limiter.php';

if (!defined('PASSWORD_MODE')) {
    define('PASSWORD_MODE', 'tfs_sha1');
}

if (!defined('TFS_ACCOUNTS_TABLE')) {
    define('TFS_ACCOUNTS_TABLE', 'accounts');
}

if (!defined('TFS_ACCOUNT_ID_COL')) {
    define('TFS_ACCOUNT_ID_COL', 'id');
}

if (!defined('TFS_NAME_COL')) {
    define('TFS_NAME_COL', 'name');
}

if (!defined('TFS_PASS_COL')) {
    define('TFS_PASS_COL', 'password');
}

if (!defined('PASS_WITH_SALT')) {
    define('PASS_WITH_SALT', false);
}

if (!defined('SALT_COL')) {
    define('SALT_COL', 'salt');
}

if (!defined('ALLOW_FALLBACKS')) {
    define('ALLOW_FALLBACKS', false);
}

if (!function_exists('nx_password_mode')) {
    function nx_password_mode(): string
    {
        $mode = strtolower((string) PASSWORD_MODE);
        $allowed = ['tfs_sha1', 'tfs_md5', 'tfs_plain', 'dual'];

        if (!in_array($mode, $allowed, true)) {
            $mode = 'tfs_sha1';
        }

        return $mode;
    }
}

if (!function_exists('nx_password_legacy_mode')) {
    function nx_password_legacy_mode(): string
    {
        $mode = nx_password_mode();

        if ($mode === 'dual') {
            return 'tfs_sha1';
        }

        return $mode;
    }
}

if (!function_exists('nx_password_supports_salt')) {
    function nx_password_supports_salt(): bool
    {
        return defined('PASS_WITH_SALT') && PASS_WITH_SALT === true;
    }
}

if (!function_exists('nx_password_generate_salt')) {
    function nx_password_generate_salt(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            return bin2hex(openssl_random_pseudo_bytes(16));
        }
    }
}

if (!function_exists('nx_hash_tfs_sha1')) {
    function nx_hash_tfs_sha1(string $password, ?string $salt = null): string
    {
        if (nx_password_supports_salt() && $salt !== null && $salt !== '') {
            return sha1($salt . $password);
        }

        return sha1($password);
    }
}

if (!function_exists('nx_hash_tfs_md5')) {
    function nx_hash_tfs_md5(string $password, ?string $salt = null): string
    {
        if (nx_password_supports_salt() && $salt !== null && $salt !== '') {
            return md5($salt . $password);
        }

        return md5($password);
    }
}

if (!function_exists('nx_verify_tfs')) {
    function nx_verify_tfs(string $inputPassword, string $storedHash, ?string $salt, string $mode): bool
    {
        $mode = strtolower($mode);
        $salt = nx_password_supports_salt() ? ($salt ?? '') : null;
        $storedHash = (string) $storedHash;

        switch ($mode) {
            case 'tfs_plain':
                return hash_equals($storedHash, $inputPassword);
            case 'tfs_md5':
                $candidate = nx_hash_tfs_md5($inputPassword, $salt);
                return hash_equals($storedHash, $candidate);
            case 'tfs_sha1':
            default:
                $candidate = nx_hash_tfs_sha1($inputPassword, $salt);
                return hash_equals($storedHash, $candidate);
        }
    }
}

if (!function_exists('nx_hash_web_secure')) {
    function nx_hash_web_secure(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('nx_verify_web_secure')) {
    function nx_verify_web_secure(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }
}

if (!function_exists('nx_password_compute_legacy_hash')) {
    function nx_password_compute_legacy_hash(string $plainPassword, string $mode, ?string $existingSalt = null): array
    {
        $salt = null;

        if (nx_password_supports_salt()) {
            $salt = $existingSalt;

            if ($salt === null || $salt === '') {
                $salt = nx_password_generate_salt();
            }
        }

        switch ($mode) {
            case 'tfs_plain':
                $hash = $plainPassword;
                break;
            case 'tfs_md5':
                $hash = nx_hash_tfs_md5($plainPassword, $salt);
                break;
            case 'tfs_sha1':
            default:
                $hash = nx_hash_tfs_sha1($plainPassword, $salt);
                break;
        }

        return [
            'hash' => $hash,
            'salt' => $salt,
        ];
    }
}

if (!function_exists('nx_password_account_columns')) {
    function nx_password_account_columns(): array
    {
        $columns = [
            'account_id' => TFS_ACCOUNT_ID_COL,
            'account_name' => TFS_NAME_COL,
            'account_password' => TFS_PASS_COL,
        ];

        if (nx_password_supports_salt()) {
            $columns['account_salt'] = SALT_COL;
        }

        return $columns;
    }
}

if (!function_exists('nx_password_rate_limit')) {
    function nx_password_rate_limit(PDO $pdo, string $prefix, int $limit, int $windowSeconds = 60): bool
    {
        $key = client_rate_limit_key($prefix);
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

                return true;
            }

            $requests = (int) $row['requests'];

            if ($requests >= $limit) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }

                return false;
            }

            $update = $pdo->prepare('UPDATE api_rate_limits SET requests = :requests WHERE rate_key = :rate_key');
            $update->execute([
                'requests' => $requests + 1,
                'rate_key' => $key,
            ]);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return true;
        } catch (PDOException $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Rate limit error: ' . $exception->getMessage());

            return true;
        }
    }
}

if (!function_exists('nx_password_set')) {
    function nx_password_set(PDO $pdo, int $accountId, string $plainPassword): void
    {
        $legacyMode = nx_password_legacy_mode();

        $columns = nx_password_account_columns();
        $selectColumns = [];

        foreach ($columns as $alias => $column) {
            $selectColumns[] = 'a.' . $column . ' AS ' . $alias;
        }

        $selectColumns[] = 'a.email AS account_email';

        $accountSql = sprintf(
            'SELECT %s FROM %s a WHERE a.%s = :account_id LIMIT 1',
            implode(', ', $selectColumns),
            TFS_ACCOUNTS_TABLE,
            TFS_ACCOUNT_ID_COL
        );

        $accountStmt = $pdo->prepare($accountSql);
        $accountStmt->execute(['account_id' => $accountId]);
        $accountRow = $accountStmt->fetch(PDO::FETCH_ASSOC);

        if ($accountRow === false) {
            throw new RuntimeException('Account not found for password update.');
        }

        $salt = nx_password_supports_salt() ? ($accountRow['account_salt'] ?? null) : null;
        $legacy = nx_password_compute_legacy_hash($plainPassword, $legacyMode, $salt);

        $updateParts = [sprintf('%s = :password', TFS_PASS_COL)];
        $params = [
            'password' => $legacy['hash'],
            'account_id' => $accountId,
        ];

        if (nx_password_supports_salt() && $legacy['salt'] !== null) {
            $updateParts[] = sprintf('%s = :salt', SALT_COL);
            $params['salt'] = $legacy['salt'];
        }

        $updateSql = sprintf(
            'UPDATE %s SET %s WHERE %s = :account_id',
            TFS_ACCOUNTS_TABLE,
            implode(', ', $updateParts),
            TFS_ACCOUNT_ID_COL
        );

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);

        $webHash = nx_hash_web_secure($plainPassword);
        $email = (string) ($accountRow['account_email'] ?? '');
        $normalizedEmail = function_exists('nx_norm_email') ? nx_norm_email($email) : strtolower(trim($email));

        if ($normalizedEmail !== '') {
            $webUpdate = $pdo->prepare('UPDATE website_users SET pass_hash = :pass_hash WHERE LOWER(email) = :email');
            $webUpdate->execute([
                'pass_hash' => $webHash,
                'email' => $normalizedEmail,
            ]);
        }
    }
}

if (!function_exists('nx_password_verify_account')) {
    function nx_password_verify_account(PDO $pdo, string $accountNameOrEmail, string $inputPassword): array
    {
        $identifier = trim($accountNameOrEmail);

        if ($identifier === '') {
            return [
                'ok' => false,
                'userRow' => null,
                'used' => 'tfs',
            ];
        }

        $columns = nx_password_account_columns();
        $selectColumns = [];

        foreach ($columns as $alias => $column) {
            $selectColumns[] = 'a.' . $column . ' AS ' . $alias;
        }

        $selectColumns[] = 'a.email AS account_email';
        $selectColumns[] = 'wu.id AS website_user_id';
        $selectColumns[] = 'wu.pass_hash AS website_pass_hash';
        $selectColumns[] = 'wu.email AS website_email';
        $selectColumns[] = 'wu.role';
        $selectColumns[] = 'wu.twofa_secret';
        $selectColumns[] = 'wu.theme_preference';
        $selectColumns[] = 'wu.created_at';

        $sql = sprintf(
            'SELECT %s FROM %s a LEFT JOIN website_users wu ON wu.email = a.email WHERE a.%s = :identifier OR a.email = :identifier OR wu.email = :identifier LIMIT 1',
            implode(', ', $selectColumns),
            TFS_ACCOUNTS_TABLE,
            TFS_NAME_COL
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['identifier' => $identifier]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return [
                'ok' => false,
                'userRow' => null,
                'used' => 'tfs',
            ];
        }

        $legacyMode = nx_password_legacy_mode();
        $mode = nx_password_mode();
        $used = 'tfs';
        $verified = false;
        $salt = nx_password_supports_salt() ? ($row['account_salt'] ?? null) : null;

        if ($mode === 'dual' && isset($row['website_pass_hash']) && $row['website_pass_hash'] !== null) {
            $webHash = (string) $row['website_pass_hash'];

            if ($webHash !== '' && nx_verify_web_secure($inputPassword, $webHash)) {
                $verified = true;
                $used = 'web';
            }
        }

        if (!$verified) {
            $accountPassword = (string) $row['account_password'];

            if ($accountPassword !== '' && nx_verify_tfs($inputPassword, $accountPassword, $salt, $legacyMode)) {
                $verified = true;
            } elseif (ALLOW_FALLBACKS === true) {
                $fallbackMatched = null;
                foreach (['tfs_sha1', 'tfs_md5'] as $candidate) {
                    if ($candidate === $legacyMode) {
                        continue;
                    }

                    if (nx_verify_tfs($inputPassword, $accountPassword, $salt, $candidate)) {
                        $verified = true;
                        $fallbackMatched = $candidate;
                        break;
                    }
                }

                if ($fallbackMatched !== null) {
                    $userId = isset($row['website_user_id']) && $row['website_user_id'] !== null
                        ? (int) $row['website_user_id']
                        : null;

                    $after = [
                        'mode' => $fallbackMatched,
                        'identifier' => $identifier,
                    ];

                    if (function_exists('audit_log')) {
                        audit_log($userId, 'password_fallback_match', null, $after);
                    }
                }
            }
        }

        if (!$verified) {
            return [
                'ok' => false,
                'userRow' => null,
                'used' => $used,
            ];
        }

        $websiteUserId = isset($row['website_user_id']) ? (int) $row['website_user_id'] : null;

        if ($websiteUserId === null) {
            $userRow = [
                'id' => 0,
                'email' => (string) ($row['account_email'] ?? ''),
                'role' => 'user',
                'twofa_secret' => null,
                'theme_preference' => null,
                'created_at' => null,
            ];
        } else {
            $userStmt = $pdo->prepare('SELECT * FROM website_users WHERE id = :id LIMIT 1');
            $userStmt->execute(['id' => $websiteUserId]);
            $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($userRow === false) {
                $userRow = [
                    'id' => 0,
                    'email' => (string) ($row['account_email'] ?? ''),
                    'role' => 'user',
                    'twofa_secret' => null,
                    'theme_preference' => null,
                    'created_at' => null,
                ];
            }
        }

        $userRow['account_id'] = (int) $row['account_id'];
        $userRow['account_name'] = (string) $row['account_name'];
        $userRow['account_email'] = (string) ($row['account_email'] ?? '');

        return [
            'ok' => true,
            'userRow' => $userRow,
            'used' => $used,
        ];
    }
}

if (!function_exists('nx_on_successful_login_upgrade')) {
    function nx_on_successful_login_upgrade(PDO $pdo, int $userId, string $inputPassword): void
    {
        if (nx_password_mode() !== 'dual') {
            return;
        }

        $stmt = $pdo->prepare('SELECT pass_hash FROM website_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return;
        }

        $currentHash = (string) ($row['pass_hash'] ?? '');

        if ($currentHash === '' || password_needs_rehash($currentHash, PASSWORD_DEFAULT)) {
            $newHash = nx_hash_web_secure($inputPassword);
            $update = $pdo->prepare('UPDATE website_users SET pass_hash = :pass_hash WHERE id = :id');
            $update->execute([
                'pass_hash' => $newHash,
                'id' => $userId,
            ]);
        }
    }
}

if (!function_exists('nx_generate_account_name')) {
    function nx_generate_account_name(PDO $pdo, string $email): string
    {
        $localPart = strtolower((string) strstr($email, '@', true));

        if ($localPart === '' || $localPart === false) {
            $localPart = strtolower(preg_replace('/[^a-z0-9]/i', '', $email));
        }

        $localPart = preg_replace('/[^a-z0-9]/', '', (string) $localPart);

        if ($localPart === '') {
            $localPart = 'account';
        }

        $localPart = substr($localPart, 0, 24);
        $base = $localPart !== '' ? $localPart : 'account';
        $candidate = $base;
        $suffix = 1;

        while (nx_account_name_exists($pdo, $candidate)) {
            $suffixString = (string) $suffix;
            $candidate = substr($base, 0, max(1, 32 - strlen($suffixString))) . $suffixString;
            $suffix++;
        }

        return $candidate;
    }
}

if (!function_exists('nx_account_name_exists')) {
    function nx_account_name_exists(PDO $pdo, string $name): bool
    {
        $sql = sprintf('SELECT 1 FROM %s WHERE %s = :name LIMIT 1', TFS_ACCOUNTS_TABLE, TFS_NAME_COL);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['name' => $name]);

        return (bool) $stmt->fetchColumn();
    }
}
