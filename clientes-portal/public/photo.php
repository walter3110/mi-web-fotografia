<?php

declare(strict_types=1);

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
} catch (RuntimeException $exception) {
    error_log('El portal de clientes no está disponible.');
    http_response_code(503);
    exit('El portal no está disponible en este momento.');
}

require dirname(__DIR__) . '/app/photo_delivery.php';

try {
    deliverPhoto(database($config), $config);
} catch (PDOException | RuntimeException $exception) {
    error_log('No se pudo entregar una fotografía.');
    http_response_code(503);
    exit('El portal no está disponible en este momento.');
}
