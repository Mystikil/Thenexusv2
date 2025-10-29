<?php
declare(strict_types=1);

require_once __DIR__ . '/../widgets/_cache.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--spells"><h2>Spell Library</h2><p class="text-muted mb-0">Unavailable.</p></section>';

    return;
}

$search = trim((string) ($_GET['search'] ?? ''));
$vocation = trim((string) ($_GET['vocation'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$sort = $_GET['sort'] ?? 'name_asc';
$levelMin = isset($_GET['level_min']) ? max(0, (int) $_GET['level_min']) : null;
$levelMax = isset($_GET['level_max']) ? max(0, (int) $_GET['level_max']) : null;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 24;

$validSorts = [
    'name_asc' => 'name ASC',
    'name_desc' => 'name DESC',
    'level_desc' => 'level DESC, name ASC',
    'mana_asc' => 'mana ASC, name ASC',
    'mana_desc' => 'mana DESC, name ASC',
    'cooldown_asc' => 'cooldown ASC, name ASC',
];

if (!array_key_exists($sort, $validSorts)) {
    $sort = 'name_asc';
}

$filtersForCache = [
    'search' => $search,
    'vocation' => $vocation,
    'type' => $type,
    'sort' => $sort,
    'level_min' => $levelMin,
    'level_max' => $levelMax,
    'page' => $page,
];

$cacheKey = cache_key('page:spells', $filtersForCache);
if ($cached = cache_get($cacheKey, 30)) {
    echo $cached;
    return;
}

ob_start();

$vocationRows = $pdo->query("SELECT vocations FROM spells_index WHERE vocations IS NOT NULL AND vocations <> ''")->fetchAll(PDO::FETCH_COLUMN);
$vocationOptions = [];
foreach ($vocationRows as $row) {
    $parts = array_filter(array_map('trim', explode(',', (string) $row)));
    foreach ($parts as $part) {
        $vocationOptions[$part] = true;
    }
}
ksort($vocationOptions);

$typeStmt = $pdo->query("SELECT DISTINCT type FROM spells_index WHERE type IS NOT NULL AND type <> '' ORDER BY type");
$typeOptions = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = '(name LIKE :search OR words LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

if ($vocation !== '') {
    $whereClauses[] = 'FIND_IN_SET(:vocation, vocations)';
    $params['vocation'] = $vocation;
}

if ($type !== '') {
    $whereClauses[] = 'type = :type';
    $params['type'] = $type;
}

if ($levelMin !== null && $levelMin > 0) {
    $whereClauses[] = 'level IS NOT NULL AND level >= :level_min';
    $params['level_min'] = $levelMin;
}

if ($levelMax !== null && $levelMax > 0) {
    $whereClauses[] = 'level IS NOT NULL AND level <= :level_max';
    $params['level_max'] = $levelMax;
}

$whereSql = $whereClauses !== [] ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM spells_index' . $whereSql);
$countStmt->execute($params);
$totalResults = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalResults / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$dataSql = 'SELECT id, file_path, name, words, level, mana, cooldown, vocations, type, attributes'
    . ' FROM spells_index'
    . $whereSql
    . ' ORDER BY ' . $validSorts[$sort]
    . ' LIMIT :limit OFFSET :offset';

$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $value) {
    $dataStmt->bindValue(':' . $key, $value);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$spells = $dataStmt->fetchAll();

$baseQuery = [
    'p' => 'spells',
    'search' => $search,
    'vocation' => $vocation,
    'type' => $type,
    'sort' => $sort,
    'level_min' => $levelMin,
    'level_max' => $levelMax,
];
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-magic me-2"></i>Spell Library</h4>
    <div class="text-muted small">Catalog of all server spells</div>
</div>

<form method="get" class="row g-2 mb-3">
    <input type="hidden" name="p" value="spells">
    <div class="col-12 col-md-3">
        <input class="form-control" name="search" placeholder="Name or words" value="<?php echo sanitize($search); ?>">
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select" name="vocation">
            <option value="">All vocations</option>
            <?php foreach (array_keys($vocationOptions) as $vocationOption): ?>
                <option value="<?php echo sanitize($vocationOption); ?>"<?php echo $vocationOption === $vocation ? ' selected' : ''; ?>><?php echo sanitize($vocationOption); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select" name="type">
            <option value="">All types</option>
            <?php foreach ($typeOptions as $typeOption): ?>
                <option value="<?php echo sanitize($typeOption); ?>"<?php echo $typeOption === $type ? ' selected' : ''; ?>><?php echo sanitize(ucfirst($typeOption)); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <input class="form-control" type="number" name="level_min" min="0" placeholder="Level min" value="<?php echo $levelMin !== null ? $levelMin : ''; ?>">
    </div>
    <div class="col-6 col-md-2">
        <input class="form-control" type="number" name="level_max" min="0" placeholder="Level max" value="<?php echo $levelMax !== null ? $levelMax : ''; ?>">
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select" name="sort">
            <option value="name_asc"<?php echo $sort === 'name_asc' ? ' selected' : ''; ?>>Name A-Z</option>
            <option value="name_desc"<?php echo $sort === 'name_desc' ? ' selected' : ''; ?>>Name Z-A</option>
            <option value="level_desc"<?php echo $sort === 'level_desc' ? ' selected' : ''; ?>>Level (desc)</option>
            <option value="mana_asc"<?php echo $sort === 'mana_asc' ? ' selected' : ''; ?>>Mana (asc)</option>
            <option value="mana_desc"<?php echo $sort === 'mana_desc' ? ' selected' : ''; ?>>Mana (desc)</option>
            <option value="cooldown_asc"<?php echo $sort === 'cooldown_asc' ? ' selected' : ''; ?>>Cooldown (asc)</option>
        </select>
    </div>
    <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
    </div>
</form>

<?php if ($spells === []): ?>
    <div class="alert alert-warning">No spells matched your filters.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-dark table-striped table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Spell</th>
                    <th>Words</th>
                    <th class="text-end">Level</th>
                    <th class="text-end">Mana</th>
                    <th class="text-end">Cooldown</th>
                    <th>Vocations</th>
                    <th>Type</th>
                    <th>Attributes</th>
                    <th class="text-end">Script</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($spells as $spell): ?>
                    <?php
                        $attributes = [];
                        if (isset($spell['attributes']) && $spell['attributes'] !== null) {
                            $decoded = json_decode((string) $spell['attributes'], true);
                            if (is_array($decoded)) {
                                $attributes = $decoded;
                            }
                        }
                        $vocationsList = [];
                        if (!empty($spell['vocations'])) {
                            $vocationsList = array_filter(array_map('trim', explode(',', (string) $spell['vocations'])));
                        }
                    ?>
                    <tr>
                        <td class="fw-semibold text-light"><?php echo sanitize($spell['name']); ?></td>
                        <td><span class="text-muted small"><?php echo !empty($spell['words']) ? sanitize($spell['words']) : '—'; ?></span></td>
                        <td class="text-end"><?php echo $spell['level'] !== null ? (int) $spell['level'] : '—'; ?></td>
                        <td class="text-end"><?php echo $spell['mana'] !== null ? number_format((int) $spell['mana']) : '—'; ?></td>
                        <td class="text-end"><?php echo $spell['cooldown'] !== null ? (int) $spell['cooldown'] . 's' : '—'; ?></td>
                        <td>
                            <?php if ($vocationsList === []): ?>
                                <span class="text-muted">All</span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($vocationsList as $voc): ?>
                                        <span class="badge bg-primary-subtle text-primary-emphasis"><?php echo sanitize($voc); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($spell['type'])): ?>
                                <span class="badge bg-secondary-subtle text-secondary-emphasis"><?php echo sanitize(ucfirst((string) $spell['type'])); ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($attributes === []): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <ul class="list-unstyled mb-0 small">
                                    <?php foreach ($attributes as $key => $value): ?>
                                        <li><?php echo sanitize(ucwords((string) $key) . ': ' . (is_array($value) ? json_encode($value) : (string) $value)); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if (!empty($spell['file_path'])): ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize($spell['file_path']); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i></a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="Spells pagination">
            <ul class="pagination pagination-sm mb-0">
                <?php $prevQuery = http_build_query(array_merge($baseQuery, ['page' => max(1, $page - 1)])); ?>
                <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo sanitize($prevQuery); ?>" aria-label="Previous">&laquo;</a>
                </li>
                <li class="page-item disabled"><span class="page-link">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span></li>
                <?php $nextQuery = http_build_query(array_merge($baseQuery, ['page' => min($totalPages, $page + 1)])); ?>
                <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo sanitize($nextQuery); ?>" aria-label="Next">&raquo;</a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();
cache_set($cacheKey, $content);

echo $content;
