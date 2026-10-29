<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f2ee">
    <title><?= $adminPageTitle ?> | Administración · Jacqueline Fotografía</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="admin-body">
    <header class="admin-header">
        <a class="admin-brand" href="index.php">
            <span class="admin-brand-mark" aria-hidden="true">J</span>
            <span>Jacqueline Fotografía <small>Administración</small></span>
        </a>
        <?php if (!isset($adminShowNavigation) || $adminShowNavigation): ?>
            <nav class="admin-nav" aria-label="Administración">
                <a href="index.php">Galerías</a>
                <form method="post" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= escape(adminCsrfToken()) ?>">
                    <button type="submit">Cerrar sesión</button>
                </form>
            </nav>
        <?php endif; ?>
    </header>
    <main class="admin-main">
        <?= $adminPageContent ?>
    </main>
    <footer class="admin-footer">Acceso privado · Jacqueline Fotografía</footer>
</body>
</html>
