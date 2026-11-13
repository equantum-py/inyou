# InYou — Fotografías persistentes (Supabase Storage)

## Arquitectura actual
- **Supabase Storage:** bucket público `inyou-products`, archivos `products/p<id>-<aleatorio>.<jpg|png|webp>`.
- **MySQL Hostinger:** mantiene los metadatos de `product_images`, productos, variantes, precios, usuarios y auditoría. `file_path` de nuevas fotos: `supabase/products/p<id>-<aleatorio>.<ext>`.
- **Panel PHP:** `admin/images.php` realiza las cargas desde el servidor autenticado a Supabase con `INY_SUPABASE_URL` e `INY_SUPABASE_SECRET_KEY`.
- **Tienda pública:** `api/v1/products.php` devuelve únicamente las URLs públicas de las imágenes; nunca las claves.
- **Biblioteca:** `admin/media.php` muestra imágenes de Supabase y de sistemas anteriores.

## Configuración
1. Bucket `inyou-products`: público **solo para lectura**; tamaño máximo de 10 MB; MIME `image/jpeg,image/png,image/webp`.
2. Guardar las variables **server-only** `INY_SUPABASE_URL` y `INY_SUPABASE_SECRET_KEY` en el entorno Production de Vercel.
3. Asegurar que los despliegues también reciban esas variables y que el contenedor esté actualizado.
4. El servidor PHP admite 10 MB de archivo y 12 MB de cuerpo multipart; valida MIME, dimensiones (hasta 25 MP) y roles/CSRF.
5. Las claves tipo `sb_secret_` se envían **exclusivamente** en la cabecera `apikey`; nunca al navegador ni al repositorio.

## Validación pendiente tras desplegar
1. Abrir `/admin/products.php` → Imágenes de un producto.
2. Subir una nueva foto JPG/PNG/WEBP y marcarla como principal.
3. Comprobar que aparece en `Storage → inyou-products → products`.
4. Verificar su visualización en **Galería**, **Medios** y en el catálogo público del producto publicado.
5. Comprobar persistencia después de un redespliegue; probar eliminación y confirmación de borradores.
6. Ensayar error de tamaño (>10 MB), MIME no permitido y usuario sin permiso.

## Compatibilidad y límites
- Las imágenes antiguas guardadas en filesystem efímero de Vercel pueden haber desaparecido. Es necesario volver a subir los originales.
- Las imágenes almacenadas previamente como blobs MySQL se siguen leyendo si su tabla existe. La carga nueva ya no escribe blobs.
- Los uploads estándar de Supabase admiten 10 MB, aunque para archivos **superiores a 6 MB** Supabase recomienda TUS reanudable por confiabilidad; si hay fallos con fotos pesadas, implementar carga reanudable antes de exigirlas en producción.
- El bloqueo del Container Registry de Vercel es independiente de Supabase; si persiste, el código no llegará a la URL pública.
- Nada de esto habilita ventas, pagos o WhatsApp.
