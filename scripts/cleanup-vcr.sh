#!/usr/bin/env bash
# InYou: mantenimiento manual y conservador del registro Docker Vercel.
set -euo pipefail
MODE="\${1:---dry-run}"
if [[ "$MODE" != "--dry-run" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash scripts/cleanup-vcr.sh --dry-run | --apply"; exit 2
fi
echo "InYou · limpieza VCR · $MODE"
OUTPUT="$(npx --yes vercel@65.15.0 vcr image ls dockerfile --project inyou --scope equantum-pys-projects)"
mapfile -t CANDIDATES < <(
  printf '%s\n' "$OUTPUT" | awk '
    /^[[:space:]]*image_[A-Za-z0-9]+[[:space:]]/ {
      days=$(NF-1)
      if (days ~ /^[0-9]+d$/) {
        sub(/d$/, "", days)
        if (days+0 >= 14) print $1 " " $3 " " days
      }
    }' | sort -k3,3nr | head -n 8
)
if (( \${#CANDIDATES[@]} == 0 )); then
  echo "No hay imágenes antiguas elegibles. Nada que borrar."
  exit 0
fi
echo "Candidatas creadas hace 14 días o más (máximo 8):"
for LINE in "\${CANDIDATES[@]}"; do
  read -r ID TAG DAYS <<< "$LINE"
  echo "  $ID · tag $TAG · $DAYS días"
done
if [[ "$MODE" == "--dry-run" ]]; then
  echo "Consulta terminada. Para aplicar: bash scripts/cleanup-vcr.sh --apply"
  exit 0
fi
echo "ATENCIÓN: es irreversible; puede afectar un despliegue histórico."
read -r -p "Escribí LIMPIAR para confirmar: " ANSWER
if [[ "$ANSWER" != "LIMPIAR" ]]; then echo "Cancelado."; exit 0; fi
for LINE in "\${CANDIDATES[@]}"; do
  read -r ID TAG DAYS <<< "$LINE"
  echo "Eliminando $ID"
  printf 'yes\n' | npx --yes vercel@65.15.0 vcr image delete dockerfile "$ID" --project inyou --scope equantum-pys-projects
done
echo "Limpieza finalizada. Ningún producto, foto ni base de datos fue modificado."
