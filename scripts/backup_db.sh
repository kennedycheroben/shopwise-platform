#!/usr/bin/env bash
# ==============================================================================
# ShopWise Platform - Database Backup Helper Script
# Dumps shopwise_db to ~/backups/shopwise_db_<timestamp>.sql using mysqldump.
# Never overwrites an existing backup file.
# Prints the backup file path and file size.
# ==============================================================================

set -euo pipefail

BACKUP_DIR="${HOME}/backups"
mkdir -p "$BACKUP_DIR"

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
DB_NAME="${1:-shopwise_db}"
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.sql"

if [ -f "$BACKUP_FILE" ]; then
    echo "ERROR: Backup file '$BACKUP_FILE' already exists. Never overwriting." >&2
    exit 1
fi

MYSQLDUMP_CMD="/opt/lampp/bin/mysqldump"
if [ ! -x "$MYSQLDUMP_CMD" ]; then
    MYSQLDUMP_CMD="mysqldump"
fi

DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

echo "Creating database backup of '$DB_NAME'..."

if [ -n "$DB_PASS" ]; then
    "$MYSQLDUMP_CMD" -u "$DB_USER" -p"$DB_PASS" \
        --single-transaction --skip-routines \
        "$DB_NAME" > "$BACKUP_FILE"
else
    "$MYSQLDUMP_CMD" -u "$DB_USER" \
        --single-transaction --skip-routines \
        "$DB_NAME" > "$BACKUP_FILE"
fi

if [ ! -s "$BACKUP_FILE" ]; then
    echo "ERROR: Backup file was created but is empty: $BACKUP_FILE" >&2
    exit 1
fi

FILE_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)

echo "=============================================================================="
echo "  DATABASE BACKUP COMPLETED SUCCESSFULLY"
echo "=============================================================================="
echo "  Database: $DB_NAME"
echo "  Path:     $BACKUP_FILE"
echo "  Size:     $FILE_SIZE"
echo "=============================================================================="
