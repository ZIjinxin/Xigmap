#!/bin/sh
# Build a modified /usr/local filesystem for the Full installer.
# Run in a FreeBSD shell with root privileges. Inputs: mdlocal.xz, overlay.tar.
set -eu
test "$#" -eq 3 || { echo 'usage: sh mkpayload.sh mdlocal.xz overlay.tar output.xz' >&2; exit 2; }
src=$(realpath "$1")
overlay=$(realpath "$2")
dest=$(realpath "$(dirname "$3")")/$(basename "$3")
test ! -e "$dest"
tmp=$(mktemp -d "$(dirname "$dest")/ridger.XXXXXX")
dev=
mounted=0
cleanup() {
    if [ "$mounted" -eq 1 ]; then umount "$tmp/fs" || return; fi
    if [ -n "$dev" ]; then mdconfig -d -u "$dev" || return; fi
    rm -rf "$tmp"
}
trap cleanup EXIT
xz -dc "$src" > "$tmp/mdlocal"
mkdir "$tmp/fs"
dev=$(mdconfig -a -t vnode -f "$tmp/mdlocal")
mount -t ufs "/dev/$dev" "$tmp/fs"
mounted=1
tar -xf "$overlay" -C "$tmp/fs" --no-same-owner
php -l "$tmp/fs/www/login.php"
php -l "$tmp/fs/www/index.php"
sync
umount "$tmp/fs"
mounted=0
mdconfig -d -u "$dev"
dev=
xz -3 -T2 -c "$tmp/mdlocal" > "$tmp/rebuilt.xz"
xz -t "$tmp/rebuilt.xz"
mv "$tmp/rebuilt.xz" "$dest"
