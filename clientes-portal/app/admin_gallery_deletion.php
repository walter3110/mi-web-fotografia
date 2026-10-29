<?php

declare(strict_types=1);

function deletionPathIsWithin(string $path, string $root): bool
{
    $normalizedPath = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    $normalizedRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
    $rootPrefix = rtrim($normalizedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return str_starts_with($normalizedPath, $rootPrefix);
}

function resolveGalleryDeletionFile(string $storageRoot, string $relativePath): string
{
    if (
        $relativePath === ''
        || str_contains($relativePath, "\0")
        || str_starts_with($relativePath, '/')
        || str_contains($relativePath, '\\')
        || preg_match('/(^|\/)\.\.?($|\/)/', $relativePath) === 1
        || preg_match('/^[A-Za-z]:/', $relativePath) === 1
    ) {
        throw new RuntimeException('La galería contiene una ruta de fotografía no segura.');
    }

    $parts = explode('/', $relativePath);
    $candidate = $storageRoot;
    foreach ($parts as $part) {
        $candidate .= DIRECTORY_SEPARATOR . $part;
        if (is_link($candidate)) {
            throw new RuntimeException('La galería contiene un enlace de archivo no seguro.');
        }
    }

    $filePath = realpath($candidate);
    if (
        $filePath === false
        || !deletionPathIsWithin($filePath, $storageRoot)
        || !is_file($filePath)
        || !is_readable($filePath)
    ) {
        throw new RuntimeException(
            'No se encontró una fotografía asociada en el almacenamiento privado; no se eliminó el evento.'
        );
    }

    return $filePath;
}

function expectedLegacyThumbnailPath(array $photo, string $storageRoot): ?string
{
    try {
        $photoPath = resolveGalleryDeletionFile($storageRoot, (string) $photo['relative_path']);
    } catch (RuntimeException) {
        return null;
    }

    $sourceVersion = (string) filesize($photoPath) . ':' . (string) filemtime($photoPath);
    $cacheName = hash(
        'sha256',
        (string) $photo['id'] . ':' . (string) $photo['relative_path'] . ':' . $sourceVersion
    ) . '.jpg';

    return $storageRoot . DIRECTORY_SEPARATOR . '.thumbnails' . DIRECTORY_SEPARATOR . $cacheName;
}

function collectGalleryDeletionFiles(
    PDO $pdo,
    array $config,
    int $galleryId,
    array $photos
): array {
    $storageRoot = realpath((string) $config['storage_path']);
    if ($storageRoot === false) {
        if ($photos !== []) {
            throw new RuntimeException(
                'No se encontró el almacenamiento privado; no se eliminó el evento.'
            );
        }
        return [
            'storage_root' => '',
            'gallery_storage_directory' => '',
            'gallery_thumbnail_directory' => '',
            'files' => [],
        ];
    }

    $documentRootValue = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $documentRoot = is_string($documentRootValue) && $documentRootValue !== ''
        ? realpath($documentRootValue)
        : false;
    if (
        $documentRoot !== false
        && (
            $storageRoot === $documentRoot
            || deletionPathIsWithin($storageRoot, $documentRoot)
        )
    ) {
        throw new RuntimeException(
            'El almacenamiento no es privado; no se eliminó el evento.'
        );
    }

    $files = [];
    foreach ($photos as $photo) {
        $filePath = resolveGalleryDeletionFile($storageRoot, (string) $photo['relative_path']);
        $files[$filePath] = true;
    }

    $galleryStorageDirectory = $storageRoot
        . DIRECTORY_SEPARATOR . 'galleries'
        . DIRECTORY_SEPARATOR . $galleryId;
    if (is_link($storageRoot . DIRECTORY_SEPARATOR . 'galleries') || is_link($galleryStorageDirectory)) {
        throw new RuntimeException(
            'La carpeta privada del evento contiene una ruta no segura; no se eliminó el evento.'
        );
    }

    if (is_dir($galleryStorageDirectory)) {
        $resolvedGalleryStorageDirectory = realpath($galleryStorageDirectory);
        if (
            $resolvedGalleryStorageDirectory === false
            || !deletionPathIsWithin($resolvedGalleryStorageDirectory, $storageRoot)
        ) {
            throw new RuntimeException(
                'La carpeta privada del evento está fuera del almacenamiento; no se eliminó el evento.'
            );
        }

        $entries = scandir($resolvedGalleryStorageDirectory);
        if ($entries === false) {
            throw new RuntimeException(
                'No se pudo revisar la carpeta privada del evento; no se eliminó el evento.'
            );
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $resolvedGalleryStorageDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                throw new RuntimeException(
                    'La carpeta privada del evento contiene elementos no seguros; no se eliminó el evento.'
                );
            }

            $resolvedPath = realpath($path);
            if (
                $entry !== '.upload.lock'
                && ($resolvedPath === false || !isset($files[$resolvedPath]))
            ) {
                throw new RuntimeException(
                    'Hay archivos privados sin registro asociados a este evento; no se eliminó el evento.'
                );
            }
            $files[$resolvedPath ?: $path] = true;
        }
    }

    $thumbnailRoot = $storageRoot . DIRECTORY_SEPARATOR . '.thumbnails';
    $galleryThumbnailDirectory = $thumbnailRoot . DIRECTORY_SEPARATOR . $galleryId;
    if (is_link($thumbnailRoot) || is_link($galleryThumbnailDirectory)) {
        throw new RuntimeException(
            'El caché privado de miniaturas contiene una ruta no segura; no se eliminó el evento.'
        );
    }

    if (is_dir($galleryThumbnailDirectory)) {
        $resolvedThumbnailDirectory = realpath($galleryThumbnailDirectory);
        if (
            $resolvedThumbnailDirectory === false
            || !deletionPathIsWithin($resolvedThumbnailDirectory, $storageRoot)
        ) {
            throw new RuntimeException(
                'El caché privado de miniaturas está fuera del almacenamiento; no se eliminó el evento.'
            );
        }

        $entries = scandir($resolvedThumbnailDirectory);
        if ($entries === false) {
            throw new RuntimeException(
                'No se pudo revisar el caché de miniaturas; no se eliminó el evento.'
            );
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $thumbnailPath = $resolvedThumbnailDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($thumbnailPath) || !is_file($thumbnailPath) || !is_readable($thumbnailPath)) {
                throw new RuntimeException(
                    'El caché contiene un elemento no seguro; no se eliminó el evento.'
                );
            }
            $files[realpath($thumbnailPath)] = true;
        }
    }

    $flatThumbnailDirectory = $thumbnailRoot;
    if (is_link($flatThumbnailDirectory)) {
        throw new RuntimeException(
            'El caché privado de miniaturas contiene una ruta no segura; no se eliminó el evento.'
        );
    }

    if (is_dir($flatThumbnailDirectory)) {
        $legacyEntries = scandir($flatThumbnailDirectory);
        if ($legacyEntries === false) {
            throw new RuntimeException(
                'No se pudo revisar el caché heredado; no se eliminó el evento.'
            );
        }

        $photoQuery = $pdo->query('SELECT id, gallery_id, relative_path FROM photos');
        $allPhotos = $photoQuery->fetchAll();
        $knownLegacyThumbnails = [];
        foreach ($allPhotos as $photo) {
            $legacyPath = expectedLegacyThumbnailPath($photo, $storageRoot);
            if ($legacyPath !== null) {
                $knownLegacyThumbnails[realpath($legacyPath) ?: $legacyPath] = (int) $photo['gallery_id'];
            }
        }

        foreach ($legacyEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $legacyPath = $flatThumbnailDirectory . DIRECTORY_SEPARATOR . $entry;
            if (
                is_dir($legacyPath)
                && preg_match('/^[1-9][0-9]*$/', $entry) === 1
                && !is_link($legacyPath)
            ) {
                continue;
            }
            if (is_link($legacyPath) || !is_file($legacyPath) || !is_readable($legacyPath)) {
                throw new RuntimeException(
                    'El caché heredado contiene un elemento no seguro; no se eliminó el evento.'
                );
            }

            $resolvedLegacyPath = realpath($legacyPath);
            if (
                $resolvedLegacyPath === false
                || !array_key_exists($resolvedLegacyPath, $knownLegacyThumbnails)
            ) {
                throw new RuntimeException(
                    'Hay miniaturas heredadas que no se pueden asociar con seguridad; no se eliminó el evento.'
                );
            }

            if ($knownLegacyThumbnails[$resolvedLegacyPath] === $galleryId) {
                $files[$resolvedLegacyPath] = true;
            }
        }
    }

    return [
        'storage_root' => $storageRoot,
        'gallery_storage_directory' => $galleryStorageDirectory,
        'gallery_thumbnail_directory' => $galleryThumbnailDirectory,
        'files' => array_keys($files),
    ];
}

function restoreGalleryDeletionFiles(array $movedFiles, string $quarantineDirectory): bool
{
    $restored = true;
    foreach (array_reverse($movedFiles) as $move) {
        if (!is_file($move['quarantine'])) {
            continue;
        }

        if (file_exists($move['original']) || !@rename($move['quarantine'], $move['original'])) {
            $restored = false;
        }
    }

    if (is_dir($quarantineDirectory)) {
        $remaining = scandir($quarantineDirectory);
        if ($remaining !== false && count($remaining) === 2) {
            @rmdir($quarantineDirectory);
        } else {
            $restored = false;
        }
    }

    return $restored;
}

function deleteAdminGallery(PDO $pdo, array $config, int $galleryId): void
{
    $pdo->beginTransaction();
    $movedFiles = [];
    $quarantineDirectory = '';

    try {
        $galleryQuery = $pdo->prepare(
            'SELECT id FROM galleries WHERE id = :id FOR UPDATE'
        );
        $galleryQuery->execute(['id' => $galleryId]);
        if ($galleryQuery->fetchColumn() === false) {
            throw new RuntimeException('El evento ya no existe.');
        }

        $photoQuery = $pdo->prepare(
            'SELECT id, gallery_id, relative_path
             FROM photos WHERE gallery_id = :gallery_id FOR UPDATE'
        );
        $photoQuery->execute(['gallery_id' => $galleryId]);
        $photos = $photoQuery->fetchAll();
        $plan = collectGalleryDeletionFiles($pdo, $config, $galleryId, $photos);

        if ($plan['files'] !== []) {
            $quarantineDirectory = $plan['storage_root']
                . DIRECTORY_SEPARATOR . '.deleting-gallery-'
                . $galleryId . '-' . bin2hex(random_bytes(12));
            if (!@mkdir($quarantineDirectory, 0700)) {
                throw new RuntimeException(
                    'No se pudo preparar una eliminación segura; el evento sigue intacto.'
                );
            }

            foreach ($plan['files'] as $index => $filePath) {
                if (!is_file($filePath) || !is_readable($filePath)) {
                    throw new RuntimeException(
                        'Un archivo asociado cambió durante la operación; el evento sigue intacto.'
                    );
                }

                $quarantinePath = $quarantineDirectory
                    . DIRECTORY_SEPARATOR . sprintf('%06d.bin', $index);
                if (!@rename($filePath, $quarantinePath)) {
                    throw new RuntimeException(
                        'No se pudo preparar un archivo para borrar; el evento sigue intacto.'
                    );
                }
                $movedFiles[] = [
                    'original' => $filePath,
                    'quarantine' => $quarantinePath,
                ];
            }
        }

        $revokeCodes = $pdo->prepare(
            'UPDATE access_codes
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
             WHERE gallery_id = :gallery_id'
        );
        $revokeCodes->execute(['gallery_id' => $galleryId]);

        $deleteGallery = $pdo->prepare('DELETE FROM galleries WHERE id = :id');
        $deleteGallery->execute(['id' => $galleryId]);
        if ($deleteGallery->rowCount() !== 1) {
            throw new RuntimeException(
                'No se pudo confirmar la eliminación del evento; no se borró ningún registro.'
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $restored = $quarantineDirectory === ''
            || restoreGalleryDeletionFiles($movedFiles, $quarantineDirectory);
        if (!$restored) {
            error_log('Falló la restauración de archivos durante la eliminación de una galería.');
            throw new RuntimeException(
                'La eliminación falló y no se pudieron restaurar todos los archivos. Revisá el almacenamiento privado.'
            );
        }

        if ($exception instanceof RuntimeException) {
            throw $exception;
        }

        error_log('Falló la preparación de una eliminación de galería.');
        throw new RuntimeException(
            'No se pudo preparar la eliminación segura; el evento sigue intacto.'
        );
    }

    $cleanupSucceeded = true;
    foreach ($movedFiles as $move) {
        if (is_file($move['quarantine']) && !@unlink($move['quarantine'])) {
            $cleanupSucceeded = false;
        }
    }

    if ($quarantineDirectory !== '' && is_dir($quarantineDirectory)) {
        if (!@rmdir($quarantineDirectory)) {
            $cleanupSucceeded = false;
        }
    }

    if (
        isset($plan['gallery_thumbnail_directory'])
        && is_dir($plan['gallery_thumbnail_directory'])
        && !@rmdir($plan['gallery_thumbnail_directory'])
    ) {
        $cleanupSucceeded = false;
    }

    if (
        isset($plan['gallery_storage_directory'])
        && is_dir($plan['gallery_storage_directory'])
        && !@rmdir($plan['gallery_storage_directory'])
    ) {
        $cleanupSucceeded = false;
    }

    if (!$cleanupSucceeded) {
        error_log('No se pudo completar la limpieza de archivos en cuarentena.');
        throw new RuntimeException(
            'El evento se eliminó, pero quedaron archivos privados en cuarentena. Pedí asistencia para completar su limpieza.'
        );
    }
}
