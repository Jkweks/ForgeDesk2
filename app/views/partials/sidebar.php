<?php
declare(strict_types=1);

$dbError = $dbError ?? null;

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
?>
<aside
  class="navbar navbar-vertical navbar-expand-lg"
  id="app-sidebar"
  tabindex="-1"
  data-bs-theme="dark"
  <?= $sidebarAriaLabel !== null ? ' aria-label="' . e($sidebarAriaLabel) . '"' : '' ?>
>
  <div class="container-fluid">
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
    <h1 class="navbar-brand navbar-brand-autodark d-flex align-items-center gap-2">
      <span class="avatar avatar-sm"><?= e($app['user']['avatar']) ?></span>
      <span class="navbar-brand-text"><?= e($app['name']) ?></span>
      <span class="badge bg-primary ms-auto"><?= e($app['version']) ?></span>
    </h1>
    <div class="d-flex align-items-center text-secondary small mb-3">
      <div class="me-3 text-uppercase fw-semibold"><?= e($app['branding']['tagline']) ?></div>
      <div class="ms-auto d-flex align-items-center gap-2">
        <span class="avatar avatar-xs" aria-hidden="true"><?= e($app['user']['avatar']) ?></span>
        <span class="text-reset"><?= e($app['user']['name']) ?></span>
      </div>
    </div>
    <div class="collapse navbar-collapse" id="sidebar-menu">
      <ul class="navbar-nav pt-lg-3">
        <?php foreach ($nav as $group => $items): ?>
          <li class="nav-item text-secondary text-uppercase fw-bold small px-3 mt-3"><?= e($group) ?></li>
          <?php foreach ($items as $item): ?>
            <?php $isActive = $item['active'] ?? false; ?>
            <li class="nav-item">
              <a class="nav-link<?= $isActive ? ' active' : '' ?>" href="<?= e(nav_href($item)) ?>">
                <span class="nav-link-icon" aria-hidden="true"><?= icon($item['icon'] ?? 'grid') ?></span>
                <span class="nav-link-title"><?= e($item['label']) ?></span>
                <?php if (!empty($item['badge'])): ?>
                  <?php $badgeClass = $item['badge_class'] ?? ''; ?>
                  <span class="badge ms-auto<?= $badgeClass !== '' ? ' bg-' . e($badgeClass) : '' ?>"><?= e($item['badge']) ?></span>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</aside>
<?php unset($sidebarAriaLabel); ?>
