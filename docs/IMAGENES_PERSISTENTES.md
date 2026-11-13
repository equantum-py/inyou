# InYou · Almacenamiento persistente de imágenes

En Vercel Container el directorio `assets/products` no es almacenamiento persistente. Desde esta corrección las **nuevas cargas** de imágenes se guardan en MySQL en `product_image_blobs` en la misma transacción que los metadatos de `product_images`.

## Puesta en marcha
- La primera carga intenta crear la tabla automáticamente con `CREATE TABLE IF NOT EXISTS`. Si el usuario MySQL carece de permiso `CREATE`, aplicar previamente `database/migrations/003_persistent_images.sql` con un usuario autorizado. En ese caso la carga mostrará un error y NO confirmará éxito falso.
- En el panel, la imagen se sirve autenticada mediante `/admin/image-file.php?id=<id>`.
- Para los productos publicados, el API sirve la imagen mediante `/api/v1/product-image.php?id=<id>`.
- Las imágenes antiguas guardadas únicamente en disco pueden haber desaparecido de Vercel. **Hay que volver a cargarlas desde los archivos originales.** Las tarjetas las marcarán como no disponibles en lugar de mostrar iconos rotos.
- Eliminar una imagen elimina también su binario de MySQL.
- El tamaño está limitado a 5 MB, tipos JPG/PNG/WEBP y máximo 25 megapíxeles.
- Los respaldos de MySQL deben incluir también `product_image_blobs`. Con muchos productos, conviene migrar a almacenamiento de objetos persistente y controlar las cuotas de la base de datos.
- No se modifica la activación de ventas ni de WhatsApp.
