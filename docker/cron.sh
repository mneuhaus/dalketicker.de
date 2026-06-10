#!/usr/bin/env bash
# Scheduler loop for the prod "scheduler" service (docker-compose.prod.yml).
# Imports run every 3 hours, the AI maintenance passes once per night, the
# weekly prune on Sunday mornings. State lives in marker files under /tmp, so
# a container restart re-runs at most one cycle. The console commands carry
# their own flock locks on the var/run volume shared with the app containers —
# overlapping with a manual (admin-triggered) run is therefore harmless. A
# failed command is logged and never kills the loop. All times are local
# Berlin time — the compose file sets TZ=Europe/Berlin (`date` honors it).
#
# Note: dedup-ai and rededup apply by default (they only have --dry-run, no
# --apply flag); categorize-ai/summarize-ai/prune-unseen need --apply.
set -u

MARKER_DIR=/tmp/dalketicker-scheduler
mkdir -p "$MARKER_DIR"

log() { echo "[scheduler] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

run() {
    log "Start: bin/console $*"
    if php bin/console "$@"; then
        log "OK:    bin/console $*"
    else
        log "FEHLER (Exit $?): bin/console $*"
    fi
}

ran_today() { [ -f "$MARKER_DIR/$1" ] && [ "$(cat "$MARKER_DIR/$1")" = "$(date +%F)" ]; }
mark_today() { date +%F > "$MARKER_DIR/$1"; }

# daily <marker> <hhmm> <console-command...> — once per day, at/after hh:mm.
daily() {
    local marker=$1 at=$2
    shift 2
    if [ "$(date +%H%M)" -ge "$at" ] && ! ran_today "$marker"; then
        mark_today "$marker"
        run "$@"
    fi
}

# import_due — marker absent or older than ~3h.
import_due() {
    local marker="$MARKER_DIR/import"
    [ ! -e "$marker" ] || [ -n "$(find "$marker" -mmin +170 2>/dev/null)" ]
}

log "Scheduler gestartet (Importe alle 3h, AI-Pflege naechtlich)"
while true; do
    if import_due; then
        touch "$MARKER_DIR/import"
        run dalketicker:import --all
    fi

    daily dedup-ai      315 dalketicker:dedup-ai
    daily categorize-ai 345 dalketicker:categorize-ai --apply
    daily summarize-ai  415 dalketicker:summarize-ai --apply
    daily rededup       445 dalketicker:rededup

    # Sundays: drop events their source no longer lists.
    if [ "$(date +%u)" = "7" ]; then
        daily prune-unseen 515 dalketicker:prune-unseen --apply
    fi

    sleep 60
done
