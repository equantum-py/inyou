<?php
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/lib/inyou-storage.php';
requireAuth();
$canEdit=canManageCatalog();$error='';$notice='';$pdo=null;$product=null;$images=[];
$productId=filter_var($_GET['product']??$_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$productId){http_response_code(400);exit('Producto no válido.');}
try{
 $pdo=db();$q=$pdo->prepare('SELECT id,name,status FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch();if(!$product)throw new RuntimeException('Producto no encontrado.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$canEdit){http_response_code(403);throw new RuntimeException('No tenés permiso para modificar imágenes.');}
  if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Recargá la página.');}
  $action=(string)($_POST['action']??'');$id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if($action==='upload'){
   if(!isset($_FILES['image'])||!is_array($_FILES['image'])){
    $contentLength=(int)($_SERVER['CONTENT_LENGTH']??0);
    $postLimit=ini_get('post_max_size');
    throw new RuntimeException($contentLength>0&&empty($_POST)?'La imagen no llegó al servidor. El archivo puede superar el límite de carga de PHP ('.($postLimit?:'desconocido').').':'Primero seleccioná una fotografía JPG, PNG o WEBP.');
   }
   $uploadCode=(int)($_FILES['image']['error']??UPLOAD_ERR_NO_FILE);
   if($uploadCode!==UPLOAD_ERR_OK){
    $uploadMessages=[
     UPLOAD_ERR_INI_SIZE=>'La fotografía supera el límite de carga configurado en el servidor.',
     UPLOAD_ERR_FORM_SIZE=>'La fotografía supera el tamaño máximo permitido por el formulario.',
     UPLOAD_ERR_PARTIAL=>'La fotografía se cargó parcialmente. Comprobá tu conexión e intentá nuevamente.',
     UPLOAD_ERR_NO_FILE=>'No se seleccionó ninguna fotografía. Elegí un archivo antes de subir.',
     UPLOAD_ERR_NO_TMP_DIR=>'El servidor no dispone de espacio temporal para recibir la fotografía.',
     UPLOAD_ERR_CANT_WRITE=>'El servidor no pudo guardar temporalmente la fotografía.',
     UPLOAD_ERR_EXTENSION=>'Una extensión de PHP bloqueó la carga de la fotografía.'
    ];
    error_log('InYou image upload rejected by PHP, code='.$uploadCode.'; upload_max_filesize='.ini_get('upload_max_filesize').'; post_max_size='.ini_get('post_max_size'));
    throw new RuntimeException($uploadMessages[$uploadCode]??'No se pudo recibir la fotografía (código '.$uploadCode.').');
   }
   $file=$_FILES['image'];if((int)$file['size']<=0||(int)$file['size']>INYOU_STORAGE_MAX_BYTES)throw new RuntimeException('La imagen debe pesar entre 1 byte y 10 MB.');
   $tmp=(string)$file['tmp_name'];$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($tmp);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];if(!isset($allowed[$mime]))throw new RuntimeException('Formato no permitido. Usá JPG, PNG o WEBP.');
   $dimensions=@getimagesize($tmp);if(!$dimensions||$dimensions[0]<1||$dimensions[1]<1||((int)$dimensions[0]*(int)$dimensions[1])>25000000)throw new RuntimeException('Dimensiones de imagen inválidas o superiores a 25 megapíxeles.');
   $bytes=file_get_contents($tmp);if($bytes===false||strlen($bytes)<1||strlen($bytes)>INYOU_STORAGE_MAX_BYTES)throw new RuntimeException('No se pudo leer la imagen subida.');
   // Supabase Storage es persistente; MySQL conserva únicamente los metadatos.
   $name='p'.$productId.'-'.bin2hex(random_bytes(16)).'.'.$allowed[$mime];
   $storagePath='supabase/products/'.$name;
   $alt=trim((string)($_POST['alt_text']??''));if(mb_strlen($alt)>255)$alt=mb_substr($alt,0,255);$sortRaw=trim((string)($_POST['sort_order']??'0'));if(!preg_match('/^-?\d+$/',$sortRaw))throw new RuntimeException('Orden inválido.');$sort=(int)$sortRaw;$isPrimary=isset($_POST['is_primary'])?1:0;
   inyouStorageUpload($storagePath,$bytes,$mime);
   try{
    $pdo->beginTransaction();
    if($isPrimary)$pdo->prepare('UPDATE product_images SET is_primary=0 WHERE product_id=?')->execute([$productId]);
    $q=$pdo->prepare('INSERT INTO product_images (product_id,file_path,alt_text,is_primary,sort_order) VALUES (?,?,?,?,?)');
    $q->execute([$productId,$storagePath,$alt,$isPrimary,$sort]);$id=(int)$pdo->lastInsertId();
    $pdo->commit();
   }catch(Throwable $uploadError){
    if($pdo->inTransaction())$pdo->rollBack();
    try{inyouStorageDelete($storagePath);}catch(Throwable $cleanupError){error_log('InYou orphan storage cleanup failed: '.$cleanupError->getMessage());}
    throw $uploadError;
   }
   auditLog($pdo,'image.upload','product_image',$id);
   header('Location: /admin/images.php?product='.$productId.'&saved=1',true,303);exit;
  }
  if($action==='meta'){
   if(!$id)throw new RuntimeException('Imagen no válida.');$alt=trim((string)($_POST['alt_text']??''));if(mb_strlen($alt)>255)$alt=mb_substr($alt,0,255);$sortRaw=trim((string)($_POST['sort_order']??'0'));if(!preg_match('/^-?\d+$/',$sortRaw))throw new RuntimeException('Orden inválido.');$q=$pdo->prepare('UPDATE product_images SET alt_text=?,sort_order=? WHERE id=? AND product_id=?');$q->execute([$alt,(int)$sortRaw,$id,$productId]);auditLog($pdo,'image.update','product_image',(int)$id);header('Location: /admin/images.php?product='.$productId.'&saved=1',true,303);exit;
  }
  if($action==='primary'){
   if(!$id)throw new RuntimeException('Imagen no válida.');$pdo->beginTransaction();$pdo->prepare('UPDATE product_images SET is_primary=0 WHERE product_id=?')->execute([$productId]);$q=$pdo->prepare('UPDATE product_images SET is_primary=1 WHERE id=? AND product_id=?');$q->execute([$id,$productId]);if(!$q->rowCount())throw new RuntimeException('Imagen no encontrada.');$pdo->commit();auditLog($pdo,'image.primary','product_image',(int)$id);header('Location: /admin/images.php?product='.$productId.'&saved=1',true,303);exit;
  }
  if($action==='delete'){
   if(!$id)throw new RuntimeException('Imagen no válida.');if(($product['status']??'')==='active'){$q=$pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id=? AND id<>?');$q->execute([$productId,$id]);if(!(int)$q->fetchColumn())throw new RuntimeException('No podés eliminar la última imagen de un producto publicado.');}
   $q=$pdo->prepare('SELECT file_path,is_primary FROM product_images WHERE id=? AND product_id=?');$q->execute([$id,$productId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Imagen no encontrada.');
   if(inyouStorageIsPath((string)$row['file_path']))inyouStorageDelete((string)$row['file_path']);
   try{$pdo->prepare('DELETE FROM product_image_blobs WHERE image_id=?')->execute([$id]);}catch(PDOException $missingBlobs){/* tabla opcional para imágenes antiguas */}
   $pdo->prepare('DELETE FROM product_images WHERE id=? AND product_id=?')->execute([$id,$productId]);if(!empty($row['is_primary'])){$q=$pdo->prepare('SELECT id FROM product_images WHERE product_id=? ORDER BY sort_order,id LIMIT 1');$q->execute([$productId]);$next=$q->fetchColumn();if($next)$pdo->prepare('UPDATE product_images SET is_primary=1 WHERE id=?')->execute([(int)$next]);}
   $path=dirname(__DIR__).'/'.(string)$row['file_path'];$base=realpath(dirname(__DIR__).'/assets/products');$real=realpath($path);if($base&&$real&&str_starts_with($real,$base.DIRECTORY_SEPARATOR)&&is_file($real))@unlink($real);auditLog($pdo,'image.delete','product_image',(int)$id);header('Location: /admin/images.php?product='.$productId.'&saved=1',true,303);exit;
  }
  throw new RuntimeException('Acción no válida.');
 }
 try{$q=$pdo->prepare('SELECT pi.id,pi.file_path,pi.alt_text,pi.is_primary,pi.sort_order,pi.created_at,IF(pb.image_id IS NULL,0,1) AS stored_in_db FROM product_images pi LEFT JOIN product_image_blobs pb ON pb.image_id=pi.id WHERE pi.product_id=? ORDER BY pi.is_primary DESC,pi.sort_order,pi.id');$q->execute([$productId]);$images=$q->fetchAll();}catch(PDOException $readError){if($readError->getCode()!=='42S02')throw $readError;$fallback=$pdo->prepare('SELECT id,file_path,alt_text,is_primary,sort_order,created_at,0 AS stored_in_db FROM product_images WHERE product_id=? ORDER BY is_primary DESC,sort_order,id');$fallback->execute([$productId]);$images=$fallback->fetchAll();}if(isset($_GET['saved']))$notice='Imágenes actualizadas.';
}catch(Throwable $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();error_log('InYou imágenes: '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo completar la operación en MySQL.':$e->getMessage();}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Imágenes · InYou</title><link rel="stylesheet" href="/admin/style.css"><style>.wrap{max-width:1100px;margin:auto}.panel{background:#fff;border:1px solid #e7e5df;border-radius:12px;padding:22px;margin:20px 0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.card{border:1px solid #e6e4df;border-radius:10px;overflow:hidden;background:#fff}.card img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}.body{padding:12px}.input{width:100%;padding:10px;border:1px solid #d9d8d3;border-radius:7px}.btn{background:#202321;color:#fff;border:0;border-radius:7px;padding:10px 14px;text-decoration:none}.btn.secondary{background:#fff;color:#222;border:1px solid #d9d8d3}.actions{display:flex;gap:8px;flex-wrap:wrap}.alert{padding:12px;border-radius:8px;background:#e4f2e9}.error{background:#f9e6e4}.muted{color:#777}@media(max-width:800px){.grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.grid{grid-template-columns:1fr}}</style></head><body class="dashboard"><aside class="sidebar"><div class="brand">in<span>You</span><i>.</i></div><div class="eyebrow">PANEL ADMINISTRATIVO</div><nav><a href="/admin/">Resumen</a><a class="selected" href="/admin/products.php">Productos</a><a href="/admin/collections.php">Colecciones</a></nav></aside><main class="content"><div class="wrap"><header><div>Hola, <?=h($_SESSION['admin_name']??'Administrador')?></div></header><div class="eyebrow">MEDIOS</div><h1><?=h($product['name']??'Producto')?></h1><p class="muted">Subí JPG, PNG o WEBP, máximo 10 MB y 25 MP. Las nuevas imágenes se guardan permanentemente en Supabase Storage.</p><?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="alert"><?=h($notice)?></div><?php endif;?>
<?php if($canEdit):?><section class="panel"><h2>Subir imagen</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="product_id" value="<?=$productId?>"><p><input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp" required></p><p><input class="input" name="alt_text" maxlength="255" placeholder="Texto alternativo"></p><p><input class="input" type="number" name="sort_order" value="0" placeholder="Orden"></p><p><label><input type="checkbox" name="is_primary" value="1"> Usar como imagen principal</label></p><div class="actions"><button class="btn">Subir imagen</button><a class="btn secondary" href="/admin/products.php?edit=<?=$productId?>">Volver al producto</a></div></form></section><?php endif;?>
<section class="panel"><h2>Galería</h2><div class="grid"><?php foreach($images as $img):?><article class="card"><?php $localImage=dirname(__DIR__).'/'.(string)$img['file_path'];$hasDb=!empty($img['stored_in_db']);$hasLocal=is_file($localImage);$hasStorage=inyouStorageIsPath((string)$img['file_path']);?><?php if($hasStorage):?><img src="<?=h(inyouStoragePublicUrl((string)$img['file_path']))?>" loading="lazy" alt="<?=h($img['alt_text'])?>"><?php elseif($hasDb):?><img src="/admin/image-file.php?id=<?=(int)$img['id']?>" loading="lazy" alt="<?=h($img['alt_text'])?>"><?php elseif($hasLocal):?><img src="/<?=h($img['file_path'])?>" loading="lazy" alt="<?=h($img['alt_text'])?>"><?php else:?><div style="aspect-ratio:1/1;display:flex;align-items:center;justify-content:center;background:#f9eceb;color:#70081E;padding:20px;text-align:center;font-size:13px">Archivo no disponible. Volvé a subir la fotografía original.</div><?php endif;?><div class="body"><strong><?=$img['is_primary']?'Principal':'Imagen'?></strong><?php if($canEdit):?><form method="post" style="margin:10px 0"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="meta"><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="id" value="<?=(int)$img['id']?>"><p><input class="input" name="alt_text" maxlength="255" value="<?=h($img['alt_text'])?>" placeholder="Texto alternativo"></p><p><input class="input" type="number" name="sort_order" value="<?=(int)$img['sort_order']?>"></p><button class="btn secondary">Guardar datos</button></form><div class="actions"><?php if(!$img['is_primary']):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="primary"><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="id" value="<?=(int)$img['id']?>"><button class="btn secondary">Hacer principal</button></form><?php endif;?><form method="post" onsubmit="return confirm('¿Eliminar imagen?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="id" value="<?=(int)$img['id']?>"><button class="btn secondary">Eliminar</button></form></div><?php else:?><p class="muted"><?=h($img['alt_text']?:'Sin texto alternativo')?></p><?php endif;?></div></article><?php endforeach;?><?php if(!$images):?><p>Todavía no hay imágenes cargadas.</p><?php endif;?></div></section></div></main></body></html>