<?php
$pdo = db();

if (!$pdo instanceof PDO) {
    echo '<section class="page page--character"><h2>Character Lookup</h2><p class="text-muted mb-0">Unavailable.</p></section>';

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
        return 'Never';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

$nameQuery = trim((string) ($_GET['name'] ?? ''));
$character = null;
$recentDeaths = [];
$message = '';

if ($nameQuery !== '') {
    $stmt = $pdo->prepare('SELECT id, name, level, vocation, maglevel, health, healthmax, mana, manamax, sex, balance, lastlogin, lastlogout, town_id, skill_fist, skill_club, skill_sword, skill_axe, skill_dist, skill_shielding, skill_fishing
        FROM players
        WHERE name = ?
        LIMIT 1');
    $stmt->execute([$nameQuery]);
    $character = $stmt->fetch();

    if ($character) {
        $deathsStmt = $pdo->prepare('SELECT time, level, killed_by, is_player
            FROM player_deaths
            WHERE player_id = ?
            ORDER BY time DESC
            LIMIT 10');
        $deathsStmt->execute([(int) $character['id']]);
        $recentDeaths = $deathsStmt->fetchAll();
    } else {
        $message = 'Character not found.';
    }
} else {
    $message = 'Please search for a character.';
}

$skills = [
    'skill_fist' => 'Fist Fighting',
    'skill_club' => 'Club Fighting',
    'skill_sword' => 'Sword Fighting',
    'skill_axe' => 'Axe Fighting',
    'skill_dist' => 'Distance Fighting',
    'skill_shielding' => 'Shielding',
    'skill_fishing' => 'Fishing',
];
?>
<section class="page page--character">
    <h2>Character Lookup</h2>
    <form method="get" class="character__search">
        <input type="hidden" name="p" value="character">
        <label>
            Name:
            <input type="text" name="name" value="<?php echo sanitize($nameQuery); ?>" required>
        </label>
        <button type="submit">Search</button>
    </form>

    <?php if ($character === null): ?>
        <p><?php echo sanitize($message); ?></p>
    <?php else: ?>
        <section class="character__summary">
            <h3><?php echo sanitize($character['name']); ?></h3>
            <ul>
                <li>Level: <?php echo (int) $character['level']; ?></li>
                <li>Vocation: <?php echo sanitize(vocation_name((int) $character['vocation'])); ?></li>
                <li>Magic Level: <?php echo (int) $character['maglevel']; ?></li>
                <li>Health: <?php echo (int) $character['health']; ?> / <?php echo (int) $character['healthmax']; ?></li>
                <li>Mana: <?php echo (int) $character['mana']; ?> / <?php echo (int) $character['manamax']; ?></li>
                <li>Balance: <?php echo sanitize(number_format((int) $character['balance'])); ?> gold</li>
                <li>Last Login: <?php echo sanitize(format_timestamp((int) $character['lastlogin'])); ?></li>
                <li>Last Logout: <?php echo sanitize(format_timestamp((int) $character['lastlogout'])); ?></li>
            </ul>
        </section>

        <section class="character__skills">
            <h3>Skills</h3>
            <ul>
                <?php foreach ($skills as $key => $label): ?>
                    <li><?php echo sanitize($label); ?>: <?php echo (int) $character[$key]; ?></li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="character__equipment">
            <h3>Equipment</h3>
            <div class="character__equipment-grid">
                <?php for ($i = 0; $i < 10; $i++): ?>
                    <div class="character__equipment-slot">Slot <?php echo $i + 1; ?></div>
                <?php endfor; ?>
            </div>
        </section>

        <section class="character__deaths">
            <h3>Recent Deaths</h3>
            <?php if ($recentDeaths === []): ?>
                <p>No recorded deaths.</p>
            <?php else: ?>
                <table class="table table--character-deaths">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Level</th>
                            <th>Killed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentDeaths as $death): ?>
                            <tr>
                                <td><?php echo sanitize(format_timestamp((int) $death['time'])); ?></td>
                                <td><?php echo (int) $death['level']; ?></td>
                                <td>
                                    <?php echo sanitize($death['killed_by']); ?>
                                    <?php if ((int) $death['is_player'] === 1): ?>
                                        (player)
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
