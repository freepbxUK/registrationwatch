<?php

declare(strict_types=1);

function package_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$root = dirname(__DIR__);
$moduleXml = simplexml_load_file($root . '/module.xml');
package_assert($moduleXml !== false, 'module.xml should parse for the package contract.');
package_assert((string)$moduleXml->rawname === 'registrationwatch', 'The archive should use the Registration Watch module identity.');
package_assert((string)$moduleXml->version === '1.5.0', 'The module version and archive filename should agree.');

$temporaryDirectory = sys_get_temp_dir() . '/registrationwatch-package-test-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryDirectory, 0700, true)) {
	throw new RuntimeException('Unable to create the temporary package test directory.');
}

try {
	$builder = $root . '/scripts/package.php';
	$command = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($builder)
		. ' --unsigned --output-dir=' . escapeshellarg($temporaryDirectory);
	exec($command, $firstOutput, $firstStatus);
	package_assert($firstStatus === 0, 'Unsigned package generation should succeed: ' . implode("\n", $firstOutput));
	$archivePath = $temporaryDirectory . '/registrationwatch-1.5.0-unsigned.tgz';
	package_assert(is_file($archivePath), 'Unsigned package generation should create a versioned .tgz archive.');
	$firstHash = hash_file('sha256', $archivePath);

	$entries = [];
	exec('tar -tzf ' . escapeshellarg($archivePath), $entries, $listStatus);
	package_assert($listStatus === 0, 'The generated .tgz should be readable by tar.');
	$expectedFiles = [
		'Job.php',
		'LICENSE',
		'README.md',
		'Registrationwatch.class.php',
		'Webhook.php',
		'WebhookJob.php',
		'assets/css/registrationwatch.css',
		'assets/js/registrationwatch.js',
		'install.php',
		'module.xml',
		'page.registrationwatch.php',
		'uninstall.php',
		'views/main.php',
	];
	$expectedEntries = array_map(static function (string $file): string {
		return 'registrationwatch/' . $file;
	}, $expectedFiles);
	sort($expectedEntries, SORT_STRING);
	$actualEntries = array_values(array_filter($entries, static function (string $entry): bool {
		return substr($entry, -1) !== '/';
	}));
	sort($actualEntries, SORT_STRING);
	package_assert($actualEntries === $expectedEntries, 'The archive should contain only the complete installable module file set.');
	package_assert(!in_array('registrationwatch/module.sig', $entries, true), 'Unsigned builds must exclude the stale 1.4.4 signature.');
	foreach ($entries as $entry) {
		package_assert(strpos($entry, 'tests/') === false && strpos($entry, 'scripts/') === false && strpos($entry, '.git/') === false, 'Development files must not be included in the archive.');
		package_assert(strpos(strtolower($entry), 'private.key') === false && strpos(strtolower($entry), '.env') === false, 'Secrets and private keys must not be included in the archive.');
	}

	$secondOutput = [];
	exec($command, $secondOutput, $secondStatus);
	package_assert($secondStatus === 0, 'A repeated package build should succeed.');
	$secondHash = hash_file('sha256', $archivePath);
	package_assert($firstHash === $secondHash, 'Repeated package generation should produce byte-identical archives.');

	$signedCommand = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($builder)
		. ' --signed --output-dir=' . escapeshellarg($temporaryDirectory);
	exec($signedCommand, $signedOutput, $signedStatus);
	package_assert($signedStatus !== 0, 'A stale signature or unavailable signing credentials must fail signed packaging safely.');
	package_assert(!is_file($temporaryDirectory . '/registrationwatch-1.5.0.tgz'), 'Failed signed packaging must not leave a release-named archive.');
} finally {
	foreach (glob($temporaryDirectory . '/*') as $path) {
		if (is_file($path)) {
			unlink($path);
		}
	}
	rmdir($temporaryDirectory);
}

echo "Packaging contract passed.\n";
