<?php
require __DIR__ . '/bootstrap.php';
requireAuth();

$role=(string)($_SESSION['admin_role']??'');
$canEdit=in_array($role,['owner','admin','catalog'],true);
$error='';$notice='';$pdo=null;$product=null;$images=[];
$productId=filter_var($_GET['product']??$_POST['product_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$productId){http_response_code(400);exit('Producto no válido.');}

try{
    $pdo=db();
    $q=$pdo->prepare('SELECT id,name FROM products WHERE id=?');$q->execute([$productId]);$product=$q->fetch();if(!$product)throw new RuntimeException('Producto no encontrado.');

    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!$canEdit){http_response_code(403);throw new RuntimeException('No tenés permiso para modificar imágenes.');}
        if(!validCsrf()){http_response_code(403);throw new RuntimeException('La sesión venció. Recargá la página.');}
        $action=(string)($_POST['action']??'');
        $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);

        if($action==='upload'){
            if(!isset($_FILES['image'])||!is_array($_FILES['image'])||($_FILES['image']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Seleccioná una imagen válida.');
            $file=$_FILES['image'];
            if((int)$file['size']>5*1024*1024)throw new RuntimeException('La imagen supera 5 MB.');
            $tmp=(string)$file['tmp_name'];
            $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($tmp);
            $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if(!isset($allowed[$mime]))throw new RuntimeException('Formato no permitido. Usá JPG, PNG o WEBP.');
            $ext=$allowed[$mime];
            $name='p'.$productId.'-'.bin2hex(random_bytes(8)).'.'.$ext;
            $dir=dirname(__DIR__).'/assets/products';
            if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('No se pudo preparar la carpeta de imágenes.');
            $dest=$dir.'/'.$name;
            if(!move_uploaded_file($tmp,$dest))throw new RuntimeException('No se pudo guardar la imagen.');
            $alt=trim((string)($_POST['alt_text']??''));if(mb_strlen($alt)>255)$alt=mb_substr($alt,0,255);
            $isPrimary=isset($_POST['is_primary'])?1:0;
            $sort=(int)($_POST['sort_order']??0);
            $pdo->beginTransaction();
            if($isPrimary)$pdo->prepare('UPDATE product_images SET is_primary=0 WHERE product_id=?')->execute([$productId]);
            $pdo->prepare('INSERT INTO product_images (product_id,file_path,alt_text,is_primary,sort_order) VALUES (?,?,?,?,?)')->execute([$productId,'assets/products/'.$name,$alt,$isPrimary,$sort]);
            $pdo->commit();
            header('Location: /admin/images.php?product='.$productId.'&saved=1',true,303);exit;
        }

        if($action==='primary'){
            if(!$id)throw new RuntimeException('Imagen no válida.');
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE product_images SET is_primary=0 WHERE product_id=?')->execute([$productId]);
            $q=$pdo->prepare('UPDATE product_images SET is_primary=1 WHERE id=? AND product_id=?');$q->execute([$id,$productId]);
            $pdo->commit();
            header('Location: /admin/images.php?product='.$productId.'&saved=1',true,303);exit;
        }

        if($action==='delete'){
            if(!$id)throw new RuntimeException('Imagen no válida.');
            $q=$pdo->prepare('SELECT file_path FROM product_images WHERE id=? AND product_id=?');$q->execute([$id,$productId]);$row=$q->fetch();
            if($row){$pdo->prepare('DELETE FROM product_images WHERE id=? AND product_id=?')->execute([$id,$productId]);$path=dirname(__DIR__).'/'.(string)$row['file_path'];$base=realpath(dirname(__DIR__).'/assets/products');$real=realpath($path);if($base&&$real&&str_starts_with($real,$base.DIRECTORY_SEPARATOR)&&is_file($real))@unlink($real);}
            header('Location: /admin/images.php?product='.$productId.'&saved=1',true,303);exit;
        }
        throw new RuntimeException('Acción no válida.');
    }

    $q=$pdo->prepare('SELECT id,file_path,alt_text,is_primary,sort_order,created_at FROM product_images WHERE product_id=? ORDER BY is_primary DESC,sort_order,id');$q->execute([$productId]);$images=$q->fetchAll();
    if(isset($_GET['saved']))$notice='Imágenes actualizadas.';
}catch(Throwable $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();error_log('InYou imágenes: '.$e->getMessage());$error=$e instanceof PDOException?'No se pudo completar la operación en MySQL.':$e->getMessage();}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Imágenes · InYou</title><link rel="stylesheet" href="/admin/style.css"><style>.wrap{max-width:1100px;margin:auto}.panel{background:#fff;border:1px solid #e7e5df;border-radius:12px;padding:22px;margin:20px 0}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.card{border:1px solid #e6e4df;border-radius:10px;overflow:hidden;background:#fff}.card img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}.body{padding:12px}.input{width:100%;padding:11px;border:1px solid #d9d8d3;border-radius:7px}.btn{background:#202321;color:#fff;border:0;border-radius:7px;padding:11px 16px;text-decoration:none}.btn.secondary{background:#fff;color:#222;border:1px solid #d9d8d3}.actions{display:flex;gap:8px;flex-wrap:wrap}.alert{padding:12px;border-radius:8px;background:#e4f2e9}.error{background:#f9e6e4}.muted{color:#777}@media(max-width:800px){.grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.grid{grid-template-columns:1fr}}</style></head><body class="dashboard"><aside class="sidebar"><div class="brand">in<span>You</span><i>.</i></div><div class="eyebrow">PANEL ADMINISTRATIVO</div><nav><a href="/admin/">Resumen</a><a class="selected" href="/admin/products.php">Productos</a><a href="/admin/collections.php">Colecciones</a></nav></aside><main class="content"><div class="wrap"><header><div>Hola, <?=h($_SESSION['admin_name']??'Administrador')?></div></header><div class="eyebrow">MEDIOS</div><h1><?=h($product['name']??'Producto')?></h1><p class="muted">Subí JPG, PNG o WEBP. Máximo 5 MB por archivo.</p><?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?><?php if($notice):?><div class="alert"><?=h($notice)?></div><?php endif;?>
<?php if($canEdit):?><section class="panel"><h2>Subir imagen</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="product_id" value="<?=$productId?>"><p><input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp" required></p><p><input class="input" name="alt_text" maxlength="255" placeholder="Texto alternativo"></p><p><input class="input" type="number" name="sort_order" value="0" placeholder="Orden"></p><p><label><input type="checkbox" name="is_primary" value="1"> Usar como imagen principal</label></p><div class="actions"><button class="btn">Subir imagen</button><a class="btn secondary" href="/admin/products.php?edit=<?=$productId?>">Volver al producto</a></div></form></section><?php endif;?>
<section class="panel"><h2>Galería</h2><div class="grid"><?php foreach($images as $img):?><article class="card"><img src="/<?=h($img['file_path'])?>" alt="<?=h($img['alt_text'])?>"><div class="body"><strong><?=$img['is_primary']?'Principal':'Imagen'?></strong><p class="muted"><?=h($img['alt_text']?:'Sin texto alternativo')?></p><?php if($canEdit):?><div class="actions"><?php if(!$img['is_primary']):?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="primary"><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="id" value="<?=(int)$img['id']?>"><button class="btn secondary">Hacer principal</button></form><?php endif;?><form method="post" onsubmit="return confirm('¿Eliminar imagen?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?=$productId?>"><input type="hidden" name="id" value="<?=(int)$img['id']?>"><button class="btn secondary">Eliminar</button></form></div><?php endif;?></div></article><?php endforeach;?><?php if(!$images):?><p>Todavía no hay imágenes cargadas.</p><?php endif;?></div></section></div></main></body></html>