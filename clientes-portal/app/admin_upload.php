<?php

declare(strict_types=1);

const ADMIN_UPLOAD_MAX_FILES = 250;
const ADMIN_UPLOAD_MAX_FILE_BYTES = 26214400;

final class AdminUploadException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422)
    {
        parent::__construct($message);
    }
}

function adminJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function parsePhpSize(string|false $value): int
{
    if ($value === false || trim($value) === '') {
        return 0;
    }

    $value = trim($value);
    if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMG])?B?$/i', $value, $matches) !== 1) {
        return 0;
    }

    $size = (float) $matches[1];
    $unit = strtoupper($matches[2] ?? '');
    $factor = match ($unit) {
        'K' => 1024,
        'M' => 1048576,
        'G' => 1073741824,
        default => 1,
    };

    return (int) min(PHP_INT_MAX, $size * $factor);
}

function uploadMaxBytes(): int
{
    $limits = [
        ADMIN_UPLOAD_MAX_FILE_BYTES,
        parsePhpSize(ini_get('upload_max_filesize')),
    ];
    $postLimit = parsePhpSize(ini_get('post_max_size'));
    if ($postLimit > 0) {
        $postFileLimit = $postLimit - 65536;
        if ($postFileLimit <= 0) {
            return 0;
        }
        $limits[] = $postFileLimit;
    }

    $limits = array_filter($limits, static fn (int $limit): bool => $limit > 0);
    return $limits === [] ? ADMIN_UPLOAD_MAX_FILE_BYTES : min($limits);
}

function adminPathIsWithin(string $path, string $root): bool
{
    $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $prefix = strtolower($prefix);
    }

    return str_starts_with($path, $prefix);
}

function adminPrivateStorageRoot(array $config): string
{
    $configuredPath = (string) $config['storage_path'];
    if (!is_dir($configuredPath) && !@mkdir($configuredPath, 0750, true) && !is_dir($configuredPath)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento privado.');
    }

    $root = realpath($configuredPath);
    if ($root === false) {
        throw new RuntimeException('No se pudo resolver el almacenamiento privado.');
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath((string) $_SERVER['DOCUMENT_ROOT'])
        : false;
    if ($documentRoot !== false) {
        $normalizedRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
        $normalizedDocumentRoot = PHP_OS_FAMILY === 'Windows'
            ? strtolower($documentRoot)
            : $documentRoot;

        if (
            $normalizedRoot === $normalizedDocumentRoot
            || adminPathIsWithin($root, $documentRoot)
        ) {
            throw new RuntimeException('El almacenamiento no puede estar dentro de la raíz pública.');
        }
    }

    return $root;
}

function adminResolvePrivateFile(string $storageRoot, string $relativePath): ?string
{
    if (
        $relativePath === ''
        || str_contains($relativePath, "\0")
        || str_starts_with($relativePath, '/')
        || str_contains($relativePath, '\\')
        || preg_match('/(^|\/)\.\.?($|\/)/', $relativePath) === 1
        || preg_match('/^[A-Za-z]:/', $relativePath) === 1
    ) {
        return null;
    }

    $filePath = realpath($storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if (
        $filePath === false
        || !adminPathIsWithin($filePath, $storageRoot)
        || !is_file($filePath)
        || !is_readable($filePath)
    ) {
        return null;
    }

    return $filePath;
}

function adminLegacyPhotoHashes(PDO $pdo, array $config, int $galleryId): array
{
    $storageRoot = adminPrivateStorageRoot($config);
    $statement = $pdo->prepare(
        'SELECT relative_path FROM photos WHERE gallery_id = :gallery_id'
    );
    $statement->execute(['gallery_id' => $galleryId]);

    $managedPathPattern = '#^galleries/'
        . preg_quote((string) $galleryId, '#')
        . '/[a-f0-9]{64}\.(?:jpg|png|webp)$#D';
    $hashes = [];

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $relativePath) {
        $relativePath = (string) $relativePath;
        if (preg_match($managedPathPattern, $relativePath) === 1) {
            continue;
        }

        $filePath = adminResolvePrivateFile($storageRoot, $relativePath);
        if ($filePath === null) {
            continue;
        }

        $hash = @hash_file('sha256', $filePath);
        if (is_string($hash)) {
            $hashes[$hash] = true;
        }
    }

    return array_keys($hashes);
}

function uploadedFileErrorMessage(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'El archivo supera el límite de PHP o el máximo permitido por archivo.',
        UPLOAD_ERR_PARTIAL => 'La carga del archivo llegó incompleta. Intentá nuevamente.',
        UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene configurada una carpeta temporal para cargas.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo guardar el archivo temporal.',
        UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la carga del archivo.',
        default => 'No se pudo recibir el archivo.',
    };
}

function sanitizedOriginalName(string $name, string $extension): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
    $name = trim(mb_convert_encoding($name, 'UTF-8', 'UTF-8'));
    if ($name === '') {
        return 'fotografia.' . $extension;
    }

    return mb_strcut($name, 0, 255, 'UTF-8');
}

function storeAdminUpload(
    PDO $pdo,
    array $config,
    int $galleryId,
    array $file,
    array $legacyHashes
): array
{
    $error = filter_var($file['error'] ?? null, FILTER_VALIDATE_INT);
    if ($error !== UPLOAD_ERR_OK) {
        throw new AdminUploadException(uploadedFileErrorMessage((int) $error), 400);
    }

    $temporaryPath = $file['tmp_name'] ?? '';
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
        throw new AdminUploadException('El archivo recibido no es una carga válida.', 400);
    }

    $size = filesize($temporaryPath);
    if ($size === false || $size < 1) {
        throw new AdminUploadException('El archivo está vacío o no se pudo leer.', 422);
    }
    if ($size > uploadMaxBytes()) {
        throw new AdminUploadException(
            'El archivo supera el límite permitido por el panel o por la configuración de PHP.',
            413
        );
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $allowedTypes = [
        'image/jpeg' => ['extension' => 'jpg', 'image_type' => IMAGETYPE_JPEG],
        'image/png' => ['extension' => 'png', 'image_type' => IMAGETYPE_PNG],
        'image/webp' => ['extension' => 'webp', 'image_type' => IMAGETYPE_WEBP],
    ];
    if (!is_string($mimeType) || !isset($allowedTypes[$mimeType])) {
        throw new AdminUploadException('Formato no admitido. Usá imágenes JPEG, PNG o WebP.', 415);
    }

    set_error_handler(
        static fn (int $severity, string $message, string $file, int $line): bool => true
    );
    try {
        $imageInfo = getimagesize($temporaryPath);
    } finally {
        restore_error_handler();
    }
    if ($imageInfo === false || $imageInfo[2] !== $allowedTypes[$mimeType]['image_type']) {
        throw new AdminUploadException('El archivo no contiene una imagen válida.', 422);
    }

    $sha256 = @hash_file('sha256', $temporaryPath);
    if (!is_string($sha256)) {
        throw new RuntimeException('No se pudo verificar el archivo recibido.');
    }

    $storageRoot = adminPrivateStorageRoot($config);
    $galleryDirectory = $storageRoot
        . DIRECTORY_SEPARATOR . 'galleries'
        . DIRECTORY_SEPARATOR . $galleryId;
    if (
        !is_dir($galleryDirectory)
        && !@mkdir($galleryDirectory, 0750, true)
        && !is_dir($galleryDirectory)
    ) {
        throw new RuntimeException('No se pudo preparar la carpeta privada de la galería.');
    }

    $resolvedDirectory = realpath($galleryDirectory);
    if (
        $resolvedDirectory === false
        || !adminPathIsWithin($resolvedDirectory, $storageRoot)
    ) {
        throw new RuntimeException('La carpeta de destino no es segura.');
    }

    $relativePath = 'galleries/' . $galleryId . '/' . $sha256 . '.' . $allowedTypes[$mimeType]['extension'];
    $destination = $resolvedDirectory
        . DIRECTORY_SEPARATOR . $sha256 . '.' . $allowedTypes[$mimeType]['extension'];
    $lockPath = $resolvedDirectory . DIRECTORY_SEPARATOR . '.upload.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('No se pudo bloquear la carpeta de la galería para escribir.');
    }
    $lock = @fopen($lockPath, 'c+b');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('No se pudo bloquear la carpeta de la galería para escribir.');
    }

    $stagedPath = null;
    $createdDestination = false;
    try {
        if (in_array($sha256, $legacyHashes, true)) {
            return [
                'status' => 'duplicate',
                'message' => 'Esta imagen ya está registrada en la galería; no se volvió a guardar.',
            ];
        }

        $existingPhotoQuery = $pdo->prepare(
            'SELECT id FROM photos
             WHERE gallery_id = :gallery_id AND relative_path = :relative_path
             LIMIT 1'
        );
        $existingPhotoQuery->execute([
            'gallery_id' => $galleryId,
            'relative_path' => $relativePath,
        ]);
        $existingPhotoId = $existingPhotoQuery->fetchColumn();

        $originalName = sanitizedOriginalName((string) ($file['name'] ?? ''), $allowedTypes[$mimeType]['extension']);

        if (is_link($destination)) {
            throw new RuntimeException('El nombre interno de destino ya está ocupado.');
        }
        $destinationExists = file_exists($destination);
        if ($destinationExists && !is_file($destination)) {
            throw new RuntimeException('El nombre interno de destino ya está ocupado.');
        }
        if ($destinationExists) {
            $destinationHash = @hash_file('sha256', $destination);
            if (!is_string($destinationHash) || !hash_equals($sha256, $destinationHash)) {
                throw new RuntimeException('El nombre interno de destino ya está ocupado.');
            }
        }

        if ($existingPhotoId !== false && $destinationExists) {
            return [
                'status' => 'duplicate',
                'message' => 'Esta imagen ya está registrada en la galería; no se volvió a guardar.',
            ];
        }

        $pdo->beginTransaction();
        if (!$destinationExists) {
            $stagedPath = $resolvedDirectory
                . DIRECTORY_SEPARATOR . '.upload-' . bin2hex(random_bytes(16)) . '.part';
            if (!@move_uploaded_file($temporaryPath, $stagedPath)) {
                throw new RuntimeException('No se pudo mover el archivo al almacenamiento privado.');
            }
            if (!@rename($stagedPath, $destination)) {
                throw new RuntimeException('No se pudo finalizar el archivo en el almacenamiento privado.');
            }
            $stagedPath = null;
            $createdDestination = true;
        }

        if ($existingPhotoId !== false) {
            $update = $pdo->prepare(
                'UPDATE photos SET original_name = :original_name
                 WHERE id = :id AND gallery_id = :gallery_id'
            );
            $update->execute([
                'original_name' => $originalName,
                'id' => $existingPhotoId,
                'gallery_id' => $galleryId,
            ]);
            $photoId = (int) $existingPhotoId;
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO photos (gallery_id, relative_path, original_name)
                 VALUES (:gallery_id, :relative_path, :original_name)'
            );
            $insert->execute([
                'gallery_id' => $galleryId,
                'relative_path' => $relativePath,
                'original_name' => $originalName,
            ]);
            $photoId = (int) $pdo->lastInsertId();
        }
        $pdo->commit();

        return [
            'status' => 'uploaded',
            'message' => 'Fotografía original guardada.',
            'photo_id' => $photoId,
            'name' => $originalName,
            'size' => $size,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($createdDestination && is_file($destination)) {
            if (!@unlink($destination)) {
                error_log('No se pudo retirar un archivo sin registro de base de datos.');
            }
        }
        throw $exception;
    } finally {
        if (is_string($stagedPath) && is_file($stagedPath)) {
            if (!@unlink($stagedPath)) {
                error_log('No se pudo retirar un archivo temporal de carga.');
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
