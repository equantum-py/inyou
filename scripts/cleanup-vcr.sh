#!/usr/bin/env bash
# InYou · Reporte seguro por defecto; borrado únicamente interactivo.
set -euo pipefail
MODE="${1:---report}"
[[ "$MODE" == "--dry-run" ]] && MODE="--report"
[[ "$MODE" == "--report" || "$MODE" == "--apply" ]] || { echo "Uso: --report | --apply"; exit 2; }
if [[ "$MODE" == "--apply" && "${CI:-}" == "true" ]]; then echo "No se permite borrar desde CI"; exit 2; fi
VERCEL=(npx --yes vercel@66.2.0)
[[ -z "${VERCEL_TOKEN:-}" ]] || VERCEL+=(--token "$VERCEL_TOKEN")
if [[ -n "${VCR_LIST_FIXTURE:-}" ]]; then OUTPUT="$(cat "$VCR_LIST_FIXTURE")"
else OUTPUT="$("${VERCEL[@]}" vcr image ls dockerfile --project inyou --scope equantum-pys-projects 2>&1)" || exit 1
fi
mapfile -t RECORDS < <(printf '%s\n' "$OUTPUT" | awk '
$1 ~ /^image_[A-Za-z0-9]+$/ && $4=="Image" && $5=="Ready" {
 age=$(NF-1)
 if(age ~ /^[0-9]+d$/){sub(/d$/,"",age);days=age+0}
 else if(age ~ /^[0-9]+[hms]$/){days=0}
 else next
 if($3 !~ /^[A-Za-z0-9._-]+$/)next
 print $1 " " $3 " " days
}')
COUNT=${#RECORDS[@]}
(( COUNT > 0 )) || { echo "Lectura vacía o formato VCR inesperado. No se borra nada."; exit 1; }
echo "InYou VCR: $COUNT imágenes (alerta >38; objetivo 30)"
(( COUNT > 38 )) || { echo "Estado saludable; sin limpieza."; exit 0; }
PROD="${VCR_PRODUCTION_TAG:-}"
BACK="${VCR_ROLLBACK_TAG:-}"
PROTECTED=false
if [[ "$PROD" =~ ^[a-f0-9]{12}$ && "$BACK" =~ ^[a-f0-9]{12}$ && "$PROD" != "$BACK" ]]; then
  HAVE_PROD=false; HAVE_BACK=false
  for ROW in "${RECORDS[@]}"; do
    read -r ID TAG DAYS <<< "$ROW"
    [[ "$TAG" != "$PROD" ]] || HAVE_PROD=true
    [[ "$TAG" != "$BACK" ]] || HAVE_BACK=true
  done
  if [[ "$HAVE_PROD" == true && "$HAVE_BACK" == true ]]; then PROTECTED=true; fi
fi
NEED=$((COUNT-30)); (( NEED <= 8 )) || NEED=8
INDEX=0; CANDIDATES=()
for ROW in "${RECORDS[@]}"; do
 INDEX=$((INDEX+1)); (( INDEX > 12 )) || continue
 read -r ID TAG DAYS <<< "$ROW"
 (( DAYS >= 14 )) || continue
 [[ "$TAG" != "$PROD" && "$TAG" != "$BACK" ]] || continue
 CANDIDATES+=("$ROW")
 (( ${#CANDIDATES[@]} < NEED )) || break
done
echo "Candidatas: ${#CANDIDATES[@]}; conservamos últimas 12 y todas las menores de 14 días."
for ROW in "${CANDIDATES[@]}"; do echo "  $ROW"; done
if [[ "$MODE" == "--report" ]]; then
 [[ "$PROTECTED" == true ]] || echo "Protección pendiente: verificar tags producción y rollback en Vercel."
 echo "Reporte solamente. No se eliminó nada."
 exit 0
fi
[[ "$PROTECTED" == true ]] || { echo "BLOQUEADO: establecer VCR_PRODUCTION_TAG y VCR_ROLLBACK_TAG verificadas." >&2; exit 3; }
(( ${#CANDIDATES[@]} > 0 )) || exit 0
echo "Protegidas: producción $PROD y rollback $BACK. Eliminación irreversible."
read -r -p "Escribí LIMPIAR para confirmar: " ANSWER
[[ "$ANSWER" == LIMPIAR ]] || exit 0
for ROW in "${CANDIDATES[@]}"; do
 read -r ID TAG DAYS <<< "$ROW"
 NOW="$("${VERCEL[@]}" vcr image ls dockerfile --project inyou --scope equantum-pys-projects 2>&1)" || exit 1
 grep -Eq "^[[:space:]]*$ID[[:space:]]+[^[:space:]]+[[:space:]]+$TAG[[:space:]]" <<< "$NOW" || { echo "La imagen cambió; cancelado."; exit 1; }
 [[ "$TAG" != "$PROD" && "$TAG" != "$BACK" ]] || { echo "Imagen protegida; cancelado."; exit 1; }
 printf 'yes\n' | "${VERCEL[@]}" vcr image delete dockerfile "$ID" --project inyou --scope equantum-pys-projects
done
echo "Limpieza VCR finalizada. MySQL y Supabase intactos."
