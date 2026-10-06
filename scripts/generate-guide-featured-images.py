#!/usr/bin/env python3
"""Generate 1200x600 featured images for FPL guide articles."""

from __future__ import annotations

import math
import random
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = Path(__file__).resolve().parents[1]
OUT_DIR = ROOT / "public/images/guides/featured"
LOGO_PATH = ROOT / "public/images/metro-football-logo-light.webp"

WIDTH = 1200
HEIGHT = 600

GUIDES = [
    {
        "slug": "how-to-play-fpl",
        "title": "HOW TO PLAY FPL",
        "clubs": [
            ("#EF0107", "#FFFFFF", "#EF0107"),  # Arsenal
            ("#034694", "#FFFFFF", "#034694"),  # Chelsea
            ("#C8102E", "#FFFFFF", "#C8102E"),  # Liverpool
        ],
    },
    {
        "slug": "wildcard-vs-free-hit",
        "title": "WILDCARD VS FREE HIT",
        "clubs": [
            ("#6CABDD", "#FFFFFF", "#1C2C5B"),  # Man City
            ("#DA291C", "#FBE122", "#DA291C"),  # Man United
            ("#132257", "#FFFFFF", "#132257"),  # Spurs
        ],
    },
    {
        "slug": "when-to-take-a-hit",
        "title": "WHEN TO TAKE A HIT",
        "clubs": [
            ("#670E36", "#95BFE5", "#670E36"),  # Villa
            ("#003399", "#FFFFFF", "#003399"),  # Everton-ish blue
            ("#241F20", "#FFFFFF", "#241F20"),  # Newcastle
        ],
    },
    {
        "slug": "how-fpl-scoring-works",
        "title": "HOW FPL SCORING WORKS",
        "clubs": [
            ("#C8102E", "#FFFFFF", "#00A398"),  # Liverpool
            ("#EF0107", "#FFFFFF", "#9C824A"),  # Arsenal
            ("#034694", "#FFFFFF", "#034694"),  # Chelsea
        ],
    },
    {
        "slug": "how-to-pick-captain",
        "title": "HOW TO PICK CAPTAIN",
        "clubs": [
            ("#DA291C", "#000000", "#DA291C"),  # Man United
            ("#6CABDD", "#FFFFFF", "#1C2C5B"),  # Man City
            ("#132257", "#FFFFFF", "#132257"),  # Spurs
        ],
    },
    {
        "slug": "price-rises-and-falls",
        "title": "PRICE RISES & FALLS",
        "clubs": [
            ("#034694", "#FFFFFF", "#034694"),  # Chelsea
            ("#EF0107", "#FFFFFF", "#EF0107"),  # Arsenal
            ("#670E36", "#95BFE5", "#670E36"),  # Villa
        ],
    },
    {
        "slug": "blank-double-gameweek-guide",
        "title": "BLANK & DOUBLE GWS",
        "clubs": [
            ("#241F20", "#FFFFFF", "#241F20"),  # Newcastle
            ("#C8102E", "#FFFFFF", "#C8102E"),  # Liverpool
            ("#6CABDD", "#FFFFFF", "#1C2C5B"),  # Man City
        ],
    },
    {
        "slug": "fpl-formations-guide",
        "title": "FPL FORMATIONS GUIDE",
        "clubs": [
            ("#132257", "#FFFFFF", "#132257"),  # Spurs
            ("#DA291C", "#FBE122", "#DA291C"),  # Man United
            ("#034694", "#FFFFFF", "#034694"),  # Chelsea
        ],
    },
    {
        "slug": "template-vs-differential",
        "title": "TEMPLATE VS DIFFERENTIAL",
        "clubs": [
            ("#EF0107", "#FFFFFF", "#EF0107"),  # Arsenal
            ("#6CABDD", "#FFFFFF", "#1C2C5B"),  # Man City
            ("#C8102E", "#FFFFFF", "#00A398"),  # Liverpool
        ],
    },
    {
        "slug": "chip-guide",
        "title": "FPL CHIP GUIDE",
        "clubs": [
            ("#670E36", "#95BFE5", "#670E36"),  # Villa
            ("#241F20", "#FFFFFF", "#241F20"),  # Newcastle
            ("#DA291C", "#000000", "#DA291C"),  # Man United
        ],
    },
]


def load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    candidates = [
        "/System/Library/Fonts/Supplemental/Arial Bold.ttf" if bold else "/System/Library/Fonts/Supplemental/Arial.ttf",
        "/Library/Fonts/Arial Bold.ttf" if bold else "/Library/Fonts/Arial.ttf",
        "/System/Library/Fonts/Helvetica.ttc",
    ]
    for path in candidates:
        p = Path(path)
        if p.exists():
            try:
                return ImageFont.truetype(str(p), size=size)
            except OSError:
                continue
    return ImageFont.load_default()


def draw_gradient(draw: ImageDraw.ImageDraw) -> None:
    for y in range(HEIGHT):
        t = y / HEIGHT
        r = int(8 + (18 - 8) * t)
        g = int(14 + (8 - 14) * t)
        b = int(28 + (12 - 28) * t)
        draw.line([(0, y), (WIDTH, y)], fill=(r, g, b))


def add_glow(base: Image.Image) -> Image.Image:
    glow = Image.new("RGBA", (WIDTH, HEIGHT), (0, 0, 0, 0))
    gdraw = ImageDraw.Draw(glow)
    gdraw.ellipse((-120, 80, 520, 520), fill=(220, 38, 38, 55))
    gdraw.ellipse((700, -80, 1280, 360), fill=(220, 38, 38, 28))
    glow = glow.filter(ImageFilter.GaussianBlur(70))
    return Image.alpha_composite(base.convert("RGBA"), glow)


def add_noise(base: Image.Image) -> Image.Image:
    random.seed(42)
    noise = Image.new("RGBA", (WIDTH, HEIGHT))
    px = noise.load()
    for y in range(HEIGHT):
        for x in range(0, WIDTH, 2):
            v = random.randint(0, 18)
            px[x, y] = (255, 255, 255, v)
    noise = noise.filter(ImageFilter.GaussianBlur(1))
    return Image.alpha_composite(base.convert("RGBA"), noise)


def draw_jersey(
    draw: ImageDraw.ImageDraw,
    cx: int,
    top: int,
    body: str,
    trim: str,
    accent: str,
) -> None:
    w, h = 150, 185
    x0, y0 = cx - w // 2, top
    x1, y1 = cx + w // 2, top + h

    shadow = Image.new("RGBA", (WIDTH, HEIGHT), (0, 0, 0, 0))
    sdraw = ImageDraw.Draw(shadow)
    sdraw.rounded_rectangle((x0 + 8, y0 + 12, x1 + 8, y1 + 8), radius=18, fill=(0, 0, 0, 90))
    # shadow merged later via paste in caller if needed

    draw.rounded_rectangle((x0, y0, x1, y1), radius=18, fill=body, outline=trim, width=3)

    neck_w = 54
    draw.polygon(
        [
            (cx - neck_w // 2, y0 + 8),
            (cx + neck_w // 2, y0 + 8),
            (cx + 18, y0 + 34),
            (cx - 18, y0 + 34),
        ],
        fill=trim,
    )

    sleeve = 34
    draw.rounded_rectangle((x0 - 8, y0 + 36, x0 + sleeve, y0 + 110), radius=12, fill=body, outline=trim, width=2)
    draw.rounded_rectangle((x1 - sleeve, y0 + 36, x1 + 8, y0 + 110), radius=12, fill=body, outline=trim, width=2)

    draw.rounded_rectangle((x0 + 18, y0 + 70, x1 - 18, y0 + 130), radius=8, fill=accent)
    draw.line([(x0 + 24, y0 + 44), (x0 + 24, y1 - 20)], fill=trim, width=2)
    draw.line([(x1 - 24, y0 + 44), (x1 - 24, y1 - 20)], fill=trim, width=2)


def wrap_title(title: str, font: ImageFont.ImageFont, max_width: int) -> list[str]:
    words = title.split()
    lines: list[str] = []
    current = ""
    for word in words:
        test = f"{current} {word}".strip()
        if font.getlength(test) <= max_width:
            current = test
        else:
            if current:
                lines.append(current)
            current = word
    if current:
        lines.append(current)
    return lines[:2]


def render_guide(guide: dict) -> Image.Image:
    base = Image.new("RGB", (WIDTH, HEIGHT), "#0b1220")
    draw = ImageDraw.Draw(base)
    draw_gradient(draw)
    img = add_glow(base)
    img = add_noise(img)
    draw = ImageDraw.Draw(img)

    positions = [300, 600, 900]
    for pos, colors in zip(positions, guide["clubs"]):
        draw_jersey(draw, pos, 42, *colors)

    card_w, card_h = 920, 150
    card_x0 = (WIDTH - card_w) // 2
    card_y0 = 250
    card_x1 = card_x0 + card_w
    card_y1 = card_y0 + card_h

    shadow = Image.new("RGBA", (WIDTH, HEIGHT), (0, 0, 0, 0))
    sdraw = ImageDraw.Draw(shadow)
    sdraw.rounded_rectangle((card_x0 + 6, card_y0 + 10, card_x1 + 6, card_y1 + 10), radius=22, fill=(0, 0, 0, 120))
    img = Image.alpha_composite(img, shadow)
    draw = ImageDraw.Draw(img)

    draw.rounded_rectangle((card_x0, card_y0, card_x1, card_y1), radius=22, fill=(24, 32, 48, 235), outline=(220, 38, 38, 180), width=2)

    title_font = load_font(54, bold=True)
    lines = wrap_title(guide["title"], title_font, card_w - 80)
    line_height = 62
    total_h = len(lines) * line_height
    start_y = card_y0 + (card_h - total_h) // 2 + 8
    for i, line in enumerate(lines):
        tw = title_font.getlength(line)
        tx = card_x0 + (card_w - tw) / 2
        ty = start_y + i * line_height
        draw.text((tx, ty), line, font=title_font, fill="#f8fafc")

    if LOGO_PATH.exists():
        logo = Image.open(LOGO_PATH).convert("RGBA")
        target_w = 280
        ratio = target_w / logo.width
        target_h = int(logo.height * ratio)
        logo = logo.resize((target_w, target_h), Image.Resampling.LANCZOS)
        lx = (WIDTH - target_w) // 2
        ly = HEIGHT - target_h - 34
        img.paste(logo, (lx, ly), logo)

    return img.convert("RGB")


def main() -> int:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for guide in GUIDES:
        out = OUT_DIR / f"{guide['slug']}.webp"
        img = render_guide(guide)
        img.save(out, format="WEBP", quality=90, method=6)
        print(f"OK {out.relative_to(ROOT)} ({img.size[0]}x{img.size[1]})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
