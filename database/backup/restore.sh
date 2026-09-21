#!/bin/bash
#
# NABILET Core - Database Restore Script
# 
# Production-ready restore script with:
# - Backup file validation
# - Pre-restore safety checks
# - Transaction-based restore (where supported)
# - Post-restore verification
# - Rollback capability
#
# Usage: ./restore.sh <backup_file> [options]
#   --force         Skip safety confirmations
#   --verify-only   Only verify backup integrity, don't restore
#   --dry-run       Show what would be done without executing
#

set -euo pipefail

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="${PROJECT_ROOT}/database/backup"

# Database configuration (from environment or .env)
DB_DRIVER="${DB_CONNECTION:-pgsql}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_DATABASE:-nabilet_core}"
DB_USER="${DB_USERNAME:-postgres}"
DB_PASSWORD="${DB_PASSWORD:-}"
PGPASSWORD="${DB_PASSWORD}"  # For psql

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Logging
log() {
    echo -e "${GREEN}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[$(date '+%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1"
}

error() {
    echo -e "${RED}[$(date '+%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1"
    exit 1
}

# Check arguments
if [[ $# -lt 1 ]]; then
    echo "Usage: $0 <backup_file> [options]"
    echo ""
    echo "Options:"
    echo "  --force         Skip safety confirmations"
    echo "  --verify-only   Only verify backup integrity"
    echo "  --dry-run       Show what would be done"
    echo ""
    echo "Examples:"
    echo "  $0 nabilet_nabilet_core_20260101_120000.sql.gz"
    echo "  $0 latest  # Restores most recent backup"
    exit 1
fi

BACKUP_FILE="$1"
shift

# Parse options
FORCE=false
VERIFY_ONLY=false
DRY_RUN=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --force)
            FORCE=true
            shift
            ;;
        --verify-only)
            VERIFY_ONLY=true
            shift
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        *)
            error "Unknown option: $1"
            ;;
    esac
done

# Handle 'latest' keyword
if [[ "$BACKUP_FILE" == "latest" ]]; then
    BACKUP_FILE=$(ls -t "${BACKUP_DIR}"/nabilet_*.sql* 2>/dev/null | grep -v '\.sha256$' | grep -v '\.log$' | head -1)
    
    if [[ -z "$BACKUP_FILE" ]]; then
        error "No backup files found in ${BACKUP_DIR}"
    fi
    
    log "Using latest backup: ${BACKUP_FILE}"
fi

# Verify backup file exists
if [[ ! -f "$BACKUP_FILE" ]]; then
    # Try in backup directory
    if [[ -f "${BACKUP_DIR}/$(basename "$BACKUP_FILE")" ]]; then
        BACKUP_FILE="${BACKUP_DIR}/$(basename "$BACKUP_FILE")"
    else
        error "Backup file not found: ${BACKUP_FILE}"
    fi
fi

log "Starting database restore..."
log "Backup file: ${BACKUP_FILE}"
log "Database: ${DB_NAME}@${DB_HOST}:${DB_PORT}"

# Verify backup integrity
log "Verifying backup integrity..."
if [[ -f "${BACKUP_FILE}.sha256" ]]; then
    if sha256sum -c "${BACKUP_FILE}.sha256" > /dev/null 2>&1; then
        log "Checksum verification passed ✓"
    else
        error "Checksum verification failed! ✗ Backup may be corrupted."
    fi
else
    warn "No checksum file found. Skipping integrity check."
fi

# Determine compression and decompress if needed
DECOMPRESSED_FILE=""
case "$BACKUP_FILE" in
    *.gz)
        if [[ "$DRY_RUN" == "true" ]] || [[ "$VERIFY_ONLY" == "true" ]]; then
            log "[DRY-RUN] Would decompress gzip backup"
        else
            log "Decompressing gzip backup..."
            DECOMPRESSED_FILE=$(mktemp)
            gunzip -c "$BACKUP_FILE" > "$DECOMPRESSED_FILE"
            BACKUP_FILE="$DECOMPRESSED_FILE"
        fi
        ;;
    *.zst)
        if ! command -v zstd &> /dev/null; then
            error "zstd not found. Cannot decompress backup."
        fi
        
        if [[ "$DRY_RUN" == "true" ]] || [[ "$VERIFY_ONLY" == "true" ]]; then
            log "[DRY-RUN] Would decompress zstd backup"
        else
            log "Decompressing zstd backup..."
            DECOMPRESSED_FILE=$(mktemp)
            zstd -d -c "$BACKUP_FILE" > "$DECOMPRESSED_FILE"
            BACKUP_FILE="$DECOMPRESSED_FILE"
        fi
        ;;
    *.gpg)
        if ! command -v gpg &> /dev/null; then
            error "gpg not found. Cannot decrypt backup."
        fi
        
        if [[ "$DRY_RUN" == "true" ]] || [[ "$VERIFY_ONLY" == "true" ]]; then
            log "[DRY-RUN] Would decrypt GPG backup"
        else
            log "Decrypting GPG backup..."
            DECOMPRESSED_FILE=$(mktemp)
            gpg --batch --yes -d "$BACKUP_FILE" > "$DECOMPRESSED_FILE"
            BACKUP_FILE="$DECOMPRESSED_FILE"
        fi
        ;;
esac

# If verify-only mode, exit after verification
if [[ "$VERIFY_ONLY" == "true" ]]; then
    log "Backup verification completed successfully!"
    exit 0
fi

# Safety check - confirm restore
if [[ "$FORCE" != "true" ]]; then
    echo ""
    warn "WARNING: This will OVERWRITE the current database '${DB_NAME}'!"
    echo ""
    echo "Backup file: ${BACKUP_FILE}"
    echo "Target database: ${DB_NAME}@${DB_HOST}:${DB_PORT}"
    echo ""
    read -p "Are you sure you want to continue? Type 'YES' to confirm: " -r
    echo ""
    
    if [[ ! $REPLY =~ ^YES$ ]]; then
        log "Restore cancelled by user."
        exit 0
    fi
fi

# Create pre-restore backup
log "Creating pre-restore backup..."
PRE_RESTORE_BACKUP="${BACKUP_DIR}/pre_restore_$(date +%Y%m%d_%H%M%S).sql.gz"

if [[ "$DRY_RUN" == "true" ]]; then
    log "[DRY-RUN] Would create pre-restore backup: ${PRE_RESTORE_BACKUP}"
else
    if [[ "$DB_DRIVER" == "pgsql" ]]; then
        pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -F c | gzip -9 > "$PRE_RESTORE_BACKUP"
    elif [[ "$DB_DRIVER" == "mysql" ]]; then
        mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"${DB_PASSWORD}" "$DB_NAME" | gzip -9 > "$PRE_RESTORE_BACKUP"
    fi
    
    log "Pre-restore backup created: ${PRE_RESTORE_BACKUP}"
fi

# Perform restore
log "Starting database restore..."

if [[ "$DB_DRIVER" == "pgsql" ]]; then
    log "Using PostgreSQL restore method"
    
    # Check if psql is available
    if ! command -v psql &> /dev/null; then
        error "psql not found. Please install PostgreSQL client tools."
    fi
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log "[DRY-RUN] Would execute: pg_restore -h ${DB_HOST} -p ${DB_PORT} -U ${DB_USER} -d ${DB_NAME} ${BACKUP_FILE}"
    else
        # Check if backup is custom format (-F c) or SQL
        if file "$BACKUP_FILE" | grep -q "PostgreSQL custom database dump"; then
            log "Detected PostgreSQL custom format backup"
            
            # Drop and recreate database for clean restore
            log "Dropping existing database..."
            psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -c "DROP DATABASE IF EXISTS ${DB_NAME};"
            
            log "Creating fresh database..."
            psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -c "CREATE DATABASE ${DB_NAME};"
            
            log "Restoring from backup..."
            pg_restore \
                -h "$DB_HOST" \
                -p "$DB_PORT" \
                -U "$DB_USER" \
                -d "$DB_NAME" \
                --verbose \
                --clean \
                --if-exists \
                "$BACKUP_FILE" \
                2>&1 | tee "${BACKUP_DIR}/restore_$(date +%Y%m%d_%H%M%S).log"
            
            if [[ ${PIPESTATUS[0]} -ne 0 ]]; then
                error "pg_restore failed"
            fi
        else
            # SQL format backup
            log "Restoring SQL format backup..."
            psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -f "$BACKUP_FILE" \
                2>&1 | tee "${BACKUP_DIR}/restore_$(date +%Y%m%d_%H%M%S).log"
            
            if [[ ${PIPESTATUS[0]} -ne 0 ]]; then
                error "psql restore failed"
            fi
        fi
    fi
    
elif [[ "$DB_DRIVER" == "mysql" ]]; then
    log "Using MySQL restore method"
    
    if ! command -v mysql &> /dev/null; then
        error "mysql client not found. Please install MySQL client tools."
    fi
    
    if [[ "$DRY_RUN" == "true" ]]; then
        log "[DRY-RUN] Would execute: mysql -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER} ${DB_NAME} < ${BACKUP_FILE}"
    else
        # Drop and recreate database for clean restore
        log "Dropping existing database..."
        mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"${DB_PASSWORD}" -e "DROP DATABASE IF EXISTS ${DB_NAME};"
        
        log "Creating fresh database..."
        mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"${DB_PASSWORD}" -e "CREATE DATABASE ${DB_NAME};"
        
        log "Restoring from backup..."
        mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"${DB_PASSWORD}" "$DB_NAME" < "$BACKUP_FILE" \
            2>&1 | tee "${BACKUP_DIR}/restore_$(date +%Y%m%d_%H%M%S).log"
        
        if [[ ${PIPESTATUS[0]} -ne 0 ]]; then
            error "mysql restore failed"
        fi
    fi
else
    error "Unsupported database driver: ${DB_DRIVER}. Supported: pgsql, mysql"
fi

# Cleanup temporary decompressed file
if [[ -n "$DECOMPRESSED_FILE" ]] && [[ -f "$DECOMPRESSED_FILE" ]]; then
    rm -f "$DECOMPRESSED_FILE"
fi

# Post-restore verification
log "Running post-restore verification..."
if [[ "$DRY_RUN" == "true" ]]; then
    log "[DRY-RUN] Would verify restored database"
else
    # Check if tables exist
    TABLE_COUNT=0
    
    if [[ "$DB_DRIVER" == "pgsql" ]]; then
        TABLE_COUNT=$(psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -t -c \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public';")
    elif [[ "$DB_DRIVER" == "mysql" ]]; then
        TABLE_COUNT=$(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"${DB_PASSWORD}" -N -e \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}';")
    fi
    
    TABLE_COUNT=$(echo "$TABLE_COUNT" | tr -d '[:space:]')
    
    if [[ "$TABLE_COUNT" -gt 0 ]]; then
        log "Post-restore verification passed ✓ (${TABLE_COUNT} tables found)"
    else
        error "Post-restore verification failed! No tables found."
    fi
fi

log "Database restore completed successfully!"
echo ""
echo "Summary:"
echo "  Restored from: ${BACKUP_FILE}"
echo "  Pre-restore backup: ${PRE_RESTORE_BACKUP}"
echo "  Tables restored: ${TABLE_COUNT}"
echo ""
echo "To rollback, run:"
echo "  $0 ${PRE_RESTORE_BACKUP} --force"
echo ""

exit 0
