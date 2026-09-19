#!/usr/bin/env bash
# doc 71 §5 — copy the raster pictures the Flutter app and the dashboards ship into this repo's
# `public/shipped/`, where the media library can list them as «Available app images».
#
# Copying is not adding: nothing lands in the library until an Admin adds it from the screen.
# SVG is skipped on purpose — an SVG served from the API's own origin can carry script.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
app="${CR7_APP_DIR:-$root/../cr7_delivery}"
dash="${CR7_DASHBOARDS_DIR:-$root/../cr7-dashboards}"

copy() { # <from> <to-dir> <pattern...>
  local from="$1" to="$2"; shift 2
  [ -d "$from" ] || { echo "skip (missing): $from"; return 0; }
  mkdir -p "$to"
  for pattern in "$@"; do
    find "$from" -maxdepth 1 -type f -iname "$pattern" -exec cp -p {} "$to"/ \;
  done
}

copy "$app/assets/images/illustrations" "$root/public/shipped/app/illustrations" '*.webp' '*.png' '*.jpg'
copy "$app/assets/icons/brand" "$root/public/shipped/app/brand" '*.png' '*.webp'
for d in "$dash"/apps/*/public; do
  [ -d "$d" ] && copy "$d" "$root/public/shipped/dashboard" '*.png' '*.webp' '*.jpg'
done

echo "shipped assets synced into public/shipped/"
