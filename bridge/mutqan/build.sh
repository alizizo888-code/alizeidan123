#!/bin/sh
# Packs the plugin into a zip wp-admin can install. Tests run first: a plugin that fails its own
# tests has no business going near a live face.
#
# WordPress expects one folder at the top of the zip, named after the plugin, so the files are
# staged into mutqan/ rather than zipped from here.
set -e
cd "$(dirname "$0")"
php tests/run.php
php tests/integration.php
out="${1:-/tmp}/mutqan.zip"
stage="$(mktemp -d)"
mkdir "$stage/mutqan"
cp -R mutqan.php inc README.md "$stage/mutqan/"
rm -f "$out"
(cd "$stage" && zip -rq "$out" mutqan -x '*.DS_Store')
rm -rf "$stage"
echo "$out"
unzip -l "$out"
