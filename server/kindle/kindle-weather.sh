#!/bin/sh
# Name: Værvarsel
# Author: ClearanceClarence
# DontUseFBInk

# Kindle weather display - scriptlet for a jailbroken Kindle Paperwhite 4.
#
# Copy this file to the Kindle's "documents" folder and tap "Værvarsel" in
# the library. It downloads a ready-made image from your server, shows it
# full screen, and refreshes it on a schedule. Tapping the screen switches
# design, refreshes, or closes the display.
#
# IMPORTANT: this file must keep Unix (LF) line endings. Edit it with VS Code
# or Notepad++, not Windows Notepad.
#
# How it is organized:
#   1. Settings               what you are meant to change (or override in
#                             kindle-weather/settings.sh on the Kindle)
#   2. Helpers                log, battery, Wi-Fi, download, draw
#   3. Screens                refresh, night screen, front light
#   4. Touch                  finding the touchscreen and reading taps
#   5. Start and stop         launcher, cleanup
#   6. Main loop

# ==============================================================================
# 1. Settings
# ==============================================================================

# Address of the image server (the folder holding index.php).
SERVER_URL="https://example.com/weather/"

# Note: the refresh interval, quiet hours, refresh style, light and tabs
# below can also be set on the server's admin page (admin.php). Once set
# there, the server's values are used and these are only the fallback.

# Minutes between refreshes.
INTERVAL_MIN=10

# 1 = stop the Kindle's own interface while the display runs, so nothing draws
#     over the weather image. Close it with the X in the top right corner.
#     If that ever fails, hold the power button until the Kindle restarts
#     (about 40 seconds).
# 0 = leave the interface running (handy while testing; tap the scriptlet
#     again to stop). The Kindle's clock and menus can draw over the image.
STOP_GUI=1

# 1 = touch controls: an X in the top right corner closes the display, the
#     tabs along the bottom switch between designs, and a tap on the upper
#     half of the screen refreshes right away.
TOUCH_CONTROLS=1

# The tabs, left to right. Normally set on the server's admin page; these
# are the values used until the Kindle has reached the server once.
TABS="now big graph week hours"

# The language of the few lines of text this script prints itself: no or en.
# The pictures follow the language chosen on the admin page.
LANGUAGE=no

# The flight screen ("Fly over oss"), opened with the plane button at the
# right end of the tab bar. FLIGHTS is normally set on the admin page.
FLIGHTS=1                  # 1 = show the plane button
FLIGHT_INTERVAL_SEC=60     # seconds between refreshes while the flight screen is open
FLIGHT_TIMEOUT_MIN=15      # go back to the weather by itself after this long

# Design shown on first start. After that the Kindle remembers the tab you
# picked last.
DEFAULT_VIEW=now

# Quiet hours: between these hours (24-hour clock) the display shows a night
# screen with the coming day's weather and stops refreshing. Set both to the
# same number to turn quiet hours off.
QUIET_START=0
QUIET_END=6

# A full refresh flashes the screen black and white, which clears ghosting.
# Other refreshes update quietly. 1 = flash every time.
FULL_REFRESH_EVERY=6

# Front light: 1 = let this script set it. By default it is kept off at all
# times, which saves the most battery. For light in the evening, set
# LIGHT_LEVEL (1-24); it is then on between LIGHT_FROM and LIGHT_TO o'clock
# and off the rest of the day and during quiet hours. Your own setting comes
# back when the display closes.
LIGHT_CONTROL=1
LIGHT_FROM=16
LIGHT_TO=24
LIGHT_LEVEL=0

# ---- End of settings ----------------------------------------------------------

# Where things live on the Kindle.
DIR=/mnt/us/kindle-weather              # images, the log and your settings (visible over USB)

# Your own settings. If the file "kindle-weather/settings.sh" exists on the
# Kindle, the values in it replace the ones above, so you can update this
# script without redoing your settings. See settings.example.sh.
[ -f "$DIR/settings.sh" ] && . "$DIR/settings.sh"

LOG="$DIR/log.txt"
RUN=/var/tmp/kindle-weather.sh          # the running copy of this script
PIDFILE=/var/tmp/kindle-weather.pid

# Tap areas, in screen pixels (1072 x 1448). They mirror the layout the
# server draws; see TAB_TOP and draw_header() in the server's src/layout.php.
CLOSE_MIN_X=860                     # close button: right of this ...
CLOSE_MAX_Y=220                     # ... and above this
TABS_MIN_Y=1310                     # tab bar: below this
TABS_LEFT=64                        # the tab bar starts here ...
TABS_WIDTH=944                      # ... and is this wide
SWIPE_MIN=150                       # a finger must move this far up or down to count as a swipe
FLIGHT_BTN_WIDTH=130                # the plane button: this much of the tab bar's right end
REFRESH_MAX_Y=760                   # tap-to-refresh: between the header and this

# ==============================================================================
# 2. Helpers
# ==============================================================================

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG"
    # Keep the log small.
    if [ "$(wc -l < "$LOG")" -gt 300 ]; then
        tail -n 150 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
    fi
}

# Prints the current hour without a leading zero (0-23).
hour_now() {
    h=$(date +%H)
    echo "${h#0}"
}

# Prints the battery level (0-100), or nothing if it cannot be read.
battery() {
    b=$(lipc-get-prop com.lab126.powerd battLevel 2>/dev/null)
    case "$b" in
        ''|*[!0-9]*) b=$(gasgauge-info -c 2>/dev/null | tr -cd '0-9') ;;
    esac
    echo "$b"
}

# Waits up to a minute for Wi-Fi.
wait_wifi() {
    i=0
    while [ "$i" -lt 30 ]; do
        [ "$(lipc-get-prop com.lab126.wifid cmState 2>/dev/null)" = "CONNECTED" ] && return 0
        sleep 2
        i=$((i + 1))
    done
    return 1
}

# fetch URL FILE - downloads to FILE, but only if the result is a PNG image.
fetch() {
    rm -f "$2.tmp"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL -m 60 -o "$2.tmp" "$1" 2>>"$LOG"      # -L: follow a redirect from http to https
    else
        wget -q -T 60 -O "$2.tmp" "$1" 2>>"$LOG"
    fi
    if [ -s "$2.tmp" ] && head -c 4 "$2.tmp" | grep -q PNG; then
        mv "$2.tmp" "$2"
        return 0
    fi
    rm -f "$2.tmp"
    return 1
}

# show FILE [full] - draws an image. "full" flashes the screen; otherwise the
# update is quiet, with a flash every FULL_REFRESH_EVERY times to clear ghosting.
DRAWS=0
show() {
    DRAWS=$((DRAWS + 1))
    if [ "$2" = full ] || [ "$FULL_REFRESH_EVERY" -le 1 ] || [ $((DRAWS % FULL_REFRESH_EVERY)) -eq 0 ]; then
        out=$(eips -f -g "$1" 2>&1) || log "eips failed: $out"
    else
        out=$(eips -g "$1" 2>&1) || log "eips failed: $out"
    fi
}

# Draws whichever of the forecast or the "no connection" screen is current.
show_current() {
    if [ "$STATE" = ok ]; then
        show "$DIR/weather.png" full
    else
        show "$DIR/offline.png" full
    fi
}

# Reads the settings made on the server's admin page (tabs, refresh interval,
# quiet hours, light) and applies them. They win over the values in this
# script and in settings.sh. If the server cannot be reached, the current
# values stay.
#
# The server sends plain KEY=value lines. Only known keys are accepted, and
# only values made of lowercase letters, digits and spaces, so nothing the
# server sends can run as a command.
load_server_settings() {
    file="$DIR/server-settings.txt"
    rm -f "$file.tmp"
    if command -v curl >/dev/null 2>&1; then
        curl -fsS -m 30 -o "$file.tmp" "$SERVER_URL?view=settings" 2>>"$LOG" || return 1
    else
        wget -q -T 30 -O "$file.tmp" "$SERVER_URL?view=settings" 2>>"$LOG" || return 1
    fi
    while IFS='=' read -r key value; do
        case "$value" in
            ''|*[!a-z0-9\ ]*) continue ;;
        esac
        case "$key" in
            TABS)         TABS=$value ;;
            DEFAULT_VIEW) DEFAULT_VIEW=$value ;;
            *)
                # Every other setting is a number.
                case "$value" in *[!0-9]*) continue ;; esac
                case "$key" in
                    INTERVAL_MIN)       [ "$value" -ge 1 ] && INTERVAL_MIN=$value ;;
                    QUIET_START)        QUIET_START=$value ;;
                    QUIET_END)          QUIET_END=$value ;;
                    FULL_REFRESH_EVERY) FULL_REFRESH_EVERY=$value ;;
                    LIGHT_LEVEL)        LIGHT_LEVEL=$value ;;
                    LIGHT_FROM)         LIGHT_FROM=$value ;;
                    LIGHT_TO)           LIGHT_TO=$value ;;
                    FLIGHTS)            FLIGHTS=$value ;;
                esac
                ;;
        esac
    done < "$file.tmp"
    mv "$file.tmp" "$file"
}

# Prints the query string every request shares: battery and touch controls.
common_query() {
    q=""
    b=$(battery)
    [ -n "$b" ] && q="$q&batt=$b"
    [ -n "$TOUCH_DEV" ] && q="$q&x=1"
    echo "$q"
}

# ==============================================================================
# 3. Screens
# ==============================================================================

# Reads the tab the user picked last into VIEW, and the page within it
# (only the hour-by-hour tab has more than one) into PAGE.
load_view() {
    VIEW=$(cat "$DIR/view" 2>/dev/null)
    case " $TABS " in
        *" $VIEW "*) ;;
        *) VIEW=$DEFAULT_VIEW ;;
    esac
    PAGE=0
    if [ "$VIEW" = hours ]; then
        case "$(cat "$DIR/page" 2>/dev/null)" in
            [0-9]) PAGE=$(cat "$DIR/page") ;;
        esac
    fi
}

# True while the flight screen is open. The file "mode" holds when it was opened.
in_flights() {
    [ -f "$DIR/mode" ]
}

# Shows the flight screen. A tap position waiting in the file "tap" is
# passed on, so the server can select the aircraft under the finger.
refresh_flights() {
    base=${SERVER_URL%index.php}
    case "$base" in */) ;; *) base="$base/" ;; esac
    query="interval=$INTERVAL_MIN$(common_query)"
    opened=0
    if [ -f "$DIR/flights_open" ]; then
        rm -f "$DIR/flights_open"
        opened=1
        query="$query&open=1"
    fi
    if [ -f "$DIR/tap" ]; then
        tap=$(cat "$DIR/tap")
        rm -f "$DIR/tap"
        case "$tap" in
            *[!0-9,]*|'') ;;
            *) query="$query&tap=$tap" ;;
        esac
    fi
    if fetch "${base}flights.php?$query" "$DIR/flights.png"; then
        # Flash when the screen is first opened; after that update quietly,
        # so a refresh every minute does not flicker.
        if [ "$opened" = 1 ] || [ "$STATE" != flights ]; then
            show "$DIR/flights.png" full
        else
            show "$DIR/flights.png"
        fi
        STATE=flights
        log "refreshed flights"
        return 0
    fi
    log "flight refresh failed"
    return 1
}

# Closes the flight screen, so the next refresh shows the weather again.
leave_flights() {
    rm -f "$DIR/mode" "$DIR/tap" "$DIR/flights_open"
    log "leaving the flight screen"
}

# True when the flight screen has been open for FLIGHT_TIMEOUT_MIN minutes.
flights_expired() {
    opened=$(cat "$DIR/mode" 2>/dev/null)
    case "$opened" in ''|*[!0-9]*) return 0 ;; esac
    [ $(( $(date +%s) - opened )) -ge $((FLIGHT_TIMEOUT_MIN * 60)) ]
}

# refresh [quick|first] - downloads and shows the current design. "quick" is
# used after a tap: it skips waiting for Wi-Fi and the extra download.
# "first" is the refresh at start-up: it also skips the extra download, so
# the picture is on screen as soon as possible.
# STATE records what is on screen: ok, offline, night or flights.
refresh() {
    if in_flights; then
        refresh_flights
        return
    fi
    if [ "$1" != quick ]; then
        wait_wifi || log "wifi not connected, trying anyway"
        # A scheduled refresh goes back to the first page: the hours have moved on.
        rm -f "$DIR/page"
        load_server_settings
        load_view
    fi
    query="interval=$INTERVAL_MIN&view=$VIEW&page=$PAGE$(common_query)"

    if fetch "$SERVER_URL?$query" "$DIR/weather.png"; then
        # Flash when the whole picture changes: after a tap, or coming back
        # from the night or no-connection screen.
        if [ "$1" = quick ] || [ "$STATE" != ok ]; then
            show "$DIR/weather.png" full
        else
            show "$DIR/weather.png"
        fi
        STATE=ok
        # On scheduled refreshes, also store the "no connection" screen for
        # this forecast. Skipped after a tap so switching stays quick.
        if [ "$1" != quick ] && [ "$1" != first ]; then
            fetch "$SERVER_URL?$query&view=offline" "$DIR/offline.png"
        fi
        log "refreshed $VIEW (battery $(battery)%)"
        return 0
    fi

    log "refresh failed"
    if [ "$STATE" != offline ] && [ -f "$DIR/offline.png" ]; then
        show "$DIR/offline.png" full
    fi
    STATE=offline
    return 1
}

# True during quiet hours.
is_quiet() {
    [ "$QUIET_START" = "$QUIET_END" ] && return 1
    h=$(hour_now)
    if [ "$QUIET_START" -gt "$QUIET_END" ]; then
        # The quiet period crosses midnight, for example 23 to 6.
        [ "$h" -ge "$QUIET_START" ] || [ "$h" -lt "$QUIET_END" ]
    else
        [ "$h" -ge "$QUIET_START" ] && [ "$h" -lt "$QUIET_END" ]
    fi
}

# Shows the night screen, once, when quiet hours begin.
night() {
    [ "$STATE" = night ] && return 0
    query="view=night&wake=$(printf '%02d:00' "$QUIET_END")$(common_query)"
    if fetch "$SERVER_URL?$query" "$DIR/night.png"; then
        show "$DIR/night.png" full
        STATE=night
        log "quiet hours: night screen shown"
    fi
}

# Sets the front light for the time of day.
apply_light() {
    [ "$LIGHT_CONTROL" = 1 ] || return 0
    h=$(hour_now)
    level=0
    if ! is_quiet && [ "$h" -ge "$LIGHT_FROM" ] && [ "$h" -lt "$LIGHT_TO" ]; then
        level=$LIGHT_LEVEL
    fi
    if [ "$level" != "$LIGHT_NOW" ]; then
        lipc-set-prop com.lab126.powerd flIntensity "$level" 2>/dev/null
        LIGHT_NOW=$level
    fi
}

# ==============================================================================
# 4. Touch
# ==============================================================================

# Prints the touchscreen's device file: the first input device that reports
# absolute positions. Prints nothing if there is none.
find_touchscreen() {
    ev=$(awk '/^H: Handlers=/ { h = $0 }
              /^B: ABS=/ && $0 !~ /ABS=0$/ { if (match(h, /event[0-9]+/)) { print substr(h, RSTART, RLENGTH); exit } }' \
        /proc/bus/input/devices 2>/dev/null)
    [ -n "$ev" ] && [ -r "/dev/input/$ev" ] && echo "/dev/input/$ev"
}

# Prints how many tabs there are.
tab_count() {
    set -- $TABS
    echo $#
}

# Prints the name of tab number $1 (0 = leftmost).
nth_tab() {
    n=$1
    set -- $TABS
    [ "$n" -ge $# ] && n=$(($# - 1))
    shift "$n"
    echo "$1"
}

# True when the plane button is switched on. The touch watcher runs beside
# the main loop, so it reads the setting from the server's last answer.
flights_on() {
    if [ -f "$DIR/server-settings.txt" ]; then
        grep -q '^FLIGHTS=1$' "$DIR/server-settings.txt"
    else
        [ "$FLIGHTS" = 1 ]
    fi
}

# Acts on a tap at ($1, $2). Runs inside the touch watcher, so it talks to
# the main loop with signals: TERM = close, USR1 = refresh now.
handle_tap() {
    log "tap at x=$1 y=$2"
    button_x=$((TABS_LEFT + TABS_WIDTH - FLIGHT_BTN_WIDTH))
    if [ "$1" -ge "$CLOSE_MIN_X" ] && [ "$2" -le "$CLOSE_MAX_Y" ]; then
        log "close button tapped"
        kill "$MAIN_PID"
    elif in_flights; then
        if [ "$2" -ge "$TABS_MIN_Y" ]; then
            # Bottom bar: everything but the plane button leads back to the weather.
            [ "$1" -lt "$button_x" ] && rm -f "$DIR/mode"
        elif [ "$2" -gt "$CLOSE_MAX_Y" ]; then
            # On the map or the card: let the server pick the aircraft under the finger.
            echo "$1,$2" > "$DIR/tap"
        fi
        kill -USR1 "$MAIN_PID"
    elif [ "$2" -ge "$TABS_MIN_Y" ] && flights_on && [ "$1" -ge "$button_x" ]; then
        # The plane button: open the flight screen.
        date +%s > "$DIR/mode"
        : > "$DIR/flights_open"
        kill -USR1 "$MAIN_PID"
    elif [ "$2" -ge "$TABS_MIN_Y" ]; then
        # Tab bar: remember the chosen tab, then refresh. The plane button,
        # when shown, takes its slice off the right end.
        width=$TABS_WIDTH
        flights_on && width=$((TABS_WIDTH - FLIGHT_BTN_WIDTH))
        tab=$(( ($1 - TABS_LEFT) * $(tab_count) / width ))
        [ "$tab" -lt 0 ] && tab=0
        nth_tab "$tab" > "$DIR/view"
        rm -f "$DIR/page"
        kill -USR1 "$MAIN_PID"
    elif [ "$2" -gt "$CLOSE_MAX_Y" ] && [ "$2" -lt "$REFRESH_MAX_Y" ]; then
        # Upper half of the screen: refresh now, same design.
        kill -USR1 "$MAIN_PID"
    elif [ "$(cat "$DIR/view" 2>/dev/null)" = hours ]; then
        # Lower half of the hour-by-hour tab: the next page, and round to the first again.
        turn_page cycle
    fi
}

# The hour-by-hour tab has this many pages (8 hours each). Must match the server.
HOUR_PAGES=3

# Turns the page of the hour-by-hour tab: "next", "prev", or "cycle" (next,
# and from the last page back to the first).
turn_page() {
    [ "$(cat "$DIR/view" 2>/dev/null)" = hours ] || return
    current=$(cat "$DIR/page" 2>/dev/null)
    case "$current" in
        [0-9]) ;;
        *) current=0 ;;
    esac
    case "$1" in
        next)  new=$((current + 1)); [ "$new" -ge "$HOUR_PAGES" ] && return ;;
        prev)  new=$((current - 1)); [ "$new" -lt 0 ] && return ;;
        cycle) new=$(( (current + 1) % HOUR_PAGES )) ;;
        *)     return ;;
    esac
    echo "$new" > "$DIR/page"
    kill -USR1 "$MAIN_PID"
}

# Acts on a finger movement from ($1, $2) to ($3, $4): a tap if it barely
# moved, a swipe if it moved mostly up or down.
handle_touch() {
    dx=$(( $3 - $1 ))
    dy=$(( $4 - $2 ))
    [ "$dx" -lt 0 ] && dx=$(( -dx ))
    if in_flights; then
        handle_tap "$3" "$4"            # the flight screen has no swipes
    elif [ "$dy" -le "-$SWIPE_MIN" ] && [ "$dx" -lt "$(( -dy ))" ]; then
        log "swipe up"
        turn_page next
    elif [ "$dy" -ge "$SWIPE_MIN" ] && [ "$dx" -lt "$dy" ]; then
        log "swipe down"
        turn_page prev
    else
        handle_tap "$3" "$4"
    fi
}

# Reads the touchscreen and calls handle_touch when a finger is lifted, with
# where it first touched (sx, sy) and where it left (tx, ty).
#
# Linux delivers input as 16-byte events: 8 bytes of time, then a type and a
# code (2 bytes each) and a 4-byte value. "od -d" prints those as an address
# followed by eight 16-bit numbers, so type = $6, code = $7 and value = $8.
#   type 3 (position):  code 53 or 0 = x, code 54 or 1 = y
#   type 3, code 57:    value 65535 (-1) = finger lifted
#   type 1, code 330:   value 0 = finger lifted
touch_watch() {
    exec 3<"$TOUCH_DEV" || return
    tx=0
    ty=0
    sx=0
    sy=0
    while true; do
        set -- $(dd bs=16 count=1 <&3 2>/dev/null | od -d)
        if [ $# -lt 9 ]; then
            sleep 1
            continue
        fi
        case "$6:$7" in
            3:53|3:0) tx=$8; [ "$sx" = 0 ] && sx=$8 ;;
            3:54|3:1) ty=$8; [ "$sy" = 0 ] && sy=$8 ;;
            3:57|1:330)
                if [ "$8" = 65535 ] || [ "$6:$8" = "1:0" ]; then
                    # Both "finger lifted" events can arrive for one tap; the
                    # position is cleared so the second one is ignored.
                    if [ "$tx" -gt 0 ] && [ "$ty" -gt 0 ]; then
                        handle_touch "$sx" "$sy" "$tx" "$ty"
                    fi
                    tx=0
                    ty=0
                    sx=0
                    sy=0
                fi
                ;;
        esac
    done
}

# ==============================================================================
# 5. Start and stop
# ==============================================================================

# Launcher: this part runs when you tap the scriptlet.
if [ "$1" != "--loop" ]; then
    mkdir -p "$DIR"
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        # Already running: tapping again stops it.
        kill "$(cat "$PIDFILE")"
        log "stop requested"
        exit 0
    fi
    # Run a detached copy so it survives the launcher (and the interface) exiting.
    cp "$0" "$RUN"
    if command -v setsid >/dev/null 2>&1; then
        setsid sh "$RUN" --loop </dev/null >/dev/null 2>&1 &
    else
        sh "$RUN" --loop </dev/null >/dev/null 2>&1 &
    fi
    exit 0
fi

# Everything below runs in the detached copy.
MAIN_PID=$$
STATE=""                            # what is on screen: ok, offline or night
SWITCH=0                            # 1 = a tap asked for a refresh
GUI_STOPPED=0                       # 1 = we stopped the Kindle interface
SLEEP_PID=""
TOUCH_PID=""
LIGHT_NOW=""
LIGHT_BEFORE=$(lipc-get-prop com.lab126.powerd flIntensity 2>/dev/null)
TOUCH_DEV=""
[ "$TOUCH_CONTROLS" = 1 ] && TOUCH_DEV=$(find_touchscreen)

# Puts the Kindle back the way it was.
restore() {
    lipc-set-prop com.lab126.powerd preventScreenSaver 0 2>/dev/null
    if [ "$LIGHT_CONTROL" = 1 ] && [ -n "$LIGHT_BEFORE" ]; then
        lipc-set-prop com.lab126.powerd flIntensity "$LIGHT_BEFORE" 2>/dev/null
    fi
    if [ "$GUI_STOPPED" = 1 ]; then
        start lab126_gui >/dev/null 2>&1
    fi
    # With STOP_GUI=0 the interface keeps running and redraws on the next tap.
}

# Runs when the display is closed (X tapped, or the scriptlet tapped again).
cleanup() {
    [ -n "$SLEEP_PID" ] && kill "$SLEEP_PID" 2>/dev/null
    [ -n "$TOUCH_PID" ] && kill "$TOUCH_PID" 2>/dev/null
    # Restarting the Kindle interface takes about half a minute; say so meanwhile.
    if [ "$GUI_STOPPED" = 1 ] && [ -f "$DIR/closing.png" ]; then
        eips -f -g "$DIR/closing.png" >/dev/null 2>&1
    fi
    restore
    rm -f "$PIDFILE"
    log "stopped"
    exit 0
}
trap cleanup TERM INT
trap 'SWITCH=1' USR1

rm -f "$DIR/mode" "$DIR/tap" "$DIR/flights_open"      # always start on the weather
echo $$ > "$PIDFILE"
log "started (interval $INTERVAL_MIN min, server $SERVER_URL)"
load_view

# Give the scriptlet launcher a moment to finish with the screen, so it does
# not paint over the first image.
sleep 3

# The lines of text printed with eips if the first download fails. It prints
# plain ASCII only, so the Norwegian lines avoid the letters ae, o and a with marks.
if [ "$LANGUAGE" = en ]; then
    MSG_NO_SERVER="No contact with the server:"
    MSG_CHECK="Check Wi-Fi, or download the file again from admin.php."
    MSG_LOG="More in kindle-weather/log.txt"
else
    MSG_NO_SERVER="Ingen kontakt med serveren:"
    MSG_CHECK="Sjekk Wi-Fi, eller last ned filen igjen fra admin.php."
    MSG_LOG="Mer i kindle-weather/log.txt"
fi

# The first refresh happens before taking over the screen, so a wrong address
# leaves you with a working Kindle and a message instead of a frozen display.
# Nothing is printed while it downloads: the library stays as it is for the
# few seconds it takes, and then the picture appears.
refresh first
FIRST=$?
if [ "$FIRST" != 0 ] && [ ! -f "$DIR/weather.png" ]; then
    # The library repaints once after the launcher exits, so the message is
    # printed again a little later to stay readable.
    log "first refresh failed, giving up"
    for wait in 0 5; do
        sleep "$wait"
        eips 2 2 "$MSG_NO_SERVER" 2>/dev/null
        eips 2 3 "$SERVER_URL" 2>/dev/null
        eips 2 5 "$MSG_CHECK" 2>/dev/null
        eips 2 6 "$MSG_LOG" 2>/dev/null
    done
    rm -f "$PIDFILE"
    exit 1
fi

# Take over the screen right away. Anything else that needs the server
# waits until the picture is safely up, because the library paints over it
# for as long as the Kindle's interface is running.
lipc-set-prop com.lab126.powerd preventScreenSaver 1 2>/dev/null
apply_light

if [ "$STOP_GUI" = 1 ]; then
    if stop lab126_gui >/dev/null 2>&1; then
        GUI_STOPPED=1
        sleep 3
        show_current            # stopping the interface can clear the screen
    else
        log "could not stop the interface, continuing with it running"
    fi
else
    sleep 4
    show_current                # the interface may repaint once after the launcher exits
fi

# Now the two pictures kept in reserve: the goodbye screen and "no connection".
fetch "$SERVER_URL?view=closing" "$DIR/closing.png"
[ "$FIRST" = 0 ] && fetch "$SERVER_URL?$query&view=offline" "$DIR/offline.png"

if [ -n "$TOUCH_DEV" ]; then
    touch_watch &
    TOUCH_PID=$!
    log "touch controls active ($TOUCH_DEV)"
elif [ "$TOUCH_CONTROLS" = 1 ]; then
    log "no touchscreen device found, touch controls disabled"
fi

# ==============================================================================
# 6. Main loop
# ==============================================================================

while true; do
    # Sleep until the next refresh; a tap (USR1) cuts the wait short.
    if [ "$SWITCH" != 1 ]; then
        pause=$((INTERVAL_MIN * 60))
        in_flights && pause=$FLIGHT_INTERVAL_SEC
        sleep "$pause" &
        SLEEP_PID=$!
        wait "$SLEEP_PID"
    fi
    [ -n "$SLEEP_PID" ] && kill "$SLEEP_PID" 2>/dev/null
    SLEEP_PID=""

    apply_light
    if [ "$SWITCH" = 1 ]; then
        # A tap always gets a fresh picture, also during quiet hours.
        SWITCH=0
        load_view
        refresh quick
    elif in_flights && { flights_expired || is_quiet; }; then
        # Left open: go back to the weather rather than polling all night.
        leave_flights
        if is_quiet; then night; else refresh quick; fi
    elif in_flights; then
        refresh_flights
    elif is_quiet; then
        night
    else
        refresh
    fi
done
