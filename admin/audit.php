<?php
require __DIR__.'/bootstrap.php';
requireAuth();
if(!canViewAudit()){http_response_code(403);exit('No autorizado.');}

$error='';$items=[];$pdo=null;

function auditEntityLabel(string $type):string{
    return match($type){
        'product'=>'Producto',
        'product_variant'=>'Variante',
        'product_image'=>'Imagen',
        'collection'=>'Colección',
        'admin_user'=>'Usuario',
        'settings'=>'Configuración',
        default=>$type!==''?$type:'—',
    };
}

function auditActionLabel(string $action):string{
    $map=[
        'admin.login'=>'Inicio de sesión',
        'admin.logout'=>'Cierre de sesión',
        'product.create'=>'Producto creado',
        'product.update'=>'Producto actualizado',
        'product.status.active'=>'Producto publicado',
        'product.status.draft'=>'Producto pasado a borrador',
        'product.status.archived'=>'Producto archivado',
        'variant.create'=>'Variante creada',
        'variant.update'=>'Variante actualizada',
        'variant.delete'=>'Variante eliminada',
        'collection.create'=>'Colección creada',
        'collection.update'=>'Colección actualizada',
        'collection.delete'=>'Colección eliminada',
        'image.upload'=>'Imagen subida',
        'image.update'=>'Imagen actualizada',
        'image.primary'=>'Imagen marcada como principal',
        'image.delete'=>'Imagen eliminada',
        'settings.update'=>'Configuración actualizada',
        'admin_user.create'=>'Usuario creado',
        'admin_user.status'=>'Estado de usuario actualizado',
        'admin_user.password'=>'Contraseña de usuario actualizada',
    ];
    if(isset($map[$action]))return $map[$action];
    $action=preg_replace('/\s*·\s*admin\s+\d+$/u','',$action);
    return $action!==''?$action:'—';
}

try{
    $pdo=db();
    $cols=[];
    $q=$pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_audit_log'");
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $col)$cols[(string)$col]=true;

    $hasActor=isset($cols['admin_user_id']);
    $hasEntityId=isset($cols['entity_id']);
    $hasDetails=isset($cols['details']);

    if($hasActor){
        $sql='SELECT a.action,a.entity_type,a.created_at'.
            ($hasEntityId?',a.entity_id':'').
            ($hasDetails?',a.details':'').
            ',a.admin_user_id,u.display_name,u.email FROM admin_audit_log a LEFT JOIN admin_users u ON u.id=a.admin_user_id ORDER BY a.created_at DESC LIMIT 300';
        $items=$pdo->query($sql)->fetchAll();
    }else{
        $rows=$pdo->query('SELECT action,entity_type,created_at FROM admin_audit_log ORDER BY created_at DESC LIMIT 300')->fetchAll();
        foreach($rows as $row){
            $action=(string)($row['action']??'');
            $adminId=null;
            if(preg_match('/·\s*admin\s+(\d+)$/u',$action,$m))$adminId=(int)$m[1];
            $row['admin_user_id']=$adminId;
            $row['entity_id']=preg_match('/#(\d+)/',$action,$m2)?(int)$m2[1]:null;
            $row['details']=null;
            $row['display_name']=null;$row['email']=null;
            if($adminId){
                $u=$pdo->prepare('SELECT display_name,email FROM admin_users WHERE id=?');
                $u->execute([$adminId]);$user=$u->fetch();
                if($user){$row['display_name']=$user['display_name'];$row['email']=$user['email'];}
            }
            $items[]=$row;
        }
    }
}catch(Throwable $e){
    error_log('InYou auditoria: '.$e->getMessage());
    $error='No se pudo cargar la auditoría.';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Auditoría · InYou</title>
<link rel="stylesheet" href="/admin/style.css">
<style>
.wrap{max-width:1250px;margin:auto}.panel{background:#fff;border:1px solid #e7e5df;border-radius:12px;padding:22px;margin:20px 0}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:12px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.muted{color:#777}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#f1f0eb;font-size:12px}.error{padding:12px;border-radius:8px;background:#f9e6e4}.who strong{display:block}.who small{color:#777}.details{max-width:320px;white-space:pre-wrap;word-break:break-word;font-size:12px;color:#666}@media(max-width:760px){.table th:nth-child(5),.table td:nth-child(5){display:none}}
</style>
</head>
<body class="dashboard">
<?php adminSidebar('audit');?>
<main class="content"><div class="wrap">
<header><div>Hola, <?=h($_SESSION['admin_name']??'Administrador')?></div><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="signout">Cerrar sesión</button></form></header>
<div class="eyebrow">SEGURIDAD</div><h1>Actividad</h1><p>Historial de cambios realizados desde el panel administrativo, con responsable y registro afectado.</p>
<?php if($error):?><div class="error"><?=h($error)?></div><?php endif;?>
<section class="panel"><div style="overflow:auto"><table class="table"><thead><tr><th>Fecha</th><th>Responsable</th><th>Acción</th><th>Registro</th><th>Detalle</th></tr></thead><tbody>
<?php foreach($items as $i):
    $actorName=trim((string)($i['display_name']??''));
    $actorEmail=trim((string)($i['email']??''));
    $actorId=(int)($i['admin_user_id']??0);
    $entityId=$i['entity_id']??null;
    $detailText='';
    if(!empty($i['details'])){
        $decoded=json_decode((string)$i['details'],true);
        $detailText=is_array($decoded)?json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):(string)$i['details'];
    }
?>
<tr>
<td><?=h((string)$i['created_at'])?></td>
<td class="who"><?php if($actorName!==''||$actorEmail!==''):?><strong><?=h($actorName!==''?$actorName:'Administrador')?></strong><small><?=h($actorEmail)?></small><?php elseif($actorId):?><strong>Admin #<?=$actorId?></strong><small>Usuario no disponible</small><?php else:?><span class="muted">Sistema / registro anterior</span><?php endif;?></td>
<td><?=h(auditActionLabel((string)$i['action']))?></td>
<td><span class="badge"><?=h(auditEntityLabel((string)$i['entity_type']))?></span><?php if($entityId!==null&&$entityId!==''):?> <span class="muted">#<?=h((string)$entityId)?></span><?php endif;?></td>
<td class="details"><?=h($detailText!==''?$detailText:'—')?></td>
</tr>
<?php endforeach;?>
<?php if(!$items):?><tr><td colspan="5" class="muted">Todavía no hay actividad registrada.</td></tr><?php endif;?>
</tbody></table></div></section>
</div></main>
</body></html>
