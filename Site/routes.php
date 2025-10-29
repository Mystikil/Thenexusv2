<?php

require_once __DIR__ . '/includes/theme.php';

$routes = [
    'home' => __DIR__ . '/pages/home.php',
    'news' => __DIR__ . '/pages/news.php',
    'changelog' => __DIR__ . '/pages/changelog.php',
    'highscores' => __DIR__ . '/pages/highscores.php',
    'whoisonline' => __DIR__ . '/pages/whoisonline.php',
    'deaths' => __DIR__ . '/pages/deaths.php',
    'market' => __DIR__ . '/pages/market.php',
    'guilds' => __DIR__ . '/pages/guilds.php',
    'houses' => __DIR__ . '/pages/houses.php',
    'character' => __DIR__ . '/pages/character.php',
    'account' => __DIR__ . '/pages/account.php',
    'recover' => __DIR__ . '/pages/recover.php',
    'characters' => __DIR__ . '/pages/characters.php',
    'shop' => __DIR__ . '/pages/shop.php',
    'bestiary' => __DIR__ . '/pages/bestiary.php',
    'monster' => __DIR__ . '/pages/monster.php',
    'spells' => __DIR__ . '/pages/spells.php',
    'tickets' => __DIR__ . '/pages/tickets.php',
    'downloads' => __DIR__ . '/pages/downloads.php',
    'rules' => __DIR__ . '/pages/rules.php',
    'about' => __DIR__ . '/pages/about.php',
];

$page = $_GET['p'] ?? 'home';
$page = strtolower(trim((string) $page));
$page = preg_replace('/[^a-z0-9_]/', '', $page);

if ($page === '') {
    $page = 'home';
}

$GLOBALS['nx_current_page_slug'] = $page;

$themeSlug = nx_theme_active_slug();

if (array_key_exists($page, $routes) && file_exists($routes[$page])) {
    $override = nx_theme_locate($themeSlug, $page);

    if ($override !== null) {
        return $override;
    }

    return $routes[$page];
}

http_response_code(404);
$GLOBALS['nx_current_page_slug'] = '404';
$notFoundTemplate = nx_theme_locate($themeSlug, '404');

if ($notFoundTemplate !== null) {
    return $notFoundTemplate;
}

if (file_exists(__DIR__ . '/pages/404.php')) {
    return __DIR__ . '/pages/404.php';
}

return __DIR__ . '/includes/404-fallback.php';
