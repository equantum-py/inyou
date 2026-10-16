# E04 — Panel completo de catálogo

Estado: **CERRADO EN CÓDIGO / PENDIENTE DE APLICACIÓN Y PRUEBA EN HOSTINGER**

## Alcance cerrado

El panel `/admin` permite gestionar:

- productos;
- estado borrador / publicado / archivado;
- colección;
- tipo, público y fit;
- variantes;
- talles y colores;
- stock físico;
- modalidad `stock` / `bajo_pedido`;
- plazo de preparación;
- precio específico por variante;
- imágenes JPG, PNG y WEBP;
- imagen principal;
- texto alternativo y orden;
- colecciones;
- actividad administrativa básica.

## Reglas de publicación

Un producto no puede pasar a `active` si no cumple todos estos puntos:

1. precio base mayor a cero;
2. al menos una colección activa;
3. al menos una variante activa y comprable;
4. al menos una imagen.

Una variante es comprable cuando:

- `availability_mode = stock` y `stock_qty > 0`; o
- `availability_mode = bajo_pedido`.

Para `bajo_pedido` el plazo (`lead_time`) es obligatorio desde el panel.

## Protecciones de integridad

- No se puede eliminar una colección asignada a productos.
- No se puede eliminar la última variante activa de un producto publicado.
- No se puede eliminar la última imagen de un producto publicado.
- Al eliminar la imagen principal, otra imagen disponible pasa a ser principal.
- Todas las mutaciones administrativas requieren sesión, permiso de catálogo y CSRF.
- Los uploads validan MIME real y tamaño máximo de 5 MB.
- Los archivos se generan con nombre aleatorio y se guardan bajo `assets/products/`.

## Auditoría

Las operaciones de catálogo llaman a `auditLog()`:

- `product.create`
- `product.update`
- `product.status.*`
- `variant.create`
- `variant.update`
- `variant.delete`
- `collection.create`
- `collection.update`
- `collection.delete`
- `image.upload`
- `image.update`
- `image.primary`
- `image.delete`

La auditoría está diseñada para no impedir una operación principal si el registro de auditoría falla; el fallo se envía al log del servidor.

## Pruebas versionadas

- `tests/store.test.mjs`: reglas del catálogo y carrito.
- `tests/admin_contract.test.mjs`: contratos estáticos del panel E04.

Comando previsto:

```bash
node --test tests/store.test.mjs tests/admin_contract.test.mjs
```

Las pruebas están versionadas en GitHub. La ejecución final debe hacerse en un entorno con Node antes del despliegue productivo.

## Checklist manual previo a producción

1. Crear producto borrador.
2. Editar producto y asignar colección.
3. Crear variante de stock.
4. Editar stock, talle, color y precio de esa variante.
5. Crear variante bajo pedido con plazo.
6. Subir imagen válida.
7. Cambiar texto alternativo, orden e imagen principal.
8. Verificar que no permita publicar sin precio, colección, variante disponible o imagen.
9. Publicar producto válido.
10. Verificar que no permita eliminar su última variante activa.
11. Verificar que no permita eliminar su última imagen.
12. Editar colección.
13. Verificar que no permita eliminar una colección en uso.
14. Confirmar actividad reciente en el dashboard.
15. Probar rol sin permiso de catálogo.
16. Probar formularios con CSRF inválido.
17. Probar panel en móvil.

## Fuera de E04

No forma parte de esta etapa:

- configuración comercial de la tienda;
- pedidos;
- WhatsApp final;
- pagos;
- despliegue en Hostinger.

Esos puntos comienzan en la etapa siguiente.
