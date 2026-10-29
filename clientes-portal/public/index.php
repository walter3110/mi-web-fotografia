<?php

declare(strict_types=1);

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
} catch (RuntimeException $exception) {
    error_log('El portal de clientes no está disponible.');
    http_response_code(503);
    exit('El portal no está disponible en este momento.');
}

$errorMessage = '';
$noticeMessage = '';
$serviceUnavailable = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validCsrfToken()) {
        http_response_code(400);
        $errorMessage = 'La solicitud venció. Actualizá la página e intentá nuevamente.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $noticeMessage = 'Cerraste tu sesión.';
    } else {
        try {
            $pdo = database($config);
            $fingerprint = clientFingerprint($config);
            $limitQuery = $pdo->prepare(
                'SELECT blocked_until FROM access_attempts
                 WHERE fingerprint_hash = :fingerprint
                 LIMIT 1'
            );
            $limitQuery->execute(['fingerprint' => $fingerprint]);
            $attemptState = $limitQuery->fetch();
            $isBlocked = $attemptState !== false
                && $attemptState['blocked_until'] !== null
                && strtotime((string) $attemptState['blocked_until'] . ' UTC') > time();

            $submittedCode = $_POST['access_code'] ?? '';
            $submittedCode = is_string($submittedCode) ? trim($submittedCode) : '';
            $codeIsValid = preg_match('/^[A-Za-z0-9-]{16,128}$/', $submittedCode) === 1;

            if (!$isBlocked && $codeIsValid) {
                $codeHash = hash_hmac('sha256', $submittedCode, $config['app_key']);
                $statement = $pdo->prepare(
                    'SELECT c.id AS access_code_id, g.id AS gallery_id
                     FROM access_codes c
                     INNER JOIN galleries g ON g.id = c.gallery_id
                     WHERE c.code_hash = :code_hash
                       AND c.revoked_at IS NULL
                       AND (c.expires_at IS NULL OR c.expires_at > UTC_TIMESTAMP())
                       AND g.is_active = 1
                     LIMIT 1'
                );
                $statement->execute(['code_hash' => $codeHash]);
                $access = $statement->fetch();

                if ($access !== false) {
                    $pdo->prepare(
                        'DELETE FROM access_attempts WHERE fingerprint_hash = :fingerprint'
                    )->execute(['fingerprint' => $fingerprint]);
                    session_regenerate_id(true);
                    $_SESSION['gallery_id'] = (int) $access['gallery_id'];
                    $_SESSION['access_code_id'] = (int) $access['access_code_id'];
                    unset($_SESSION['csrf_token']);
                }
            }

            if (!isset($_SESSION['gallery_id']) && !$isBlocked) {
                $pdo->prepare(
                    'INSERT INTO access_attempts (fingerprint_hash, attempts, window_started_at, blocked_until)
                     VALUES (:fingerprint, 1, UTC_TIMESTAMP(), NULL)
                     ON DUPLICATE KEY UPDATE
                       attempts = IF(
                           window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                           1,
                           attempts + 1
                       ),
                       blocked_until = IF(
                           attempts >= 5,
                           DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                           blocked_until
                       ),
                       window_started_at = IF(
                           window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE),
                           UTC_TIMESTAMP(),
                           window_started_at
                       )'
                )->execute(['fingerprint' => $fingerprint]);
                $errorMessage = 'No pudimos validar ese código. Revisalo o consultá con Jacqueline.';
            }
        } catch (PDOException | RuntimeException $exception) {
            error_log('Falló el procesamiento del acceso de clientes.');
            http_response_code(503);
            $errorMessage = 'El portal no está disponible en este momento. Intentá nuevamente más tarde.';
            $serviceUnavailable = true;
        }
    }
}

$gallery = null;
$photos = [];
if (!$serviceUnavailable) {
    try {
        $pdo = database($config);
        $gallery = currentGallery($pdo);
        if ($gallery !== null) {
            $photosQuery = $pdo->prepare(
                'SELECT id, original_name FROM photos WHERE gallery_id = :gallery_id ORDER BY id ASC'
            );
            $photosQuery->execute(['gallery_id' => $gallery['id']]);
            $photos = $photosQuery->fetchAll();
        }
    } catch (PDOException | RuntimeException $exception) {
        error_log('No se pudo cargar la galería de clientes.');
        http_response_code(503);
        $serviceUnavailable = true;
        $errorMessage = 'El portal no está disponible en este momento. Intentá nuevamente más tarde.';
    }
}

if ($serviceUnavailable) {
    $gallery = null;
}

if ($gallery !== null && isset($pdo)) {
    ob_start();
    ?>
    <section class="gallery-shell" aria-labelledby="gallery-title">
        <?php if ($errorMessage !== ''): ?>
            <p class="error-message" role="alert"><?= escape($errorMessage) ?></p>
        <?php endif; ?>
        <?php if ($noticeMessage !== ''): ?>
            <p class="notice-message" role="status"><?= escape($noticeMessage) ?></p>
        <?php endif; ?>
        <p class="eyebrow">Tu galería privada</p>
        <h1 id="gallery-title"><?= escape((string) $gallery['title']) ?></h1>
        <?php if ($gallery['event_date'] !== null): ?>
            <p class="gallery-date">
                <?= escape((new DateTimeImmutable((string) $gallery['event_date']))->format('d/m/Y')) ?>
            </p>
        <?php endif; ?>
        <p class="intro">Tus fotografías están listas para volver a esos momentos.</p>

        <?php if ($photos === []): ?>
            <div class="empty-state">Muy pronto vas a encontrar tus fotografías acá.</div>
        <?php else: ?>
            <div class="gallery-toolbar">
                <p><?= count($photos) ?> <?= count($photos) === 1 ? 'fotografía disponible' : 'fotografías disponibles' ?></p>
            </div>
            <div class="photo-grid">
                <?php foreach ($photos as $photo): ?>
                    <article class="photo-card">
                        <div class="photo-preview">
                            <img
                                src="photo.php?id=<?= (int) $photo['id'] ?>&amp;thumbnail=1"
                                alt="<?= escape((string) $photo['original_name']) ?>"
                                loading="lazy"
                                decoding="async"
                            >
                        </div>
                        <div class="photo-card-info">
                            <p class="photo-name" title="<?= escape((string) $photo['original_name']) ?>">
                                <?= escape((string) $photo['original_name']) ?>
                            </p>
                            <a class="photo-download-button" href="photo.php?id=<?= (int) $photo['id'] ?>">
                                Descargar foto <span aria-hidden="true">↓</span>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="logout-form">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="text-button" type="submit">Cerrar sesión</button>
        </form>
    </section>
    <?php
    renderPage('Tu galería', (string) ob_get_clean());
    exit;
}

ob_start();
?>
<section class="login-shell" aria-labelledby="login-title">
    <div class="login-card">
        <div class="card-flourish" aria-hidden="true"><span></span> J <span></span></div>
        <p class="eyebrow">Un recuerdo solo tuyo</p>
        <h1 id="login-title">Tu historia, en imágenes</h1>
        <p class="intro">Ingresá el código que recibiste para descubrir y descargar tu galería.</p>

        <?php if ($noticeMessage !== ''): ?>
            <p class="notice-message" role="status"><?= escape($noticeMessage) ?></p>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <p class="error-message" role="alert"><?= escape($errorMessage) ?></p>
        <?php endif; ?>

        <form method="post" class="access-form">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <label for="access-code">Código de acceso</label>
            <input
                id="access-code"
                name="access_code"
                type="text"
                inputmode="text"
                autocomplete="one-time-code"
                minlength="16"
                maxlength="128"
                placeholder="Ingresá tu código personal"
                required
            >
            <button type="submit">Ver mi galería <span aria-hidden="true">→</span></button>
        </form>
        <p class="help-note">¿Necesitás ayuda? Comunicate directamente con Jacqueline.</p>
    </div>
    <p class="privacy-note"><span aria-hidden="true">✦</span> Un espacio privado, creado para vos.</p>
</section>
<?php
renderPage('Acceso a clientes', (string) ob_get_clean());
