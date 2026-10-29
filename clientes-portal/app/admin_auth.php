<?php

declare(strict_types=1);

function adminIsConfigured(array $config): bool
{
    $username = $config['admin']['username'] ?? null;
    $passwordHash = $config['admin']['password_hash'] ?? null;

    return is_string($username)
        && $username !== ''
        && is_string($passwordHash)
        && $passwordHash !== ''
        && password_get_info($passwordHash)['algo'] !== null;
}

function adminIsAuthenticated(array $config): bool
{
    return ($_SESSION['admin_authenticated'] ?? false) === true
        && isset($_SESSION['admin_username'], $_SESSION['admin_authenticated_at'])
        && adminIsConfigured($config)
        && hash_equals($config['admin']['username'], (string) $_SESSION['admin_username'])
        && time() - (int) $_SESSION['admin_authenticated_at'] < 28800;
}

function requireAdmin(array $config): void
{
    if (!adminIsAuthenticated($config)) {
        header('Location: login.php', true, 303);
        exit;
    }
}

function adminCsrfToken(): string
{
    if (!isset($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['admin_csrf_token'];
}

function validAdminCsrfToken(): bool
{
    $submittedToken = $_POST['csrf_token'] ?? '';

    return is_string($submittedToken)
        && isset($_SESSION['admin_csrf_token'])
        && hash_equals($_SESSION['admin_csrf_token'], $submittedToken);
}

function adminRateLimitFingerprint(array $config): string
{
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash_hmac('sha256', 'admin:' . $address, $config['app_key']);
}

function adminRender(string $title, string $content): void
{
    $adminPageTitle = escape($title);
    $adminPageContent = $content;
    require __DIR__ . '/views/admin_layout.php';
}

function adminFlash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function takeAdminFlash(): ?array
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);

    return is_array($flash) ? $flash : null;
}
