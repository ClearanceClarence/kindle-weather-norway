# Your settings for the Kindle weather display.
#
# Copy this file to the Kindle as "kindle-weather/settings.sh" (create the
# "kindle-weather" folder next to "documents" if it is not there yet). Anything
# you set here replaces the default at the top of kindle-weather.sh, so updating
# the script never overwrites your settings.
#
# Keep Unix (LF) line endings: edit with VS Code or Notepad++, not Notepad.

# Address of your image server (the folder holding index.php).
SERVER_URL="https://example.com/weather/"

# Uncomment and change any of these. The defaults are shown.
#INTERVAL_MIN=10            # minutes between refreshes
#QUIET_START=0              # quiet hours start (24-hour clock) ...
#QUIET_END=6                # ... and end; same number twice = off
#FULL_REFRESH_EVERY=6       # flash the screen every Nth refresh
#ROTATE=0                   # 1 = show the next tab at every refresh
#ROTATE_FLIGHTS=0           # 1 = the flight screen takes a turn in the rotation too
#LIGHT_CONTROL=1            # 1 = this script sets the front light
#LIGHT_LEVEL=0              # 0 = light always off (saves the most battery); 1-24 = on ...
#LIGHT_CHARGING=10          # light level while charging, all day except quiet hours; 0 = no difference
#LIGHT_FROM=16              # ... from this hour ...
#LIGHT_TO=24                # ... until this hour
#STOP_GUI=1                 # 0 = keep the Kindle interface running (for testing)
#LANGUAGE=no               # language of the start-up messages: no or en
