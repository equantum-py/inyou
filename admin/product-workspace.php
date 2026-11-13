<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/lib/inyou-storage.php';
requireAuth();

$id=filter_var($_GET['product']??$_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(400);exit('Producto no válido.');}
$canEdit=canManageCatalog();$error='';$notice='';$product=null;$variants=[];$photos=[];$collections=[];
try{
 $pdo=db();
 $find=$pdo->prepare("SELECT p.*, (SELECT pc.collection_id FROM product_collections pc WHERE pc.product_id=p.id ORDER BY pc.sort_order LIMIT 1) AS collection_id FROM products p WHERE p.id=?");
 $find->execute([$id]);$product=$find->fetch();
 if(!$product)throw new RuntimeException('Producto no encontrado.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$canEdit){http_response_code(403);throw new RuntimeException('No tenés permiso para editar productos.');}
  if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Actualizá la página.');}
  $action=(string)($_POST['action']??'');
  if($action==='details'){
   $name=trim((string)($_POST['name']??''));$description=trim((string)($_POST['description']??''));
   $priceRaw=trim((string)($_POST['base_price']??''));$collectionId=filter_var($_POST['collection_id']??null,FILTER_VALIDATE_INT);
   if($name===''||mb_strlen($name)>200)throw new RuntimeException('Ingresá un nombre de hasta 200 caracteres.');
   if(!preg_match('/^[0-9]{1,12}$/',$priceRaw)||(int)$priceRaw<1)throw new RuntimeException('Ingresá el precio en guaraníes, sin puntos ni comas.');
   if(!$collectionId)throw new RuntimeException('Seleccioná una colección.');
   $valid=$pdo->prepare('SELECT COUNT(*) FROM collections WHERE id=? AND is_active=1');$valid->execute([$collectionId]);
   if(!(int)$valid->fetchColumn())throw new RuntimeException('La colección seleccionada no está disponible.');
   $pdo->beginTransaction();
   $pdo->prepare('UPDATE products SET name=?,description=?,base_price=? WHERE id=?')->execute([$name,$description,(int)$priceRaw,$id]);
   $pdo->prepare('DELETE FROM product_collections WHERE product_id=?')->execute([$id]);
   $pdo->prepare('INSERT INTO product_collections (product_id,collection_id,sort_order) VALUES (?,?,0)')->execute([$id,$collectionId]);
   $pdo->commit();auditLog($pdo,'product.update','product',$id);
  }elseif($action==='variants'){
   $rows=$_POST['variants']??null;
   if(!is_array($rows)||count($rows)>200)throw new RuntimeException('No se recibieron variantes válidas.');
   $owned=$pdo->prepare('SELECT id FROM product_variants WHERE product_id=?');$owned->execute([$id]);
   $ids=array_map('intval',$owned->fetchAll(PDO::FETCH_COLUMN));
   if(count($rows)!==count($ids))throw new RuntimeException('La lista de variantes cambió. Actualizá la página.');
   $updates=[];
   foreach($rows as $variantId=>$values){
    if(!ctype_digit((string)$variantId)||!in_array((int)$variantId,$ids,true)||!is_array($values))throw new RuntimeException('Variante no válida.');
    $stockRaw=trim((string)($values['stock']??''));
    if(!preg_match('/^\d{1,8}$/',$stockRaw))throw new RuntimeException('El stock debe ser un número válido.');
    $stock=(int)$stockRaw;
    $priceRaw=trim((string)($values['price']??''));
    if($priceRaw!==''&&(!preg_match('/^\d{1,12}$/',$priceRaw)||(int)$priceRaw<1))throw new RuntimeException('Precio de variante inválido.');
    $active=isset($values['active'])?1:0;
    $updates[]=[(int)$variantId,$stock,$priceRaw===''?null:(int)$priceRaw,$active];
   }
   $pdo->beginTransaction();
   $save=$pdo->prepare('UPDATE product_variants SET stock_qty=?,price_override=?,is_active=? WHERE id=? AND product_id=?');
   foreach($updates as [$variantId,$stock,$price,$active])$save->execute([$stock,$price,$active,$variantId,$id]);
   $pdo->commit();auditLog($pdo,'variant.bulk_update','product',$id);
  }elseif($action==='photo'){
   if(!isset($_FILES['image'])||!is_array($_FILES['image']))throw new RuntimeException('Elegí una fotografía antes de subir.');
   $file=$_FILES['image'];$uploadCode=(int)($file['error']??UPLOAD_ERR_NO_FILE);
   if($uploadCode!==UPLOAD_ERR_OK){
    $messages=[UPLOAD_ERR_INI_SIZE=>'El archivo supera el límite de PHP.',UPLOAD_ERR_FORM_SIZE=>'El archivo supera el límite permitido.',UPLOAD_ERR_PARTIAL=>'La carga se interrumpió.',UPLOAD_ERR_NO_FILE=>'Elegí una fotografía antes de subir.'];
    throw new RuntimeException($messages[$uploadCode]??'No se pudo recibir la fotografía (código '.$uploadCode.').');
   }
   if((int)$file['size']<1||(int)$file['size']>INYOU_STORAGE_MAX_BYTES)throw new RuntimeException('La fotografía debe pesar menos de 10 MB.');
   $tmp=(string)$file['tmp_name'];$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
   $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
   if(!isset($allowed[$mime]))throw new RuntimeException('Solo se aceptan JPG, PNG y WEBP.');
   $dims=@getimagesize($tmp);
   if(!$dims||$dims[0]<1||$dims[1]<1||$dims[0]*$dims[1]>25000000)throw new RuntimeException('La imagen debe tener como máximo 25 megapíxeles.');
   $bytes=file_get_contents($tmp);if($bytes===false)throw new RuntimeException('No se pudo leer el archivo.');
   $path='supabase/products/p'.$id.'-'.bin2hex(random_bytes(16)).'.'.$allowed[$mime];
   inyouStorageUpload($path,$bytes,$mime);
   try{
    $pdo->beginTransaction();
    $count=$pdo->prepare('SELECT COUNT(*) FROM product_images WHERE product_id=?');$count->execute([$id]);
    $primary=(int)$count->fetchColumn()===0?1:0;
    if(isset($_POST['primary'])){$primary=1;$pdo->prepare('UPDATE product_images SET is_primary=0 WHERE product_id=?')->execute([$id]);}
    $q=$pdo->prepare('INSERT INTO product_images (product_id,file_path,alt_text,is_primary,sort_order) VALUES (?,?,?,?,?)');
    $q->execute([$id,$path,mb_substr(trim((string)($_POST['alt_text']??'')),0,255),$primary,0]);
    $photoId=(int)$pdo->lastInsertId();$pdo->commit();
   }catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    try{inyouStorageDelete($path);}catch(Throwable $ignore){}
    throw $e;
   }
   auditLog($pdo,'image.upload','product_image',$photoId);
  }elseif($action==='primary'){
   $photoId=filter_var($_POST['image_id']??null,FILTER_VALIDATE_INT);
   $q=$pdo->prepare('SELECT COUNT(*) FROM product_images WHERE id=? AND product_id=?');$q->execute([$photoId,$id]);
   if(!(int)$q->fetchColumn())throw new RuntimeException('Fotografía no encontrada.');
   $pdo->beginTransaction();$pdo->prepare('UPDATE product_images SET is_primary=0 WHERE product_id=?')->execute([$id]);
   $pdo->prepare('UPDATE product_images SET is_primary=1 WHERE id=? AND product_id=?')->execute([$photoId,$id]);
   $pdo->commit();auditLog($pdo,'image.primary','product_image',(int)$photoId);
  }else{throw new RuntimeException('Acción no permitida.');}
  header('Location: /admin/product-workspace.php?product='.$id.'&saved=1',true,303);exit;
 }
 $collections=$pdo->query('SELECT id,name FROM collections WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
 $q=$pdo->prepare("SELECT pv.id,pv.sku,pv.color_name,pv.stock_qty,pv.price_override,pv.is_active,pv.availability_mode,COALESCE(s.label,'Único') size_label FROM product_variants pv LEFT JOIN sizes s ON s.id=pv.size_id WHERE pv.product_id=? ORDER BY pv.color_name,s.sort_order,pv.sort_order,pv.id");
 $q->execute([$id]);$variants=$q->fetchAll();
 $q=$pdo->prepare('SELECT id,file_path,alt_text,is_primary FROM product_images WHERE product_id=? ORDER BY is_primary DESC,sort_order,id');$q->execute([$id]);$photos=$q->fetchAll();
 if(isset($_GET['saved']))$notice='Cambios guardados.';
}catch(Throwable $e){
 if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
 error_log('InYou workspace: '.$e->getMessage());
 $error=$e instanceof PDOException?'No se pudo guardar el cambio en MySQL.':$e->getMessage();
}
$hasPhoto=false;foreach($photos as $p){if(inyouStorageIsPath((string)$p['file_path'])){$hasPhoto=true;break;}}
$available=0;foreach($variants as $v){if($v['is_active']&&($v['availability_mode']==='bajo_pedido'||(int)$v['stock_qty']>0))$available++;}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editar <?=h($product['name']??'producto')?> · InYou</title><link rel="stylesheet" href="/admin/style.css">
<style>
.workspace{max-width:1150px;margin:auto}.workspace-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin:12px 0 24px}.workspace h1{margin:5px 0;font-size:clamp(30px,3.5vw,44px)}.workspace .sub{font-size:13px;color:#767675;margin:5px 0}.workspace .quick-nav{display:flex;gap:9px;overflow-x:auto;padding:3px 0 14px;margin-bottom:5px}.workspace .quick-nav a{display:inline-block;white-space:nowrap;border-radius:30px;border:1px solid #ddd7d0;padding:10px 16px;background:#fff;color:#282525;text-decoration:none;font-size:13px}.workspace section.block{background:#fff;border:1px solid #e8e2dc;border-radius:18px;padding:25px;margin:16px 0;scroll-margin-top:16px;box-shadow:0 6px 22px rgba(49,30,25,.035)}.workspace .section-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:16px}.workspace h2{font-size:20px;letter-spacing:-.025em;margin:0}.workspace .hint{font-size:13px;color:#767675;margin:4px 0 0}.workspace label.caption{font-size:12px;font-weight:700;color:#555;display:block;margin-bottom:7px}.workspace input[type=text],.workspace input[type=number],.workspace textarea,.workspace select,.workspace input[type=file]{width:100%;padding:12px;border:1px solid #ddd9d4;border-radius:10px;background:#fff;font:inherit;font-size:14px;min-width:0}.workspace input:focus,.workspace textarea:focus,.workspace select:focus{outline:2px solid #c3dacc;outline-offset:1px}.workspace .fields{display:grid;grid-template-columns:1fr 1fr;gap:15px}.workspace .field-full{grid-column:1/-1}.workspace textarea{min-height:90px}.workspace .btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;background:#212624;color:white;padding:12px 17px;border:0;border-radius:10px;font-size:13px;text-decoration:none;cursor:pointer;font-weight:600}.workspace .btn.light{background:#f5f3ef;color:#30302d;border:1px solid #e5dfd8}.workspace .btn.small{padding:8px 11px;font-size:12px}.workspace .savebar{display:flex;align-items:center;gap:12px;margin-top:15px;flex-wrap:wrap}.workspace .variant-table{width:100%;border-collapse:collapse}.workspace .variant-table th{text-align:left;font-size:11px;text-transform:uppercase;color:#888;letter-spacing:.07em}.workspace .variant-table td,.workspace .variant-table th{padding:11px 8px;border-bottom:1px solid #efebe7}.workspace .variant-table input[type=number]{width:110px;padding:9px}.workspace .variant-table input[type=checkbox]{width:19px;height:19px;accent-color:#70081e}.workspace .variant-table .v-name{font-weight:700;font-size:13px}.workspace .variant-table .v-sku{display:block;font-size:10px;color:#999;margin-top:4px}.workspace .photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:12px}.workspace .photo{padding:8px;background:#faf8f5;border-radius:12px}.workspace .photo img,.workspace .photo .missing{width:100%;height:145px;object-fit:cover;border-radius:8px;background:#f2e8e6}.workspace .photo .missing{display:grid;place-items:center;text-align:center;font-size:12px;padding:12px;color:#8e5757}.workspace .photo .photo-actions{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;align-items:center;font-size:12px}.workspace .upload-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}.workspace .flag{font-size:12px;border-radius:20px;padding:7px 10px;background:#f2f0eb;display:inline-block;margin:3px 4px 3px 0}.workspace .flag.good{background:#e3f2e9;color:#245e43}.workspace .notice{padding:12px 16px;border-radius:10px;background:#e4f3e9;color:#235a39;margin:12px 0}.workspace .err{padding:12px 16px;border-radius:10px;background:#fcecea;color:#842c2c;margin:12px 0}.workspace .separator{border-top:1px solid #ece8e3;margin:20px 0}.workspace .advanced{font-size:13px;color:#777}.workspace .advanced a{color:#70081e}
@media(max-width:760px){.workspace .fields,.workspace .upload-row{grid-template-columns:1fr}.workspace section.block{padding:17px;border-radius:14px}.workspace .variant-table th:nth-child(3),.workspace .variant-table td:nth-child(3){display:none}.workspace .variant-table input[type=number]{width:76px}.workspace-top{display:block}.workspace .section-head{display:block}}
</style></head><body class="dashboard">
<?php adminSidebar('products');?>
<main class="content"><div class="workspace"><header><a href="/admin/products.php" style="text-decoration:none;color:#6a6862">← Volver a productos</a><span style="font-size:12px;color:#777">Editor simple · InYou</span></header>
<div class="workspace-top"><div><div class="eyebrow">EDITAR PRODUCTO</div><h1><?=h($product['name']??'Producto')?></h1><p class="sub">Todo lo importante en una sola pantalla, sin pasos innecesarios.</p><span class="flag"><?=h($product['status']==='active'?'Publicado':'Borrador / no publicado')?></span><span class="flag <?=$hasPhoto?'good':''?>"><?=$hasPhoto?'Foto cargada':'Falta foto disponible'?></span><span class="flag <?=$available?'good':''?>"><?=$available?'Variantes disponibles: '.$available:'Sin variantes disponibles'?></span></div><a class="btn light" href="/admin/products.php?edit=<?=$id?>">Opciones avanzadas</a></div>
<?php if($error):?><div class="err"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=h($notice)?></div><?php endif;?>
<nav class="quick-nav" aria-label="Ir a sección"><a href="#datos">Datos y precio</a><a href="#fotos">Fotografías</a><a href="#talles">Talles, colores y stock</a></nav>
<section class="block" id="datos"><div class="section-head"><div><h2>1. Datos del producto</h2><p class="hint">Nombre, precio y descripción. Nada más para empezar.</p></div></div>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="details"><div class="fields"><div class="field-full"><label class="caption" for="pname">Nombre</label><input id="pname" type="text" name="name" maxlength="200" required value="<?=h($product['name'])?>" <?=$canEdit?'':'disabled'?>></div><div><label class="caption" for="pprice">Precio base en G.</label><input id="pprice" type="number" name="base_price" min="1" required value="<?=(int)$product['base_price']?>" <?=$canEdit?'':'disabled'?>></div><div><label class="caption" for="pcoll">Colección</label><select id="pcoll" name="collection_id" required <?=$canEdit?'':'disabled'?>><?php foreach($collections as $c):?><option value="<?=(int)$c['id']?>" <?=((int)$c['id']===(int)$product['collection_id'])?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></div><div class="field-full"><label class="caption" for="pdesc">Descripción</label><textarea id="pdesc" name="description" <?=$canEdit?'':'disabled'?>><?=h($product['description']??'')?></textarea></div></div><?php if($canEdit):?><div class="savebar"><button class="btn">Guardar datos</button></div><?php endif;?></form></section>
<section class="block" id="fotos"><div class="section-head"><div><h2>2. Fotografías</h2><p class="hint">Subí varias fotos. La primera se marca como principal automáticamente.</p></div><span class="flag"><?=count($photos)?> registrada(s)</span></div>
<?php if($photos):?><div class="photo-grid"><?php foreach($photos as $photo):?><div class="photo"><?php $path=(string)$photo['file_path'];$src=inyouStorageIsPath($path)?inyouStoragePublicUrl($path):'';?><?php if($src):?><img src="<?=h($src)?>" loading="lazy" alt="<?=h($photo['alt_text'])?>"><?php else:?><div class="missing">Imagen antigua no disponible</div><?php endif;?><div class="photo-actions"><?php if($photo['is_primary']):?><strong>Principal</strong><?php elseif($canEdit):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="primary"><input type="hidden" name="image_id" value="<?=(int)$photo['id']?>"><button class="btn light small" type="submit">Hacer principal</button></form><?php endif;?></div></div><?php endforeach;?></div><?php endif;?>
<?php if($canEdit):?><div class="separator"></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="photo"><div class="upload-row"><div><label class="caption" for="newphoto">Agregar fotografía (JPG, PNG o WEBP · máx. 10 MB)</label><input id="newphoto" type="file" name="image" accept="image/jpeg,image/png,image/webp" required></div><button class="btn">+ Subir fotografía</button></div><label style="display:inline-block;margin-top:12px;font-size:13px"><input type="checkbox" name="primary" value="1"> Usar como foto principal</label></form><?php endif;?><p class="advanced">¿Querés reordenar o eliminar fotos? <a href="/admin/images.php?product=<?=$id?>">Abrir opciones avanzadas de fotos</a>.</p></section>
<section class="block" id="talles"><div class="section-head"><div><h2>3. Talles, colores y stock</h2><p class="hint">Las combinaciones ya están creadas. Activá las que quieras vender y completá su stock.</p></div><span class="flag"><?=count($variants)?> variantes</span></div>
<?php if($variants):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="variants"><div style="overflow-x:auto"><table class="variant-table"><thead><tr><th>Prenda</th><th>Stock</th><th>Precio especial G.</th><th>Disponible</th></tr></thead><tbody><?php foreach($variants as $v):?><tr><td><span class="v-name"><?=h($v['color_name'])?> · <?=h($v['size_label'])?></span><span class="v-sku"><?=h($v['sku'])?></span></td><td><input type="number" min="0" max="99999999" name="variants[<?=(int)$v['id']?>][stock]" value="<?=(int)$v['stock_qty']?>" aria-label="Stock <?=h($v['color_name'].' '.$v['size_label'])?>" <?=$canEdit?'':'disabled'?>></td><td><input type="number" min="1" name="variants[<?=(int)$v['id']?>][price]" value="<?=h((string)($v['price_override']??''))?>" placeholder="Base" aria-label="Precio especial <?=h($v['color_name'].' '.$v['size_label'])?>" <?=$canEdit?'':'disabled'?>></td><td><input type="checkbox" name="variants[<?=(int)$v['id']?>][active]" value="1" <?=!empty($v['is_active'])?'checked':''?> aria-label="Activar <?=h($v['color_name'].' '.$v['size_label'])?>" <?=$canEdit?'':'disabled'?>></td></tr><?php endforeach;?></tbody></table></div><?php if($canEdit):?><div class="savebar"><button class="btn">Guardar talles y stock</button><span class="hint">No publica el producto automáticamente.</span></div><?php endif;?></form><?php else:?><p>Todavía no hay talles. <a href="/admin/variants.php?product=<?=$id?>">Crear primera variante</a>.</p><?php endif;?><p class="advanced" style="margin-top:18px">¿Necesitás agregar otra talla, color o SKU? <a href="/admin/variants.php?product=<?=$id?>">Configuración avanzada de variantes</a>.</p></section>
<section class="block" style="background:#f9f7f4"><div class="section-head"><div><h2>Publicación</h2><p class="hint">Se mantiene separada por seguridad: revisá fotos, precio y stock antes de publicar.</p></div><a class="btn light" href="/admin/products.php">Ver estado en listado</a></div></section>
</div></main></body></html>
