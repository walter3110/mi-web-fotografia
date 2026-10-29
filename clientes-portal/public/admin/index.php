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

requireAdmin($config);

$titleValue = '';
$dateValue = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validAdminCsrfToken()) {
        http_response_code(400);
        $errorMessage = 'La solicitud venció. Actualizá la página e intentá nuevamente.';
    } else {
        $submittedTitle = $_POST['title'] ?? '';
        $submittedDate = $_POST['event_date'] ?? '';
        $titleValue = is_string($submittedTitle) ? trim($submittedTitle) : '';
        $dateValue = is_string($submittedDate) ? trim($submittedDate) : '';
        $validDate = $dateValue === '';

        if ($dateValue !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
            $validDate = $date !== false && $date->format('Y-m-d') === $dateValue;
        }

        if (
            !is_string($submittedTitle)
            || !is_string($submittedDate)
            || $titleValue === ''
            || mb_strlen($titleValue, 'UTF-8') > 180
        ) {
            $errorMessage = 'El nombre del evento es obligatorio y no puede superar 180 caracteres.';
        } elseif (!$validDate) {
            $errorMessage = 'Ingresá una fecha válida.';
        } else {
            try {
                $statement = database($config)->prepare(
                    'INSERT INTO galleries (title, event_date) VALUES (:title, :event_date)'
                );
                $statement->execute([
                    'title' => $titleValue,
                    'event_date' => $dateValue === '' ? null : $dateValue,
                ]);
                $galleryId = (int) database($config)->lastInsertId();
                adminFlash('success', 'La galería se creó correctamente.');
                header('Location: gallery.php?id=' . $galleryId, true, 303);
                exit;
            } catch (PDOException $exception) {
                error_log('No se pudo crear una galería desde el panel.');
                http_response_code(503);
                $errorMessage = 'No se pudo crear la galería. Intentá nuevamente.';
            }
        }
    }
}

try {
    $galleries = database($config)->query(
        'SELECT g.id, g.title, g.event_date, g.is_active, g.created_at,
                COUNT(DISTINCT p.id) AS photo_count,
                COUNT(DISTINCT CASE
                    WHEN c.revoked_at IS NULL
                     AND (c.expires_at IS NULL OR c.expires_at > UTC_TIMESTAMP())
                    THEN c.id
                END) AS active_code_count
         FROM galleries g
         LEFT JOIN photos p ON p.gallery_id = g.id
         LEFT JOIN access_codes c ON c.gallery_id = g.id
         GROUP BY g.id
         ORDER BY g.created_at DESC, g.id DESC'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('No se pudieron consultar las galerías del panel.');
    http_response_code(503);
    $galleries = [];
    $errorMessage = 'No se pudieron cargar las galerías. Verificá la conexión a la base.';
}

$flash = takeAdminFlash();
ob_start();
?>
<section class="admin-heading">
    <div>
        <span class="admin-kicker">Panel privado</span>
        <h1>Galerías</h1>
        <p>Creá eventos, organizá fotografías y compartí el acceso privado.</p>
    </div>
    <div class="admin-summary">
        <span><?= count($galleries) ?></span>
        <small><?= count($galleries) === 1 ? 'galería' : 'galerías' ?></small>
    </div>
</section>

<?php if ($flash !== null): ?>
    <p class="admin-alert admin-alert-<?= escape((string) $flash['type']) ?>" role="status">
        <?= escape((string) $flash['message']) ?>
    </p>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
    <p class="admin-alert admin-alert-error" role="alert"><?= escape($errorMessage) ?></p>
<?php endif; ?>

<div class="admin-dashboard-grid">
    <section class="admin-panel">
        <div class="panel-heading">
            <div>
                <span class="admin-kicker">Tu colección</span>
                <h2>Eventos creados</h2>
            </div>
        </div>

        <?php if ($galleries === []): ?>
            <div class="admin-empty">
                <span aria-hidden="true">✦</span>
                <h3>Tu primera galería empieza acá</h3>
                <p>Creá un evento para cargar las fotografías y preparar el acceso de tu clienta.</p>
            </div>
        <?php else: ?>
            <div class="gallery-list">
                <?php foreach ($galleries as $gallery): ?>
                    <div class="gallery-list-row">
                        <a class="gallery-row" href="gallery.php?id=<?= (int) $gallery['id'] ?>">
                            <span class="gallery-initial" aria-hidden="true">
                                <?= escape(mb_strtoupper(mb_substr((string) $gallery['title'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                            </span>
                            <span class="gallery-row-main">
                                <strong><?= escape((string) $gallery['title']) ?></strong>
                                <small>
                                    <?= $gallery['event_date'] === null
                                        ? 'Sin fecha'
                                        : escape((new DateTimeImmutable((string) $gallery['event_date']))->format('d/m/Y')) ?>
                                    · <?= (int) $gallery['photo_count'] ?>
                                    <?= (int) $gallery['photo_count'] === 1 ? 'fotografía' : 'fotografías' ?>
                                </small>
                            </span>
                            <span class="gallery-row-status">
                                <span class="status-dot <?= (int) $gallery['is_active'] === 1 ? 'is-active' : '' ?>"></span>
                                <?= (int) $gallery['is_active'] === 1 ? 'Activa' : 'Inactiva' ?>
                            </span>
                            <span class="gallery-row-arrow" aria-hidden="true">→</span>
                        </a>
                        <form
                            method="post"
                            action="delete-gallery.php"
                            onsubmit="return confirm('¿Eliminar este evento y todas sus fotografías, miniaturas y códigos? Esta acción no se puede deshacer.');"
                        >
                            <input type="hidden" name="csrf_token" value="<?= escape(adminCsrfToken()) ?>">
                            <input type="hidden" name="gallery_id" value="<?= (int) $gallery['id'] ?>">
                            <button class="delete-gallery-button" type="submit">Eliminar</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel create-panel">
        <span class="admin-kicker">Nuevo recuerdo</span>
        <h2>Crear una galería</h2>
        <p class="panel-description">Comenzá con el nombre del evento y su fecha.</p>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?= escape(adminCsrfToken()) ?>">
            <label for="title">Nombre del evento</label>
            <input
                id="title"
                name="title"
                type="text"
                maxlength="180"
                value="<?= escape($titleValue) ?>"
                placeholder="Ej.: Boda de Ana y Martín"
                required
            >
            <label for="event-date">Fecha del evento <span>(opcional)</span></label>
            <input id="event-date" name="event_date" type="date" value="<?= escape($dateValue) ?>">
            <button class="admin-button" type="submit">Crear galería <span aria-hidden="true">→</span></button>
        </form>
    </section>
</div>
<?php
adminRender('Galerías', (string) ob_get_clean());
