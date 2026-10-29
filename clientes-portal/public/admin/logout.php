<?php

declare(strict_types=1);

try {
    require dirname(__DIR__, 2) . '/app/bootstrap.php';
    require dirname(__DIR__, 2) . '/app/admin_auth.php';
} catch (RuntimeException $exception) {
    error_log('Panel de administración no disponible.');
    http_response_code(503);
    exit('El panel no está disponible en este momento.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

requireAdmin($config);
if (!validAdminCsrfToken()) {
    http_response_code(400);
    exit('La solicitud venció. Actualizá la página e intentá nuevamente.');
}

unset(
    $_SESSION['admin_authenticated'],
    $_SESSION['admin_username'],
    $_SESSION['admin_authenticated_at'],
    $_SESSION['admin_csrf_token'],
    $_SESSION['admin_upload_batch'],
    $_SESSION['admin_new_code'],
    $_SESSION['admin_flash']
);
session_regenerate_id(true);

header('Location: login.php', true, 303);
exit;
