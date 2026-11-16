#!/usr/bin/env bash
# Render the legacy CGI icons without changing their original dimensions.
set -euo pipefail

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
image_dir="$script_dir/../share/images"
for tool in rsvg-convert magick python3; do
    command -v "$tool" >/dev/null || { echo "Missing dependency: $tool" >&2; exit 1; }
done
render_dir=$(mktemp -d)
trap 'rm -rf -- "$render_dir"' EXIT

for variant in light dark; do
    suffix=''
    background='#f1f5f9'
    if [[ "$variant" == dark ]]; then
        suffix='-dark'
        background='#243449'
    fi
    for icon in action comment downtime flapping status2; do
        asset="$icon$suffix"
        if [[ "$icon" == flapping ]]; then
            python3 "$script_dir/render-flapping-frames.py" "$image_dir/$asset.svg" "$render_dir"
            # Full transparent frames must clear the preceding arrow positions.
            magick -delay 5 -dispose Background "$render_dir/$asset-"*.png \
                -channel A -threshold '50%' +channel -loop 0 "$render_dir/$asset.gif"
            continue
        fi
        rsvg-convert "$image_dir/$asset.svg" -o "$render_dir/$asset.png"
        # GIF has binary transparency. Only the dark graph keeps its surface;
        # preserve stroke colors at transparent edges for the other icons.
        if [[ "$icon" == action && "$variant" == dark ]]; then
            magick "$render_dir/$asset.png" -background "$background" -alpha background \
                -channel A -threshold '50%' +channel "$render_dir/$asset.gif"
        else
            magick "$render_dir/$asset.png" \
                -channel A -threshold '50%' +channel "$render_dir/$asset.gif"
        fi
    done
done

for icon in action comment downtime flapping status2; do
    cp -- "$render_dir/$icon.gif" "$image_dir/$icon.gif"
    cp -- "$render_dir/$icon-dark.gif" "$image_dir/$icon-dark.gif"
done
