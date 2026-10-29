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

$postLimit = parsePhpSize(ini_get('post_max_size'));
$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($postLimit > 0 && $contentLength !== false && $contentLength > $postLimit) {
    adminJsonResponse(
        413,
        ['message' => 'La solicitud supera post_max_size de PHP. Reducí el tamaño máximo configurado y reintentá.']
    );
}
if (!validAdminCsrfToken()) {
    adminJsonResponse(400, ['message' => 'La solicitud venció. Actualizá la página.']);
}

$batch = $_SESSION['admin_upload_batch'] ?? null;
$batchToken = $_POST['batch_token'] ?? '';
$galleryId = filter_var($_POST['gallery_id'] ?? null, FILTER_VALIDATE_INT);
if (
    !is_array($batch)
    || !is_string($batchToken)
    || !isset(
        $batch['token'],
        $batch['gallery_id'],
        $batch['legacy_hashes'],
        $batch['attempts'],
        $batch['expires_at']
    )
    || !is_array($batch['legacy_hashes'])
    || !hash_equals((string) $batch['token'], $batchToken)
    || !$galleryId
    || (int) $batch['gallery_id'] !== $galleryId
    || (int) $batch['expires_at'] < time()
) {
    adminJsonResponse(403, ['message' => 'El lote de carga venció o no es válido. Iniciá uno nuevo.']);
}
if ((int) $batch['attempts'] >= ADMIN_UPLOAD_MAX_FILES) {
    adminJsonResponse(
        429,
        ['message' => 'El lote alcanzó el máximo de ' . ADMIN_UPLOAD_MAX_FILES . ' archivos.']
    );
}

$_SESSION['admin_upload_batch']['attempts']++;

if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
    adminJsonResponse(400, ['message' => 'PHP no recibió el archivo. Revisá los límites de carga configurados.']);
}

try {
    $pdo = database($config);
    $galleryQuery = $pdo->prepare(
        'SELECT id FROM galleries WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $galleryQuery->execute(['id' => $galleryId]);
    if ($galleryQuery->fetchColumn() === false) {
        adminJsonResponse(404, ['message' => 'La galería no existe o está inactiva.']);
    }

    $result = storeAdminUpload(
        $pdo,
        $config,
        $galleryId,
        $_FILES['photo'],
        $batch['legacy_hashes']
    );
    adminJsonResponse(200, $result);
} catch (AdminUploadException $exception) {
    adminJsonResponse($exception->httpStatus, ['message' => $exception->getMessage()]);
} catch (PDOException $exception) {
    error_log('No se pudo registrar una fotografía cargada.');
    adminJsonResponse(503, ['message' => 'No se pudo registrar el archivo. Podés reintentar este elemento.']);
} catch (RuntimeException $exception) {
    error_log('Falló el almacenamiento de una fotografía.');
    adminJsonResponse(500, ['message' => 'No se pudo guardar el archivo en el almacenamiento privado.']);
}
