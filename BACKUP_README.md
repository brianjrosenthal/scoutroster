# Pack 440 MySQL Backup Script

This backup script creates daily MySQL backups with intelligent retention policies for the Pack 440 Cub Scouts application.

## Features

- **Daily automated backups** with mysqldump
- **Intelligent retention policy**:
  - Keep all backups from last 7 days
  - Keep monthly archives (backups created on 1st of month)
  - Delete anything over 6 months old
  - Delete everything else
- **Compressed backups** (gzip) to save disk space
- **Secure file permissions** (600) on backup files
- **Comprehensive logging** with optional log file
- **Cron-friendly** (silent on success, errors to stderr)
- **Atomic operations** (temp files, then rename)

## Setup Instructions

### 1. Configure Database Credentials

Copy the example configuration file and edit it with your database details:

```bash
cp backup_config.conf.example backup_config.conf
```

Edit `backup_config.conf` with your database information:

```bash
# Database connection details
DB_HOST="localhost"
DB_NAME="cub_scouts_app"
DB_USER="your_db_username"
DB_PASS="your_db_password"

# Optional: MySQL port (uncomment if not using default 3306)
# DB_PORT="3306"

# Backup directory (will be created if it doesn't exist)
BACKUP_DIR="$HOME/backups/pack440"

# Optional: Enable logging (uncomment to enable)
# LOG_FILE="$HOME/backups/pack440/backup.log"
```

### 2. Secure the Configuration File

Set secure permissions on the configuration file since it contains database credentials:

```bash
chmod 600 backup_config.conf
```

### 3. Test the Backup Script

Run the script manually to test it:

```bash
./backup_pack440.sh
```

Check that the backup was created:

```bash
ls -la ~/backups/pack440/
```

You should see a file like: `pack440_2025-09-15_141530.sql.gz`

### 4. Set Up Cron Job

Add a cron job to run the backup daily. Edit your crontab:

```bash
crontab -e
```

Add this line to run the backup daily at 2:00 AM:

```bash
# Pack 440 MySQL Backup - Daily at 2:00 AM
0 2 * * * /path/to/your/backup_pack440.sh >/dev/null
```

**Important**: Replace `/path/to/your/backup_pack440.sh` with the actual full path to the script.

To find the full path, run:
```bash
pwd
```
from the directory containing the script.

## Usage

### Manual Backup
```bash
./backup_pack440.sh
```

### Check Backup Status
```bash
ls -la ~/backups/pack440/
```

### View Logs (if enabled)
```bash
tail -f ~/backups/pack440/backup.log
```

### Restore from Backup
To restore a backup:

```bash
# Decompress the backup
gunzip pack440_2025-09-15_141530.sql.gz

# Restore to MySQL
mysql -h localhost -u your_username -p < pack440_2025-09-15_141530.sql
```

## File Naming Convention

Backup files are named: `pack440_YYYY-MM-DD_HHMMSS.sql.gz`

Examples:
- `pack440_2025-09-15_020000.sql.gz` (Daily backup)
- `pack440_2025-10-01_020000.sql.gz` (Monthly archive - kept longer)

## Retention Policy Details

The script implements a three-tier retention policy:

1. **Last 7 Days**: All backups from the last 7 days are kept
2. **Monthly Archives**: Backups created on the 1st of any month are kept as monthly archives
3. **6 Month Limit**: Anything older than 6 months is deleted regardless of other rules

### Examples:
- **September 15, 2025**: Keep (within 7 days)
- **September 1, 2025**: Keep (monthly archive)
- **August 15, 2025**: Delete (older than 7 days, not monthly archive)
- **March 1, 2025**: Delete (older than 6 months, even though it's monthly archive)

## Troubleshooting

### Common Issues

**"mysqldump command not found"**
```bash
# Install MySQL client tools
# Ubuntu/Debian:
sudo apt-get install mysql-client

# CentOS/RHEL:
sudo yum install mysql

# macOS:
brew install mysql-client
```

**"Configuration file not found"**
- Make sure you copied `backup_config.conf.example` to `backup_config.conf`
- Ensure the config file is in the same directory as the script

**"Permission denied"**
```bash
# Make sure the script is executable
chmod +x backup_pack440.sh

# Make sure you have write permissions to the backup directory
ls -la ~/backups/
```

**"Database connection failed"**
- Verify database credentials in `backup_config.conf`
- Test connection manually:
```bash
mysql -h localhost -u your_username -p your_database_name
```

### Monitoring Backups

To monitor backup success/failure, you can:

1. **Enable logging** in `backup_config.conf`:
   ```bash
   LOG_FILE="$HOME/backups/pack440/backup.log"
   ```

2. **Check cron logs**:
   ```bash
   # View cron logs
   tail -f /var/log/cron
   
   # Or check mail for cron errors
   mail
   ```

3. **Create a monitoring script**:
   ```bash
   #!/bin/bash
   # Check if backup was created today
   TODAY=$(date '+%Y-%m-%d')
   if ls ~/backups/pack440/pack440_${TODAY}_*.sql.gz 1> /dev/null 2>&1; then
       echo "✓ Backup exists for today"
   else
       echo "✗ No backup found for today"
   fi
   ```

## Security Notes

- Configuration file contains database credentials - keep it secure (600 permissions)
- Backup files are created with 600 permissions (owner read/write only)
- Backup directory is created with 700 permissions (owner access only)
- Database password is not visible in process list during backup

## Disk Space Considerations

- Compressed backups typically use 10-20% of the original database size
- With the retention policy, you'll typically have:
  - 7 daily backups (last week)
  - ~12 monthly archives (depending on age)
  - Total: ~19 backup files at any given time

Monitor disk usage periodically:
```bash
du -sh ~/backups/pack440/
