<?php

declare(strict_types=1);

try {
    require dirname(__DIR__, 2) . '/app/bootstrap.php';
    require dirname(__DIR__, 2) . '/app/admin_auth.php';
} catch (RuntimeException $exception) {
    error_log('Panel de administración no disponible: ' . $exception->getMessage());
    http_response_code(503);
    exit('El panel no está disponible en este momento.');
}

if (adminIsAuthenticated($config)) {
    header('Location: index.php', true, 303);
    exit;
}

$errorMessage = '';
$isConfigured = adminIsConfigured($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validAdminCsrfToken()) {
        http_response_code(400);
        $errorMessage = 'La solicitud venció. Actualizá la página e intentá nuevamente.';
    } elseif (!$isConfigured) {
        http_response_code(503);
        $errorMessage = 'Configurá el usuario y el hash de contraseña administrativa antes de iniciar sesión.';
    } else {
        try {
            $pdo = database($config);
            $fingerprint = adminRateLimitFingerprint($config);
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

            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $username = is_string($username) ? trim($username) : '';
            $password = is_string($password) ? $password : '';
            $passwordIsValid = password_verify($password, $config['admin']['password_hash']);
            $usernameIsValid = hash_equals($config['admin']['username'], $username);

            if (!$isBlocked && $usernameIsValid && $passwordIsValid) {
                $pdo->prepare(
                    'DELETE FROM access_attempts WHERE fingerprint_hash = :fingerprint'
                )->execute(['fingerprint' => $fingerprint]);

                session_regenerate_id(true);
                unset($_SESSION['admin_authenticated'], $_SESSION['admin_username']);
                $_SESSION['admin_authenticated'] = true;
                $_SESSION['admin_username'] = $config['admin']['username'];
                $_SESSION['admin_authenticated_at'] = time();
                unset($_SESSION['admin_csrf_token']);

                header('Location: index.php', true, 303);
                exit;
            }

            if (!$isBlocked) {
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
            }

            $errorMessage = 'No pudimos validar esas credenciales.';
        } catch (PDOException | RuntimeException $exception) {
           error_log('Error al procesar el acceso administrativo: ' . get_class($exception) . ' - ' . $exception->getMessage());
            http_response_code(503);
            $errorMessage = 'El panel no está disponible en este momento.';
        }
    }
}

ob_start();
?>
<section class="admin-login-card" aria-labelledby="admin-login-title">
    <span class="admin-kicker">Espacio privado</span>
    <h1 id="admin-login-title">Administración</h1>
    <p>Ingresá con las credenciales administrativas configuradas para este portal.</p>

    <?php if ($errorMessage !== ''): ?>
        <p class="admin-alert admin-alert-error" role="alert"><?= escape($errorMessage) ?></p>
    <?php endif; ?>
    <?php if (!$isConfigured): ?>
        <p class="admin-alert" role="status">
            Antes de ingresar, configurá <code>ADMIN_USERNAME</code> y
            <code>ADMIN_PASSWORD_HASH</code> o los valores equivalentes en
            <code>config.local.php</code>.
        </p>
    <?php endif; ?>

    <form method="post" class="admin-form">
        <input type="hidden" name="csrf_token" value="<?= escape(adminCsrfToken()) ?>">
        <label for="username">Usuario</label>
        <input id="username" name="username" type="text" autocomplete="username" maxlength="100" required>
        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button class="admin-button" type="submit" <?= $isConfigured ? '' : 'disabled' ?>>
            Ingresar al panel
        </button>
    </form>
</section>
<?php

$adminPageTitle = escape('Ingreso');
$adminPageContent = (string) ob_get_clean();
$adminShowNavigation = false;
require dirname(__DIR__, 2) . '/app/views/admin_layout.php';
