<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Merge Accounts';
$adminNavActive = 'merge_accounts';

require __DIR__ . '/partials/header.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="admin-section"><h2>Merge Accounts</h2><div class="admin-alert admin-alert--error">Database connection unavailable.</div></section>';
    require __DIR__ . '/partials/footer.php';

    return;
}
$currentAdmin = current_user();
$actorIsMaster = $currentAdmin !== null && is_master($currentAdmin);
$csrfToken = csrf_token();
$successMessage = take_flash('success');
$errorMessage = take_flash('error');
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchResults = ['users' => [], 'accounts' => []];
$mode = 'search';
$previewData = null;

function nx_admin_merge_fetch_user(PDO $pdo, int $userId): ?array
{
    $sql = sprintf(
        'SELECT wu.*, a.%1$s AS account_name FROM website_users wu '
        . 'LEFT JOIN %2$s a ON a.%3$s = wu.account_id '
        . 'WHERE wu.id = :id LIMIT 1',
        TFS_NAME_COL,
        TFS_ACCOUNTS_TABLE,
        TFS_ACCOUNT_ID_COL
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    $coinStmt = $pdo->prepare('SELECT coins FROM coin_balances WHERE user_id = :user_id LIMIT 1');
    $coinStmt->execute(['user_id' => $userId]);
    $coinRow = $coinStmt->fetch();

    $orderStmt = $pdo->prepare('SELECT COUNT(*) AS order_count FROM shop_orders WHERE user_id = :user_id');
    $orderStmt->execute(['user_id' => $userId]);
    $orderRow = $orderStmt->fetch();

    return [
        'id' => (int) $row['id'],
        'email' => $row['email'],
        'account_id' => $row['account_id'] !== null ? (int) $row['account_id'] : null,
        'account_name' => $row['account_name'] ?? null,
        'role' => $row['role'],
        'created_at' => $row['created_at'],
        'coins' => $coinRow !== false ? (int) $coinRow['coins'] : 0,
        'orders' => $orderRow !== false ? (int) $orderRow['order_count'] : 0,
    ];
}

function nx_admin_merge_fetch_account(PDO $pdo, int $accountId): ?array
{
    $sql = sprintf('SELECT * FROM %s WHERE %s = :id LIMIT 1', TFS_ACCOUNTS_TABLE, TFS_ACCOUNT_ID_COL);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $accountId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    return [
        'id' => (int) $row[TFS_ACCOUNT_ID_COL],
        'name' => $row[TFS_NAME_COL] ?? null,
        'email' => $row['email'] ?? null,
    ];
}

function nx_admin_merge_fetch_linked_users(PDO $pdo, int $accountId, int $excludeUserId = 0): array
{
    $stmt = $pdo->prepare('SELECT id FROM website_users WHERE account_id = :account_id');
    $stmt->execute(['account_id' => $accountId]);

    $ids = [];

    while ($row = $stmt->fetch()) {
        $id = (int) $row['id'];

        if ($excludeUserId !== 0 && $id === $excludeUserId) {
            continue;
        }

        $ids[] = $id;
    }

    return $ids;
}

function nx_admin_merge_compute_after(array $target, array $linkedUsers): array
{
    $totalCoins = $target['coins'];
    $totalOrders = $target['orders'];

    foreach ($linkedUsers as $user) {
        $totalCoins += $user['coins'];
        $totalOrders += $user['orders'];
    }

    return [
        'id' => $target['id'],
        'email' => $target['email'],
        'account_id' => $target['account_id'],
        'account_name' => $target['account_name'] ?? null,
        'coins' => $totalCoins,
        'orders' => $totalOrders,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? null;

    if (!csrf_validate($token)) {
        flash('error', 'Invalid request. Please try again.');
        redirect('merge_accounts.php');
    }

    if ($action === 'preview') {
        $websiteUserId = (int) ($_POST['website_user_id'] ?? 0);
        $accountId = (int) ($_POST['account_id'] ?? 0);

        if ($websiteUserId <= 0 || $accountId <= 0) {
            flash('error', 'Please select both a website user and an account.');
            redirect('merge_accounts.php');
        }

        $targetUser = nx_admin_merge_fetch_user($pdo, $websiteUserId);
        $account = nx_admin_merge_fetch_account($pdo, $accountId);

        if ($targetUser === null) {
            flash('error', 'The selected website user could not be found.');
            redirect('merge_accounts.php');
        }

        if ($account === null) {
            flash('error', 'The selected account could not be found.');
            redirect('merge_accounts.php');
        }

        $linkedIds = nx_admin_merge_fetch_linked_users($pdo, $accountId, $targetUser['id']);
        $linkedUsers = [];

        foreach ($linkedIds as $id) {
            $linkedUser = nx_admin_merge_fetch_user($pdo, $id);

            if ($linkedUser !== null) {
                $linkedUsers[] = $linkedUser;
            }
        }

        $afterTarget = nx_admin_merge_compute_after($targetUser, $linkedUsers);
        $afterTarget['account_id'] = $account['id'];
        $afterTarget['account_name'] = $account['name'];

        $previewData = [
            'target_before' => $targetUser,
            'target_after' => $afterTarget,
            'linked_users' => $linkedUsers,
            'account' => $account,
        ];
        $mode = 'preview';
    } elseif ($action === 'merge') {
        $websiteUserId = (int) ($_POST['website_user_id'] ?? 0);
        $accountId = (int) ($_POST['account_id'] ?? 0);

        if ($websiteUserId <= 0 || $accountId <= 0) {
            flash('error', 'Please select both a website user and an account.');
            redirect('merge_accounts.php');
        }

        try {
            $pdo->beginTransaction();

            $targetStmt = $pdo->prepare('SELECT * FROM website_users WHERE id = :id LIMIT 1 FOR UPDATE');
            $targetStmt->execute(['id' => $websiteUserId]);
            $targetRow = $targetStmt->fetch();

            if ($targetRow === false) {
                throw new RuntimeException('Target website user not found.');
            }

            $accountSql = sprintf('SELECT * FROM %s WHERE %s = :id LIMIT 1 FOR UPDATE', TFS_ACCOUNTS_TABLE, TFS_ACCOUNT_ID_COL);
            $accountStmt = $pdo->prepare($accountSql);
            $accountStmt->execute(['id' => $accountId]);
            $accountRow = $accountStmt->fetch();

            if ($accountRow === false) {
                throw new RuntimeException('Target account not found.');
            }

            $linkedStmt = $pdo->prepare('SELECT * FROM website_users WHERE account_id = :account_id FOR UPDATE');
            $linkedStmt->execute(['account_id' => $accountId]);
            $linkedRows = $linkedStmt->fetchAll();

            $linkedUsers = [];

            foreach ($linkedRows as $linkedRow) {
                $linkedId = (int) $linkedRow['id'];

                if ($linkedId === $websiteUserId) {
                    continue;
                }

                $snapshot = nx_admin_merge_fetch_user($pdo, $linkedId);

                if ($snapshot !== null) {
                    $linkedUsers[] = $snapshot;
                }
            }

            $before = [
                'target' => nx_admin_merge_fetch_user($pdo, $websiteUserId),
                'account' => nx_admin_merge_fetch_account($pdo, $accountId),
                'linked_users' => $linkedUsers,
            ];

            $targetCoinStmt = $pdo->prepare('SELECT * FROM coin_balances WHERE user_id = :user_id LIMIT 1 FOR UPDATE');
            $targetCoinStmt->execute(['user_id' => $websiteUserId]);
            $targetCoinRow = $targetCoinStmt->fetch();
            $mergedCoins = $targetCoinRow !== false ? (int) $targetCoinRow['coins'] : 0;

            $sourceCoinRows = [];

            foreach ($linkedUsers as $linkedUser) {
                $coinStmt = $pdo->prepare('SELECT * FROM coin_balances WHERE user_id = :user_id LIMIT 1 FOR UPDATE');
                $coinStmt->execute(['user_id' => $linkedUser['id']]);
                $coinRow = $coinStmt->fetch();

                if ($coinRow !== false) {
                    $sourceCoinRows[] = $coinRow;
                    $mergedCoins += (int) $coinRow['coins'];
                }
            }

            foreach ($linkedUsers as $linkedUser) {
                $orderMoveStmt = $pdo->prepare('UPDATE shop_orders SET user_id = :target_id WHERE user_id = :source_id');
                $orderMoveStmt->execute([
                    'target_id' => $websiteUserId,
                    'source_id' => $linkedUser['id'],
                ]);
            }

            foreach ($sourceCoinRows as $coinRow) {
                $deleteCoinStmt = $pdo->prepare('DELETE FROM coin_balances WHERE id = :id');
                $deleteCoinStmt->execute(['id' => $coinRow['id']]);
            }

            if ($targetCoinRow !== false) {
                $updateCoinStmt = $pdo->prepare('UPDATE coin_balances SET coins = :coins WHERE id = :id');
                $updateCoinStmt->execute([
                    'coins' => $mergedCoins,
                    'id' => $targetCoinRow['id'],
                ]);
            } elseif ($sourceCoinRows !== []) {
                $insertCoinStmt = $pdo->prepare('INSERT INTO coin_balances (user_id, coins) VALUES (:user_id, :coins)');
                $insertCoinStmt->execute([
                    'user_id' => $websiteUserId,
                    'coins' => $mergedCoins,
                ]);
            }

            foreach ($linkedUsers as $linkedUser) {
                $unlinkStmt = $pdo->prepare('UPDATE website_users SET account_id = NULL WHERE id = :id');
                $unlinkStmt->execute(['id' => $linkedUser['id']]);
            }

            $linkStmt = $pdo->prepare('UPDATE website_users SET account_id = :account_id WHERE id = :id');
            $linkStmt->execute([
                'account_id' => $accountId,
                'id' => $websiteUserId,
            ]);

            $after = [
                'target' => nx_admin_merge_fetch_user($pdo, $websiteUserId),
                'account' => nx_admin_merge_fetch_account($pdo, $accountId),
                'linked_users' => [],
                'a_is_master' => $actorIsMaster ? 1 : 0,
            ];

            $pdo->commit();

            audit_log($currentAdmin['id'] ?? null, 'merge_accounts', $before, $after);

            flash('success', 'Accounts merged successfully.');
            redirect('merge_accounts.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash('error', $exception->getMessage());
            redirect('merge_accounts.php');
        }
    }
}

if ($searchQuery !== '') {
    $lowerQuery = strtolower($searchQuery);
    $likeQuery = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $lowerQuery) . '%';

    $userSql = sprintf(
        'SELECT wu.id, wu.email, wu.account_id, wu.role, wu.created_at, a.%1$s AS account_name '
        . 'FROM website_users wu '
        . 'LEFT JOIN %2$s a ON a.%3$s = wu.account_id '
        . 'WHERE LOWER(wu.email) LIKE :query OR LOWER(a.%1$s) LIKE :query '
        . 'ORDER BY wu.created_at DESC LIMIT 25',
        TFS_NAME_COL,
        TFS_ACCOUNTS_TABLE,
        TFS_ACCOUNT_ID_COL
    );

    $userStmt = $pdo->prepare($userSql);
    $userStmt->execute(['query' => $likeQuery]);
    $searchResults['users'] = $userStmt->fetchAll();

    $accountSql = sprintf(
        'SELECT %1$s AS id, %2$s AS name, email FROM %3$s '
        . 'WHERE LOWER(%2$s) LIKE :query OR LOWER(email) LIKE :query '
        . 'ORDER BY %2$s ASC LIMIT 25',
        TFS_ACCOUNT_ID_COL,
        TFS_NAME_COL,
        TFS_ACCOUNTS_TABLE
    );

    $accountStmt = $pdo->prepare($accountSql);
    $accountStmt->execute(['query' => $likeQuery]);
    $searchResults['accounts'] = $accountStmt->fetchAll();
}

?>
<section class="admin-section">
    <h2>Merge Website User with Account</h2>
    <p>Search by email or account name to find the records you want to link.</p>
    <form method="get" class="admin-form">
        <label>
            Search
            <input type="text" name="q" value="<?php echo sanitize($searchQuery); ?>" placeholder="Email or account name">
        </label>
        <button type="submit">Search</button>
    </form>
    <?php if ($successMessage): ?>
        <div class="admin-alert admin-alert--success"><?php echo sanitize($successMessage); ?></div>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <div class="admin-alert admin-alert--error"><?php echo sanitize($errorMessage); ?></div>
    <?php endif; ?>
</section>

<?php if ($searchQuery !== ''): ?>
<section class="admin-section">
    <h3>Search Results</h3>
    <?php if ($searchResults['users'] === [] && $searchResults['accounts'] === []): ?>
        <p>No results found for that query.</p>
    <?php else: ?>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
            <input type="hidden" name="action" value="preview">
            <div class="admin-grid">
                <div>
                    <h4>Website Users</h4>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Email</th>
                                <th>Account</th>
                                <th>Role</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($searchResults['users'] === []): ?>
                                <tr>
                                    <td colspan="5">No website users matched that query.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($searchResults['users'] as $user): ?>
                                    <tr>
                                        <td><input type="radio" name="website_user_id" value="<?php echo (int) $user['id']; ?>" required></td>
                                        <td><?php echo sanitize($user['email'] ?? ''); ?></td>
                                        <td><?php echo sanitize($user['account_name'] ?? ($user['account_id'] !== null ? ('#' . $user['account_id']) : 'Not linked')); ?></td>
                                        <td><?php echo sanitize($user['role']); ?></td>
                                        <td><?php echo sanitize($user['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h4>Accounts</h4>
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Account Name</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($searchResults['accounts'] === []): ?>
                                <tr>
                                    <td colspan="3">No accounts matched that query.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($searchResults['accounts'] as $account): ?>
                                    <tr>
                                        <td><input type="radio" name="account_id" value="<?php echo (int) $account['id']; ?>" required></td>
                                        <td><?php echo sanitize($account['name'] ?? ''); ?></td>
                                        <td><?php echo sanitize($account['email'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <button type="submit">Preview Merge</button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($mode === 'preview' && $previewData !== null): ?>
<section class="admin-section">
    <h3>Confirm Merge</h3>
    <p>Review the changes below. The account will be linked to the selected website user and any related data will be reassigned.</p>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Field</th>
                <th>Before</th>
                <th>After</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Website User ID</td>
                <td><?php echo sanitize((string) $previewData['target_before']['id']); ?></td>
                <td><?php echo sanitize((string) $previewData['target_after']['id']); ?></td>
            </tr>
            <tr>
                <td>Email</td>
                <td><?php echo sanitize((string) $previewData['target_before']['email']); ?></td>
                <td><?php echo sanitize((string) $previewData['target_after']['email']); ?></td>
            </tr>
            <tr>
                <td>Account Link</td>
                <td>
                    <?php if ($previewData['target_before']['account_id'] !== null): ?>
                        #<?php echo sanitize((string) $previewData['target_before']['account_id']); ?>
                        (<?php echo sanitize((string) ($previewData['target_before']['account_name'] ?? '')); ?>)
                    <?php else: ?>
                        Not linked
                    <?php endif; ?>
                </td>
                <td>#<?php echo sanitize((string) $previewData['account']['id']); ?> (<?php echo sanitize((string) ($previewData['account']['name'] ?? '')); ?>)</td>
            </tr>
            <tr>
                <td>Coin Balance</td>
                <td><?php echo sanitize((string) $previewData['target_before']['coins']); ?></td>
                <td><?php echo sanitize((string) $previewData['target_after']['coins']); ?></td>
            </tr>
            <tr>
                <td>Shop Orders</td>
                <td><?php echo sanitize((string) $previewData['target_before']['orders']); ?></td>
                <td><?php echo sanitize((string) $previewData['target_after']['orders']); ?></td>
            </tr>
        </tbody>
    </table>

    <?php if ($previewData['linked_users'] !== []): ?>
        <h4>Website Users Currently Linked to This Account</h4>
        <p>The following website users will be detached from the account and their purchases and coins moved to the target user:</p>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Email</th>
                    <th>Coin Balance</th>
                    <th>Shop Orders</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($previewData['linked_users'] as $linkedUser): ?>
                    <tr>
                        <td><?php echo sanitize((string) $linkedUser['id']); ?></td>
                        <td><?php echo sanitize((string) $linkedUser['email']); ?></td>
                        <td><?php echo sanitize((string) $linkedUser['coins']); ?></td>
                        <td><?php echo sanitize((string) $linkedUser['orders']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
        <input type="hidden" name="website_user_id" value="<?php echo (int) $previewData['target_before']['id']; ?>">
        <input type="hidden" name="account_id" value="<?php echo (int) $previewData['account']['id']; ?>">
        <input type="hidden" name="action" value="merge">
        <button type="submit" class="admin-button admin-button--danger">Confirm Merge</button>
        <a href="merge_accounts.php" class="admin-button">Cancel</a>
    </form>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php';
