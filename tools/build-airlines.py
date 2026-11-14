#!/usr/bin/env python3
"""
Builds server/map/airlines.json: airline names by their three-letter ICAO
code, which is how a call sign such as "CAO1153" starts.

    python3 tools/build-airlines.py path/to/airlines.dat

airlines.dat comes from OpenFlights (https://openflights.org/data), which
publishes it under the Open Database License. The file made here is a part
of that database and stays under the same license.
"""
import csv
import json
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / "server" / "map" / "airlines.json"


def main(source: str) -> None:
    names = {}
    with open(source, newline="", encoding="utf-8") as handle:
        for row in csv.reader(handle):
            if len(row) < 8:
                continue
            name, icao, active = row[1].strip(), row[4].strip().upper(), row[7].strip()
            if len(icao) != 3 or not icao.isalpha() or not name:
                continue
            # Airlines still flying win over defunct ones that used the same code.
            if icao not in names or active == "Y":
                names[icao] = name
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(
        {"source": "OpenFlights airline database, Open Database License (ODbL)", "airlines": dict(sorted(names.items()))},
        ensure_ascii=False, separators=(",", ":"),
    ), encoding="utf-8")
    print(f"wrote {OUT}: {len(names)} airlines, {OUT.stat().st_size // 1024} kB")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
