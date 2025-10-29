<?php
$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--whoisonline"><h2>Who is online</h2><p class="text-muted mb-0">Unavailable.</p></section>';

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

$sql = "SELECT p.name, p.level, p.vocation
    FROM players_online po
    INNER JOIN players p ON p.id = po.player_id
    WHERE p.deletion = 0
    ORDER BY p.level DESC, p.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$players = $stmt->fetchAll();
?>
<section class="page page--whoisonline">
    <h2>Who is online</h2>
    <?php if ($players === []): ?>
        <p>No players are currently online.</p>
    <?php else: ?>
        <table class="table table--whoisonline">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Level</th>
                    <th>Vocation</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($players as $player): ?>
                    <tr>
                        <td><?php echo sanitize($player['name']); ?></td>
                        <td><?php echo (int) $player['level']; ?></td>
                        <td><?php echo sanitize(vocation_name((int) $player['vocation'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
