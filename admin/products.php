<?php
require __DIR__.'/bootstrap.php';
require_once dirname(__DIR__).'/lib/inyou-storage.php';
requireAuth();

$canEdit=canManageCatalog();$error='';$notice='';$items=[];$product=null;$collections=[];$pdo=null;
function productSlug(string $name):string{$s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name);if($s===false)$s=$name;$s=strtolower($s);$s=preg_replace('/[^a-z0-9]+/','-',$s);$s=trim((string)$s,'-');return substr($s!==''?$s:'producto',0,130);}
function positiveInt($v,int $max=999999999999):int{$s=trim((string)$v);if(!preg_match('/^\d+$/',$s))throw new RuntimeException('Ingresá un número válido.');$n=(int)$s;if($n<0||$n>$max)throw new RuntimeException('Valor fuera de rango.');return $n;}
function assertProductReady(PDO $pdo,int $id):void{
 $q=$pdo->prepare("SELECT p.base_price,
 (SELECT COUNT(*) FROM product_collections pc JOIN collections c ON c.id=pc.collection_id WHERE pc.product_id=p.id AND c.is_active=1) collections_ok,
 (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id=p.id AND pv.is_active=1 AND (pv.availability_mode='bajo_pedido' OR pv.stock_qty>0)) buyable_variants,
 (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id=p.id) images_ok
 FROM products p WHERE p.id=?");$q->execute([$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Producto no encontrado.');
 if((int)$r['base_price']<=0)throw new RuntimeException('No podés publicar un producto sin precio válido.');
 if(!(int)$r['collections_ok'])throw new RuntimeException('No podés publicar un producto sin una colección activa.');
 if(!(int)$r['buyable_variants'])throw new RuntimeException('No podés publicar un producto sin al menos una variante disponible.');
 if(!(int)$r['images_ok'])throw new RuntimeException('No podés publicar un producto sin al menos una imagen.');
}

try{
 $pdo=db();$collections=$pdo->query("SELECT id,name FROM collections WHERE is_active=1 ORDER BY sort_order,id")->fetchAll();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!$canEdit){http_response_code(403);throw new RuntimeException('Tu usuario no tiene permiso para modificar productos.');}
  if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Recargá la página.');}
  $action=(string)($_POST['action']??'');$id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if($action==='save'){
   $name=trim((string)($_POST['name']??''));$sku=trim((string)($_POST['sku']??''));$description=trim((string)($_POST['description']??''));$price=positiveInt($_POST['base_price']??0);$featured=isset($_POST['featured'])?1:0;$type=(string)($_POST['product_type']??'remera');$audience=(string)($_POST['audience']??'Unisex');$fit=(string)($_POST['fit']??'');$collectionId=filter_var($_POST['collection_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   if($name===''||mb_strlen($name)>200)throw new RuntimeException('El nombre es obligatorio (máximo 200 caracteres).');if(mb_strlen($sku)>80)throw new RuntimeException('El SKU supera 80 caracteres.');if(!in_array($type,['remera','kepi'],true))throw new RuntimeException('Tipo no válido.');if(!in_array($audience,['Mujer','Hombre','Unisex'],true))throw new RuntimeException('Público no válido.');if(!in_array($fit,['','Regular','Oversize','Cropped','Único'],true))throw new RuntimeException('Fit no válido.');if(!$collectionId)throw new RuntimeException('Seleccioná una colección.');
   $q=$pdo->prepare('SELECT COUNT(*) FROM collections WHERE id=? AND is_active=1');$q->execute([$collectionId]);if(!(int)$q->fetchColumn())throw new RuntimeException('La colección seleccionada no está disponible.');
   $pdo->beginTransaction();
   if($id){$q=$pdo->prepare('UPDATE products SET name=?,sku=?,description=?,base_price=?,featured=?,product_type=?,audience=?,fit=? WHERE id=?');$q->execute([$name,$sku!==''?$sku:null,$description!==''?$description:null,$price,$featured,$type,$audience,$fit,$id]);$actionName='product.update';}
   else{$slugBase=productSlug($name);$slug=$slugBase;$n=2;$check=$pdo->prepare('SELECT COUNT(*) FROM products WHERE slug=?');do{$check->execute([$slug]);if(!(int)$check->fetchColumn())break;$suffix='-'.$n++;$slug=substr($slugBase,0,160-strlen($suffix)).$suffix;}while($n<1000);$q=$pdo->prepare("INSERT INTO products (sku,slug,name,description,base_price,currency,status,featured,product_type,audience,fit) VALUES (?,?,?,?,?,'PYG','draft',?,?,?,?)");$q->execute([$sku!==''?$sku:null,$slug,$name,$description!==''?$description:null,$price,$featured,$type,$audience,$fit]);$id=(int)$pdo->lastInsertId();$actionName='product.create';}
   $pdo->prepare('DELETE FROM product_collections WHERE product_id=?')->execute([$id]);$pdo->prepare('INSERT INTO product_collections (product_id,collection_id,sort_order) VALUES (?,?,0)')->execute([$id,$collectionId]);$pdo->commit();auditLog($pdo,$actionName,'product',(int)$id);header('Location: /admin/product-workspace.php?product='.(int)$id.'&saved=1',true,303);exit;
  }
  if($action==='status'){
   if(!$id)throw new RuntimeException('Producto no válido.');$status=(string)($_POST['status']??'');if(!in_array($status,['draft','active','archived'],true))throw new RuntimeException('Estado no válido.');if($status==='active')assertProductReady($pdo,(int)$id);$pdo->prepare('UPDATE products SET status=? WHERE id=?')->execute([$status,$id]);auditLog($pdo,'product.status.'.$status,'product',(int)$id);header('Location: /admin/products.php'.($status==='archived'?'?estado=archived&saved=1':'?saved=1'),true,303);exit;
  }
  throw new RuntimeException('Acción no válida.');
 }
 $editId=filter_var($_GET['edit']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($editId){$q=$pdo->prepare("SELECT p.*, (SELECT pc.collection_id FROM product_collections pc WHERE pc.product_id=p.id ORDER BY pc.sort_order,pc.collection_id LIMIT 1) collection_id FROM products p WHERE p.id=?");$q->execute([$editId]);$product=$q->fetch();if(!$product)throw new RuntimeException('No se encontró el producto.');}
 $search=trim((string)($_GET['q']??''));if(mb_strlen($search)>100)$search=mb_substr($search,0,100);
 $statusFilter=(string)($_GET['estado']??'todos');if(!in_array($statusFilter,['todos','active','draft','archived'],true))$statusFilter='todos';
 $page=max(1,min(100000,(int)($_GET['pagina']??1)));$perPage=24;
 $where=[];$params=[];
 if($search!==''){$where[]='(p.name LIKE ? OR p.sku LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
 if($statusFilter==='todos'){$where[]="p.status IN ('active','draft')";}else{$where[]='p.status=?';$params[]=$statusFilter;}
 $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
 $countStmt=$pdo->prepare('SELECT COUNT(*) FROM products p'.$whereSql);$countStmt->execute($params);$totalProducts=(int)$countStmt->fetchColumn();
 $totalPages=max(1,(int)ceil($totalProducts/$perPage));$page=min($page,$totalPages);$offset=($page-1)*$perPage;
 $sql="SELECT p.id,p.sku,p.name,p.base_price,p.status,p.featured,p.product_type,p.audience,p.updated_at,
 (SELECT c.name FROM product_collections pc JOIN collections c ON c.id=pc.collection_id WHERE pc.product_id=p.id ORDER BY pc.sort_order,c.sort_order LIMIT 1) collection_name,
 (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id=p.id) variant_count,
 (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id=p.id AND pv.is_active=1 AND (pv.availability_mode='bajo_pedido' OR pv.stock_qty>0)) buyable_count,
 (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id=p.id) image_count,
 (SELECT pi.file_path FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.is_primary DESC,pi.sort_order,pi.id LIMIT 1) cover_path
 FROM products p".$whereSql." ORDER BY p.updated_at DESC,p.id DESC LIMIT ".$perPage." OFFSET ".$offset;
 $q=$pdo->prepare($sql);$q->execute($params);$items=$q->fetchAll();
 $totals=$pdo->query("SELECT COUNT(*) total,SUM(status='active') active_count,SUM(status='draft') draft_count,SUM(status='archived') archived_count FROM products")->fetch();
 if(isset($_GET['saved']))$notice='Cambios guardados correctamente.';
 if(isset($_GET['imported']))$notice='Prendas incorporadas: '.(int)$_GET['imported'].'. Ya estaban registradas: '.(int)($_GET['existing']??0).'. Se guardaron como borradores, sin stock activo ni imágenes.';
}catch(Throwable $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();error_log('InYou productos: '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo completar la operación en MySQL.':$e->getMessage();}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Productos · InYou</title><link rel="stylesheet" href="/admin/style.css"><link rel="stylesheet" href="/admin/fashion-admin.css?v=3"></head>
<body class="dashboard fashion-admin">
<?php adminSidebar('products');?>
<main class="content"><div class="catalog-manager">
<header class="admin-topbar"><div><span class="admin-topbar-kicker">CATÁLOGO / PRODUCTOS</span><strong>Gestioná tus prendas</strong></div><div class="admin-top-actions"><a href="/" target="_blank" rel="noopener" class="admin-ghost-action">Ver tienda ↗</a><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="signout">Salir</button></form></div></header>
<div class="catalog-intro"><div><span class="eyebrow"><?=$statusFilter==='archived'?'PRENDAS RETIRADAS':'TU CATÁLOGO DE MODA'?></span><h1><?=$statusFilter==='archived'?'Archivados':'Mis prendas'?> <span class="catalog-number"><?=(int)($statusFilter==='archived'?($totals['archived_count']??0):(($totals['active_count']??0)+($totals['draft_count']??0)))?></span></h1><p><?=$statusFilter==='archived'?'Prendas retiradas de la tienda. Podés restaurarlas cuando quieras.':'Gestioná prendas publicadas y borradores, sin mezclar las archivadas.'?></p></div>
<?php if($statusFilter==='archived'):?><a href="/admin/products.php" class="catalog-new-btn">← Volver a Mis prendas</a><?php elseif($canEdit):?><a href="/admin/products.php?new=1" class="catalog-new-btn">＋ Agregar producto</a><?php endif;?></div>
<?php if($error):?><div class="alert error" role="alert"><?=h($error)?></div><?php endif;?>
<?php if($notice):?><div class="alert" role="status"><?=h($notice)?></div><?php endif;?>
<?php if($canEdit&&(isset($_GET['new'])||$product)):?>
<section class="fashion-create-panel" id="nuevo-producto"><div class="create-panel-head"><div><span class="eyebrow">PASO 1 DE 3</span><h2><?=$product?'Editar información de la prenda':'Creá una nueva prenda'?></h2><p>Guardá los datos básicos. Después podrás agregar fotos, talles, colores y publicar.</p></div><a class="admin-close-link" href="/admin/products.php" aria-label="Cerrar formulario">✕</a></div>
<div class="create-steps"><span class="current">1. Información</span><span>2. Fotos y variantes</span><span>3. Publicación</span></div>
<form method="post" class="fashion-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h((string)($product['id']??''))?>">
<div class="fashion-form-grid"><label class="fashion-field"><span>Nombre de la prenda *</span><input name="name" maxlength="200" placeholder="Ej.: Remera Rosa" required value="<?=h((string)($product['name']??''))?>"></label>
<label class="fashion-field"><span>Precio en guaraníes *</span><input name="base_price" type="number" min="1" inputmode="numeric" placeholder="Ej.: 139000" required value="<?=h((string)($product['base_price']??''))?>"></label>
<label class="fashion-field"><span>Colección *</span><select name="collection_id" required><option value="">Elegir colección</option><?php foreach($collections as $c):?><option value="<?=(int)$c['id']?>" <?=((int)($product['collection_id']??0)===(int)$c['id'])?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></label>
<label class="fashion-field"><span>Tipo de prenda</span><select name="product_type"><option value="remera" <?=($product['product_type']??'remera')==='remera'?'selected':''?>>Remera</option><option value="kepi" <?=($product['product_type']??'')==='kepi'?'selected':''?>>Kepi</option></select></label>
<label class="fashion-field"><span>Para quién</span><select name="audience"><?php foreach(['Mujer','Hombre','Unisex'] as $v):?><option <?=$v===($product['audience']??'Unisex')?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label>
<label class="fashion-field"><span>Corte / fit</span><select name="fit"><?php foreach(['','Regular','Oversize','Cropped','Único'] as $v):?><option value="<?=h($v)?>" <?=$v===($product['fit']??'')?'selected':''?>><?=h($v===''?'Sin definir':$v)?></option><?php endforeach;?></select></label>
<label class="fashion-field full"><span>Código interno (SKU, opcional)</span><input name="sku" maxlength="80" placeholder="Código de la prenda" value="<?=h((string)($product['sku']??''))?>"></label>
<label class="fashion-field full"><span>Descripción</span><textarea name="description" rows="3" placeholder="Material, estilo, detalles y recomendaciones…"><?=h((string)($product['description']??''))?></textarea></label></div>
<label class="fashion-featured"><input type="checkbox" name="featured" value="1" <?=!empty($product['featured'])?'checked':''?>><span><strong>Destacar en la tienda</strong><small>Mostrará esta prenda entre las primeras opciones del catálogo.</small></span></label>
<div class="fashion-form-footer"><a href="/admin/products.php" class="admin-ghost-action">Cancelar</a><button type="submit" class="catalog-new-btn">Guardar y continuar →</button></div></form></section>
<?php endif;?>
<?php if($statusFilter!=='archived'):?><div class="catalog-stat-strip"><div><span>Publicados</span><strong><?=h((string)($totals['active_count']??0))?></strong></div><div><span>Borradores</span><strong><?=h((string)($totals['draft_count']??0))?></strong></div></div><?php endif;?>
<div class="catalog-browser"><div class="catalog-browser-head"><div><h2><?=$statusFilter==='archived'?'Prendas archivadas':'Prendas en gestión'?></h2><p><?=$statusFilter==='archived'?'No aparecen en tu catálogo activo. Restauralas para volver a gestionarlas.':'Seleccioná una prenda para editarla o publicarla.'?></p></div></div>
<form class="catalog-searchbar" method="get" action="/admin/products.php"><label class="catalog-search-box"><span aria-hidden="true">⌕</span><input type="search" name="q" value="<?=h($search??'')?>" placeholder="Buscar por nombre o código…" aria-label="Buscar productos"></label><select name="estado" aria-label="Filtrar por estado"><?php if($statusFilter==='archived'):?><option value="archived" selected>Archivados</option><?php else:?><option value="todos" <?=$statusFilter==='todos'?'selected':''?>>Todas mis prendas</option><option value="active" <?=$statusFilter==='active'?'selected':''?>>Publicados</option><option value="draft" <?=$statusFilter==='draft'?'selected':''?>>Borradores</option><?php endif;?></select><button type="submit" class="catalog-search-btn">Buscar</button></form>
<?php if($statusFilter!=='archived'):?><div class="catalog-filter-tabs"><a href="/admin/products.php" class="<?=$statusFilter==='todos'?'active':''?>">Todos <span><?=(int)($totals['active_count']??0)+(int)($totals['draft_count']??0)?></span></a><a href="/admin/products.php?estado=active" class="<?=$statusFilter==='active'?'active':''?>">Publicados <span><?=h((string)($totals['active_count']??0))?></span></a><a href="/admin/products.php?estado=draft" class="<?=$statusFilter==='draft'?'active':''?>">Borradores <span><?=h((string)($totals['draft_count']??0))?></span></a></div><?php endif;?>
<?php if($items):?><div class="fashion-product-grid"><?php foreach($items as $i):?>
<article class="fashion-product-card"><a class="fashion-card-photo" href="/admin/product-workspace.php?product=<?=(int)$i['id']?>" aria-label="Editar <?=h($i['name'])?>"><?php $cover=(string)($i['cover_path']??'');$coverUrl=inyouStorageIsPath($cover)?inyouStoragePublicUrl($cover):'';if($coverUrl):?><img src="<?=h($coverUrl)?>" alt="<?=h($i['name'])?>" loading="lazy"><?php else:?><span class="fashion-no-photo"><span aria-hidden="true">◇</span> Sin fotografía</span><?php endif;?></a><span class="fashion-card-status <?=h((string)$i['status'])?>"><?=h($i['status']==='active'?'Publicado':($i['status']==='archived'?'Archivado':'Borrador'))?></span>
<div class="fashion-card-content"><div class="fashion-card-collection"><?=h((string)($i['collection_name']??'Sin colección'))?> <?=!empty($i['featured'])?'· ★ Destacado':''?></div><h3><a href="/admin/product-workspace.php?product=<?=(int)$i['id']?>"><?=h($i['name'])?></a></h3><div class="fashion-card-price">G. <?=h(number_format((float)$i['base_price'],0,',','.'))?></div><div class="fashion-card-facts"><span><?=h((string)$i['audience'])?></span><span><?=(int)$i['variant_count']?> variantes</span><span><?=(int)$i['image_count']?> fotos</span></div>
<?php if((int)$i['buyable_count']===0):?><p class="fashion-card-warning">Falta stock disponible para publicar</p><?php else:?><p class="fashion-card-good"><?=(int)$i['buyable_count']?> variantes disponibles</p><?php endif;?>
<div class="fashion-card-actions"><a class="fashion-edit-action" href="/admin/product-workspace.php?product=<?=(int)$i['id']?>">Editar prenda →</a><?php if($canEdit):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=(int)$i['id']?>"><select name="status" aria-label="Estado de <?=h($i['name'])?>"><option value="draft" <?=$i['status']==='draft'?'selected':''?>>Borrador</option><option value="active" <?=$i['status']==='active'?'selected':''?>>Publicado</option><option value="archived" <?=$i['status']==='archived'?'selected':''?>>Archivado</option></select><button type="submit">Aplicar</button></form>
<?php if($i['status']!=='archived'):?>
<form method="post" class="fashion-delete-form" onsubmit="return confirm('¿Eliminar esta prenda del catálogo? Dejará de verse en la tienda y podrás recuperarla desde Archivados. Se conservarán las fotos y el stock.')">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=(int)$i['id']?>"><input type="hidden" name="status" value="archived">
<button type="submit" class="fashion-delete-btn" aria-label="Eliminar <?=h($i['name'])?> del catálogo">Eliminar prenda</button></form>
<?php else:?>
<form method="post" class="fashion-restore-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=(int)$i['id']?>"><input type="hidden" name="status" value="draft"><button type="submit" class="fashion-restore-btn">Restaurar borrador</button></form>
<?php endif;?>
<?php endif;?></div></div></article>
<?php endforeach;?></div><?php else:?><div class="fashion-empty"><strong>No encontramos prendas</strong><p><?=$statusFilter==='archived'?'No hay prendas archivadas.':'Cambiá los filtros o agregá tu primer producto.'?></p><?php if($canEdit&&$statusFilter!=='archived'):?><a class="catalog-new-btn" href="/admin/products.php?new=1">＋ Agregar producto</a><?php endif;?></div><?php endif;?>
<?php if($totalPages>1):?><nav class="catalog-pagination" aria-label="Páginas de productos"><span>Mostrando <?=count($items)?> de <?=$totalProducts?> productos</span><div><?php $baseQuery=['q'=>$search,'estado'=>$statusFilter];if($page>1):?><a href="/admin/products.php?<?=h(http_build_query($baseQuery+['pagina'=>$page-1]))?>">← Anterior</a><?php endif;?><strong><?=$page?> / <?=$totalPages?></strong><?php if($page<$totalPages):?><a href="/admin/products.php?<?=h(http_build_query($baseQuery+['pagina'=>$page+1]))?>">Siguiente →</a><?php endif;?></div></nav><?php endif;?>
</div></div></main></body></html>