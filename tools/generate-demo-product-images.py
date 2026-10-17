#!/usr/bin/env python3
"""Render the demo catalogue's product images.

Reads database/seeders/DummyData/catalogue.json and writes one 800x800 WebP per
product to database/seeders/DummyData/images/. Each image is the product's
Font Awesome Free glyph on its top-level category's tint, so the demo store
looks coherent without bundling stock photography.

Needs Pillow, PyYAML and node_modules (npm ci). Run from the repository root:

    python3 tools/generate-demo-product-images.py
"""

import json
from pathlib import Path

import yaml
from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = Path(__file__).resolve().parent.parent
CATALOGUE = ROOT / "database/seeders/DummyData/catalogue.json"
OUTPUT = ROOT / "database/seeders/DummyData/images"
FA = ROOT / "node_modules/@fortawesome/fontawesome-free"
SIZE = 800


def hex_rgb(value: str) -> tuple[int, int, int]:
    value = value.lstrip("#")
    return tuple(int(value[i:i + 2], 16) for i in (0, 2, 4))


def shade(rgb, factor):
    return tuple(max(0, min(255, round(c * factor))) for c in rgb)


def main() -> None:
    catalogue = json.loads(CATALOGUE.read_text())
    icons = yaml.safe_load((FA / "metadata/icons.yml").read_text())
    font = ImageFont.truetype(str(FA / "webfonts/fa-solid-900.woff2"), 340)

    palette = {}
    for top in catalogue["categories"]:
        for slug in [top["slug"], *(child["slug"] for child in top["children"])]:
            palette[slug] = (hex_rgb(top["tint"]), hex_rgb(top["ink"]))

    OUTPUT.mkdir(parents=True, exist_ok=True)

    for product in catalogue["products"]:
        icon = icons.get(product["icon"])
        if not icon or "solid" not in icon.get("styles", []):
            raise SystemExit(f"{product['slug']}: '{product['icon']}' is not a Font Awesome Free solid icon")

        tint, ink = palette[product["category"]]
        image = Image.new("RGB", (SIZE, SIZE), tint)

        # A soft floor shadow, so the glyph reads as an object rather than a label.
        shadow = Image.new("L", (SIZE, SIZE), 0)
        ImageDraw.Draw(shadow).ellipse((250, 548, 550, 590), fill=70)
        shadow = shadow.filter(ImageFilter.GaussianBlur(18))
        image.paste(Image.new("RGB", (SIZE, SIZE), shade(tint, 0.82)), mask=shadow)

        draw = ImageDraw.Draw(image)
        glyph = chr(int(icon["unicode"], 16))
        left, top, right, bottom = draw.textbbox((0, 0), glyph, font=font)
        x = (SIZE - (right - left)) / 2 - left
        y = (SIZE - (bottom - top)) / 2 - top - 40
        draw.text((x, y), glyph, font=font, fill=ink)

        image.save(OUTPUT / f"{product['slug']}.webp", "WEBP", quality=82, method=6)

    print(f"Wrote {len(catalogue['products'])} images to {OUTPUT.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
