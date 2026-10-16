<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/database.php';

ini_set('display_errors','0');
ini_set('log_errors','1');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
if (!$isHttps && $forwardedProto !== 'https') {
    http_response_code(403);
    exit('Se requiere HTTPS.');
}

ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('inyou_admin');
session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/admin',
    'secure'=>true,
    'httponly'=>true,
    'samesite'=>'Strict',
]);
if(session_status()===PHP_SESSION_NONE&&!session_start()){
    error_log('InYou: No se pudo iniciar la sesion');
    http_response_code(503);
    exit('Servicio temporalmente no disponible.');
}

function h(?string $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function db():PDO{return inyouDb();}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function validCsrf():bool{return isset($_POST['csrf'])&&is_string($_POST['csrf'])&&hash_equals(csrf(),$_POST['csrf']);}
function requireAuth():void{if(empty($_SESSION['admin_id'])){header('Location: /admin/login.php');exit;}}
function canManageCatalog():bool{return in_array((string)($_SESSION['admin_role']??''),['owner','admin','catalog'],true);}

function auditLog(PDO $pdo,string $action,string $entityType,?int $entityId=null):void{
    try{
        $actor=(int)($_SESSION['admin_id']??0);
        $label=$action.($entityId!==null?' #'.$entityId:'').($actor>0?' · admin '.$actor:'');
        if(mb_strlen($label)>190)$label=mb_substr($label,0,190);
        $q=$pdo->prepare('INSERT INTO admin_audit_log (action,entity_type,created_at) VALUES (?,?,CURRENT_TIMESTAMP)');
        $q->execute([$label,mb_substr($entityType,0,80)]);
    }catch(Throwable $e){error_log('InYou audit log: '.$e->getMessage());}
}
