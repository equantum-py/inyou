# InYou

Ecommerce de InYou preparado para PHP + MySQL en Hostinger.

## Estado del proyecto

- E01 — Tienda pública / experiencia base: completada.
- E02 — Login, dashboard y CRUD inicial: completada como base administrativa.
- E03 — Modelo definitivo de catálogo: **cerrado en código**.
- E04 — Panel completo de catálogo: **cerrado en código**.
- E05 — Pedidos y configuración comercial: siguiente etapa.
- Producción Hostinger: pendiente hasta cerrar el desarrollo y validar migraciones.

## Estructura

```text
admin/                  Panel administrativo
api/v1/                 API pública
database/migrations/    Migraciones SQL versionadas
docs/                   Contratos y documentación
assets/products/         Imágenes administradas del catálogo
data/                    Configuración y catálogo demo
tests/                   Pruebas de dominio y contratos
app.js                   Interfaz de la tienda
store-core.mjs           Reglas de catálogo y carrito
index.html               Tienda pública
```

## E03 — Catálogo

Fuente de verdad prevista: MySQL.

Entidades principales:

- `products`
- `product_variants`
- `sizes`
- `collections`
- `product_collections`
- `product_images`

Incluye variantes, stock, bajo pedido, plazo, precio por variante, múltiples imágenes, colecciones, tipo, público y fit.

Contrato: `docs/E03_CATALOGO_MODELO.md`.

## E04 — Panel de catálogo

El panel permite administrar:

- productos y estados;
- colecciones;
- variantes por talle y color;
- stock y bajo pedido;
- precio específico por variante;
- imágenes, imagen principal, alt y orden;
- auditoría básica de cambios.

La publicación exige precio válido, colección activa, al menos una variante comprable y al menos una imagen.

Contrato y checklist: `docs/E04_PANEL_CATALOGO.md`.

## Pruebas

Con Node.js disponible:

```bash
node --test tests/store.test.mjs tests/admin_contract.test.mjs
```

Las pruebas están versionadas; la ejecución final forma parte del control previo al despliegue.

## Migraciones

`database/migrations/001_catalog_model.sql` está versionada pero **no debe ejecutarse automáticamente en producción**.

Antes de aplicar cambios en Hostinger se debe comparar la migración con el esquema MySQL existente.

## Seguridad

- Las credenciales de MySQL no pertenecen al repositorio.
- En producción se cargan desde `inyou-private/db.php`, fuera de `public_html`.
- Las mutaciones administrativas requieren sesión, permiso y CSRF.
- Los uploads aceptan JPG, PNG y WEBP por MIME real, con máximo 5 MB.
- `assets/products/.htaccess` bloquea ejecución de scripts e indexado de directorio en servidores compatibles.

## Próxima etapa

E05 debe implementar pedidos/consultas y configuración comercial sin habilitar producción hasta completar las pruebas y la integración final con Hostinger.
