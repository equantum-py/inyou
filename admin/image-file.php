<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
requireAuth();
require_once dirname(__DIR__).'/lib/product-image-binary.php';
try {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
        header('Allow: GET');http_response_code(405);exit;
    }
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id){http_response_code(400);exit;}
    inyouServeImage(db(),(int)$id,false);
} catch(Throwable $e) {
    error_log('InYou admin image: '.$e->getMessage());
    http_response_code(404);
}
