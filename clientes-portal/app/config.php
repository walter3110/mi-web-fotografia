<?php

declare(strict_types=1);

$localConfigPath = dirname(__DIR__) . '/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];

$environmentValue = static function (string $name, mixed $fallback = null): mixed {
    $value = getenv($name);
    return $value === false ? $fallback : $value;
};

return [
    'db' => [
        'host' => $environmentValue('DB_HOST', $localConfig['db']['host'] ?? '127.0.0.1'),
        'port' => (int) $environmentValue('DB_PORT', $localConfig['db']['port'] ?? 3306),
        'name' => $environmentValue('DB_NAME', $localConfig['db']['name'] ?? ''),
        'user' => $environmentValue('DB_USER', $localConfig['db']['user'] ?? ''),
        'password' => $environmentValue('DB_PASSWORD', $localConfig['db']['password'] ?? ''),
        'charset' => $environmentValue('DB_CHARSET', $localConfig['db']['charset'] ?? 'utf8mb4'),
    ],
    'app_key' => $environmentValue('APP_KEY', $localConfig['app_key'] ?? ''),
    'admin' => [
        'username' => $environmentValue(
            'ADMIN_USERNAME',
            $localConfig['admin']['username'] ?? ''
        ),
        'password_hash' => $environmentValue(
            'ADMIN_PASSWORD_HASH',
            $localConfig['admin']['password_hash'] ?? ''
        ),
    ],
    'storage_path' => $environmentValue(
        'PHOTO_STORAGE_PATH',
        $localConfig['storage_path'] ?? dirname(__DIR__) . '/storage/originals'
    ),
];
