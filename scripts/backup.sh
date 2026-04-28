#!/bin/bash
# ============================================
# UltrAI Auto Backup Script
# Jalankan via cron: 0 2 * * * /home/superpro/ultrai-web/scripts/backup.sh
# Backup setiap hari jam 2 pagi
# ============================================

set -e

# Config
BACKUP_DIR="/home/superpro/backups"
PROJECT_DIR="/home/superpro/ultrai-web"
DB_NAME="ultrai_db"
DB_USER="ultrai"
DATE=$(date +%Y%m%d_%H%M%S)
KEEP_DAYS=7

# Buat folder backup
mkdir -p "$BACKUP_DIR"

echo "[$(date)] Starting backup..."

# 1. Backup database PostgreSQL
echo "[$(date)] Backing up database..."
PGPASSWORD="UltrAI@2026!Secure" pg_dump -U "$DB_USER" -h 127.0.0.1 "$DB_NAME" | gzip > "$BACKUP_DIR/db_${DATE}.sql.gz"

# 2. Backup .env (contains secrets)
echo "[$(date)] Backing up .env..."
cp "$PROJECT_DIR/.env" "$BACKUP_DIR/env_${DATE}.bak"

# 3. Backup uploaded files (if any)
if [ -d "$PROJECT_DIR/storage/app/public" ]; then
    echo "[$(date)] Backing up uploads..."
    tar -czf "$BACKUP_DIR/uploads_${DATE}.tar.gz" -C "$PROJECT_DIR/storage/app" public/ 2>/dev/null || true
fi

# 4. Hapus backup lama (lebih dari KEEP_DAYS hari)
echo "[$(date)] Cleaning old backups (older than ${KEEP_DAYS} days)..."
find "$BACKUP_DIR" -type f -mtime +$KEEP_DAYS -delete 2>/dev/null || true

# 5. Tampilkan ukuran backup
echo "[$(date)] Backup complete!"
echo "Files:"
ls -lh "$BACKUP_DIR"/*_${DATE}* 2>/dev/null
echo ""
echo "Total backup size:"
du -sh "$BACKUP_DIR"
