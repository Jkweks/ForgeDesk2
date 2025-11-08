<?php
declare(strict_types=1);

foreach ($nav as &$groupItems) {
    foreach ($groupItems as &$item) {
        if (($item['label'] ?? '') === 'Database Health') {
            $item['badge'] = $dbError === null ? 'Live' : 'Error';
            $item['badge_class'] = $dbError === null ? 'success' : 'danger';
        }
    }
}



/** @var array<string,mixed> $app */
/** @var array<string,array<int,array<string,mixed>>> $nav */
/** @var string|null $sidebarAriaLabel */
$sidebarAriaLabel = $sidebarAriaLabel ?? null;
?>
<aside class="sidebar"<?= $sidebarAriaLabel !== null ? ' aria-label="' . e($sidebarAriaLabel) . '"' : '' ?>>
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
<?php unset($sidebarAriaLabel); ?>
