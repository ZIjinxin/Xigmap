<?php
// Run with the same PHP version as XigmaNAS 14.3; creates only temporary directories.
require_once __DIR__ . '/../etc/inc/services/samba/share/storage_check.php';
use services\samba\share\storage_check;
$root = realpath(sys_get_temp_dir()) . '/share-check-' . bin2hex(random_bytes(6));
$point = "$root/data"; $other = "$root/data2"; $dir = "$point/files";
mkdir($dir, 0700, true); mkdir($other);
symlink($other, "$point/link");
$good = '/dev/ufsid/1234567890abcdef'; $bad = '/dev/ufsid/fedcba0987654321';
$base = ['/'=>[$bad, 'ufs']];
function expect_status($s, $path, $status) {
	if ($s->inspect($path)[0] !== $status) {
		throw new RuntimeException("Unexpected result for $path: " . implode(' / ', $s->inspect($path)));
	}
}
try {
	$ok = new storage_check([$point=>$good], $base + [$point=>[$good,'ufs']]);
	expect_status($ok, $dir, 'Verified');
	expect_status($ok, "$dir/missing", 'Directory missing');
	expect_status($ok, $other, 'Unverified');
	expect_status($ok, "$point/link", 'Different mount');
	expect_status($ok, "$dir/../files", 'Unverified');
	expect_status(new storage_check([$point=>$good], $base), $dir, 'Volume not mounted');
	expect_status(new storage_check([$point=>$good], $base + [$point=>[$bad,'ufs']]), $dir, 'Volume mismatch');
	expect_status(new storage_check([$point=>$good], $base, false), $dir, 'Unverified');
	expect_status(new storage_check([], $base + [$point=>['pool/lab','zfs']]), $dir, 'Unverified');
	$parsed = storage_check::parse_mounts(['pool/ds /mnt/a\\040b zfs rw 0 0']);
	if (($parsed['/mnt/a b'][0] ?? null) !== 'pool/ds') throw new RuntimeException('Escaped mount path');
	echo "10 checks passed\n";
} finally {
	unlink("$point/link"); rmdir($dir); rmdir($point); rmdir($other); rmdir($root);
}
