#!/usr/bin/env bash
#
# Regenerates the brand images from their HTML sources.
#
#   resources/og/baseline-og.html  ->  public/og/baseline-og.png   (1200x630)
#   resources/og/favicon.html      ->  public/apple-touch-icon.png (180x180)
#                                  ->  public/favicon.ico          (16/32/48)
#
# The font files under public/build/assets are content hashed, so their names
# change on every `npm run build`. The HTML sources carry __FONT_*__ placeholders
# that this script resolves against whatever is currently built.
#
# Usage: resources/og/render.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ASSETS="$ROOT/public/build/assets"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

if [ ! -x "$CHROME" ]; then
    echo "Google Chrome not found at $CHROME" >&2
    exit 1
fi

# Resolve the current hashed path for a font family and weight.
font() {
    local match
    match="$(find "$ASSETS" -name "$1-$2-normal-*.woff2" -print -quit)"

    if [ -z "$match" ]; then
        echo "No built font matching $1-$2-normal-*.woff2 in $ASSETS. Run 'npm run build' first." >&2
        exit 1
    fi

    echo "file://$match"
}

SPACE_GROTESK_700="$(font space-grotesk 700)"
ONEST_500="$(font onest 500)"
PLEX_MONO_600="$(font ibm-plex-mono 600)"

# render <source html> <path under public/> <width> <height>
render() {
    local source="$1" output="public/$2" width="$3" height="$4"

    sed \
        -e "s|__FONT_SPACE_GROTESK_700__|$SPACE_GROTESK_700|g" \
        -e "s|__FONT_ONEST_500__|$ONEST_500|g" \
        -e "s|__FONT_PLEX_MONO_600__|$PLEX_MONO_600|g" \
        "$ROOT/resources/og/$source" > "$WORK/$source"

    mkdir -p "$(dirname "$ROOT/$output")"

    "$CHROME" \
        --headless \
        --disable-gpu \
        --no-sandbox \
        --hide-scrollbars \
        --allow-file-access-from-files \
        --force-device-scale-factor=1 \
        --default-background-color=00000000 \
        --window-size="$width,$height" \
        --screenshot="$ROOT/$output" \
        "file://$WORK/$source" 2>/dev/null

    echo "$output ($(du -h "$ROOT/$output" | cut -f1))"
}

render baseline-og.html og/baseline-og.png 1200 630
render favicon.html apple-touch-icon.png 180 180

php "$ROOT/resources/og/make-favicon.php"
echo "public/favicon.ico ($(du -h "$ROOT/public/favicon.ico" | cut -f1))"
