#!/bin/bash

# MySQL Backup Script for Pack 440 Cub Scouts
# Creates daily backups with intelligent retention policy
# 
# Retention Rules:
# - Keep all backups from last 7 days
# - Keep backups created on 1st of month (monthly archives)
# - Delete anything over 6 months old
# - Delete everything else

set -euo pipefail  # Exit on error, undefined vars, pipe failures

# Script directory (where this script is located)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="$SCRIPT_DIR/backup_config.conf"

# Default values
DB_HOST="localhost"
DB_NAME=""
DB_USER=""
DB_PASS=""
DB_PORT="3306"
BACKUP_DIR="$HOME/backups/pack440"
LOG_FILE=""

# Function to log messages
log_message() {
    local message="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    if [[ -n "$LOG_FILE" ]]; then
        echo "[$timestamp] $message" >> "$LOG_FILE"
    fi
    
    # Always output to stderr for cron error reporting
    echo "[$timestamp] $message" >&2
}

# Function to log info messages (only to log file, not stderr)
log_info() {
    local message="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    
    if [[ -n "$LOG_FILE" ]]; then
        echo "[$timestamp] INFO: $message" >> "$LOG_FILE"
    fi
}

# Load configuration
load_config() {
    if [[ ! -f "$CONFIG_FILE" ]]; then
        log_message "ERROR: Configuration file not found: $CONFIG_FILE"
        log_message "Please copy backup_config.conf.example to backup_config.conf and configure it"
        exit 1
    fi
    
    # Source the config file
    source "$CONFIG_FILE"
    
    # Validate required settings
    if [[ -z "$DB_NAME" || -z "$DB_USER" || -z "$DB_PASS" ]]; then
        log_message "ERROR: Missing required database configuration (DB_NAME, DB_USER, DB_PASS)"
        exit 1
    fi
    
    log_info "Configuration loaded successfully"
}

# Create backup directory if it doesn't exist
setup_backup_dir() {
    if [[ ! -d "$BACKUP_DIR" ]]; then
        mkdir -p "$BACKUP_DIR"
        chmod 700 "$BACKUP_DIR"  # Secure permissions
        log_info "Created backup directory: $BACKUP_DIR"
    fi
}

# Create MySQL backup
create_backup() {
    local timestamp=$(date '+%Y-%m-%d_%H%M%S')
    local backup_file="$BACKUP_DIR/pack440_${timestamp}.sql"
    local compressed_file="${backup_file}.gz"
    local temp_file="${backup_file}.tmp"
    
    log_info "Starting backup: $compressed_file"
    
    # Create mysqldump with optimal settings
    local mysqldump_cmd="mysqldump"
    mysqldump_cmd+=" --host=$DB_HOST"
    mysqldump_cmd+=" --port=$DB_PORT"
    mysqldump_cmd+=" --user=$DB_USER"
    mysqldump_cmd+=" --password=$DB_PASS"
    mysqldump_cmd+=" --databases $DB_NAME"     # Specify database
    
    # Execute backup to temporary file, then compress
    if eval "$mysqldump_cmd" > "$temp_file" 2>/dev/null; then
        # Compress the backup
        if gzip < "$temp_file" > "$compressed_file"; then
            # Set secure permissions
            chmod 600 "$compressed_file"
            
            # Remove temporary file
            rm -f "$temp_file"
            
            # Verify the compressed file exists and has content
            if [[ -s "$compressed_file" ]]; then
                local file_size=$(du -h "$compressed_file" | cut -f1)
                log_info "Backup completed successfully: $compressed_file ($file_size)"
                return 0
            else
                log_message "ERROR: Backup file is empty: $compressed_file"
                rm -f "$compressed_file"
                return 1
            fi
        else
            log_message "ERROR: Failed to compress backup file"
            rm -f "$temp_file"
            return 1
        fi
    else
        log_message "ERROR: mysqldump failed"
        rm -f "$temp_file"
        return 1
    fi
}

# Clean up old backups according to retention policy
cleanup_old_backups() {
    log_info "Starting backup cleanup"
    
    local current_date=$(date '+%s')
    local seven_days_ago=$((current_date - 7 * 24 * 3600))
    local six_months_ago=$((current_date - 180 * 24 * 3600))
    local deleted_count=0
    local kept_count=0
    
    # Process all backup files
    for backup_file in "$BACKUP_DIR"/pack440_*.sql.gz; do
        # Skip if no files match the pattern
        [[ -f "$backup_file" ]] || continue
        
        # Extract date from filename (pack440_YYYY-MM-DD_HHMMSS.sql.gz)
        local filename=$(basename "$backup_file")
        local date_part=$(echo "$filename" | sed 's/pack440_\([0-9-]*\)_[0-9]*.sql.gz/\1/')
        
        # Skip if we can't parse the date
        if [[ ! "$date_part" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
            log_info "Skipping file with unparseable date: $filename"
            continue
        fi
        
        # Convert date to timestamp
        local file_timestamp=$(date -d "$date_part" '+%s' 2>/dev/null || echo "0")
        
        if [[ "$file_timestamp" -eq 0 ]]; then
            log_info "Skipping file with invalid date: $filename"
            continue
        fi
        
        # Extract day of month
        local day_of_month=$(echo "$date_part" | cut -d'-' -f3)
        
        local should_delete=false
        local reason=""
        
        # Rule 1: Delete anything over 6 months old (highest priority)
        if [[ "$file_timestamp" -lt "$six_months_ago" ]]; then
            should_delete=true
            reason="older than 6 months"
        
        # Rule 2: Keep files from last 7 days
        elif [[ "$file_timestamp" -gt "$seven_days_ago" ]]; then
            should_delete=false
            reason="within last 7 days"
        
        # Rule 3: Keep files created on 1st of month (monthly archives)
        elif [[ "$day_of_month" == "01" ]]; then
            should_delete=false
            reason="monthly archive (1st of month)"
        
        # Rule 4: Delete everything else
        else
            should_delete=true
            reason="older than 7 days and not monthly archive"
        fi
        
        if [[ "$should_delete" == true ]]; then
            rm -f "$backup_file"
            log_info "Deleted: $filename ($reason)"
            ((deleted_count++))
        else
            log_info "Kept: $filename ($reason)"
            ((kept_count++))
        fi
    done
    
    log_info "Cleanup completed: kept $kept_count files, deleted $deleted_count files"
}

# Main execution
main() {
    log_info "=== Pack 440 Backup Script Started ==="
    
    # Load configuration
    load_config
    
    # Setup backup directory
    setup_backup_dir
    
    # Create backup
    if create_backup; then
        log_info "Backup creation successful"
        
        # Clean up old backups
        cleanup_old_backups
        
        log_info "=== Pack 440 Backup Script Completed Successfully ==="
        exit 0
    else
        log_message "ERROR: Backup creation failed"
        log_info "=== Pack 440 Backup Script Failed ==="
        exit 1
    fi
}

# Check if mysqldump is available
if ! command -v mysqldump >/dev/null 2>&1; then
    log_message "ERROR: mysqldump command not found. Please install MySQL client tools."
    exit 1
fi

# Check if gzip is available
if ! command -v gzip >/dev/null 2>&1; then
    log_message "ERROR: gzip command not found. Please install gzip."
    exit 1
fi

# Run main function
main "$@"
