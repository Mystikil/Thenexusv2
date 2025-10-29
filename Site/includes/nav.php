<?php
require_once __DIR__ . '/../auth.php';

$current = $_GET['p'] ?? 'home';
$u = current_user();
?>
<nav class="navbar navbar-expand-lg mb-3 container-page nx-glow">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <img src="/assets/img/logo.png" alt="Devnexus" style="height:36px;border-radius:8px">
      <span class="fw-semibold">Devnexus Online</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nxNav" aria-controls="nxNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nxNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link<?php echo $current === 'home' ? ' active' : ''; ?>" href="?p=home">Home</a></li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Game</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="?p=news">News</a></li>
            <li><a class="dropdown-item" href="?p=bestiary">Bestiary</a></li>
            <li><a class="dropdown-item" href="?p=spells">Spells</a></li>
            <li><a class="dropdown-item" href="?p=guilds">Guilds</a></li>
            <li><a class="dropdown-item" href="?p=highscores">Highscores</a></li>
            <li><a class="dropdown-item" href="?p=whoisonline">Who’s Online</a></li>
            <li><a class="dropdown-item" href="?p=deaths">Deaths</a></li>
            <li><a class="dropdown-item" href="?p=market">Market</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Community</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="?p=tickets">Support</a></li>
            <li><a class="dropdown-item" href="?p=downloads">Downloads</a></li>
            <li><a class="dropdown-item" href="?p=rules">Rules</a></li>
            <li><a class="dropdown-item" href="?p=about">About</a></li>
          </ul>
        </li>
      </ul>

      <ul class="navbar-nav ms-auto">
        <?php if ($u): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
              <span><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($u['email'] ?? $u['account_name'] ?? 'Account') ?></span>
              <?php if ($u && is_master($u)): ?>
                <span class="badge bg-warning text-dark">MASTER</span>
              <?php endif; ?>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="?p=account">Dashboard</a></li>
              <li><a class="dropdown-item" href="?p=characters">My Characters</a></li>
              <li><a class="dropdown-item" href="?p=shop">Shop</a></li>
              <?php if (function_exists('is_role') && is_role('admin')): ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="/admin/">Admin Panel</a></li>
              <?php endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item text-danger" href="?p=account&action=logout"><i class="bi bi-box-arrow-right me-1"></i>Logout</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="?p=account">Login / Register</a></li>
          <li class="nav-item"><a class="nav-link" href="?p=recover">Recover</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
