#!/usr/bin/env python3
"""Render GIF frames from the animated SVG's translation samples."""
import copy
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET

source, output = map(Path, sys.argv[1:])
root = ET.parse(source).getroot()
animation_tag = '{http://www.w3.org/2000/svg}animateTransform'
animations = list(root.iter(animation_tag))
# The final sample closes the loop; omit it to avoid a pause on repetition.
frame_count = len(animations[0].attrib['values'].split(';')) - 1
for index in range(frame_count):
    frame = copy.deepcopy(root)
    for element in frame.iter():
        for animation in list(element):
            if animation.tag == animation_tag:
                offset = animation.attrib['values'].split(';')[index]
                element.set('transform', f'translate({offset})')
                element.remove(animation)
    svg_path = output / f'{source.stem}-{index:02}.svg'
    ET.ElementTree(frame).write(svg_path, encoding='unicode')
    subprocess.run(['rsvg-convert', str(svg_path), '-o',
                    str(svg_path.with_suffix('.png'))], check=True)
