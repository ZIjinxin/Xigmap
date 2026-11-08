#!/bin/sh
# Replace selected files in an existing FreeBSD UFS payload.
# Usage: sh mkpayload.sh mfsroot.gz|mdlocal.xz overlay.tar output.gz|output.xz
set -eu
test "$#" -eq 3 || exit 2
src=$(realpath "$1"); overlay=$(realpath "$2")
dest=$(realpath "$(dirname "$3")")/$(basename "$3")
test ! -e "$dest"
case "$src:$dest" in
    *.gz:*.gz) codec=gzip ;;
    *.xz:*.xz) codec=xz ;;
    *) echo 'Input and output must use the same supported compression' >&2; exit 2 ;;
esac
tmp=$(mktemp -d "$(dirname "$dest")/sharecheck.XXXXXX")
dev=; mounted=0
cleanup() {
    if [ "$mounted" -eq 1 ]; then umount "$tmp/fs" || return; fi
    if [ -n "$dev" ]; then mdconfig -d -u "$dev" || return; fi
    rm -rf "$tmp"
}
trap cleanup EXIT
"$codec" -dc "$src" > "$tmp/image"
mkdir "$tmp/fs"
dev=$(mdconfig -a -t vnode -f "$tmp/image")
mount -t ufs "/dev/$dev" "$tmp/fs"; mounted=1
tar -xf "$overlay" -C "$tmp/fs" --no-same-owner
tar -tf "$overlay" > "$tmp/entries"
while IFS= read -r entry; do
    case "$entry" in *.php) php -l "$tmp/fs/$entry" ;; esac
done < "$tmp/entries"
sync
umount "$tmp/fs"; mounted=0
mdconfig -d -u "$dev"; dev=
case "$codec" in
    gzip) gzip -n -6 -c "$tmp/image" > "$tmp/result" ;;
    xz) xz -3 -T2 -c "$tmp/image" > "$tmp/result" ;;
esac
"$codec" -t "$tmp/result"
mv "$tmp/result" "$dest"
