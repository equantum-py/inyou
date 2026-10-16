<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $config = require dirname(__DIR__, 3) . '/inyou-private/db.php';

    $pdo = new PDO(
        'mysql:host=' . $config['host'] .
        ';dbname=' . $config['database'] .
        ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->query('SELECT 1');

    echo json_encode([
        'ok' => true,
        'database' => 'connected'
    ]);

} catch (Throwable $e) {
    error_log('InYou database health check failed: ' . $e->getMessage());

    http_response_code(503);

    echo json_encode([
        'ok' => false,
        'database' => 'unavailable'
    ]);
}