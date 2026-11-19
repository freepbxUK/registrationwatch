<?php

declare(strict_types=1);

const REGISTRATIONWATCH_SIGNER_FINGERPRINT = 'DD6DC4210FD028092D4981A56079D3C0D58322EE';

class RegistrationwatchPackageException extends RuntimeException {}

function packageFail(string $message, int $status = 1): void {
	throw new RegistrationwatchPackageException($message, $status);
}

set_exception_handler(static function (Throwable $exception): void {
	$status = $exception instanceof RegistrationwatchPackageException
		? (int)$exception->getCode()
		: 1;
	fwrite(STDERR, $exception->getMessage() . "\n");
	exit(max(1, $status));
});

function productionFiles(): array {
	return [
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
}

function productionDirectories(): array {
	$directories = [];
	foreach (productionFiles() as $file) {
		$directory = dirname($file);
		while ($directory !== '.') {
			$directories[$directory] = true;
			$directory = dirname($directory);
		}
	}
	ksort($directories, SORT_STRING);
	return array_keys($directories);
}

function optionValue(array $arguments, string $name): ?string {
	foreach ($arguments as $argument) {
		if (strpos($argument, $name . '=') === 0) {
			return substr($argument, strlen($name) + 1);
		}
	}
	return null;
}

function removeTree(string $path): void {
	if (!file_exists($path) && !is_link($path)) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $entry) {
		$entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
	}
	rmdir($path);
}

function relativeRegularFiles(string $root): array {
	$files = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);
	foreach ($iterator as $entry) {
		$relative = substr($entry->getPathname(), strlen($root) + 1);
		if ($entry->isLink()) {
			packageFail('Package staging contains a symlink: ' . $relative);
		}
		if ($entry->isFile()) {
			$files[] = $relative;
		}
	}
	sort($files, SORT_STRING);
	return $files;
}

function validateStageFileSet(string $stage, bool $signed): void {
	$expected = productionFiles();
	if ($signed) {
		$expected[] = 'module.sig';
	}
	sort($expected, SORT_STRING);
	$actual = relativeRegularFiles($stage);
	if ($actual !== $expected) {
		packageFail('The package staging file set is incomplete or contains additional files.');
	}
	$actualDirectories = [];
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ($iterator as $entry) {
		if ($entry->isDir() && !$entry->isLink()) {
			$actualDirectories[] = substr($entry->getPathname(), strlen($stage) + 1);
		}
	}
	sort($actualDirectories, SORT_STRING);
	$expectedDirectories = productionDirectories();
	if ($actualDirectories !== $expectedDirectories) {
		packageFail('The package staging directory structure contains additional or missing directories.');
	}
}

function stageHashMap(string $stage, bool $includeSignature = false): array {
	$hashes = [];
	$relativeFiles = productionFiles();
	if ($includeSignature) {
		$relativeFiles[] = 'module.sig';
	}
	foreach ($relativeFiles as $relativePath) {
		$path = $stage . '/' . $relativePath;
		$hash = is_file($path) ? hash_file('sha256', $path) : false;
		if (!is_string($hash)) {
			packageFail('Unable to hash staged production file: ' . $relativePath);
		}
		$hashes[$relativePath] = strtolower($hash);
	}
	return $hashes;
}

function assertStageMatchesSource(string $sourceRoot, string $stage): void {
	foreach (stageHashMap($stage) as $relativePath => $expectedHash) {
		$source = $sourceRoot . '/' . $relativePath;
		$actualHash = is_file($source) && !is_link($source) ? hash_file('sha256', $source) : false;
		if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) {
			packageFail('The staging tree no longer matches the finalised source file: ' . $relativePath);
		}
	}
}

function copyProductionStage(string $sourceRoot, string $stage): void {
	foreach (productionFiles() as $relativePath) {
		$source = $sourceRoot . '/' . $relativePath;
		if (!is_file($source) || is_link($source)) {
			packageFail('Required production source is missing or is a symlink: ' . $relativePath);
		}
		$destination = $stage . '/' . $relativePath;
		$parent = dirname($destination);
		if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
			packageFail('Unable to create a staging directory for ' . $relativePath);
		}
		if (!copy($source, $destination)) {
			packageFail('Unable to stage ' . $relativePath);
		}
		$sourceHash = hash_file('sha256', $source);
		$stageHash = hash_file('sha256', $destination);
		if (!is_string($sourceHash) || !is_string($stageHash)
			|| !hash_equals($sourceHash, $stageHash)) {
			packageFail('A production file changed while it was being staged: ' . $relativePath);
		}
	}
	validateStageFileSet($stage, false);
}

function verifySignature(string $signaturePath): array {
	$statusLines = [];
	exec(
		'gpg --batch --status-fd=1 --verify ' . escapeshellarg($signaturePath) . ' 2>/dev/null',
		$statusLines,
		$verifyStatus
	);
	$validSignature = false;
	foreach ($statusLines as $line) {
		if (preg_match('/^\[GNUPG:\] VALIDSIG ([A-Fa-f0-9]+)/', $line, $matches)
			&& strtoupper($matches[1]) === REGISTRATIONWATCH_SIGNER_FINGERPRINT) {
			$validSignature = true;
		}
	}
	if ($verifyStatus !== 0 || !$validSignature) {
		packageFail('module.sig is not verified by the established Registration Watch signing identity.');
	}

	$manifestLines = [];
	exec('gpg --batch --decrypt ' . escapeshellarg($signaturePath) . ' 2>/dev/null', $manifestLines, $manifestStatus);
	if ($manifestStatus !== 0) {
		packageFail('Unable to read the signed module file manifest.');
	}
	$manifest = [];
	foreach ($manifestLines as $line) {
		if (preg_match('/^(.+?) = ([a-fA-F0-9]{64})$/', $line, $matches)) {
			$manifest[$matches[1]] = strtolower($matches[2]);
		}
	}
	$expected = productionFiles();
	ksort($manifest, SORT_STRING);
	sort($expected, SORT_STRING);
	if (array_keys($manifest) !== $expected) {
		packageFail('The signed manifest must match the complete production package file list exactly.');
	}
	return $manifest;
}

function verifySignedStage(string $stage, bool $requireFrozen = true): array {
	validateStageFileSet($stage, true);
	$manifest = verifySignature($stage . '/module.sig');
	foreach ($manifest as $relativePath => $expectedHash) {
		$actualHash = hash_file('sha256', $stage . '/' . $relativePath);
		if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) {
			packageFail('The signed staging manifest does not match ' . $relativePath . '.');
		}
	}
	if ($requireFrozen) {
		$rootPermissions = @fileperms($stage);
		if ($rootPermissions === false || ($rootPermissions & 0222) !== 0) {
			packageFail('The signed staging directory must be frozen read-only before packaging.');
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $entry) {
			$permissions = @fileperms($entry->getPathname());
			if ($permissions !== false && ($permissions & 0222) !== 0) {
				packageFail('The signed staging directory must be frozen read-only before packaging.');
			}
		}
	}
	return stageHashMap($stage, true);
}

function archiveEntries(string $archivePath): array {
	$entries = [];
	exec('tar -tzf ' . escapeshellarg($archivePath), $entries, $status);
	if ($status !== 0) {
		packageFail('The resulting archive cannot be read.');
	}
	return $entries;
}

function validateArchiveStructure(string $archivePath, bool $signed): void {
	$verboseEntries = [];
	exec('tar -tvzf ' . escapeshellarg($archivePath), $verboseEntries, $verboseStatus);
	if ($verboseStatus !== 0) {
		packageFail('The resulting archive metadata cannot be read.');
	}
	foreach ($verboseEntries as $entry) {
		if ($entry === '' || ($entry[0] !== '-' && $entry[0] !== 'd')) {
			packageFail('The resulting archive contains a non-regular file or unsafe entry type.');
		}
	}
	$expected = productionFiles();
	if ($signed) {
		$expected[] = 'module.sig';
	}
	$expected = array_map(static function (string $path): string {
		return 'registrationwatch/' . $path;
	}, $expected);
	sort($expected, SORT_STRING);
	$actual = array_values(array_filter(archiveEntries($archivePath), static function (string $path): bool {
		return substr($path, -1) !== '/';
	}));
	sort($actual, SORT_STRING);
	if ($actual !== $expected) {
		packageFail('The resulting archive has missing, additional, or incorrectly rooted files.');
	}
	$expectedDirectories = productionDirectories();
	$expectedDirectories[] = 'registrationwatch';
	$expectedDirectories = array_map(static function (string $path): string {
		return $path === 'registrationwatch'
			? 'registrationwatch/'
			: 'registrationwatch/' . $path . '/';
	}, $expectedDirectories);
	sort($expectedDirectories, SORT_STRING);
	$actualDirectories = array_values(array_filter(archiveEntries($archivePath), static function (string $path): bool {
		return substr($path, -1) === '/';
	}));
	sort($actualDirectories, SORT_STRING);
	if ($actualDirectories !== $expectedDirectories) {
		packageFail('The resulting archive has additional or missing directories.');
	}
}

function verifyExtractedArchive(
	string $archivePath,
	bool $signed,
	array $expectedStageHashes
): void {
	$extractRoot = sys_get_temp_dir() . '/registrationwatch-extract-' . bin2hex(random_bytes(8));
	if (!mkdir($extractRoot, 0700, true)) {
		packageFail('Unable to create independent extraction directory.');
	}
	try {
		exec(
			'tar -xzf ' . escapeshellarg($archivePath) . ' --directory ' . escapeshellarg($extractRoot),
			$output,
			$status
		);
		$extractedStage = $extractRoot . '/registrationwatch';
		if ($status !== 0 || !is_dir($extractedStage)) {
			packageFail('Unable to extract the completed archive.');
		}
		validateStageFileSet($extractedStage, $signed);
		foreach (stageHashMap($extractedStage, $signed) as $relativePath => $actualHash) {
			if (!hash_equals($expectedStageHashes[$relativePath], $actualHash)) {
				packageFail('The extracted archive differs from the frozen staging file: ' . $relativePath);
			}
		}
		if ($signed) {
			verifySignedStage($extractedStage, false);
		}
	} finally {
		removeTree($extractRoot);
	}
}

function buildArchive(
	string $stage,
	string $archivePath,
	bool $signed
): void {
	if (basename($stage) !== 'registrationwatch') {
		packageFail('The final staging directory must be named registrationwatch.');
	}
	$command = 'tar --directory ' . escapeshellarg(dirname($stage))
		. ' --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner'
		. ' --mode=u+rwX,go+rX,go-w -cf - registrationwatch'
		. ' | gzip -n > ' . escapeshellarg($archivePath);
	$process = proc_open(['bash', '-o', 'pipefail', '-c', $command], [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	], $pipes);
	if (!is_resource($process)) {
		packageFail('Unable to start the archive builder.');
	}
	fclose($pipes[0]);
	stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$status = proc_close($process);
	if ($status !== 0 || !is_file($archivePath) || filesize($archivePath) === 0) {
		packageFail('Archive creation failed.' . ($stderr !== '' ? ' ' . trim($stderr) : ''));
	}
	validateArchiveStructure($archivePath, $signed);
}

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$stageMode = in_array('--stage', $arguments, true);
$unsigned = in_array('--unsigned', $arguments, true);
$signed = in_array('--signed', $arguments, true);
$verifyUnsignedArchive = optionValue($arguments, '--verify-unsigned-archive');
$modeCount = (int)$stageMode + (int)$unsigned + (int)$signed
	+ (int)($verifyUnsignedArchive !== null);
if ($modeCount !== 1) {
	packageFail('Choose exactly one mode: --stage, --unsigned, --signed, or --verify-unsigned-archive.', 2);
}

$moduleXml = simplexml_load_file($root . '/module.xml');
if ($moduleXml === false || (string)$moduleXml->rawname !== 'registrationwatch') {
	packageFail('module.xml is invalid or has the wrong module identity.');
}
$version = (string)$moduleXml->version;
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version)) {
	packageFail('module.xml must contain a stable numeric release version.');
}

$stageArgument = optionValue($arguments, '--stage-dir');
$stage = $stageArgument !== null && $stageArgument !== ''
	? realpath($stageArgument) ?: $stageArgument
	: null;
if ($stage !== null && basename($stage) !== 'registrationwatch') {
	packageFail('The staging directory must be named registrationwatch.');
}

if ($stageMode) {
	if ($stage === null || file_exists($stage)) {
		packageFail('Use --stage with a new --stage-dir=.../registrationwatch path.');
	}
	if (!mkdir($stage, 0755, true)) {
		packageFail('Unable to create the isolated package staging directory.');
	}
	copyProductionStage($root, $stage);
	echo $stage . "\n";
	exit(0);
}

if ($verifyUnsignedArchive !== null) {
	if ($stage === null || !is_dir($stage) || !is_file($verifyUnsignedArchive)) {
		packageFail('Archive verification requires an existing --stage-dir and unsigned archive.');
	}
	validateStageFileSet($stage, false);
	assertStageMatchesSource($root, $stage);
	validateArchiveStructure($verifyUnsignedArchive, false);
	verifyExtractedArchive($verifyUnsignedArchive, false, stageHashMap($stage));
	echo $verifyUnsignedArchive . "\n";
	exit(0);
}

$outputDirectory = optionValue($arguments, '--output-dir') ?? ($root . '/dist');
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
	packageFail('Unable to create the package output directory.');
}
$outputDirectory = realpath($outputDirectory);
if ($outputDirectory === false) {
	packageFail('Unable to resolve the package output directory.');
}

$temporaryParent = null;
if ($stage === null) {
	if ($signed) {
		packageFail('Signed packaging requires an authorised signed --stage-dir=.../registrationwatch.');
	}
	$temporaryParent = sys_get_temp_dir() . '/registrationwatch-build-' . bin2hex(random_bytes(8));
	$stage = $temporaryParent . '/registrationwatch';
	if (!mkdir($stage, 0755, true)) {
		packageFail('Unable to create the temporary package staging directory.');
	}
	copyProductionStage($root, $stage);
} elseif (!is_dir($stage)) {
	packageFail('The requested staging directory does not exist.');
}

try {
	validateStageFileSet($stage, $signed);
	assertStageMatchesSource($root, $stage);
	$expectedStageHashes = $signed ? verifySignedStage($stage) : stageHashMap($stage);
	$archivePath = $outputDirectory . '/registrationwatch-' . $version . ($unsigned ? '-unsigned' : '') . '.tgz';
	if (is_file($archivePath)) {
		unlink($archivePath);
	}
	buildArchive($stage, $archivePath, $signed);
	$afterArchiveHashes = stageHashMap($stage, $signed);
	if ($afterArchiveHashes !== $expectedStageHashes) {
		unlink($archivePath);
		packageFail('The frozen staging directory changed while the archive was being built.');
	}
	verifyExtractedArchive($archivePath, $signed, $expectedStageHashes);

	$checksum = hash_file('sha256', $archivePath);
	if (!is_string($checksum)) {
		unlink($archivePath);
		packageFail('Unable to calculate the verified archive checksum.');
	}
	if (in_array('--checksum', $arguments, true)) {
		$checksumPath = $archivePath . '.sha256';
		$checksumData = $checksum . '  ' . basename($archivePath) . "\n";
		if (file_put_contents($checksumPath, $checksumData, LOCK_EX) !== strlen($checksumData)) {
			packageFail('Unable to write the verified archive checksum.');
		}
		echo $checksumPath . "\n";
	}
	echo $archivePath . "\n";
	echo $checksum . "\n";
} finally {
	if ($temporaryParent !== null && is_dir($temporaryParent)) {
		removeTree($temporaryParent);
	}
}
