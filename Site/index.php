<?php
session_start();

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/includes/theme.php';
$pageFile = require __DIR__ . '/routes.php';

$themeSlug = nx_theme_active_slug();
$headerTemplate = nx_theme_locate($themeSlug, 'header');
$layoutTemplate = nx_theme_locate($themeSlug, 'layout');
$footerTemplate = nx_theme_locate($themeSlug, 'footer');

if (!is_string($pageFile) || $pageFile === '') {
    return;
}

if ($headerTemplate !== null) {
    include $headerTemplate;
} else {
    include __DIR__ . '/includes/header.php';
}

if ($layoutTemplate !== null) {
    include $layoutTemplate;
} else {
    include __DIR__ . '/includes/layout.php';
}

if ($footerTemplate !== null) {
    include $footerTemplate;
} else {
    include __DIR__ . '/includes/footer.php';
}
