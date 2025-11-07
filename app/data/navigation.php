<?php
return [
    'Overview' => [
        ['icon' => 'grid', 'label' => 'Dashboard', 'href' => '/index.php', 'active' => true],
        ['icon' => 'activity', 'label' => 'Alerts'],
    ],
    'Inventory' => [
        ['icon' => 'edit', 'label' => 'Manage Inventory', 'href' => '/inventory.php'],
        ['icon' => 'checklist', 'label' => 'Cycle Counts', 'href' => '/cycle-count.php'],
        ['icon' => 'search', 'label' => 'EZ Estimate Check', 'href' => '/admin/estimate-check.php'],
        ['icon' => 'calendar', 'label' => 'Job Reservations', 'href' => '/admin/job-reservations.php'],
        ['icon' => 'box', 'label' => 'Stock Levels', 'badge' => 'soon'],
        ['icon' => 'layers', 'label' => 'Kitting', 'badge' => 'soon'],
        ['icon' => 'tag', 'label' => 'Suppliers'],
    ],
    'Roadmap' => [
        ['icon' => 'clipboard', 'label' => 'Work Orders', 'badge' => 'soon'],
        ['icon' => 'settings', 'label' => 'Door Assembly', 'badge' => 'soon'],
    ],
    'System' => [
        ['icon' => 'database', 'label' => 'Database Health', 'badge' => 'beta'],
        ['icon' => 'upload', 'label' => 'Data Seeding', 'href' => '/admin/import.php'],
    ],
];
