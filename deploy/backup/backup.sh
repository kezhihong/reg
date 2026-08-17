#!/usr/bin/env bash
# 每日备份脚本（docs/05 §6.1）：mysqldump 全量 + 压缩；密钥独立备份。
# cron: 0 2 * * * /opt/mem-reg/deploy/backup/backup.sh
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups/mem-reg}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
MYSQL_USER="${MYSQL_USER:-app}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:?MYSQL_PASSWORD required}"
MYSQL_DATABASE="${MYSQL_DATABASE:-mem_reg}"
ENV_FILE="${ENV_FILE:-/opt/mem-reg/deploy/.env}"

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)"

echo "[backup] dumping ${MYSQL_DATABASE}..."
mysqldump \
  --single-transaction \
  --set-gtid-purged=OFF \
  -h"$MYSQL_HOST" -P"$MYSQL_PORT" -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
  "$MYSQL_DATABASE" \
  | gzip > "${BACKUP_DIR}/${MYSQL_DATABASE}_${STAMP}.sql.gz"

# 密钥独立保存（丢失 = TOTP 密文不可解 + 会话不可验证，docs/05 §6.1）
if [ -f "$ENV_FILE" ]; then
  grep -E '^(JWT_SECRET|JWT_TICKET_SECRET|TOTP_ENCRYPTION_KEY)=' "$ENV_FILE" \
    > "${BACKUP_DIR}/keys_${STAMP}.env"
fi

# 保留期清理
find "$BACKUP_DIR" -name '*.sql.gz' -mtime +"$RETENTION_DAYS" -delete
find "$BACKUP_DIR" -name 'keys_*.env' -mtime +"$RETENTION_DAYS" -delete

echo "[backup] done: ${BACKUP_DIR}/${MYSQL_DATABASE}_${STAMP}.sql.gz"
