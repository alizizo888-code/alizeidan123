<?php
// Test-only configuration: in-memory SQLite and throwaway keys. Never used on the server.
return [
    'db' => ['dsn' => 'sqlite::memory:'],
    'master_key' => str_repeat('ab', 32),
    'setup_token' => str_repeat('s', 40),
    'cron_token' => str_repeat('c', 40),
];
