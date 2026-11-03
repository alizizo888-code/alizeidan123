<?php
// TEMPLATE ONLY. Copy to <account>/oxy-master-config.php (two levels above public_html), fill in the
// values on the server, and never commit the filled copy. The web root cannot reach this file.
//
// Generate each secret with:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
return [
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=DATABASE_NAME;charset=utf8mb4',
        'user' => 'DATABASE_USER',
        'pass' => 'DATABASE_PASSWORD',
    ],
    // 64 hex characters: encrypts the per-site bridge secrets (AES-256-GCM).
    'master_key' => 'REPLACE_WITH_64_HEX_CHARS',
    // At least 32 characters: used once, for the first-run install and PIN setup.
    'setup_token' => 'REPLACE_WITH_LONG_RANDOM_STRING',
    // At least 32 characters: shared only with GitHub Actions secret CRON_TOKEN.
    'cron_token' => 'REPLACE_WITH_LONG_RANDOM_STRING',
];
