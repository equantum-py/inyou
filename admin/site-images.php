<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/lib/inyou-site-visuals.php';
requireAuth();
if(!canManageCatalog()){http_response_code(403);exit('No autorizado.');}
$error='';$notice='';$images=[];$slots=inyouSiteSlots();
try{
 $pdo=db();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Recargá la página.');}
  $slot=(string)($_POST['slot']??'');
  $device=(string)($_POST['device']??'');
  $key=inyouSiteSettingKey($slot,$device);
  $action=(string)($_POST['action']??'');
  $current=$pdo->prepare('SELECT `value` FROM settings WHERE `key`=?');
  $current->execute([$key]);
  $oldPath=(string)($current->fetchColumn()?:'');
  if($action==='upload'){
   $file=$_FILES['image']??null;
   if(!is_array($file)){
    $length=(int)($_SERVER['CONTENT_LENGTH']??0);
    error_log('InYou site-image upload missing: content_length='.$length.' post_max_size='.ini_get('post_max_size'));
    throw new RuntimeException($length>0&&empty($_POST)
      ?'El archivo supera el límite total del servidor ('.ini_get('post_max_size').'). Reducí su peso y volvé a intentar.'
      :'Primero seleccioná una fotografía JPG, PNG o WEBP para este espacio.');
   }
   $uploadCode=(int)($file['error']??UPLOAD_ERR_NO_FILE);
   if($uploadCode!==UPLOAD_ERR_OK){
    $messages=[
     UPLOAD_ERR_INI_SIZE=>'El archivo supera el límite del servidor ('.ini_get('upload_max_filesize').'). Reducí su tamaño.',
     UPLOAD_ERR_FORM_SIZE=>'El archivo supera el límite permitido por el formulario.',
     UPLOAD_ERR_PARTIAL=>'La imagen llegó incompleta. Comprobá tu conexión y volvé a intentar.',
     UPLOAD_ERR_NO_FILE=>'No se seleccionó ninguna imagen. Tocá «Seleccionar archivo» antes de guardar.',
     UPLOAD_ERR_NO_TMP_DIR=>'El servidor no tiene carpeta temporal de subida. Contactá al administrador.',
     UPLOAD_ERR_CANT_WRITE=>'El servidor no pudo recibir la imagen. Intentá nuevamente.',
     UPLOAD_ERR_EXTENSION=>'PHP bloqueó la carga de la imagen.'
    ];
    error_log('InYou site-image upload error='.$uploadCode.' max_file='.ini_get('upload_max_filesize').' max_post='.ini_get('post_max_size'));
    throw new RuntimeException($messages[$uploadCode]??'No se pudo recibir el archivo (código '.$uploadCode.').');
   }
   $tmp=(string)($file['tmp_name']??'');
   $bytesCount=(int)($file['size']??0);
   if($bytesCount<1||$bytesCount>INYOU_STORAGE_MAX_BYTES||!is_uploaded_file($tmp))throw new RuntimeException('La imagen no puede superar 10 MB.');
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
   $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
   if(!$ext)throw new RuntimeException('Subí una imagen JPG, PNG o WEBP.');
   $dims=@getimagesize($tmp);
   if(!$dims||$dims[0]<1||$dims[1]<1||$dims[0]*$dims[1]>25000000)throw new RuntimeException('La imagen debe tener menos de 25 megapíxeles.');
   $bytes=file_get_contents($tmp);if($bytes===false)throw new RuntimeException('No se pudo leer la imagen.');
   $newPath=inyouSiteImagePath($slot,$device).'.'.$ext;
   inyouStorageUpload($newPath,$bytes,$mime);
   try{
    $pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$key,$newPath]);
   }catch(Throwable $e){try{inyouStorageDelete($newPath);}catch(Throwable $ignore){}throw $e;}
   auditLog($pdo,'site.image.upload','site',null,['slot'=>$slot,'device'=>$device]);
   // Previous image remains in Storage as a recovery asset; no destructive cleanup in this action.
  }elseif($action==='reset'){
   $pdo->prepare('DELETE FROM settings WHERE `key`=?')->execute([$key]);
   auditLog($pdo,'site.image.reset','site',null,['slot'=>$slot,'device'=>$device]);
  }else{throw new RuntimeException('Acción no válida.');}
  header('Location: /admin/site-images.php?saved=1#slot-'.rawurlencode($slot),true,303);exit;
 }
 $images=inyouSiteImages($pdo);
 if(isset($_GET['saved']))$notice='Imagen actualizada. La tienda usará la nueva fotografía sin redeploy.';
}catch(Throwable $e){
 error_log('InYou site images: '.$e->getMessage());
 $error=$e instanceof PDOException?'No se pudieron guardar las imágenes en MySQL.':$e->getMessage();
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Imágenes de la tienda · InYou</title>
<link rel="stylesheet" href="/admin/style.css"><link rel="stylesheet" href="/admin/fashion-admin.css?v=9">
<style>
.site-images-shell{max-width:1300px;margin:auto}.site-image-intro{color:#837873;font-size:13px;line-height:1.6;max-width:850px}
.site-image-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px;margin:24px 0}
.site-image-card{background:white;border:1px solid #e7ded9;border-radius:15px;padding:20px;min-width:0}
.site-image-card h2{font-size:18px;margin:0 0 5px}.site-image-card>p{color:#8b807b;font-size:12px;margin:0 0 18px}
.site-image-sizes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.site-image-device{min-width:0}.site-image-device h3{font-size:13px;margin:0 0 6px}.site-image-device small{font-size:11px;color:#81736d}
.site-image-preview{display:grid;place-items:center;aspect-ratio:4/3;background:#f6f1ee;border:1px dashed #e1d5cf;border-radius:11px;margin:10px 0;overflow:hidden}
.site-image-preview img{width:100%;height:100%;object-fit:contain}.site-image-preview span{font-size:12px;color:#9c8c83}
.site-image-device form{display:grid;gap:9px}.site-image-device input[type=file]{width:100%;font-size:11px;min-width:0}
.site-image-device button{min-height:42px;border:0;background:#70081e;color:white;border-radius:9px;padding:10px;font-size:12px;font-weight:700;cursor:pointer}
.site-image-device .site-reset{border:1px solid #e5d9d4;color:#745b56;background:#fff}
.site-file-picker{display:flex;align-items:center;justify-content:center;min-height:46px;padding:9px 12px;border:1px dashed #a96d79;background:#faf5f6;color:#70081e;border-radius:9px;cursor:pointer;font-size:12px;font-weight:700}
.site-file-picker:focus-within{outline:3px solid #d6aab4;outline-offset:2px}
.site-file-picker input[type=file]{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;pointer-events:none}
.site-file-feedback{font-size:11px;color:#776965;margin:0;min-height:30px;overflow-wrap:anywhere}
.site-file-feedback.error,.site-upload-notice{color:#a52f40}
.site-upload-notice{font-size:11px;margin:0}
.site-upload-form button[type=submit]:disabled{opacity:.52;cursor:not-allowed}
.site-image-card[id="slot-hero"] .site-image-device:first-child .site-image-preview{aspect-ratio:1920 / 450}
.site-image-card[id="slot-hero"] .site-image-device:nth-child(2) .site-image-preview{aspect-ratio:750 / 507}
.site-image-card[id="slot-hero"]{grid-column:1 / -1}
.site-image-card[id="slot-hero"] .site-image-preview img{object-fit:contain}

.site-message{padding:13px 17px;border-radius:11px;background:#e8f3e9;color:#326846;margin:14px 0}.site-message.error{background:#fbe9e7;color:#8c3b2c}
.site-image-note{background:#fff9f3;border:1px solid #efe2d7;border-radius:12px;padding:14px;font-size:12px;color:#705d53;margin:12px 0}
@media(max-width:900px){.site-image-grid{grid-template-columns:1fr}}
@media(max-width:520px){.site-image-card{padding:14px}.site-image-sizes{grid-template-columns:1fr}.site-image-preview{aspect-ratio:16/9}}
</style>
</head><body class="dashboard"><?php adminSidebar('visuals');?>
<main class="content"><div class="site-images-shell"><header><div>InYou Studio · Apariencia de la tienda</div><a class="btn light" href="/" target="_blank" rel="noopener">Ver tienda ↗</a></header>
<span class="eyebrow">CONTENIDO VISUAL</span><h1>Imágenes de la tienda</h1>
<p class="site-image-intro">Cambiá el banner principal, las fotos de categorías, las colecciones y las campañas desde aquí. Cada espacio admite una foto para notebook y otra para celular.</p>
<div class="site-image-note">Tamaños recomendados en píxeles. Usá JPG o WEBP optimizado, de hasta 10 MB. <strong>Primero elegí el archivo y después hacé clic en Guardar imagen.</strong> Si no cargás una foto mobile, se usa la imagen desktop. Si no cargás ninguna, se conserva la imagen actual de la web. Los cambios no modifican las fotos de los productos.</div>
<?php if($error):?><div class="site-message error" role="alert"><?=h($error)?></div><?php endif;?>
<?php if($notice):?><div class="site-message" role="status"><?=h($notice)?></div><?php endif;?>
<div class="site-image-grid">
<?php foreach($slots as $slot=>$meta):?>
<section class="site-image-card" id="slot-<?=h($slot)?>"><h2><?=h($meta[0])?></h2><p>Proporción recomendada: <?=h($meta[3])?></p><div class="site-image-sizes">
<?php foreach(['desktop'=>'Notebook / desktop','mobile'=>'Celular / mobile'] as $device=>$label):?>
<div class="site-image-device"><h3><?=h($label)?></h3><small><?=h($device==='desktop'?$meta[1]:$meta[2])?> px</small>
<div class="site-image-preview"><?php if(!empty($images[$slot][$device]['url'])):?><img src="<?=h($images[$slot][$device]['url'])?>" loading="lazy" alt="<?=h($meta[0].' '.$label)?>"><?php else:?><span>Sin imagen personalizada</span><?php endif;?></div>
<form class="site-upload-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="slot" value="<?=h($slot)?>"><input type="hidden" name="device" value="<?=h($device)?>">
<label class="site-file-picker">＋ Seleccionar imagen <input type="file" name="image" accept="image/jpeg,image/png,image/webp" aria-label="Seleccionar imagen de <?=h($meta[0].' '.$label)?>" required></label>
<p class="site-file-feedback" role="status" aria-live="polite">Elegí una foto para ver su vista previa.</p><p class="site-upload-notice" role="alert" aria-live="polite"></p>
<button type="submit" disabled>Guardar imagen</button></form>
<?php if(!empty($images[$slot][$device])):?><form method="post" onsubmit="return confirm('¿Restablecer esta imagen? Volverá a la imagen predeterminada.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="reset"><input type="hidden" name="slot" value="<?=h($slot)?>"><input type="hidden" name="device" value="<?=h($device)?>"><button class="site-reset" type="submit">Restablecer imagen</button></form><?php endif;?>
</div><?php endforeach;?></div></section>
<?php endforeach;?>
</div></div></main><script defer src="/admin/site-image-upload.js?v=1"></script></body></html>