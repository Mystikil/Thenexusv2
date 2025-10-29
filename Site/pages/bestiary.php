<?php
declare(strict_types=1);

require_once __DIR__ . '/../widgets/_cache.php';

$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--bestiary"><h2>Bestiary</h2><p class="text-muted mb-0">Unavailable.</p></section>';

    return;
}

$search = trim((string) ($_GET['search'] ?? ''));
$race = trim((string) ($_GET['race'] ?? ''));
$elementRelation = $_GET['element_relation'] ?? '';
$elementType = $_GET['element_type'] ?? '';
$sort = $_GET['sort'] ?? 'name_asc';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 24;

$validSorts = [
    'name_asc' => 'name ASC',
    'exp_desc' => 'experience DESC',
    'health_desc' => 'health DESC',
    'speed_desc' => 'speed DESC',
];

if (!array_key_exists($sort, $validSorts)) {
    $sort = 'name_asc';
}

$elementTypes = ['physical', 'energy', 'earth', 'fire', 'ice', 'death', 'holy', 'drown'];
if (!in_array($elementType, $elementTypes, true)) {
    $elementType = '';
}

$elementRelations = ['strong', 'weak', 'immune', 'vulnerable'];
if (!in_array($elementRelation, $elementRelations, true)) {
    $elementRelation = '';
}

$filtersForCache = [
    'search' => $search,
    'race' => $race,
    'relation' => $elementRelation,
    'element' => $elementType,
    'sort' => $sort,
    'page' => $page,
];

$cacheKey = cache_key('page:bestiary', $filtersForCache);
if ($cached = cache_get($cacheKey, 30)) {
    echo $cached;
    return;
}

ob_start();

$racesStmt = $pdo->query("SELECT DISTINCT race FROM monster_index WHERE race IS NOT NULL AND race <> '' ORDER BY race");
$races = $racesStmt->fetchAll(PDO::FETCH_COLUMN);

$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = 'name LIKE :search';
    $params['search'] = '%' . $search . '%';
}

if ($race !== '') {
    $whereClauses[] = 'race = :race';
    $params['race'] = $race;
}

$elementExpr = null;
if ($elementType !== '' && $elementRelation !== '') {
    $elementExpr = sprintf("CAST(JSON_UNQUOTE(JSON_EXTRACT(elemental, '$.\"%s\"')) AS SIGNED)", $elementType);

    switch ($elementRelation) {
        case 'immune':
            $whereClauses[] = $elementExpr . ' >= 100';
            break;
        case 'strong':
            $whereClauses[] = $elementExpr . ' > 0';
            break;
        case 'weak':
            $whereClauses[] = $elementExpr . ' BETWEEN 1 AND 49';
            break;
        case 'vulnerable':
            $whereClauses[] = $elementExpr . ' < 0';
            break;
    }
}

$whereSql = $whereClauses !== [] ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM monster_index' . $whereSql);
$countStmt->execute($params);
$totalResults = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalResults / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$dataSql = 'SELECT id, name, race, experience, health, speed, elemental FROM monster_index'
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
$monsters = $dataStmt->fetchAll();

$baseQuery = [
    'p' => 'bestiary',
    'search' => $search,
    'race' => $race,
    'element_relation' => $elementRelation,
    'element_type' => $elementType,
    'sort' => $sort,
];
?>
<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-bookmark-star me-2"></i>Bestiary</h4>
    <div class="text-muted small">Indexed from server data</div>
</div>

<form method="get" class="row g-2 mb-3">
    <input type="hidden" name="p" value="bestiary">
    <div class="col-12 col-md-4">
        <input class="form-control" name="search" placeholder="Search name" value="<?php echo sanitize($search); ?>">
    </div>
    <div class="col-6 col-md-3">
        <select class="form-select" name="race">
            <option value="">All races</option>
            <?php foreach ($races as $raceOption): ?>
                <option value="<?php echo sanitize($raceOption); ?>"<?php echo $raceOption === $race ? ' selected' : ''; ?>><?php echo sanitize(ucwords($raceOption)); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <select class="form-select" name="sort">
            <option value="name_asc"<?php echo $sort === 'name_asc' ? ' selected' : ''; ?>>Name A-Z</option>
            <option value="exp_desc"<?php echo $sort === 'exp_desc' ? ' selected' : ''; ?>>Experience (desc)</option>
            <option value="health_desc"<?php echo $sort === 'health_desc' ? ' selected' : ''; ?>>Health (desc)</option>
            <option value="speed_desc"<?php echo $sort === 'speed_desc' ? ' selected' : ''; ?>>Speed (desc)</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <select class="form-select" name="element_relation">
            <option value="">Element focus</option>
            <option value="strong"<?php echo $elementRelation === 'strong' ? ' selected' : ''; ?>>Strong Against</option>
            <option value="weak"<?php echo $elementRelation === 'weak' ? ' selected' : ''; ?>>Resistant</option>
            <option value="immune"<?php echo $elementRelation === 'immune' ? ' selected' : ''; ?>>Immune</option>
            <option value="vulnerable"<?php echo $elementRelation === 'vulnerable' ? ' selected' : ''; ?>>Vulnerable</option>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <select class="form-select" name="element_type">
            <option value="">Any element</option>
            <?php foreach ($elementTypes as $type): ?>
                <option value="<?php echo sanitize($type); ?>"<?php echo $type === $elementType ? ' selected' : ''; ?>><?php echo sanitize(ucfirst($type)); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Filter</button>
    </div>
</form>

<?php if ($monsters === []): ?>
    <div class="alert alert-warning">No monsters matched your filters. Try adjusting the search or filters above.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-dark table-striped table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Race</th>
                    <th class="text-end">EXP</th>
                    <th class="text-end">HP</th>
                    <th class="text-end">Speed</th>
                    <th>Elemental</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monsters as $monster): ?>
                    <?php
                        $elemental = [];
                        if (isset($monster['elemental']) && $monster['elemental'] !== null) {
                            $decoded = json_decode((string) $monster['elemental'], true);
                            if (is_array($decoded)) {
                                $elemental = $decoded;
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <a class="text-decoration-none text-light fw-semibold" href="?p=monster&amp;id=<?php echo (int) $monster['id']; ?>">
                                <?php echo sanitize($monster['name']); ?>
                            </a>
                        </td>
                        <td>
                            <?php if (!empty($monster['race'])): ?>
                                <span class="badge bg-primary-subtle text-primary-emphasis"><?php echo sanitize(ucwords((string) $monster['race'])); ?></span>
                            <?php else: ?>
                                <span class="text-muted">Unknown</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?php echo number_format((int) $monster['experience']); ?></td>
                        <td class="text-end"><?php echo number_format((int) $monster['health']); ?></td>
                        <td class="text-end"><?php echo number_format((int) $monster['speed']); ?></td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($elemental as $type => $value): ?>
                                    <?php $valueInt = (int) $value; ?>
                                    <?php if ($valueInt === 0) { continue; } ?>
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
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo sanitize(ucfirst((string) $type)); ?> <?php echo $valueInt; ?>%</span>
                                <?php endforeach; ?>
                                <?php if ($elemental === []): ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="?p=monster&amp;id=<?php echo (int) $monster['id']; ?>">
                                Details
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3" aria-label="Bestiary pagination">
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
