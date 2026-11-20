<?php
require __DIR__.'/bootstrap.php';
requireAuth();
$stats=['products'=>0,'active'=>0,'drafts'=>0,'low_stock'=>0,'collections'=>0,'images'=>0,'variants'=>0,'admins'=>0];$recent=[];$drafts=[];$dbError=false;
try{
 $pdo=db();
 $stats['products']=(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
 $stats['active']=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
 $stats['drafts']=(int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='draft'")->fetchColumn();
 $stats['low_stock']=(int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE is_active=1 AND availability_mode='stock' AND stock_qty BETWEEN 0 AND 3")->fetchColumn();
 $stats['collections']=(int)$pdo->query('SELECT COUNT(*) FROM collections WHERE is_active=1')->fetchColumn();
 $stats['images']=(int)$pdo->query('SELECT COUNT(*) FROM product_images')->fetchColumn();
 $stats['variants']=(int)$pdo->query('SELECT COUNT(*) FROM product_variants')->fetchColumn();
 $drafts=$pdo->query("SELECT id,name,base_price FROM products WHERE status='draft' ORDER BY updated_at DESC,id DESC LIMIT 5")->fetchAll();
 if(canManageUsers())$stats['admins']=(int)$pdo->query('SELECT COUNT(*) FROM admin_users WHERE is_active=1')->fetchColumn();
 if(canViewAudit())$recent=$pdo->query('SELECT action,entity_type,created_at FROM admin_audit_log ORDER BY created_at DESC LIMIT 10')->fetchAll();
}catch(Throwable $e){error_log('InYou admin dashboard: '.$e->getMessage());$dbError=true;}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inicio · InYou Studio</title><link rel="stylesheet" href="/admin/style.css"><link rel="stylesheet" href="/admin/fashion-admin.css?v=2"></head>
<body class="dashboard"><?php adminSidebar('home');?>
<main class="content"><div class="fashion-shell">
<header><div>InYou Studio <small style="color:#8a807b">· Panel de tu tienda</small></div><form method="post" action="/admin/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="signout">Cerrar sesión</button></form></header>
<div class="fashion-page-top"><div><span class="fashion-kicker">TU TIENDA · RESUMEN</span><h1>Tu tienda, de un vistazo.</h1><p>Todo lo importante para administrar tus prendas, sin complicaciones.</p></div>
<?php if(canManageCatalog()):?><a class="fashion-btn" href="/admin/product-workspace.php?new=1">＋ Cargar nueva prenda</a><?php endif;?></div>
<?php if($dbError):?><div class="fashion-alert" role="alert">No pudimos cargar todos los datos de tu tienda. Intentá actualizar la página.</div><?php endif;?>
<div class="fashion-summary">
<a class="fashion-metric" href="/admin/products.php?estado=active" style="text-decoration:none;color:inherit"><span>Prendas publicadas</span><strong><?=(int)$stats['active']?></strong><small>Visibles en la tienda</small></a>
<a class="fashion-metric" href="/admin/products.php?estado=draft" style="text-decoration:none;color:inherit"><span>Por terminar</span><strong><?=(int)$stats['drafts']?></strong><small>Borradores guardados</small></a>
<a class="fashion-metric" href="/admin/inventory.php" style="text-decoration:none;color:inherit"><span>Stock bajo</span><strong><?=(int)$stats['low_stock']?></strong><small>Variantes con 0 a 3 unidades</small></a>
<a class="fashion-metric" href="/admin/collections.php" style="text-decoration:none;color:inherit"><span>Colecciones</span><strong><?=(int)$stats['collections']?></strong><small>Familias de prendas activas</small></a>
</div>
<section class="studio-section"><div class="studio-section-head"><h2>¿Qué querés hacer hoy?</h2><p>Accesos rápidos para gestionar tu tienda.</p></div><div class="studio-actions">
<a href="/admin/product-workspace.php?new=1" class="studio-action"><span class="studio-action-icon">＋</span><strong>Cargar prenda</strong><small>Nombre, precio, fotos y talles</small><span class="studio-action-arrow">→</span></a>
<a href="/admin/products.php" class="studio-action"><span class="studio-action-icon">▣</span><strong>Mis prendas</strong><small>Editar o publicar productos</small><span class="studio-action-arrow">→</span></a>
<a href="/admin/inventory.php" class="studio-action"><span class="studio-action-icon">◈</span><strong>Stock y talles</strong><small>Consultar disponibilidad</small><span class="studio-action-arrow">→</span></a>
<a href="/admin/collections.php" class="studio-action"><span class="studio-action-icon">◎</span><strong>Colecciones</strong><small>Organizar el catálogo</small><span class="studio-action-arrow">→</span></a>
</div></section>
<div class="studio-split"><section class="studio-section"><div class="studio-section-head"><h2>Prendas por terminar</h2><a href="/admin/products.php?estado=draft">Ver borradores →</a></div>
<?php if($drafts):?><div class="studio-drafts"><?php foreach($drafts as $p):?><a class="studio-draft" href="/admin/product-workspace.php?product=<?=(int)$p['id']?>"><span class="studio-draft-icon">♧</span><span><strong><?=h($p['name'])?></strong><small>G. <?=h(number_format((float)$p['base_price'],0,',','.'))?> · Borrador</small></span><b>Continuar →</b></a><?php endforeach;?></div>
<?php else:?><p class="studio-muted">¡Todo al día! No hay prendas pendientes de terminar.</p><?php endif;?></section>
<section class="studio-section"><div class="studio-section-head"><h2>Tu escaparate</h2></div><div class="studio-store"><span class="studio-store-art">inYou.</span><p>Así ven tus productos los clientes. Revisá fotos, precios y talles.</p><a href="/" target="_blank" rel="noopener" class="fashion-btn light">Abrir tienda ↗</a></div></section>
</div>
</div></main></body></html>