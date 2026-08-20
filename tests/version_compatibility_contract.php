<?php

declare(strict_types=1);

function compatibility_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$root = dirname(__DIR__);
$moduleXml = simplexml_load_file($root . '/module.xml');
compatibility_assert($moduleXml !== false, 'module.xml should parse');
compatibility_assert((string)$moduleXml->version === '1.4.0', 'module version should be 1.4.0');
compatibility_assert((string)$moduleXml->depends->version === '16.0', 'minimum FreePBX version should be 16.0');
compatibility_assert((string)$moduleXml->supported->version === '16.0', 'supported release metadata should be 16.0 for the shared FreePBX 16 and 17 package');

$productionFiles = [
	'Job.php',
	'install.php',
	'uninstall.php',
	'Registrationwatch.class.php',
	'page.registrationwatch.php',
	'views/main.php',
];

$phpEightConstructs = [
	'/\?->/' => 'nullsafe operator',
	'/#\[/' => 'attributes',
	'/\bmatch\s*\(/' => 'match expression',
	'/\b(?:enum|readonly)\s+[A-Za-z_]/' => 'enum or readonly declaration',
	'/\bstr_(?:contains|starts_with|ends_with)\s*\(/' => 'PHP 8 string helper',
];

foreach ($productionFiles as $file) {
	$source = file_get_contents($root . '/' . $file);
	compatibility_assert($source !== false, $file . ' should be readable');
	foreach ($phpEightConstructs as $pattern => $description) {
		compatibility_assert(!preg_match($pattern, $source), $file . ' should not use PHP 8-only ' . $description);
	}
}

$job = file_get_contents($root . '/Job.php');
$install = file_get_contents($root . '/install.php');
$uninstall = file_get_contents($root . '/uninstall.php');
$readme = file_get_contents($root . '/README.md');
$moduleClass = file_get_contents($root . '/Registrationwatch.class.php');

compatibility_assert(strpos($job, 'class Job implements TaskInterface') !== false, 'Job should implement FreePBX Job TaskInterface');
compatibility_assert(strpos($job, 'use FreePBX\\Job\\TaskInterface;') !== false, 'Job should import FreePBX Job TaskInterface');
compatibility_assert(strpos($install, "'registrationwatch',\n                        'monitor',") !== false, 'installer should register registrationwatch/monitor');
compatibility_assert(strpos($install, "ALTER TABLE registrationwatch_registrations MODIFY COLUMN notes VARCHAR(72) NOT NULL DEFAULT ''") !== false, 'installer should widen registration notes to VARCHAR(72)');
$releasedRegistrationColumns = [
	'auto_disabled_absent_at',
	'contact_uri',
	'latency_ms',
	'source_ip',
	'registration_ua_class',
	'transport',
	'user_agent',
	'device_name',
	'firmware_version',
	'source_port',
	'contact_count',
	'contact_expires_at',
	'qualify_frequency',
	'last_heartbeat_at',
];
foreach ($releasedRegistrationColumns as $column) {
	compatibility_assert(strpos($install, 'ADD ' . $column) === false, 'installer should not add released registration column ' . $column);
}
compatibility_assert(strpos($uninstall, "remove('registrationwatch', 'monitor')") !== false, 'uninstaller should remove registrationwatch/monitor');
compatibility_assert(strpos($readme, 'FreePBX 16 and 17') !== false, 'README should identify FreePBX 16 and 17 support');
compatibility_assert(strpos($readme, 'FreePBX/PBXact 16 or 17') !== false, 'README compatibility should cover FreePBX/PBXact 16 and 17');
compatibility_assert(strpos($moduleClass, 'FreePBX 16 and 17') !== false, 'module documentation should identify FreePBX 16 and 17 support');

echo "Version compatibility contract passed.\n";
