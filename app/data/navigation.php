<?php
return [
    'Overview' => [
        ['icon' => 'grid', 'label' => 'Dashboard', 'href' => '/index.php', 'active' => true],
        // ['icon' => 'activity', 'label' => 'Alerts'],
    ],
    'Inventory' => [
        ['icon' => 'edit', 'label' => 'Manage Inventory', 'href' => '/inventory.php'],
        ['icon' => 'repeat', 'label' => 'Inventory Transactions', 'href' => '/inventory-transactions.php'],
        ['icon' => 'checklist', 'label' => 'Cycle Counts', 'href' => '/cycle-count.php'],
        ['icon' => 'alert-triangle', 'label' => 'Location Reconciliation', 'href' => '/admin/inventory-reconciliation.php'],
    ],
    'Purchasing' => [
        ['icon' => 'truck', 'label' => 'Material Replenishment', 'href' => '/material-replenishment.php'],
        ['icon' => 'clipboard', 'label' => 'Purchase Orders', 'href' => '/purchase-orders.php'],
        ['icon' => 'inbox', 'label' => 'Receive Material', 'href' => '/receive-material.php'],
    ],
    'Maintenance' => [
        ['icon' => 'settings', 'label' => 'Maintenance Hub', 'href' => '/maintenance.php'],
    ],
    'Small Orders' => [
        ['icon' => 'search', 'label' => 'EZ Estimate Check', 'href' => '/admin/estimate-check.php'],
        ['icon' => 'clipboard', 'label' => 'Work Orders', 'badge' => 'soon'],
        ['icon' => 'calendar', 'label' => 'Job Reservations', 'href' => '/admin/job-reservations.php'],
        ['icon' => 'box', 'label' => 'Door Configurator', 'href' => '/configurator.php'],
    ],
    'Admin' => [
        ['icon' => 'users', 'label' => 'User Management', 'badge' => 'soon'],
        ['icon' => 'tag', 'label' => 'Suppliers', 'href' => '/admin/suppliers.php'],
        ['icon' => 'map', 'label' => 'Storage Locations', 'href' => '/admin/storage-locations.php'],
        ['icon' => 'database', 'label' => 'Database Health', 'href' => '/database-health.php', 'badge' => 'beta'],
        ['icon' => 'activity', 'label' => 'Dashboard Metrics', 'href' => '/admin/metrics.php'],
        // ['icon' => 'upload', 'label' => 'Data Seeding', 'href' => '/admin/import.php'],
    ],
];
