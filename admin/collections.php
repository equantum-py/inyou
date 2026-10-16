<?php
require __DIR__ . '/bootstrap.php';
requireAuth();

$role=(string)($_SESSION['admin_role']??'');
$canEdit=in_array($role,['owner','admin','catalog'],true);
$error='';$notice='';$pdo=null;$items=[];
function collectionSlug(string $name):string{$s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name);if($s===false)$s=$name;$s=strtolower($s);$s=preg_replace('/[^a-z0-9]+/','-',$s);return substr(trim((string)$s,'-'),0,150);}
try{
    $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!$canEdit){http_response_code(403);throw new RuntimeException('No tenés permiso para modificar colecciones.');}
        if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Recargá la página.');}
        $action=(string)($_POST['action']??'');$id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($action==='save'){
            $name=trim((string)($_POST['name']??''));$desc=trim((string)($_POST['description']??''));$sort=(int)($_POST['sort_order']??0);$active=isset($_POST['is_active'])?1:0;
            if($name===''||mb_strlen($name)>160)throw new RuntimeException('Nombre de colección inválido.');
            $slug=collectionSlug($name);if($slug==='')throw new RuntimeException('No se pudo generar la URL de la colección.');
            if($id){$q=$pdo->prepare('UPDATE collections SET slug=?,name=?,description=?,sort_order=?,is_active=? WHERE id=?');$q->execute([$slug,$name,$desc!==''?$desc:null,$sort,$active,$id]);}
            else{$q=$pdo->prepare('INSERT INTO collections (slug,name,description,is_active,sort_order) VALUES (?,?,?,?,?)');$q->execute([$slug,$name,$desc!==''?$desc:null,$active,$sort]);}
            header('Location: /admin/collections.php?saved=1',true,303);exit;
        }
        if($action==='delete'){
            if(!$id)throw new RuntimeException('Colección no válida.');
            $q=$pdo->prepare('SELECT COUNT(*) FROM product_collections WHERE collection_id=?');$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('No podés eliminar una colección que tiene productos asignados.');
            $pdo->prepare('DELETE FROM collections WHERE id=?')->execute([$id]);header('Location: /admin/collections.php?saved=1',true,303);exit;
        }
        throw new RuntimeException('Acción no válida.');
    }
    $items=$pdo->query("SELECT c.id,c.slug,c.name,c.description,c.is_active,c.sort_order,(SELECT COUNT(*) FROM product_collections pc WHERE pc.collection_id=c.id) product_count FROM collections c ORDER BY c.sort_order,c.id")->fetchAll();
    if(isset($_GET['saved']))$notice='Colecciones actualizadas.';
}catch(Throwable $e){error_log('InYou colecciones: '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo completar la operación en MySQL.':$e->getMessage();}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Colecciones · InYou</title><link rel="stylesheet" href="/admin/style.css"><style>.wrap{max-width:1100px;margin:auto}.panel{background:#fff;border:1px solid #e7e5df;border-radius:12px;padding:22px;margin:20px 0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:block;margin-bottom:12px}.field span{display:block;margin-bottom:6px;font-weight:600}.input,.textarea{width:100%;padding:11px;border:1px solid #d9d8d3;border-radius:7px}.textarea{min-height:90px}.btn{background:#202321;color:#fff;border:0;border-radius:7px;padding:11px 16px;text-decoration:none}.btn.secondary{background:#fff;color:#222;border:1px solid #d9d8d3}.table{width:100%;border-collapse:collapse}.table td,.table th{padding:11px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}.actions{display:flex;gap:8px;flex-wrap:wrap}.alert{padding:12px;border-radius:8px;background:#e4f2e9}.error{background:#f9e6e4}.muted{color:#777}@media(max-width:700px){.grid{grid-template-columns:1fr}}</style></head><body class="dashboard"><aside class="sidebar"><div class="brand">in<span>You</span><i>.</i></div><div class="eyebrow">PANEL ADMINISTRATIVO</div><nav><a href="/admin/">Resumen</a><a href="/admin/products.php">Productos</a><a class="selected" href="/admin/collections.php">Colecciones</a></nav></aside><main class="content"><div class="wrap"><header><div>Hola, <?=h($_SESSION['admin_name']??'Administrador')?></div></header><div class="eyebrow">CATÁLOGO</div><h1>Colecciones</h1><p class="muted">Organizá las familias de productos que aparecen en la tienda.</p><?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="alert"><?=h($notice)?></div><?php endif;?>
<?php if($canEdit):?><section class="panel"><h2>Nueva colección</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save"><div class="grid"><label class="field"><span>Nombre *</span><input class="input" name="name" required maxlength="160"></label><label class="field"><span>Orden</span><input class="input" name="sort_order" type="number" value="0"></label></div><label class="field"><span>Descripción</span><textarea class="textarea" name="description"></textarea></label><label><input type="checkbox" name="is_active" value="1" checked> Colección activa</label><div class="actions" style="margin-top:14px"><button class="btn">Crear colección</button></div></form></section><?php endif;?>
<section class="panel"><h2>Colecciones existentes</h2><div style="overflow:auto"><table class="table"><thead><tr><th>Nombre</th><th>Slug</th><th>Productos</th><th>Estado</th><th>Orden</th><th>Acciones</th></tr></thead><tbody><?php foreach($items as $c):?><tr><td><strong><?=h($c['name'])?></strong><br><small><?=h((string)($c['description']??''))?></small></td><td><?=h($c['slug'])?></td><td><?=(int)$c['product_count']?></td><td><?=$c['is_active']?'Activa':'Inactiva'?></td><td><?=(int)$c['sort_order']?></td><td><?php if($canEdit):?><form method="post" onsubmit="return confirm('¿Eliminar colección?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$c['id']?>"><button class="btn secondary">Eliminar</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section></div></main></body></html>