<div class="container-page">
  <div class="nx-nebula-shell py-4 py-lg-5">
    <div class="row g-4 g-xl-5 align-items-start">
      <aside class="col-12 col-xl-3 order-3 order-xl-1">
        <?php include __DIR__ . '/../../../includes/sidebar-left.php'; ?>
      </aside>

      <main class="col-12 col-xl-6 order-1 order-xl-2">
        <div class="card nx-card nx-glow mb-4 mb-xl-0">
          <div class="card-body p-4 p-lg-5">
            <?php include $pageFile; ?>
          </div>
        </div>
      </main>

      <aside class="col-12 col-xl-3 order-2 order-xl-3">
        <?php include __DIR__ . '/../../../includes/sidebar-right.php'; ?>
      </aside>
    </div>
  </div>
</div>
