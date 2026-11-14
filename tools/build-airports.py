#!/usr/bin/env python3
"""
Builds server/map/airports.json: the world's large and medium airports,
for the flight screen's map.

    python3 tools/build-airports.py path/to/airports.csv

airports.csv comes from OurAirports (public domain):
https://github.com/davidmegginson/ourairports-data
"""
import csv
import json
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / "server" / "map" / "airports.json"


def main(source: str) -> None:
    airports = []
    with open(source, newline="", encoding="utf-8") as handle:
        for row in csv.DictReader(handle):
            if row["type"] not in ("large_airport", "medium_airport"):
                continue
            # The code people know: the three-letter one when there is one.
            code = row["iata_code"] or row["icao_code"] or row["gps_code"] or row["ident"]
            airports.append([
                code,
                round(float(row["latitude_deg"]), 4),
                round(float(row["longitude_deg"]), 4),
                1 if row["type"] == "large_airport" else 0,
                row["name"],
            ])
    airports.sort(key=lambda a: (a[1], a[2]))
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(
        {"source": "OurAirports (public domain)", "fields": ["code", "lat", "lon", "large", "name"], "airports": airports},
        ensure_ascii=False, separators=(",", ":"),
    ), encoding="utf-8")
    print(f"wrote {OUT}: {len(airports)} airports, {OUT.stat().st_size // 1024} kB")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
