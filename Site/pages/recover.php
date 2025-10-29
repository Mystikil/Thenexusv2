<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_recovery.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--recover"><h2>Account Recovery</h2><p class="text-muted mb-0">Unavailable.</p></section>';

    return;
}

$rotateOnUse = nx_recovery_rotate_on_use_enabled($pdo);

$errors = [];
$successMessage = '';
$revealedRecoveryKey = null;
$accountName = '';
$recoveryKeyInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $accountName = trim((string) ($_POST['account_name'] ?? ''));
    $recoveryKeyInput = trim((string) ($_POST['recovery_key'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!csrf_validate($token)) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $ip = ip_address();

        if (nx_recovery_too_many_attempts($pdo, $accountName, $ip, RECOVERY_WINDOW_SECONDS, RECOVERY_ATTEMPT_LIMIT)) {
            $errors[] = 'Too many attempts. Try again later.';
        } else {
            nx_record_recovery_attempt($pdo, $accountName, $ip);

            if ($accountName === '') {
                $errors[] = 'Please enter your account name.';
            }

            if ($recoveryKeyInput === '') {
                $errors[] = 'Please enter your recovery key.';
            }

            if ($newPassword === '' || strlen($newPassword) < 8) {
                $errors[] = 'New password must be at least 8 characters long.';
            }

            if ($newPassword !== $confirmPassword) {
                $errors[] = 'New password and confirmation do not match.';
            }

            if ($errors === []) {
                $verification = nx_verify_recovery_key($pdo, $accountName, $recoveryKeyInput);

                if ($verification === null) {
                    $errors[] = 'Invalid account or recovery key.';
                } else {
                    $accountId = (int) $verification['account_id'];
                    $startedTransaction = false;

                    try {
                        if (!$pdo->inTransaction()) {
                            $pdo->beginTransaction();
                            $startedTransaction = true;
                        }

                        nx_password_set($pdo, $accountId, $newPassword);

                        if (defined('PASSWORD_MODE') && PASSWORD_MODE === 'dual') {
                            $webUpdate = $pdo->prepare('UPDATE website_users SET pass_hash = :pass_hash WHERE account_id = :account_id');
                            $webUpdate->execute([
                                'pass_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                                'account_id' => $accountId,
                            ]);
                        }

                        if ($rotateOnUse) {
                            $newRecoveryKey = nx_generate_recovery_key();
                            nx_set_recovery_key($pdo, $accountId, $newRecoveryKey);
                        }

                        if ($startedTransaction && $pdo->inTransaction()) {
                            $pdo->commit();
                        }

                        audit_log(null, 'recover_account_password', null, ['account_id' => $accountId]);

                        if ($rotateOnUse) {
                            $revealedRecoveryKey = $newRecoveryKey;
                            $successMessage = 'Your password has been reset. Save the new recovery key below and then log in.';
                        } else {
                            $successMessage = 'Your password has been reset. Your recovery key remains valid—store it safely.';
                        }
                        $accountName = '';
                        $recoveryKeyInput = '';
                    } catch (Throwable $exception) {
                        if ($startedTransaction && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $errors[] = 'Unable to reset your password right now. Please try again later.';
                    }
                }
            }
        }
    }
}

$csrfToken = csrf_token();
?>
<section class="page page--recover">
    <h2>Account Recovery</h2>

    <p>Use your recovery key to reset the password on your game account. Each attempt is rate limited to protect against abuse.</p>

    <p class="form-help">Password tips:</p>
    <ul class="form-help-list">
        <li>Passwords must be at least 8 characters—aim for 12 or more for better security.</li>
        <li>Mix uppercase and lowercase letters, numbers, and symbols to increase entropy.</li>
        <li>Avoid reusing passwords across sites and keep your recovery key private.</li>
    </ul>

    <?php if ($successMessage): ?>
        <div class="alert alert--success"><?php echo sanitize($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <ul class="form-errors">
            <?php foreach ($errors as $error): ?>
                <li><?php echo sanitize($error); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($revealedRecoveryKey !== null): ?>
        <div class="account-recovery-modal" role="alertdialog" aria-labelledby="recovery-modal-title" aria-describedby="recovery-modal-body">
            <h3 id="recovery-modal-title">Save Your New Recovery Key</h3>
            <p id="recovery-modal-body">This replacement key is shown only once. Store it somewhere secure.</p>
            <div class="account-recovery-modal__key"><code><?php echo sanitize($revealedRecoveryKey); ?></code></div>
            <form class="account-recovery-modal__confirm" method="get" action="?p=recover">
                <div class="form-group">
                    <input type="checkbox" id="recovery-modal-confirm" name="ack" value="1" required>
                    <label for="recovery-modal-confirm">I have stored this safely</label>
                </div>
                <div class="form-actions">
                    <button type="submit">OK</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <form class="account-form" method="post" action="?p=recover">
        <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">

        <div class="form-group">
            <label for="recover-account-name">Account Name</label>
            <input type="text" id="recover-account-name" name="account_name" value="<?php echo sanitize($accountName); ?>" required autocomplete="username">
        </div>

        <div class="form-group">
            <label for="recover-key">Recovery Key</label>
            <input type="text" id="recover-key" name="recovery_key" value="<?php echo sanitize($recoveryKeyInput); ?>" required autocomplete="one-time-code">
        </div>

        <div class="form-group">
            <label for="recover-new-password">New Password</label>
            <input type="password" id="recover-new-password" name="new_password" required autocomplete="new-password">
        </div>

        <div class="form-group">
            <label for="recover-confirm-password">Confirm New Password</label>
            <input type="password" id="recover-confirm-password" name="confirm_password" required autocomplete="new-password">
        </div>

        <div class="form-actions">
            <button type="submit">Reset Password</button>
        </div>
    </form>

    <p>Remember to <a href="?p=account">return to the login page</a> once your password has been updated.</p>
</section>
