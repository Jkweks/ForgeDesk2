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
<?php
$forceLegacyTopbar = $forceLegacyTopbar ?? false;
$isAdminRoute = isset($_SERVER['SCRIPT_NAME']) && strpos((string) $_SERVER['SCRIPT_NAME'], '/admin/') !== false;
$useLegacyTopbar = $forceLegacyTopbar || $isAdminRoute;
?>
<?php if ($useLegacyTopbar): ?>
  <header class="topbar">
    <button
      class="topbar-toggle"
      type="button"
      data-sidebar-toggle
      aria-controls="app-sidebar"
      aria-expanded="false"
      aria-label="Toggle navigation"
    >
      <span aria-hidden="true"><?= icon('menu') ?></span>
    </button>

    <?php if (!empty($topbarTitle ?? null)): ?>
      <div class="topbar-title">
        <h1><?= e((string) $topbarTitle) ?></h1>
        <?php if (!empty($topbarSubhead ?? null)): ?>
          <p class="small"><?= e((string) $topbarSubhead) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($topbarExtras ?? null)): ?>
      <div class="topbar-extras"><?= renderTopbarExtras($topbarExtras) ?></div>
    <?php endif; ?>

    <div class="topbar-spacer"></div>

    <div class="user" aria-label="Current user">
      <span class="user-avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
      <div class="user-details">
        <span class="user-name"><?= e($app['user']['name']) ?></span>
        <span class="user-email"><?= e($app['user']['email']) ?></span>
      </div>
    </div>
  </header>
<?php else: ?>
  <header class="navbar navbar-expand-md d-print-none">
    <div class="container-fluid">
      <button
        class="navbar-toggler"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#sidebar-menu"
        data-sidebar-toggle
        aria-controls="sidebar-menu"
        aria-expanded="false"
        aria-label="Toggle navigation"
      >
        <span class="navbar-toggler-icon" aria-hidden="true"></span>
      </button>

      <div class="navbar-brand flex-row align-items-center">
        <?php if (!empty($topbarTitle ?? null)): ?>
          <div class="d-flex flex-column">
            <span class="navbar-brand-text fw-semibold mb-0"><?= e((string) $topbarTitle) ?></span>
            <?php if (!empty($topbarSubhead ?? null)): ?>
              <small class="text-muted"><?= e((string) $topbarSubhead) ?></small>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="navbar-nav flex-row order-md-last align-items-center">
        <?php if (!empty($topbarExtras ?? null)): ?>
          <div class="nav-item d-none d-md-flex align-items-center me-2">
            <?= renderTopbarExtras($topbarExtras) ?>
          </div>
        <?php endif; ?>
        <div class="nav-item d-none d-md-flex">
          <button class="btn btn-primary btn-icon" type="button" aria-label="Create new item">
            <?= icon('plus') ?>
          </button>
        </div>
        <div class="nav-item dropdown ms-2">
          <a href="#" class="nav-link d-flex align-items-center px-2" data-bs-toggle="dropdown" aria-label="Open user menu">
            <span class="avatar me-2" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
            <div class="d-none d-md-block lh-sm">
              <div class="fw-semibold"><?= e($app['user']['name']) ?></div>
              <small class="text-muted"><?= e($app['user']['email']) ?></small>
            </div>
          </a>
          <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
            <span class="dropdown-header">Signed in</span>
            <span class="dropdown-item d-flex align-items-center">
              <?= icon('user', 'icon me-2') ?>
              <?= e($app['user']['name']) ?>
            </span>
            <a
              class="dropdown-item d-flex align-items-center"
              href="#"
              data-bs-toggle="offcanvas"
              data-bs-target="#theme-switcher"
              aria-controls="theme-switcher"
            >
              <?= icon('settings', 'icon me-2') ?>
              Appearance
            </a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item d-flex align-items-center" href="#logout" aria-label="Sign out">
              <?= icon('logout', 'icon me-2') ?>
              Sign out
            </a>
          </div>
        </div>
      </div>

      <div class="collapse navbar-collapse" id="navbar-menu">
        <div class="d-flex flex-column flex-md-row flex-fill align-items-stretch align-items-md-center">
          <form class="navbar-form flex-grow-1 me-md-3" role="search">
            <div class="input-icon">
              <span class="input-icon-addon" aria-hidden="true"><?= icon('search') ?></span>
              <input type="search" class="form-control" placeholder="Search" aria-label="Search" />
            </div>
          </form>
          <?php if (!empty($topbarExtras ?? null)): ?>
            <div class="d-md-none mt-2">
              <?= renderTopbarExtras($topbarExtras) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>
  <div
    class="offcanvas offcanvas-end"
    tabindex="-1"
    id="theme-switcher"
    aria-labelledby="theme-switcher-title"
  >
    <div class="offcanvas-header">
      <h2 class="offcanvas-title" id="theme-switcher-title">Appearance</h2>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
      <p class="text-muted">Choose a theme. Your preference is saved for future visits.</p>
      <div class="list-group list-group-flush">
        <label class="list-group-item d-flex align-items-center">
          <span class="me-auto">
            <span class="fw-semibold">Auto</span>
            <small class="d-block text-secondary">Match system setting</small>
          </span>
          <input
            class="form-check-input m-0"
            type="radio"
            name="theme-switcher-mode"
            value="auto"
            data-tabler-theme-value="auto"
            aria-label="Use system theme"
          />
        </label>
        <label class="list-group-item d-flex align-items-center">
          <span class="me-auto">
            <span class="fw-semibold">Light</span>
            <small class="d-block text-secondary">Bright appearance</small>
          </span>
          <input
            class="form-check-input m-0"
            type="radio"
            name="theme-switcher-mode"
            value="light"
            data-tabler-theme-value="light"
            aria-label="Use light theme"
          />
        </label>
        <label class="list-group-item d-flex align-items-center">
          <span class="me-auto">
            <span class="fw-semibold">Dark</span>
            <small class="d-block text-secondary">Dimmed surfaces</small>
          </span>
          <input
            class="form-check-input m-0"
            type="radio"
            name="theme-switcher-mode"
            value="dark"
            data-tabler-theme-value="dark"
            aria-label="Use dark theme"
          />
        </label>
      </div>
    </div>
  </div>
<?php endif; ?>
