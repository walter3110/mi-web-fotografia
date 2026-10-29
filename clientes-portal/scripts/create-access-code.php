<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/config.php';

if (strlen((string) $config['app_key']) < 32) {
    fwrite(STDERR, "Configurá APP_KEY (al menos 32 caracteres) antes de continuar.\n");
    exit(1);
}

$galleryId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (!$galleryId || $galleryId < 1) {
    fwrite(STDERR, "Uso: php scripts/create-access-code.php <id_de_galeria>\n");
    exit(1);
}

try {
    $db = $config['db'];
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset']
        ),
        $db['user'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $galleryQuery = $pdo->prepare('SELECT id FROM galleries WHERE id = :id AND is_active = 1');
    $galleryQuery->execute(['id' => $galleryId]);
    if ($galleryQuery->fetchColumn() === false) {
        fwrite(STDERR, "No existe una galería activa con ese ID.\n");
        exit(1);
    }

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

    fwrite(STDOUT, "Código (guardalo; no se vuelve a mostrar): {$code}\n");
} catch (PDOException | RuntimeException $exception) {
    error_log('No se pudo generar un código de acceso.');
    fwrite(STDERR, "No se pudo crear el código. Revisá la configuración y los registros.\n");
    exit(1);
}
