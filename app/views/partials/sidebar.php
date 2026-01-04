<?php
declare(strict_types=1);

$dbError = $dbError ?? null;
$forceLegacySidebar = $forceLegacySidebar ?? false;

$isAdminRoute = isset($_SERVER['SCRIPT_NAME']) && strpos((string) $_SERVER['SCRIPT_NAME'], '/admin/') !== false;

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        if (($item['label'] ?? '') === 'Database Health') {
            $item['badge'] = $dbError === null ? 'Live' : 'Error';
            $item['badge_class'] = $dbError === null ? 'success' : 'danger';
        }
    }
}
unset($groupItems, $item);

$seenDatabaseHealth = false;
foreach ($nav as &$groupItems) {
    $filtered = [];
    foreach ($groupItems as $item) {
        if (($item['label'] ?? '') === 'Database Health') {
            if ($seenDatabaseHealth) {
                continue;
            }
            $seenDatabaseHealth = true;
        }

        $filtered[] = $item;
    }

    $groupItems = $filtered;
}
unset($groupItems);




/** @var array<string,mixed> $app */
/** @var array<string,array<int,array<string,mixed>>> $nav */
/** @var string|null $sidebarAriaLabel */
$sidebarAriaLabel = $sidebarAriaLabel ?? null;
$useLegacySidebar = $forceLegacySidebar || $isAdminRoute;
?>
<?php if ($useLegacySidebar): ?>
  <aside
    class="sidebar"
    id="app-sidebar"
    tabindex="-1"
    <?= $sidebarAriaLabel !== null ? ' aria-label="' . e($sidebarAriaLabel) . '"' : '' ?>
  >
    <div class="brand">
      <span class="brand-badge"><?= e($app['user']['avatar']) ?></span>
      <div>
        <strong><?= e($app['name']) ?></strong>
        <div class="small"><?= e($app['branding']['tagline']) ?></div>
      </div>
      <span class="brand-version"><?= e($app['version']) ?></span>
    </div>
    <?php foreach ($nav as $group => $items): ?>
      <nav class="nav-group">
        <h6><?= e($group) ?></h6>
        <?php foreach ($items as $item): ?>
          <?php $isActive = $item['active'] ?? false; ?>
          <a class="nav-item<?= $isActive ? ' active' : '' ?>" href="<?= e(nav_href($item)) ?>">
            <span aria-hidden="true"><?= icon($item['icon'] ?? 'grid') ?></span>
            <span><?= e($item['label']) ?></span>
            <?php if (!empty($item['badge'])): ?>
              <?php $badgeClass = $item['badge_class'] ?? ''; ?>
              <span class="badge<?= $badgeClass !== '' ? ' ' . e($badgeClass) : '' ?>"><?= e($item['badge']) ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endforeach; ?>
  </aside>
<?php else: ?>
  <aside
    class="navbar navbar-vertical navbar-expand-lg"
    id="app-sidebar"
    tabindex="-1"
    <?= $sidebarAriaLabel !== null ? ' aria-label="' . e($sidebarAriaLabel) . '"' : '' ?>
  >
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
      <h1 class="navbar-brand navbar-brand-autodark">
        <span class="avatar" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
        <div class="ms-2">
          <span class="fw-semibold d-block"><?= e($app['name']) ?></span>
          <small class="text-muted"><?= e($app['branding']['tagline']) ?></small>
        </div>
        <span class="badge bg-blue-lt ms-auto"><?= e($app['version']) ?></span>
      </h1>
      <div class="collapse navbar-collapse" id="sidebar-menu">
        <ul class="navbar-nav pt-lg-3">
          <?php foreach ($nav as $group => $items): ?>
            <li class="nav-item nav-section">
              <div class="nav-link disabled text-uppercase text-muted" aria-disabled="true"><?= e($group) ?></div>
            </li>
            <?php foreach ($items as $item): ?>
              <?php
              $isActive = $item['active'] ?? false;
              $badgeClass = $item['badge_class'] ?? '';
              if ($badgeClass !== '' && strpos($badgeClass, 'bg-') !== 0) {
                  $badgeClass = 'bg-' . $badgeClass;
              }
              ?>
              <li class="nav-item">
                <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= e(nav_href($item)) ?>">
                  <span class="nav-link-icon" aria-hidden="true"><?= icon($item['icon'] ?? 'grid') ?></span>
                  <span class="nav-link-title"><?= e($item['label']) ?></span>
                  <?php if (!empty($item['badge'])): ?>
                    <span class="badge ms-auto<?= $badgeClass !== '' ? ' ' . e($badgeClass) : '' ?>"><?= e($item['badge']) ?></span>
                  <?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </aside>
<?php endif; ?>
<div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>
<?php unset($sidebarAriaLabel); ?>
