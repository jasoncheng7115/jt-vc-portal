#!/usr/bin/env bash
# Keycloak 每日備份：PostgreSQL 資料庫（pg_dump 自訂格式）＋ 設定檔（.env、realm.env、client secret、憑證、compose）。
# 備份含密鑰，目錄與檔案只有 root 能讀；請另外複製到主機以外的地方（見 KEYCLOAK-SETUP「日常維運 → 備份」）。
# 用法：/opt/keycloak/backup-keycloak.sh        （排程範例：/etc/cron.d/keycloak-backup）
#   環境變數：KC_DIR（預設 /opt/keycloak）、BACKUP_DIR（預設 /var/backups/keycloak）、KEEP_DAYS（預設 30）
set -euo pipefail
KC_DIR=${KC_DIR:-/opt/keycloak}
BACKUP_DIR=${BACKUP_DIR:-/var/backups/keycloak}
KEEP_DAYS=${KEEP_DAYS:-30}
TS=$(date +%Y%m%d-%H%M)
umask 077
mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
cd "$KC_DIR"

# 1) 資料庫：在 postgres 容器內 pg_dump，寫到暫存檔，驗證能列出內容後才改名（避免留下壞檔）
DB="$BACKUP_DIR/keycloak-db-$TS.dump"
docker compose exec -T postgres pg_dump -U keycloak -d keycloak -Fc > "$DB.part"
docker compose exec -T postgres pg_restore --list < "$DB.part" > /dev/null
mv "$DB.part" "$DB"

# 2) 設定檔（不含資料庫目錄 data/）
CFG="$BACKUP_DIR/keycloak-config-$TS.tar.gz"
tar czf "$CFG" --ignore-failed-read \
  .env realm.env client-secret.txt docker-compose.yml docker-compose.bootstrap.yml truststores certs 2>/dev/null || true
[ -s "$CFG" ] || { echo "config backup failed" >&2; exit 1; }

# 3) 只保留最近 KEEP_DAYS 天
find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'keycloak-db-*.dump' -o -name 'keycloak-config-*.tar.gz' \) -mtime +"$KEEP_DAYS" -delete

echo "$(date '+%F %T') ok db=$(du -h "$DB" | cut -f1) config=$(du -h "$CFG" | cut -f1) kept=$(ls "$BACKUP_DIR"/keycloak-db-*.dump | wc -l)"
