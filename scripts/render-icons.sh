#!/usr/bin/env bash
# Render the legacy CGI icons without changing their original dimensions.
set -euo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
image_dir="$script_dir/../share/images"
for tool in rsvg-convert magick; do
    command -v "$tool" >/dev/null || { echo "Missing dependency: $tool" >&2; exit 1; }
done
render_dir=$(mktemp -d)
trap 'rm -rf -- "$render_dir"' EXIT

for icon in action comment downtime flapping status2; do
    rsvg-convert "$image_dir/$icon.svg" -o "$render_dir/$icon.png"
    # GIF has binary transparency. Keep transparent corners and the neutral
    # surface color at their edges, avoiding a dark fringe on bright cells.
    magick "$render_dir/$icon.png" -background '#f1f5f9' -alpha background \
        -channel A -threshold '50%' +channel "$render_dir/$icon.gif"
done

for icon in action comment downtime flapping status2; do
    cp -- "$render_dir/$icon.gif" "$image_dir/$icon.gif"
done
