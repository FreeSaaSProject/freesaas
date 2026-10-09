#!/usr/bin/env bash
# Build FreeSaaS release packages for UpdateEngine:
#   - freesaas-core-<ver>.zip              platform only (app/bin/modules/core/…)
#   - freesaas-module-<id>-<ver>.zip       each public product module
#   - freesaas-catalog.json                package index (sha256 + versions)
#   - freesaas-manifest.json               core-only compat manifest
#
# Usage:
#   bin/build-release-artifacts.sh
#   bin/build-release-artifacts.sh --module=likespa
#   PRIVATE_MODULES=likespa,foo bin/build-release-artifacts.sh

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

is_private() {
  local mid="$1" p
  IFS=',' read -r -a priv <<< "$PRIVATE_MODULES"
  for p in "${priv[@]}"; do
    p="$(echo "$p" | tr -d '[:space:]')"
    [[ "$p" == "$mid" ]] && return 0
  done
  return 1
}

write_catalog() {
  local lines_file="$1"
  php -r '
    $channel = $argv[1];
    $linesFile = $argv[2];
    $outCatalog = $argv[3];
    $outManifest = $argv[4];
    $packages = [];
    $core = null;
    foreach (file($linesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $p = explode("|", $line);
      if (count($p) < 4) continue;
      $artifact = $p[0];
      $version = $p[1];
      $kind = $p[2];
      $sha = $p[3];
      $id = $kind === "core" ? "core" : ($p[4] ?? "");
      if ($id === "") continue;
      $row = [
        "id" => $id,
        "kind" => $kind,
        "version" => $version,
        "artifact" => $artifact,
        "url" => $artifact,
        "sha256" => $sha,
      ];
      if ($kind === "module") {
        $row["module"] = $id;
      }
      $packages[] = $row;
      if ($kind === "core") {
        $core = $row;
      }
    }
    $catalog = [
      "channel" => $channel,
      "created_at" => gmdate("c"),
      "packages" => $packages,
    ];
    file_put_contents($outCatalog, json_encode($catalog, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    if ($core !== null) {
      $man = [
        "version" => $core["version"],
        "channel" => $channel,
        "kind" => "core",
        "artifact" => $core["artifact"],
        "url" => $core["artifact"],
        "sha256" => $core["sha256"],
      ];
      file_put_contents($outManifest, json_encode($man, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    } elseif ($packages !== []) {
      // Single-module build: also write a module-shaped freesaas-manifest.json
      $m = $packages[0];
      file_put_contents($outManifest, json_encode([
        "version" => $m["version"],
        "channel" => $channel,
        "kind" => "module",
        "module" => $m["id"],
        "artifact" => $m["artifact"],
        "url" => $m["artifact"],
        "sha256" => $m["sha256"],
      ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    }
    fwrite(STDERR, "Catalog packages: ".count($packages)."\n");
  ' "$CHANNEL" "$lines_file" "$DIST/freesaas-catalog.json" "$DIST/freesaas-manifest.json"
}

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT

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
  echo "Built $zip (sha256=$sha)"
  echo "$name|$mver|module|$sha|$mid" >> "$tmp"
  write_catalog "$tmp"
  exit 0
fi

# --- Full public release: platform core + public modules ---
name="freesaas-core-${VERSION}.zip"
zip="$DIST/$name"
rm -f "$zip"
stage="$(mktemp -d "${TMPDIR:-/tmp}/freesaas-core.XXXXXX")"
mkdir -p "$stage"
for path in app bin modules/core docs assets/css assets/js index.php VERSION .htaccess AGENTS.md; do
  if [[ -e "$ROOT/$path" ]]; then
    mkdir -p "$stage/$(dirname "$path")"
    if [[ -d "$ROOT/$path" ]]; then
      rm -rf "$stage/$path"
      cp -R "$ROOT/$path" "$stage/$path"
    else
      cp "$ROOT/$path" "$stage/$path"
    fi
  fi
done
find "$stage" -name '.DS_Store' -delete 2>/dev/null || true
( cd "$stage" && zip -qr "$zip" . )
rm -rf "$stage"
sha="$(sha256_file "$zip")"
echo "Built $zip (sha256=$sha)"
echo "$name|$VERSION|core|$sha" >> "$tmp"

for mod_json in "$ROOT"/modules/*/module.json; do
  mid="$(basename "$(dirname "$mod_json")")"
  [[ "$mid" == "core" || "$mid" == "updates" ]] && continue
  if is_private "$mid"; then
    echo "Skip private module: $mid"
    continue
  fi
  mver="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"0.0.0";' "$mod_json")"
  name="freesaas-module-${mid}-${mver}.zip"
  zip="$DIST/$name"
  rm -f "$zip"
  ( cd "$ROOT/modules/$mid" && zip -qr "$zip" . -x '*.DS_Store' -x '*/.DS_Store' )
  sha="$(sha256_file "$zip")"
  echo "Built $zip (sha256=$sha)"
  echo "$name|$mver|module|$sha|$mid" >> "$tmp"
done

write_catalog "$tmp"
echo "Wrote $DIST/freesaas-catalog.json"
