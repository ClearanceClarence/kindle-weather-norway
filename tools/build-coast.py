#!/usr/bin/env python3
"""
Builds the two coarse outlines of land and large lakes that the flight
screen falls back on when no map service can be reached, from the
public-domain Natural Earth maps:

    server/map/coast.json         the Nordic area, about 300 m detail
    server/map/coast-world.json   the whole world, about 2 km detail

    python3 tools/build-coast.py path/to/natural-earth-geojson-folder

The folder must hold ne_10m_land.geojson, ne_10m_minor_islands.geojson and
ne_10m_lakes.geojson from https://github.com/nvkelso/natural-earth-vector
(folder "geojson"). Needs shapely.
"""
import json
import sys
from pathlib import Path

from shapely.geometry import box, shape

MAP_DIR = Path(__file__).resolve().parent.parent / "server" / "map"
# file name => (area as west, south, east, north; detail in degrees; smallest land and lake kept, in square degrees)
MAPS = {
    "coast.json": (box(2.0, 53.5, 34.0, 72.0), 0.004, 0.00002, 0.0005),
    "coast-world.json": (box(-180.0, -85.0, 180.0, 85.0), 0.02, 0.002, 0.02),
}


def rings(folder: Path, name: str, area, tolerance: float, min_area: float) -> list:
    out = []
    for feature in json.loads((folder / f"{name}.geojson").read_text())["features"]:
        if not feature["geometry"]:
            continue
        geom = shape(feature["geometry"])
        if not geom.is_valid:
            geom = geom.buffer(0)
        geom = geom.intersection(area).simplify(tolerance)
        for poly in getattr(geom, "geoms", [geom]):
            if poly.geom_type == "Polygon" and poly.area >= min_area:
                out.append([[round(x, 4), round(y, 4)] for x, y in poly.exterior.coords])
    return out


def main(folder: str) -> None:
    folder = Path(folder)
    MAP_DIR.mkdir(parents=True, exist_ok=True)
    for name, (area, tolerance, min_land, min_lake) in MAPS.items():
        data = {
            "source": "Natural Earth (public domain), 1:10m",
            "land": rings(folder, "ne_10m_land", area, tolerance, min_land)
            + rings(folder, "ne_10m_minor_islands", area, tolerance, min_land),
            "lakes": rings(folder, "ne_10m_lakes", area, tolerance, min_lake),
        }
        out = MAP_DIR / name
        out.write_text(json.dumps(data, separators=(",", ":")))
        points = sum(len(r) for r in data["land"] + data["lakes"])
        print(f"wrote {out}: {len(data['land'])} land rings, {len(data['lakes'])} lakes, {points} points, {out.stat().st_size // 1024} kB")


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
