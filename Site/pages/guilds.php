<?php
$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--guilds"><h2>Guilds</h2><p class="text-muted mb-0">Unavailable.</p></section>';

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

function format_timestamp(int $timestamp): string
{
    if ($timestamp <= 0) {
        return 'Unknown';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

$nameQuery = trim((string) ($_GET['name'] ?? ''));
$guild = null;
$members = [];

if ($nameQuery !== '') {
    $guildStmt = $pdo->prepare('SELECT g.id, g.name, g.creationdata, g.motd, p.name AS owner_name
        FROM guilds g
        INNER JOIN players p ON p.id = g.ownerid
        WHERE g.name = ?
        LIMIT 1');
    $guildStmt->execute([$nameQuery]);
    $guild = $guildStmt->fetch();

    if ($guild) {
        $membersStmt = $pdo->prepare('SELECT pl.name, pl.level, pl.vocation, gr.name AS rank_name, gr.level AS rank_level
            FROM guild_membership gm
            INNER JOIN players pl ON pl.id = gm.player_id
            INNER JOIN guild_ranks gr ON gr.id = gm.rank_id
            WHERE gm.guild_id = ?
            ORDER BY gr.level DESC, pl.level DESC, pl.name ASC');
        $membersStmt->execute([(int) $guild['id']]);
        $members = $membersStmt->fetchAll();
    }
} else {
    $guild = null;
}

if ($guild === null && $nameQuery === '') {
    $listStmt = $pdo->prepare('SELECT g.id, g.name, g.creationdata, g.motd, p.name AS owner_name, COUNT(gm.player_id) AS member_count
        FROM guilds g
        INNER JOIN players p ON p.id = g.ownerid
        LEFT JOIN guild_membership gm ON gm.guild_id = g.id
        GROUP BY g.id, g.name, g.creationdata, g.motd, p.name
        ORDER BY g.name ASC');
    $listStmt->execute();
    $guilds = $listStmt->fetchAll();
} else {
    $guilds = [];
}
?>
<section class="page page--guilds">
    <h2>Guilds</h2>
    <?php if ($nameQuery === ''): ?>
        <?php if ($guilds === []): ?>
            <p>No guilds have been created yet.</p>
        <?php else: ?>
            <table class="table table--guilds">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Leader</th>
                        <th>Members</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guilds as $guildRow): ?>
                        <tr>
                            <td><a href="<?php echo sanitize(base_url('index.php?p=guilds&name=' . urlencode($guildRow['name']))); ?>"><?php echo sanitize($guildRow['name']); ?></a></td>
                            <td><?php echo sanitize($guildRow['owner_name']); ?></td>
                            <td><?php echo (int) $guildRow['member_count']; ?></td>
                            <td><?php echo sanitize(format_timestamp((int) $guildRow['creationdata'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($guild === null): ?>
            <p>Guild not found. <a href="<?php echo sanitize(base_url('index.php?p=guilds')); ?>">Back to list</a></p>
        <?php else: ?>
            <article class="guild">
                <h3><?php echo sanitize($guild['name']); ?></h3>
                <p><strong>Leader:</strong> <?php echo sanitize($guild['owner_name']); ?></p>
                <p><strong>Message:</strong> <?php echo sanitize($guild['motd']); ?></p>
                <p><strong>Created:</strong> <?php echo sanitize(format_timestamp((int) $guild['creationdata'])); ?></p>
            </article>

            <section class="guild__members">
                <h4>Members</h4>
                <?php if ($members === []): ?>
                    <p>No members yet.</p>
                <?php else: ?>
                    <table class="table table--guild-members">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Rank</th>
                                <th>Level</th>
                                <th>Vocation</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <td><?php echo sanitize($member['name']); ?></td>
                                    <td><?php echo sanitize($member['rank_name']); ?></td>
                                    <td><?php echo (int) $member['level']; ?></td>
                                    <td><?php echo sanitize(vocation_name((int) $member['vocation'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

            <p><a href="<?php echo sanitize(base_url('index.php?p=guilds')); ?>">Back to guild list</a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
