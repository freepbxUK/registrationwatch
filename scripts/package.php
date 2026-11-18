<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$unsigned = in_array('--unsigned', $argv, true);
$signed = in_array('--signed', $argv, true);
$outputDirectory = null;
foreach ($argv as $argument) {
	if (strpos($argument, '--output-dir=') === 0) {
		$outputDirectory = substr($argument, strlen('--output-dir='));
	}
}

if ($unsigned === $signed) {
	fwrite(STDERR, "Choose exactly one mode: --unsigned or --signed.\n");
	exit(2);
}

$moduleXml = simplexml_load_file($root . '/module.xml');
if ($moduleXml === false || (string)$moduleXml->rawname !== 'registrationwatch') {
	fwrite(STDERR, "module.xml is invalid or has the wrong module identity.\n");
	exit(1);
}
$version = (string)$moduleXml->version;
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version)) {
	fwrite(STDERR, "module.xml must contain a stable numeric release version.\n");
	exit(1);
}

$relativeFiles = [
	'Job.php',
	'LICENSE',
	'README.md',
	'Registrationwatch.class.php',
	'Webhook.php',
	'WebhookJob.php',
	'install.php',
	'module.xml',
	'page.registrationwatch.php',
	'uninstall.php',
	'views/main.php',
	'assets/css/registrationwatch.css',
	'assets/js/registrationwatch.js',
];

if ($signed) {
	$signaturePath = $root . '/module.sig';
	if (!is_file($signaturePath)) {
		fwrite(STDERR, "A newly generated module.sig is required for signed packaging.\n");
		exit(1);
	}
	$statusLines = [];
	exec('gpg --batch --status-fd=1 --verify ' . escapeshellarg($signaturePath) . ' 2>/dev/null', $statusLines, $verifyStatus);
	$validSignature = false;
	foreach ($statusLines as $line) {
		if (preg_match('/^\[GNUPG:\] VALIDSIG ([A-Fa-f0-9]+)/', $line, $matches)
			&& strtoupper($matches[1]) === 'DD6DC4210FD028092D4981A56079D3C0D58322EE') {
			$validSignature = true;
		}
	}
	if ($verifyStatus !== 0 || !$validSignature) {
		fwrite(STDERR, "module.sig is not verified by the established Registration Watch signing identity.\n");
		exit(1);
	}

	$manifestLines = [];
	exec('gpg --batch --decrypt ' . escapeshellarg($signaturePath) . ' 2>/dev/null', $manifestLines, $manifestStatus);
	if ($manifestStatus !== 0) {
		fwrite(STDERR, "Unable to read the signed module file manifest.\n");
		exit(1);
	}
	$manifest = [];
	foreach ($manifestLines as $line) {
		if (preg_match('/^(.+?) = ([a-fA-F0-9]{64})$/', $line, $matches)) {
			$manifest[$matches[1]] = strtolower($matches[2]);
		}
	}
	$expectedFiles = $relativeFiles;
	sort($expectedFiles, SORT_STRING);
	$manifestFiles = array_keys($manifest);
	sort($manifestFiles, SORT_STRING);
	if ($manifestFiles !== $expectedFiles) {
		fwrite(STDERR, "The signed manifest must match the complete production package file list exactly.\n");
		exit(1);
	}
	foreach ($manifest as $relativePath => $expectedHash) {
		$source = $root . '/' . $relativePath;
		$actualHash = is_file($source) ? hash_file('sha256', $source) : false;
		if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) {
			fwrite(STDERR, "The signed manifest does not match " . $relativePath . ".\n");
			exit(1);
		}
	}
	$relativeFiles[] = 'module.sig';
}

foreach ($relativeFiles as $relativePath) {
	if (!is_file($root . '/' . $relativePath) || is_link($root . '/' . $relativePath)) {
		fwrite(STDERR, "Required package file is missing or is a symlink: " . $relativePath . "\n");
		exit(1);
	}
}

if ($outputDirectory === null || $outputDirectory === '') {
	$outputDirectory = $root . '/dist';
}
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
	fwrite(STDERR, "Unable to create the package output directory.\n");
	exit(1);
}
$outputDirectory = realpath($outputDirectory);
if ($outputDirectory === false) {
	fwrite(STDERR, "Unable to resolve the package output directory.\n");
	exit(1);
}

$suffix = $unsigned ? '-unsigned' : '';
$archivePath = $outputDirectory . '/registrationwatch-' . $version . $suffix . '.tgz';
$stage = sys_get_temp_dir() . '/registrationwatch-build-' . bin2hex(random_bytes(8));
$moduleDirectory = $stage . '/registrationwatch';
if (!mkdir($moduleDirectory, 0755, true)) {
	fwrite(STDERR, "Unable to create a temporary package staging directory.\n");
	exit(1);
}

try {
	foreach ($relativeFiles as $relativePath) {
		$destination = $moduleDirectory . '/' . $relativePath;
		$parent = dirname($destination);
		if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
			throw new RuntimeException('Unable to stage a required module directory.');
		}
		if (!copy($root . '/' . $relativePath, $destination)) {
			throw new RuntimeException('Unable to stage ' . $relativePath . '.');
		}
	}

	$command = 'tar --directory ' . escapeshellarg($stage)
		. ' --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner'
		. ' --mode=u+rwX,go+rX,go-w -cf - registrationwatch'
		. ' | gzip -n > ' . escapeshellarg($archivePath);
	$process = proc_open(['bash', '-o', 'pipefail', '-c', $command], [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	], $pipes);
	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start the archive builder.');
	}
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	if ($status !== 0 || !is_file($archivePath) || filesize($archivePath) === 0) {
		throw new RuntimeException('Archive creation failed.' . ($stderr !== '' ? ' ' . trim($stderr) : ''));
	}

	$archiveEntries = [];
	exec('tar -tzf ' . escapeshellarg($archivePath), $archiveEntries, $listStatus);
	$expectedEntries = array_map(static function (string $path): string {
		return 'registrationwatch/' . $path;
	}, $relativeFiles);
	sort($expectedEntries, SORT_STRING);
	$actualFileEntries = array_values(array_filter($archiveEntries, static function (string $path): bool {
		return substr($path, -1) !== '/';
	}));
	sort($actualFileEntries, SORT_STRING);
	if ($listStatus !== 0 || $actualFileEntries !== $expectedEntries) {
		throw new RuntimeException('The resulting archive has an unexpected internal structure.');
	}
	if ($unsigned && in_array('registrationwatch/module.sig', $archiveEntries, true)) {
		throw new RuntimeException('Unsigned packages must not include a stale module signature.');
	}
} catch (Throwable $exception) {
	if (is_file($archivePath)) {
		unlink($archivePath);
	}
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(1);
} finally {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $entry) {
		$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
	}
	rmdir($stage);
}

echo $archivePath . "\n";
