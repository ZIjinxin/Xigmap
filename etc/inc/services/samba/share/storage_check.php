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

	/** Capture the two destinations once; both the status cell and the map use this observation. */
	public function trace(string $path): array {
		$route = ['expected_mount' => null, 'expected_device' => '', 'actual_mount' => null,
			'source' => null, 'type' => null, 'identity' => null, 'resolved' => null, 'directory' => null];
		if (!str_starts_with($path, '/') || str_contains($path, '%') || preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) {
			return $route;
		}
		$path = rtrim(preg_replace('~/+~', '/', $path), '/') ?: '/';
		$expected = self::ancestor($path, $this->expected);
		$route['expected_mount'] = $expected;
		$route['expected_device'] = $this->expected[$expected ?? ''] ?? '';
		if (!$this->available || !isset($this->mounted['/'])) {
			return $route;
		}
		$resolved = realpath($path);
		$route['directory'] = $resolved !== false && is_dir($resolved);
		// For an absent share, identify only the filesystem of its nearest existing parent.
		while ($resolved === false && $path !== '/') {
			$path = dirname($path);
			$resolved = realpath($path);
		}
		if ($resolved === false) {
			return $route;
		}
		$route['resolved'] = $resolved;
		$actual = self::ancestor($resolved, $this->mounted);
		if ($actual !== null) {
			$route['actual_mount'] = $actual;
			[$route['source'], $route['type']] = $this->mounted[$actual];
			if ($route['type'] === 'ufs' && $route['expected_device'] !== '') {
				$route['identity'] = $this->ufs_id($route['source']);
			}
		}
		return $route;
	}

	/** Report the status of a captured route without looking up the path a second time. */
	public function diagnose(array $route): array {
		$expected = $route['expected_mount'];
		$actual = $route['actual_mount'];
		if ($actual === null) {
			return [gettext('Unverified'), $route['directory'] === null ?
				gettext('Mount information or a static absolute path is unavailable.') :
				gettext('The backing filesystem could not be resolved.')];
		}
		$source = $route['source'];
		$detail = sprintf(gettext('Actual: %s (%s) at %s'), $source, $route['type'], $actual);
		if ($expected !== null && $expected !== $actual) {
			$detail .= ' · ' . sprintf(gettext('Expected: %s'), $expected);
			return [isset($this->mounted[$expected]) ? gettext('Different mount') : gettext('Volume not mounted'), $detail];
		}
		if (!$route['directory']) {
			return [gettext('Directory missing'), $detail . ' · ' . gettext('Check path and traversal permissions.')];
		}
		$device = $route['expected_device'];
		if ($route['identity'] === null || !preg_match('~^/dev/ufsid/([0-9a-f]{16})$~iD', $device, $match)) {
			return [gettext('Unverified'), $detail];
		}
		if ($route['identity'] !== strtolower($match[1])) {
			return [gettext('Volume mismatch'), $detail . ' · ' . sprintf(gettext('Expected: %s'), $device)];
		}
		return [gettext('Verified'), $detail . ($source === $device ? '' : ' · ' . $device)];
	}

	public function inspect(string $path): array {
		return $this->diagnose($this->trace($path));
	}
}
