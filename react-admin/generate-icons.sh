#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# generate-icons.sh - Phase 1 PWA icon generation
#
# Requires: ImageMagick (convert) or sharp-cli
#
# Place your master logo at: public/icons/master.png
#   - Must be at least 512×512 pixels
#   - PNG with transparency (or white background)
#   - Square aspect ratio
#
# Run: chmod +x generate-icons.sh && ./generate-icons.sh
# ─────────────────────────────────────────────────────────────────────────────

MASTER="public/icons/master.png"
OUT="public/icons"

if [ ! -f "$MASTER" ]; then
  echo "Error: Place your master icon at $MASTER (512×512 PNG minimum)"
  exit 1
fi

echo "Generating PWA icons from $MASTER..."

# Standard sizes
for size in 72 96 128 144 152 192 384 512; do
  convert "$MASTER" -resize "${size}x${size}" "$OUT/icon-${size}.png"
  echo "  ✓ icon-${size}.png"
done

# Apple Touch Icon (180×180 - no transparency, white background)
convert "$MASTER" -resize "180x180" \
  -background white -flatten \
  "$OUT/apple-touch-icon.png"
echo "  ✓ apple-touch-icon.png"

# Maskable icon - add 20% padding (safe zone is inner 80%)
# The background colour should match your brand
convert "$MASTER" \
  -background "#2563EB" \
  -gravity center \
  -extent "640x640" \
  -resize "512x512" \
  "$OUT/icon-512-maskable.png"
echo "  ✓ icon-512-maskable.png"

# Badge icon for push notifications (monochrome, small)
convert "$MASTER" -resize "72x72" -colorspace Gray "$OUT/badge-72.png"
echo "  ✓ badge-72.png"

# App shortcut icons (simplified versions)
for shortcut in pos tasks comms; do
  convert "$MASTER" -resize "192x192" "$OUT/shortcut-${shortcut}.png"
  echo "  ✓ shortcut-${shortcut}.png"
done

# OG image for social sharing (1200×630)
convert "$MASTER" \
  -background white \
  -gravity center \
  -extent "1200x630" \
  "$OUT/og-image.png"
echo "  ✓ og-image.png"

echo ""
echo "All icons generated in $OUT/"
echo ""
echo "NEXT: Verify maskable icon using https://maskable.app/"
echo "      The content should be fully visible within the inner circle."