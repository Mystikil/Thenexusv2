<?php

declare(strict_types=1);


require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'News';
$adminNavActive = 'news';

require __DIR__ . '/partials/header.php';

admin_render_placeholder('News');

require __DIR__ . '/partials/footer.php';
