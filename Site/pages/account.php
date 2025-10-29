<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/theme.php';

require_once __DIR__ . '/../auth_recovery.php';

$loginErrors = [];
$registerErrors = [];
$passwordErrors = [];
$themeErrors = [];
$linkErrors = [];
$recoveryErrors = [];
$generatedRecoveryKey = null;
$showRecoveryKeyModal = false;
$loginIdentifier = '';
$registerEmail = '';
$registerAccountName = '';
$themes = nx_all_themes();

if (!nx_database_available()) {
    echo '<section class="page page--account"><h2>Account</h2><p class="text-muted mb-0">Account services are currently unavailable. Please try again later.</p></section>';

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? null;

    if ($action === 'login') {
        $loginIdentifier = trim((string) ($_POST['identifier'] ?? ''));
    }

    if ($action === 'register') {
        $registerEmail = nx_norm_email($_POST['email'] ?? '');
        $registerAccountName = trim((string) ($_POST['account_name'] ?? ''));
    }

    if (!csrf_validate($token)) {
        if ($action === 'login') {
            $loginErrors[] = 'Invalid request. Please try again.';
        } elseif ($action === 'register') {
            $registerErrors[] = 'Invalid request. Please try again.';
        } elseif ($action === 'password') {
            $passwordErrors[] = 'Invalid request. Please try again.';
        } elseif ($action === 'generate_recovery_key') {
            $recoveryErrors[] = 'Invalid request. Please try again.';
        } elseif ($action === 'logout') {
            flash('error', 'Invalid request. Please try again.');
            redirect('?p=account');
        }
    } else {
        switch ($action) {
            case 'login':
                $password = (string) ($_POST['password'] ?? '');
                $result = login($loginIdentifier, $password);

                if ($result['success'] ?? false) {
                    flash('success', 'You are now logged in.');
                    redirect('?p=account');
                }

                $loginErrors = $result['errors'] ?? ['Unable to log in.'];
                break;

            case 'register':
                $password = (string) ($_POST['password'] ?? '');
                $confirm = (string) ($_POST['confirm_password'] ?? '');
                $accountName = trim((string) ($_POST['account_name'] ?? ''));

                if ($password !== $confirm) {
                    $registerErrors[] = 'Passwords do not match.';
                    break;
                }

                $result = register($registerEmail, $password, $accountName);

                if ($result['success'] ?? false) {
                    flash('success', 'Your account has been created.');
                    redirect('?p=account');
                }

                $registerErrors = $result['errors'] ?? ['Unable to register at this time.'];
                break;

            case 'logout':
                logout();
                flash('success', 'You have been logged out.');
                redirect('?p=account');
                break;

            case 'password':
                if (!is_logged_in()) {
                    flash('error', 'You must be logged in to change your password.');
                    redirect('?p=account');
                }

                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

                if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                    $passwordErrors[] = 'All password fields are required.';
                }

                if ($newPassword !== $confirmPassword) {
                    $passwordErrors[] = 'New password and confirmation do not match.';
                }

                if ($newPassword !== '' && strlen($newPassword) < 8) {
                    $passwordErrors[] = 'New password must be at least 8 characters long.';
                }

                if ($passwordErrors === []) {
                    $user = current_user();

                    if ($user === null) {
                        $passwordErrors[] = 'Unable to load your account. Please try again.';
                        break;
                    }

                    $accountEmail = (string) ($user['account_email'] ?? $user['email'] ?? '');
                    $accountId = isset($user['account_id']) ? (int) $user['account_id'] : 0;

                    if ($accountEmail === '') {
                        $passwordErrors[] = 'Unable to locate your account record.';
                        break;
                    }

                    $pdo = db();

                    $selectParts = [
                        sprintf('a.%s AS account_id', TFS_ACCOUNT_ID_COL),
                        sprintf('a.%s AS account_password', TFS_PASS_COL),
                    ];

                    if (nx_password_supports_salt()) {
                        $selectParts[] = sprintf('a.%s AS account_salt', SALT_COL);
                    }

                    if ($accountId > 0) {
                        $accountSql = sprintf(
                            'SELECT %s FROM %s a WHERE a.%s = :value LIMIT 1',
                            implode(', ', $selectParts),
                            TFS_ACCOUNTS_TABLE,
                            TFS_ACCOUNT_ID_COL
                        );
                        $accountParams = ['value' => $accountId];
                    } else {
                        $accountSql = sprintf(
                            'SELECT %s FROM %s a WHERE a.email = :value LIMIT 1',
                            implode(', ', $selectParts),
                            TFS_ACCOUNTS_TABLE
                        );
                        $accountParams = ['value' => $accountEmail];
                    }

                    $accountStmt = $pdo->prepare($accountSql);
                    $accountStmt->execute($accountParams);
                    $accountRow = $accountStmt->fetch(PDO::FETCH_ASSOC);

                    if ($accountRow === false) {
                        $passwordErrors[] = 'Unable to locate your account record.';
                        break;
                    }

                    $accountId = (int) $accountRow['account_id'];
                    $legacyOk = nx_verify_account_password($accountRow, $currentPassword);
                    $modernOk = nx_verify_web_secure($currentPassword, (string) ($user['pass_hash'] ?? ''));

                    if (!$legacyOk && !$modernOk) {
                        $passwordErrors[] = 'Current password is incorrect.';
                        break;
                    }

                    $startedTransaction = false;

                    try {
                        if (!$pdo->inTransaction()) {
                            $pdo->beginTransaction();
                            $startedTransaction = true;
                        }

                        nx_password_set($pdo, $accountId, $newPassword);

                        if (defined('PASSWORD_MODE') && PASSWORD_MODE === 'dual') {
                            $update = $pdo->prepare('UPDATE website_users SET pass_hash = :pass_hash WHERE id = :id');
                            $update->execute([
                                'pass_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                                'id' => (int) $user['id'],
                            ]);
                        }

                        if ($startedTransaction && $pdo->inTransaction()) {
                            $pdo->commit();
                        }
                    } catch (Throwable $exception) {
                        if ($startedTransaction && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $passwordErrors[] = 'Unable to update your password right now. Please try again later.';
                        break;
                    }

                    audit_log((int) $user['id'], 'change_password', null, ['account_id' => $accountId]);

                    flash('success', 'Your password has been updated.');
                    redirect('?p=account');
                }

                break;

            case 'generate_recovery_key':
                if (!is_logged_in()) {
                    flash('error', 'You must be logged in to generate a recovery key.');
                    redirect('?p=account');
                }

                $user = current_user();

                if ($user === null) {
                    $recoveryErrors[] = 'Unable to load your profile. Please try again.';
                    break;
                }

                $accountId = isset($user['account_id']) ? (int) $user['account_id'] : 0;

                if ($accountId <= 0) {
                    $recoveryErrors[] = 'You must link a game account before generating a recovery key.';
                    break;
                }

                $pdo = db();

                if (nx_account_has_recovery_key($pdo, $accountId)) {
                    $recoveryErrors[] = 'A recovery key is already active for this account.';
                    break;
                }

                $newKey = nx_generate_recovery_key();

                if (!nx_set_recovery_key($pdo, $accountId, $newKey)) {
                    $recoveryErrors[] = 'Unable to generate a recovery key right now. Please try again later.';
                    break;
                }

                audit_log((int) $user['id'], 'generate_recovery_key', null, ['account_id' => $accountId]);

                $generatedRecoveryKey = $newKey;
                $showRecoveryKeyModal = true;

                break;

            case 'link_account':
                if (!is_logged_in()) {
                    flash('error', 'You must be logged in to link a game account.');
                    redirect('?p=account');
                }

                $accountName = trim((string) ($_POST['account_name'] ?? ''));
                $accountPassword = (string) ($_POST['account_password'] ?? '');
                $user = current_user();

                if ($user === null) {
                    $linkErrors[] = 'Unable to load your profile. Please try again.';
                    break;
                }

                $result = link_account_manual((int) $user['id'], $accountName, $accountPassword);

                if ($result['success'] ?? false) {
                    flash('success', 'Your website profile is now linked to your game account.');
                    redirect('?p=account');
                }

                $linkErrors = $result['errors'] ?? ['Unable to link your account right now.'];

                break;

            case 'theme':
                if (!is_logged_in()) {
                    flash('error', 'You must be logged in to update your theme preference.');
                    redirect('?p=account');
                }

                $selectedTheme = trim((string) ($_POST['theme'] ?? ''));

                if ($selectedTheme === '' || isset($themes[$selectedTheme])) {
                    $user = current_user();

                    if ($user !== null) {
                        $pdo = db();

                        if ($selectedTheme === '') {
                            $stmt = $pdo->prepare('UPDATE website_users SET theme_preference = NULL WHERE id = :id');
                            $stmt->execute(['id' => (int) $user['id']]);
                        } else {
                            $stmt = $pdo->prepare('UPDATE website_users SET theme_preference = :theme WHERE id = :id');
                            $stmt->execute([
                                'theme' => $selectedTheme,
                                'id' => (int) $user['id'],
                            ]);
                        }

                        flash('success', 'Your theme preference has been updated.');
                        redirect('?p=account');
                    }
                } else {
                    $themeErrors[] = 'The selected theme is not available.';
                }

                break;
        }
    }
}

$csrfToken = csrf_token();
$successMessage = take_flash('success');
$errorMessage = take_flash('error');
$user = current_user();
$selectedThemeSlug = $user !== null ? (string) ($user['theme_preference'] ?? '') : '';

if ($themeErrors !== []) {
    $selectedThemeSlug = trim((string) ($_POST['theme'] ?? ''));
}

$selectedThemeName = '';

if ($selectedThemeSlug !== '' && isset($themes[$selectedThemeSlug])) {
    $selectedThemeName = (string) ($themes[$selectedThemeSlug]['name'] ?? ucfirst($selectedThemeSlug));
}

$defaultThemeLabel = 'site default theme';
$themeStatusMessage = $selectedThemeSlug === ''
    ? 'Using the site default theme.'
    : 'Using the "' . ($selectedThemeName !== '' ? $selectedThemeName : ucfirst($selectedThemeSlug)) . '" theme.';

$accountStatus = [
    'linked' => false,
    'name' => '',
];
$accountHasRecoveryKey = false;

if ($user !== null) {
    $accountId = isset($user['account_id']) ? (int) $user['account_id'] : 0;

    if ($accountId > 0) {
        $pdo = db();
        $accountHasRecoveryKey = nx_account_has_recovery_key($pdo, $accountId);
        $accountRow = nx_fetch_account_by_id($pdo, $accountId);

        if ($accountRow !== null) {
            $accountStatus['linked'] = true;
            $accountStatus['name'] = (string) ($accountRow[TFS_NAME_COL] ?? '');
        }
    }

    if ($showRecoveryKeyModal) {
        $accountHasRecoveryKey = true;
    }

    if ($accountStatus['name'] === '') {
        $fallbackName = (string) ($user['account_name'] ?? '');

        if ($fallbackName !== '') {
            $accountStatus['name'] = $fallbackName;
        }
    }
}
?>
<div class="container-page">
    <div class="card nx-card nx-glow">
        <div class="card-body p-4 p-lg-5">
            <section class="page page--account">
                <h4 class="mb-3">Account</h4>

                <?php if ($errorMessage): ?>
                    <div class="alert alert--error"><?php echo sanitize($errorMessage); ?></div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="alert alert--success"><?php echo sanitize($successMessage); ?></div>
                <?php endif; ?>

                <?php if (!$user): ?>
                    <div class="account-forms" id="login">
                        <form class="account-form" method="post" action="?p=account">
                            <h5 class="mb-3 text-uppercase text-muted" style="letter-spacing:.08em">Login</h5>

                            <?php if ($loginErrors): ?>
                                <ul class="form-errors">
                                    <?php foreach ($loginErrors as $error): ?>
                                        <li><?php echo sanitize($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <input type="hidden" name="action" value="login">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">

                            <div class="form-group">
                                <label class="form-label text-reset" for="login-identifier">Email or Account Name</label>
                                <input type="text" id="login-identifier" name="identifier" value="<?php echo sanitize($loginIdentifier); ?>" required autocomplete="username">
                            </div>

                            <div class="form-group">
                                <label class="form-label text-reset" for="login-password">Password</label>
                                <input type="password" id="login-password" name="password" required autocomplete="current-password">
                            </div>
                            <p class="form-help"><a href="?p=recover">Forgot password? Use Recovery Key</a></p>

                            <div class="form-actions">
                                <button type="submit">Login</button>
                            </div>
                        </form>

                        <form class="account-form" method="post" action="?p=account" id="register">
                            <h5 class="mb-3 text-uppercase text-muted" style="letter-spacing:.08em">Register</h5>

                            <?php if ($registerErrors): ?>
                                <ul class="form-errors">
                                    <?php foreach ($registerErrors as $error): ?>
                                        <li><?php echo sanitize($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <input type="hidden" name="action" value="register">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">

                            <div class="form-group">
                                <label class="form-label text-reset" for="register-account-name">Game Account Name</label>
                                <input
                                    type="text"
                                    id="register-account-name"
                                    name="account_name"
                                    value="<?php echo sanitize($registerAccountName); ?>"
                                    required
                                    pattern="[A-Za-z0-9]{3,20}"
                                    autocomplete="username"
                                >
                            </div>

                            <div class="form-group">
                                <label class="form-label text-reset" for="register-email">Email</label>
                                <input type="email" id="register-email" name="email" value="<?php echo sanitize($registerEmail); ?>" required autocomplete="email">
                            </div>

                            <div class="form-group">
                                <label class="form-label text-reset" for="register-password">Password</label>
                                <input type="password" id="register-password" name="password" required autocomplete="new-password">
                            </div>

                            <div class="form-group">
                                <label class="form-label text-reset" for="register-confirm">Confirm Password</label>
                                <input type="password" id="register-confirm" name="confirm_password" required autocomplete="new-password">
                            </div>

                            <div class="form-actions">
                                <button type="submit">Create Account</button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <?php if ($showRecoveryKeyModal && $generatedRecoveryKey !== null): ?>
                        <div class="alert alert--info alert--dismissible" role="alert">
                            <button type="button" class="alert__close" aria-label="Dismiss" onclick="this.closest('.alert').remove();">&times;</button>
                            Store this somewhere safe. If you lose access to your email or password, this key is your only way back.
                        </div>
                        <div class="account-recovery-modal" role="alertdialog" aria-labelledby="recovery-modal-title" aria-describedby="recovery-modal-body">
                            <h4 class="mb-2">Save Your Recovery Key</h4>
                            <p id="recovery-modal-body">This key is shown only once. Store it safely—we only keep a secure hash on the server.</p>
                            <div class="account-recovery-modal__key"><code><?php echo sanitize($generatedRecoveryKey); ?></code></div>
                            <form class="account-recovery-modal__confirm" method="get" action="?p=account">
                                <div class="form-group">
                                    <input type="checkbox" id="recovery-modal-confirm" name="ack" value="1" required>
                                    <label class="form-label text-reset" for="recovery-modal-confirm">I have stored this safely</label>
                                </div>
                                <div class="form-actions">
                                    <button type="submit">OK</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                    <div class="account-status" role="status">
                        <h5 class="mt-3 mb-2 text-uppercase text-muted" style="letter-spacing:.08em">Game Account Status</h5>
                        <p class="mb-2">
                            <span class="fw-semibold text-reset">Account name:</span>
                            <?php if ($accountStatus['name'] !== ''): ?>
                                <?php echo sanitize($accountStatus['name']); ?>
                            <?php else: ?>
                                <em>Not set</em>
                            <?php endif; ?>
                        </p>
                        <p class="mb-0">
                            <span class="fw-semibold text-reset">Linked:</span>
                            <?php echo $accountStatus['linked'] ? 'Yes' : 'No'; ?>
                        </p>

                        <?php if (!$accountStatus['linked']): ?>
                            <form class="account-status__link-form mt-3" method="post" action="?p=account">
                                <h6 class="mb-2 text-uppercase text-muted" style="letter-spacing:.08em">Link Now</h6>

                                <?php if ($linkErrors): ?>
                                    <ul class="form-errors">
                                        <?php foreach ($linkErrors as $error): ?>
                                            <li><?php echo sanitize($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>

                                <input type="hidden" name="action" value="link_account">
                                <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">

                                <div class="account-status__field">
                                    <label class="form-label text-reset" for="link-account-name-mini">Game Account Name</label>
                                    <input
                                        type="text"
                                        id="link-account-name-mini"
                                        name="account_name"
                                        required
                                        pattern="[A-Za-z0-9]{3,20}"
                                        autocomplete="username"
                                    >
                                </div>

                                <div class="account-status__field">
                                    <label class="form-label text-reset" for="link-account-password-mini">Game Account Password</label>
                                    <input
                                        type="password"
                                        id="link-account-password-mini"
                                        name="account_password"
                                        required
                                        autocomplete="current-password"
                                    >
                                </div>

                                <div class="account-status__actions">
                                    <button type="submit">Link now</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="account-profile">
                        <h5 class="mt-3 mb-2 text-uppercase text-muted" style="letter-spacing:.08em">Your Profile</h5>
                        <dl class="mb-0 text-reset">
                            <dt>Email</dt>
                            <dd>
                                <?php if ($user['email'] !== null && $user['email'] !== ''): ?>
                                    <?php echo sanitize((string) $user['email']); ?>
                                <?php else: ?>
                                    <em>Not set</em>
                                <?php endif; ?>
                            </dd>
                            <dt>Role</dt>
                            <dd><?php echo sanitize($user['role']); ?></dd>
                            <dt>Game Account</dt>
                            <dd>
                                <?php if ($accountStatus['linked']): ?>
                                    <?php echo sanitize($accountStatus['name']); ?>
                                <?php elseif ($accountStatus['name'] !== ''): ?>
                                    <?php echo sanitize($accountStatus['name']); ?>
                                    <span class="account-profile__status-note">(link pending)</span>
                                <?php else: ?>
                                    <em>Not linked</em>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>

                    <div class="account-recovery">
                        <h5 class="mt-3 mb-2 text-uppercase text-muted" style="letter-spacing:.08em">Account Recovery Key</h5>
                        <p>Generate a recovery key to regain access if you ever lose your password. The key is displayed only once&mdash;store it securely.</p>

                        <?php if ($recoveryErrors): ?>
                            <ul class="form-errors">
                                <?php foreach ($recoveryErrors as $error): ?>
                                    <li><?php echo sanitize($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if (!$accountHasRecoveryKey): ?>
                            <form class="account-recovery__form" method="post" action="?p=account">
                                <input type="hidden" name="action" value="generate_recovery_key">
                                <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">
                                <p class="form-help">You will only see the recovery key once.</p>
                                <div class="form-actions">
                                    <button type="submit" class="account-recovery__button">Generate Recovery Key</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p>Your account already has a recovery key on file. Keep it stored safely.</p>
                        <?php endif; ?>
                    </div>

                    <form class="account-form" method="post" action="?p=account">
                        <h5 class="mt-3 mb-2 text-uppercase text-muted" style="letter-spacing:.08em">Change Password</h5>

                        <?php if ($passwordErrors): ?>
                            <ul class="form-errors">
                                <?php foreach ($passwordErrors as $error): ?>
                                    <li><?php echo sanitize($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <input type="hidden" name="action" value="password">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">

                        <div class="form-group">
                            <label class="form-label text-reset" for="current-password">Current Password</label>
                            <input type="password" id="current-password" name="current_password" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label text-reset" for="new-password">New Password</label>
                            <input type="password" id="new-password" name="new_password" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label text-reset" for="confirm-password">Confirm New Password</label>
                            <input type="password" id="confirm-password" name="confirm_password" required>
                        </div>

                        <div class="form-actions">
                            <button type="submit">Update Password</button>
                        </div>
                    </form>

                    <div class="account-theme-placeholder">
                        <h5 class="mt-3 mb-2 text-uppercase text-muted" style="letter-spacing:.08em">Theme Preference</h5>
                        <form class="account-form" method="post" action="?p=account">
                            <input type="hidden" name="action" value="theme">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize($csrfToken); ?>">

                            <?php if ($themeErrors): ?>
                                <ul class="form-errors">
                                    <?php foreach ($themeErrors as $error): ?>
                                        <li><?php echo sanitize($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <div class="form-group">
                                <label class="form-label text-reset" for="theme-preference">Preferred Theme</label>
                                <select
                                    id="theme-preference"
                                    name="theme"
                                    data-theme-select
                                    data-theme-default-label="<?php echo sanitize($defaultThemeLabel); ?>"
                                >
                                    <option value="" data-theme-label="<?php echo sanitize(ucfirst($defaultThemeLabel)); ?>"<?php echo $selectedThemeSlug === '' ? ' selected' : ''; ?>>Use site default</option>
                                    <?php foreach ($themes as $slug => $theme): ?>
                                        <?php $displayName = (string) ($theme['name'] ?? $slug); ?>
                                        <option value="<?php echo sanitize($slug); ?>" data-theme-label="<?php echo sanitize($displayName); ?>"<?php echo $selectedThemeSlug === $slug ? ' selected' : ''; ?>>
                                            <?php echo sanitize($displayName . ' (' . $slug . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-help" data-theme-select-message><?php echo sanitize($themeStatusMessage); ?></p>
                            </div>

                            <div class="form-actions">
                                <button type="submit">Save Preference</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
