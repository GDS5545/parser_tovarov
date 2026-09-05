#!/usr/bin/env bash
#
# Packages the WordPress plugin (not the worker/ service, which deploys
# separately — see worker/README.md and worker/Dockerfile) into a
# distributable ZIP. Builds in a temp directory OUTSIDE the repo so a
# --no-dev composer install never touches (or gets confused by) this
# working tree's own vendor/, and so the copy step can't recurse into
# its own output (spec §68, Stage 16).
#
# Usage: ./build.sh
# Output: universal-woo-scraper-<version>.zip in the repo root.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

PLUGIN_SLUG="universal-woo-scraper"

VERSION=$(grep -m1 '^ \* Version:' universal-woo-scraper.php | sed -E 's/.*Version:[[:space:]]*//')
if [ -z "$VERSION" ]; then
  echo "Could not read plugin version from universal-woo-scraper.php" >&2
  exit 1
fi

echo "Packaging ${PLUGIN_SLUG} v${VERSION}..."

TMP_ROOT="$(mktemp -d)"
BUILD_DIR="${TMP_ROOT}/${PLUGIN_SLUG}"
trap 'rm -rf "$TMP_ROOT"' EXIT

mkdir -p "$BUILD_DIR"

# Only what a WordPress install actually needs to run the plugin —
# excludes the worker/ service, tests/, dev tooling configs, and VCS/CI
# metadata. tar --exclude piped into the temp dir avoids depending on
# rsync (not always preinstalled) while still excluding cleanly in one pass.
tar \
  --exclude='./.git' \
  --exclude='./.github' \
  --exclude='./worker' \
  --exclude='./tests' \
  --exclude='./build' \
  --exclude='./node_modules' \
  --exclude='./vendor' \
  --exclude='./.phpunit.result.cache' \
  --exclude='./phpunit.xml.dist' \
  --exclude='./composer.lock' \
  --exclude='./.gitignore' \
  --exclude='*.zip' \
  -cf - . | tar -xf - -C "$BUILD_DIR"

echo "Installing production Composer dependencies into the build copy..."
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$BUILD_DIR"

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
rm -f "$SCRIPT_DIR/$ZIP_NAME"
(cd "$TMP_ROOT" && zip -rq "${SCRIPT_DIR}/${ZIP_NAME}" "${PLUGIN_SLUG}")

echo "Built ${ZIP_NAME} — upload this via WordPress → Plugins → Add New → Upload Plugin."
