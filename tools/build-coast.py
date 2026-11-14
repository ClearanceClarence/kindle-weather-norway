#!/usr/bin/env python3
"""
Builds server/map/coast.json: a coarse outline of land and large lakes in
the Nordic area, from the public-domain Natural Earth maps.

The flight screen draws this when Kartverket's detailed map is not
available: outside Norway, or when the map service cannot be reached.

    python3 tools/build-coast.py path/to/natural-earth-geojson-folder

The folder must hold ne_10m_land.geojson, ne_10m_minor_islands.geojson and
ne_10m_lakes.geojson from https://github.com/nvkelso/natural-earth-vector
(folder "geojson"). Needs shapely.
"""
import json
import sys
from pathlib import Path

from shapely.geometry import box, shape

AREA = box(2.0, 53.5, 34.0, 72.0)        # west, south, east, north
TOLERANCE = 0.004                        # degrees; about 300 m
OUT = Path(__file__).resolve().parent.parent / "server" / "map" / "coast.json"


def rings(folder: Path, name: str, min_area: float) -> list:
    out = []
    for feature in json.loads((folder / f"{name}.geojson").read_text())["features"]:
        if not feature["geometry"]:
            continue
        geom = shape(feature["geometry"])
        if not geom.is_valid:
            geom = geom.buffer(0)
        geom = geom.intersection(AREA).simplify(TOLERANCE)
        for poly in getattr(geom, "geoms", [geom]):
            if poly.geom_type == "Polygon" and poly.area >= min_area:
                out.append([[round(x, 4), round(y, 4)] for x, y in poly.exterior.coords])
    return out


def main(folder: str) -> None:
    folder = Path(folder)
    data = {
        "source": "Natural Earth (public domain), 1:10m",
        "land": rings(folder, "ne_10m_land", 0.00002) + rings(folder, "ne_10m_minor_islands", 0.00002),
        "lakes": rings(folder, "ne_10m_lakes", 0.0005),
    }
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(data, separators=(",", ":")))
    points = sum(len(r) for r in data["land"] + data["lakes"])
    print(f"wrote {OUT}: {len(data['land'])} land rings, {len(data['lakes'])} lakes, {points} points, {OUT.stat().st_size // 1024} kB")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
