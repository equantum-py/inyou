-- InYou E03 — Modelo definitivo del catálogo
-- Migración versionada. NO ejecutar automáticamente en producción.
-- Revisar compatibilidad con el esquema existente antes de aplicar.

START TRANSACTION;

-- 1) Productos: ampliar el modelo base sin destruir columnas existentes.
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS product_type ENUM('remera','kepi') NOT NULL DEFAULT 'remera' AFTER sort_order,
    ADD COLUMN IF NOT EXISTS audience ENUM('Mujer','Hombre','Unisex') NOT NULL DEFAULT 'Unisex' AFTER product_type,
    ADD COLUMN IF NOT EXISTS fit ENUM('','Regular','Oversize','Cropped','Único') NOT NULL DEFAULT '' AFTER audience;

-- 2) Talles reutilizables.
CREATE TABLE IF NOT EXISTS sizes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    label VARCHAR(60) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sizes_code (code),
    KEY idx_sizes_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sizes (code, label, sort_order, is_active)
VALUES
    ('S', 'S', 10, 1),
    ('M', 'M', 20, 1),
    ('L', 'L', 30, 1),
    ('XL', 'XL', 40, 1),
    ('UNICO', 'Único', 50, 1)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active);

-- 3) Colecciones.
CREATE TABLE IF NOT EXISTS collections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(160) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_collections_slug (slug),
    UNIQUE KEY uq_collections_name (name),
    KEY idx_collections_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO collections (slug, name, sort_order, is_active)
VALUES
    ('floral-arte', 'Floral & Arte', 10, 1),
    ('esenciales', 'Esenciales', 20, 1),
    ('fe-esperanza', 'Fe & Esperanza', 30, 1),
    ('momentos-especiales', 'Momentos Especiales', 40, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active);

-- 4) Variantes: ampliar tabla existente.
ALTER TABLE product_variants
    ADD COLUMN IF NOT EXISTS availability_mode ENUM('stock','bajo_pedido') NOT NULL DEFAULT 'stock' AFTER stock_qty,
    ADD COLUMN IF NOT EXISTS lead_time VARCHAR(120) NOT NULL DEFAULT '' AFTER availability_mode,
    ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER is_active;

-- Asegurar stock no negativo a nivel de datos existentes.
UPDATE product_variants SET stock_qty = 0 WHERE stock_qty < 0;

-- 5) Relación productos / colecciones.
CREATE TABLE IF NOT EXISTS product_collections (
    product_id BIGINT UNSIGNED NOT NULL,
    collection_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, collection_id),
    KEY idx_product_collections_collection (collection_id, sort_order),
    CONSTRAINT fk_product_collections_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_product_collections_collection
        FOREIGN KEY (collection_id) REFERENCES collections(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Imágenes de producto.
CREATE TABLE IF NOT EXISTS product_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    alt_text VARCHAR(255) NOT NULL DEFAULT '',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_images_product_sort (product_id, is_primary, sort_order, id),
    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) Clave foránea de talla en variantes, si la tabla/índice previo no la tiene.
-- MySQL no ofrece ADD CONSTRAINT IF NOT EXISTS. Esta parte se deja explícita para revisión
-- antes de producción porque el esquema actual puede ya contener la FK.
--
-- ALTER TABLE product_variants
--     ADD CONSTRAINT fk_product_variants_size
--     FOREIGN KEY (size_id) REFERENCES sizes(id)
--     ON DELETE SET NULL ON UPDATE CASCADE;

COMMIT;
