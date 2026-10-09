#!/usr/bin/env bash
# Build FreeSaaS release zips + freesaas-manifest.json for UpdateEngine.
# Usage:
#   bin/build-release-artifacts.sh              # core (+ optional private modules)
#   bin/build-release-artifacts.sh --module=likespa
#   PRIVATE_MODULES=likespa bin/build-release-artifacts.sh
#
# Output: dist/freesaas-core-<ver>.zip, dist/freesaas-manifest.json
#         dist/freesaas-module-<id>-<ver>.zip (+ per-module manifest when --module=)

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="${DIST_DIR:-$ROOT/dist}"
PRIVATE_MODULES="${PRIVATE_MODULES:-likespa}"
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
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION" 2>/dev/null || true)"
if [[ -z "$VERSION" ]]; then
  VERSION="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"0.0.0";' "$ROOT/modules/core/module.json")"
fi

sha256_file() {
  if command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$1" | awk '{print $1}'
  else
    sha256sum "$1" | awk '{print $1}'
  fi
}

write_manifest() {
  local kind="$1" version="$2" url_name="$3" sha="$4" module_id="${5:-}"
  local out="$6"
  if [[ "$kind" == "module" ]]; then
    php -r '
      $d=[
        "version"=>$argv[1],
        "channel"=>$argv[2],
        "kind"=>"module",
        "module"=>$argv[3],
        "artifact"=>$argv[4],
        "url"=>$argv[4],
        "sha256"=>$argv[5],
      ];
      file_put_contents($argv[6], json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    ' "$version" "$CHANNEL" "$module_id" "$url_name" "$sha" "$out"
  else
    php -r '
      $d=[
        "version"=>$argv[1],
        "channel"=>$argv[2],
        "kind"=>"core",
        "artifact"=>$argv[3],
        "url"=>$argv[3],
        "sha256"=>$argv[4],
      ];
      file_put_contents($argv[5], json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    ' "$version" "$CHANNEL" "$url_name" "$sha" "$out"
  fi
}

build_core() {
  local name="freesaas-core-${VERSION}.zip"
  local zip="$DIST/$name"
  rm -f "$zip"
  local stage
  stage="$(mktemp -d "${TMPDIR:-/tmp}/freesaas-core.XXXXXX")"
  # rsync-like copy excluding secrets, var, private modules, VCS, dist
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
  # Drop private module trees from public core artifact
  IFS=',' read -r -a priv <<< "$PRIVATE_MODULES"
  for mid in "${priv[@]}"; do
    mid="$(echo "$mid" | tr -d '[:space:]')"
    [[ -z "$mid" ]] && continue
    rm -rf "$stage/modules/$mid"
  done
  # Keep example ini files
  (
    cd "$stage"
    zip -qr "$zip" .
  )
  rm -rf "$stage"
  local sha
  sha="$(sha256_file "$zip")"
  write_manifest "core" "$VERSION" "$name" "$sha" "" "$DIST/freesaas-manifest.json"
  echo "Built $zip (sha256=$sha)"
  echo "Manifest $DIST/freesaas-manifest.json"
}

build_module() {
  local mid="$1"
  local mod_dir="$ROOT/modules/$mid"
  if [[ ! -f "$mod_dir/module.json" ]]; then
    echo "Missing modules/$mid/module.json" >&2
    exit 1
  fi
  local mver
  mver="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"0.0.0";' "$mod_dir/module.json")"
  local name="freesaas-module-${mid}-${mver}.zip"
  local zip="$DIST/$name"
  rm -f "$zip"
  (
    cd "$mod_dir"
    zip -qr "$zip" . -x '*.DS_Store' -x '*/.DS_Store'
  )
  local sha
  sha="$(sha256_file "$zip")"
  local man="$DIST/freesaas-manifest-${mid}.json"
  write_manifest "module" "$mver" "$name" "$sha" "$mid" "$man"
  # Convenience alias when building a single module release
  cp "$man" "$DIST/freesaas-manifest.json"
  echo "Built $zip (sha256=$sha)"
  echo "Manifest $man"
}

if [[ -n "$ONLY_MODULE" ]]; then
  build_module "$ONLY_MODULE"
else
  build_core
fi
