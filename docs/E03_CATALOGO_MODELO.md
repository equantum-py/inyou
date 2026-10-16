# E03 — Modelo definitivo del catálogo

Estado: **CERRADO EN CÓDIGO / PENDIENTE DE APLICACIÓN EN HOSTINGER**  
Alcance: `products + product_variants + sizes + collections + product_collections + product_images`.

## Objetivo

Definir un único modelo de catálogo para que el panel administrativo, la API pública y la tienda usen la misma fuente de verdad en MySQL.

La tienda pública no depende de valores hardcodeados para colección, talle, imagen, disponibilidad, stock o precio de variante.

## Modelo definitivo

### Productos (`products`)

Campos utilizados por E03:

- `id`
- `sku`
- `slug`
- `name`
- `description`
- `base_price`
- `currency` (`PYG`)
- `status`: `draft`, `active`, `archived`
- `featured`
- `sort_order`
- `product_type`: `remera`, `kepi`
- `audience`: `Mujer`, `Hombre`, `Unisex`
- `fit`: vacío, `Regular`, `Oversize`, `Cropped`, `Único`
- `created_at`, `updated_at`

Un producto activo sin variantes comprables puede mostrarse, pero queda como **No disponible**.

### Talles (`sizes`)

Talles iniciales:

- `S`
- `M`
- `L`
- `XL`
- `UNICO` → `Único`

La API resuelve el nombre real del talle mediante `size_id`; nunca expone el ID como texto visible.

### Variantes (`product_variants`)

Campos usados:

- `id`
- `product_id`
- `size_id`
- `sku`
- `color_name`
- `price_override`
- `stock_qty`
- `availability_mode`: `stock`, `bajo_pedido`
- `lead_time`
- `is_active`
- `sort_order`

Reglas cerradas:

- `stock` requiere `stock_qty > 0` para agregarse al carrito.
- `bajo_pedido` puede agregarse con stock cero.
- variantes inactivas no se exponen.
- `price_override`, cuando existe y es positivo, prevalece sobre `base_price`.
- el carrito calcula subtotal con el precio real de la variante.
- el mensaje de WhatsApp usa el precio real de la variante y muestra el plazo de bajo pedido.

### Colecciones (`collections`)

Colecciones iniciales:

1. `Floral & Arte`
2. `Esenciales`
3. `Fe & Esperanza`
4. `Momentos Especiales`

`product_collections` resuelve la relación N:M. La API utiliza la primera colección activa por orden como colección principal visible.

Un producto activo sin colección activa no se publica en la respuesta de catálogo: se registra el problema en el log del servidor y se omite. Esto evita inventar una colección.

### Imágenes (`product_images`)

Campos usados:

- `id`
- `product_id`
- `file_path`
- `alt_text`
- `is_primary`
- `sort_order`
- `created_at`

Reglas cerradas:

- rutas exclusivamente relativas bajo `assets/products/`.
- extensiones públicas admitidas: JPG, JPEG, PNG y WEBP.
- se rechazan rutas con `..`.
- la API ordena primero la imagen primaria y después por `sort_order`.
- el frontend recibe `image` para compatibilidad y `images[]` para el modelo definitivo.
- el texto alternativo se conserva para accesibilidad.
- si una imagen falla al cargar, el frontend usa su ilustración de respaldo sin romper la tarjeta.

## Contrato definitivo de API

`GET /api/v1/products.php`

```json
{
  "id": "remera-floral",
  "name": "Remera Floral",
  "description": "...",
  "collection": "Floral & Arte",
  "tipo": "remera",
  "para": "Unisex",
  "fit": "Regular",
  "priceGs": 150000,
  "image": "assets/products/remera-floral.webp",
  "images": [
    {
      "src": "assets/products/remera-floral.webp",
      "alt": "Remera floral frente"
    }
  ],
  "published": true,
  "variants": [
    {
      "sku": "INY-FLO-M-BEI",
      "size": "M",
      "color": "Beige",
      "mode": "bajo_pedido",
      "stock": 0,
      "leadTime": "3 a 5 días",
      "priceGs": 165000
    }
  ]
}
```

## Frontend cerrado en E03

`store-core.mjs` ahora contempla:

- validación del catálogo;
- validación segura de imágenes;
- múltiples imágenes por producto;
- precio por variante;
- rango de precios;
- stock físico;
- bajo pedido;
- limpieza del carrito contra el catálogo vigente;
- límite de cantidad según stock;
- subtotal por precio real de variante.

`app.js` ahora contempla:

- imagen principal y `alt` real;
- precio desde / rango de precio;
- actualización visual del precio al cambiar variante;
- stock o plazo bajo pedido en el selector;
- precio unitario y subtotal en carrito.

## Pruebas versionadas

Archivo: `tests/store.test.mjs`

Cubre:

1. normalización de imágenes;
2. precio por variante;
3. rango de precio;
4. bloqueo de stock cero en modalidad stock;
5. compra permitida en bajo pedido;
6. límite de stock;
7. subtotal con precio de variante;
8. rechazo de rutas de imagen inseguras.

Ejecutar en un entorno con Node.js:

```bash
node --test tests/store.test.mjs
```

## Migración

Archivo: `database/migrations/001_catalog_model.sql`.

La migración está versionada y **no se ejecuta automáticamente**. Antes de Hostinger se debe comparar contra el esquema real existente para evitar duplicar claves foráneas o columnas ya creadas.

## Fuera de E03

Queda deliberadamente para E04:

- formularios visuales para variantes;
- gestión visual de stock;
- carga y eliminación de imágenes;
- selección visual de colección;
- validación de publicación desde el panel;
- gestión de talles/colecciones desde administración.

Pedidos, pagos y despliegue tampoco forman parte de E03.

## Definición de terminado — resultado

- [x] modelo documentado;
- [x] migración SQL versionada;
- [x] talles iniciales definidos;
- [x] colecciones iniciales definidas;
- [x] precio por variante implementado;
- [x] lógica de stock y bajo pedido implementada;
- [x] imágenes múltiples y ruta segura implementadas;
- [x] API alineada con el frontend;
- [x] pruebas de dominio versionadas;
- [x] sin credenciales en repositorio;
- [x] sin modificación automática de producción;
- [ ] migración aplicada y verificada en Hostinger — reservado para etapa final de producción.

**E03 queda cerrado en GitHub.**
