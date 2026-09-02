#!/usr/bin/env bash
# Scheduler loop for the prod "scheduler" service (docker-compose.prod.yml).
# Imports run every 3 hours, the AI maintenance passes once per night, the
# weekly prune on Sunday mornings. State lives in marker files on the shared
# var/run volume, so deploys/restarts do not re-run the same daily maintenance
# pass later in the day. The console commands carry their own flock locks on
# that same volume — overlapping with a manual (admin-triggered) run is harmless. A
# failed command is logged and never kills the loop. All times are local
# Berlin time — the compose file sets TZ=Europe/Berlin (`date` honors it).
#
# Note: dedup-ai and rededup apply by default (they only have --dry-run, no
# --apply flag); categorize-ai/summarize-ai/prune-unseen need --apply.
set -u

MARKER_DIR=${SCHEDULER_MARKER_DIR:-/app/var/run/scheduler}
mkdir -p "$MARKER_DIR"

log() { echo "[scheduler] $(date '+%Y-%m-%d %H:%M:%S') $*"; }

# Graceful stop. This loop is PID 1, so `docker stop` (every deploy recreates
# the scheduler) sends TERM here — without a trap bash ignores it, Docker
# kills everything after 10 s, and a half-done import leaves "running" run
# records behind while a nightly AI pass is skipped for the day (its marker
# is already set). So: let the running console command finish, then exit.
# The compose file grants a matching stop_grace_period. Commands are started
# in the background and waited for, because a trap never fires while bash
# sits on a foreground command.
child=
on_term() {
    if [ -n "$child" ]; then
        log "Stop angefordert - laufender Befehl wird noch zu Ende gebracht"
        wait "$child"
    fi
    log "Scheduler beendet"
    exit 0
}
trap on_term TERM INT

run() {
    log "Start: bin/console $*"
    php bin/console "$@" &
    child=$!
    if wait "$child"; then
        log "OK:    bin/console $*"
    else
        log "FEHLER (Exit $?): bin/console $*"
    fi
    child=
}

ran_today() { [ -f "$MARKER_DIR/$1" ] && [ "$(cat "$MARKER_DIR/$1")" = "$(date +%F)" ]; }
mark_today() { date +%F > "$MARKER_DIR/$1"; }

# daily <marker> <hhmm> <console-command...> — once per day, within two hours
# after the planned time. This prevents evening deploys from running nightly
# maintenance immediately after the scheduler restarts.
daily() {
    local marker=$1 at
    # Normalize to 4 digits ("315" -> "0315") — the 2+2 slicing below would
    # otherwise read "315" as hour 31 and the job would never trigger.
    at=$(printf '%04d' "$((10#$2))")
    shift 2
    local now_hm now_minutes at_minutes late_minutes
    now_hm=$(date +%H%M)
    now_minutes=$((10#${now_hm:0:2} * 60 + 10#${now_hm:2:2}))
    at_minutes=$((10#${at:0:2} * 60 + 10#${at:2:2}))
    late_minutes=$((now_minutes - at_minutes))

    if [ "$late_minutes" -ge 0 ] && [ "$late_minutes" -lt 120 ] && ! ran_today "$marker"; then
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
        run dalketicker:import --all-regions --workers=4
    fi

    daily dedup-ai      0315 dalketicker:dedup-ai
    daily categorize-ai 0345 dalketicker:categorize-ai --apply
    daily summarize-ai  0415 dalketicker:summarize-ai --apply
    daily rededup       0445 dalketicker:rededup

    # Sundays: drop events their source no longer lists.
    if [ "$(date +%u)" = "7" ]; then
        daily prune-unseen 0515 dalketicker:prune-unseen --apply
    fi

    sleep 60
done
