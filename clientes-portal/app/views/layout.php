<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f2ee">
    <title><?= $pageTitle ?> | Jacqueline Fotografía</title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="index.php" aria-label="Jacqueline Fotografía, inicio">
            <span class="brand-mark" aria-hidden="true">J</span>
            <span class="brand-name">jacqueline <span>fotografía</span></span>
        </a>
        <span class="header-note">Un espacio para tus recuerdos</span>
    </header>

    <main>
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <span>Jacqueline Fotografía</span>
        <span>Momentos que merecen quedarse</span>
    </footer>
</body>
</html>
