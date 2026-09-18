#!/usr/bin/env bash
# ============================================================
# bin/backup.sh — Backup do HelpTI (banco + uploads)
# cPanel cron (1x/dia):  bash /caminho/bin/backup.sh
#
# Lê credenciais de config.local.php via um pequeno helper PHP.
# Destino externo: configure BACKUP_RCLONE_REMOTE (ex.: "gdrive:helpti-backups")
# ou deixe vazio para manter apenas cópia local em ./_backups.
# ============================================================
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%Y%m%d_%H%M%S)"
DEST="${DIR}/_backups"
KEEP_DAYS=14
RCLONE_REMOTE="${BACKUP_RCLONE_REMOTE:-}"

mkdir -p "$DEST"

# Extrai credenciais do config.local.php sem expô-las no processo
read -r DB_HOST DB_NAME DB_USER DB_PASS < <(php -r '
  $c = require "'"$DIR"'/config.local.php";
  echo $c["DB_HOST"]," ",$c["DB_NAME"]," ",$c["DB_USER"]," ",$c["DB_PASS"],"\n";
')

echo "[$(date -Is)] Dump do banco ${DB_NAME}..."
export MYSQL_PWD="$DB_PASS"
mysqldump --single-transaction --quick --routines --no-tablespaces \
  -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
  | gzip > "${DEST}/db_${STAMP}.sql.gz"
unset MYSQL_PWD

echo "[$(date -Is)] Tar de uploads/..."
tar -czf "${DEST}/uploads_${STAMP}.tar.gz" -C "$DIR" uploads

echo "[$(date -Is)] Limpando backups locais com mais de ${KEEP_DAYS} dias..."
find "$DEST" -type f -name '*.gz' -mtime "+${KEEP_DAYS}" -delete

if [ -n "$RCLONE_REMOTE" ] && command -v rclone >/dev/null 2>&1; then
  echo "[$(date -Is)] Enviando para ${RCLONE_REMOTE}..."
  rclone copy "${DEST}/db_${STAMP}.sql.gz"      "${RCLONE_REMOTE}/" --quiet
  rclone copy "${DEST}/uploads_${STAMP}.tar.gz" "${RCLONE_REMOTE}/" --quiet
else
  echo "[$(date -Is)] AVISO: sem destino externo (BACKUP_RCLONE_REMOTE vazio ou rclone ausente)."
  echo "                 Backup existe apenas em ${DEST} — mesma máquina que o servidor."
fi

echo "[$(date -Is)] Backup concluído: ${STAMP}"
