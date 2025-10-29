<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Server Settings';
$adminNavActive = 'server';

require __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/server_paths.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="admin-section"><h2>Server Settings</h2><div class="admin-alert admin-alert--error">Database connection unavailable.</div></section>';
    require __DIR__ . '/partials/footer.php';

    return;
}
$currentAdmin = current_user();
$actorIsMaster = $currentAdmin !== null && is_master($currentAdmin);
$storedPath = trim((string) (get_setting('server_path') ?? ''));
$currentPath = $storedPath !== '' ? $storedPath : SERVER_PATH;

if ($currentPath !== '' && is_dir($currentPath)) {
    $real = realpath($currentPath);
    if ($real !== false) {
        $currentPath = $real;
    }
}

$errors = [];
$successMessage = take_flash('success');
$errorMessage = take_flash('error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? null;

    if (!csrf_validate($token)) {
        $errors[] = 'The request could not be validated. Please try again.';
    } elseif ($action === 'update_server_path') {
        $inputPath = trim((string) ($_POST['server_path'] ?? ''));

        if ($inputPath === '') {
            $errors[] = 'Please provide the absolute path to your TFS server.';
        } else {
            $candidate = $inputPath;
            if (is_dir($candidate)) {
                $real = realpath($candidate);
                if ($real !== false) {
                    $candidate = $real;
                }
            }

            if (!is_dir($candidate)) {
                $errors[] = sprintf('The directory "%s" could not be found.', $inputPath);
            } elseif (!is_file($candidate . DIRECTORY_SEPARATOR . 'config.lua')) {
                $errors[] = sprintf('config.lua was not found inside "%s".', $candidate);
            } else {
                $upsert = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)');
                $upsert->execute([
                    'key' => 'server_path',
                    'value' => $candidate,
                ]);

                if (function_exists('audit_log')) {
                    $before = $storedPath === '' ? null : ['server_path' => $storedPath];
                    $after = [
                        'server_path' => $candidate,
                        'a_is_master' => $actorIsMaster ? 1 : 0,
                    ];
                    audit_log($currentAdmin['id'] ?? null, 'update_server_path', $before, $after);
                }

                $storedPath = $candidate;
                $currentPath = $candidate;
                $successMessage = 'Server path updated successfully.';
            }
        }
    }
}

$resolvedPaths = nx_server_paths();
$checklist = [
    ['label' => 'Server Root', 'path' => $resolvedPaths['server_root'] ?? '', 'exists' => isset($resolvedPaths['server_root']) && is_dir($resolvedPaths['server_root'])],
    ['label' => 'config.lua', 'path' => $resolvedPaths['config_lua'] ?? '', 'exists' => isset($resolvedPaths['config_lua']) && is_file($resolvedPaths['config_lua'])],
    ['label' => 'data', 'path' => $resolvedPaths['data'] ?? '', 'exists' => isset($resolvedPaths['data']) && is_dir($resolvedPaths['data'])],
    ['label' => 'Monsters', 'path' => $resolvedPaths['monsters'] ?? '', 'exists' => isset($resolvedPaths['monsters']) && is_dir($resolvedPaths['monsters'])],
    ['label' => 'Items XML', 'path' => $resolvedPaths['items_xml'] ?? '', 'exists' => isset($resolvedPaths['items_xml']) && is_file($resolvedPaths['items_xml'])],
    ['label' => 'Spells', 'path' => $resolvedPaths['spells'] ?? '', 'exists' => isset($resolvedPaths['spells']) && is_dir($resolvedPaths['spells'])],
];

if (isset($resolvedPaths['quests'])) {
    $checklist[] = ['label' => 'Quests', 'path' => $resolvedPaths['quests'], 'exists' => is_dir($resolvedPaths['quests'])];
}
?>
<section class="admin-section">
    <h2>Server Path</h2>
    <p>Configure where The Nexus should look for <code>config.lua</code>, monsters, items, and other server data.</p>

    <?php if ($errorMessage): ?>
        <div class="admin-alert admin-alert--error"><?php echo sanitize($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($successMessage): ?>
        <div class="admin-alert admin-alert--success"><?php echo sanitize($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="admin-alert admin-alert--error">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?php echo sanitize($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
        <input type="hidden" name="action" value="update_server_path">
        <div class="admin-form__group">
            <label for="server-path">Server Root Path</label>
            <input
                type="text"
                id="server-path"
                name="server_path"
                value="<?php echo sanitize($currentPath); ?>"
                placeholder="C:/xampp/htdocs"
            >
        </div>
        <button type="submit" class="admin-button">Save Server Path</button>
    </form>
</section>

<section class="admin-section">
    <h2>Resolved Directories</h2>
    <p>The following paths are detected based on the configured server root.</p>
    <table class="admin-table">
        <thead>
            <tr>
                <th>Resource</th>
                <th>Path</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($checklist as $row): ?>
                <tr>
                    <td><?php echo sanitize($row['label']); ?></td>
                    <td><code><?php echo sanitize($row['path']); ?></code></td>
                    <td>
                        <?php if ($row['exists']): ?>
                            <span class="admin-status admin-status--ok">&#10003; Found</span>
                        <?php else: ?>
                            <span class="admin-status admin-status--error">&#10007; Missing</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="admin-section">
    <h2>Indexers</h2>
    <p>Re-scan XML data sources to refresh the Bestiary and Item Shop references.</p>
    <div class="admin-actions">
        <form method="post" action="indexers.php?action=reindex_items">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="return_to" value="settings_server.php">
            <button type="submit" class="admin-button">Re-index Items</button>
        </form>
        <form method="post" action="indexers.php?action=reindex_all">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="return_to" value="settings_server.php">
            <button type="submit" class="admin-button">Re-index Bestiary &amp; Items</button>
        </form>
        <form method="post" action="indexers.php?action=reindex_spells">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="return_to" value="settings_server.php">
            <button type="submit" class="admin-button">Re-index Spells</button>
        </form>
    </div>
</section>
<?php
require __DIR__ . '/partials/footer.php';
