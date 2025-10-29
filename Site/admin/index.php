<?php

declare(strict_types=1);

require_once __DIR__ . '/partials/bootstrap.php';
require_once __DIR__ . '/../auth.php';
require_admin('admin');

$adminPageTitle = 'Dashboard';
$adminNavActive = 'index';

require __DIR__ . '/partials/header.php';
?>
<section class="admin-section">
    <h2>Welcome</h2>
    <p>Choose a section from the navigation above to begin managing the site.</p>
</section>
<?php
admin_render_placeholder('Dashboard Overview');
require __DIR__ . '/partials/footer.php';
