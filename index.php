
<?php
require __DIR__ . '/bootstrap.php';
requireAuth();

$stats = [
    'products' => 0,
    'active' => 0,
    'drafts' => 0,
    'low_stock' => 0,
    'orders' => 0
];

$recent = [];
$dbError = false;

try {
    $pdo = db();

    $stats['products'] = (int) $pdo
        ->query('SELECT COUNT(*) FROM products')
        ->fetchColumn();

    $stats['active'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM products WHERE status='active'")
        ->fetchColumn();

    $stats['drafts'] = (int) $pdo
        ->query("SELECT COUNT(*) FROM products WHERE status='draft'")
        ->fetchColumn();

    $stats['low_stock'] = (int) $pdo
        ->query(
            'SELECT COUNT(*) FROM product_variants
             WHERE is_active=1 AND stock_qty BETWEEN 0 AND 3'
        )
        ->fetchColumn();

    $stats['orders'] = (int) $pdo
        ->query('SELECT COUNT(*) FROM orders')
        ->fetchColumn();

    $recent = $pdo
        ->query(
            'SELECT action, entity_type, created_at
             FROM admin_audit_log
             ORDER BY created_at DESC
             LIMIT 6'
        )
        ->fetchAll();

} catch (Throwable $e) {
    error_log('InYou admin dashboard: ' . $e->getMessage());
    $dbError = true;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Panel · InYou</title>
    <link rel="stylesheet" href="/admin/style.css">
</head>

<body class="dashboard">

<aside class="sidebar">

    <div class="brand">
        in<span>You</span><i>.</i>
    </div>

    <div class="eyebrow">
        PANEL ADMINISTRATIVO
    </div>

    <nav>
        <a class="selected" href="/admin/">
            ⌂ &nbsp; Resumen
        </a>

        <a href="/admin/products.php">
            Productos
        </a>

        <span>Colecciones · próxima entrega</span>
        <span>Inventario · próxima entrega</span>
        <span>Medios · próxima entrega</span>
        <span>Configuración · próxima entrega</span>
    </nav>

    <div class="sidebar-bottom">
        InYou · Administración
    </div>

</aside>

<main class="content">

    <header>
        <div>
            Hola, <?= h($_SESSION['admin_name'] ?? 'Administrador') ?>
        </div>

        <form method="post" action="/admin/logout.php">
            <input
                type="hidden"
                name="csrf"
                value="<?= h(csrf()) ?>"
            >

            <button class="signout">
                Cerrar sesión
            </button>
        </form>
    </header>

    <div class="eyebrow">
        RESUMEN GENERAL
    </div>

    <h1>Dashboard</h1>

    <p>Información actual de tu tienda.</p>

    <?php if ($dbError): ?>
        <div class="error">
            No se pudo cargar la información. Revisá la conexión.
        </div>
    <?php endif; ?>

    <section class="cards">

        <article>
            <small>Productos activos</small>
            <strong><?= h((string) $stats['active']) ?></strong>
        </article>

        <article>
            <small>Borradores</small>
            <strong><?= h((string) $stats['drafts']) ?></strong>
        </article>

        <article>
            <small>Variantes con stock bajo (0–3)</small>
            <strong><?= h((string) $stats['low_stock']) ?></strong>
        </article>

        <article>
            <small>Pedidos registrados</small>
            <strong><?= h((string) $stats['orders']) ?></strong>
        </article>

    </section>

    <section class="panels">

        <article>
            <h2>Productos</h2>

            <p>
                <?= h((string) $stats['products']) ?>
                productos registrados en la base de datos.
            </p>

            <div class="empty">
                <p>
                    Administrá el catálogo de tu tienda.
                </p>

                <a href="/admin/products.php">
                    Ir a Productos →
                </a>
            </div>
        </article>

        <article>
            <h2>Actividad reciente</h2>

            <?php if (!$recent): ?>

                <div class="empty">
                    Todavía no hay actividad registrada.
                </div>

            <?php else: ?>

                <ul>
                    <?php foreach ($recent as $r): ?>
                        <li>
                            <?= h($r['action']) ?>
                            ·
                            <?= h($r['entity_type']) ?>

                            <small>
                                <?= h($r['created_at']) ?>
                            </small>
                        </li>
                    <?php endforeach; ?>
                </ul>

            <?php endif; ?>

        </article>

    </section>

    <p class="notice">
        Entorno de desarrollo · Ventas y pedidos reales no habilitados.
    </p>

</main>

</body>
</html>
