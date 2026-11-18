<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/UpdateMetadata.php';

function update_metadata_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$directory = sys_get_temp_dir() . '/registrationwatch-metadata-test-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700, true)) {
	throw new RuntimeException('Unable to create the update metadata test directory.');
}

try {
	$packagePath = $directory . '/registrationwatch-1.5.0.tgz';
	file_put_contents($packagePath, 'local package bytes for metadata contract');
	$module = [
		'rawname' => 'registrationwatch',
		'name' => 'Registration Watch',
		'version' => '1.5.0',
		'publisher' => 'FreePBX UK',
		'license' => 'GPLv3+',
		'licenselink' => 'https://www.gnu.org/licenses/gpl-3.0.txt',
		'changelog' => '*1.5.0* Webhooks',
		'category' => 'Reports',
		'description' => 'PJSIP registration monitoring.',
		'depends' => ['version' => '16.0'],
		'supported' => ['version' => ['16.0', '17.0']],
	];
	$location = RegistrationwatchUpdateMetadata::expectedLocation('1.5.0');
	$metadata = RegistrationwatchUpdateMetadata::build($module, $location, $packagePath, 1795000000);

	update_metadata_assert($metadata['rawname'] === 'registrationwatch', 'The update feed should identify the correct module.');
	update_metadata_assert($metadata['version'] === '1.5.0', 'The feed version should match module metadata and the archive filename.');
	update_metadata_assert($metadata['location'] === 'https://github.com/freepbxUK/registrationwatch/releases/download/1.5.0/registrationwatch-1.5.0.tgz', 'The download link should use the versioned HTTPS release asset.');
	update_metadata_assert($metadata['md5sum'] === md5_file($packagePath), 'The feed checksum should match the package bytes.');
	update_metadata_assert($metadata['packaged'] === 1795000000, 'The feed should include the packaging timestamp.');
	update_metadata_assert($metadata['supported']['version'] === ['16.0', '17.0'], 'The feed should retain both supported FreePBX versions.');

$previous = $metadata;
$previous['version'] = '1.4.4';
$previous['md5sum'] = str_repeat('a', 32);
$previous['location'] = RegistrationwatchUpdateMetadata::expectedLocation('1.4.4');
	RegistrationwatchUpdateMetadata::assertNoRegression($metadata, $previous);
RegistrationwatchUpdateMetadata::assertNoRegression($metadata, $metadata);

	$regression = $metadata;
	$regression['version'] = '1.4.3';
	$regressionFailed = false;
	try {
		RegistrationwatchUpdateMetadata::assertNoRegression($regression, $previous);
	} catch (RuntimeException $exception) {
		$regressionFailed = true;
	}
	update_metadata_assert($regressionFailed, 'Older versions must not replace the currently advertised version.');

	$sameVersionReplacement = $metadata;
	$sameVersionReplacement['md5sum'] = str_repeat('b', 32);
	$replacementFailed = false;
	try {
		RegistrationwatchUpdateMetadata::assertNoRegression($sameVersionReplacement, $metadata);
	} catch (RuntimeException $exception) {
		$replacementFailed = true;
	}
	update_metadata_assert($replacementFailed, 'A published version must not be replaced by different package contents.');

	$badLocationFailed = false;
	try {
		RegistrationwatchUpdateMetadata::build($module, 'http://example.invalid/package.tgz', $packagePath, 1795000000);
	} catch (InvalidArgumentException $exception) {
		$badLocationFailed = true;
	}
	update_metadata_assert($badLocationFailed, 'Metadata must reject noncanonical or non-HTTPS download URLs.');

	$packageBytes = file_get_contents($packagePath);
	RegistrationwatchUpdateMetadata::verifyPublishedPackage(
		$location,
		$packagePath,
		static function (string $url, int $maximumBytes) use ($packageBytes): array {
			return ['http_status' => 200, 'body' => $packageBytes];
		}
	);
	$unavailableAssetFailed = false;
	try {
		RegistrationwatchUpdateMetadata::verifyPublishedPackage(
			$location,
			$packagePath,
			static function (): array {
				return ['http_status' => 404, 'body' => ''];
			}
		);
	} catch (RuntimeException $exception) {
		$unavailableAssetFailed = true;
	}
	update_metadata_assert($unavailableAssetFailed, 'An unavailable release asset must not produce update metadata.');

	$mismatchedAssetFailed = false;
	try {
		RegistrationwatchUpdateMetadata::verifyPublishedPackage(
			$location,
			$packagePath,
			static function (): array {
				return ['http_status' => 200, 'body' => 'different release bytes'];
			}
		);
	} catch (RuntimeException $exception) {
		$mismatchedAssetFailed = true;
	}
	update_metadata_assert($mismatchedAssetFailed, 'A release asset with different bytes must not produce update metadata.');

	$unreleasedPackageFailed = false;
	try {
		RegistrationwatchUpdateMetadata::verifyPublishedPackage('https://example.invalid/package.tgz', $packagePath);
	} catch (InvalidArgumentException $exception) {
		$unreleasedPackageFailed = true;
	}
	update_metadata_assert($unreleasedPackageFailed, 'The published-asset check must reject a URL that does not match the release identity.');
} finally {
	unlink($packagePath);
	rmdir($directory);
}

echo "Update metadata contract passed.\n";
