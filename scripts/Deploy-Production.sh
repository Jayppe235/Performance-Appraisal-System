#!/usr/bin/env bash
set -euo pipefail

deploy_root="${1:?deploy root required}"
archive="${2:?release archive required}"
release_id="${3:?release id required}"

[[ "$deploy_root" == /* && "$deploy_root" != "/" ]] || { echo "Unsafe deploy root" >&2; exit 1; }
[[ "$release_id" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "Unsafe release id" >&2; exit 1; }
[[ -f "$archive" ]] || { echo "Release archive not found" >&2; exit 1; }

release_dir="$deploy_root/releases/$release_id"
mkdir -p "$deploy_root/releases" "$deploy_root/shared/uploads"
[[ ! -e "$release_dir" ]] || { echo "Release already exists" >&2; exit 1; }
mkdir "$release_dir"
tar -xzf "$archive" -C "$release_dir"

[[ -f "$release_dir/index.html" && -f "$release_dir/api/health.php" ]] || {
  echo "Incomplete release" >&2
  exit 1
}

rm -rf "$release_dir/assets/uploads"
ln -s "$deploy_root/shared/uploads" "$release_dir/assets/uploads"

if [[ -L "$deploy_root/current" ]]; then
  previous_target="$(readlink "$deploy_root/current")"
  ln -sfn "$previous_target" "$deploy_root/previous"
fi
ln -sfn "$release_dir" "$deploy_root/current"
rm -f "$archive"

echo "Activated $release_id"
