<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/lib/database.php';
require_once dirname(__DIR__,2).'/lib/product-image-binary.php';
try {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET' && ($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD') {
        header('Allow: GET, HEAD'); http_response_code(405); exit;
    }
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) { http_response_code(400); exit; }
    if (($_SERVER['REQUEST_METHOD']??'GET')==='HEAD') {
        // Keep simple HEAD support without emitting image bytes.
        http_response_code(405);header('Allow: GET');exit;
    }
    inyouServeImage(inyouDb(),(int)$id,true);
} catch(Throwable $e) {
    error_log('InYou public image: '.$e->getMessage());
    http_response_code(404);
}
