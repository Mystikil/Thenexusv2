<?php
$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--highscores"><h2>Highscores</h2><p class="text-muted mb-0">Unavailable.</p></section>';

    return;
}

if (!function_exists('vocation_name')) {
    function vocation_name(int $vocationId): string
    {
        $vocations = [
            0 => 'None',
            1 => 'Sorcerer',
            2 => 'Druid',
            3 => 'Paladin',
            4 => 'Knight',
            5 => 'Master Sorcerer',
            6 => 'Elder Druid',
            7 => 'Royal Paladin',
            8 => 'Elite Knight',
        ];

        return $vocations[$vocationId] ?? 'Unknown';
    }
}

$skillOptions = [
    'level' => ['label' => 'Experience', 'column' => 'level'],
    'maglevel' => ['label' => 'Magic Level', 'column' => 'maglevel'],
    'fist' => ['label' => 'Fist Fighting', 'column' => 'skill_fist'],
    'club' => ['label' => 'Club Fighting', 'column' => 'skill_club'],
    'sword' => ['label' => 'Sword Fighting', 'column' => 'skill_sword'],
    'axe' => ['label' => 'Axe Fighting', 'column' => 'skill_axe'],
    'distance' => ['label' => 'Distance Fighting', 'column' => 'skill_dist'],
    'shielding' => ['label' => 'Shielding', 'column' => 'skill_shielding'],
    'fishing' => ['label' => 'Fishing', 'column' => 'skill_fishing'],
];

$selectedSkill = strtolower((string) ($_GET['skill'] ?? 'level'));
if (!array_key_exists($selectedSkill, $skillOptions)) {
    $selectedSkill = 'level';
}

$vocationFilter = $_GET['vocation'] ?? '';
$vocationValue = null;
if ($vocationFilter !== '' && is_numeric($vocationFilter)) {
    $vocationValue = (int) $vocationFilter;
}

$params = [];
$where = 'WHERE deletion = 0';
if ($vocationValue !== null) {
    $where .= ' AND vocation = ?';
    $params[] = $vocationValue;
}

$orderColumn = $skillOptions[$selectedSkill]['column'];
$sql = "SELECT name, level, vocation, maglevel, skill_fist, skill_club, skill_sword, skill_axe, skill_dist, skill_shielding, skill_fishing, experience
    FROM players
    $where
    ORDER BY $orderColumn DESC, experience DESC, name ASC
    LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
?>
<section class="page page--highscores">
    <h2>Highscores</h2>
    <form method="get" class="highscores__filters">
        <input type="hidden" name="p" value="highscores">
        <label>
            Skill:
            <select name="skill">
                <?php foreach ($skillOptions as $key => $option): ?>
                    <option value="<?php echo sanitize($key); ?>" <?php echo $key === $selectedSkill ? 'selected' : ''; ?>>
                        <?php echo sanitize($option['label']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Vocation:
            <select name="vocation">
                <option value="">All</option>
                <?php foreach ([0,1,2,3,4,5,6,7,8] as $vocationId): ?>
                    <option value="<?php echo $vocationId; ?>" <?php echo $vocationValue === $vocationId ? 'selected' : ''; ?>>
                        <?php echo sanitize(vocation_name($vocationId)); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Apply</button>
    </form>
    <table class="table table--highscores">
        <thead>
            <tr>
                <th>Rank</th>
                <th>Name</th>
                <th>Vocation</th>
                <th>Level</th>
                <th><?php echo sanitize($skillOptions[$selectedSkill]['label']); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="5">No entries found.</td>
                </tr>
            <?php else: ?>
                <?php $rank = 1; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo $rank++; ?></td>
                        <td><?php echo sanitize($row['name']); ?></td>
                        <td><?php echo sanitize(vocation_name((int) $row['vocation'])); ?></td>
                        <td><?php echo (int) $row['level']; ?></td>
                        <td><?php echo (int) $row[$skillOptions[$selectedSkill]['column']]; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</section>
