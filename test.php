<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

register_shutdown_function(function () {
    $error = error_get_last();

    if ($error !== null) {
        echo "\nError PHP: " . $error['message'];
        echo "\nLínea: " . $error['line'];
    }
});

echo "1. PHP funciona\n";

echo "2. Antes de bootstrap\n";

require __DIR__ . '/bootstrap.php';

echo "3. Bootstrap cargado\n";

$pdo = db();

echo "4. MySQL conectado\n";