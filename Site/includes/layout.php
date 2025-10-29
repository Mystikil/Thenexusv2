<div class="container-page">
  <div class="row g-3">
    <!-- Left widgets -->
    <aside class="col-12 col-lg-3">
      <?php include __DIR__.'/sidebar-left.php'; ?>
    </aside>

    <!-- Main content -->
    <main class="col-12 col-lg-6">
      <div class="card nx-glow mb-3">
        <div class="card-body p-3 p-md-4">
          <?php include $pageFile; ?>
        </div>
      </div>
    </main>

    <!-- Right widgets -->
    <aside class="col-12 col-lg-3">
      <?php include __DIR__.'/sidebar-right.php'; ?>
    </aside>
  </div>
</div>
