#!/usr/bin/env bash
# Build FreeSaaS release artifacts.
#
# Always (monorepo release):
#   - freesaas-<ver>.zip              FULL tree (new UpdateEngine; private modules excluded)
#   - freesaas-core-<ver>.zip         platform only — bridge for old 0.3.x engines
#   - freesaas-module-updates-<ver>.zip  updates UI (old CORE_ALLOW skips modules/updates/)
#   - freesaas-catalog.json           core + updates (old check/apply)
#   - freesaas-manifest.json          points at full zip (kind=full); core also listed in catalog
#
# External module (other repo workflow):
#   bin/build-release-artifacts.sh --module=likespa
#
# Usage:
#   PRIVATE_MODULES=likespa bin/build-release-artifacts.sh
#   bin/build-release-artifacts.sh --module=id [--channel=stable]

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
    $cat=["channel"=>$argv[2],"created_at"=>gmdate("c"),"packages"=>[[
      "id"=>$argv[3],"kind"=>"module","module"=>$argv[3],"version"=>$argv[1],
      "artifact"=>$argv[4],"url"=>$argv[4],"sha256"=>$argv[5],
    ]]];
    file_put_contents(dirname($argv[6])."/freesaas-catalog.json",
      json_encode($cat, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
  ' "$mver" "$CHANNEL" "$mid" "$name" "$sha" "$DIST/freesaas-manifest.json"
  echo "Built $zip (sha256=$sha)"
  exit 0
fi

# Clean previous release zips/manifests in dist (keep uploads if any other files)
rm -f "$DIST"/freesaas-core-*.zip \
  "$DIST"/freesaas-[0-9]*.zip \
  "$DIST"/freesaas-module-updates-*.zip \
  "$DIST"/freesaas-catalog.json \
  "$DIST"/freesaas-manifest.json

# --- 1) Platform core zip (old UpdateEngine bridge) ---
core_name="freesaas-core-${VERSION}.zip"
core_zip="$DIST/$core_name"
stage="$(mktemp -d "${TMPDIR:-/tmp}/freesaas-core.XXXXXX")"
mkdir -p "$stage"
# Include modules/updates in the zip (new engines overlay full tree; old CORE_ALLOW
# still needs a separate updates module package — built below).
for path in app bin modules/core modules/updates docs assets/css assets/js index.php VERSION .htaccess AGENTS.md; do
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
( cd "$stage" && zip -qr "$core_zip" . )
rm -rf "$stage"
core_sha="$(sha256_file "$core_zip")"
echo "Built $core_zip (sha256=$core_sha)"

# --- 2) updates module zip (old CORE_ALLOW cannot write modules/updates from core) ---
upd_ver="$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo $j["version"]??"0.0.0";' "$ROOT/modules/updates/module.json")"
upd_name="freesaas-module-updates-${upd_ver}.zip"
upd_zip="$DIST/$upd_name"
rm -f "$upd_zip"
( cd "$ROOT/modules/updates" && zip -qr "$upd_zip" . -x '*.DS_Store' -x '*/.DS_Store' )
upd_sha="$(sha256_file "$upd_zip")"
echo "Built $upd_zip (sha256=$upd_sha)"

# --- 3) Full monorepo zip (new UpdateEngine) ---
full_name="freesaas-${VERSION}.zip"
full_zip="$DIST/$full_name"
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
IFS=',' read -r -a priv <<< "$PRIVATE_MODULES"
for mid in "${priv[@]}"; do
  mid="$(echo "$mid" | tr -d '[:space:]')"
  [[ -z "$mid" ]] && continue
  rm -rf "$stage/modules/$mid"
  echo "Excluded private module from full zip: $mid"
done
find "$stage" -name '.DS_Store' -delete 2>/dev/null || true
( cd "$stage" && zip -qr "$full_zip" . )
rm -rf "$stage"
full_sha="$(sha256_file "$full_zip")"
echo "Built $full_zip (sha256=$full_sha)"

# --- 4) Catalog (old engines) + manifest (new prefers full) ---
php -r '
  $channel = $argv[1];
  $ver = $argv[2];
  $coreName = $argv[3]; $coreSha = $argv[4];
  $updName = $argv[5]; $updVer = $argv[6]; $updSha = $argv[7];
  $fullName = $argv[8]; $fullSha = $argv[9];
  $dist = $argv[10];

  $catalog = [
    "channel" => $channel,
    "created_at" => gmdate("c"),
    "packages" => [
      [
        "id" => "core",
        "kind" => "core",
        "version" => $ver,
        "artifact" => $coreName,
        "url" => $coreName,
        "sha256" => $coreSha,
      ],
      [
        "id" => "updates",
        "kind" => "module",
        "module" => "updates",
        "version" => $updVer,
        "artifact" => $updName,
        "url" => $updName,
        "sha256" => $updSha,
      ],
    ],
  ];
  file_put_contents($dist."/freesaas-catalog.json",
    json_encode($catalog, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");

  $manifest = [
    "version" => $ver,
    "channel" => $channel,
    "kind" => "full",
    "artifact" => $fullName,
    "url" => $fullName,
    "sha256" => $fullSha,
    "bridge" => [
      "core_artifact" => $coreName,
      "core_sha256" => $coreSha,
      "updates_artifact" => $updName,
      "updates_sha256" => $updSha,
      "note" => "Old UpdateEngine (<=0.3.2) uses freesaas-catalog.json + core/updates zips; new engine prefers this full zip.",
    ],
  ];
  file_put_contents($dist."/freesaas-manifest.json",
    json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");

  fwrite(STDERR, "Catalog packages: ".count($catalog["packages"])."\n");
' "$CHANNEL" "$VERSION" \
  "$core_name" "$core_sha" \
  "$upd_name" "$upd_ver" "$upd_sha" \
  "$full_name" "$full_sha" \
  "$DIST"

echo "Wrote $DIST/freesaas-catalog.json"
echo "Wrote $DIST/freesaas-manifest.json"
