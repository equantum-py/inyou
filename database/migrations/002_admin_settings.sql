-- InYou ADMIN-01 — Configuración pública controlada

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`,`value`) VALUES
    ('whatsapp_phone',''),
    ('whatsapp_enabled','0'),
    ('store_ready','0'),
    ('currency','PYG')
ON DUPLICATE KEY UPDATE `value`=`value`;
