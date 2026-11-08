<?php
/* Part of the XigmaNAS share diagnostics. See build/COPYRIGHT. */
namespace services\samba\share;

/** Read-only snapshot of configured mount points and the FreeBSD mount table. */
final class storage_check {
	private array $identities = [];

	public function __construct(private array $expected, private array $mounted, private bool $available = true) {}

	public static function capture(array $configured, string $base): self {
		$expected = [];
		foreach ($configured as $row) {
			if (!is_array($row) || empty($row['sharename'])) {
				continue;
			}
			$point = rtrim($base, '/') . '/' . trim($row['sharename'], '/');
			$expected[$point] = (string)($row['devicespecialfile'] ?? '');
		}
		exec('/sbin/mount -p', $lines, $status);
		return new self($expected, self::parse_mounts($lines), $status === 0);
	}

	public static function parse_mounts(array $lines): array {
		$mounted = [];
		foreach ($lines as $line) {
			$fields = preg_split('/\s+/', trim($line));
			if (count($fields) < 4) {
				continue;
			}
			[$source, $point, $type] = array_map(static fn($s) =>
				preg_replace_callback('/\\\\([0-7]{3})/', static fn($m) => chr(octdec($m[1])), $s),
				array_slice($fields, 0, 3));
			if (str_starts_with($point, '/')) {
				$mounted[$point] = [$source, $type];
			}
		}
		return $mounted;
	}

	/** Walk whole directory components; /mnt/data2 must never match /mnt/data. */
	private static function ancestor(string $path, array $points): ?string {
		while ($path !== '') {
			if (array_key_exists($path, $points)) {
				return $path;
			}
			if ($path === '/') {
				break;
			}
			$path = dirname($path);
		}
		return null;
	}

	private function ufs_id(string $source): ?string {
		if (preg_match('~^/dev/ufsid/([a-f0-9]{16})$~iD', $source, $match)) {
			return strtolower($match[1]);
		}
		if (!str_starts_with($source, '/dev/')) {
			return null;
		}
		if (!array_key_exists($source, $this->identities)) {
			exec('/sbin/dumpfs -l ' . escapeshellarg($source) . ' 2>/dev/null', $lines, $status);
			$this->identities[$source] = $status === 0 && preg_match(
				'~^/dev/ufsid/([a-f0-9]{16})(?:\s|$)~i', $lines[0] ?? '', $match
			) ? strtolower($match[1]) : null;
		}
		return $this->identities[$source];
	}

	/** Return separate plain-text fields; the view inserts them as DOM text nodes. */
	public function inspect(string $path): array {
		if (!$this->available || !isset($this->mounted['/']) || !str_starts_with($path, '/') ||
			str_contains($path, '%') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) {
			return [gettext('Unverified'), gettext('Mount information or a static absolute path is unavailable.')];
		}
		$path = preg_replace('~/+~', '/', $path);
		$path = rtrim($path, '/') ?: '/';
		$expected = self::ancestor($path, $this->expected);
		$resolved = realpath($path);
		$exists = $resolved !== false && is_dir($resolved);
		// Missing children inherit the observed location of the nearest accessible parent.
		$parent = $path;
		while ($resolved === false && $parent !== '/') {
			$parent = dirname($parent);
			$resolved = realpath($parent);
		}
		$actual = $resolved === false ? null : self::ancestor($resolved, $this->mounted);
		if ($actual === null) {
			return [gettext('Unverified'), gettext('The backing filesystem could not be resolved.')];
		}
		[$source, $type] = $this->mounted[$actual];
		$detail = sprintf(gettext('Actual: %s (%s) at %s'), $source, $type, $actual);
		if ($expected !== null && $expected !== $actual) {
			$detail .= ' · ' . sprintf(gettext('Expected: %s'), $expected);
			if (!isset($this->mounted[$expected])) {
				return [gettext('Volume not mounted'), $detail];
			}
			return [gettext('Different mount'), $detail];
		}
		if (!$exists) {
			return [gettext('Directory missing'), $detail . ' · ' . gettext('Check path and traversal permissions.')];
		}
		$device = $this->expected[$expected ?? ''] ?? '';
		if ($type !== 'ufs' || !preg_match('~^/dev/ufsid/([0-9a-f]{16})$~iD', $device, $match)) {
			return [gettext('Unverified'), $detail];
		}
		$id = $this->ufs_id($source);
		if ($id === null) {
			return [gettext('Unverified'), $detail];
		}
		if ($id !== strtolower($match[1])) {
			return [gettext('Volume mismatch'), $detail . ' · ' . sprintf(gettext('Expected: %s'), $device)];
		}
		return [gettext('Verified'), $detail . ($source === $device ? '' : ' · ' . $device)];
	}
}
