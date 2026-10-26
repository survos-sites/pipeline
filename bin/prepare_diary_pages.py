#!/usr/bin/env python3
"""Make upright notebook leaves from Edith's sideways spreads, preserving source coordinates.

Requires pypdf and Pillow. Run from the project root. Original PDF is never changed.
Blank notebook pages remain; only the two black covers are excluded.
"""
import hashlib
import json
from pathlib import Path
from PIL import Image
from pypdf import PdfReader

root = Path(__file__).resolve().parent.parent
folder = root / 'var/private/documents/edith-amerika-1957'
source = folder / 'source.pdf'
reader = PdfReader(source)
(folder / 'pages').mkdir(exist_ok=True)
leaves = []
for scan, page in enumerate(reader.pages, 1):
    if len(page.images) != 1:
        raise ValueError(f'Scan {scan}: expected exactly one embedded spread image')
    original = page.images[0].image.convert('RGB')
    upright = original.transpose(Image.Transpose.ROTATE_270)
    width, height = upright.size
    # Small overlap around the gutter avoids clipping handwriting near the rings.
    overlap = round(width * 0.025)
    for side in ['left', 'right']:
        if (scan == 1 and side == 'left') or (scan == len(reader.pages) and side == 'right'):
            continue
        box = (0, 0, width // 2 + overlap, height) if side == 'left' else (width // 2 - overlap, 0, width, height)
        number = len(leaves) + 1
        filename = f'pages/{number:03d}.png'
        upright.crop(box).save(folder / filename)
        leaves.append({'number': number, 'scan': scan, 'side': side, 'image': filename,
                       'original_pixels': list(original.size), 'rotation_clockwise': 90,
                       'crop_box_upright': list(box)})
(folder / 'leaves.json').write_text(json.dumps({'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
    'note': 'Reading page numbers are generated; scan numbers refer to the original PDF. Crops overlap at the gutter.',
    'pages': leaves}, indent=2) + '\n')
print(f'Prepared {len(leaves)} upright reading pages from {len(reader.pages)} original scans.')
