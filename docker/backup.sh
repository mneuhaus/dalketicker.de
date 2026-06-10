#!/bin/sh
# Daily pg_dump sidecar for the prod "db-backup" service (docker-compose.prod.yml).
# Sleeps until the next 03:00, dumps the database in custom format (-Fc) into
# /backups (named volume dalketicker-db-backups) and prunes dumps older than
# 14 days. Connection settings come from the compose environment (PGHOST,
# PGUSER, PGDATABASE, PGPASSWORD). Restore: pg_restore -d dalketicker <file>.
# POSIX sh — runs inside postgres:16-alpine (busybox). "03:00" is Berlin
# local time — the compose file sets TZ=Europe/Berlin (`date` honors it).
set -eu

BACKUP_DIR=${BACKUP_DIR:-/backups}
RETENTION_DAYS=${RETENTION_DAYS:-14}

log() { echo "[db-backup] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

seconds_until_3am() {
    h=$(date +%H); m=$(date +%M); s=$(date +%S)
    # Strip leading zeros so busybox arithmetic doesn't read them as octal.
    h=${h#0}; m=${m#0}; s=${s#0}
    wait=$(( 3 * 3600 - (h * 3600 + m * 60 + s) ))
    [ "$wait" -gt 0 ] || wait=$(( wait + 86400 ))
    echo "$wait"
}

dump() {
    file="$BACKUP_DIR/dalketicker-$(date +%Y-%m-%d_%H%M%S).dump"
    log "Dump nach $file"
    if pg_dump -Fc -f "$file"; then
        log "Fertig: $(du -h "$file" | cut -f1)"
    else
        log "FEHLER: pg_dump fehlgeschlagen"
        rm -f "$file"
    fi
    find "$BACKUP_DIR" -name 'dalketicker-*.dump' -mtime +"$RETENTION_DAYS" -delete
}

# First start ever (empty volume): take an initial dump right away.
if [ -z "$(find "$BACKUP_DIR" -name 'dalketicker-*.dump' 2>/dev/null)" ]; then
    log "Kein Backup vorhanden — erstelle initialen Dump"
    dump
fi

while true; do
    wait=$(seconds_until_3am)
    log "Naechster Dump in ${wait}s"
    sleep "$wait"
    dump
done
