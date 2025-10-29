<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Users';
$adminNavActive = 'users';

require __DIR__ . '/partials/header.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="admin-section"><h2>Users</h2><div class="admin-alert admin-alert--error">Database connection unavailable.</div></section>';
    require __DIR__ . '/partials/footer.php';

    return;
}
$currentAdmin = current_user();
$adminId = $currentAdmin !== null ? (int) $currentAdmin['id'] : null;

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$errors = [];
$successes = [];
$revealedRecoveryKey = null;
$accountRow = null;
$websiteUser = null;
$targetIsMaster = false;
$recoveryMeta = ['has_key' => false, 'created_at' => null];
$adminPlainAllowed = nx_recovery_admin_plain_allowed($pdo);
$actorIsMaster = $currentAdmin !== null && is_master($currentAdmin);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $searchQuery = trim((string) ($_POST['search_query'] ?? $searchQuery));

    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The request could not be validated. Please try again.';
    } elseif ($accountId <= 0) {
        $errors[] = 'A valid account must be selected before performing that action.';
    } else {
        $accountRow = nx_fetch_account_by_id($pdo, $accountId);

        if ($accountRow === null) {
            $errors[] = 'The specified account could not be found.';
        } else {
            $recoveryMeta = nx_fetch_recovery_key_meta($pdo, $accountId);

            if ($websiteUser === null) {
                $websiteStmt = $pdo->prepare('SELECT * FROM website_users WHERE account_id = :account_id LIMIT 1');
                $websiteStmt->execute(['account_id' => $accountId]);
                $websiteRow = $websiteStmt->fetch(PDO::FETCH_ASSOC);

                if ($websiteRow !== false) {
                    $websiteUser = $websiteRow;
                }
            }

            $targetUser = $websiteUser;

            if ($targetUser === null && isset($accountRow['email']) && $accountRow['email'] !== '') {
                $targetUser = [
                    'email' => nx_norm_email((string) $accountRow['email']),
                    'role' => 'owner',
                ];
            }

            $targetIsMaster = $targetUser !== null && is_master($targetUser);

            if ($targetIsMaster) {
                $errors[] = 'This is a MASTER account and cannot be modified here.';
            } else {
                switch ($action) {
                    case 'invalidate_recovery_key':
                    if (!$recoveryMeta['has_key']) {
                        $errors[] = 'No recovery key is currently assigned to this account.';
                        break;
                    }

                    if (!nx_clear_recovery_key($pdo, $accountId)) {
                        $errors[] = 'Unable to invalidate the recovery key. Please try again later.';
                        break;
                    }

                    $successes[] = 'The recovery key has been invalidated.';
                    $afterMeta = nx_fetch_recovery_key_meta($pdo, $accountId);
                    audit_log($adminId, 'invalidate_recovery_key', [
                        'account_id' => $accountId,
                        'had_key' => $recoveryMeta['has_key'],
                        'created_at' => $recoveryMeta['created_at'],
                    ], [
                        'account_id' => $accountId,
                        'had_key' => $afterMeta['has_key'],
                        'created_at' => $afterMeta['created_at'],
                        'a_is_master' => $actorIsMaster ? 1 : 0,
                    ]);
                    $recoveryMeta = $afterMeta;
                    break;

                case 'rotate_recovery_key':
                    if (!$adminPlainAllowed) {
                        $errors[] = 'Plain recovery key viewing is disabled in settings.';
                        break;
                    }

                    if (($_POST['confirm_delivery'] ?? '') !== '1') {
                        $errors[] = 'Please confirm you will deliver the new recovery key to the verified owner.';
                        break;
                    }

                    $newKey = nx_generate_recovery_key();

                    if (!nx_set_recovery_key($pdo, $accountId, $newKey)) {
                        $errors[] = 'Unable to rotate the recovery key at this time. Please try again later.';
                        break;
                    }

                    $revealedRecoveryKey = $newKey;
                    $successes[] = 'A new recovery key has been generated. Share it securely with the verified owner.';
                    $afterMeta = nx_fetch_recovery_key_meta($pdo, $accountId);
                    audit_log($adminId, 'rotate_recovery_key_admin', [
                        'account_id' => $accountId,
                        'previous_created_at' => $recoveryMeta['created_at'],
                    ], [
                        'account_id' => $accountId,
                        'created_at' => $afterMeta['created_at'],
                        'a_is_master' => $actorIsMaster ? 1 : 0,
                    ]);
                    $recoveryMeta = $afterMeta;
                    break;

                    default:
                        $errors[] = 'Unknown admin action requested.';
                        break;
                }
            }
        }
    }
}

if ($accountRow === null && $searchQuery !== '') {
    $accountRow = nx_fetch_account_by_name($pdo, $searchQuery);

    if ($accountRow === null) {
        $normalizedEmail = nx_norm_email($searchQuery);

        if ($normalizedEmail !== '') {
            $websiteStmt = $pdo->prepare('SELECT * FROM website_users WHERE LOWER(email) = :email LIMIT 1');
            $websiteStmt->execute(['email' => $normalizedEmail]);
            $websiteRow = $websiteStmt->fetch(PDO::FETCH_ASSOC);

            if ($websiteRow !== false) {
                $websiteUser = $websiteRow;
                $linkedAccountId = isset($websiteRow['account_id']) ? (int) $websiteRow['account_id'] : 0;

                if ($linkedAccountId > 0) {
                    $accountRow = nx_fetch_account_by_id($pdo, $linkedAccountId);
                }

                if ($accountRow === null) {
                    $accountRow = nx_fetch_account_by_email($pdo, (string) $websiteRow['email']);
                }
            } else {
                $accountRow = nx_fetch_account_by_email($pdo, $searchQuery);
            }
        }
    }
}

if ($accountRow !== null) {
    $accountId = (int) $accountRow[TFS_ACCOUNT_ID_COL];
    $recoveryMeta = nx_fetch_recovery_key_meta($pdo, $accountId);

    if ($websiteUser === null) {
        $websiteStmt = $pdo->prepare('SELECT * FROM website_users WHERE account_id = :account_id LIMIT 1');
        $websiteStmt->execute(['account_id' => $accountId]);
        $websiteRow = $websiteStmt->fetch(PDO::FETCH_ASSOC);

        if ($websiteRow !== false) {
            $websiteUser = $websiteRow;
        }
    }
}

$targetUser = $websiteUser;

if ($targetUser === null && $accountRow !== null && isset($accountRow['email']) && $accountRow['email'] !== '') {
    $targetUser = [
        'email' => nx_norm_email((string) $accountRow['email']),
        'role' => 'owner',
    ];
}

$targetIsMaster = $targetUser !== null && is_master($targetUser);

$csrfToken = csrf_token();
?>

<section class="admin-section">
    <h2>Account Recovery Tools</h2>
    <p>Search by game account name or website email to view recovery status and perform support actions.</p>

    <?php if ($errors !== []): ?>
        <div class="admin-alert admin-alert--error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo sanitize($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successes !== []): ?>
        <div class="admin-alert admin-alert--success">
            <ul>
                <?php foreach ($successes as $message): ?>
                    <li><?php echo sanitize($message); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($revealedRecoveryKey !== null): ?>
        <div class="admin-alert admin-alert--warning">
            <p class="admin-alert__title">New Recovery Key (shown once)</p>
            <code><?php echo sanitize($revealedRecoveryKey); ?></code>
        </div>
    <?php endif; ?>

    <form class="admin-form admin-form--inline" method="get" action="users.php">
        <div class="admin-form__group">
            <label for="search-query">Account Name or Website Email</label>
            <input type="text" id="search-query" name="q" value="<?php echo sanitize($searchQuery); ?>" placeholder="Search...">
        </div>
        <div class="admin-form__actions">
            <button type="submit" class="admin-button">Search</button>
        </div>
    </form>

    <?php if ($accountRow !== null): ?>
        <?php $accountId = (int) $accountRow[TFS_ACCOUNT_ID_COL]; ?>
        <div class="admin-card">
            <h3>Account Details</h3>
            <p><strong>Account Name:</strong> <?php echo sanitize((string) ($accountRow[TFS_NAME_COL] ?? '')); ?></p>
            <p><strong>Account Email:</strong> <?php echo sanitize((string) ($accountRow['email'] ?? '')); ?></p>
            <p>
                <strong>Recovery Key Active:</strong>
                <?php echo $recoveryMeta['has_key'] ? 'Yes' : 'No'; ?>
                <?php if ($recoveryMeta['created_at'] !== null): ?>
                    <span class="admin-table__meta">Generated at <?php echo sanitize($recoveryMeta['created_at']); ?> UTC</span>
                <?php endif; ?>
            </p>

            <?php if ($websiteUser !== null): ?>
                <p><strong>Linked Website User:</strong> <?php echo sanitize((string) ($websiteUser['email'] ?? '')); ?></p>
            <?php endif; ?>

            <?php if ($targetIsMaster): ?>
                <div class="admin-alert admin-alert--warning">
                    <p class="admin-alert__title">Master Account</p>
                    <p>This is a MASTER account and cannot be modified here.</p>
                </div>
            <?php else: ?>
                <div class="admin-form__actions">
                    <form method="post" action="users.php">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
                        <input type="hidden" name="action" value="invalidate_recovery_key">
                        <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                        <input type="hidden" name="search_query" value="<?php echo sanitize($searchQuery); ?>">
                        <button type="submit" class="admin-button admin-button--secondary"<?php echo $recoveryMeta['has_key'] ? '' : ' disabled'; ?>>Invalidate Recovery Key</button>
                    </form>

                    <form method="post" action="users.php">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
                        <input type="hidden" name="action" value="rotate_recovery_key">
                        <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                        <input type="hidden" name="search_query" value="<?php echo sanitize($searchQuery); ?>">
                        <label class="admin-checkbox-inline">
                            <input type="checkbox" name="confirm_delivery" value="1"> I will deliver to verified owner
                        </label>
                        <button type="submit" class="admin-button"<?php echo $adminPlainAllowed ? '' : ' disabled'; ?>>Force Rotation (Generate New)</button>
                    </form>
                </div>

                <?php if (!$adminPlainAllowed): ?>
                    <p class="admin-table__meta">Plain recovery keys cannot be shown while "Allow Admin Plain Recovery View" is disabled in settings.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php elseif ($searchQuery !== ''): ?>
        <p>No account could be located for that query.</p>
    <?php endif; ?>

    <?php if ($websiteUser !== null && $accountRow === null): ?>
        <div class="admin-card">
            <h3>Website User</h3>
            <p><strong>Email:</strong> <?php echo sanitize((string) ($websiteUser['email'] ?? '')); ?></p>
            <p>This website profile is not linked to a game account.</p>
        </div>
    <?php endif; ?>
</section>

<?php
require __DIR__ . '/partials/footer.php';
