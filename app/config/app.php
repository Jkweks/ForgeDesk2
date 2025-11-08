<?php
return [
    'name' => getenv('APP_NAME') ?: 'ForgeDesk ERP',
    'version' => 'v0.2.6',
    'user' => [
        'email' => getenv('APP_USER_EMAIL') ?: 'inventory@forgedesk.io',
        'avatar' => getenv('APP_USER_AVATAR') ?: 'FD',
        'name' => getenv('APP_USER_NAME') ?: 'Inventory Lead',
    ],
    'branding' => [
        'tagline' => getenv('APP_TAGLINE') ?: 'Fab Operations',
    ],
    'database' => [
        'host' => getenv('DB_HOST') ?: 'postgres',
        'port' => (int) (getenv('DB_PORT') ?: 5432),
        'name' => getenv('DB_DATABASE') ?: 'forge_desk',
        'user' => getenv('DB_USERNAME') ?: 'forge',
        'password' => getenv('DB_PASSWORD') ?: 'forgepass',
    ],
];
