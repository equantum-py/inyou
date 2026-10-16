<?php
require __DIR__ . '/bootstrap.php';
requireAuth();

$role=(string)($_SESSION['admin_role']??'');
$canEdit=in_array($role,['owner','admin','catalog'],true);
$error='';$notice='';$items=[];$product=null;$collections=[];$pdo=null;

function productSlug(string $name):string{
    $s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name);if($s===false)$s=$name;
    $s=strtolower($s);$s=preg_replace('/[^a-z0-9]+/','-',$s);$s=trim((string)$s,'-');
    return substr($s!==''?$s:'producto',0,130);
}
function positiveInt($v,int $max=999999999999):int{
    $s=trim((string)$v);if(!preg_match('/^\d+$/',$s))throw new RuntimeException('Ingresá un número válido.');
    $n=(int)$s;if($n<0||$n>$max)throw new RuntimeException('Valor fuera de rango.');return $n;
}

try{
    $pdo=db();
    $collections=$pdo->query("SELECT id,name FROM collections WHERE is_active=1 ORDER BY sort_order,id")->fetchAll();

    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!$canEdit){http_response_code(403);throw new RuntimeException('Tu usuario no tiene permiso para modificar productos.');}
        if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Recargá la página.');}
        $action=(string)($_POST['action']??'');
        $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);

        if($action==='save'){
            $name=trim((string)($_POST['name']??''));
            $sku=trim((string)($_POST['sku']??''));
            $description=trim((string)($_POST['description']??''));
            $price=positiveInt($_POST['base_price']??0);
            $featured=isset($_POST['featured'])?1:0;
            $type=(string)($_POST['product_type']??'remera');
            $audience=(string)($_POST['audience']??'Unisex');
            $fit=(string)($_POST['fit']??'');
            $collectionId=filter_var($_POST['collection_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);

            if($name===''||mb_strlen($name)>200)throw new RuntimeException('El nombre es obligatorio (máximo 200 caracteres).');
            if(mb_strlen($sku)>80)throw new RuntimeException('El SKU supera 80 caracteres.');
            if(!in_array($type,['remera','kepi'],true))throw new RuntimeException('Tipo no válido.');
            if(!in_array($audience,['Mujer','Hombre','Unisex'],true))throw new RuntimeException('Público no válido.');
            if(!in_array($fit,['','Regular','Oversize','Cropped','Único'],true))throw new RuntimeException('Fit no válido.');
            if(!$collectionId)throw new RuntimeException('Seleccioná una colección.');

            $pdo->beginTransaction();
            if($id){
                $q=$pdo->prepare('UPDATE products SET name=?,sku=?,description=?,base_price=?,featured=?,product_type=?,audience=?,fit=? WHERE id=?');
                $q->execute([$name,$sku!==''?$sku:null,$description!==''?$description:null,$price,$featured,$type,$audience,$fit,$id]);
            }else{
                $slugBase=productSlug($name);$slug=$slugBase;$n=2;$check=$pdo->prepare('SELECT COUNT(*) FROM products WHERE slug=?');
                do{$check->execute([$slug]);if(!(int)$check->fetchColumn())break;$suffix='-'.$n++;$slug=substr($slugBase,0,160-strlen($suffix)).$suffix;}while($n<1000);
                $q=$pdo->prepare("INSERT INTO products (sku,slug,name,description,base_price,currency,status,featured,product_type,audience,fit) VALUES (?,?,?,?,?,'PYG','draft',?,?,?,?)");
                $q->execute([$sku!==''?$sku:null,$slug,$name,$description!==''?$description:null,$price,$featured,$type,$audience,$fit]);
                $id=(int)$pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM product_collections WHERE product_id=?')->execute([$id]);
            $pdo->prepare('INSERT INTO product_collections (product_id,collection_id,sort_order) VALUES (?,?,0)')->execute([$id,$collectionId]);
            $pdo->commit();
            header('Location: /admin/products.php?edit='.(int)$id.'&saved=1',true,303);exit;
        }

        if($action==='status'){
            if(!$id)throw new RuntimeException('Producto no válido.');
            $status=(string)($_POST['status']??'');
            if(!in_array($status,['draft','active','archived'],true))throw new RuntimeException('Estado no válido.');
            if($status==='active'){
                $q=$pdo->prepare('SELECT COUNT(*) FROM product_collections WHERE product_id=?');$q->execute([$id]);if(!(int)$q->fetchColumn())throw new RuntimeException('No podés publicar un producto sin colección.');
            }
            $pdo->prepare('UPDATE products SET status=? WHERE id=?')->execute([$status,$id]);
            header('Location: /admin/products.php?saved=1',true,303);exit;
        }
        throw new RuntimeException('Acción no válida.');
    }

    $editId=filter_var($_GET['edit']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($editId){
        $q=$pdo->prepare("SELECT p.*, (SELECT pc.collection_id FROM product_collections pc WHERE pc.product_id=p.id ORDER BY pc.sort_order,pc.collection_id LIMIT 1) AS collection_id FROM products p WHERE p.id=?");
        $q->execute([$editId]);$product=$q->fetch();if(!$product)throw new RuntimeException('No se encontró el producto.');
    }

    $search=trim((string)($_GET['q']??''));if(mb_strlen($search)>100)$search=mb_substr($search,0,100);
    $sql="SELECT p.id,p.sku,p.name,p.base_price,p.status,p.featured,p.product_type,p.audience,p.fit,p.updated_at,
        (SELECT c.name FROM product_collections pc JOIN collections c ON c.id=pc.collection_id WHERE pc.product_id=p.id ORDER BY pc.sort_order,c.sort_order LIMIT 1) collection_name,
        (SELECT COUNT(*) FROM product_variants pv WHERE pv.product_id=p.id) variant_count,
        (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id=p.id) image_count
        FROM products p";
    if($search!==''){$q=$pdo->prepare($sql.' WHERE p.name LIKE ? OR p.sku LIKE ? ORDER BY p.id DESC LIMIT 100');$q->execute(['%'.$search.'%','%'.$search.'%']);$items=$q->fetchAll();}
    else{$items=$pdo->query($sql.' ORDER BY p.id DESC LIMIT 100')->fetchAll();}
    if(isset($_GET['saved']))$notice='Cambios guardados correctamente.';
}catch(Throwable $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();error_log('InYou productos: '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo completar la operación en MySQL.':$e->getMessage();}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Productos · InYou</title><link rel="stylesheet" href="/admin/style.css">
<style>.products-content{max-width:1250px;margin:auto}.products-toolbar,.products-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.products-toolbar{justify-content:space-between;margin:24px 0}.products-btn{background:#202321;color:#fff;border:0;border-radius:7px;padding:12px 18px;text-decoration:none;cursor:pointer;font:inherit}.products-btn.secondary{background:#fff;color:#222;border:1px solid #d9d8d3}.products-input,.products-textarea,.products-select{width:100%;padding:12px;border:1px solid #d9d8d3;border-radius:7px;background:#fff;font:inherit}.products-textarea{min-height:110px}.products-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.products-panel{background:#fff;border:1px solid #e7e5df;border-radius:12px;padding:24px;margin:22px 0}.products-table{width:100%;border-collapse:collapse}.products-table th,.products-table td{padding:13px 10px;text-align:left;border-bottom:1px solid #eee;vertical-align:top}.products-field{display:block;margin:0 0 14px}.products-field span{display:block;margin-bottom:7px;font-weight:600}.products-alert{padding:14px;border-radius:8px;margin:15px 0;background:#e4f2e9}.error-alert{background:#f9e6e4}.muted{color:#777}.products-scroll{overflow-x:auto}.module-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#f1f0eb;font-size:12px}@media(max-width:760px){.products-grid{grid-template-columns:1fr}.products-panel{padding:15px}}</style></head>
<body class="dashboard"><aside class="sidebar"><div class="brand">in<span>You</span><i>.</i></div><div class="eyebrow">PANEL ADMINISTRATIVO</div><nav><a href="/admin/">⌂ &nbsp; Resumen</a><a class="selected" href="/admin/products.php">Productos</a><a href="/admin/collections.php">Colecciones</a><span>Inventario · dentro de Productos</span><span>Medios · dentro de Productos</span><span>Configuración · próxima entrega</span></nav><div class="sidebar-bottom">InYou · Administración</div></aside>
<main class="content"><div class="products-content"><header><div>Hola, <?=h($_SESSION['admin_name']??'Administrador')?></div><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="signout">Cerrar sesión</button></form></header>
<div class="eyebrow">CATÁLOGO</div><h1>Productos</h1><p>Gestioná producto, colección, variantes, stock e imágenes desde un mismo flujo.</p>
<?php if($error):?><div class="products-alert error-alert"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="products-alert"><?=h($notice)?></div><?php endif;?>
<div class="products-toolbar"><form method="get" style="display:flex;gap:8px;flex:1;max-width:500px"><input class="products-input" name="q" placeholder="Buscar por nombre o SKU" value="<?=h((string)($_GET['q']??''))?>"><button class="products-btn secondary">Buscar</button></form><?php if($canEdit):?><a class="products-btn" href="/admin/products.php?new=1">+ Nuevo producto</a><?php endif;?></div>
<?php if($canEdit&&(isset($_GET['new'])||$product)):?><section class="products-panel"><h2><?=$product?'Editar producto':'Nuevo producto'?></h2><p class="muted">Los productos nuevos se guardan como borradores.</p><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h((string)($product['id']??''))?>">
<div class="products-grid"><label class="products-field"><span>Nombre *</span><input class="products-input" name="name" maxlength="200" required value="<?=h((string)($product['name']??''))?>"></label><label class="products-field"><span>SKU base</span><input class="products-input" name="sku" maxlength="80" value="<?=h((string)($product['sku']??''))?>"></label></div>
<label class="products-field"><span>Descripción</span><textarea class="products-textarea" name="description"><?=h((string)($product['description']??''))?></textarea></label>
<div class="products-grid"><label class="products-field"><span>Precio base (Gs.) *</span><input class="products-input" name="base_price" type="number" min="0" step="1" required value="<?=h((string)($product['base_price']??'0'))?>"></label><label class="products-field"><span>Colección *</span><select class="products-select" name="collection_id" required><option value="">Seleccionar</option><?php foreach($collections as $c):?><option value="<?=(int)$c['id']?>" <?=((int)($product['collection_id']??0)===(int)$c['id'])?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></label></div>
<div class="products-grid"><label class="products-field"><span>Tipo</span><select class="products-select" name="product_type"><option value="remera" <?=($product['product_type']??'remera')==='remera'?'selected':''?>>Remera</option><option value="kepi" <?=($product['product_type']??'')==='kepi'?'selected':''?>>Kepi</option></select></label><label class="products-field"><span>Público</span><select class="products-select" name="audience"><?php foreach(['Mujer','Hombre','Unisex'] as $v):?><option <?=$v===($product['audience']??'Unisex')?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label></div>
<div class="products-grid"><label class="products-field"><span>Fit</span><select class="products-select" name="fit"><?php foreach(['','Regular','Oversize','Cropped','Único'] as $v):?><option value="<?=h($v)?>" <?=$v===($product['fit']??'')?'selected':''?>><?=h($v===''?'Sin definir':$v)?></option><?php endforeach;?></select></label><label class="products-field"><span>Destacado</span><input type="checkbox" name="featured" value="1" <?=!empty($product['featured'])?'checked':''?>> Mostrar como destacado</label></div>
<div class="products-actions"><button class="products-btn">Guardar producto</button><a class="products-btn secondary" href="/admin/products.php">Cancelar</a></div></form>
<?php if($product):?><div class="module-links"><a class="products-btn secondary" href="/admin/variants.php?product=<?=(int)$product['id']?>">Variantes y stock</a><a class="products-btn secondary" href="/admin/images.php?product=<?=(int)$product['id']?>">Imágenes</a></div><?php endif;?></section><?php endif;?>
<section class="products-panel"><h2>Listado</h2><div class="products-scroll"><table class="products-table"><thead><tr><th>Producto</th><th>Colección</th><th>Precio</th><th>Estado</th><th>Catálogo</th><th>Acciones</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><strong><?=h($item['name'])?></strong><br><small><?=h((string)($item['sku']??'—'))?> · <?=h($item['product_type'])?> · <?=h($item['audience'])?></small></td><td><?=h((string)($item['collection_name']??'Sin colección'))?></td><td>Gs. <?=h(number_format((float)$item['base_price'],0,',','.'))?></td><td><?=h($item['status'])?></td><td><span class="badge"><?=(int)$item['variant_count']?> variantes</span> <span class="badge"><?=(int)$item['image_count']?> imágenes</span></td><td><div class="products-actions"><?php if($canEdit):?><a href="/admin/products.php?edit=<?=(int)$item['id']?>">Editar</a><a href="/admin/variants.php?product=<?=(int)$item['id']?>">Stock</a><a href="/admin/images.php?product=<?=(int)$item['id']?>">Imágenes</a><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><select name="status"><option value="draft" <?=$item['status']==='draft'?'selected':''?>>Borrador</option><option value="active" <?=$item['status']==='active'?'selected':''?>>Publicado</option><option value="archived" <?=$item['status']==='archived'?'selected':''?>>Archivado</option></select><button class="products-btn secondary">Aplicar</button></form><?php else:?>Solo lectura<?php endif;?></div></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="6">Todavía no hay productos.</td></tr><?php endif;?></tbody></table></div></section><p class="notice">Entorno de desarrollo · Ventas reales no habilitadas.</p></div></main></body></html>