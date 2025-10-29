<?php
$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--deaths"><h2>Recent Deaths</h2><p class="text-muted mb-0">Unavailable.</p></section>';

    return;
}

$sql = "SELECT p.name, pd.level, pd.killed_by, pd.is_player, pd.time, pd.mostdamage_by, pd.mostdamage_is_player
    FROM player_deaths pd
    INNER JOIN players p ON p.id = pd.player_id
    ORDER BY pd.time DESC
    LIMIT 50";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$deaths = $stmt->fetchAll();

function format_timestamp(int $timestamp): string
{
    if ($timestamp <= 0) {
        return 'Unknown';
    }

    return date('Y-m-d H:i:s', $timestamp);
}
?>
<section class="page page--deaths">
    <h2>Recent Deaths</h2>
    <?php if ($deaths === []): ?>
        <p>No recorded deaths yet.</p>
    <?php else: ?>
        <table class="table table--deaths">
            <thead>
                <tr>
                    <th>Player</th>
                    <th>Level</th>
                    <th>Killed By</th>
                    <th>Most Damage</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deaths as $death): ?>
                    <tr>
                        <td><?php echo sanitize($death['name']); ?></td>
                        <td><?php echo (int) $death['level']; ?></td>
                        <td>
                            <?php echo sanitize($death['killed_by']); ?>
                            <?php if ((int) $death['is_player'] === 1): ?>
                                (player)
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo sanitize($death['mostdamage_by']); ?>
                            <?php if ((int) $death['mostdamage_is_player'] === 1): ?>
                                (player)
                            <?php endif; ?>
                        </td>
                        <td><?php echo sanitize(format_timestamp((int) $death['time'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
