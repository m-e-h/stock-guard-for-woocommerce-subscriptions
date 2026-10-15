#!/usr/bin/env bash
set -euo pipefail

plugin_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_slug='stock-guard-for-woocommerce-subscriptions'
plugin_version="$(sed -n 's/^ \* Version: //p' "$plugin_dir/$plugin_slug.php")"
stable_tag="$(sed -n 's/^Stable tag: //p' "$plugin_dir/readme.txt")"

if [[ ! "$plugin_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ || "$plugin_version" != "$stable_tag" ]]; then
    printf 'Plugin version and readme stable tag must match and use x.y.z.\n' >&2
    exit 1
fi

staging_dir="$(mktemp -d)"
trap 'rm -rf -- "$staging_dir"' EXIT
mkdir -p -- "$staging_dir/$plugin_slug" "$plugin_dir/dist"
cp -- "$plugin_dir/$plugin_slug.php" "$plugin_dir/readme.txt" "$plugin_dir/LICENSE" "$staging_dir/$plugin_slug/"
cp -R -- "$plugin_dir/includes" "$plugin_dir/templates" "$staging_dir/$plugin_slug/"

(
    cd -- "$staging_dir"
    zip -q -X -r "$staging_dir/release.zip" "$plugin_slug"
)
mv -- "$staging_dir/release.zip" "$plugin_dir/dist/$plugin_slug-$plugin_version.zip"
printf '%s\n' "$plugin_dir/dist/$plugin_slug-$plugin_version.zip"
