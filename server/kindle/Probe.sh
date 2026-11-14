#!/bin/sh
# Name: Værvarsel probe
# Author: ClearanceClarence
# DontUseFBInk

# Collects facts about this Kindle that a sleep-between-refreshes mode would
# need: which clocks can wake it, how it suspends, and what its power and
# Wi-Fi services report. It only reads; it changes nothing on the Kindle.
#
# Copy to the Kindle's "documents" folder, tap it once, then find the result
# in "kindle-weather/probe.txt" over USB.

OUT=/mnt/us/kindle-weather/probe.txt
mkdir -p /mnt/us/kindle-weather

section() {
    echo
    echo "=== $1 ==="
}

{
    echo "Probe run: $(date)"

    section "System"
    uname -a
    cat /etc/prettyversion.txt 2>/dev/null
    cat /proc/cpuinfo 2>/dev/null | grep -i -E "hardware|model name" | head -3

    section "Real-time clocks (which one can wake the Kindle)"
    ls -l /dev/rtc* 2>&1
    for rtc in /sys/class/rtc/rtc*; do
        [ -d "$rtc" ] || continue
        echo "$rtc: name=$(cat "$rtc/name" 2>/dev/null) wakealarm=$([ -e "$rtc/wakealarm" ] && echo yes || echo no) since_epoch=$(cat "$rtc/since_epoch" 2>/dev/null)"
        cat "$rtc/device/power/wakeup" 2>/dev/null | sed "s#^#  can wake: #"
    done
    ls /sys/devices/platform/ 2>/dev/null | grep -i rtc

    section "Suspend"
    echo "sleep states: $(cat /sys/power/state 2>&1)"
    echo "rtcwake: $(command -v rtcwake 2>&1 || echo 'not found')"
    busybox 2>/dev/null | grep -o -E "rtcwake|hwclock|od|setsid" | sort -u | tr '\n' ' '
    echo

    section "Power service (powerd)"
    lipc-probe com.lab126.powerd -a -v 2>&1

    section "Wi-Fi"
    echo "wirelessEnable: $(lipc-get-prop com.lab126.cmd wirelessEnable 2>&1)"
    echo "cmState: $(lipc-get-prop com.lab126.wifid cmState 2>&1)"

    section "Input devices"
    cat /proc/bus/input/devices 2>&1
} > "$OUT" 2>&1

eips 2 2 "Ferdig: se kindle-weather/probe.txt" 2>/dev/null
