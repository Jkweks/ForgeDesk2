<?php
return [
    'Overview' => [
        ['icon' => 'grid', 'label' => 'Dashboard', 'href' => '/index.php', 'active' => true],
        // ['icon' => 'activity', 'label' => 'Alerts'],
    ],
    'Inventory' => [
        ['icon' => 'edit', 'label' => 'Manage Inventory', 'href' => '/inventory.php'],
        ['icon' => 'box', 'label' => 'Door Configurator', 'href' => '/configurator.php'],
        ['icon' => 'repeat', 'label' => 'Inventory Transactions', 'href' => '/inventory-transactions.php'],
        ['icon' => 'checklist', 'label' => 'Cycle Counts', 'href' => '/cycle-count.php'],
        ['icon' => 'map', 'label' => 'Storage Locations', 'href' => '/admin/storage-locations.php'],
        ['icon' => 'search', 'label' => 'EZ Estimate Check', 'href' => '/admin/estimate-check.php'],
        ['icon' => 'calendar', 'label' => 'Job Reservations', 'href' => '/admin/job-reservations.php'],
        ['icon' => 'truck', 'label' => 'Material Replenishment', 'href' => '/material-replenishment.php'],
        ['icon' => 'clipboard', 'label' => 'Purchase Orders', 'href' => '/purchase-orders.php'],
        ['icon' => 'inbox', 'label' => 'Receive Material', 'href' => '/receive-material.php'],
        // ['icon' => 'box', 'label' => 'Stock Levels', 'badge' => 'soon'],
        // ['icon' => 'layers', 'label' => 'Kitting', 'badge' => 'soon'],
        ['icon' => 'tag', 'label' => 'Suppliers', 'href' => '/admin/suppliers.php'],
    ],
    'Maintenance' => [
        ['icon' => 'settings', 'label' => 'Maintenance Hub', 'href' => '/maintenance.php'],
    ],
    'Roadmap' => [
        ['icon' => 'clipboard', 'label' => 'Work Orders', 'badge' => 'soon'],
        ['icon' => 'settings', 'label' => 'Door Assembly', 'badge' => 'soon'],
    ],
    'System' => [
        ['icon' => 'database', 'label' => 'Database Health', 'badge' => 'beta'],
        // ['icon' => 'upload', 'label' => 'Data Seeding', 'href' => '/admin/import.php'],
    ],
];
