<?php
require __DIR__.'/bootstrap.php';
if (!empty($_SESSION['admin_id'])) {header('Location: /admin/');exit;}
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if(!validCsrf()) {$error='Solicitud no válida. Recargá la página.';}
 elseif (($_SESSION['lock_until']??0)>time()) {$error='Demasiados intentos. Volvé a probar más tarde.';}
 else {
  $email=strtolower(trim((string)($_POST['email']??'')));
  $password=(string)($_POST['password']??'');
  try {
   $q=db()->prepare('SELECT id,email,display_name,password_hash,role FROM admin_users WHERE email=? AND is_active=1 LIMIT 1');
   $q->execute([$email]);$u=$q->fetch();
   if($u&&password_verify($password,$u['password_hash'])) {
    session_regenerate_id(true);unset($_SESSION['fails'],$_SESSION['lock_until']);
    $_SESSION['admin_id']=(int)$u['id'];$_SESSION['admin_name']=$u['display_name'];$_SESSION['admin_role']=$u['role'];$_SESSION['csrf']=bin2hex(random_bytes(32));
    header('Location: /admin/');exit;
   }
   $_SESSION['fails']=($_SESSION['fails']??0)+1;
   if($_SESSION['fails']>=5){$_SESSION['lock_until']=time()+900;$_SESSION['fails']=0;}
   $error='Correo o contraseña incorrectos.';
  }catch(Throwable $e){error_log('InYou admin login: '.$e->getMessage());$error='No se pudo validar el acceso.';}
 }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ingresar · InYou Admin</title><link rel="stylesheet" href="/admin/style.css"></head><body class="login-page"><main class="login-card"><div class="brand">in<span>You</span><i>.</i></div><div class="eyebrow">PANEL ADMINISTRATIVO</div><div class="divider"></div><h1>Bienvenido <em>de nuevo</em></h1><p>Ingresá a tu panel para gestionar tu tienda.</p><?php if($error):?><div class="error" role="alert"><?=h($error)?></div><?php endif;?><form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label for="email">Correo electrónico</label><input id="email" name="email" type="email" autocomplete="username" required maxlength="190"><label for="password">Contraseña</label><input id="password" name="password" type="password" autocomplete="current-password" required><button type="submit">Ingresar →</button></form><div class="login-footer">✳<small>Diseños con sentido, desde Paraguay.</small></div></main></body></html>
