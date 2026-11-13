-- InYou: imágenes persistentes en MySQL (apto para despliegue con contenedores efímeros).
-- Los archivos originales NO se pueden recuperar desde metadatos preexistentes.
CREATE TABLE IF NOT EXISTS product_image_blobs (
  image_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  mime_type VARCHAR(30) NOT NULL,
  image_data LONGBLOB NOT NULL,
  byte_length INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
