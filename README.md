# InYou

Ecommerce de InYou preparado para PHP + MySQL en Hostinger.

## Estado del proyecto

- E01 — Tienda pública / experiencia base: completada.
- E02 — Login, dashboard y CRUD inicial de productos: completada como base administrativa.
- E03 — Modelo definitivo de catálogo: **cerrado en GitHub**.
- E04 — Panel completo de catálogo: siguiente etapa.
- Producción Hostinger: pendiente hasta cerrar el desarrollo.

## Estructura

```text
admin/                  Panel administrativo
api/v1/                 API pública
database/migrations/    Migraciones SQL versionadas
docs/                   Contratos y documentación
assets/                  Recursos públicos
data/                    Configuración y catálogo demo
tests/                   Pruebas de dominio
app.js                   Interfaz de la tienda
store-core.mjs           Reglas de catálogo y carrito
index.html               Tienda pública
```

## Catálogo E03

Fuente de verdad prevista: MySQL.

Entidades:

- `products`
- `product_variants`
- `sizes`
- `collections`
- `product_collections`
- `product_images`

Incluye soporte para:

- variantes por talle y color;
- stock físico;
- modalidad bajo pedido;
- plazo de preparación;
- precio específico por variante;
- múltiples imágenes con imagen principal;
- colecciones;
- tipo, público y fit del producto.

Contrato completo: `docs/E03_CATALOGO_MODELO.md`.

## Pruebas de dominio

Con Node.js disponible:

```bash
node --test tests/store.test.mjs
```

## Migraciones

`database/migrations/001_catalog_model.sql` está versionada pero **no debe ejecutarse automáticamente en producción**.

Antes de aplicar cambios en Hostinger se debe comparar la migración con el esquema MySQL existente.

## Seguridad

Las credenciales de MySQL no pertenecen al repositorio. En producción se cargan desde `inyou-private/db.php`, fuera de `public_html`.

## Próxima etapa

E04 implementará en `/admin` la administración completa de producto, variantes, inventario, colecciones e imágenes usando el contrato cerrado en E03.
