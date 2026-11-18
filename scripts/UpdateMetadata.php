<?php

declare(strict_types=1);

final class RegistrationwatchUpdateMetadata {
	const RAW_NAME = 'registrationwatch';
	const RELEASE_OWNER = 'freepbxUK';
	const RELEASE_REPOSITORY = 'registrationwatch';

	public static function expectedLocation(string $version): string {
		if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version)) {
			throw new InvalidArgumentException('A stable numeric module version is required.');
		}
		return 'https://github.com/' . self::RELEASE_OWNER . '/' . self::RELEASE_REPOSITORY
			. '/releases/download/' . $version . '/registrationwatch-' . $version . '.tgz';
	}

	public static function build(array $module, string $location, string $packagePath, int $packaged): array {
		$rawName = (string)($module['rawname'] ?? '');
		$version = (string)($module['version'] ?? '');
		if ($rawName !== self::RAW_NAME || $location !== self::expectedLocation($version)) {
			throw new InvalidArgumentException('Update metadata must match the Registration Watch release identity and HTTPS asset URL.');
		}
		if (basename($packagePath) !== 'registrationwatch-' . $version . '.tgz'
			|| !is_file($packagePath)
			|| is_link($packagePath)
			|| filesize($packagePath) <= 0
			|| $packaged <= 0) {
			throw new InvalidArgumentException('A non-empty, release-named signed package and packaged timestamp are required.');
		}
		$md5 = hash_file('md5', $packagePath);
		if (!is_string($md5)) {
			throw new RuntimeException('Unable to calculate the release package checksum.');
		}

		foreach (['name', 'publisher', 'license', 'licenselink', 'changelog', 'category', 'description'] as $field) {
			if (!isset($module[$field]) || !is_string($module[$field])) {
				throw new InvalidArgumentException('Module metadata is missing the ' . $field . ' field.');
			}
		}
		if (empty($module['depends']['version']) || empty($module['supported']['version'])) {
			throw new InvalidArgumentException('Module metadata is missing its FreePBX compatibility versions.');
		}

		return [
			'rawname' => $rawName,
			'repo' => 'local',
			'name' => $module['name'],
			'version' => $version,
			'publisher' => $module['publisher'],
			'license' => $module['license'],
			'licenselink' => $module['licenselink'],
			'changelog' => $module['changelog'],
			'category' => $module['category'],
			'description' => $module['description'],
			'depends' => ['version' => (string)$module['depends']['version']],
			'supported' => ['version' => array_values(array_map('strval', (array)$module['supported']['version']))],
			'location' => $location,
			'md5sum' => strtolower($md5),
			'packaged' => $packaged,
		];
	}

	public static function assertNoRegression(array $current, array $previous): void {
		$currentVersion = (string)($current['version'] ?? '');
		$previousVersion = (string)($previous['version'] ?? '');
		if (($previous['rawname'] ?? null) !== self::RAW_NAME
			|| !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $previousVersion)
			|| !preg_match('/^[a-f0-9]{32}$/i', (string)($previous['md5sum'] ?? ''))
			|| (int)($previous['packaged'] ?? 0) <= 0
			|| (string)($previous['location'] ?? '') !== self::expectedLocation($previousVersion)) {
			throw new InvalidArgumentException('The previous update metadata is invalid.');
		}
		$comparison = version_compare($currentVersion, $previousVersion);
		if ($comparison < 0) {
			throw new RuntimeException('Refusing to advertise a version older than the currently published update metadata.');
		}
		if ($comparison === 0 && !hash_equals(strtolower((string)$previous['md5sum']), strtolower((string)$current['md5sum']))) {
			throw new RuntimeException('Refusing to replace an already published version with different package contents.');
		}
	}

	public static function verifyPublishedPackage(string $url, string $packagePath, ?callable $transport = null): void {
		if ($url !== self::expectedLocationFromPackagePath($packagePath)) {
			throw new InvalidArgumentException('The published package URL does not match this release version.');
		}
		$size = filesize($packagePath);
		$localHash = hash_file('sha256', $packagePath);
		if (!is_int($size) || $size <= 0 || !is_string($localHash)) {
			throw new RuntimeException('Unable to inspect the signed release package.');
		}

		if ($transport !== null) {
			$response = $transport($url, $size);
			$body = is_array($response) ? (string)($response['body'] ?? '') : '';
			$status = is_array($response) ? (int)($response['http_status'] ?? 0) : 0;
			if ($status !== 200 || strlen($body) !== $size || !hash_equals($localHash, hash('sha256', $body))) {
				throw new RuntimeException('The published GitHub release asset is unavailable or does not match the signed package.');
			}
			return;
		}

		if (!function_exists('curl_init')) {
			throw new RuntimeException('The PHP cURL extension is required to verify the published release asset.');
		}
		$receivedBytes = 0;
		$remoteHash = hash_init('sha256');
		$handle = curl_init($url);
		if ($handle === false) {
			throw new RuntimeException('Unable to initialize the release asset verification request.');
		}
		curl_setopt_array($handle, [
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_PROXY => '',
			CURLOPT_NOPROXY => '*',
			CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$receivedBytes, $size, $remoteHash): int {
				$length = strlen($chunk);
				if ($receivedBytes + $length > $size) {
					return 0;
				}
				$receivedBytes += $length;
				hash_update($remoteHash, $chunk);
				return $length;
			},
		]);
		$result = curl_exec($handle);
		$status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		curl_close($handle);
		if ($result === false || $status !== 200 || $receivedBytes !== $size
			|| !hash_equals($localHash, hash_final($remoteHash))) {
			throw new RuntimeException('The published GitHub release asset is unavailable or does not match the signed package.');
		}
	}

	private static function expectedLocationFromPackagePath(string $packagePath): string {
		$basename = basename($packagePath);
		if (!preg_match('/^registrationwatch-([0-9]+\.[0-9]+\.[0-9]+)\.tgz$/', $basename, $matches)) {
			throw new InvalidArgumentException('The package filename is not a stable Registration Watch release archive.');
		}
		return self::expectedLocation($matches[1]);
	}
}
