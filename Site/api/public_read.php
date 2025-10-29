<?php

declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/../functions.php';

const NX_PUBLIC_CACHE_TTL = 30;
const NX_PUBLIC_CACHE_DIR = __DIR__ . '/cache';

/**
 * Output an error response and terminate the request.
 */
function nx_public_error(string $message, int $status = 400): void
{
    header('Cache-Control: no-store, max-age=0');

    json_out([
        'status' => 'error',
        'message' => $message,
    ], $status);
}

/**
 * Map vocation IDs to their display name.
 */
function nx_public_vocation_name(int $vocationId): string
{
    static $vocations = [
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

$rawEndpoint = $_GET['endpoint'] ?? '';
$endpoint = strtolower(trim((string) $rawEndpoint));

$allowedEndpoints = ['highscores', 'online', 'character', 'news', 'changelog', 'widget'];

if ($endpoint === '' || !in_array($endpoint, $allowedEndpoints, true)) {
    nx_public_error('Unsupported endpoint. Expected one of: ' . implode(', ', $allowedEndpoints));
}

$characterName = '';

if ($endpoint === 'character') {
    $characterName = trim((string) ($_GET['name'] ?? ''));

    if ($characterName === '') {
        nx_public_error('The "name" query parameter is required for the character endpoint.');
    }

    if (mb_strlen($characterName) > 50) {
        nx_public_error('Character names must be 50 characters or fewer.');
    }

    if (!preg_match("/^[\p{L}0-9\s'\-]+$/u", $characterName)) {
        nx_public_error('Character names may only contain letters, numbers, spaces, apostrophes, and hyphens.');
    }
}

$cacheKeySeed = $endpoint;

if ($characterName !== '') {
    $cacheKeySeed .= '|' . mb_strtolower($characterName, 'UTF-8');
}

$usePublicCache = $endpoint !== 'widget';
$cacheFile = '';

if ($usePublicCache) {
    $cacheKey = sha1($cacheKeySeed);
    $cacheFile = NX_PUBLIC_CACHE_DIR . '/' . $cacheKey . '.json';

    if (is_file($cacheFile)) {
        $modified = filemtime($cacheFile);

        if ($modified !== false && (time() - $modified) < NX_PUBLIC_CACHE_TTL) {
            $payload = json_decode((string) file_get_contents($cacheFile), true);

            if (is_array($payload)) {
                $remaining = max(0, NX_PUBLIC_CACHE_TTL - (time() - (int) $modified));
                header('Cache-Control: public, max-age=' . $remaining);

                if (!isset($payload['meta']) || !is_array($payload['meta'])) {
                    $payload['meta'] = [];
                }

                $payload['meta']['cached'] = true;

                json_out($payload);
            }
        }
    }
}

$pdo = db();

if (!$pdo instanceof PDO) {
    json_out(['status' => 'error', 'message' => 'Database unavailable'], 503);
}

try {
    switch ($endpoint) {
        case 'highscores':
            $stmt = $pdo->query('SELECT name, level, vocation, maglevel, experience
                FROM players
                WHERE deletion = 0
                ORDER BY level DESC, experience DESC, name ASC
                LIMIT 50');

            $rows = $stmt->fetchAll();

            $data = array_map(static function (array $row): array {
                return [
                    'name' => (string) $row['name'],
                    'level' => (int) $row['level'],
                    'experience' => (int) $row['experience'],
                    'vocation' => [
                        'id' => (int) $row['vocation'],
                        'name' => nx_public_vocation_name((int) $row['vocation']),
                    ],
                    'maglevel' => (int) $row['maglevel'],
                ];
            }, $rows ?: []);
            break;

        case 'online':
            $stmt = $pdo->query('SELECT p.name, p.level, p.vocation
                FROM players_online po
                INNER JOIN players p ON p.id = po.player_id
                WHERE p.deletion = 0
                ORDER BY p.level DESC, p.name ASC');

            $rows = $stmt->fetchAll();

            $data = array_map(static function (array $row): array {
                return [
                    'name' => (string) $row['name'],
                    'level' => (int) $row['level'],
                    'vocation' => [
                        'id' => (int) $row['vocation'],
                        'name' => nx_public_vocation_name((int) $row['vocation']),
                    ],
                ];
            }, $rows ?: []);
            break;

        case 'character':
            $stmt = $pdo->prepare('SELECT id, name, level, vocation, maglevel, health, healthmax, mana, manamax, sex, balance,
                    lastlogin, lastlogout, town_id, skill_fist, skill_club, skill_sword, skill_axe, skill_dist, skill_shielding,
                    skill_fishing
                FROM players
                WHERE name = :name
                LIMIT 1');

            $stmt->execute(['name' => $characterName]);
            $character = $stmt->fetch();

            if (!$character) {
                nx_public_error('Character not found.', 404);
            }

            $deathStmt = $pdo->prepare('SELECT time, level, killed_by, is_player
                FROM player_deaths
                WHERE player_id = :id
                ORDER BY time DESC
                LIMIT 10');

            $deathStmt->execute(['id' => (int) $character['id']]);
            $recentDeaths = $deathStmt->fetchAll();

            $skillKeys = [
                'skill_fist' => 'Fist Fighting',
                'skill_club' => 'Club Fighting',
                'skill_sword' => 'Sword Fighting',
                'skill_axe' => 'Axe Fighting',
                'skill_dist' => 'Distance Fighting',
                'skill_shielding' => 'Shielding',
                'skill_fishing' => 'Fishing',
            ];

            $skills = [];

            foreach ($skillKeys as $column => $label) {
                $skills[] = [
                    'id' => $column,
                    'label' => $label,
                    'value' => (int) $character[$column],
                ];
            }

            $data = [
                'id' => (int) $character['id'],
                'name' => (string) $character['name'],
                'level' => (int) $character['level'],
                'maglevel' => (int) $character['maglevel'],
                'vocation' => [
                    'id' => (int) $character['vocation'],
                    'name' => nx_public_vocation_name((int) $character['vocation']),
                ],
                'health' => [
                    'current' => (int) $character['health'],
                    'max' => (int) $character['healthmax'],
                ],
                'mana' => [
                    'current' => (int) $character['mana'],
                    'max' => (int) $character['manamax'],
                ],
                'sex' => (int) $character['sex'],
                'balance' => (int) $character['balance'],
                'last_login' => (int) $character['lastlogin'],
                'last_logout' => (int) $character['lastlogout'],
                'town_id' => (int) $character['town_id'],
                'skills' => $skills,
                'recent_deaths' => array_map(static function (array $death): array {
                    return [
                        'timestamp' => (int) $death['time'],
                        'level' => (int) $death['level'],
                        'killed_by' => (string) $death['killed_by'],
                        'is_player' => ((int) $death['is_player']) === 1,
                    ];
                }, $recentDeaths ?: []),
            ];
            break;

        case 'news':
            $stmt = $pdo->query('SELECT id, title, slug, body, tags, created_at
                FROM news
                ORDER BY created_at DESC
                LIMIT 10');

            $rows = $stmt->fetchAll();

            $data = array_map(static function (array $row): array {
                $rawTags = array_filter(array_map('trim', explode(',', (string) ($row['tags'] ?? ''))));

                return [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'slug' => (string) $row['slug'],
                    'body' => (string) $row['body'],
                    'tags' => array_values($rawTags),
                    'created_at' => (string) $row['created_at'],
                ];
            }, $rows ?: []);
            break;

        case 'changelog':
            $stmt = $pdo->query('SELECT id, title, body, created_at
                FROM changelog
                ORDER BY created_at DESC
                LIMIT 25');

            $rows = $stmt->fetchAll();

            $data = array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'body' => (string) $row['body'],
                    'created_at' => (string) $row['created_at'],
                ];
            }, $rows ?: []);
            break;

        case 'widget':
            require_once __DIR__ . '/../widgets/_registry.php';

            $widgetName = trim((string) ($_GET['name'] ?? ''));

            if ($widgetName === '') {
                nx_public_error('The "name" query parameter is required for the widget endpoint.');
            }

            if (!isset($WIDGETS[$widgetName])) {
                nx_public_error('Unknown widget requested.', 404);
            }

            $limitParam = isset($_GET['limit']) ? (int) $_GET['limit'] : 0;
            if ($limitParam <= 0) {
                $limitParam = 5;
            }
            $limit = max(1, min(50, $limitParam));

            $html = render_widget_box($widgetName, $limit);
            $attributes = widget_collect_attributes($widgetName, $limit);
            $widgetCacheKey = widget_cache_key($widgetName, $limit, $attributes);
            $timestamp = cache_last_modified($widgetCacheKey) ?? time();

            $data = [
                'name' => $widgetName,
                'limit' => $limit,
                'html' => $html,
                'ts' => $timestamp,
            ];
            break;

        default:
            nx_public_error('Unsupported endpoint requested.', 400);
    }
} catch (PDOException $exception) {
    error_log('public_read.php: ' . $exception->getMessage());
    nx_public_error('A database error occurred while processing the request.', 500);
}

$payload = [
    'status' => 'ok',
    'endpoint' => $endpoint,
    'data' => $data ?? [],
    'meta' => [
        'cached' => false,
        'generated_at' => gmdate('c'),
        'cache_ttl' => $usePublicCache ? NX_PUBLIC_CACHE_TTL : 0,
    ],
];

if ($usePublicCache) {
    if (!is_dir(NX_PUBLIC_CACHE_DIR)) {
        @mkdir(NX_PUBLIC_CACHE_DIR, 0775, true);
    }

    if ($cacheFile !== '' && is_dir(NX_PUBLIC_CACHE_DIR)) {
        $encoded = json_encode($payload);

        if ($encoded !== false) {
            @file_put_contents($cacheFile, $encoded, LOCK_EX);
        }
    }

    header('Cache-Control: public, max-age=' . NX_PUBLIC_CACHE_TTL);
} else {
    header('Cache-Control: no-store, max-age=0');
}
json_out($payload);
