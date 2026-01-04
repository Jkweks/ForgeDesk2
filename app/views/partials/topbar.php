<?php
if (!function_exists('renderTopbarExtras')) {
    /**
     * Helper to render topbar extras while keeping HTML inline in callers.
     *
     * @param string|null $extras
     * @return string
     */
    function renderTopbarExtras(?string $extras): string
    {
        return $extras ?? '';
    }
}
?>
<header class="navbar navbar-expand-md d-print-none">
  <div class="container-xl">
    <button
      class="navbar-toggler"
      type="button"
      data-bs-toggle="collapse"
      data-bs-target="#sidebar-menu"
      aria-controls="sidebar-menu"
      aria-expanded="false"
      aria-label="Toggle navigation"
    >
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="navbar-nav flex-row order-md-last">
      <div class="nav-item">
        <div class="d-flex align-items-center" aria-label="Current user">
          <span class="avatar avatar-sm" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
          <div class="ps-2 d-none d-sm-block">
            <div class="fw-semibold"><?= e($app['user']['name']) ?></div>
            <div class="text-secondary small"><?= e($app['user']['email']) ?></div>
          </div>
        </div>
      </div>
    </div>
    <?php if (!empty($topbarTitle ?? null)): ?>
      <div class="navbar-brand">
        <div class="d-flex flex-column">
          <h1 class="mb-0 h2"><?= e((string) $topbarTitle) ?></h1>
          <?php if (!empty($topbarSubhead ?? null)): ?>
            <p class="text-secondary mb-0 small"><?= e((string) $topbarSubhead) ?></p>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!empty($topbarExtras ?? null)): ?>
      <div class="navbar-nav ms-auto">
        <div class="nav-item d-flex align-items-center">
          <?= renderTopbarExtras($topbarExtras) ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</header>
