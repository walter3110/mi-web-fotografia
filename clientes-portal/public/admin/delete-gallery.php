<?php

declare(strict_types=1);

try {
    require dirname(__DIR__, 2) . '/app/bootstrap.php';
    require dirname(__DIR__, 2) . '/app/admin_auth.php';
    require dirname(__DIR__, 2) . '/app/admin_gallery_deletion.php';
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

$galleryId = filter_var($_POST['gallery_id'] ?? null, FILTER_VALIDATE_INT);
if (!$galleryId || $galleryId < 1) {
    http_response_code(400);
    exit('El evento seleccionado no es válido.');
}

try {
    deleteAdminGallery(database($config), $config, $galleryId);
    adminFlash('success', 'El evento, sus fotografías, miniaturas y códigos fueron eliminados.');
} catch (RuntimeException $exception) {
    adminFlash('error', $exception->getMessage());
} catch (PDOException $exception) {
    error_log('No se pudo completar una eliminación de galería en la base de datos.');
    adminFlash('error', 'No se pudo eliminar el evento de forma segura; el evento sigue intacto.');
}

header('Location: index.php', true, 303);
exit;
