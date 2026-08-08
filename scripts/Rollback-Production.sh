#!/usr/bin/env bash
set -euo pipefail

deploy_root="${1:?deploy root required}"
[[ "$deploy_root" == /* && "$deploy_root" != "/" ]] || { echo "Unsafe deploy root" >&2; exit 1; }
[[ -L "$deploy_root/previous" ]] || { echo "No previous release is available" >&2; exit 1; }

rollback_target="$(readlink "$deploy_root/previous")"
[[ -d "$rollback_target" ]] || { echo "Previous release is missing" >&2; exit 1; }
ln -sfn "$rollback_target" "$deploy_root/current"
echo "Rolled back to $rollback_target"
