#!/bin/bash

# Test script for Pack 440 backup system
# This script helps verify the backup functionality without waiting for cron

echo "=== Pack 440 Backup Test Script ==="
echo

# Check if backup script exists and is executable
if [[ ! -f "backup_pack440.sh" ]]; then
    echo "❌ backup_pack440.sh not found in current directory"
    exit 1
fi

if [[ ! -x "backup_pack440.sh" ]]; then
    echo "❌ backup_pack440.sh is not executable"
    echo "Run: chmod +x backup_pack440.sh"
    exit 1
fi

# Check if config file exists
if [[ ! -f "backup_config.conf" ]]; then
    echo "❌ backup_config.conf not found"
    echo "Copy backup_config.conf.example to backup_config.conf and configure it"
    exit 1
fi

echo "✅ Backup script found and executable"
echo "✅ Configuration file found"
echo

# Check required commands
echo "Checking required commands..."
if ! command -v mysqldump >/dev/null 2>&1; then
    echo "❌ mysqldump not found - install MySQL client tools"
    exit 1
fi

if ! command -v gzip >/dev/null 2>&1; then
    echo "❌ gzip not found - install gzip"
    exit 1
fi

echo "✅ mysqldump available"
echo "✅ gzip available"
echo

# Show current backup directory status
source backup_config.conf
echo "Backup directory: $BACKUP_DIR"

if [[ -d "$BACKUP_DIR" ]]; then
    echo "✅ Backup directory exists"
    echo "Current backups:"
    ls -la "$BACKUP_DIR"/pack440_*.sql.gz 2>/dev/null || echo "  (no backups found)"
else
    echo "ℹ️  Backup directory will be created on first run"
fi
echo

# Run the backup
echo "Running backup test..."
echo "----------------------------------------"
if ./backup_pack440.sh; then
    echo "----------------------------------------"
    echo "✅ Backup completed successfully!"
    echo
    echo "Backup files:"
    ls -la "$BACKUP_DIR"/pack440_*.sql.gz 2>/dev/null
    echo
    echo "Latest backup size:"
    du -sh "$BACKUP_DIR"/pack440_*.sql.gz 2>/dev/null | tail -1
else
    echo "----------------------------------------"
    echo "❌ Backup failed!"
    echo "Check the error messages above"
    exit 1
fi

echo
echo "=== Test completed successfully! ==="
echo
echo "Next steps:"
echo "1. Add to crontab: crontab -e"
echo "2. Add line: 0 2 * * * $(pwd)/backup_pack440.sh >/dev/null"
echo "3. Monitor: ls -la $BACKUP_DIR"
