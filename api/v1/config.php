<?php

declare(strict_types=1);
require_once dirname(__DIR__,2).'/lib/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

try{
    $pdo=inyouDb();
    $rows=$pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('whatsapp_phone','whatsapp_enabled','store_ready','currency')")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo json_encode([
        'whatsappPhone'=>(string)($rows['whatsapp_phone']??''),
        'whatsappEnabled'=>(string)($rows['whatsapp_enabled']??'0')==='1',
        'currency'=>(string)($rows['currency']??'PYG'),
        'storeReady'=>(string)($rows['store_ready']??'0')==='1',
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    error_log('InYou public config: '.$e->getMessage());
    http_response_code(503);
    echo json_encode(['whatsappPhone'=>'','whatsappEnabled'=>false,'currency'=>'PYG','storeReady'=>false]);
}
