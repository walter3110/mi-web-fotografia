<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

if (strlen((string) $config['app_key']) < 32) {
    throw new RuntimeException('APP_KEY debe tener al menos 32 caracteres.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '1800');
    session_set_cookie_params([
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');

function database(array $config): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $db = $config['db'];
    if ($db['name'] === '' || $db['user'] === '') {
        throw new RuntimeException('Falta configurar la conexión a MySQL.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $connection = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $connection;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validCsrfToken(): bool
{
    $submittedToken = $_POST['csrf_token'] ?? '';
    return is_string($submittedToken)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

function clientFingerprint(array $config): string
{
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash_hmac('sha256', $address, $config['app_key']);
}

function currentGallery(PDO $pdo): ?array
{
    $galleryId = filter_var($_SESSION['gallery_id'] ?? null, FILTER_VALIDATE_INT);
    $accessCodeId = filter_var($_SESSION['access_code_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$galleryId || !$accessCodeId) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT g.id, g.title, g.event_date
         FROM galleries g
         INNER JOIN access_codes c ON c.gallery_id = g.id
         WHERE g.id = :gallery_id
           AND c.id = :access_code_id
           AND g.is_active = 1
           AND c.revoked_at IS NULL
           AND (c.expires_at IS NULL OR c.expires_at > UTC_TIMESTAMP())
         LIMIT 1'
    );
    $statement->execute([
        'gallery_id' => $galleryId,
        'access_code_id' => $accessCodeId,
    ]);

    $gallery = $statement->fetch();
    if ($gallery === false) {
        unset($_SESSION['gallery_id'], $_SESSION['access_code_id']);
    }

    return $gallery === false ? null : $gallery;
}

function renderPage(string $title, string $content): void
{
    $pageTitle = escape($title);
  require __DIR__ . '/views/layout.php';
}
