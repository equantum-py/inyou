<?php
require __DIR__ . '/bootstrap.php';
requireAuth();

$role = (string)($_SESSION['admin_role'] ?? '');
$canEdit = in_array($role, ['owner', 'admin', 'catalog'], true);
$error = '';
$notice = '';
$items = [];
$product = null;
$pdo = null;

function productSlug(string $name): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if ($s === false) $s = $name;
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    return substr($s !== '' ? $s : 'producto', 0, 130);
}

try {
    $pdo = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canEdit) {
            http_response_code(403);
            throw new RuntimeException('Tu usuario no tiene permiso para modificar productos.');
        }
        if (!validCsrf()) {
            http_response_code(403);
            throw new RuntimeException('La sesión venció. Recargá la página e intentá de nuevo.');
        }
        $action = (string)($_POST['action'] ?? '');
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $sku = trim((string)($_POST['sku'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $priceInput = trim((string)($_POST['base_price'] ?? ''));
            $featured = isset($_POST['featured']) ? 1 : 0;
            if ($name === '' || mb_strlen($name) > 200) throw new RuntimeException('El nombre es obligatorio (máximo 200 caracteres).');
            if (mb_strlen($sku) > 80) throw new RuntimeException('El SKU supera 80 caracteres.');
            if (!preg_match('/^\d{1,12}$/', $priceInput)) throw new RuntimeException('Ingresá un precio válido en guaraníes, sin puntos ni comas.');
            $price = ltrim($priceInput, '0');
            if ($price === '') $price = '0';
            if (strlen($price) > 12) throw new RuntimeException('El precio supera el máximo permitido.');
            if ($id) {
                $q = $pdo->prepare('UPDATE products SET name=?, sku=?, description=?, base_price=?, featured=? WHERE id=?');
                $q->execute([$name, $sku !== '' ? $sku : null, $description !== '' ? $description : null, $price, $featured, $id]);
            } else {
                $slugBase = productSlug($name);
                $slug = $slugBase;
                $n = 2;
                $check = $pdo->prepare('SELECT COUNT(*) FROM products WHERE slug=?');
                do {
                    $check->execute([$slug]);
                    if (!(int)$check->fetchColumn()) break;
                    $suffix = '-' . $n++;
                    $slug = substr($slugBase, 0, 160 - strlen($suffix)) . $suffix;
                    if ($n > 1000) throw new RuntimeException('No se pudo generar una URL única.');
                } while (true);
                $q = $pdo->prepare("INSERT INTO products (sku,slug,name,description,base_price,currency,status,featured) VALUES (?,?,?,?,?,'PYG','draft',?)");
                $q->execute([$sku !== '' ? $sku : null, $slug, $name, $description !== '' ? $description : null, $price, $featured]);
            }
            header('Location: /admin/products.php?saved=1', true, 303);
            exit;
        }
        if ($action === 'status') {
            if (!$id) throw new RuntimeException('Producto no válido.');
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['draft', 'active', 'archived'], true)) throw new RuntimeException('Estado no válido.');
            $q = $pdo->prepare('UPDATE products SET status=? WHERE id=?');
            $q->execute([$status, $id]);
            header('Location: /admin/products.php?saved=1', true, 303);
            exit;
        }
        throw new RuntimeException('Acción no válida.');
    }
    $editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($editId) {
        $q = $pdo->prepare('SELECT id,sku,slug,name,description,base_price,status,featured FROM products WHERE id=?');
        $q->execute([$editId]);
        $product = $q->fetch();
        if (!$product) throw new RuntimeException('No se encontró el producto.');
    }
    $search = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
    if ($search !== '') {
        $q = $pdo->prepare('SELECT id,sku,name,base_price,status,featured,updated_at FROM products WHERE name LIKE ? OR sku LIKE ? ORDER BY id DESC LIMIT 100');
        $q->execute(['%' . $search . '%', '%' . $search . '%']);
        $items = $q->fetchAll();
    } else {
        $items = $pdo->query('SELECT id,sku,name,base_price,status,featured,updated_at FROM products ORDER BY id DESC LIMIT 100')->fetchAll();
    }
    if (isset($_GET['saved'])) $notice = 'Cambios guardados correctamente.';
} catch (Throwable $e) {
    error_log('InYou productos: ' . $e->getMessage());
    $error = $e instanceof PDOException ? 'No se pudo completar la operación en MySQL.' : $e->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Productos · InYou</title>
<link rel="stylesheet" href="/admin/style.css">
<style>
.products-content{max-width:1250px;margin:auto}.products-toolbar{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin:25px 0}.products-btn{background:#202321;color:#fff;border:0;border-radius:7px;padding:12px 18px;text-decoration:none;cursor:pointer;font:inherit}.products-btn.secondary{background:#fff;color:#222;border:1px solid #d9d8d3}.products-input,.products-textarea{width:100%;padding:12px;border:1px solid #d9d8d3;border-radius:7px;background:#fff;font:inherit;box-sizing:border-box}.products-textarea{min-height:110px}.products-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.products-panel{background:#fff;border:1px solid #e7e5df;border-radius:12px;padding:24px;margin:22px 0}.products-table{width:100%;border-collapse:collapse}.products-table th,.products-table td{padding:13px 10px;text-align:left;border-bottom:1px solid #eee}.products-table th{font-size:13px;color:#666}.products-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.products-actions form{display:inline}.products-alert{padding:14px;border-radius:8px;margin:15px 0;background:#e4f2e9}.products-alert.error-alert{background:#f9e6e4}.products-field{display:block;margin:0 0 14px}.products-field span{display:block;margin-bottom:7px;font-weight:600}.products-muted{color:#777}.products-scroll{overflow-x:auto}@media(max-width:750px){.products-grid{grid-template-columns:1fr}.products-panel{padding:15px}}
</style>
</head>
<body class="dashboard">
<aside class="sidebar">
<div class="brand">in<span>You</span><i>.</i></div><div class="eyebrow">PANEL ADMINISTRATIVO</div>
<nav><a href="/admin/">⌂ &nbsp; Resumen</a><a class="selected" href="/admin/products.php">Productos</a><span>Colecciones · próxima entrega</span><span>Inventario · próxima entrega</span><span>Medios · próxima entrega</span><span>Configuración · próxima entrega</span></nav>
<div class="sidebar-bottom">InYou · Administración</div>
</aside>
<main class="content"><div class="products-content">
<header><div>Hola, <?=h($_SESSION['admin_name'] ?? 'Administrador')?></div><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="signout">Cerrar sesión</button></form></header>
<div class="eyebrow">CATÁLOGO</div><h1>Productos</h1><p>Creá, editá y publicá los productos de tu tienda.</p>
<?php if ($error): ?><div class="products-alert error-alert"><?=h($error)?></div><?php endif; ?>
<?php if ($notice): ?><div class="products-alert"><?=h($notice)?></div><?php endif; ?>
<div class="products-toolbar"><form method="get" style="display:flex;gap:8px;flex:1;max-width:500px"><input class="products-input" name="q" placeholder="Buscar por nombre o SKU" value="<?=h((string)($_GET['q'] ?? ''))?>"><button class="products-btn secondary">Buscar</button></form><?php if($canEdit): ?><a class="products-btn" href="/admin/products.php?new=1">+ Nuevo producto</a><?php endif; ?></div>
<?php if($canEdit && (isset($_GET['new']) || $product)): ?>
<section class="products-panel"><h2><?=$product ? 'Editar producto' : 'Nuevo producto'?></h2><p class="products-muted">Los productos nuevos se guardan como borradores.</p>
<form method="post" action="/admin/products.php">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h((string)($product['id'] ?? ''))?>">
<div class="products-grid"><label class="products-field"><span>Nombre *</span><input class="products-input" name="name" maxlength="200" required value="<?=h((string)($product['name'] ?? ''))?>"></label><label class="products-field"><span>SKU</span><input class="products-input" name="sku" maxlength="80" value="<?=h((string)($product['sku'] ?? ''))?>"></label></div>
<label class="products-field"><span>Descripción</span><textarea class="products-textarea" name="description"><?=h((string)($product['description'] ?? ''))?></textarea></label>
<div class="products-grid"><label class="products-field"><span>Precio (Gs.) *</span><input class="products-input" name="base_price" type="number" min="0" max="999999999999" step="1" required value="<?=h((string)($product['base_price'] ?? '0'))?>"></label><label class="products-field"><span>Destacado</span><input type="checkbox" name="featured" value="1" <?=!empty($product['featured'])?'checked':''?>> Mostrar como destacado</label></div>
<div class="products-actions"><button class="products-btn" type="submit">Guardar producto</button><a class="products-btn secondary" href="/admin/products.php">Cancelar</a></div>
</form></section>
<?php endif; ?>
<section class="products-panel"><h2>Listado de productos</h2><p class="products-muted">Se muestran hasta 100 productos por consulta.</p><div class="products-scroll"><table class="products-table"><thead><tr><th>Producto</th><th>SKU</th><th>Precio</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($items as $item): ?><tr><td><?=h($item['name'])?></td><td><?=h((string)($item['sku'] ?? '—'))?></td><td>Gs. <?=h(number_format((float)$item['base_price'],0,',','.'))?></td><td><?=h($item['status'])?></td><td><div class="products-actions">
<?php if($canEdit): ?><a href="/admin/products.php?edit=<?=(int)$item['id']?>">Editar</a><form method="post" action="/admin/products.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><select name="status" aria-label="Estado del producto"><option value="draft" <?=$item['status']==='draft'?'selected':''?>>Borrador</option><option value="active" <?=$item['status']==='active'?'selected':''?>>Publicado</option><option value="archived" <?=$item['status']==='archived'?'selected':''?>>Archivado</option></select><button class="products-btn secondary" type="submit">Aplicar</button></form><?php else: ?>Solo lectura<?php endif; ?>
</div></td></tr><?php endforeach; ?>
<?php if(!$items): ?><tr><td colspan="5">Todavía no hay productos para mostrar.</td></tr><?php endif; ?></tbody></table></div></section>
<p class="notice">Entorno de desarrollo · Ventas y pedidos reales no habilitados.</p>
</div></main></body></html>
