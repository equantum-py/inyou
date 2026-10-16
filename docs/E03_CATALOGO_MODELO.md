# E03 — Modelo definitivo del catálogo

Estado: **DEFINIDO / PREPARADO PARA IMPLEMENTACIÓN**  
Alcance: `products + product_variants + sizes + collections + product_collections + product_images`.

## Objetivo

Definir un único modelo de catálogo para que el panel administrativo, la API pública y la tienda usen la misma fuente de verdad en MySQL.

La tienda pública no debe depender de datos inventados ni de valores hardcodeados para colección, talle, imagen, disponibilidad o stock.

## Reglas de dominio

### Productos (`products`)

Un producto representa la ficha comercial principal.

Campos mínimos:

- `id`: identificador interno.
- `sku`: SKU base opcional del producto.
- `slug`: URL única y estable.
- `name`: nombre comercial.
- `description`: descripción.
- `base_price`: precio base en guaraníes.
- `currency`: `PYG`.
- `status`: `draft`, `active` o `archived`.
- `featured`: destacado en catálogo.
- `sort_order`: orden manual.
- `product_type`: `remera` o `kepi`.
- `audience`: `Mujer`, `Hombre` o `Unisex`.
- `fit`: `Regular`, `Oversize`, `Cropped`, `Único` o vacío.
- `created_at` / `updated_at`.

Un producto `active` puede mostrarse en la tienda aunque temporalmente no tenga una variante comprable; en ese caso debe mostrarse como **No disponible**.

### Talles (`sizes`)

Los talles son entidades reutilizables.

Campos mínimos:

- `id`.
- `code`: valor técnico único (`S`, `M`, `L`, `XL`, `UNICO`).
- `label`: texto visible (`S`, `M`, `L`, `XL`, `Único`).
- `sort_order`.
- `is_active`.

La API nunca debe mostrar `size_id` como si fuese el nombre del talle.

### Variantes (`product_variants`)

Una variante representa una combinación vendible de producto + talle + color.

Campos mínimos:

- `id`.
- `product_id`.
- `size_id` nullable para productos que no requieran talle.
- `sku`: obligatorio y único.
- `color_name`.
- `price_override`: precio opcional específico.
- `stock_qty`: stock físico.
- `availability_mode`: `stock` o `bajo_pedido`.
- `lead_time`: plazo visible para productos bajo pedido.
- `is_active`.
- `sort_order`.
- `created_at` / `updated_at`.

Reglas:

- `stock`: se puede agregar al carrito solo si `stock_qty > 0`.
- `bajo_pedido`: puede agregarse aunque `stock_qty = 0`.
- una variante inactiva nunca se expone públicamente.
- `price_override` prevalece sobre `products.base_price` cuando exista.

### Colecciones (`collections`)

Colecciones iniciales aprobadas por el frontend:

1. `Floral & Arte`
2. `Esenciales`
3. `Fe & Esperanza`
4. `Momentos Especiales`

Campos mínimos:

- `id`.
- `slug` único.
- `name` único.
- `description` opcional.
- `is_active`.
- `sort_order`.
- `created_at` / `updated_at`.

### Relación producto/colección (`product_collections`)

Relación N:M entre productos y colecciones.

Campos mínimos:

- `product_id`.
- `collection_id`.
- `sort_order`.

No se permite duplicar la misma relación producto/colección.

En la V1 la API utiliza la primera colección activa por orden como colección principal visible en la tarjeta.

### Imágenes (`product_images`)

Campos mínimos:

- `id`.
- `product_id`.
- `file_path`: ruta relativa bajo `assets/products/`.
- `alt_text`.
- `is_primary`.
- `sort_order`.
- `created_at`.

Reglas:

- solo rutas relativas.
- formatos permitidos al subir: JPG, PNG, WEBP.
- ninguna imagen puede ejecutar PHP.
- un producto debe tener como máximo una imagen primaria.
- la API usa la primaria; si no existe, usa la primera por `sort_order`.

## Contrato de salida de la API pública

`GET /api/v1/products.php`

Cada producto debe devolver:

```json
{
  "id": "remera-floral",
  "name": "Remera Floral",
  "description": "...",
  "collection": "Floral & Arte",
  "tipo": "remera",
  "para": "Unisex",
  "fit": "Regular",
  "priceGs": 175000,
  "image": "assets/products/remera-floral.webp",
  "published": true,
  "variants": [
    {
      "sku": "INY-FLO-S-BEI",
      "size": "S",
      "color": "Beige",
      "mode": "stock",
      "stock": 3,
      "leadTime": ""
    }
  ]
}
```

## Integridad obligatoria

- claves foráneas para relaciones de catálogo.
- SKU de variante único.
- slug de producto único.
- slug y nombre de colección únicos.
- stock nunca negativo.
- una relación producto/colección no puede duplicarse.
- índices en las claves foráneas y campos utilizados por el catálogo público.

## Fuera de E03

E03 no incluye todavía:

- formulario visual del panel para variantes.
- upload visual de imágenes.
- gestión visual de colecciones.
- pedidos o pagos.
- despliegue en Hostinger.

Esos módulos se construyen sobre este contrato sin volver a cambiar el modelo base.

## Definición de terminado de E03

E03 queda terminado cuando:

1. el modelo está documentado;
2. existe una migración SQL versionada;
3. están definidas las colecciones iniciales;
4. están definidos los talles iniciales;
5. la migración no contiene credenciales ni datos privados;
6. el contrato de API coincide con `store-core.mjs`;
7. ningún archivo modifica automáticamente la base de producción.
