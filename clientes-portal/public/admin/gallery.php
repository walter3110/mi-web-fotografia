<?php

declare(strict_types=1);

try {
    require dirname(__DIR__, 2) . '/app/bootstrap.php';
    require dirname(__DIR__, 2) . '/app/admin_auth.php';
    require dirname(__DIR__, 2) . '/app/admin_upload.php';
} catch (RuntimeException $exception) {
    error_log('Panel de administración no disponible.');
    http_response_code(503);
    exit('El panel no está disponible en este momento.');
}

requireAdmin($config);
$galleryId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$galleryId || $galleryId < 1) {
    http_response_code(404);
    exit('Galería no encontrada.');
}

try {
    $pdo = database($config);
} catch (PDOException $exception) {
    error_log('No se pudo conectar a la base para el panel.');
    http_response_code(503);
    exit('No se pudo cargar la galería en este momento.');
}
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validAdminCsrfToken()) {
        http_response_code(400);
        $errorMessage = 'La solicitud venció. Actualizá la página e intentá nuevamente.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'create_code') {
                $galleryCheck = $pdo->prepare(
                    'SELECT id FROM galleries WHERE id = :id AND is_active = 1 LIMIT 1'
                );
                $galleryCheck->execute(['id' => $galleryId]);
                if ($galleryCheck->fetchColumn() === false) {
                    $errorMessage = 'No se pueden generar códigos para una galería inactiva.';
                } else {
                    $code = strtoupper(bin2hex(random_bytes(16)));
                    $codeHash = hash_hmac('sha256', $code, $config['app_key']);
                    $insert = $pdo->prepare(
                        'INSERT INTO access_codes (gallery_id, code_hash)
                         VALUES (:gallery_id, :code_hash)'
                    );
                    $insert->execute([
                        'gallery_id' => $galleryId,
                        'code_hash' => $codeHash,
                    ]);
                    $_SESSION['admin_new_code'] = [
                        'gallery_id' => $galleryId,
                        'code' => $code,
                    ];
                    adminFlash('success', 'Código generado. Copialo ahora: no volverá a mostrarse.');
                    header('Location: gallery.php?id=' . $galleryId, true, 303);
                    exit;
                }
            } elseif ($action === 'revoke_code') {
                $codeId = filter_var($_POST['code_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$codeId || $codeId < 1) {
                    $errorMessage = 'Código inválido.';
                } else {
                    $revoke = $pdo->prepare(
                        'UPDATE access_codes
                         SET revoked_at = UTC_TIMESTAMP()
                         WHERE id = :code_id
                           AND gallery_id = :gallery_id
                           AND revoked_at IS NULL'
                    );
                    $revoke->execute([
                        'code_id' => $codeId,
                        'gallery_id' => $galleryId,
                    ]);
                    adminFlash('success', 'El código fue revocado.');
                    header('Location: gallery.php?id=' . $galleryId, true, 303);
                    exit;
                }
            } else {
                http_response_code(400);
                $errorMessage = 'Operación no válida.';
            }
        } catch (PDOException $exception) {
            error_log('No se pudo actualizar un código de acceso.');
            http_response_code(503);
            $errorMessage = 'No se pudo completar la operación. Intentá nuevamente.';
        }
    }
}

try {
    $galleryQuery = $pdo->prepare(
        'SELECT id, title, event_date, is_active, created_at
         FROM galleries WHERE id = :id LIMIT 1'
    );
    $galleryQuery->execute(['id' => $galleryId]);
    $gallery = $galleryQuery->fetch();
    if ($gallery === false) {
        http_response_code(404);
        exit('Galería no encontrada.');
    }

    $photosQuery = $pdo->prepare(
        'SELECT id, original_name, created_at FROM photos
         WHERE gallery_id = :gallery_id ORDER BY id DESC LIMIT 100'
    );
    $photosQuery->execute(['gallery_id' => $galleryId]);
    $photos = $photosQuery->fetchAll();

    $codesQuery = $pdo->prepare(
        'SELECT id, created_at, expires_at, revoked_at
         FROM access_codes WHERE gallery_id = :gallery_id ORDER BY id DESC'
    );
    $codesQuery->execute(['gallery_id' => $galleryId]);
    $codes = $codesQuery->fetchAll();

    $photoCountQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM photos WHERE gallery_id = :gallery_id'
    );
    $photoCountQuery->execute(['gallery_id' => $galleryId]);
    $photoCount = (int) $photoCountQuery->fetchColumn();
} catch (PDOException $exception) {
    error_log('No se pudo consultar el detalle de una galería.');
    http_response_code(503);
    exit('No se pudo cargar la galería en este momento.');
}

$flash = takeAdminFlash();
$newCode = $_SESSION['admin_new_code'] ?? null;
unset($_SESSION['admin_new_code']);
if (!is_array($newCode) || (int) ($newCode['gallery_id'] ?? 0) !== $galleryId) {
    $newCode = null;
}

ob_start();
?>
<p class="admin-breadcrumb"><a href="index.php">← Volver a galerías</a></p>
<section class="admin-heading gallery-detail-heading">
    <div>
        <span class="admin-kicker">Detalle del evento</span>
        <h1><?= escape((string) $gallery['title']) ?></h1>
        <p>
            <?= $gallery['event_date'] === null
                ? 'Sin fecha registrada'
                : escape((new DateTimeImmutable((string) $gallery['event_date']))->format('d/m/Y')) ?>
            · <?= $photoCount ?> <?= $photoCount === 1 ? 'fotografía' : 'fotografías' ?>
            · <span class="status-label">
                <?= (int) $gallery['is_active'] === 1 ? 'Galería activa' : 'Galería inactiva' ?>
            </span>
        </p>
    </div>
    <span class="admin-summary">
        <span><?= $photoCount ?></span>
        <small>originales</small>
    </span>
</section>

<?php if ($flash !== null): ?>
    <p class="admin-alert admin-alert-<?= escape((string) $flash['type']) ?>" role="status">
        <?= escape((string) $flash['message']) ?>
    </p>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
    <p class="admin-alert admin-alert-error" role="alert"><?= escape($errorMessage) ?></p>
<?php endif; ?>
<?php if ($newCode !== null): ?>
    <section class="admin-panel generated-code" aria-labelledby="generated-code-title">
        <div>
            <span class="admin-kicker">Código privado · mostrar una sola vez</span>
            <h2 id="generated-code-title">Compartilo con tu clienta</h2>
            <p>Guardalo ahora. Por seguridad, el código original no se almacena ni se vuelve a mostrar.</p>
        </div>
        <div class="code-copy-row">
            <code id="new-access-code"><?= escape((string) $newCode['code']) ?></code>
            <button type="button" class="admin-button secondary" data-copy-code>Copiar código</button>
        </div>
    </section>
<?php endif; ?>

<div class="admin-dashboard-grid detail-grid">
    <section class="admin-panel">
        <div class="panel-heading">
            <div>
                <span class="admin-kicker">Archivos originales</span>
                <h2>Fotografías</h2>
            </div>
        </div>
        <p class="panel-description">
            Seleccioná una carpeta completa o varios archivos. Los originales se conservan sin compresión.
        </p>

        <?php if ((int) $gallery['is_active'] === 1): ?>
            <div class="upload-area"
                data-upload-url="upload.php"
                data-start-url="upload-start.php"
                data-gallery-id="<?= $galleryId ?>"
                data-max-file-bytes="<?= uploadMaxBytes() ?>"
                data-max-files="<?= ADMIN_UPLOAD_MAX_FILES ?>">
                <label for="photo-files" class="upload-picker">
                    <span class="upload-icon" aria-hidden="true">↑</span>
                    <strong data-picker-title>Elegir una carpeta de fotografías</strong>
                    <small data-picker-help>También podés seleccionar archivos de forma individual.</small>
                </label>
                <input
                    id="photo-files"
                    class="visually-hidden"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                    multiple
                    webkitdirectory
                    directory
                >

                <div class="selection-summary" aria-live="polite">
                    <span><strong data-file-count>0</strong> archivos seleccionados</span>
                    <span data-total-size>0 B</span>
                </div>
                <ul class="selected-files" data-file-list aria-label="Archivos seleccionados"></ul>
                <p class="upload-note">
                    Máximo <?= number_format(uploadMaxBytes() / 1048576, 0) ?> MB por archivo y
                    <?= ADMIN_UPLOAD_MAX_FILES ?> archivos por lote. Formatos JPEG, PNG y WebP.
                </p>
                <button type="button" class="admin-button" data-upload-button disabled>
                    Subir fotografías seleccionadas
                </button>
                <div class="upload-progress" data-progress-container hidden>
                    <div class="progress-label">
                        <span data-progress-message>Preparando carga…</span>
                        <span data-progress-percent>0%</span>
                    </div>
                    <progress max="100" value="0" data-progress-bar aria-label="Progreso total de la carga">0%</progress>
                </div>
                <div class="upload-results" data-upload-results aria-live="polite"></div>
                <input type="hidden" data-csrf-token value="<?= escape(adminCsrfToken()) ?>">
            </div>
        <?php else: ?>
            <p class="admin-alert">La galería está inactiva; no se pueden cargar fotografías.</p>
        <?php endif; ?>
    </section>

    <section class="admin-panel codes-panel">
        <div class="panel-heading">
            <div>
                <span class="admin-kicker">Acceso de la clienta</span>
                <h2>Códigos privados</h2>
            </div>
        </div>
        <?php if ((int) $gallery['is_active'] === 1): ?>
            <form method="post" class="code-create-form">
                <input type="hidden" name="csrf_token" value="<?= escape(adminCsrfToken()) ?>">
                <input type="hidden" name="action" value="create_code">
                <button type="submit" class="admin-button secondary">Generar nuevo código</button>
            </form>
        <?php endif; ?>
        <?php if ($codes === []): ?>
            <p class="empty-inline">Todavía no hay códigos asociados a esta galería.</p>
        <?php else: ?>
            <ul class="code-list">
                <?php foreach ($codes as $code): ?>
                    <?php
                    $isCodeActive = $code['revoked_at'] === null
                        && ($code['expires_at'] === null
                            || strtotime((string) $code['expires_at'] . ' UTC') > time());
                    ?>
                    <li>
                        <span>
                            <strong><?= $isCodeActive ? 'Vigente' : 'Revocado o vencido' ?></strong>
                            <small>Creado <?= escape((new DateTimeImmutable((string) $code['created_at']))->format('d/m/Y')) ?></small>
                        </span>
                        <?php if ($isCodeActive): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= escape(adminCsrfToken()) ?>">
                                <input type="hidden" name="action" value="revoke_code">
                                <input type="hidden" name="code_id" value="<?= (int) $code['id'] ?>">
                                <button class="text-button danger-button" type="submit">Revocar</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<section class="admin-panel recent-photos">
    <div class="panel-heading">
        <div>
            <span class="admin-kicker">Registro</span>
            <h2>Fotografías recientes</h2>
        </div>
        <span class="muted-count"><?= $photoCount ?> en total</span>
    </div>
    <?php if ($photos === []): ?>
        <p class="empty-inline">Las fotografías cargadas aparecerán acá.</p>
    <?php else: ?>
        <ul class="photo-admin-list">
            <?php foreach ($photos as $photo): ?>
                <li>
                    <span class="photo-file-icon" aria-hidden="true">▧</span>
                    <span class="photo-admin-name"><?= escape((string) $photo['original_name']) ?></span>
                    <time datetime="<?= escape((string) $photo['created_at']) ?>">
                        <?= escape((new DateTimeImmutable((string) $photo['created_at']))->format('d/m/Y H:i')) ?>
                    </time>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($photoCount > count($photos)): ?>
            <p class="muted-count">Se muestran las 100 más recientes.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
<script src="assets/admin.js" defer></script>
<?php
adminRender('Detalle de galería', (string) ob_get_clean());
