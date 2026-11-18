<?php

declare(strict_types=1);

require_once __DIR__ . '/UpdateMetadata.php';

$root = dirname(__DIR__);
$outputDirectory = null;
$previousPath = null;
foreach ($argv as $argument) {
	if (strpos($argument, '--output-dir=') === 0) {
		$outputDirectory = substr($argument, strlen('--output-dir='));
	} elseif (strpos($argument, '--previous=') === 0) {
		$previousPath = substr($argument, strlen('--previous='));
	}
}

if ($outputDirectory === null || $outputDirectory === '') {
	$outputDirectory = $root . '/dist';
}
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
	fwrite(STDERR, "Unable to create the metadata output directory.\n");
	exit(1);
}
$outputDirectory = realpath($outputDirectory);
if ($outputDirectory === false) {
	fwrite(STDERR, "Unable to resolve the metadata output directory.\n");
	exit(1);
}

$moduleXml = simplexml_load_file($root . '/module.xml');
if ($moduleXml === false || (string)$moduleXml->rawname !== RegistrationwatchUpdateMetadata::RAW_NAME) {
	fwrite(STDERR, "module.xml is invalid or has the wrong module identity.\n");
	exit(1);
}
$version = (string)$moduleXml->version;
try {
	$location = RegistrationwatchUpdateMetadata::expectedLocation($version);
} catch (Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(1);
}

$packagePath = $outputDirectory . '/registrationwatch-' . $version . '.tgz';
$packageCommand = [
	PHP_BINARY,
	__DIR__ . '/package.php',
	'--signed',
	'--output-dir=' . $outputDirectory,
];
$pipes = [];
$process = proc_open($packageCommand, [
	0 => ['pipe', 'r'],
	1 => ['pipe', 'w'],
	2 => ['pipe', 'w'],
], $pipes);
if (!is_resource($process)) {
	fwrite(STDERR, "Unable to start signed package validation.\n");
	exit(1);
}
fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$packageStatus = proc_close($process);
if ($packageStatus !== 0 || !is_file($packagePath)) {
	fwrite(STDERR, trim((string)$stderr) !== '' ? trim((string)$stderr) : "Signed package validation failed.\n");
	exit(1);
}

try {
	RegistrationwatchUpdateMetadata::verifyPublishedPackage($location, $packagePath);
	$supported = [];
	foreach ($moduleXml->supported->version as $supportedVersion) {
		$supported[] = (string)$supportedVersion;
	}
	$module = [
		'rawname' => (string)$moduleXml->rawname,
		'name' => (string)$moduleXml->name,
		'version' => $version,
		'publisher' => (string)$moduleXml->publisher,
		'license' => (string)$moduleXml->license,
		'licenselink' => (string)$moduleXml->licenselink,
		'changelog' => trim((string)$moduleXml->changelog),
		'category' => (string)$moduleXml->category,
		'description' => (string)$moduleXml->description,
		'depends' => ['version' => (string)$moduleXml->depends->version],
		'supported' => ['version' => $supported],
	];
	$metadata = RegistrationwatchUpdateMetadata::build($module, $location, $packagePath, time());

	if ($previousPath === null || $previousPath === '') {
		$previousPath = $root . '/version.json';
	}
	if (is_file($previousPath)) {
		$previous = json_decode((string)file_get_contents($previousPath), true);
		if (!is_array($previous) || json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('The previous version.json is invalid JSON.');
		}
		RegistrationwatchUpdateMetadata::assertNoRegression($metadata, $previous);
		if ((string)$metadata['version'] === (string)($previous['version'] ?? '')
			&& (string)$metadata['md5sum'] === strtolower((string)($previous['md5sum'] ?? ''))) {
			$metadata['packaged'] = (int)$previous['packaged'];
		}
	}

	$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (!is_string($json)) {
		throw new RuntimeException('Unable to encode version metadata as JSON.');
	}
	$outputPath = $outputDirectory . '/version.json';
	$temporaryPath = $outputPath . '.' . bin2hex(random_bytes(8)) . '.tmp';
	if (file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false
		|| !rename($temporaryPath, $outputPath)) {
		if (is_file($temporaryPath)) {
			unlink($temporaryPath);
		}
		throw new RuntimeException('Unable to write the validated version metadata.');
	}
	echo $outputPath . "\n";
} catch (Throwable $exception) {
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(1);
}
