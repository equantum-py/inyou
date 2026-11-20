<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/lib/inyou-storage.php';
requireAuth();

$id=filter_var($_GET['product']??$_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$isNew=(!$id&&isset($_GET['new']));
if(!$id&&!$isNew){http_response_code(400);exit('Producto no válido.');}
$canEdit=canManageCatalog();$error='';$notice='';$product=null;$variants=[];$photos=[];$collections=[];$sizes=[];
try{
 $pdo=db();
 if($id){
  $find=$pdo->prepare("SELECT p.*, (SELECT pc.collection_id FROM product_collections pc WHERE pc.product_id=p.id ORDER BY pc.sort_order LIMIT 1) AS collection_id FROM products p WHERE p.id=?");
  $find->execute([$id]);$product=$find->fetch();
  if(!$product)throw new RuntimeException('Producto no encontrado.');
 }
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$canEdit){http_response_code(403);throw new RuntimeException('No tenés permiso para editar productos.');}
  if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Actualizá la página.');}
  $action=(string)($_POST['action']??'');
  if($action==='create'){
   if(!$isNew)throw new RuntimeException('La prenda ya está creada.');
   $name=trim((string)($_POST['name']??''));
   $description=trim((string)($_POST['description']??''));
   $priceRaw=trim((string)($_POST['base_price']??''));
   $collectionId=filter_var($_POST['collection_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $type=(string)($_POST['product_type']??'remera');
   $audience=(string)($_POST['audience']??'Unisex');
   $fit=(string)($_POST['fit']??'');
   $requestedStatus=(string)($_POST['status']??'draft');
   if($name===''||mb_strlen($name)>200)throw new RuntimeException('Ingresá el nombre de la prenda (máximo 200 caracteres).');
   if(!preg_match('/^[0-9]{1,12}$/',$priceRaw)||(int)$priceRaw<=0)throw new RuntimeException('Ingresá un precio válido en guaraníes.');
   if(!in_array($type,['remera','kepi'],true)||!in_array($audience,['Mujer','Hombre','Unisex'],true)||!in_array($fit,['','Regular','Oversize','Cropped','Único'],true))throw new RuntimeException('Revisá tipo, público y corte de la prenda.');
   if(!in_array($requestedStatus,['draft','active'],true))throw new RuntimeException('Estado no válido.');
   if(!$collectionId)throw new RuntimeException('Elegí una colección.');
   $valid=$pdo->prepare('SELECT COUNT(*) FROM collections WHERE id=? AND is_active=1');
   $valid->execute([$collectionId]);if(!(int)$valid->fetchColumn())throw new RuntimeException('Elegí una colección activa.');
   $rows=$_POST['new_variants']??[];
   if(!is_array($rows)||count($rows)>50)throw new RuntimeException('Máximo 50 combinaciones de talle y color.');
   $validatedVariants=[];$used=[];
   $sizeCheck=$pdo->prepare('SELECT COUNT(*) FROM sizes WHERE id=? AND is_active=1');
   foreach($rows as $row){
    if(!is_array($row))throw new RuntimeException('Variante inválida.');
    $color=trim((string)($row['color']??''));$sizeRaw=trim((string)($row['size_id']??''));
    if($color===''&&$sizeRaw==='')continue;
    $sizeId=filter_var($sizeRaw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $stockRaw=trim((string)($row['stock']??'0'));$extraPrice=trim((string)($row['price']??''));
    if(!$sizeId||$color===''||mb_strlen($color)>45)throw new RuntimeException('Cada combinación necesita talle y color válidos.');
    $sizeCheck->execute([$sizeId]);if(!(int)$sizeCheck->fetchColumn())throw new RuntimeException('Uno de los talles no está disponible.');
    if(!preg_match('/^\d{1,8}$/',$stockRaw))throw new RuntimeException('El stock debe estar entre 0 y 99.999.999.');
    if($extraPrice!==''&&(!preg_match('/^\d{1,12}$/',$extraPrice)||(int)$extraPrice<=0))throw new RuntimeException('Precio especial de variante no válido.');
    $key=$sizeId.':'.mb_strtolower($color);
    if(isset($used[$key]))throw new RuntimeException('No repitas una combinación de talle y color.');
    $used[$key]=true;
    $validatedVariants[]=['size'=>$sizeId,'color'=>$color,'stock'=>(int)$stockRaw,'price'=>$extraPrice===''?null:(int)$extraPrice,'active'=>!empty($row['active'])?1:0];
   }
   $images=[];
   $uploads=$_FILES['images']??null;
   if(is_array($uploads)&&isset($uploads['name'])&&is_array($uploads['name'])){
    if(count($uploads['name'])>8)throw new RuntimeException('Podés subir hasta 8 fotografías por producto.');
    foreach($uploads['name'] as $ix=>$filename){
     $errorCode=(int)($uploads['error'][$ix]??UPLOAD_ERR_NO_FILE);
     if($errorCode===UPLOAD_ERR_NO_FILE)continue;
     if($errorCode!==UPLOAD_ERR_OK)throw new RuntimeException('No se pudo cargar una fotografía. Revisá que pese menos de 10 MB.');
     $size=(int)($uploads['size'][$ix]??0);$tmp=(string)($uploads['tmp_name'][$ix]??'');
     if($size<1||$size>INYOU_STORAGE_MAX_BYTES||!is_uploaded_file($tmp))throw new RuntimeException('Fotografía inválida o mayor a 10 MB.');
     $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
     $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
     if(!isset($allowed[$mime]))throw new RuntimeException('Solo se permiten fotos JPG, PNG y WEBP.');
     $dims=@getimagesize($tmp);
     if(!$dims||$dims[0]<1||$dims[1]<1||$dims[0]*$dims[1]>25000000)throw new RuntimeException('Una fotografía supera el máximo de 25 megapíxeles.');
     $images[]=['tmp'=>$tmp,'mime'=>$mime,'extension'=>$allowed[$mime]];
    }
   }
   if($requestedStatus==='active'){
    $sellable=false;foreach($validatedVariants as $variant){if($variant['active']&&$variant['stock']>0){$sellable=true;break;}}
    if(!$sellable||!count($images))throw new RuntimeException('Para publicar necesitás al menos una foto y un talle/color activo con stock. Podés guardar como borrador.');
   }
   $baseSlug=trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)?:$name)),'-');
   $baseSlug=substr($baseSlug?:'prenda',0,110);
   $slug=$baseSlug;
   $check=$pdo->prepare('SELECT COUNT(*) FROM products WHERE slug=?');
   for($suffix=2;$suffix<1000;$suffix++){$check->execute([$slug]);if(!(int)$check->fetchColumn())break;$slug=$baseSlug.'-'.$suffix;}
   $uploadedPaths=[];
   try{
    $pdo->beginTransaction();
    $insert=$pdo->prepare("INSERT INTO products (sku,slug,name,description,base_price,currency,status,featured,product_type,audience,fit) VALUES (NULL,?,?,?,?,'PYG','draft',0,?,?,?)");
    $insert->execute([$slug,$name,$description!==''?$description:null,(int)$priceRaw,$type,$audience,$fit]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_collections (product_id,collection_id,sort_order) VALUES (?,?,0)')->execute([$id,$collectionId]);
    $addVariant=$pdo->prepare("INSERT INTO product_variants (product_id,size_id,sku,color_name,price_override,stock_qty,availability_mode,lead_time,is_active,sort_order) VALUES (?,?,?,?,?,?,'stock','',?,?)");
    foreach($validatedVariants as $index=>$v){
     $sku='INY-'.$id.'-'.strtoupper(bin2hex(random_bytes(5)));
     $addVariant->execute([$id,$v['size'],$sku,$v['color'],$v['price'],$v['stock'],$v['active'],$index]);
    }
    $addPhoto=$pdo->prepare('INSERT INTO product_images (product_id,file_path,alt_text,is_primary,sort_order) VALUES (?,?,?,?,?)');
    foreach($images as $index=>$photo){
     $path='supabase/products/p'.$id.'-'.bin2hex(random_bytes(16)).'.'.$photo['extension'];
     $bytes=file_get_contents($photo['tmp']);if($bytes===false)throw new RuntimeException('No se pudo leer una fotografía.');
     inyouStorageUpload($path,$bytes,$photo['mime']);
     $uploadedPaths[]=$path;
     $addPhoto->execute([$id,$path,$name,$index===0?1:0,$index]);
    }
    if($requestedStatus==='active')$pdo->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$id]);
    $pdo->commit();auditLog($pdo,'product.create','product',$id);
   }catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($uploadedPaths as $path){try{inyouStorageDelete($path);}catch(Throwable $ignore){}}
    throw $e;
   }
   header('Location: /admin/product-workspace.php?product='.$id.'&created=1',true,303);exit;
  }
  if(!$id)throw new RuntimeException('Primero guardá los datos básicos de la prenda.');
  if($action==='details'){
   $name=trim((string)($_POST['name']??''));$description=trim((string)($_POST['description']??''));
   $priceRaw=trim((string)($_POST['base_price']??''));$collectionId=filter_var($_POST['collection_id']??null,FILTER_VALIDATE_INT);
   $type=(string)($_POST['product_type']??'remera');$audience=(string)($_POST['audience']??'Unisex');$fit=(string)($_POST['fit']??'');
   if(!in_array($type,['remera','kepi'],true)||!in_array($audience,['Mujer','Hombre','Unisex'],true)||!in_array($fit,['','Regular','Oversize','Cropped','Único'],true))throw new RuntimeException('Revisá tipo, público y corte de la prenda.');
   if($name===''||mb_strlen($name)>200)throw new RuntimeException('Ingresá un nombre de hasta 200 caracteres.');
   if(!preg_match('/^[0-9]{1,12}$/',$priceRaw)||(int)$priceRaw<1)throw new RuntimeException('Ingresá el precio en guaraníes, sin puntos ni comas.');
   if(!$collectionId)throw new RuntimeException('Seleccioná una colección.');
   $valid=$pdo->prepare('SELECT COUNT(*) FROM collections WHERE id=? AND is_active=1');$valid->execute([$collectionId]);
   if(!(int)$valid->fetchColumn())throw new RuntimeException('La colección seleccionada no está disponible.');
   $pdo->beginTransaction();
   $pdo->prepare('UPDATE products SET name=?,description=?,base_price=?,product_type=?,audience=?,fit=? WHERE id=?')->execute([$name,$description,(int)$priceRaw,$type,$audience,$fit,$id]);
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
  }elseif($action==='add_variant'){
   $sizeId=filter_var($_POST['size_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $color=trim((string)($_POST['color_name']??''));
   $stockRaw=trim((string)($_POST['stock_qty']??'0'));
   $priceRaw=trim((string)($_POST['price_override']??''));
   if(!$sizeId)throw new RuntimeException('Elegí un talle.');
   $sizeCheck=$pdo->prepare('SELECT COUNT(*) FROM sizes WHERE id=? AND is_active=1');$sizeCheck->execute([$sizeId]);
   if(!(int)$sizeCheck->fetchColumn())throw new RuntimeException('El talle seleccionado no está disponible.');
   if($color===''||mb_strlen($color)>45)throw new RuntimeException('Ingresá un color de hasta 45 caracteres.');
   if(!preg_match('/^\\d{1,8}$/',$stockRaw))throw new RuntimeException('Ingresá un stock válido de 0 a 99.999.999.');
   if($priceRaw!==''&&(!preg_match('/^\\d{1,12}$/',$priceRaw)||(int)$priceRaw<=0))throw new RuntimeException('Precio especial inválido.');
   $exists=$pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id=? AND size_id=? AND LOWER(color_name)=LOWER(?)');
   $exists->execute([$id,$sizeId,$color]);if((int)$exists->fetchColumn()>0)throw new RuntimeException('Ya existe ese talle y color. Editá su stock en la lista.');
   $sku='INY-'.$id.'-'.strtoupper(bin2hex(random_bytes(5)));
   $pdo->prepare("INSERT INTO product_variants (product_id,size_id,sku,color_name,price_override,stock_qty,availability_mode,lead_time,is_active,sort_order) VALUES (?,?,?,?,?,?,'stock','',1,0)")
    ->execute([$id,$sizeId,$sku,$color,$priceRaw===''?null:(int)$priceRaw,(int)$stockRaw]);
   auditLog($pdo,'variant.create','product_variant',(int)$pdo->lastInsertId());
  }elseif($action==='publish'){
   $newStatus=(string)($_POST['status']??'');
   if(!in_array($newStatus,['draft','active','archived'],true))throw new RuntimeException('Estado de publicación no válido.');
   if($newStatus==='active'){
    $ready=$pdo->prepare("SELECT p.base_price,
      (SELECT COUNT(*) FROM product_collections pc JOIN collections c ON c.id=pc.collection_id WHERE pc.product_id=p.id AND c.is_active=1) AS collections_ok,
      (SELECT COUNT(*) FROM product_variants v WHERE v.product_id=p.id AND v.is_active=1 AND (v.availability_mode='bajo_pedido' OR v.stock_qty>0)) AS variants_ok,
      (SELECT COUNT(*) FROM product_images i WHERE i.product_id=p.id AND i.file_path LIKE 'supabase/products/%') AS images_ok
      FROM products p WHERE p.id=?");
    $ready->execute([$id]);$checks=$ready->fetch();
    if(!$checks||(int)$checks['base_price']<=0)throw new RuntimeException('Antes de publicar, cargá un precio válido.');
    if(!(int)$checks['collections_ok'])throw new RuntimeException('Antes de publicar, seleccioná una colección activa.');
    if(!(int)$checks['variants_ok'])throw new RuntimeException('Antes de publicar, activá al menos un talle/color con stock o bajo pedido.');
    if(!(int)$checks['images_ok'])throw new RuntimeException('Antes de publicar, subí una fotografía.');
   }
   if($newStatus==='archived'&&trim((string)($_POST['confirm_archive']??''))!=='1')throw new RuntimeException('Confirmá que querés retirar y archivar el producto.');
   $pdo->prepare('UPDATE products SET status=? WHERE id=?')->execute([$newStatus,$id]);
   auditLog($pdo,'product.status.'.$newStatus,'product',$id);
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
 $sizes=$pdo->query('SELECT id,label FROM sizes WHERE is_active=1 ORDER BY sort_order,id')->fetchAll();
 $q=$pdo->prepare("SELECT pv.id,pv.sku,pv.color_name,pv.stock_qty,pv.price_override,pv.is_active,pv.availability_mode,COALESCE(s.label,'Único') size_label FROM product_variants pv LEFT JOIN sizes s ON s.id=pv.size_id WHERE pv.product_id=? ORDER BY pv.color_name,s.sort_order,pv.sort_order,pv.id");
 $q->execute([$id]);$variants=$q->fetchAll();
 $q=$pdo->prepare('SELECT id,file_path,alt_text,is_primary FROM product_images WHERE product_id=? ORDER BY is_primary DESC,sort_order,id');$q->execute([$id]);$photos=$q->fetchAll();
 if(isset($_GET['saved']))$notice='Cambios guardados.';
 if(isset($_GET['created']))$notice='Prenda creada como borrador. Ahora podés cargar fotos, talles y stock en esta misma pantalla.';
}catch(Throwable $e){
 if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
 error_log('InYou workspace: '.$e->getMessage());
 $error=$e instanceof PDOException?'No se pudo guardar el cambio en MySQL.':$e->getMessage();
}
if(!$product)$product=['name'=>'Nueva prenda','status'=>'draft','base_price'=>0,'description'=>'','collection_id'=>0,'product_type'=>'remera','audience'=>'Unisex','fit'=>''];
$hasPhoto=false;foreach($photos as $p){if(inyouStorageIsPath((string)$p['file_path'])){$hasPhoto=true;break;}}
$available=0;foreach($variants as $v){if($v['is_active']&&($v['availability_mode']==='bajo_pedido'||(int)$v['stock_qty']>0))$available++;}
$hasCollection=false;foreach($collections as $c){if((int)$c['id']===(int)($product['collection_id']??0)){$hasCollection=true;break;}}
$readyToPublish=$hasPhoto&&$available>0&&$hasCollection&&(int)($product['base_price']??0)>0;

?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editar <?=h($product['name']??'producto')?> · InYou</title><link rel="stylesheet" href="/admin/style.css"><link rel="stylesheet" href="/admin/fashion-admin.css?v=5">
<style>
.workspace{max-width:1150px;margin:auto}.workspace-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin:12px 0 24px}.workspace h1{margin:5px 0;font-size:clamp(30px,3.5vw,44px)}.workspace .sub{font-size:13px;color:#767675;margin:5px 0}.workspace .quick-nav{display:flex;gap:9px;overflow-x:auto;padding:3px 0 14px;margin-bottom:5px}.workspace .quick-nav a{display:inline-block;white-space:nowrap;border-radius:30px;border:1px solid #ddd7d0;padding:10px 16px;background:#fff;color:#282525;text-decoration:none;font-size:13px}.workspace section.block{background:#fff;border:1px solid #e8e2dc;border-radius:18px;padding:25px;margin:16px 0;scroll-margin-top:16px;box-shadow:0 6px 22px rgba(49,30,25,.035)}.workspace .section-head{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:16px}.workspace h2{font-size:20px;letter-spacing:-.025em;margin:0}.workspace .hint{font-size:13px;color:#767675;margin:4px 0 0}.workspace label.caption{font-size:12px;font-weight:700;color:#555;display:block;margin-bottom:7px}.workspace input[type=text],.workspace input[type=number],.workspace textarea,.workspace select,.workspace input[type=file]{width:100%;padding:12px;border:1px solid #ddd9d4;border-radius:10px;background:#fff;font:inherit;font-size:14px;min-width:0}.workspace input:focus,.workspace textarea:focus,.workspace select:focus{outline:2px solid #c3dacc;outline-offset:1px}.workspace .fields{display:grid;grid-template-columns:1fr 1fr;gap:15px}.workspace .field-full{grid-column:1/-1}.workspace textarea{min-height:90px}.workspace .btn{display:inline-flex;align-items:center;justify-content:center;gap:5px;background:#212624;color:white;padding:12px 17px;border:0;border-radius:10px;font-size:13px;text-decoration:none;cursor:pointer;font-weight:600}.workspace .btn.light{background:#f5f3ef;color:#30302d;border:1px solid #e5dfd8}.workspace .btn.small{padding:8px 11px;font-size:12px}.workspace .savebar{display:flex;align-items:center;gap:12px;margin-top:15px;flex-wrap:wrap}.workspace .variant-table{width:100%;border-collapse:collapse}.workspace .variant-table th{text-align:left;font-size:11px;text-transform:uppercase;color:#888;letter-spacing:.07em}.workspace .variant-table td,.workspace .variant-table th{padding:11px 8px;border-bottom:1px solid #efebe7}.workspace .variant-table input[type=number]{width:110px;padding:9px}.workspace .variant-table input[type=checkbox]{width:19px;height:19px;accent-color:#70081e}.workspace .variant-table .v-name{font-weight:700;font-size:13px}.workspace .variant-table .v-sku{display:block;font-size:10px;color:#999;margin-top:4px}.workspace .photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(135px,1fr));gap:12px}.workspace .photo{padding:8px;background:#faf8f5;border-radius:12px}.workspace .photo img,.workspace .photo .missing{width:100%;height:145px;object-fit:cover;border-radius:8px;background:#f2e8e6}.workspace .photo .missing{display:grid;place-items:center;text-align:center;font-size:12px;padding:12px;color:#8e5757}.workspace .photo .photo-actions{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px;align-items:center;font-size:12px}.workspace .upload-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}.workspace .flag{font-size:12px;border-radius:20px;padding:7px 10px;background:#f2f0eb;display:inline-block;margin:3px 4px 3px 0}.workspace .flag.good{background:#e3f2e9;color:#245e43}.workspace .notice{padding:12px 16px;border-radius:10px;background:#e4f3e9;color:#235a39;margin:12px 0}.workspace .err{padding:12px 16px;border-radius:10px;background:#fcecea;color:#842c2c;margin:12px 0}.workspace .separator{border-top:1px solid #ece8e3;margin:20px 0}.workspace .advanced{font-size:13px;color:#777}.workspace .advanced a{color:#70081e}
@media(max-width:760px){.workspace .fields,.workspace .upload-row{grid-template-columns:1fr}.workspace section.block{padding:17px;border-radius:14px}.workspace .variant-table th:nth-child(3),.workspace .variant-table td:nth-child(3){display:none}.workspace .variant-table input[type=number]{width:76px}.workspace-top{display:block}.workspace .section-head{display:block}}
.status-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:16px 18px;background:#fff;border:1px solid #e6e0d8;border-radius:15px;margin:16px 0 8px}.status-overview{display:flex;gap:11px;align-items:center}.status-overview strong{display:block;font-size:15px}.status-overview small{display:block;margin-top:3px;font-size:12px;color:#777}.status-dot{display:block;width:11px;height:11px;flex:none;border-radius:50%;background:#c7b4a8}.status-dot.on{background:#21895e}.status-actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.status-actions form{margin:0}.status-actions button{min-height:42px}.status-actions button:disabled{opacity:.45;cursor:not-allowed}.workspace .status-actions .status-publish{background:#70081e}.workspace .status-actions .status-delete{background:#fff;color:#a13e3e;border:1px solid #ead3d0}.state-quiet{display:inline-flex;padding:10px 12px;font-size:12px;color:#65635d}.status-requirement{margin:0 0 14px;color:#8e493b;font-size:12px;line-height:1.5}.status-requirement a{color:#70081e;font-weight:700}@media(max-width:760px){.status-toolbar{padding:13px}.status-actions{width:100%}.status-actions form{flex:1}.status-actions button{width:100%}}
.variant-quick-create{border:1px solid #ece3dd;border-radius:13px;padding:13px 16px;margin:10px 0 20px;background:#fdfbf9}.variant-quick-create summary{cursor:pointer;font-size:13px;font-weight:700;color:#70081e;list-style:none}.variant-quick-create summary::-webkit-details-marker{display:none}.variant-add-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end;margin-top:16px}.variant-add-form label{display:grid;gap:6px;font-size:12px;font-weight:700;color:#5c5551;min-width:0}.variant-add-form button{grid-column:1/-1;justify-self:start}@media(max-width:850px){.variant-add-form{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.variant-add-form{grid-template-columns:1fr}.variant-add-form button{width:100%}}
</style></head><body class="dashboard">
<?php adminSidebar('products');?>
<main class="content"><div class="workspace"><header><a href="/admin/products.php" style="text-decoration:none;color:#6a6862">← Volver a productos</a><span style="font-size:12px;color:#777">InYou Studio · Editor de prendas</span></header>
<?php if($isNew):?>
<div class="studio-product-new">
  <div class="fashion-kicker">CATÁLOGO / NUEVA PRENDA</div>
  <h1>Agregar prenda</h1>
  <p class="sub">Completá los datos básicos y continuá acá mismo con fotografías, talles, colores y publicación.</p>
  <?php if($error):?><div class="err" role="alert"><?=h($error)?></div><?php endif;?>
  <div class="studio-new-editor">
    <div class="studio-new-main">
      <section class="block"><h2>Información de la prenda</h2>
      <form method="post" id="studio-create-form">
      <input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="create">
      <label class="caption" for="new-name">Nombre de la prenda *</label><input id="new-name" type="text" name="name" maxlength="200" placeholder="Ej.: Remera oversize rosa" required>
      <label class="caption" for="new-desc">Descripción</label><textarea id="new-desc" name="description" placeholder="Materiales, estilo, cuidados y detalles"></textarea>
      <div class="fields">
      <div><label class="caption" for="new-price">Precio en G. *</label><input id="new-price" type="number" min="1" name="base_price" placeholder="Ej.: 85000" required></div>
      <div><label class="caption" for="new-coll">Colección *</label><select id="new-coll" name="collection_id" required><option value="">Elegir colección</option><?php foreach($collections as $c):?><option value="<?=(int)$c['id']?>"><?=h($c['name'])?></option><?php endforeach;?></select></div>
      <div><label class="caption" for="new-type">Tipo</label><select id="new-type" name="product_type"><option value="remera">Remera</option><option value="kepi">Kepi</option></select></div>
      <div><label class="caption" for="new-aud">Para quién</label><select id="new-aud" name="audience"><option>Mujer</option><option>Hombre</option><option selected>Unisex</option></select></div>
      <div><label class="caption" for="new-fit">Corte</label><select id="new-fit" name="fit"><option value="">Sin definir</option><option>Regular</option><option>Oversize</option><option>Cropped</option><option>Único</option></select></div>
      </div><div class="savebar"><button class="btn" type="submit">Guardar borrador y continuar →</button></div></form>
      </section>
    </div>
    <aside class="studio-new-side"><section class="block"><h2>Publicación</h2><span class="flag">Borrador</span><p class="hint">La prenda no aparece en la tienda hasta que agregues fotografías y talles disponibles y la publiques.</p><h3>Todo en una página</h3><p class="hint">Después de crear el borrador, vas a completar las fotos, variantes y stock aquí, sin volver al catálogo.</p></section></aside>
  </div>
</div>
<?php else:?>
<div class="workspace-top"><div><div class="eyebrow">EDITAR PRODUCTO</div><h1><?=h($product['name']??'Producto')?></h1><p class="sub">Nombre, imágenes, talles, colores, stock y publicación. Todo en este editor.</p><span class="flag"><?=h($product['status']==='active'?'Publicado':'Borrador / no publicado')?></span><span class="flag <?=$hasPhoto?'good':''?>"><?=$hasPhoto?'Foto cargada':'Falta foto disponible'?></span><span class="flag <?=$available?'good':''?>"><?=$available?'Variantes disponibles: '.$available:'Sin variantes disponibles'?></span></div><a class="btn light" href="/" target="_blank" rel="noopener">Ver tienda ↗</a></div>
<?php if($error):?><div class="err"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="notice"><?=h($notice)?></div><?php endif;?>
<div class="studio-editor-layout"><div class="studio-editor-primary">
<nav class="quick-nav" aria-label="Navegar dentro del editor"><a href="#datos">Información</a><a href="#fotos">Fotografías</a><a href="#talles">Talles y stock</a><a href="#publicar">Publicación</a></nav>
<section class="block" id="datos"><div class="section-head"><div><h2>Información del producto</h2><p class="hint">Nombre, precio y descripción. Nada más para empezar.</p></div></div>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="details"><div class="fields"><div class="field-full"><label class="caption" for="pname">Nombre</label><input id="pname" type="text" name="name" maxlength="200" required value="<?=h($product['name'])?>" <?=$canEdit?'':'disabled'?>></div><div><label class="caption" for="pprice">Precio base en G.</label><input id="pprice" type="number" name="base_price" min="1" required value="<?=(int)$product['base_price']?>" <?=$canEdit?'':'disabled'?>></div><div><label class="caption" for="pcoll">Colección</label><select id="pcoll" name="collection_id" required <?=$canEdit?'':'disabled'?>><?php foreach($collections as $c):?><option value="<?=(int)$c['id']?>" <?=((int)$c['id']===(int)$product['collection_id'])?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></div>
<div><label class="caption" for="ptype">Tipo de prenda</label><select id="ptype" name="product_type" <?=$canEdit?'':'disabled'?>><?php foreach(['remera'=>'Remera','kepi'=>'Kepi'] as $key=>$value):?><option value="<?=h($key)?>" <?=($product['product_type']??'remera')===$key?'selected':''?>><?=h($value)?></option><?php endforeach;?></select></div>
<div><label class="caption" for="paudience">Para quién</label><select id="paudience" name="audience" <?=$canEdit?'':'disabled'?>><?php foreach(['Mujer','Hombre','Unisex'] as $value):?><option value="<?=h($value)?>" <?=($product['audience']??'Unisex')===$value?'selected':''?>><?=h($value)?></option><?php endforeach;?></select></div>
<div><label class="caption" for="pfit">Corte / fit</label><select id="pfit" name="fit" <?=$canEdit?'':'disabled'?>><?php foreach([''=>'Sin definir','Regular'=>'Regular','Oversize'=>'Oversize','Cropped'=>'Cropped','Único'=>'Único'] as $key=>$value):?><option value="<?=h($key)?>" <?=($product['fit']??'')===$key?'selected':''?>><?=h($value)?></option><?php endforeach;?></select></div>
<div class="field-full"><label class="caption" for="pdesc">Descripción</label><textarea id="pdesc" name="description" <?=$canEdit?'':'disabled'?>><?=h($product['description']??'')?></textarea></div></div><?php if($canEdit):?><div class="savebar"><button class="btn">Guardar datos</button></div><?php endif;?></form></section>
<section class="block" id="fotos"><div class="section-head"><div><h2>Fotos y multimedia</h2><p class="hint">Subí varias fotos. La primera se marca como principal automáticamente.</p></div><span class="flag"><?=count($photos)?> registrada(s)</span></div>
<?php if($photos):?><div class="photo-grid"><?php foreach($photos as $photo):?><div class="photo"><?php $path=(string)$photo['file_path'];$src=inyouStorageIsPath($path)?inyouStoragePublicUrl($path):'';?><?php if($src):?><img src="<?=h($src)?>" loading="lazy" alt="<?=h($photo['alt_text'])?>"><?php else:?><div class="missing">Imagen antigua no disponible</div><?php endif;?><div class="photo-actions"><?php if($photo['is_primary']):?><strong>Principal</strong><?php elseif($canEdit):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="primary"><input type="hidden" name="image_id" value="<?=(int)$photo['id']?>"><button class="btn light small" type="submit">Hacer principal</button></form><?php endif;?></div></div><?php endforeach;?></div><?php endif;?>
<?php if($canEdit):?><div class="separator"></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="photo"><div class="upload-row"><div><label class="caption" for="newphoto">Agregar fotografía (JPG, PNG o WEBP · máx. 10 MB)</label><input id="newphoto" type="file" name="image" accept="image/jpeg,image/png,image/webp" required></div><button class="btn">+ Subir fotografía</button></div><label style="display:inline-block;margin-top:12px;font-size:13px"><input type="checkbox" name="primary" value="1"> Usar como foto principal</label></form><?php endif;?><p class="advanced">¿Querés reordenar o eliminar fotos? <a href="/admin/images.php?product=<?=$id?>">Abrir opciones avanzadas de fotos</a>.</p></section>
<section class="block" id="talles"><div class="section-head"><div><h2>Variantes · Talles, colores y stock</h2><p class="hint">Las combinaciones ya están creadas. Activá las que quieras vender y completá su stock.</p></div><span class="flag"><?=count($variants)?> variantes</span></div>
<?php if($canEdit):?><details class="variant-quick-create" <?=$variants?'':'open'?>><summary>＋ Agregar talle y color</summary><form method="post" class="variant-add-form">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="add_variant">
<label><span>Talle</span><select name="size_id" required><option value="">Elegir talle</option><?php foreach($sizes as $size):?><option value="<?=(int)$size['id']?>"><?=h($size['label'])?></option><?php endforeach;?></select></label>
<label><span>Color</span><input type="text" name="color_name" maxlength="45" required placeholder="Ej.: Rosa"></label>
<label><span>Stock</span><input type="number" name="stock_qty" min="0" max="99999999" value="0" required></label>
<label><span>Precio especial (opcional)</span><input type="number" name="price_override" min="1" placeholder="Usar precio base"></label>
<button type="submit" class="btn">Agregar combinación</button></form></details><?php endif;?>
<?php if($variants):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="variants"><div style="overflow-x:auto"><table class="variant-table"><thead><tr><th>Prenda</th><th>Stock</th><th>Precio especial G.</th><th>Disponible</th></tr></thead><tbody><?php foreach($variants as $v):?><tr><td><span class="v-name"><?=h($v['color_name'])?> · <?=h($v['size_label'])?></span><span class="v-sku"><?=h($v['sku'])?></span></td><td data-label="Stock"><input type="number" min="0" max="99999999" name="variants[<?=(int)$v['id']?>][stock]" value="<?=(int)$v['stock_qty']?>" aria-label="Stock <?=h($v['color_name'].' '.$v['size_label'])?>" <?=$canEdit?'':'disabled'?>></td><td data-label="Precio especial"><input type="number" min="1" name="variants[<?=(int)$v['id']?>][price]" value="<?=h((string)($v['price_override']??''))?>" placeholder="Base" aria-label="Precio especial <?=h($v['color_name'].' '.$v['size_label'])?>" <?=$canEdit?'':'disabled'?>></td><td data-label="Disponible"><input type="checkbox" name="variants[<?=(int)$v['id']?>][active]" value="1" <?=!empty($v['is_active'])?'checked':''?> aria-label="Activar <?=h($v['color_name'].' '.$v['size_label'])?>" <?=$canEdit?'':'disabled'?>></td></tr><?php endforeach;?></tbody></table></div><?php if($canEdit):?><div class="savebar"><button class="btn">Guardar talles y stock</button><span class="hint">No publica el producto automáticamente.</span></div><?php endif;?></form><?php else:?><p>Todavía no hay talles. <a href="/admin/variants.php?product=<?=$id?>">Crear primera variante</a>.</p><?php endif;?><p class="advanced" style="margin-top:18px">¿Necesitás agregar otra talla, color o SKU? <a href="/admin/variants.php?product=<?=$id?>">Configuración avanzada de variantes</a>.</p></section>

</div><aside class="studio-editor-aside" aria-label="Opciones y publicación del producto">
<div class="status-toolbar" aria-label="Estado y publicación del producto">
 <div class="status-overview"><span class="status-dot <?=($product['status']==='active')?'on':''?>"></span><div><strong><?=($product['status']==='active')?'Publicado':(($product['status']==='archived')?'Archivado':'Borrador')?></strong><small><?=($product['status']==='active')?'Visible para clientes':(($product['status']==='archived')?'Retirado del catálogo':'Solo visible en el administrador')?></small></div></div>
 <?php if($canEdit):?><div class="status-actions">
 <?php if($product['status']!=='active'):?>
 <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="status" value="active"><button class="btn status-publish" type="submit" <?=$readyToPublish?'':'disabled'?> title="<?=$readyToPublish?'Publicar ahora':'Primero completá foto, precio, colección y una variante con stock'?>">✓ Publicar</button></form>
 <?php else:?>
 <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="status" value="draft"><button class="btn light" type="submit">Pasar a borrador</button></form>
 <?php endif;?>
 <?php if($product['status']==='archived'):?>
 <form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="status" value="draft"><button class="btn light" type="submit">Restaurar borrador</button></form>
 <?php elseif($product['status']==='draft'):?>
 <span class="state-quiet" aria-current="true">Borrador</span>
 <?php endif;?>
 <?php if($product['status']!=='archived'):?>
 <form method="post" onsubmit="return confirm('¿Archivar este producto? Dejará de aparecer en la tienda y podrás recuperarlo después. No se borrarán sus fotos ni variantes.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="status" value="archived"><input type="hidden" name="confirm_archive" value="1"><button class="btn status-delete" type="submit" aria-label="Eliminar de la tienda y archivar el producto">Eliminar</button></form>
 <?php endif;?>
 </div><?php endif;?>
 </div>
 <?php if($product['status']!=='active'&&!$readyToPublish):?><p class="status-requirement">Para publicar: <?php $missing=[];if(!$hasPhoto)$missing[]='agregar fotografía';if(!$hasCollection)$missing[]='elegir colección';if((int)$product['base_price']<=0)$missing[]='definir precio';if(!$available)$missing[]='activar un talle/color con stock';echo h(implode(' · ',$missing));?>. <a href="#talles">Revisar datos</a></p><?php endif;?>
<section class="block" id="publicar" style="background:#f9f7f4"><div class="section-head"><div><h2>Publicación</h2><p class="hint">Publicá la prenda desde aquí, sin volver al listado.</p></div><span class="flag <?=($product['status']==='active')?'good':''?>"><?=($product['status']==='active')?'Visible en la tienda':'No visible en la tienda'?></span></div>
<?php if($product['status']!=='active'&&!$readyToPublish):?><p class="hint">Para publicar falta completar:</p><div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
<?php if((int)$product['base_price']<=0):?><a class="btn light small" href="#datos">Agregar precio</a><?php endif;?>
<?php if(!$hasCollection):?><a class="btn light small" href="#datos">Elegir colección</a><?php endif;?>
<?php if(!$hasPhoto):?><a class="btn light small" href="#fotos">Subir fotografía</a><?php endif;?>
<?php if(!$available):?><a class="btn light small" href="#talles">Activar talle con stock</a><?php endif;?>
</div><?php endif;?>
<?php if($canEdit):?><form method="post" style="margin-top:16px"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="product_id" value="<?=$id?>"><input type="hidden" name="action" value="publish">
<?php if($product['status']==='active'):?><input type="hidden" name="status" value="draft"><button class="btn light" type="submit">Retirar de la tienda</button>
<?php else:?><input type="hidden" name="status" value="active"><button class="btn" type="submit" <?=$readyToPublish?'':'disabled'?> style="<?=$readyToPublish?'':'opacity:.45;cursor:not-allowed'?>">Publicar producto en la tienda</button>
<?php endif;?></form><?php endif;?>
</section>
</aside></div>
<?php endif;?></div></main></body></html>
