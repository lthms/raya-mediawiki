#!/bin/sh
set -eu
umask 077
# mediawiki-backup-initialize provisions/verifies the repository at deployment.
# Scheduled backups require that repository and never attempt to reinitialize it.
: "${RESTIC_REPOSITORY:?}"
: "${RESTIC_PASSWORD:?}"
: "${AWS_ACCESS_KEY_ID:?}"
: "${AWS_SECRET_ACCESS_KEY:?}"
: "${MW_BACKUP_HOST:?}"
: "${MW_BACKUP_KEEP_DAILY:?}"
export RESTIC_CACHE_DIR=/tmp/restic-cache
mkdir -p /data/control /tmp/backup-metadata
mkdir /data/control/backup-lock
if [ -e /data/control/read-only ]; then
    rmdir /data/control/backup-lock
    echo 'Wiki is already in maintenance mode; refusing backup.' >&2
    exit 1
fi
cleanup() {
    rm -f /data/control/read-only
    rmdir /data/control/backup-lock
}
trap cleanup EXIT
trap 'exit 1' INT TERM
printf '%s\n' 'Sauvegarde quotidienne en cours.' > /data/control/read-only
# Every web request/job holds a shared lock through LocalSettings.php.
# Wait for existing work, then keep all new requests out until the snapshot ends.
exec 9>/data/control/operations.lock
flock -x -w 300 9
php /opt/mediawiki/initialize.php --backup-point > /tmp/backup-metadata/postgres-point.json
restic snapshots --host "$MW_BACKUP_HOST" --tag uploads > /dev/null
restic backup /data/uploads /tmp/backup-metadata --host "$MW_BACKUP_HOST" --tag uploads
# Resume editing before repository maintenance. A failed backup never prunes.
flock -u 9
cleanup
trap - EXIT INT TERM
restic forget --host "$MW_BACKUP_HOST" --tag uploads --group-by host,paths --keep-daily "$MW_BACKUP_KEEP_DAILY" --prune
restic check
