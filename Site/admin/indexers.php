<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Indexers';
$adminNavActive = 'server';

require __DIR__ . '/partials/header.php';
require_once __DIR__ . '/../lib/items_indexer.php';
require_once __DIR__ . '/../lib/monster_indexer.php';
require_once __DIR__ . '/../lib/spell_indexer.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="admin-section"><h2>Indexers</h2><div class="admin-alert admin-alert--error">Database connection unavailable.</div></section>';
    require __DIR__ . '/partials/footer.php';

    return;
}
$action = $_GET['action'] ?? '';
$returnTo = $_POST['return_to'] ?? $_GET['return_to'] ?? null;

if ($returnTo !== null) {
    $returnTo = trim((string) $returnTo);
    if ($returnTo === '' || !preg_match('#^[a-z0-9_./?&=-]+$#i', $returnTo)) {
        $returnTo = null;
    }
}

if ($returnTo === null) {
    $returnTo = 'indexers.php';
}

if ($action !== '') {
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_validate($token)) {
        flash('error', 'The request could not be validated.');
        redirect($returnTo);
    }

    try {
        switch ($action) {
            case 'reindex_items':
                $result = nx_index_items($pdo);
                flash('success', sprintf('Items re-indexed. %d entries updated.', $result['count'] ?? 0));
                break;
            case 'reindex_monsters':
                $result = nx_index_monsters($pdo);
                flash('success', sprintf('Monsters re-indexed. %d entries updated.', $result['monsters'] ?? 0));
                break;
            case 'reindex_spells':
                $result = nx_index_spells($pdo);
                flash('success', sprintf('Spells re-indexed. %d entries updated.', $result['count'] ?? 0));
                break;
            case 'reindex_all':
                $itemsResult = nx_index_items($pdo);
                $monsterResult = nx_index_monsters($pdo);
                $spellResult = null;
                try {
                    $spellResult = nx_index_spells($pdo);
                } catch (Throwable $exception) {
                    flash('error', 'Spells indexer error: ' . $exception->getMessage());
                }
                flash('success', sprintf(
                    'Items (%d), monsters (%d)%s re-indexed successfully.',
                    $itemsResult['count'] ?? 0,
                    $monsterResult['monsters'] ?? 0,
                    $spellResult !== null ? sprintf(', spells (%d)', $spellResult['count'] ?? 0) : ''
                ));
                break;
            default:
                flash('error', 'Unknown indexer action requested.');
                break;
        }
    } catch (Throwable $exception) {
        flash('error', 'Indexer error: ' . $exception->getMessage());
    }

    redirect($returnTo);
}

$successMessage = take_flash('success');
$errorMessage = take_flash('error');

$logsStmt = $pdo->query('SELECT kind, status, message, ts FROM index_scan_log ORDER BY ts DESC LIMIT 20');
$logs = $logsStmt->fetchAll();
?>
<section class="admin-section">
    <h2>Indexers</h2>
    <p>Run manual re-index tasks for items and monsters. These routines populate the Bestiary, item picker, and shop metadata.</p>
    <div class="admin-actions">
        <form method="post" action="indexers.php?action=reindex_items">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="return_to" value="indexers.php">
            <button type="submit" class="admin-button">Re-index Items</button>
        </form>
        <form method="post" action="indexers.php?action=reindex_monsters">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="return_to" value="indexers.php">
            <button type="submit" class="admin-button">Re-index Monsters</button>
        </form>
        <form method="post" action="indexers.php?action=reindex_spells">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="return_to" value="indexers.php">
            <button type="submit" class="admin-button">Re-index Spells</button>
        </form>
        <form method="post" action="indexers.php?action=reindex_all">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize(csrf_token()); ?>">
            <input type="hidden" name="return_to" value="indexers.php">
            <button type="submit" class="admin-button">Re-index All</button>
        </form>
    </div>
</section>

<?php if ($successMessage): ?>
    <div class="admin-alert admin-alert--success"><?php echo sanitize($successMessage); ?></div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="admin-alert admin-alert--error"><?php echo sanitize($errorMessage); ?></div>
<?php endif; ?>

<section class="admin-section">
    <h2>Recent Scan Logs</h2>
    <?php if ($logs === []): ?>
        <p>No scan activity recorded yet.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Kind</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo sanitize($log['ts']); ?></td>
                        <td><?php echo sanitize($log['kind']); ?></td>
                        <td>
                            <span class="admin-status <?php echo $log['status'] === 'ok' ? 'admin-status--ok' : 'admin-status--error'; ?>">
                                <?php echo sanitize(strtoupper($log['status'])); ?>
                            </span>
                        </td>
                        <td><?php echo sanitize($log['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php
require __DIR__ . '/partials/footer.php';
