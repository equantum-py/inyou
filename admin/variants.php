<?php
require __DIR__.'/bootstrap.php';
requireAuth();

$canEdit=canManageCatalog();
$error='';$notice='';$pdo=null;$product=null;$sizes=[];$variants=[];$editing=null;
$productId=filter_var($_GET['product']??$_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$productId){http_response_code(400);exit('Producto no válido.');}

try{
    $pdo=db();
    $q=$pdo->prepare('SELECT id,name,status FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch();if(!$product)throw new RuntimeException('Producto no encontrado.');
    $sizes=$pdo->query("SELECT id,label FROM sizes WHERE is_active=1 ORDER BY sort_order,id")->fetchAll();

    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!$canEdit){http_response_code(403);throw new RuntimeException('No tenés permiso para modificar variantes.');}
        if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Recargá la página.');}
        $action=(string)($_POST['action']??'');
        $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);

        if($action==='save'){
            $sku=strtoupper(trim((string)($_POST['sku']??'')));
            $sizeId=filter_var($_POST['size_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $color=trim((string)($_POST['color_name']??''));
            $stockRaw=trim((string)($_POST['stock_qty']??'0'));
            $mode=(string)($_POST['availability_mode']??'stock');
            $lead=trim((string)($_POST['lead_time']??''));
            $priceRaw=trim((string)($_POST['price_override']??''));
            $sortRaw=trim((string)($_POST['sort_order']??'0'));
            $active=isset($_POST['is_active'])?1:0;
            if(!preg_match('/^[A-Z0-9-]{4,55}$/',$sku))throw new RuntimeException('SKU de variante inválido.');
            if(!$sizeId)throw new RuntimeException('Seleccioná un talle.');
            if($color===''||mb_strlen($color)>100)throw new RuntimeException('Color obligatorio.');
            if(!preg_match('/^\d+$/',$stockRaw))throw new RuntimeException('Stock inválido.');
            $stock=(int)$stockRaw;
            if(!in_array($mode,['stock','bajo_pedido'],true))throw new RuntimeException('Disponibilidad inválida.');
            if(mb_strlen($lead)>120)throw new RuntimeException('El plazo supera 120 caracteres.');
            if($mode==='bajo_pedido'&&$lead==='')throw new RuntimeException('Indicá el plazo para una variante bajo pedido.');
            if($priceRaw!==''&&!preg_match('/^\d{1,12}$/',$priceRaw))throw new RuntimeException('Precio específico inválido.');
            $price=$priceRaw===''?null:(int)$priceRaw;
            if($price!==null&&$price<=0)throw new RuntimeException('El precio específico debe ser mayor a cero.');
            if(!preg_match('/^-?\d+$/',$sortRaw))throw new RuntimeException('Orden inválido.');
            $sort=(int)$sortRaw;

            if($id){
                $q=$pdo->prepare('UPDATE product_variants SET size_id=?,sku=?,color_name=?,price_override=?,stock_qty=?,availability_mode=?,lead_time=?,is_active=?,sort_order=? WHERE id=? AND product_id=?');
                $q->execute([$sizeId,$sku,$color,$price,$stock,$mode,$lead,$active,$sort,$id,$productId]);
                if(!$q->rowCount()){$check=$pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE id=? AND product_id=?');$check->execute([$id,$productId]);if(!(int)$check->fetchColumn())throw new RuntimeException('Variante no encontrada.');}
                auditLog($pdo,'variant.update','product_variant',(int)$id);
            }else{
                $q=$pdo->prepare('INSERT INTO product_variants (product_id,size_id,sku,color_name,price_override,stock_qty,availability_mode,lead_time,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$productId,$sizeId,$sku,$color,$price,$stock,$mode,$lead,$active,$sort]);
                $id=(int)$pdo->lastInsertId();
                auditLog($pdo,'variant.create','product_variant',$id);
            }
            header('Location: /admin/variants.php?product='.$productId.'&saved=1',true,303);exit;
        }

        if($action==='delete'){
            if(!$id)throw new RuntimeException('Variante no válida.');
            if(($product['status']??'')==='active'){
                $q=$pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id=? AND is_active=1 AND id<>?");$q->execute([$productId,$id]);
                if(!(int)$q->fetchColumn())throw new RuntimeException('No podés eliminar la última variante activa de un producto publicado.');
            }
            $q=$pdo->prepare('DELETE FROM product_variants WHERE id=? AND product_id=?');$q->execute([$id,$productId]);
            if(!$q->rowCount())throw new RuntimeException('Variante no encontrada.');
            auditLog($pdo,'variant.delete','product_variant',(int)$id);
            header('Location: /admin/variants.php?product='.$productId.'&saved=1',true,303);exit;
        }
        throw new RuntimeException('Acción no válida.');
    }

    $editId=filter_var($_GET['edit']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if($editId){$q=$pdo->prepare('SELECT * FROM product_variants WHERE id=? AND product_id=?');$q->execute([$editId,$productId]);$editing=$q->fetch();if(!$editing)throw new RuntimeException('Variante no encontrada.');}
    $q=$pdo->prepare("SELECT pv.*,COALESCE(s.label,'Único') size_label FROM product_variants pv LEFT JOIN sizes s ON s.id=pv.size_id WHERE pv.product_id=? ORDER BY pv.sort_order,pv.id");$q->execute([$productId]);$variants=$q->fetchAll();
    if(isset($_GET['saved']))$notice='Variantes actualizadas.';
}catch(Throwable $e){error_log('InYou variantes: '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo completar la operación en MySQL.':$e->getMessage();}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Variantes · InYou</title><link rel="stylesheet" href="/admin/style.css"><style>.wrap{max-width:1200px;margin:auto}.panel{background:#fff;border:1px solid #e7e5df;border-radius:12px;padding:22px;margin:20px 0}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}.field{display:block;margin-bottom:12px}.field span{display:block;margin-bottom:6px;font-weight:600}.input,.select{width:100%;padding:11px;border:1px solid #d9d8d3;border-radius:7px}.btn{background:#202321;color:#fff;border:0;border-radius:7px;padding:11px 16px;text-decoration:none}.btn.secondary{background:#fff;color:#222;border:1px solid #d9d8d3}.table{width:100%;border-collapse:collapse}.table td,.table th{padding:11px;border-bottom:1px solid #eee;text-align:left}.actions{display:flex;gap:8px;flex-wrap:wrap}.alert{padding:12px;border-radius:8px;background:#e4f2e9}.error{background:#f9e6e4}.muted{color:#777}@media(max-width:750px){.grid{grid-template-columns:1fr}}</style></head><body class="dashboard"><aside class="sidebar"><div class="brand">in<span>You</span><i>.</i></div><div class="eyebrow">PANEL ADMINISTRATIVO</div><nav><a href="/admin/">Resumen</a><a class="selected" href="/admin/products.php">Productos</a><a href="/admin/collections.php">Colecciones</a></nav></aside><main class="content"><div class="wrap"><header><div>Hola, <?=h($_SESSION['admin_name']??'Administrador')?></div></header><div class="eyebrow">VARIANTES E INVENTARIO</div><h1><?=h($product['name']??'Producto')?></h1><p class="muted">Gestioná talle, color, SKU, stock, modalidad y precio específico.</p><?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="alert"><?=h($notice)?></div><?php endif;?>
<?php if($canEdit):?><section class="panel"><h2><?=$editing?'Editar variante':'Nueva variante'?></h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="id" value="<?=h((string)($editing['id']??''))?>"><div class="grid"><label class="field"><span>SKU *</span><input class="input" name="sku" required maxlength="55" value="<?=h((string)($editing['sku']??''))?>"></label><label class="field"><span>Talle *</span><select class="select" name="size_id" required><option value="">Seleccionar</option><?php foreach($sizes as $s):?><option value="<?=(int)$s['id']?>" <?=((int)($editing['size_id']??0)===(int)$s['id'])?'selected':''?>><?=h($s['label'])?></option><?php endforeach;?></select></label><label class="field"><span>Color *</span><input class="input" name="color_name" required maxlength="100" value="<?=h((string)($editing['color_name']??''))?>"></label><label class="field"><span>Stock</span><input class="input" name="stock_qty" type="number" min="0" value="<?=h((string)($editing['stock_qty']??0))?>"></label><label class="field"><span>Disponibilidad</span><select class="select" name="availability_mode"><option value="stock" <?=($editing['availability_mode']??'stock')==='stock'?'selected':''?>>Stock</option><option value="bajo_pedido" <?=($editing['availability_mode']??'')==='bajo_pedido'?'selected':''?>>Bajo pedido</option></select></label><label class="field"><span>Plazo</span><input class="input" name="lead_time" maxlength="120" value="<?=h((string)($editing['lead_time']??''))?>" placeholder="Ej. 5 a 7 días"></label><label class="field"><span>Precio específico</span><input class="input" name="price_override" type="number" min="1" value="<?=h((string)($editing['price_override']??''))?>" placeholder="Opcional"></label><label class="field"><span>Orden</span><input class="input" name="sort_order" type="number" value="<?=h((string)($editing['sort_order']??0))?>"></label></div><label><input type="checkbox" name="is_active" value="1" <?=!isset($editing)||!empty($editing['is_active'])?'checked':''?>> Variante activa</label><div class="actions" style="margin-top:14px"><button class="btn"><?=$editing?'Guardar cambios':'Agregar variante'?></button><?php if($editing):?><a class="btn secondary" href="/admin/variants.php?product=<?=$productId?>">Cancelar edición</a><?php endif;?><a class="btn secondary" href="/admin/products.php?edit=<?=$productId?>">Volver al producto</a></div></form></section><?php endif;?>
<section class="panel"><h2>Variantes</h2><div style="overflow:auto"><table class="table"><thead><tr><th>SKU</th><th>Talle</th><th>Color</th><th>Stock</th><th>Modo</th><th>Precio</th><th>Estado</th><th>Acciones</th></tr></thead><tbody><?php foreach($variants as $v):?><tr><td><?=h($v['sku'])?></td><td><?=h($v['size_label'])?></td><td><?=h($v['color_name'])?></td><td><?=(int)$v['stock_qty']?></td><td><?=h($v['availability_mode'])?></td><td><?=$v['price_override']!==null?'Gs. '.h(number_format((float)$v['price_override'],0,',','.')):'Precio base'?></td><td><?=$v['is_active']?'Activa':'Inactiva'?></td><td><?php if($canEdit):?><div class="actions"><a class="btn secondary" href="/admin/variants.php?product=<?=$productId?>&edit=<?=(int)$v['id']?>">Editar</a><form method="post" onsubmit="return confirm('¿Eliminar variante?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="id" value="<?=(int)$v['id']?>"><button class="btn secondary">Eliminar</button></form></div><?php endif;?></td></tr><?php endforeach;?><?php if(!$variants):?><tr><td colspan="8">Todavía no hay variantes.</td></tr><?php endif;?></tbody></table></div></section></div></main></body></html>