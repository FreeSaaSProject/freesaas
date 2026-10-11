#!/usr/bin/env bash
# Build FreeSaaS release artifacts (new UpdateEngine only):
#   - freesaas-<ver>.zip          FULL tree (private modules excluded)
#   - freesaas-manifest.json      {kind:"full", version, sha256, artifact}
#   - freesaas-catalog.json       copy of modules-catalog.json (installable modules)
#   - modules-catalog.json        regenerated at repo root (also inside the zip)
#
# External module from another repo:
#   bin/build-release-artifacts.sh --module=id
#
# Usage:
#   PRIVATE_MODULES= bin/build-release-artifacts.sh
#   PRIVATE_THEMES=likespa bin/build-release-artifacts.sh
#   bin/build-release-artifacts.sh --module=likespa [--channel=stable]
#
# Defaults: ship product modules (incl. likespa); exclude instance storefront themes.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${DIST_DIR:-$ROOT/dist}"
PRIVATE_MODULES="${PRIVATE_MODULES:-}"
PRIVATE_THEMES="${PRIVATE_THEMES:-likespa}"
ONLY_MODULE=""
CHANNEL="${CHANNEL:-stable}"

for arg in "$@"; do
  case "$arg" in
    --module=*) ONLY_MODULE="${arg#--module=}" ;;
    --channel=*) CHANNEL="${arg#--channel=}" ;;
    --help|-h)
      echo "Usage: $0 [--module=id] [--channel=stable]"
      exit 0
      ;;
  esac
done

mkdir -p "$DIST"
# Canonical platform version: modules/core/module.json (root VERSION is derived).
VERSION="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo trim((string)($j["version"]??""));' "$ROOT/modules/core/module.json")"
if [[ -z "$VERSION" ]]; then
  echo "error: modules/core/module.json missing version" >&2
  exit 1
fi
printf '%s\n' "$VERSION" > "$ROOT/VERSION"

sha256_file() {
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{print $1}'
  else
    sha256sum "$1" | awk '{print $1}'
  fi
}

if [[ -n "$ONLY_MODULE" ]]; then
  mid="$ONLY_MODULE"
  mod_dir="$ROOT/modules/$mid"
  if [[ ! -f "$mod_dir/module.json" ]]; then
    echo "Missing modules/$mid/module.json" >&2
    exit 1
  fi
  mver="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"0.0.0";' "$mod_dir/module.json")"
  name="freesaas-module-${mid}-${mver}.zip"
  zip="$DIST/$name"
  rm -f "$zip"
  ( cd "$mod_dir" && zip -qr "$zip" . -x '*.DS_Store' -x '*/.DS_Store' )
  sha="$(sha256_file "$zip")"
  php -r '
    $d=["version"=>$argv[1],"channel"=>$argv[2],"kind"=>"module","module"=>$argv[3],
        "artifact"=>$argv[4],"url"=>$argv[4],"sha256"=>$argv[5]];
    file_put_contents($argv[6], json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
  ' "$mver" "$CHANNEL" "$mid" "$name" "$sha" "$DIST/freesaas-manifest.json"
  echo "Built $zip (sha256=$sha)"
  exit 0
fi

rm -f "$DIST"/freesaas-core-*.zip \
  "$DIST"/freesaas-[0-9]*.zip \
  "$DIST"/freesaas-module-*.zip \
  "$DIST"/freesaas-catalog.json \
  "$DIST"/freesaas-manifest.json

# Remote install catalog (Contents API + release asset).
PRIVATE_MODULES="$PRIVATE_MODULES" php "$ROOT/bin/build-modules-catalog.php"
PRIVATE_MODULES="$PRIVATE_MODULES" php "$ROOT/bin/build-modules-catalog.php" --out="$DIST/freesaas-catalog.json"

name="freesaas-${VERSION}.zip"
zip="$DIST/$name"
stage="$(mktemp -d "${TMPDIR:-/tmp}/freesaas-full.XXXXXX")"
(
  cd "$ROOT"
  tar -cf - \
    --exclude='./cfg/config.ini' \
    --exclude='./cfg/modules/*.ini' \
    --exclude='./cfg/updates.ini' \
    --exclude='./cfg/updates.state.json' \
    --exclude='./var' \
    --exclude='./log/*.log' \
    --exclude='./dist' \
    --exclude='./.git' \
    --exclude='./.DS_Store' \
    --exclude='./assets/uploads/logo/*' \
    . \
  | (cd "$stage" && tar -xf -)
)

if [[ -n "${PRIVATE_MODULES//[[:space:],]/}" ]]; then
  IFS=',' read -r -a priv <<< "$PRIVATE_MODULES"
  for mid in "${priv[@]}"; do
    mid="$(echo "$mid" | tr -d '[:space:]')"
    [[ -z "$mid" ]] && continue
    rm -rf "$stage/modules/$mid"
    echo "Excluded private module from zip: $mid"
  done
fi
if [[ -n "${PRIVATE_THEMES//[[:space:],]/}" ]]; then
  IFS=',' read -r -a priv_themes <<< "$PRIVATE_THEMES"
  for tid in "${priv_themes[@]}"; do
    tid="$(echo "$tid" | tr -d '[:space:]')"
    [[ -z "$tid" ]] && continue
    [[ "$tid" == "default" ]] && continue
    rm -rf "$stage/themes/$tid"
    echo "Excluded private theme from zip: $tid"
  done
fi
find "$stage" -name '.DS_Store' -delete 2>/dev/null || true
( cd "$stage" && zip -qr "$zip" . )
rm -rf "$stage"
sha="$(sha256_file "$zip")"
php -r '
  $d=["version"=>$argv[1],"channel"=>$argv[2],"kind"=>"full",
      "artifact"=>$argv[3],"url"=>$argv[3],"sha256"=>$argv[4]];
  file_put_contents($argv[5], json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "$VERSION" "$CHANNEL" "$name" "$sha" "$DIST/freesaas-manifest.json"
echo "Built $zip (sha256=$sha)"
echo "Manifest $DIST/freesaas-manifest.json"
