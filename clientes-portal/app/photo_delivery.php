<?php

declare(strict_types=1);

function resolvePrivatePhotoPath(array $photo, array $config): string
{
    $storageRoot = realpath((string) $config['storage_path']);
    $relativePath = (string) $photo['relative_path'];
    if (
        $storageRoot === false
        || $relativePath === ''
        || str_contains($relativePath, "\0")
        || str_starts_with($relativePath, '/')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $relativePath) === 1
    ) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    $filePath = realpath($storageRoot . DIRECTORY_SEPARATOR . $relativePath);
    $rootPrefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $pathIsInsideStorage = $filePath !== false
        && str_starts_with(
            PHP_OS_FAMILY === 'Windows' ? strtolower($filePath) : $filePath,
            PHP_OS_FAMILY === 'Windows' ? strtolower($rootPrefix) : $rootPrefix
        );

    if (!$pathIsInsideStorage || !is_file($filePath) || !is_readable($filePath)) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    return $filePath;
}

function deliverPhotoThumbnail(array $photo, array $config, string $filePath): never
{
    if (!extension_loaded('gd')) {
        http_response_code(503);
        exit('La vista previa no está disponible en este momento.');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
    $imageFunctions = [
        'image/jpeg' => ['create' => 'imagecreatefromjpeg', 'type' => IMAGETYPE_JPEG],
        'image/png' => ['create' => 'imagecreatefrompng', 'type' => IMAGETYPE_PNG],
        'image/webp' => ['create' => 'imagecreatefromwebp', 'type' => IMAGETYPE_WEBP],
    ];
    if (
        !is_string($mimeType)
        || !isset($imageFunctions[$mimeType])
        || !function_exists($imageFunctions[$mimeType]['create'])
    ) {
        http_response_code(415);
        exit('Formato de fotografía no admitido.');
    }

    $imageInfo = getimagesize($filePath);
    if ($imageInfo === false || $imageInfo[2] !== $imageFunctions[$mimeType]['type']) {
        http_response_code(415);
        exit('Formato de fotografía no admitido.');
    }

    $width = (int) $imageInfo[0];
    $height = (int) $imageInfo[1];
    $pixelCount = $width * $height;
    $memoryLimit = thumbnailMemoryLimit();
    $estimatedMemory = $pixelCount * 5 + 8 * 1024 * 1024;
    if (
        $width < 1
        || $height < 1
        || $pixelCount > 40000000
        || ($memoryLimit > 0 && memory_get_usage(true) + $estimatedMemory > $memoryLimit)
    ) {
        http_response_code(413);
        exit('La vista previa de esta imagen no se puede generar.');
    }

    $storageRoot = realpath((string) $config['storage_path']);
    if ($storageRoot === false) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath((string) $_SERVER['DOCUMENT_ROOT'])
        : false;
    if ($documentRoot !== false && (
        $storageRoot === $documentRoot
        || privatePathIsInside($storageRoot, $documentRoot)
    )) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    $cacheDirectory = $storageRoot
        . DIRECTORY_SEPARATOR . '.thumbnails'
        . DIRECTORY_SEPARATOR . (int) $photo['gallery_id'];
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0700) && !is_dir($cacheDirectory)) {
        http_response_code(503);
        exit('La vista previa no está disponible en este momento.');
    }

    $resolvedCacheDirectory = realpath($cacheDirectory);
    if (
        $resolvedCacheDirectory === false
        || !privatePathIsInside($resolvedCacheDirectory, $storageRoot)
    ) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    $sourceVersion = (string) filesize($filePath) . ':' . (string) filemtime($filePath);
    $cacheName = hash('sha256', (string) $photo['relative_path'] . ':' . $sourceVersion) . '.jpg';
    $thumbnailPath = $resolvedCacheDirectory . DIRECTORY_SEPARATOR . $cacheName;

    if (is_link($thumbnailPath)) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    if (!is_file($thumbnailPath)) {
        $createFunction = $imageFunctions[$mimeType]['create'];
        $sourceImage = @$createFunction($filePath);
        if ($sourceImage === false) {
            http_response_code(415);
            exit('No se pudo leer la imagen para generar la vista previa.');
        }

        $maxDimension = 640;
        $scale = min(1, $maxDimension / max($width, $height));
        $thumbnailWidth = max(1, (int) round($width * $scale));
        $thumbnailHeight = max(1, (int) round($height * $scale));
        $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);
        if ($thumbnail === false) {
            imagedestroy($sourceImage);
            http_response_code(503);
            exit('La vista previa no está disponible en este momento.');
        }

        $background = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefill($thumbnail, 0, 0, $background);
        imagecopyresampled(
            $thumbnail,
            $sourceImage,
            0,
            0,
            0,
            0,
            $thumbnailWidth,
            $thumbnailHeight,
            $width,
            $height
        );

        $temporaryPath = tempnam($resolvedCacheDirectory, '.preview-');
        $temporaryIsPrivate = $temporaryPath !== false
            && dirname(realpath($temporaryPath) ?: $temporaryPath) === $resolvedCacheDirectory;
        $thumbnailCreated = $temporaryIsPrivate
            && @imagejpeg($thumbnail, $temporaryPath, 82);
        imagedestroy($sourceImage);
        imagedestroy($thumbnail);

        if (!$thumbnailCreated || !is_string($temporaryPath)) {
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
            http_response_code(503);
            exit('La vista previa no está disponible en este momento.');
        }

        if (
            !is_file($thumbnailPath)
            && (
                is_link($thumbnailPath)
                || !@rename($temporaryPath, $thumbnailPath)
            )
        ) {
            @unlink($temporaryPath);
            if (!is_file($thumbnailPath)) {
                http_response_code(503);
                exit('La vista previa no está disponible en este momento.');
            }
        } elseif (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }

    if (!is_file($thumbnailPath) || !is_readable($thumbnailPath)) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . (string) filesize($thumbnailPath));
    header('Content-Disposition: inline; filename="vista-previa-' . (int) $photo['id'] . '.jpg"');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($thumbnailPath);
    exit;
}

function thumbnailMemoryLimit(): int
{
    $value = trim((string) ini_get('memory_limit'));
    if ($value === '' || $value === '-1') {
        return 0;
    }

    if (preg_match('/^(\d+)\s*([KMG])?$/i', $value, $matches) !== 1) {
        return 0;
    }

    $factor = match (strtoupper($matches[2] ?? '')) {
        'K' => 1024,
        'M' => 1048576,
        'G' => 1073741824,
        default => 1,
    };

    return (int) $matches[1] * $factor;
}

function privatePathIsInside(string $path, string $root): bool
{
    $normalizedPath = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    $normalizedRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
    $rootPrefix = rtrim($normalizedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return $normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $rootPrefix);
}

function deliverPhoto(PDO $pdo, array $config): never
{
    $gallery = currentGallery($pdo);
    $photoId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

    if ($gallery === null || !$photoId) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    $statement = $pdo->prepare(
        'SELECT id, gallery_id, relative_path FROM photos
         WHERE id = :photo_id AND gallery_id = :gallery_id
         LIMIT 1'
    );
    $statement->execute([
        'photo_id' => $photoId,
        'gallery_id' => $gallery['id'],
    ]);
    $photo = $statement->fetch();

    if ($photo === false) {
        http_response_code(404);
        exit('Fotografía no encontrada.');
    }

    $filePath = resolvePrivatePhotoPath($photo, $config);

    if (($_GET['thumbnail'] ?? '') === '1') {
        deliverPhotoThumbnail($photo, $config, $filePath);
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
        http_response_code(415);
        exit('Formato de fotografía no admitido.');
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($filePath));
    header(
        'Content-Disposition: attachment; filename="fotografia-'
        . (int) $photo['id'] . '.' . $extensions[$mimeType] . '"'
    );
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}
