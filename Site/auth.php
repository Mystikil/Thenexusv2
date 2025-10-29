<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_passwords.php';

/** Normalize emails for comparison (lowercase, trim) */
function nx_norm_email(?string $e): string
{
    $e = trim((string) $e);

    return strtolower($e);
}

/** True if the current user (or given user row) is configured as master */
function is_master(?array $user = null): bool
{
    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return false;
    }

    $email = nx_norm_email($user['email'] ?? '');

    $masters = defined('MASTER_ACCOUNTS') ? MASTER_ACCOUNTS : [];

    foreach ($masters as $m) {
        if ($email && $email === nx_norm_email($m)) {
            return true;
        }
    }

    return false;
}

/**
 * Role check (>= target role). Masters always pass.
 * Role order: user < mod < gm < admin < owner
 */
function is_role(string $roleOrAbove, ?array $user = null): bool
{
    static $order = ['user' => 0, 'mod' => 1, 'gm' => 2, 'admin' => 3, 'owner' => 4];

    if ($user === null) {
        $user = current_user();
    }

    if (!$user) {
        return false;
    }

    $isMaster = is_master($user);

    if ($isMaster && (!defined('MASTER_BYPASS_RBAC') || MASTER_BYPASS_RBAC)) {
        return true;
    }

    $have = $isMaster ? $order['owner'] : ($order[strtolower($user['role'] ?? 'user')] ?? 0);
    $need = $order[strtolower($roleOrAbove)] ?? 0;

    return $have >= $need;
}

/** Guard for admin pages. Masters bypass RBAC if enabled. */
function require_admin(?string $minRole = 'admin'): void
{
    $u = current_user();

    if (!$u) {
        header('Location: ?p=account');
        exit;
    }

    if (is_master($u) && (!defined('MASTER_BYPASS_RBAC') || MASTER_BYPASS_RBAC)) {
        return;
    }

    if (!is_role($minRole, $u)) {
        http_response_code(403);
        echo '<div class="container-page"><div class="card nx-glow"><div class="card-body"><h4>Forbidden</h4><p>You do not have permission.</p></div></div></div>';
        exit;
    }
}

/**
 * Authentication helpers for linking website users to legacy TFS accounts.
 */

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $sql = sprintf(
        'SELECT wu.*, '
        . 'a.%1$s AS linked_account_id, a.%2$s AS linked_account_name, a.email AS linked_account_email, '
        . 'ae.%1$s AS fallback_account_id, ae.%2$s AS fallback_account_name, ae.email AS fallback_account_email '
        . 'FROM website_users wu '
        . 'LEFT JOIN %3$s a ON a.%1$s = wu.account_id '
        . 'LEFT JOIN %3$s ae ON wu.account_id IS NULL AND ae.email = wu.email '
        . 'WHERE wu.id = :id LIMIT 1',
        TFS_ACCOUNT_ID_COL,
        TFS_NAME_COL,
        TFS_ACCOUNTS_TABLE
    );

    $pdo = db();

    if (!$pdo instanceof PDO) {
        return null;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user === false) {
        unset($_SESSION['user_id']);
        return null;
    }

    if (($user['account_id'] ?? null) === null) {
        $fallbackId = $user['fallback_account_id'] ?? null;

        if ($fallbackId !== null) {
            $user['account_id'] = (int) $fallbackId;
            $user['account_name'] = (string) ($user['fallback_account_name'] ?? '');
            $user['account_email'] = (string) ($user['fallback_account_email'] ?? '');
        }
    } else {
        $user['account_id'] = (int) $user['account_id'];
        $user['account_name'] = (string) ($user['linked_account_name'] ?? '');
        $user['account_email'] = (string) ($user['linked_account_email'] ?? '');
    }

    unset($user['linked_account_id'], $user['linked_account_name'], $user['linked_account_email']);
    unset($user['fallback_account_id'], $user['fallback_account_name'], $user['fallback_account_email']);

    if (isset($user['email'])) {
        $user['email'] = nx_norm_email((string) $user['email']);
    }

    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (is_logged_in()) {
        return;
    }

    flash('error', 'You must be logged in to access that page.');
    redirect('?p=account');
}

/**
 * Register a new website user and linked TFS account.
 *
 * The returned website user will always include an `account_id` pointing to
 * the corresponding row in the legacy `accounts` table.
 *
 * @return array{success:bool,errors?:array<int,string>,user?:array}
 */
function register(string $email, string $password, string $accountName): array
{
    $pdo = db();

    if (!$pdo instanceof PDO) {
        return [
            'success' => false,
            'errors' => ['Registration is currently unavailable. Please try again later.'],
        ];
    }

    $errors = [];

    $email = nx_norm_email($email);
    $accountName = trim($accountName);

    if (!nx_password_rate_limit($pdo, 'register', 5, 60)) {
        return [
            'success' => false,
            'errors' => ['Too many registration attempts. Please try again later.'],
        ];
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if ($password === '' || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }

    if ($accountName === '' || !preg_match('/^[A-Za-z0-9]{3,20}$/', $accountName)) {
        $errors[] = 'Account name must be 3-20 characters using only letters and numbers.';
    }

    if ($errors !== []) {
        return [
            'success' => false,
            'errors' => $errors,
        ];
    }

    $userExists = $pdo->prepare('SELECT id FROM website_users WHERE LOWER(email) = :email LIMIT 1');
    $userExists->execute(['email' => $email]);

    if ($userExists->fetch()) {
        return [
            'success' => false,
            'errors' => ['An account with that email already exists.'],
        ];
    }

    $normalizedAccount = strtolower($accountName);
    $accountExistsSql = sprintf(
        'SELECT %1$s FROM %2$s WHERE LOWER(%3$s) = :name LIMIT 1',
        TFS_ACCOUNT_ID_COL,
        TFS_ACCOUNTS_TABLE,
        TFS_NAME_COL
    );

    $accountExists = $pdo->prepare($accountExistsSql);
    $accountExists->execute(['name' => $normalizedAccount]);

    if ($accountExists->fetch()) {
        return [
            'success' => false,
            'errors' => ['That account name is already taken.'],
        ];
    }

    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $initialPassword = str_repeat('0', 40);
        $accountFields = [
            TFS_NAME_COL => $accountName,
            TFS_PASS_COL => $initialPassword,
            'email' => $email,
            'creation' => time(),
        ];

        if (nx_password_supports_salt()) {
            $accountFields[SALT_COL] = '';
        }

        $columns = array_keys($accountFields);
        $placeholders = [];
        $params = [];

        foreach ($accountFields as $column => $value) {
            $placeholders[] = ':' . $column;
            $params[$column] = $value;
        }

        $accountSql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            TFS_ACCOUNTS_TABLE,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $accountStmt = $pdo->prepare($accountSql);
        $accountStmt->execute($params);
        $accountId = (int) $pdo->lastInsertId();

        nx_password_set($pdo, $accountId, $password);

        $webHash = nx_password_mode() === 'dual'
            ? nx_hash_web_secure($password)
            : null;

        $websiteInsert = $pdo->prepare(
            'INSERT INTO website_users (email, pass_hash, account_id, role, created_at) '
            . 'VALUES (:email, :pass_hash, :account_id, :role, NOW())'
        );
        $websiteInsert->execute([
            'email' => $email,
            'pass_hash' => $webHash,
            'account_id' => $accountId,
            'role' => 'user',
        ]);

        $userId = (int) $pdo->lastInsertId();

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'success' => false,
            'errors' => ['Unable to create your account at this time. Please try again.'],
        ];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    $user = current_user();

    if ($user === null || (int) $user['id'] !== $userId) {
        $sql = 'SELECT * FROM website_users WHERE id = :id LIMIT 1';
        $userStmt = $pdo->prepare($sql);
        $userStmt->execute(['id' => $userId]);
        $user = $userStmt->fetch();

        if ($user === false) {
            return [
                'success' => false,
                'errors' => ['There was a problem completing your registration.'],
            ];
        }
    }

    audit_log($userId, 'register', null, [
        'email' => $user['email'],
        'account_id' => $user['account_id'] ?? null,
        'account_name' => $accountName,
    ]);

    return [
        'success' => true,
        'user' => $user,
    ];
}

/**
 * Log in using either an email address or a TFS account name.
 *
 * @return array{success:bool,errors?:array<int,string>,user?:array}
 */
function login(string $accountNameOrEmail, string $password): array
{
    $pdo = db();

    if (!$pdo instanceof PDO) {
        return [
            'success' => false,
            'errors' => ['Login is currently unavailable. Please try again later.'],
        ];
    }

    $identifier = trim($accountNameOrEmail);
    $errors = [];

    if ($identifier === '') {
        $errors[] = 'Email or account name is required.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    }

    if ($errors !== []) {
        return [
            'success' => false,
            'errors' => $errors,
        ];
    }

    if (!nx_password_rate_limit($pdo, 'login', 10, 60)) {
        return [
            'success' => false,
            'errors' => ['Too many login attempts. Please try again later.'],
        ];
    }

    $isEmail = strpos($identifier, '@') !== false;
    if ($isEmail) {
        $identifier = nx_norm_email($identifier);
    }
    $websiteUser = null;
    $accountRow = null;
    $used = 'tfs';
    $linkedAutomatically = false;
    $autoProvisionWebsiteUser = !defined('ALLOW_AUTO_PROVISION_WEBSITE_USER')
        || ALLOW_AUTO_PROVISION_WEBSITE_USER === true;

    if ($isEmail) {
        $stmt = $pdo->prepare('SELECT * FROM website_users WHERE LOWER(email) = :email LIMIT 1');
        $stmt->execute(['email' => $identifier]);
        $websiteUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($websiteUser === null) {
            return [
                'success' => false,
                'errors' => ['Invalid account credentials.'],
            ];
        }

        $accountId = isset($websiteUser['account_id']) ? (int) $websiteUser['account_id'] : 0;

        if ($accountId > 0) {
            $accountRow = nx_fetch_account_by_id($pdo, $accountId);
        }

        if ($accountRow === null && isset($websiteUser['email']) && $websiteUser['email'] !== null) {
            $candidateRows = nx_find_account_candidates($pdo, (string) $websiteUser['email']);

            if (count($candidateRows) === 1) {
                $accountRow = $candidateRows[0];
            }
        }
    } else {
        $accountRow = nx_fetch_account_by_name($pdo, $identifier);

        if ($accountRow === null) {
            return [
                'success' => false,
                'errors' => ['Invalid account credentials.'],
            ];
        }

        $accountId = (int) $accountRow[TFS_ACCOUNT_ID_COL];

        $stmt = $pdo->prepare('SELECT * FROM website_users WHERE account_id = :account_id LIMIT 1');
        $stmt->execute(['account_id' => $accountId]);
        $websiteUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($websiteUser === null) {
            if (!$autoProvisionWebsiteUser) {
                return [
                    'success' => false,
                    'errors' => ['This game account is not linked to a website profile yet. Please create a website account before logging in.'],
                ];
            }

            $webHash = nx_password_mode() === 'dual'
                ? nx_hash_web_secure($password)
                : null;

            $insert = $pdo->prepare(
                'INSERT INTO website_users (email, pass_hash, account_id, role, created_at) '
                . 'VALUES (:email, :pass_hash, :account_id, :role, NOW())'
            );
            $emailValue = $accountRow['email'] ?? null;
            $insert->execute([
                'email' => $emailValue !== '' ? nx_norm_email((string) $emailValue) : null,
                'pass_hash' => $webHash,
                'account_id' => $accountId,
                'role' => 'user',
            ]);

            $websiteUserId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM website_users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $websiteUserId]);
            $websiteUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }

    if ($websiteUser === null) {
        return [
            'success' => false,
            'errors' => ['Unable to load your account profile. Please contact support.'],
        ];
    }

    $webHash = (string) ($websiteUser['pass_hash'] ?? '');
    $modernOk = $webHash !== '' && nx_verify_web_secure($password, $webHash);

    $legacyOk = false;
    $accountId = isset($websiteUser['account_id']) ? (int) $websiteUser['account_id'] : 0;

    if ($accountRow !== null) {
        $legacyOk = nx_verify_account_password($accountRow, $password);

        if ($legacyOk && $accountId === 0) {
            $accountId = (int) $accountRow[TFS_ACCOUNT_ID_COL];

            try {
                $update = $pdo->prepare('UPDATE website_users SET account_id = :account_id WHERE id = :id');
                $update->execute([
                    'account_id' => $accountId,
                    'id' => (int) $websiteUser['id'],
                ]);
                audit_log((int) $websiteUser['id'], 'link_account', null, [
                    'account_id' => $accountId,
                    'method' => 'auto',
                ]);
                $websiteUser['account_id'] = $accountId;
                $linkedAutomatically = true;
            } catch (Throwable $exception) {
                // Non-fatal; continue without linking.
            }
        }
    }

    if (!$modernOk && !$legacyOk) {
        return [
            'success' => false,
            'errors' => ['Invalid account credentials.'],
        ];
    }

    if ($modernOk) {
        $used = 'web';
    }

    if (!$modernOk && nx_password_mode() === 'dual') {
        // Upgrade stored hash for dual mode users logging in via TFS password.
        try {
            $newHash = nx_hash_web_secure($password);
            $update = $pdo->prepare('UPDATE website_users SET pass_hash = :pass_hash WHERE id = :id');
            $update->execute([
                'pass_hash' => $newHash,
                'id' => (int) $websiteUser['id'],
            ]);
            $websiteUser['pass_hash'] = $newHash;
        } catch (Throwable $exception) {
            // Ignore upgrade errors.
        }
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $websiteUser['id'];

    audit_log((int) $websiteUser['id'], 'login', null, [
        'used' => $used,
        'linked' => $linkedAutomatically,
    ]);

    nx_on_successful_login_upgrade($pdo, (int) $websiteUser['id'], $password);

    $current = current_user();

    if ($current !== null) {
        $websiteUser = $current;
    }

    return [
        'success' => true,
        'user' => $websiteUser,
    ];
}

/**
 * Link an existing website user to an in-game account.
 *
 * @return array{success:bool,errors?:array<int,string>}
 */
function link_account_manual(int $userId, string $accountName, string $password): array
{
    $pdo = db();

    if (!$pdo instanceof PDO) {
        return [
            'success' => false,
            'errors' => ['Account linking is currently unavailable. Please try again later.'],
        ];
    }

    $errors = [];

    $accountName = trim($accountName);

    if ($accountName === '' || !preg_match('/^[A-Za-z0-9]{3,20}$/', $accountName)) {
        $errors[] = 'Please enter a valid account name (letters and numbers, 3-20 characters).';
    }

    if ($password === '') {
        $errors[] = 'Password is required to link your game account.';
    }

    if ($errors !== []) {
        return [
            'success' => false,
            'errors' => $errors,
        ];
    }

    $stmt = $pdo->prepare('SELECT * FROM website_users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $websiteUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($websiteUser === false) {
        return [
            'success' => false,
            'errors' => ['Unable to load your profile. Please try again.'],
        ];
    }

    if (!empty($websiteUser['account_id'])) {
        return [
            'success' => false,
            'errors' => ['Your profile is already linked to a game account.'],
        ];
    }

    $accountRow = nx_fetch_account_by_name($pdo, $accountName);

    if ($accountRow === null) {
        return [
            'success' => false,
            'errors' => ['That account name could not be found.'],
        ];
    }

    if (!nx_verify_account_password($accountRow, $password)) {
        return [
            'success' => false,
            'errors' => ['The password did not match that account.'],
        ];
    }

    $accountId = (int) $accountRow[TFS_ACCOUNT_ID_COL];

    try {
        $update = $pdo->prepare('UPDATE website_users SET account_id = :account_id WHERE id = :id');
        $update->execute([
            'account_id' => $accountId,
            'id' => $userId,
        ]);

        audit_log($userId, 'link_account', null, [
            'account_id' => $accountId,
            'method' => 'manual',
        ]);
    } catch (Throwable $exception) {
        return [
            'success' => false,
            'errors' => ['Unable to link your account right now. Please try again later.'],
        ];
    }

    return ['success' => true];
}

/**
 * @return array<int, array<string, mixed>>
 */
function nx_find_account_candidates(PDO $pdo, string $email): array
{
    $candidates = [];
    $email = nx_norm_email($email);

    if ($email === '') {
        return $candidates;
    }

    $sql = sprintf('SELECT * FROM %s WHERE LOWER(email) = :email', TFS_ACCOUNTS_TABLE);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => $email]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $candidates[] = $row;
    }

    $localPart = strtolower((string) strstr($email, '@', true));

    if ($localPart !== '' && $localPart !== false) {
        $nameSql = sprintf(
            'SELECT * FROM %s WHERE LOWER(%s) = :name',
            TFS_ACCOUNTS_TABLE,
            TFS_NAME_COL
        );
        $nameStmt = $pdo->prepare($nameSql);
        $nameStmt->execute(['name' => $localPart]);
        $byName = $nameStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($byName as $row) {
            $existingIds = array_column($candidates, TFS_ACCOUNT_ID_COL);
            if (!in_array($row[TFS_ACCOUNT_ID_COL], $existingIds, true)) {
                $candidates[] = $row;
            }
        }
    }

    return $candidates;
}

function nx_fetch_account_by_id(PDO $pdo, int $accountId): ?array
{
    $sql = sprintf(
        'SELECT * FROM %s WHERE %s = :id LIMIT 1',
        TFS_ACCOUNTS_TABLE,
        TFS_ACCOUNT_ID_COL
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function nx_fetch_account_by_name(PDO $pdo, string $accountName): ?array
{
    $normalized = strtolower(trim($accountName));

    if ($normalized === '') {
        return null;
    }

    $sql = sprintf(
        'SELECT * FROM %s WHERE LOWER(%s) = :name LIMIT 1',
        TFS_ACCOUNTS_TABLE,
        TFS_NAME_COL
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['name' => $normalized]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function nx_fetch_account_by_email(PDO $pdo, string $email): ?array
{
    $normalized = nx_norm_email($email);

    if ($normalized === '') {
        return null;
    }

    $sql = sprintf(
        'SELECT * FROM %s WHERE LOWER(email) = :email LIMIT 1',
        TFS_ACCOUNTS_TABLE
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => $normalized]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function nx_verify_account_password(array $accountRow, string $password): bool
{
    $legacyMode = nx_password_legacy_mode();
    $salt = nx_password_supports_salt() ? ($accountRow[SALT_COL] ?? null) : null;
    $hash = (string) ($accountRow[TFS_PASS_COL] ?? '');

    if ($hash === '') {
        return false;
    }

    if (nx_verify_tfs($password, $hash, $salt, $legacyMode)) {
        return true;
    }

    if (ALLOW_FALLBACKS !== true) {
        return false;
    }

    foreach (['tfs_sha1', 'tfs_md5'] as $candidate) {
        if ($candidate === $legacyMode) {
            continue;
        }

        if (nx_verify_tfs($password, $hash, $salt, $candidate)) {
            return true;
        }
    }

    return false;
}

function logout(): void
{
    $user = current_user();

    if ($user !== null) {
        audit_log((int) $user['id'], 'logout');
    }

    unset($_SESSION['user_id']);
    session_regenerate_id(true);
}

function audit_log(?int $userId, string $action, ?array $before = null, ?array $after = null): void
{
    try {
        $pdo = db();

        if (!$pdo instanceof PDO) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO audit_log (user_id, action, before_json, after_json, ip) VALUES (:user_id, :action, :before_json, :after_json, :ip)');
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'ip' => ip_address(),
        ]);
    } catch (Throwable $exception) {
        // Swallow logging errors so auth flow continues.
    }
}

function ip_address(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    if (!is_string($ip) || $ip === '') {
        return null;
    }

    return substr($ip, 0, 45);
}
