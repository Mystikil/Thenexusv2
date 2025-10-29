<?php

declare(strict_types=1);

require_once __DIR__ . '/init.php';

$adminPageTitle = $adminPageTitle ?? 'Admin';
$adminNavActive = $adminNavActive ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sanitize($adminPageTitle . ' | Admin Panel | ' . SITE_TITLE); ?></title>
    <link rel="stylesheet" href="<?php echo sanitize(base_url('../assets/css/styles.css')); ?>">
    <link rel="stylesheet" href="<?php echo sanitize(base_url('../assets/css/admin.css')); ?>">
</head>
<body class="admin-body">
<header class="admin-header">
    <div class="admin-header__inner">
        <h1 class="admin-header__title">Admin Panel</h1>
        <p class="admin-header__subtitle">Managing <?php echo sanitize(SITE_TITLE); ?></p>
    </div>
    <nav class="admin-nav">
        <ul>
            <?php foreach (admin_nav_items() as $item): ?>
                <?php $isActive = $adminNavActive === $item['slug']; ?>
                <li class="<?php echo $isActive ? 'is-active' : ''; ?>">
                    <a href="<?php echo sanitize($item['href']); ?>"><?php echo sanitize($item['label']); ?></a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</header>
<main class="admin-content">
