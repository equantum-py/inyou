<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/database.php';

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
    $pdo = inyouDb();
    $pdo->query('SELECT 1')->fetchColumn();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode([
            'ok' => true,
            'database' => 'connected',
            'runtime' => inyouRuntime(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable $e) {
    error_log('InYou health check failed: ' . $e->getMessage());
    http_response_code(503);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode([
            'ok' => false,
            'database' => 'unavailable',
            'runtime' => inyouRuntime(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
