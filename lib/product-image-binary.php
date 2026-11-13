<?php
declare(strict_types=1);

/** Serve a stored product image from the persistent MySQL store.
 * Caller must enforce the authorization policy before invoking.
 */
function inyouServeImage(PDO $pdo, int $imageId, bool $publicOnly): void
{
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; sandbox');
    header('Cache-Control: private, no-store');
    if ($imageId <= 0) {
        http_response_code(400); exit;
    }
    $sql = "SELECT pb.mime_type, pb.image_data, pb.byte_length
            FROM product_image_blobs pb
            INNER JOIN product_images pi ON pi.id=pb.image_id
            INNER JOIN products p ON p.id=pi.product_id
            WHERE pb.image_id=?";
    if ($publicOnly) $sql .= " AND p.status='active'";
    $sql .= ' LIMIT 1';
    $q = $pdo->prepare($sql);
    $q->execute([$imageId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit; }
    $mime=(string)($row['mime_type']??'');
    if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) {
        http_response_code(415); exit;
    }
    $bytes=(string)$row['image_data'];
    if ($bytes==='' || strlen($bytes)>5*1024*1024) {
        http_response_code(404); exit;
    }
    header('Content-Type: '.$mime);
    header('Content-Length: '.strlen($bytes));
    echo $bytes;
    exit;
}
