<?php

declare(strict_types=1);

require_once __DIR__ . '/_cache.php';

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config.php';
}

if (!function_exists('db')) {
    require_once __DIR__ . '/../db.php';
}

if (!function_exists('sanitize')) {
    require_once __DIR__ . '/../functions.php';
}

if (!function_exists('vocation_name_widget')) {
    function vocation_name_widget(int $vocationId): string
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

global $WIDGETS;

$WIDGETS = [
    'top_levels' => ['title' => 'Top Players', 'renderer' => 'widget_top_levels', 'ttl' => 60],
    'top_guilds' => ['title' => 'Top Guilds', 'renderer' => 'widget_top_guilds', 'ttl' => 120],
    'online' => ['title' => 'Who’s Online', 'renderer' => 'widget_online', 'ttl' => 20],
    'recent_deaths' => ['title' => 'Recent Deaths', 'renderer' => 'widget_recent_deaths', 'ttl' => 60],
    'server_status' => ['title' => 'Server Status', 'renderer' => 'widget_server_status', 'ttl' => 15],
    'vote_links' => ['title' => 'Vote & Support', 'renderer' => 'widget_vote_links', 'ttl' => 3600],
];

function widget_collect_attributes(string $slug, int $limit, ?array $attributeOverrides = null): array
{
    global $WIDGETS;

    $attributes = ['data-auto-refresh' => $slug];

    $widget = $WIDGETS[$slug] ?? [];
    $ttlSeconds = isset($widget['ttl']) ? (int) $widget['ttl'] : 0;
    if ($ttlSeconds > 0) {
        $attributes['data-interval'] = max(5000, $ttlSeconds * 1000);
    } else {
        $attributes['data-interval'] = 15000;
    }

    if ($limit > 0) {
        $attributes['data-limit'] = $limit;
    }

    if (is_array($attributeOverrides)) {
        foreach ($attributeOverrides as $key => $value) {
            if ($value === null || $value === false) {
                unset($attributes[$key]);
                continue;
            }

            $attributes[$key] = $value;
        }
    }

    return $attributes;
}

if (!function_exists('widget_resolve_attributes')) {
    /**
     * Turn an assoc array into a safe HTML attributes string.
     * Example: ['data-auto-refresh'=>'online','data-interval'=>15000,'hidden'=>true]
     *   ->  ' data-auto-refresh="online" data-interval="15000" hidden'
     */
    function widget_resolve_attributes(array $attrs = []): string
    {
        if (!$attrs) {
            return '';
        }

        $out = [];
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === false) {
                continue;
            }

            $k = htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8');

            if ($v === true) {
                $out[] = $k;
            } else {
                $val = htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                $out[] = $k . '="' . $val . '"';
            }
        }

        return $out ? ' ' . implode(' ', $out) : '';
    }
}

function widget_cache_key(string $slug, int $limit, array $attributes = []): string
{
    $params = ['limit' => $limit];

    if ($attributes) {
        ksort($attributes);
        $params['attrs'] = $attributes;
    }

    return cache_key('widget:' . $slug, $params);
}

function nx_widget_registry(): array
{
    global $WIDGETS;

    if (!is_array($WIDGETS)) {
        return [];
    }

    return $WIDGETS;
}

function nx_widget_default_layout(): array
{
    return [
        'left' => ['top_levels', 'top_guilds', 'vote_links'],
        'right' => ['online', 'server_status', 'recent_deaths'],
    ];
}

function nx_widget_default_limits(): array
{
    return [
        'top_levels' => 10,
        'top_guilds' => 8,
        'online' => 10,
        'recent_deaths' => 8,
    ];
}

function nx_widget_normalize_slug(?string $slug): string
{
    $slug = strtolower(trim((string) $slug));
    $slug = preg_replace('/[^a-z0-9_]/', '', $slug);

    return is_string($slug) ? $slug : '';
}

function nx_widget_normalize_page_slug(?string $pageSlug): string
{
    $pageSlug = nx_widget_normalize_slug($pageSlug);

    if ($pageSlug === '') {
        return '';
    }

    return $pageSlug;
}

function nx_widget_setting_key(string $side, ?string $pageSlug = null): string
{
    $side = $side === 'right' ? 'right' : 'left';
    $pageSlug = nx_widget_normalize_page_slug($pageSlug);

    if ($pageSlug === '') {
        return 'widgets_' . $side . '_default';
    }

    return 'widgets_' . $side . '_' . $pageSlug;
}

function nx_widget_setting_fetch(PDO $pdo, string $side, ?string $pageSlug = null): array
{
    $key = nx_widget_setting_key($side, $pageSlug);

    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $result = ['found' => false, 'value' => []];

    if (!widget_table_exists($pdo, 'settings')) {
        $cache[$key] = $result;

        return $result;
    }

    try {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            $cache[$key] = $result;

            return $result;
        }

        $json = trim((string) $value);
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $registry = nx_widget_registry();
        $slugs = [];

        foreach ($decoded as $slug) {
            $normalized = nx_widget_normalize_slug(is_string($slug) ? $slug : null);

            if ($normalized === '' || isset($slugs[$normalized])) {
                continue;
            }

            if (!array_key_exists($normalized, $registry)) {
                continue;
            }

            $slugs[$normalized] = true;
        }

        $result = [
            'found' => true,
            'value' => array_keys($slugs),
        ];
    } catch (PDOException $exception) {
        $result = ['found' => false, 'value' => []];
    }

    $cache[$key] = $result;

    return $result;
}

function nx_widget_resolve_layout(PDO $pdo, string $side, string $pageSlug): array
{
    $side = $side === 'right' ? 'right' : 'left';
    $pageSlug = nx_widget_normalize_page_slug($pageSlug);

    $pageSetting = nx_widget_setting_fetch($pdo, $side, $pageSlug);
    if ($pageSetting['found']) {
        return ['slugs' => $pageSetting['value'], 'source' => 'page'];
    }

    $defaultSetting = nx_widget_setting_fetch($pdo, $side, null);
    if ($defaultSetting['found']) {
        return ['slugs' => $defaultSetting['value'], 'source' => 'default'];
    }

    $defaults = nx_widget_default_layout();

    return [
        'slugs' => $defaults[$side] ?? [],
        'source' => 'fallback',
    ];
}

function nx_widget_build_order(array $enabledSlugs, array $registry): array
{
    $order = [];
    $seen = [];
    $limits = nx_widget_default_limits();

    foreach ($enabledSlugs as $slug) {
        $normalized = nx_widget_normalize_slug($slug);

        if ($normalized === '' || isset($seen[$normalized])) {
            continue;
        }

        if (!array_key_exists($normalized, $registry)) {
            continue;
        }

        $limit = $limits[$normalized] ?? null;

        $order[] = ['slug' => $normalized, 'enabled' => true, 'limit' => $limit];
        $seen[$normalized] = true;
    }

    foreach ($registry as $slug => $widget) {
        if (isset($seen[$slug])) {
            continue;
        }

        $limit = $limits[$slug] ?? null;

        $order[] = ['slug' => $slug, 'enabled' => false, 'limit' => $limit];
    }

    return $order;
}

function nx_widget_order_from_slugs(array $enabledSlugs): array
{
    $registry = nx_widget_registry();

    return nx_widget_build_order($enabledSlugs, $registry);
}

function nx_widget_order(PDO $pdo, string $side, string $pageSlug): array
{
    $resolved = nx_widget_resolve_layout($pdo, $side, $pageSlug);

    return nx_widget_order_from_slugs($resolved['slugs']);
}

function nx_widget_save_enabled_slugs(PDO $pdo, string $side, ?string $pageSlug, array $slugs): void
{
    $side = $side === 'right' ? 'right' : 'left';
    $pageSlug = nx_widget_normalize_page_slug($pageSlug);
    $registry = nx_widget_registry();
    $filtered = [];

    foreach ($slugs as $slug) {
        $normalized = nx_widget_normalize_slug($slug);

        if ($normalized === '' || isset($filtered[$normalized])) {
            continue;
        }

        if (!array_key_exists($normalized, $registry)) {
            continue;
        }

        $filtered[$normalized] = true;
    }

    $payload = json_encode(array_keys($filtered));
    $key = nx_widget_setting_key($side, $pageSlug === '' ? null : $pageSlug);

    if (!widget_table_exists($pdo, 'settings')) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $stmt->execute([
        'key' => $key,
        'value' => $payload,
    ]);
}

function nx_widget_delete_configuration(PDO $pdo, string $side, ?string $pageSlug = null): void
{
    $side = $side === 'right' ? 'right' : 'left';
    $pageSlug = nx_widget_normalize_page_slug($pageSlug);
    $key = nx_widget_setting_key($side, $pageSlug === '' ? null : $pageSlug);

    if (!widget_table_exists($pdo, 'settings')) {
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM settings WHERE `key` = :key');
    $stmt->execute(['key' => $key]);
}

function widget_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vocation_short_code_widget(int $vocationId): string
{
    $codes = [
        0 => 'None',
        1 => 'Sor',
        2 => 'Dru',
        3 => 'Pal',
        4 => 'Kni',
        5 => 'MS',
        6 => 'ED',
        7 => 'RP',
        8 => 'EK',
    ];

    return $codes[$vocationId] ?? 'N/A';
}

function widget_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare('SELECT name FROM sqlite_master WHERE type = :type AND name = :name LIMIT 1');
        $stmt->execute([':type' => 'table', ':name' => $table]);
        $cache[$table] = (bool) $stmt->fetchColumn();

        return $cache[$table];
    }

    $sql = 'SELECT 1 FROM information_schema.tables WHERE table_name = :name AND table_schema = DATABASE() LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':name' => $table]);
    $cache[$table] = (bool) $stmt->fetchColumn();

    return $cache[$table];
}

function widget_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
        if ($stmt->execute()) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (isset($row['name']) && $row['name'] === $column) {
                    $cache[$key] = true;

                    return true;
                }
            }
        }
        $cache[$key] = false;

        return false;
    }

    $sql = 'SELECT 1 FROM information_schema.columns WHERE table_name = :table AND column_name = :column AND table_schema = DATABASE() LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':table' => $table, ':column' => $column]);
    $cache[$key] = (bool) $stmt->fetchColumn();

    return $cache[$key];
}

function widget_relative_time(int $timestamp): string
{
    $diff = max(0, time() - $timestamp);
    $units = [
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second',
    ];

    foreach ($units as $seconds => $label) {
        if ($diff >= $seconds) {
            $value = (int) floor($diff / $seconds);
            $suffix = $value === 1 ? '' : 's';

            return $value . ' ' . $label . $suffix . ' ago';
        }
    }

    return 'just now';
}

function widget_top_levels(PDO $pdo, int $limit = 10): string
{
    $stmt = $pdo->prepare('SELECT name, level, vocation FROM players ORDER BY level DESC, experience DESC, name ASC LIMIT :lim');
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($players === [] || $players === false) {
        return '<p class="text-muted mb-0">No players found.</p>';
    }

    $html = '<ol class="list-group list-group-numbered list-group-flush">';
    foreach ($players as $player) {
        $name = (string) ($player['name'] ?? '');
        $level = (int) ($player['level'] ?? 0);
        $vocation = (int) ($player['vocation'] ?? 0);
        $href = '?p=character&amp;name=' . rawurlencode($name);
        $html .= '<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">';
        $html .= '<div>';
        $html .= '<a class="text-decoration-none fw-semibold text-light" href="' . $href . '">' . widget_escape($name) . '</a>';
        $html .= '<div class="text-muted small">Level ' . $level . '</div>';
        $html .= '</div>';
        $html .= '<span class="badge bg-primary-subtle text-primary-emphasis px-3 py-2">' . widget_escape(vocation_name_widget($vocation)) . '</span>';
        $html .= '</li>';
    }
    $html .= '</ol>';

    return $html;
}

function widget_top_guilds(PDO $pdo, int $limit = 8): string
{
    $membershipTable = widget_table_exists($pdo, 'guild_memberships') ? 'guild_memberships' : 'guild_membership';
    $scoreColumn = null;
    $scoreLabel = '';

    if (widget_table_has_column($pdo, 'guilds', 'points')) {
        $scoreColumn = 'points';
        $scoreLabel = 'Points';
    } elseif (widget_table_has_column($pdo, 'guilds', 'frags')) {
        $scoreColumn = 'frags';
        $scoreLabel = 'Frags';
    }

    if ($scoreColumn !== null) {
        $sql = "SELECT g.name, COALESCE(g.$scoreColumn, 0) AS score, COUNT(m.player_id) AS members
            FROM guilds g
            LEFT JOIN $membershipTable m ON m.guild_id = g.id
            GROUP BY g.id, g.name, g.$scoreColumn
            ORDER BY score DESC, members DESC, g.name ASC
            LIMIT :lim";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $guilds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT g.id, g.name,
                COALESCE((SELECT AVG(sub.level)
                          FROM (
                              SELECT p.level
                              FROM $membershipTable gm2
                              JOIN players p ON p.id = gm2.player_id
                              WHERE gm2.guild_id = g.id
                              ORDER BY p.level DESC
                              LIMIT 10
                          ) AS sub), 0) AS avg_level,
                (SELECT COUNT(*) FROM $membershipTable gm3 WHERE gm3.guild_id = g.id) AS members
            FROM guilds g
            ORDER BY avg_level DESC, members DESC, g.name ASC
            LIMIT :lim";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $guilds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($guilds === [] || $guilds === false) {
        return '<p class="text-muted mb-0">No guilds found.</p>';
    }

    $html = '<ol class="list-group list-group-numbered list-group-flush">';
    foreach ($guilds as $guild) {
        $name = (string) ($guild['name'] ?? '');
        $members = (int) ($guild['members'] ?? 0);
        $url = '?p=guilds&amp;name=' . rawurlencode($name);
        $html .= '<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">';
        $html .= '<div>';
        $html .= '<a class="text-decoration-none fw-semibold text-light" href="' . $url . '">' . widget_escape($name) . '</a>';
        if ($scoreColumn !== null) {
            $scoreValue = (int) ($guild['score'] ?? 0);
            $html .= '<div class="text-muted small">' . $members . ' members • ' . widget_escape($scoreLabel) . ': ' . $scoreValue . '</div>';
        } else {
            $avg = number_format((float) ($guild['avg_level'] ?? 0), 1);
            $html .= '<div class="text-muted small">Avg Lv ' . $avg . ' • ' . $members . ' members</div>';
        }
        $html .= '</div>';
        $html .= '<span class="badge bg-primary-subtle text-primary-emphasis px-3 py-2"><i class="bi bi-people-fill me-1"></i>' . $members . '</span>';
        $html .= '</li>';
    }
    $html .= '</ol>';

    return $html;
}

function widget_online(PDO $pdo, int $limit = 10): string
{
    $hasOnlineColumn = widget_table_has_column($pdo, 'players', 'online');
    $hasPlayersOnline = widget_table_exists($pdo, 'players_online');

    if (!$hasOnlineColumn && !$hasPlayersOnline) {
        return '<p class="widget-empty">No players online.</p>';
    }

    if ($hasOnlineColumn) {
        $listSql = 'SELECT name, level, vocation FROM players WHERE online = 1 ORDER BY level DESC, experience DESC, name ASC LIMIT :lim';
        $countSql = 'SELECT COUNT(*) FROM players WHERE online = 1';
    } else {
        $listSql = 'SELECT p.name, p.level, p.vocation
            FROM players_online po
            INNER JOIN players p ON p.id = po.player_id
            ORDER BY p.level DESC, p.experience DESC, p.name ASC
            LIMIT :lim';
        $countSql = 'SELECT COUNT(*) FROM players_online';
    }

    $stmt = $pdo->prepare($listSql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countStmt = $pdo->query($countSql);
    $totalOnline = $countStmt !== false ? (int) $countStmt->fetchColumn() : count($players);

    if ($players === []) {
        return '<div class="d-flex justify-content-between align-items-center"><span class="text-muted">No players online.</span><span class="badge rounded-pill bg-secondary">' . $totalOnline . '</span></div>';
    }

    $html = '<div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small">Players online</span><span class="badge rounded-pill bg-success">' . $totalOnline . '</span></div>';
    $html .= '<ul class="list-group list-group-flush">';
    foreach ($players as $player) {
        $name = (string) ($player['name'] ?? '');
        $level = (int) ($player['level'] ?? 0);
        $vocation = (int) ($player['vocation'] ?? 0);
        $url = '?p=character&amp;name=' . rawurlencode($name);
        $html .= '<li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">';
        $html .= '<div>';
        $html .= '<a class="text-decoration-none fw-semibold text-light" href="' . $url . '">' . widget_escape($name) . '</a>';
        $html .= '<div class="text-muted small">Level ' . $level . ' • ' . widget_escape(vocation_name_widget($vocation)) . '</div>';
        $html .= '</div>';
        $html .= '<span class="badge rounded-pill bg-success-subtle text-success-emphasis">Online</span>';
        $html .= '</li>';
    }
    $html .= '</ul>';

    return $html;
}

function widget_recent_deaths(PDO $pdo, int $limit = 8): string
{
    $deathsTable = widget_table_exists($pdo, 'deaths') ? 'deaths' : 'player_deaths';
    $killerColumn = widget_table_has_column($pdo, $deathsTable, 'killer') ? 'killer' : 'killed_by';

    $sql = "SELECT p.name, d.level, d.time, d.$killerColumn AS killer
        FROM $deathsTable d
        INNER JOIN players p ON p.id = d.player_id
        ORDER BY d.time DESC
        LIMIT :lim";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $deaths = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($deaths === [] || $deaths === false) {
        return '<p class="text-muted mb-0">No recent deaths.</p>';
    }

    $html = '<ul class="list-group list-group-flush">';
    foreach ($deaths as $death) {
        $name = (string) ($death['name'] ?? '');
        $level = (int) ($death['level'] ?? 0);
        $killer = (string) ($death['killer'] ?? 'Unknown');
        $time = isset($death['time']) ? (int) $death['time'] : 0;
        $relative = $time > 0 ? widget_relative_time($time) : 'Unknown time';
        $html .= '<li class="list-group-item bg-transparent">';
        $html .= '<div class="d-flex justify-content-between align-items-start">';
        $html .= '<div>';
        $html .= '<div class="fw-semibold text-light">' . widget_escape($name) . ' <span class="text-muted small">Lv ' . $level . '</span></div>';
        $html .= '<div class="text-muted small">Slain by ' . widget_escape($killer) . '</div>';
        $html .= '</div>';
        if ($time > 0) {
            $html .= '<time class="text-muted small" datetime="' . widget_escape(date('c', $time)) . '">' . widget_escape($relative) . '</time>';
        } else {
            $html .= '<span class="text-muted small">' . widget_escape($relative) . '</span>';
        }
        $html .= '</div>';
        $html .= '</li>';
    }
    $html .= '</ul>';

    return $html;
}

function widget_server_status(PDO $pdo): string
{
    $hasOnlineColumn = widget_table_has_column($pdo, 'players', 'online');
    $hasPlayersOnline = widget_table_exists($pdo, 'players_online');

    if ($hasOnlineColumn) {
        $onlineStmt = $pdo->query('SELECT COUNT(*) FROM players WHERE online = 1');
    } elseif ($hasPlayersOnline) {
        $onlineStmt = $pdo->query('SELECT COUNT(*) FROM players_online');
    } else {
        $onlineStmt = false;
    }

    $onlineCount = $onlineStmt !== false ? (int) $onlineStmt->fetchColumn() : 0;

    $record = 0;
    if (widget_table_exists($pdo, 'server_config')) {
        $recordStmt = $pdo->prepare('SELECT value FROM server_config WHERE config = :config LIMIT 1');
        $recordStmt->execute([':config' => 'players_record']);
        $recordValue = $recordStmt->fetchColumn();
        if ($recordValue !== false) {
            $record = (int) $recordValue;
        }
    }

    $since = time() - 86400;
    $logins = 0;
    if (widget_table_has_column($pdo, 'players', 'lastlogin')) {
        $loginsStmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE lastlogin >= :since');
        $loginsStmt->bindValue(':since', $since, PDO::PARAM_INT);
        $loginsStmt->execute();
        $logins = (int) $loginsStmt->fetchColumn();
    }

    $statusHost = defined('GAME_SERVER_STATUS_HOST') ? GAME_SERVER_STATUS_HOST : '127.0.0.1';
    $statusPort = defined('GAME_SERVER_STATUS_PORT') ? (int) GAME_SERVER_STATUS_PORT : 7171;
    $isOnline = $statusPort > 0 && nx_port_is_listening($statusHost, $statusPort);
    $status = $isOnline ? 'Online' : 'Offline';
    $statusBadge = $isOnline
        ? '<span class="nx-status-indicator nx-status-indicator--online">Online</span>'
        : '<span class="nx-status-indicator nx-status-indicator--offline">Offline</span>';

    $html = '<dl class="row mb-0">';
    $html .= '<dt class="col-6">Status</dt><dd class="col-6 text-end">' . $statusBadge . '</dd>';
    $html .= '<dt class="col-6">Online Now</dt><dd class="col-6 text-end fw-semibold">' . $onlineCount . '</dd>';
    $html .= '<dt class="col-6">Peak Today</dt><dd class="col-6 text-end">' . $record . '</dd>';
    $html .= '<dt class="col-6">Logins (24h)</dt><dd class="col-6 text-end">' . $logins . '</dd>';
    $html .= '</dl>';

    return $html;
}

function widget_vote_links(PDO $pdo, int $limit = 0): string
{
    $links = [];

    if (widget_table_exists($pdo, 'settings')) {
        $keys = [
            'vote_link_1_title',
            'vote_link_1_url',
            'vote_link_2_title',
            'vote_link_2_url',
        ];
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare('SELECT `key`, value FROM settings WHERE `key` IN (' . $placeholders . ')');
        $stmt->execute($keys);
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (isset($row['key'])) {
                $settings[$row['key']] = (string) $row['value'];
            }
        }

        for ($i = 1; $i <= 2; $i++) {
            $titleKey = 'vote_link_' . $i . '_title';
            $urlKey = 'vote_link_' . $i . '_url';
            $title = trim($settings[$titleKey] ?? '');
            $url = trim($settings[$urlKey] ?? '');

            if ($url !== '') {
                $links[] = [
                    'label' => $title !== '' ? $title : 'Vote Link ' . $i,
                    'url' => $url,
                ];
            }
        }
    }

    if ($links === []) {
        $links = [
            ['label' => 'Vote on OTServList', 'url' => 'https://otservlist.org/'],
        ];
    }

    if ($limit > 0) {
        $links = array_slice($links, 0, $limit);
    }

    if ($links === []) {
        return '<p class="text-muted mb-0">No vote links available.</p>';
    }

    $html = '<div class="d-grid gap-2">';
    foreach ($links as $link) {
        $label = widget_escape($link['label']);
        $url = htmlspecialchars($link['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html .= '<a class="btn btn-outline-primary btn-sm" href="' . $url . '" rel="noopener noreferrer" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>' . $label . '</a>';
    }
    $html .= '</div>';

    return $html;
}

function widget_render_box_html(array $widget, string $slug, array $attributes, string $innerHtml): string
{
    $title = htmlspecialchars($widget['title'] ?? $slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $attrString = widget_resolve_attributes($attributes);

    return '<section class="widget"' . $attrString . '><h3>' . $title . '</h3><div class="widget-body">' . $innerHtml . '</div></section>';
}

function render_widget_box(string $slug, int $limit = 5, ?array $attributeOverrides = null, bool $wrap = true): string
{
    global $WIDGETS;

    if (!isset($WIDGETS[$slug])) {
        return '';
    }

    $widget = $WIDGETS[$slug];
    $renderer = $widget['renderer'] ?? null;
    if (!is_callable($renderer)) {
        return '';
    }

    $limit = max(1, $limit);
    $attributes = widget_collect_attributes($slug, $limit, $attributeOverrides);
    $cacheAttributes = $attributes;
    $cacheAttributes['_wrap_v'] = '2';
    $ttl = isset($widget['ttl']) ? (int) $widget['ttl'] : 0;
    $key = widget_cache_key($slug, $limit, $cacheAttributes);

    if ($ttl > 0) {
        $cached = cache_get($key, $ttl);
        if ($cached !== null) {
            return $wrap ? widget_render_box_html($widget, $slug, $attributes, $cached) : $cached;
        }
    }

    $pdo = db();

    if (!$pdo instanceof PDO) {
        $innerHtml = '<p class="text-muted mb-0">Unavailable.</p>';

        return $wrap ? widget_render_box_html($widget, $slug, $attributes, $innerHtml) : $innerHtml;
    }

    $innerHtml = call_user_func($renderer, $pdo, $limit);
    if (!is_string($innerHtml)) {
        $innerHtml = (string) $innerHtml;
    }

    if ($ttl > 0) {
        cache_set($key, $innerHtml);
    }

    return $wrap ? widget_render_box_html($widget, $slug, $attributes, $innerHtml) : $innerHtml;
}
