#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

VERSION="$(tr -d '[:space:]' < VERSION)"
NS_SUFFIX="v$(echo "$VERSION" | tr '.' '_')"

echo "Building GumPress $VERSION (namespace suffix: GumPress\\$NS_SUFFIX)"

echo "==> Linting source"
find gumpress -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

echo "==> Preparing dist/"
rm -rf dist
mkdir -p dist

php bin/build.php "$VERSION" "$NS_SUFFIX" dist

echo "==> Linting dist output"
find dist -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

if [[ "${1:-}" == "--obfuscate" ]]; then
    if ! command -v yakpro-po >/dev/null 2>&1; then
        echo "ERROR: yakpro-po not found on PATH. Install it, or omit --obfuscate." >&2
        exit 1
    fi
    if [[ ! -f ../phpz/encode.php ]]; then
        echo "ERROR: ../phpz/encode.php not found (expects a sibling 'phpz' checkout). Omit --obfuscate to skip." >&2
        exit 1
    fi

    echo "==> Obfuscating dist/GumPress.php"
    mkdir -p dist-obfuscated
    yakpro-po dist/GumPress.php \
        --no-obfuscate-class-name --no-obfuscate-namespace-name \
        --no-obfuscate-method-name --no-obfuscate-constant-name --no-obfuscate-function-name \
        --no-shuffle-statements -o dist-obfuscated/GumPress.php
    php ../phpz/encode.php ./dist-obfuscated/GumPress.php
fi

echo
echo "Done."
echo "  Drop-in folder: dist/gumpress/"
echo "  Single file:    dist/GumPress.php"
