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

final class InYouDbSessionHandler implements SessionHandlerInterface
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
            id VARCHAR(128) NOT NULL,
            payload MEDIUMTEXT NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_admin_sessions_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function open(string $path,string $name):bool{return true;}
    public function close():bool{return true;}

    public function read(string $id):string|false
    {
        $q=$this->pdo->prepare('SELECT payload FROM admin_sessions WHERE id=? AND expires_at>CURRENT_TIMESTAMP LIMIT 1');
        $q->execute([$id]);
        $row=$q->fetch();
        return $row ? (string)$row['payload'] : '';
    }

    public function write(string $id,string $data):bool
    {
        $ttl=max(1800,(int)ini_get('session.gc_maxlifetime'));
        $q=$this->pdo->prepare('INSERT INTO admin_sessions (id,payload,expires_at) VALUES (?,?,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE payload=VALUES(payload),expires_at=VALUES(expires_at),updated_at=CURRENT_TIMESTAMP');
        return $q->execute([$id,$data,$ttl]);
    }

    public function destroy(string $id):bool
    {
        $q=$this->pdo->prepare('DELETE FROM admin_sessions WHERE id=?');
        return $q->execute([$id]);
    }

    public function gc(int $max_lifetime):int|false
    {
        $q=$this->pdo->prepare('DELETE FROM admin_sessions WHERE expires_at<=CURRENT_TIMESTAMP');
        $q->execute();
        return $q->rowCount();
    }
}

ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
ini_set('session.gc_maxlifetime','43200');
session_name('inyou_admin');
session_set_cookie_params([
    'lifetime'=>0,
    'path'=>'/admin',
    'secure'=>true,
    'httponly'=>true,
    'samesite'=>'Lax',
]);

try {
    $sessionPdo=inyouDb();
    session_set_save_handler(new InYouDbSessionHandler($sessionPdo),true);
} catch (Throwable $e) {
    error_log('InYou session storage: '.$e->getMessage());
    http_response_code(503);
    exit('Servicio temporalmente no disponible.');
}

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
function role():string{return (string)($_SESSION['admin_role']??'');}
function canManageCatalog():bool{return in_array(role(),['owner','admin','catalog'],true);}
function canManageUsers():bool{return role()==='owner';}
function canManageSettings():bool{return in_array(role(),['owner','admin'],true);}
function canViewAudit():bool{return in_array(role(),['owner','admin'],true);}

function auditLog(PDO $pdo,string $action,string $entityType,?int $entityId=null,?array $details=null):void{
    $actor=(int)($_SESSION['admin_id']??0);
    try{
        $q=$pdo->prepare('INSERT INTO admin_audit_log (admin_user_id,action,entity_type,entity_id,details,created_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)');
        $q->execute([$actor>0?$actor:null,mb_substr($action,0,100),mb_substr($entityType,0,80),$entityId,$details?json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
        return;
    }catch(Throwable $ignore){}
    try{
        $label=$action.($entityId!==null?' #'.$entityId:'').($actor>0?' · admin '.$actor:'');
        if(mb_strlen($label)>190)$label=mb_substr($label,0,190);
        $q=$pdo->prepare('INSERT INTO admin_audit_log (action,entity_type,created_at) VALUES (?,?,CURRENT_TIMESTAMP)');
        $q->execute([$label,mb_substr($entityType,0,80)]);
    }catch(Throwable $e){error_log('InYou audit log: '.$e->getMessage());}
}

function adminSidebar(string $active=''):void{
    $items=[
        'home'=>['/admin/','Resumen'],
        'products'=>['/admin/products.php','Productos'],
        'collections'=>['/admin/collections.php','Colecciones'],
        'inventory'=>['/admin/inventory.php','Inventario'],
        'media'=>['/admin/media.php','Medios'],
    ];
    if(canManageSettings())$items['settings']=['/admin/settings.php','Configuración'];
    if(canViewAudit())$items['audit']=['/admin/audit.php','Actividad'];
    if(canManageUsers())$items['users']=['/admin/users.php','Usuarios'];
    echo '<aside class="sidebar"><div class="brand">in<span>You</span><i>.</i></div><div class="eyebrow">PANEL ADMINISTRATIVO</div><nav>';
    foreach($items as $key=>$item){$cls=$key===$active?' class="selected"':'';echo '<a'.$cls.' href="'.h($item[0]).'">'.h($item[1]).'</a>';}
    echo '</nav><div class="sidebar-bottom">InYou · Administración<br><small>'.h(role()).'</small></div></aside>';
}
