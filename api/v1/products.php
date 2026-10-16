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

function validImagePath(string $path): bool
{
    return $path !== ''
        && !str_contains($path, '..')
        && (bool)preg_match('#^assets/products/[a-zA-Z0-9._/-]+\.(jpg|jpeg|png|webp)$#i', $path);
}

try {
    $pdo = inyouDb();

    $products = $pdo->query(
        "SELECT
            p.id,
            p.slug,
            p.name,
            p.description,
            p.base_price,
            p.currency,
            p.product_type,
            p.audience,
            p.fit,
            p.featured,
            p.sort_order,
            (
                SELECT c.name
                FROM product_collections pc
                INNER JOIN collections c ON c.id = pc.collection_id
                WHERE pc.product_id = p.id AND c.is_active = 1
                ORDER BY pc.sort_order ASC, c.sort_order ASC, c.id ASC
                LIMIT 1
            ) AS collection_name
         FROM products p
         WHERE p.status = 'active'
         ORDER BY p.featured DESC, p.sort_order ASC, p.id DESC"
    )->fetchAll();

    $variantStmt = $pdo->prepare(
        "SELECT
            pv.id,
            pv.sku,
            pv.color_name,
            pv.price_override,
            pv.stock_qty,
            pv.availability_mode,
            pv.lead_time,
            COALESCE(s.label, 'Único') AS size_label
         FROM product_variants pv
         LEFT JOIN sizes s ON s.id = pv.size_id AND s.is_active = 1
         WHERE pv.product_id = ? AND pv.is_active = 1
         ORDER BY pv.sort_order ASC, pv.id ASC"
    );

    $imageStmt = $pdo->prepare(
        "SELECT file_path, alt_text
         FROM product_images
         WHERE product_id = ?
         ORDER BY is_primary DESC, sort_order ASC, id ASC"
    );

    $payload = [];

    foreach ($products as $product) {
        $collection = trim((string)($product['collection_name'] ?? ''));
        if ($collection === '') {
            error_log('InYou catalog: active product without active collection, id=' . (int)$product['id']);
            continue;
        }

        if ((string)$product['currency'] !== 'PYG' || (int)$product['base_price'] <= 0) {
            error_log('InYou catalog: invalid price/currency, id=' . (int)$product['id']);
            continue;
        }

        $slug = strtolower((string)$product['slug']);
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'producto-' . (int)$product['id'];
        }

        $variantStmt->execute([(int)$product['id']]);
        $variants = [];

        foreach ($variantStmt->fetchAll() as $variant) {
            $sku = strtoupper(trim((string)$variant['sku']));
            if (!preg_match('/^[A-Z0-9-]{4,55}$/', $sku)) {
                error_log('InYou catalog: invalid variant SKU, variant=' . (int)$variant['id']);
                continue;
            }

            $mode = (string)$variant['availability_mode'];
            if (!in_array($mode, ['stock', 'bajo_pedido'], true)) {
                continue;
            }

            $basePrice = max(1, (int)$product['base_price']);
            $price = $variant['price_override'] !== null ? (int)$variant['price_override'] : $basePrice;
            if ($price <= 0) {
                $price = $basePrice;
            }

            $variants[] = [
                'sku' => $sku,
                'size' => mb_substr((string)$variant['size_label'], 0, 20),
                'color' => mb_substr(trim((string)$variant['color_name']) !== '' ? (string)$variant['color_name'] : 'Sin color', 0, 45),
                'mode' => $mode,
                'stock' => max(0, (int)$variant['stock_qty']),
                'leadTime' => mb_substr((string)($variant['lead_time'] ?? ''), 0, 120),
                'priceGs' => $price,
            ];
        }

        $imageStmt->execute([(int)$product['id']]);
        $images = [];
        foreach ($imageStmt->fetchAll() as $imageRow) {
            $imagePath = trim((string)$imageRow['file_path']);
            if (!validImagePath($imagePath)) {
                continue;
            }
            $images[] = [
                'src' => $imagePath,
                'alt' => mb_substr((string)($imageRow['alt_text'] ?? ''), 0, 255),
            ];
        }

        $payload[] = [
            'id' => substr($slug, 0, 60),
            'name' => mb_substr((string)$product['name'], 0, 100),
            'description' => mb_substr((string)($product['description'] ?? ''), 0, 1000),
            'collection' => $collection,
            'tipo' => (string)$product['product_type'],
            'para' => (string)$product['audience'],
            'fit' => (string)$product['fit'],
            'priceGs' => max(1, (int)$product['base_price']),
            'image' => $images[0]['src'] ?? '',
            'images' => $images,
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
        echo json_encode(['ok' => false, 'error' => 'catalog_unavailable'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
