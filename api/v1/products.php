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

    $products = $pdo->query(
        "SELECT id, sku, slug, name, description, base_price, currency, featured, sort_order
         FROM products
         WHERE status = 'active'
         ORDER BY featured DESC, sort_order ASC, id DESC"
    )->fetchAll();

    $variantStmt = $pdo->prepare(
        "SELECT id, product_id, size_id, sku, color_name, price_override, stock_qty
         FROM product_variants
         WHERE product_id = ? AND is_active = 1
         ORDER BY id ASC"
    );

    $payload = [];

    foreach ($products as $product) {
        $variantStmt->execute([(int)$product['id']]);
        $variants = [];

        foreach ($variantStmt->fetchAll() as $variant) {
            $size = $variant['size_id'] !== null
                ? 'Talle ' . (string)$variant['size_id']
                : 'Único';

            $variants[] = [
                'sku' => (string)$variant['sku'],
                'size' => $size,
                'color' => trim((string)$variant['color_name']) !== ''
                    ? (string)$variant['color_name']
                    : 'Sin color',
                'mode' => 'stock',
                'stock' => max(0, (int)$variant['stock_qty']),
                'leadTime' => '',
            ];
        }

        $slug = strtolower((string)$product['slug']);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'producto-' . (int)$product['id'];
        }

        $payload[] = [
            'id' => substr($slug, 0, 60),
            'name' => mb_substr((string)$product['name'], 0, 100),
            'description' => mb_substr((string)($product['description'] ?? ''), 0, 1000),
            'collection' => 'Esenciales',
            'tipo' => 'remera',
            'para' => 'Unisex',
            'fit' => '',
            'priceGs' => max(1, (int)$product['base_price']),
            'image' => '',
            'published' => true,
            'variants' => $variants,
        ];
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
} catch (Throwable $e) {
    error_log('InYou products API failed: ' . $e->getMessage());
    http_response_code(503);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo json_encode([
            'ok' => false,
            'error' => 'catalog_unavailable',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
