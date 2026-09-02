#!/bin/sh
# Daily pg_dump sidecar for the prod "db-backup" service (docker-compose.prod.yml).
# Sleeps until the next 03:00, dumps the database in custom format (-Fc) into
# /backups (named volume dalketicker-db-backups) and prunes dumps older than
# 14 days — but only after a verified fresh dump, so persistent failures can
# never age out the last good backup. Each success touches /backups/.last-success;
# the compose healthcheck flags the service unhealthy when that marker goes
# stale. Connection settings come from the compose environment (PGHOST,
# PGUSER, PGDATABASE, PGPASSWORD). Restore: pg_restore -d dalketicker <file>.
# POSIX sh — runs inside postgres:16-alpine (busybox). "03:00" is Berlin
# local time — the compose file sets TZ=Europe/Berlin (`date` honors it).
set -eu

BACKUP_DIR=${BACKUP_DIR:-/backups}
UPLOADS_DIR=${UPLOADS_DIR:-/uploads}
RETENTION_DAYS=${RETENTION_DAYS:-14}

log() { echo "[db-backup] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

# A dump interrupted by a container stop leaves its ".part" behind forever
# (only the failure branch below cleans up) — sweep on start.
rm -f "$BACKUP_DIR"/*.part

seconds_until_3am() {
    h=$(date +%H); m=$(date +%M); s=$(date +%S)
    # Strip leading zeros so busybox arithmetic doesn't read them as octal.
    h=${h#0}; m=${m#0}; s=${s#0}
    wait=$(( 3 * 3600 - (h * 3600 + m * 60 + s) ))
    [ "$wait" -gt 0 ] || wait=$(( wait + 86400 ))
    echo "$wait"
}

dump() {
    stamp=$(date +%Y-%m-%d_%H%M%S)
    file="$BACKUP_DIR/dalketicker-$stamp.dump"
    tmp="$file.part"
    log "Dump nach $file"
    # Dump to a temp name and only promote a verified result (pg_dump exit 0
    # AND non-trivial size); the ".part" suffix keeps it out of the prune glob.
    if pg_dump -Fc -f "$tmp" && [ "$(wc -c < "$tmp")" -ge 1024 ]; then
        mv "$tmp" "$file"
        touch "$BACKUP_DIR/.last-success"
        log "Fertig: $(du -h "$file" | cut -f1)"
        dump_uploads "$stamp"
        # Prune ONLY after a verified fresh dump — otherwise consecutive
        # failures would eventually delete the last remaining good backup.
        find "$BACKUP_DIR" \( -name 'dalketicker-*.dump' -o -name 'dalketicker-uploads-*.tar.gz' \) -mtime +"$RETENTION_DAYS" -delete
    else
        log "FEHLER: pg_dump fehlgeschlagen oder Dump unplausibel klein — Retention uebersprungen"
        rm -f "$tmp"
    fi
}

# The approval proofs (var/uploads/freigaben, mounted read-only at
# $UPLOADS_DIR) are the one thing besides the database that cannot be
# re-imported — they go next to every dump. The image cache in the same
# volume is derived data and deliberately left out.
dump_uploads() {
    [ -d "$UPLOADS_DIR/freigaben" ] || return 0
    tarball="$BACKUP_DIR/dalketicker-uploads-$1.tar.gz"
    if tar -C "$UPLOADS_DIR" -czf "$tarball.part" freigaben; then
        mv "$tarball.part" "$tarball"
        log "Freigabe-Nachweise gesichert: $(du -h "$tarball" | cut -f1)"
    else
        log "FEHLER: Freigabe-Nachweise konnten nicht gepackt werden"
        rm -f "$tarball.part"
    fi
}

# Upgrade path: seed the freshness marker from the newest existing dump so a
# recreated container is not "unhealthy" until the next nightly run.
if [ ! -f "$BACKUP_DIR/.last-success" ]; then
    newest=$(ls -t "$BACKUP_DIR"/dalketicker-*.dump 2>/dev/null | head -n 1)
    if [ -n "$newest" ]; then
        touch -r "$newest" "$BACKUP_DIR/.last-success"
    fi
fi

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
