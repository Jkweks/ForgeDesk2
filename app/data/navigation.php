<?php
return [
    'Overview' => [
        ['icon' => 'grid', 'label' => 'Dashboard', 'href' => '/index.php', 'active' => true],
        ['icon' => 'activity', 'label' => 'Alerts'],
    ],
    'Inventory' => [
        ['icon' => 'edit', 'label' => 'Manage Inventory', 'href' => '/inventory.php'],
        ['icon' => 'box', 'label' => 'Stock Levels'],
        ['icon' => 'layers', 'label' => 'Kitting'],
        ['icon' => 'tag', 'label' => 'Suppliers'],
    ],
    'Roadmap' => [
        ['icon' => 'clipboard', 'label' => 'Work Orders', 'badge' => 'soon'],
        ['icon' => 'settings', 'label' => 'Door Assembly'],
    ],
    'System' => [
        ['icon' => 'database', 'label' => 'Database Health', 'badge' => 'beta'],
        ['icon' => 'upload', 'label' => 'Data Seeding', 'href' => '/admin/import.php'],
    ],
];
