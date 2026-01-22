<?php
return [
    'name' => getenv('APP_NAME') ?: 'ForgeDesk ERP',
    'version' => 'v0.4.5',
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
        'port' => (int) (getenv('DB_PORT') ?: 5433),
        'name' => getenv('DB_DATABASE') ?: 'forgedesk',
        'user' => getenv('DB_USERNAME') ?: 'forge_dev',
        'password' => getenv('DB_PASSWORD') ?: 'forgepass_dev',
    ],
];
