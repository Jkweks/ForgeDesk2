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

  <button class="user" type="button">
    <span class="user-avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
    <span class="user-email"><?= e($app['user']['email']) ?></span>
    <span aria-hidden="true"><?= icon('chev') ?></span>
  </button>
</header>
