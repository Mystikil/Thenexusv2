<?php
declare(strict_types=1);

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--monster"><h2>Monster</h2><p class="text-muted mb-0">Unavailable.</p></section>';

    return;
}
$monsterId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($monsterId <= 0) {
    echo '<section class="page"><p>Monster not found.</p></section>';
    return;
}

$stmt = $pdo->prepare('SELECT * FROM monster_index WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $monsterId]);
$monster = $stmt->fetch();

if ($monster === false) {
    echo '<section class="page"><p>Monster not found.</p></section>';
    return;
}

$elemental = [];
if (isset($monster['elemental']) && $monster['elemental'] !== null) {
    $decoded = json_decode((string) $monster['elemental'], true);
    if (is_array($decoded)) {
        $elemental = $decoded;
    }
}

$immunities = [];
if (isset($monster['immunities']) && $monster['immunities'] !== null) {
    $decoded = json_decode((string) $monster['immunities'], true);
    if (is_array($decoded)) {
        $immunities = $decoded;
    }
}

$flags = [];
if (isset($monster['flags']) && $monster['flags'] !== null) {
    $decoded = json_decode((string) $monster['flags'], true);
    if (is_array($decoded)) {
        $flags = $decoded;
    }
}

$outfit = [];
if (isset($monster['outfit']) && $monster['outfit'] !== null) {
    $decoded = json_decode((string) $monster['outfit'], true);
    if (is_array($decoded)) {
        $outfit = $decoded;
    }
}

$lootStmt = $pdo->prepare('SELECT ml.*, i.name AS index_name FROM monster_loot ml
    LEFT JOIN item_index i ON i.id = ml.item_id
    WHERE ml.monster_id = :id
    ORDER BY ml.chance DESC, COALESCE(i.name, ml.item_name) ASC');
$lootStmt->execute(['id' => $monsterId]);
$loot = $lootStmt->fetchAll();

$related = [];
if (!empty($monster['race'])) {
    $relatedStmt = $pdo->prepare('SELECT id, name FROM monster_index WHERE race = :race AND id != :id ORDER BY experience DESC, name ASC LIMIT 6');
    $relatedStmt->execute([
        'race' => $monster['race'],
        'id' => $monsterId,
    ]);
    $related = $relatedStmt->fetchAll();
}
$location = isset($monster['location']) ? trim((string) $monster['location']) : '';
$strategy = isset($monster['strategy']) ? trim((string) $monster['strategy']) : '';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-shield-shaded me-2"></i><?php echo sanitize($monster['name']); ?></h4>
    <?php if (!empty($monster['race'])): ?>
        <span class="badge bg-primary-subtle text-primary-emphasis px-3 py-2">Race: <?php echo sanitize($monster['race']); ?></span>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4">
        <div class="card nx-glow h-100">
            <div class="card-body">
                <h6 class="mb-3 text-uppercase text-muted small">Core Stats</h6>
                <dl class="row mb-0">
                    <dt class="col-6">Experience</dt><dd class="col-6 text-end fw-semibold"><?php echo number_format((int) $monster['experience']); ?></dd>
                    <dt class="col-6">Health</dt><dd class="col-6 text-end"><?php echo number_format((int) $monster['health']); ?></dd>
                    <dt class="col-6">Speed</dt><dd class="col-6 text-end"><?php echo number_format((int) $monster['speed']); ?></dd>
                    <dt class="col-6">Summonable</dt><dd class="col-6 text-end"><?php echo ((int) $monster['summonable']) === 1 ? 'Yes' : 'No'; ?></dd>
                    <dt class="col-6">Convinceable</dt><dd class="col-6 text-end"><?php echo ((int) $monster['convinceable']) === 1 ? 'Yes' : 'No'; ?></dd>
                    <dt class="col-6">Illusionable</dt><dd class="col-6 text-end"><?php echo ((int) $monster['illusionable']) === 1 ? 'Yes' : 'No'; ?></dd>
                </dl>
                <?php if ($outfit !== []): ?>
                    <hr>
                    <div class="text-muted small">
                        <span class="fw-semibold text-light">Outfit</span>
                        <div class="mt-1">
                            <?php
                                $parts = [];
                                foreach ($outfit as $key => $value) {
                                    $parts[] = sanitize($key . ': ' . $value);
                                }
                                echo implode('<br>', $parts);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card nx-glow h-100">
            <div class="card-body">
                <h6 class="mb-3 text-uppercase text-muted small">Elemental Profile</h6>
                <?php if ($elemental === []): ?>
                    <p class="text-muted mb-0">No elemental data available.</p>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php foreach ($elemental as $element => $value): ?>
                            <?php $valueInt = (int) $value; ?>
                            <?php
                                $badgeClass = 'bg-secondary-subtle text-secondary-emphasis';
                                if ($valueInt >= 100) {
                                    $badgeClass = 'bg-success-subtle text-success-emphasis';
                                } elseif ($valueInt > 0) {
                                    $badgeClass = 'bg-warning-subtle text-warning-emphasis';
                                } elseif ($valueInt < 0) {
                                    $badgeClass = 'bg-danger-subtle text-danger-emphasis';
                                }
                            ?>
                            <span class="badge <?php echo $badgeClass; ?> px-3 py-2"><?php echo sanitize(ucfirst($element)); ?> <?php echo $valueInt; ?>%</span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($immunities !== [] || $flags !== []): ?>
                    <h6 class="mt-3 mb-2 text-uppercase text-muted small">Immunities & Traits</h6>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($immunities as $key => $value): ?>
                            <li><i class="bi bi-shield-check me-1 text-success"></i><?php echo sanitize(ucwords($key) . ': ' . (is_bool($value) ? ($value ? 'Yes' : 'No') : $value)); ?></li>
                        <?php endforeach; ?>
                        <?php foreach ($flags as $key => $value): ?>
                            <li><i class="bi bi-flag me-1 text-primary"></i><?php echo sanitize(ucwords($key) . ': ' . (is_bool($value) ? ($value ? 'Yes' : 'No') : $value)); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-4">
        <div class="card nx-glow h-100">
            <div class="card-body">
                <h6 class="mb-3 text-uppercase text-muted small">Quick Facts</h6>
                <ul class="list-unstyled mb-3 small">
                    <li><i class="bi bi-geo-fill me-1 text-warning"></i>Spawn: <?php echo $location !== '' ? sanitize($location) : 'Unknown'; ?></li>
                    <li><i class="bi bi-emoji-smile me-1 text-info"></i>Strategy: <?php echo $strategy !== '' ? sanitize($strategy) : 'Unrecorded'; ?></li>
                </ul>
                <?php if ($related !== []): ?>
                    <h6 class="mb-2 text-uppercase text-muted small">Related Monsters</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($related as $rel): ?>
                            <a class="badge bg-primary-subtle text-primary-emphasis text-decoration-none" href="?p=monster&amp;id=<?php echo (int) $rel['id']; ?>"><?php echo sanitize($rel['name']); ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card nx-glow mt-3">
    <div class="card-body">
        <h6 class="mb-3 text-uppercase text-muted small">Loot Table</h6>
        <?php if ($loot === []): ?>
            <p class="text-muted mb-0">No loot data recorded.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-striped table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-end">Chance</th>
                            <th class="text-end">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loot as $entry): ?>
                            <?php
                                $chance = $entry['chance'] !== null ? (int) $entry['chance'] : null;
                                $chanceDisplay = $chance === null ? '—' : sprintf('%.1f%%', $chance / 1000);
                                $countMin = (int) ($entry['count_min'] ?? 1);
                                $countMax = (int) ($entry['count_max'] ?? 1);
                                $quantity = $countMin === $countMax ? $countMin : $countMin . ' - ' . $countMax;
                                $itemName = $entry['index_name'] ?? $entry['item_name'] ?? 'Unknown Item';
                            ?>
                            <tr>
                                <td><?php echo sanitize($itemName); ?><?php if (!empty($entry['item_id'])): ?> <span class="text-muted small">(ID <?php echo (int) $entry['item_id']; ?>)</span><?php endif; ?></td>
                                <td class="text-end"><?php echo sanitize($chanceDisplay); ?></td>
                                <td class="text-end"><?php echo sanitize($quantity); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
