<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/lib/inyou-site-visuals.php';
requireAuth();
if(!canManageCatalog()){http_response_code(403);exit('No autorizado.');}
$error='';$notice='';$images=[];$heroSlides=[];$slots=inyouSiteSlots();
try{
 $pdo=db();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Recargá la página.');}
  $action=(string)($_POST['action']??'');
  if(in_array($action,['slide_add','slide_toggle','slide_up','slide_down','slide_remove'],true)){
   $slides=inyouHeroOrder($pdo);
   if($action==='slide_add'){
    if(count($slides)>=6)throw new RuntimeException('Máximo 6 banners en el carrusel.');
    $used=array_column($slides,'id');
    for($newId=1;$newId<=5;$newId++)if(!in_array($newId,$used,true))break;
    if($newId>5)throw new RuntimeException('No hay espacios libres para otro banner.');
    // Al reutilizar un número, no reactivar fotos antiguas sin autorización.
    $pdo->prepare('DELETE FROM settings WHERE `key` IN (?,?)')->execute([inyouHeroImageKey($newId,'desktop'),inyouHeroImageKey($newId,'mobile')]);
    $slides[]=['id'=>$newId,'active'=>true];
   }else{
    $slideId=filter_var($_POST['slide_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>5]]);
    if($slideId===false)throw new RuntimeException('Identificador del banner no válido.');
    $position=null;
    foreach($slides as $index=>$row)if($row['id']===$slideId){$position=$index;break;}
    if($position===null)throw new RuntimeException('Este banner ya no está disponible.');
    if($action==='slide_toggle')$slides[$position]['active']=!$slides[$position]['active'];
    if($action==='slide_up'&&$position>0){[$slides[$position-1],$slides[$position]]=[$slides[$position],$slides[$position-1]];}
    if($action==='slide_down'&&$position<count($slides)-1){[$slides[$position+1],$slides[$position]]=[$slides[$position],$slides[$position+1]];}
    if($action==='slide_remove'){
     if($slideId===0)throw new RuntimeException('El banner original no se puede eliminar: podés desactivarlo.');
     array_splice($slides,$position,1);
    }
   }
   inyouHeroSaveOrder($pdo,$slides);
   auditLog($pdo,'site.carousel.'.$action,'site',null);
   header('Location: /admin/site-images.php?saved=1#carousel',true,303);exit;
  }
  $slot=(string)($_POST['slot']??'');
  $device=(string)($_POST['device']??'');
  $slideId=filter_var($_POST['slide_id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>5]]);
  if($slideId===false)throw new RuntimeException('Banner no válido.');
  if($slot==='hero'){
   if(!in_array($slideId,array_column(inyouHeroOrder($pdo),'id'),true))throw new RuntimeException('El banner no existe en el carrusel.');
   $key=inyouHeroImageKey($slideId,$device);
  }else{
   if($slideId!==0)throw new RuntimeException('Sección de imagen no válida.');
   $key=inyouSiteSettingKey($slot,$device);
  }
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
   $newPath=($slot==='hero'&&$slideId>0?'supabase/site/hero_'.$slideId.'-'.$device.'-'.bin2hex(random_bytes(16)):inyouSiteImagePath($slot,$device)).'.'.$ext;
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
  $anchor=$slot==='hero'?'banner-'.$slideId:'slot-'.$slot;
  header('Location: /admin/site-images.php?saved=1#'.rawurlencode($anchor),true,303);exit;
 }
 $images=inyouSiteImages($pdo);
 $heroSlides=inyouHeroImages($pdo,false);
 if(isset($_GET['saved']))$notice='Cambios guardados. Los banners se actualizan en la tienda sin redeploy.';
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

.site-carousel-admin{background:white;border:1px solid #e7ded9;border-radius:16px;padding:23px;margin:24px 0}
.site-carousel-title{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.site-carousel-title h2{font-size:23px;margin:0 0 6px}.site-carousel-title p,.site-carousel-help{font-size:12px;color:#82716c;margin:0;line-height:1.6}
.site-carousel-help{margin:15px 0 20px}.site-carousel-add{border:0;background:#70081e;color:white;padding:13px 18px;border-radius:10px;font-weight:700;cursor:pointer}
.site-carousel-add:disabled{opacity:.5;cursor:not-allowed}
.site-carousel-list{display:grid;gap:16px}
.site-carousel-slide{background:#fffcfa;border:1px solid #e9ded9;border-radius:14px;padding:17px;min-width:0}
.site-carousel-slide-top{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap;padding-bottom:15px}
.site-carousel-slide-top>div:first-child{display:flex;align-items:center;gap:10px}
.site-carousel-slide-top strong{font-size:17px}
.site-slide-badge{display:inline-flex;align-items:center;background:#f4e9e5;color:#895f4d;border-radius:999px;padding:5px 9px;font-size:11px}
.site-slide-badge.is-active{background:#e7f2e9;color:#306947}
.site-carousel-tools{display:flex;align-items:center;flex-wrap:wrap;gap:7px}
.site-carousel-tools button{min-height:40px;min-width:40px;background:#fff;border:1px solid #e9deda;border-radius:9px;padding:8px 11px;color:#70081e;font-size:12px;font-weight:700;cursor:pointer}
.site-carousel-tools button:disabled{opacity:.35;cursor:not-allowed}
.site-carousel-tools .site-slide-remove{color:#a83241;background:#fff5f5}
.site-carousel-preview.desktop{aspect-ratio:1920/450}
.site-carousel-preview.mobile{aspect-ratio:750/507}
.site-carousel-preview img{object-fit:contain}
.site-carousel-admin .site-image-device form.site-upload-form{margin-top:8px}
@media(max-width:620px){.site-carousel-admin{padding:14px}.site-carousel-slide{padding:12px}.site-carousel-title>form{width:100%}.site-carousel-add{width:100%}.site-carousel-tools{width:100%}.site-carousel-preview.desktop{aspect-ratio:1920/450}}
</style>
</head><body class="dashboard"><?php adminSidebar('visuals');?>
<main class="content"><div class="site-images-shell"><header><div>InYou Studio · Apariencia de la tienda</div><a class="btn light" href="/" target="_blank" rel="noopener">Ver tienda ↗</a></header>
<span class="eyebrow">CONTENIDO VISUAL</span><h1>Diseño de la tienda</h1>
<p class="site-image-intro">Elegí qué querés editar. No necesitás recorrer todos los banners para cambiar uno.</p>
<nav class="site-visual-tabs" aria-label="Secciones de imágenes de la tienda">
<button type="button" data-visual-tab="banners" class="is-current" aria-current="page">Banners principales</button>
<button type="button" data-visual-tab="categories">Categorías</button>
<button type="button" data-visual-tab="collections">Colecciones</button>
<button type="button" data-visual-tab="editorial">Campañas y looks</button>
</nav>
<p class="site-image-intro">Cambiá el banner principal, las fotos de categorías, las colecciones y las campañas desde aquí. Cada espacio admite una foto para notebook y otra para celular.</p>
<div class="site-image-note">Tamaños recomendados en píxeles. Usá JPG o WEBP. El sistema optimiza automáticamente las imágenes grandes antes de subirlas. <strong>Primero elegí el archivo y después hacé clic en Guardar imagen.</strong> Si no cargás una foto mobile, se usa la imagen desktop. Si no cargás ninguna, se conserva la imagen actual de la web. Los cambios no modifican las fotos de los productos.</div>
<?php if($error):?><div class="site-message error" role="alert"><?=h($error)?></div><?php endif;?>
<?php if($notice):?><div class="site-message" role="status"><?=h($notice)?></div><?php endif;?>

<section class="site-carousel-admin" id="carousel" data-visual-panel="banners">
 <div class="site-carousel-title">
  <div><h2>Carrusel principal</h2><p>Agregá, activá o desactivá banners desde aquí. Para cambiar fotografías tocá «Editar imágenes».</p></div>
  <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="slide_add"><button type="submit" class="site-carousel-add" <?=count($heroSlides)>=6?'disabled':''?>>＋ Agregar banner</button></form>
 </div>
 <p class="site-carousel-help">Usá el interruptor <strong>Activo / Desactivado</strong> para decidir qué se muestra en la tienda. Podés cambiar el orden con las flechas. Los banners activos cambian cada 5 segundos.</p>
 <div class="site-carousel-list">
 <?php foreach($heroSlides as $position=>$slide):?>
 <article class="site-carousel-slide" id="banner-<?=(int)$slide['id']?>">
  <div class="site-carousel-slide-top">
   <div><strong>Banner <?=(int)$position+1?></strong><span class="site-slide-badge <?=$slide['active']?'is-active':''?>"><?=$slide['active']?'Publicado':'Desactivado'?></span></div>
   <div class="site-carousel-tools">
    <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="slide_up"><input type="hidden" name="slide_id" value="<?=(int)$slide['id']?>"><button type="submit" title="Subir en el carrusel" aria-label="Mover banner hacia arriba" <?=$position===0?'disabled':''?>>↑</button></form>
    <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="slide_down"><input type="hidden" name="slide_id" value="<?=(int)$slide['id']?>"><button type="submit" title="Bajar en el carrusel" aria-label="Mover banner hacia abajo" <?=$position===count($heroSlides)-1?'disabled':''?>>↓</button></form>
    <form method="post" class="site-carousel-status-form" aria-label="Estado del banner <?=(int)$position+1?>">
     <input type="hidden" name="csrf" value="<?=h(csrf())?>">
     <input type="hidden" name="action" value="slide_toggle">
     <input type="hidden" name="slide_id" value="<?=(int)$slide['id']?>">
     <button type="submit" class="site-carousel-switch <?=$slide['active']?'is-on':''?>" role="switch" aria-checked="<?=$slide['active']?'true':'false'?>" aria-label="<?=$slide['active']?'Desactivar':'Activar'?> banner <?=(int)$position+1?>" title="Hacé clic para <?=$slide['active']?'desactivar':'activar'?> el banner">
       <span class="site-switch-track" aria-hidden="true"><span></span></span>
       <span><?=$slide['active']?'Activo':'Desactivado'?></span>
     </button>
    </form>
    <?php if($slide['id']!==0):?><form method="post" onsubmit="return confirm('¿Quitar este banner del carrusel? Las fotos guardadas no se borrarán de Supabase.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="slide_remove"><input type="hidden" name="slide_id" value="<?=(int)$slide['id']?>"><button type="submit" class="site-slide-remove">Quitar</button></form><?php endif;?>
   </div>
  </div>
  <details class="site-carousel-edit" <?=$position===0?'open':''?>>
   <summary>Editar imágenes desktop y mobile <span aria-hidden="true">▾</span></summary>
  <div class="site-image-sizes">
   <?php foreach(['desktop'=>'Desktop / notebook','mobile'=>'Mobile / celular'] as $device=>$label):?>
   <div class="site-image-device">
    <h3><?=h($label)?></h3><small><?=$device==='desktop'?'1920 × 450':'750 × 507'?> px</small>
    <div class="site-image-preview site-carousel-preview <?=$device==='desktop'?'desktop':'mobile'?>">
     <?php if(!empty($slide[$device]['url'])):?><img src="<?=h($slide[$device]['url'])?>" loading="lazy" alt="Vista previa banner <?=(int)$position+1?> <?=h($label)?>"><?php else:?><span>Sin imagen <?=h($device)?></span><?php endif;?>
    </div>
    <form class="site-upload-form" method="post" enctype="multipart/form-data">
     <input type="hidden" name="csrf" value="<?=h(csrf())?>">
     <input type="hidden" name="action" value="upload">
     <input type="hidden" name="slot" value="hero">
     <input type="hidden" name="slide_id" value="<?=(int)$slide['id']?>">
     <input type="hidden" name="device" value="<?=h($device)?>">
     <label class="site-file-picker">＋ Seleccionar imagen <input type="file" name="image" accept="image/jpeg,image/png,image/webp" aria-label="Banner <?=(int)$position+1?> <?=h($label)?>" required></label>
     <p class="site-file-feedback" role="status" aria-live="polite">Elegí una foto para verla antes de guardar.</p>
     <p class="site-upload-notice" role="alert" aria-live="polite"></p>
     <button type="submit" disabled>Guardar imagen</button>
    </form>
    <?php if(!empty($slide[$device]['path'])):?>
    <form method="post" onsubmit="return confirm('¿Restablecer esta imagen del banner?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="reset"><input type="hidden" name="slot" value="hero"><input type="hidden" name="slide_id" value="<?=(int)$slide['id']?>"><input type="hidden" name="device" value="<?=h($device)?>"><button type="submit" class="site-reset">Restablecer imagen</button></form>
    <?php endif;?>
   </div>
   <?php endforeach;?>
  </div>
  </details>
 </article>
 <?php endforeach;?>
 </div>
</section>

<div class="site-image-grid" id="site-visual-grid">
<?php foreach($slots as $slot=>$meta):if($slot==='hero')continue;?>
<section class="site-image-card" data-visual-group="<?=str_starts_with($slot,'category_')?'categories':(str_starts_with($slot,'collection_')?'collections':'editorial')?>" id="slot-<?=h($slot)?>"><h2><?=h($meta[0])?></h2><p>Proporción recomendada: <?=h($meta[3])?></p><div class="site-image-sizes">
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
</div></div></main><script defer src="/admin/site-image-upload.js?v=2"></script><script defer src="/admin/site-visual-tabs.js?v=1"></script></body></html>