<?php

declare(strict_types=1);

function package_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function packageRemoveTree(string $path): void {
	if (!file_exists($path) && !is_link($path)) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $entry) {
		$entry->isDir() && !$entry->isLink()
			? rmdir($entry->getPathname())
			: unlink($entry->getPathname());
	}
	rmdir($path);
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

	$releaseParent = $temporaryDirectory . '/release';
	$stage = $releaseParent . '/registrationwatch';
	$stageCommand = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($builder)
		. ' --stage --stage-dir=' . escapeshellarg($stage);
	exec($stageCommand, $stageOutput, $stageStatus);
	package_assert($stageStatus === 0 && is_dir($stage), 'The builder should construct an isolated production staging directory.');
	$stagedPackageCommand = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($builder)
		. ' --unsigned --stage-dir=' . escapeshellarg($stage)
		. ' --output-dir=' . escapeshellarg($temporaryDirectory);
	exec($stagedPackageCommand, $stagedPackageOutput, $stagedPackageStatus);
	package_assert(
		$stagedPackageStatus === 0 && hash_file('sha256', $archivePath) === $firstHash,
		'Packaging an unchanged explicit stage should reproduce the same verified archive bytes.'
	);

	$stagedReadme = $stage . '/README.md';
	$originalReadme = file_get_contents($stagedReadme);
	package_assert(is_string($originalReadme), 'The staged README should be readable.');
	file_put_contents($stagedReadme, $originalReadme . "\nstaged mutation\n");
	exec($stagedPackageCommand, $modifiedStageOutput, $modifiedStageStatus);
	package_assert(
		$modifiedStageStatus !== 0,
		'Unsigned packaging should reject a stage that no longer matches the finalised source.'
	);
	file_put_contents($stagedReadme, $originalReadme);

	$tamperRoot = $temporaryDirectory . '/tamper';
	mkdir($tamperRoot, 0700, true);
	exec(
		'tar -xzf ' . escapeshellarg($archivePath) . ' --directory ' . escapeshellarg($tamperRoot),
		$extractOutput,
		$extractStatus
	);
	package_assert($extractStatus === 0, 'The verified archive should extract for tamper testing.');
	file_put_contents(
		$tamperRoot . '/registrationwatch/README.md',
		(string)file_get_contents($tamperRoot . '/registrationwatch/README.md') . "\narchive mutation\n"
	);
	$tamperedPath = $temporaryDirectory . '/registrationwatch-1.5.0-tampered.tgz';
	exec(
		'tar -czf ' . escapeshellarg($tamperedPath)
		. ' --directory ' . escapeshellarg($tamperRoot) . ' registrationwatch',
		$tamperOutput,
		$tamperStatus
	);
	package_assert($tamperStatus === 0, 'The tampered fixture archive should be constructible.');
	$verifyTamperedCommand = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($builder)
		. ' --verify-unsigned-archive=' . escapeshellarg($tamperedPath)
		. ' --stage-dir=' . escapeshellarg($stage);
	exec($verifyTamperedCommand, $tamperVerifyOutput, $tamperVerifyStatus);
	package_assert(
		$tamperVerifyStatus !== 0,
		'Independent archive verification should detect altered extracted bytes.'
	);

	$signedCommand = escapeshellarg(PHP_BINARY)
		. ' ' . escapeshellarg($builder)
		. ' --signed --stage-dir=' . escapeshellarg($stage)
		. ' --output-dir=' . escapeshellarg($temporaryDirectory);
	exec($signedCommand, $signedOutput, $signedStatus);
	package_assert($signedStatus !== 0, 'A missing, stale, or unavailable production signature must fail signed packaging safely.');
	package_assert(!is_file($temporaryDirectory . '/registrationwatch-1.5.0.tgz'), 'Failed signed packaging must not leave a release-named archive.');

	file_put_contents($stage . '/module.sig', "invalid isolated signature fixture\n");
	exec($signedCommand, $invalidSignedOutput, $invalidSignedStatus);
	package_assert(
		$invalidSignedStatus !== 0,
		'An invalid isolated signature fixture must fail signed packaging without relying on a repository signature.'
	);
	package_assert(!is_file($temporaryDirectory . '/registrationwatch-1.5.0.tgz'), 'Invalid signatures must not leave a release-named archive.');
} finally {
	packageRemoveTree($temporaryDirectory);
}

echo "Packaging contract passed.\n";
