-- InYou E04 — Auditoría con contexto completo
-- Aplicar una sola vez sobre la base activa.

ALTER TABLE admin_audit_log
    ADD COLUMN IF NOT EXISTS admin_user_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS entity_id BIGINT UNSIGNED NULL AFTER entity_type,
    ADD COLUMN IF NOT EXISTS details LONGTEXT NULL AFTER entity_id;

-- Los registros históricos anteriores a esta migración conservarán admin_user_id/entity_id/details en NULL.
-- Los eventos nuevos usan estas columnas desde auditLog().
