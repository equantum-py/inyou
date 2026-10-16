<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

try {
    $path = dirname(__DIR__, 3) . '/inyou-private/db.php';

    if (!is_file($path)) {
        throw new RuntimeException('Private database configuration was not found.');
    }

    $config = require $path;

    if (!is_array($config)) {
        throw new RuntimeException('Invalid database configuration.');
    }

    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
            throw new RuntimeException('Incomplete database configuration.');
        }
    }

    $pdo = new PDO(
        'mysql:host=' . $config['host'] .
        ';dbname=' . $config['database'] .
        ';charset=utf8mb4',
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );

    $pdo->query('SELECT 1')->fetchColumn();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode([
            'ok' => true,
            'database' => 'connected',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    error_log('InYou health check failed: ' . $e->getMessage());
    http_response_code(503);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode([
            'ok' => false,
            'database' => 'unavailable',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
