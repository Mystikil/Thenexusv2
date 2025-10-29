<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Settings';
$adminNavActive = 'settings';

require __DIR__ . '/partials/header.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="admin-section"><h2>Settings</h2><div class="admin-alert admin-alert--error">Database connection unavailable.</div></section>';
    require __DIR__ . '/partials/footer.php';

    return;
}

$settingsMap = [
    'site_title' => 'Site Title',
    'WEBHOOK_SECRET' => 'Webhook Secret',
    'BRIDGE_SECRET' => 'Bridge Secret',
    'recovery_rotate_on_use' => 'Rotate Recovery Key After Use',
    'recovery_allow_admin_view_plain' => 'Allow Admin Plain Recovery View',
];

$booleanSettings = ['recovery_rotate_on_use', 'recovery_allow_admin_view_plain'];

$currentSettings = [
    'site_title' => SITE_TITLE,
    'WEBHOOK_SECRET' => WEBHOOK_SECRET,
    'BRIDGE_SECRET' => BRIDGE_SECRET,
    'recovery_rotate_on_use' => 'true',
    'recovery_allow_admin_view_plain' => 'false',
];

$errors = [];
$successMessage = null;

$placeholders = implode(', ', array_fill(0, count($settingsMap), '?'));
$stmt = $pdo->prepare('SELECT `key`, value FROM settings WHERE `key` IN (' . $placeholders . ')');
$stmt->execute(array_keys($settingsMap));

while ($row = $stmt->fetch()) {
    $key = $row['key'];
    if (array_key_exists($key, $currentSettings)) {
        if (in_array($key, $booleanSettings, true)) {
            $currentSettings[$key] = in_array(strtolower(trim((string) $row['value'] ?? '')), ['1', 'true', 'yes', 'on'], true)
                ? 'true'
                : 'false';
        } else {
            $currentSettings[$key] = (string) $row['value'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $errors[] = 'The request could not be validated. Please try again.';
    } else {
        $upsert = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        $user = current_user();
        $userId = $user !== null ? (int) $user['id'] : null;
        $actorIsMaster = $user !== null && is_master($user);
        $changes = 0;

        foreach ($settingsMap as $key => $label) {
            $newValue = trim((string) ($_POST[$key] ?? ''));

            if (in_array($key, $booleanSettings, true)) {
                $normalized = strtolower($newValue);
                $newValue = in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 'true' : 'false';
            }
            $previousValue = $currentSettings[$key] ?? '';

            if ($newValue === $previousValue) {
                continue;
            }

            $upsert->execute([
                'key' => $key,
                'value' => $newValue,
            ]);

            audit_log($userId, 'update_setting', ['key' => $key, 'value' => $previousValue], [
                'key' => $key,
                'value' => $newValue,
                'a_is_master' => $actorIsMaster ? 1 : 0,
            ]);
            $currentSettings[$key] = $newValue;
            $changes++;
        }

        if ($changes > 0) {
            $successMessage = 'Settings updated successfully.';
        } else {
            $successMessage = 'No changes were detected.';
        }
    }
}
?>
<section class="admin-section">
    <h2>Settings</h2>
    <p>Update core site configuration values. Changes are logged for auditing.</p>

    <?php if ($errors !== []): ?>
        <div class="admin-alert admin-alert--error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo sanitize($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($successMessage !== null): ?>
        <div class="admin-alert admin-alert--success">
            <?php echo sanitize($successMessage); ?>
        </div>
    <?php endif; ?>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
        <?php foreach ($settingsMap as $key => $label): ?>
            <div class="admin-form__group">
                <label for="<?php echo sanitize($key); ?>"><?php echo sanitize($label); ?></label>
                <?php if (in_array($key, $booleanSettings, true)): ?>
                    <select id="<?php echo sanitize($key); ?>" name="<?php echo sanitize($key); ?>">
                        <option value="true"<?php echo ($currentSettings[$key] ?? '') === 'true' ? ' selected' : ''; ?>>Enabled</option>
                        <option value="false"<?php echo ($currentSettings[$key] ?? '') === 'false' ? ' selected' : ''; ?>>Disabled</option>
                    </select>
                <?php else: ?>
                    <input
                        type="text"
                        id="<?php echo sanitize($key); ?>"
                        name="<?php echo sanitize($key); ?>"
                        value="<?php echo sanitize($currentSettings[$key] ?? ''); ?>"
                    >
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="admin-button">Save Settings</button>
    </form>
</section>
<section class="admin-section">
    <h3>Theme Manager</h3>
    <p>Switch layouts, preview new themes, and configure theme options from a dedicated manager.</p>
    <a class="admin-button" href="<?php echo sanitize(base_url('admin/themes.php')); ?>">Open Theme Manager</a>
</section>
<?php
require __DIR__ . '/partials/footer.php';
