<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin_upload.php';

try {
    require dirname(__DIR__, 2) . '/app/bootstrap.php';
    require dirname(__DIR__, 2) . '/app/admin_auth.php';
} catch (RuntimeException $exception) {
    error_log('Panel de administración no disponible.');
    adminJsonResponse(503, ['message' => 'El panel no está disponible en este momento.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    adminJsonResponse(405, ['message' => 'Método no permitido.']);
}
if (!adminIsAuthenticated($config)) {
    adminJsonResponse(401, ['message' => 'Iniciá sesión para continuar.']);
}
if (!validAdminCsrfToken()) {
    adminJsonResponse(400, ['message' => 'La solicitud venció. Actualizá la página.']);
}

$galleryId = filter_var($_POST['gallery_id'] ?? null, FILTER_VALIDATE_INT);
if (!$galleryId || $galleryId < 1) {
    adminJsonResponse(400, ['message' => 'Galería no válida.']);
}

try {
    $galleryQuery = database($config)->prepare(
        'SELECT id FROM galleries WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $galleryQuery->execute(['id' => $galleryId]);
    if ($galleryQuery->fetchColumn() === false) {
        adminJsonResponse(404, ['message' => 'La galería no existe o está inactiva.']);
    }

    $legacyHashes = adminLegacyPhotoHashes(database($config), $config, $galleryId);
    $batchToken = bin2hex(random_bytes(32));
    $_SESSION['admin_upload_batch'] = [
        'token' => $batchToken,
        'gallery_id' => $galleryId,
        'legacy_hashes' => $legacyHashes,
        'attempts' => 0,
        'expires_at' => time() + 3600,
    ];

    adminJsonResponse(200, [
        'batch_token' => $batchToken,
        'max_files' => ADMIN_UPLOAD_MAX_FILES,
        'max_file_bytes' => uploadMaxBytes(),
    ]);
} catch (PDOException $exception) {
    error_log('No se pudo iniciar un lote de carga.');
    adminJsonResponse(503, ['message' => 'No se pudo iniciar la carga. Intentá nuevamente.']);
} catch (RuntimeException $exception) {
    error_log('No se pudo preparar el almacenamiento privado de una carga.');
    adminJsonResponse(500, ['message' => 'No se pudo preparar el almacenamiento privado.']);
}
