<?php
require __DIR__.'/bootstrap.php';
requireAuth();
if(!canManageUsers()){http_response_code(403);exit('No autorizado.');}
$error='';$notice='';$items=[];$pdo=null;
try{
  $pdo=db();
  if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!validCsrf())throw new RuntimeException('La sesión venció. Recargá la página.');
    $action=(string)($_POST['action']??'');
    $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($action==='create'){
      $email=strtolower(trim((string)($_POST['email']??'')));$name=trim((string)($_POST['display_name']??''));$password=(string)($_POST['password']??'');$roleNew=(string)($_POST['role']??'catalog');
      if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Correo inválido.');
      if($name===''||mb_strlen($name)>120)throw new RuntimeException('Nombre inválido.');
      if(strlen($password)<10)throw new RuntimeException('La contraseña debe tener al menos 10 caracteres.');
      if(!in_array($roleNew,['owner','admin','catalog'],true))throw new RuntimeException('Rol inválido.');
      $hash=password_hash($password,PASSWORD_DEFAULT);
      $q=$pdo->prepare('INSERT INTO admin_users (email,display_name,password_hash,role,is_active,created_at,updated_at) VALUES (?,?,?,?,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');$q->execute([$email,$name,$hash,$roleNew]);$newId=(int)$pdo->lastInsertId();auditLog($pdo,'admin_user.create','admin_user',$newId);
      header('Location: /admin/users.php?saved=1',true,303);exit;
    }
    if($action==='status'){
      if(!$id)throw new RuntimeException('Usuario inválido.');
      if($id===(int)($_SESSION['admin_id']??0))throw new RuntimeException('No podés desactivar tu propio usuario.');
      $active=isset($_POST['is_active'])?1:0;$pdo->prepare('UPDATE admin_users SET is_active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$active,$id]);auditLog($pdo,'admin_user.status','admin_user',$id);header('Location: /admin/users.php?saved=1',true,303);exit;
    }
    if($action==='password'){
      if(!$id)throw new RuntimeException('Usuario inválido.');$password=(string)($_POST['password']??'');if(strlen($password)<10)throw new RuntimeException('La contraseña debe tener al menos 10 caracteres.');$hash=password_hash($password,PASSWORD_DEFAULT);$pdo->prepare('UPDATE admin_users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$hash,$id]);auditLog($pdo,'admin_user.password','admin_user',$id);header('Location: /admin/users.php?saved=1',true,303);exit;
    }
    throw new RuntimeException('Acción no válida.');
  }
  $items=$pdo->query('SELECT id,email,display_name,role,is_active,last_login_at,created_at FROM admin_users ORDER BY id')->fetchAll();
  if(isset($_GET['saved']))$notice='Usuarios actualizados.';
}catch(Throwable $e){error_log('InYou usuarios: '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo completar la operación en MySQL.':$e->getMessage();}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Usuarios · InYou</title><link rel="stylesheet" href="/admin/style.css"><style>.wrap{max-width:1150px;margin:auto}.panel{background:#fff;border:1px solid #e7e5df;border-radius:12px;padding:22px;margin:20px 0}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.field{display:block;margin-bottom:12px}.field span{display:block;margin-bottom:6px;font-weight:600}.input,.select{width:100%;padding:11px;border:1px solid #d9d8d3;border-radius:7px;background:#fff}.btn{background:#202321;color:#fff;border:0;border-radius:7px;padding:11px 16px}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:11px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.actions{display:flex;gap:8px;flex-wrap:wrap}.alert{padding:12px;border-radius:8px;background:#e4f2e9}.error{background:#f9e6e4}.muted{color:#777}@media(max-width:750px){.grid{grid-template-columns:1fr}}</style></head><body class="dashboard"><?php adminSidebar('users');?><main class="content"><div class="wrap"><header><div>Hola, <?=h($_SESSION['admin_name']??'Administrador')?></div><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="signout">Cerrar sesión</button></form></header><div class="eyebrow">SEGURIDAD</div><h1>Usuarios</h1><p>Accesos individuales al panel. Solo el propietario puede administrar usuarios.</p><?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="alert"><?=h($notice)?></div><?php endif;?><section class="panel"><h2>Nuevo usuario</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="create"><div class="grid"><label class="field"><span>Nombre *</span><input class="input" name="display_name" required maxlength="120"></label><label class="field"><span>Correo *</span><input class="input" name="email" type="email" required maxlength="190"></label><label class="field"><span>Contraseña temporal *</span><input class="input" name="password" type="password" required minlength="10"></label><label class="field"><span>Rol</span><select class="select" name="role"><option value="catalog">Catálogo</option><option value="admin">Administrador</option><option value="owner">Propietario</option></select></label></div><button class="btn">Crear usuario</button></form></section><section class="panel"><h2>Usuarios existentes</h2><div style="overflow:auto"><table class="table"><thead><tr><th>Usuario</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th>Acciones</th></tr></thead><tbody><?php foreach($items as $u):?><tr><td><strong><?=h($u['display_name'])?></strong><br><small><?=h($u['email'])?></small></td><td><?=h($u['role'])?></td><td><?=$u['is_active']?'Activo':'Inactivo'?></td><td><?=h((string)($u['last_login_at']??'—'))?></td><td><div class="actions"><?php if((int)$u['id']!==(int)($_SESSION['admin_id']??0)):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><label><input type="checkbox" name="is_active" value="1" <?=$u['is_active']?'checked':''?>> Activo</label> <button>Guardar</button></form><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?=(int)$u['id']?>"><input class="input" style="width:190px" name="password" type="password" minlength="10" placeholder="Nueva contraseña" required><button>Cambiar</button></form></div></td></tr><?php endforeach;?></tbody></table></div></section></div></main></body></html>