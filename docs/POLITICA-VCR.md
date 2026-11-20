# InYou — Política de retención Vercel Container Registry (VCR)

**Estado:** Implementada para supervisión y limpieza manual protegida. Ningún borrado automático está autorizado.

## Reglas
- Registro único: `equantum-pys-projects/inyou/dockerfile`.
- Revisión semanal (lunes) desde GitHub Actions; **solo lectura**.
- Aviso al superar **38 imágenes**; objetivo operativo **30**.
- Mantener como mínimo las **12 imágenes más recientes**.
- No eliminar imágenes de menos de **14 días**.
- Nunca borrar producción ni el despliegue de recuperación elegido.
- Máximo **8 eliminaciones por ejecución**.
- Conservar siempre el registro `dockerfile`; no tocar archivos en Supabase, productos ni MySQL.
- Si falta información o Vercel cambia el formato de salida, bloquear la eliminación.

## Activación de vigilancia semanal
En GitHub → Settings → Secrets and variables → Actions, crear el secreto `VERCEL_TOKEN` con acceso al proyecto `inyou` del equipo `equantum-pys-projects`. Nunca copiarlo en chats o commits. Si falta, el workflow informa el bloqueo y no realiza cambios.

## Revisión
```bash
bash scripts/cleanup-vcr.sh --report
```

## Limpieza manual (solo después de verificar qué está publicado)
1. En Vercel → Deployments, identificar el commit de producción vigente y otro despliegue Ready que se conservará para recuperación.
2. Confirmar que ambos tags de **12 caracteres** están en `vercel vcr image ls dockerfile`.
3. Desde Codespaces autenticado, ejecutá sustituyendo por los tags reales:

```bash
VCR_PRODUCTION_TAG=<sha12-produccion> VCR_ROLLBACK_TAG=<sha12-recuperacion> bash scripts/cleanup-vcr.sh --apply
```

4. Verificar la lista de candidatas y confirmar escribiendo `LIMPIAR`.

El script se niega a borrar si faltan los dos tags o no existen en el registro. **No se deben deducir por fecha.**

La retención de Deployments de Vercel y la de imágenes VCR son distintas; configurar la primera no sustituye la revisión de VCR.
