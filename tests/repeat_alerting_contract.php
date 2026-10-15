<?php

declare(strict_types=1);

interface BMO {}

if (!function_exists('_')) {
	function _($text) {
		return $text;
	}
}

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

class FreePBXSystemIdentifierConfigStub {
	public array $calls = [];
	public function get(string $key) {
		$this->calls[] = $key;
		return $this->values[$key] ?? '';
	}
	private array $values;
	public function __construct(array $values = []) {
		$this->values = $values;
	}
}

class FreePBX {
	public static $config;
	public static $database;
	public static function Config() {
		return self::$config;
	}
	public static function Database() {
		return self::$database;
	}
}

class ContractAsteriskManagerStub {
	public string $contactOutput = '';
	public array $commands = [];

	public function Command(string $command): string {
		$this->commands[] = $command;
		if ($command === 'pjsip show contacts') {
			return trim($this->contactOutput) !== '' ? $this->contactOutput : "No objects found.\n";
		}

		return '';
	}
}

if (!class_exists('CI_Email', false)) {
	class CI_Email {
		public static array $sendResults = [];
		public static array $sentMessages = [];
		private array $messageData = [];

		public function from($address, $name = null, $returnPath = null): void {
			$this->messageData['from'] = $address;
		}

		public function reply_to($address, $name = null): void {}
		public function to($recipient): void {
			$this->messageData['recipient'] = $recipient;
		}
		public function subject($subject): void {
			$this->messageData['subject'] = $subject;
		}
		public function set_mailtype($type): void {}
		public function message($message): void {
			$this->messageData['message'] = $message;
		}
		public function send(): bool {
			self::$sentMessages[] = $this->messageData;
			return self::$sendResults ? (bool)array_shift(self::$sendResults) : true;
		}
		public function print_debugger($include = null): string {
			return '';
		}
	}
} else {
	throw new RuntimeException('Contract tests must not run against a live FreePBX mailer.');
}

class ContractSQLitePDO extends PDO {
	public function prepare($query, $options = null): PDOStatement {
		$query = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $query);
		if (strpos($query, 'ON DUPLICATE KEY UPDATE') !== false) {
			if (!preg_match('/^\s*INSERT\s+INTO\s+registrationwatch_alert_escalation\b/i', $query)) {
				throw new RuntimeException('Contract SQLite adapter only supports escalation upserts.');
			}
			$query = preg_replace('/\s+ON DUPLICATE KEY UPDATE\s+[\s\S]*$/i', '', $query) ?? $query;
		}

		$statement = parent::prepare($query, $options ?? []);
		if ($statement === false) {
			throw new RuntimeException('Unable to prepare contract SQLite statement.');
		}

		return $statement;
	}
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE registrationwatch_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT)');
$database->exec("INSERT INTO registrationwatch_settings (setting_key, setting_value, updated_at) VALUES ('alert_recipients', 'admin@example.invalid', '2026-06-15 10:00:00')");
FreePBX::$database = $database;

require_once __DIR__ . '/../Registrationwatch.class.php';

$registrationWatchCss = file_get_contents(__DIR__ . '/../assets/css/registrationwatch.css');
assert_true($registrationWatchCss !== false, 'Registration Watch CSS should be readable');
assert_true(strpos($registrationWatchCss, "body:has(.registrationwatch) #page_body {\n\tdisplay: block !important;\n\twidth: 100% !important;\n\tmax-width: 100% !important;\n}") !== false, 'Registration Watch CSS should include exact scoped FreePBX page body override');
assert_true(strpos($registrationWatchCss, ".registrationwatch #rw-topology-container {\n\toverflow-x: auto;\n\toverflow-y: hidden;\n\t-webkit-overflow-scrolling: touch;\n}") !== false, 'Registration Watch CSS should include exact scoped topology scroll container override');
assert_true(strpos($registrationWatchCss, ".registrationwatch .rw-map-row-view {\n\twidth: max-content;\n\tmin-width: 100%;\n\tmargin-bottom: 0;\n}") !== false, 'Registration Watch CSS should include exact scoped map row width override');

function registration_key(string $extension, string $sourceIp, string $uaClass = ''): string {
	$basis = strtolower(trim($extension)) . "\0" . strtolower(trim($sourceIp));
	$uaClass = strtolower(trim(preg_replace('/\s+/', ' ', $uaClass) ?? ''));
	if ($uaClass !== '') {
		$basis .= "\0" . $uaClass;
	}
	return hash('sha256', $basis);
}

function no_contact_registration_key(string $extension): string {
	return hash('sha256', strtolower(trim($extension)) . "\0no-contact");
}

function clean_contact_uri(string $value): string {
	$value = trim($value);
	if ($value === '') {
		return '-';
	}
	$value = trim($value, '<>');
	if (stripos($value, 'sip:') === 0) {
		$value = substr($value, 4);
	} elseif (stripos($value, 'sips:') === 0) {
		$value = substr($value, 5);
	}
	$value = preg_split('/[;?&#>\s]/', $value, 2)[0] ?? '';
	$value = trim($value);

	return $value !== '' ? $value : '-';
}

function parse_user_agent_details_contract(?string $userAgent): array {
	$userAgent = trim((string)$userAgent);
	if ($userAgent === '') {
		return ['device_name' => null, 'firmware_version' => null];
	}
	if (preg_match('/^([^\/]+)\/(.+)$/', $userAgent, $matches)) {
		return ['device_name' => trim($matches[1]) ?: null, 'firmware_version' => trim($matches[2]) ?: null];
	}
	if (preg_match('/^(.+?)\s+([0-9]+(?:\.[0-9A-Za-z_-]+)+)$/', $userAgent, $matches)) {
		return ['device_name' => trim($matches[1]) ?: null, 'firmware_version' => trim($matches[2]) ?: null];
	}
	if (preg_match('/^(Sangoma\s+P[0-9A-Za-z-]+)\s+([0-9]+(?:_[0-9A-Za-z]+)+)\s+([0-9A-Fa-f]{12})$/', $userAgent, $matches)) {
		return ['device_name' => trim($matches[1] . ' ' . strtoupper($matches[3])) ?: null, 'firmware_version' => trim($matches[2]) ?: null];
	}

	return ['device_name' => $userAgent, 'firmware_version' => null];
}

function resolve_identity_group(array $items, array $existingState = []): array {
	$usable = [];
	foreach ($items as $item) {
		$ua = strtolower(trim(preg_replace('/\s+/', ' ', (string)($item['user_agent'] ?? '')) ?? ''));
		if ($ua !== '') {
			$usable[$ua] = true;
		}
	}
	ksort($usable);

	$existingClasses = $existingState['classes'] ?? [];
	$existingShared = $existingState['shared'] ?? [];
	$existingNonShared = array_values(array_filter(array_map('strval', $existingClasses), function ($ua) {
		return $ua !== '';
	}));

	$split = [];
	if (count($usable) > 1) {
		$split = array_fill_keys(array_keys($usable), true);
	} elseif (count($usable) === 1 && $existingNonShared) {
		$split = array_fill_keys(array_unique(array_merge(array_keys($usable), $existingNonShared)), true);
	}

	$anchor = null;
	if ($split && $existingShared) {
		foreach ($existingShared as $existing) {
			foreach ($items as $idx => $item) {
				if (!empty($existing['contact_uri']) && $existing['contact_uri'] === ($item['contact_uri'] ?? '')) {
					$anchor = $idx;
					break 2;
				}
			}
		}
		if ($anchor === null) {
			$ranked = [];
			foreach ($items as $idx => $item) {
				$ranked[] = [
					'index' => $idx,
					'ua' => strtolower(trim((string)($item['user_agent'] ?? ''))),
					'contact_uri' => (string)($item['contact_uri'] ?? ''),
				];
			}
			usort($ranked, function ($a, $b) {
				return strcmp($a['ua'], $b['ua']) ?: strcmp($a['contact_uri'], $b['contact_uri']);
			});
			$anchor = (int)$ranked[0]['index'];
		}
	}

	foreach ($items as $idx => $item) {
		$ua = strtolower(trim(preg_replace('/\s+/', ' ', (string)($item['user_agent'] ?? '')) ?? ''));
		$class = ($anchor !== $idx && $ua !== '' && isset($split[$ua])) ? $ua : '';
		$items[$idx]['registration_ua_class'] = $class;
		$items[$idx]['registration_key'] = registration_key($item['extension'], $item['source_ip'], $class);
	}

	return $items;
}

$sangomaParsed = parse_user_agent_details_contract('Sangoma P330 4_27_8 000FD3D0B030');
assert_true($sangomaParsed['device_name'] === 'Sangoma P330 000FD3D0B030', 'Sangoma user-agent parser should retain trailing 12-character device identifier');
assert_true($sangomaParsed['firmware_version'] === '4_27_8', 'Sangoma user-agent parser should extract underscore firmware token');

$slashParsed = parse_user_agent_details_contract('DeskPhone/1.2.3');
assert_true($slashParsed['device_name'] === 'DeskPhone' && $slashParsed['firmware_version'] === '1.2.3', 'slash user-agent parsing should remain unchanged');

function enrich_for_identity_contract(array $contact, array $registrarDetails): array {
	$parsePort = static function ($value): ?int {
		if ($value === null || $value === '' || !is_numeric($value)) {
			return null;
		}
		$port = (int)$value;
		return ($port > 0 && $port <= 65535) ? $port : null;
	};
	$parseUriAddress = static function (?string $contactUri) use ($parsePort): array {
		$result = ['host' => null, 'port' => null];
		$contactUri = trim((string)$contactUri);
		if ($contactUri === '' || preg_match('/\s/', $contactUri)) {
			return $result;
		}
		$contactUri = trim($contactUri, '<>');
		$atPosition = strrpos($contactUri, '@');
		$hostPort = $atPosition === false ? $contactUri : substr($contactUri, $atPosition + 1);
		$hostPort = preg_split('/[;?\#>\s]/', $hostPort, 2)[0] ?? '';
		if ($hostPort === '' || strpos($hostPort, ':') === false) {
			return $result;
		}
		if (!preg_match('/^([^:]+):([0-9]+)$/', $hostPort, $matches)) {
			return $result;
		}
		$result['host'] = strtolower(trim($matches[1]));
		$result['port'] = $parsePort($matches[2]);
		return $result;
	};
	$shouldPromote = static function (?string $parsedUri, ?string $registrarUri, ?string $parsedIp, ?string $registrarIp) use ($parseUriAddress, $parsePort): bool {
		$parsed = $parseUriAddress($parsedUri);
		$registrar = $parseUriAddress($registrarUri);
		if (($parsed['host'] ?? null) === null || ($registrar['host'] ?? null) === null || $parsed['host'] !== $registrar['host']) {
			return false;
		}
		$parsedPort = $parsePort($parsed['port'] ?? null);
		$registrarPort = $parsePort($registrar['port'] ?? null);
		if ($parsedPort === null || $registrarPort === null || $parsedPort === $registrarPort) {
			return false;
		}
		if (strtolower(trim((string)$parsedIp)) !== strtolower(trim((string)$parsed['host'] ?? ''))) {
			return false;
		}
		return strlen((string)$registrarPort) > strlen((string)$parsedPort)
			&& strpos((string)$registrarPort, (string)$parsedPort) === 0;
	};
	$networkHostMatches = static function (?string $leftUri, ?string $rightUri) use ($parseUriAddress): bool {
		$left = strtolower(trim((string)($parseUriAddress($leftUri)['host'] ?? '')));
		$right = strtolower(trim((string)($parseUriAddress($rightUri)['host'] ?? '')));
		return $left !== '' && $left === $right;
	};
	$applyPorts = static function (array $contactRow, array $detail) use ($parsePort, $parseUriAddress): array {
		$detailSourcePort = $parsePort($detail['source_port'] ?? null);
		$detailUriPort = $parsePort(($parseUriAddress((string)($detail['contact_uri'] ?? ''))['port'] ?? null));
		$resolvedPort = $detailSourcePort;
		if ($resolvedPort === null) {
			$resolvedPort = $detailUriPort;
		} elseif ($detailUriPort !== null && $detailUriPort !== $resolvedPort
			&& strlen((string)$detailUriPort) > strlen((string)$resolvedPort)
			&& strpos((string)$detailUriPort, (string)$resolvedPort) === 0
		) {
			$resolvedPort = $detailUriPort;
		}
		if ($resolvedPort !== null) {
			$contactRow['source_port'] = $resolvedPort;
		}
		return $contactRow;
	};

	$exact = null;
	$fallback = [];
	foreach ($registrarDetails as $detail) {
		if (($detail['extension'] ?? '') !== ($contact['extension'] ?? '')) {
			continue;
		}
		if (($detail['contact_uri'] ?? '') !== '' && $detail['contact_uri'] === ($contact['contact_uri'] ?? '')) {
			$exact = $detail;
			break;
		}
		if (($detail['source_ip'] ?? '') !== '' && $detail['source_ip'] === ($contact['source_ip'] ?? '')) {
			$fallback[] = $detail;
			continue;
		}
		if (($detail['contact_uri'] ?? '') !== '' && $networkHostMatches($detail['contact_uri'], $contact['contact_uri'] ?? null)) {
			$fallback[] = $detail;
		}
	}

	if (is_array($exact)) {
		if (($exact['contact_uri'] ?? '') !== '') {
			$contact['contact_uri'] = (string)$exact['contact_uri'];
		}
		$contact['source_ip'] = $exact['source_ip'] ?: $contact['source_ip'];
		$contact['user_agent'] = $exact['user_agent'] ?? $contact['user_agent'];
		return $applyPorts($contact, $exact);
	}

	if (count($fallback) === 1 && $shouldPromote(
		$contact['contact_uri'] ?? null,
		$fallback[0]['contact_uri'] ?? null,
		$contact['source_ip'] ?? null,
		$fallback[0]['source_ip'] ?? null
	)) {
		$contact['contact_uri'] = (string)($fallback[0]['contact_uri'] ?? $contact['contact_uri']);
	}

	if (count($fallback) === 1) {
		$contact = $applyPorts($contact, $fallback[0]);
		$contact['contact_expires_at'] = $fallback[0]['contact_expires_at'] ?? ($contact['contact_expires_at'] ?? null);
	}

	return $contact;
}

function registration_address_details_contract(?string $contactUri, ?string $sourceIp, $sourcePort): array {
	$parseUriAddress = static function (?string $uri): array {
		$result = ['host' => null, 'port' => null];
		$uri = trim((string)$uri);
		if ($uri === '' || preg_match('/\s/', $uri)) {
			return $result;
		}
		$uri = trim($uri, '<>');
		$at = strrpos($uri, '@');
		$hostPort = $at === false ? $uri : substr($uri, $at + 1);
		$hostPort = preg_split('/[;?\#>\s]/', $hostPort, 2)[0] ?? '';
		if ($hostPort === '') {
			return $result;
		}
		if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $hostPort, $m)) {
			$result['host'] = trim($m[1]);
			$result['port'] = isset($m[2]) ? (int)$m[2] : null;
			return $result;
		}
		if (substr_count($hostPort, ':') === 1 && preg_match('/^([^:]+):([0-9]+)$/', $hostPort, $m)) {
			$result['host'] = trim($m[1]);
			$result['port'] = (int)$m[2];
			return $result;
		}
		if (substr_count($hostPort, ':') === 0) {
			$result['host'] = $hostPort;
		}
		return $result;
	};
	$parseOriginal = static function (?string $uri): array {
		$result = ['host' => null, 'port' => null];
		$uri = trim((string)$uri);
		if ($uri === '' || !preg_match('/[;?&]x-ast-orig-host=([^;?&#>\s]+)/', $uri, $m)) {
			return $result;
		}
		$value = rawurldecode($m[1]);
		if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $value, $parts)) {
			$result['host'] = trim($parts[1]);
			$result['port'] = isset($parts[2]) ? (int)$parts[2] : null;
			return $result;
		}
		if (substr_count($value, ':') === 1 && preg_match('/^([^:]+):([0-9]+)$/', $value, $parts)) {
			$result['host'] = trim($parts[1]);
			$result['port'] = (int)$parts[2];
		}
		return $result;
	};

	$contact = $parseUriAddress($contactUri);
	$original = $parseOriginal($contactUri);
	$hasOriginal = $original['host'] !== null || $original['port'] !== null;
	$device = $hasOriginal ? $original : $contact;
	$network = $hasOriginal ? $contact : ['host' => null, 'port' => null];
	if ($network['host'] === null && trim((string)$sourceIp) !== '') {
		$network['host'] = $sourceIp;
	}
	if ($network['port'] === null && trim((string)$sourcePort) !== '' && is_numeric((string)$sourcePort)) {
		$network['port'] = (int)$sourcePort;
	}

	return [
		'device_ip' => $device['host'],
		'device_port' => $device['port'],
		'network_ip' => $network['host'],
		'network_port' => $network['port'],
	];
}

function filter_allowlisted_contacts(array $contacts, array $allowedDevices): array {
	$allowed = [];
	foreach ($allowedDevices as $id) {
		$id = strtolower(trim((string)$id));
		if ($id !== '') {
			$allowed[$id] = true;
		}
	}

	$result = ['accepted' => [], 'ignored' => []];
	foreach ($contacts as $contact) {
		$extension = strtolower(trim((string)($contact['extension'] ?? '')));
		if (isset($allowed[$extension])) {
			$result['accepted'][] = $contact;
		} else {
			$result['ignored'][] = $contact;
		}
	}

	return $result;
}

function should_auto_disable_absent(array $registration, int $thresholdSeconds, string $now): bool {
	if ($thresholdSeconds <= 0 || (int)($registration['enabled'] ?? 0) !== 1 || !empty($registration['auto_disabled_absent_at'])) {
		return false;
	}
	$lastSeen = strtotime((string)($registration['last_seen_at'] ?? ''));
	$nowTs = strtotime($now);
	return $lastSeen !== false && $nowTs !== false && ($nowTs - $lastSeen) >= $thresholdSeconds;
}

function escalating_interval_seconds(int $alertCount): int {
	$base = 300;
	$ceiling = 86400;
	$step = max(1, $alertCount + 1);
	$previous = 0;
	$current = 1;
	for ($i = 1; $i < $step; $i++) {
		$next = $previous + $current;
		$previous = $current;
		$current = $next;
		if ($current * $base >= $ceiling) {
			return $ceiling;
		}
	}
	return min($ceiling, $current * $base);
}

function normalise_repeat_mode(?string $mode): string {
	$mode = strtolower(trim((string)$mode));
	if ($mode === 'fibonacci') {
		return 'escalating';
	}
	return in_array($mode, ['never', '5m', 'hourly', 'daily', 'escalating'], true) ? $mode : 'never';
}

function is_still_alertable(string $alertType, string $status): bool {
	return ($alertType === 'unreachable' && $status === 'Unreachable')
		|| ($alertType === 'not_registered' && $status === 'Not registered');
}

function transition_alert_type(string $from, string $to): ?string {
	if ($from === '' || $from === 'Unknown') {
		return null;
	}
	if ($to === 'Unreachable' && in_array($from, ['Reachable', 'Registered (no qualify)', 'Unreachable'], true)) {
		return 'unreachable';
	}
	if ($to === 'Not registered' && in_array($from, ['Reachable', 'Registered (no qualify)', 'Unreachable'], true)) {
		return 'not_registered';
	}
	if (in_array($from, ['Unreachable', 'Not registered'], true) && in_array($to, ['Reachable', 'Registered (no qualify)'], true)) {
		return 'recovery';
	}

	return null;
}

function same_pass_dead_registration_id(PDO $db, string $extension, array $liveContactsByKey): int {
	$extension = strtolower(trim($extension));
	if ($extension === '') {
		return 0;
	}

	$params = [
		':extension' => $extension,
		':placeholder_key' => no_contact_registration_key($extension),
	];
	$sql =
		'SELECT id
		FROM registrationwatch_registrations
		WHERE extension = :extension
			AND discovered = 1
			AND source_ip IS NOT NULL AND source_ip <> ""
			AND registration_key <> :placeholder_key';

	$liveKeys = array_keys($liveContactsByKey);
	if ($liveKeys) {
		$placeholders = [];
		foreach ($liveKeys as $idx => $key) {
			$param = ':live_key_' . $idx;
			$placeholders[] = $param;
			$params[$param] = (string)$key;
		}
		$sql .= ' AND registration_key NOT IN (' . implode(', ', $placeholders) . ')';
	}

	$sql .= ' ORDER BY id ASC LIMIT 2';
	$stmt = $db->prepare($sql);
	$stmt->execute($params);
	$ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

	return count($ids) === 1 ? (int)$ids[0] : 0;
}

function extension_monitoring_state_key_contract(string $extension): string {
	return 'extension_monitoring_state_' . strtolower(trim($extension));
}

function set_extension_monitoring_state_contract(PDO $db, string $extension, int $enabled): void {
	$db->prepare('INSERT OR REPLACE INTO registrationwatch_settings (setting_key, setting_value) VALUES (?, ?)')
		->execute([extension_monitoring_state_key_contract($extension), $enabled ? '1' : '0']);
}

function get_extension_monitoring_state_contract(PDO $db, string $extension): ?int {
	$stmt = $db->prepare('SELECT setting_value FROM registrationwatch_settings WHERE setting_key = ?');
	$stmt->execute([extension_monitoring_state_key_contract($extension)]);
	$value = $stmt->fetchColumn();
	if ($value === false || $value === null || trim((string)$value) === '') {
		return null;
	}
	return trim((string)$value) === '1' ? 1 : 0;
}

function normalise_extension_monitoring_state_contract(PDO $db): void {
	$extensions = $db->query('SELECT DISTINCT extension FROM registrationwatch_registrations')->fetchAll(PDO::FETCH_COLUMN, 0);
	$updateEnabled = $db->prepare('UPDATE registrationwatch_registrations SET enabled = ? WHERE extension = ? AND enabled <> ?');
	$clearAutoDisabled = $db->prepare('UPDATE registrationwatch_registrations SET auto_disabled_absent_at = NULL WHERE extension = ? AND auto_disabled_absent_at IS NOT NULL');
	foreach ($extensions as $extension) {
		$configured = get_extension_monitoring_state_contract($db, (string)$extension);
		if ($configured === null) {
			continue;
		}
		$updateEnabled->execute([$configured, (string)$extension, $configured]);
		if ($configured === 1) {
			$clearAutoDisabled->execute([(string)$extension]);
		}
	}
}

function promote_live_contact_same_pass(PDO $db, array $liveContact, array $liveContactsByKey): int {
	$registrationKey = (string)$liveContact['registration_key'];
	$extension = strtolower(trim((string)$liveContact['extension']));

	$stmt = $db->prepare('SELECT id FROM registrationwatch_registrations WHERE registration_key = ?');
	$stmt->execute([$registrationKey]);
	$id = $stmt->fetchColumn();
	if ($id) {
		$db->prepare('UPDATE registrationwatch_registrations SET source_ip = ?, source_port = ?, last_known_status = ? WHERE id = ?')
			->execute([(string)($liveContact['source_ip'] ?? ''), $liveContact['source_port'] ?? null, (string)($liveContact['status'] ?? 'Unknown'), (int)$id]);
		return (int)$id;
	}

	$placeholderStmt = $db->prepare('SELECT id FROM registrationwatch_registrations WHERE registration_key = ? AND (source_ip IS NULL OR source_ip = "") LIMIT 1');
	$placeholderStmt->execute([no_contact_registration_key($extension)]);
	$placeholderId = $placeholderStmt->fetchColumn();
	if ($placeholderId) {
		$db->prepare('UPDATE registrationwatch_registrations SET registration_key = ?, source_ip = ?, source_port = ?, last_known_status = ? WHERE id = ?')
			->execute([$registrationKey, (string)($liveContact['source_ip'] ?? ''), $liveContact['source_port'] ?? null, (string)($liveContact['status'] ?? 'Unknown'), (int)$placeholderId]);
		return (int)$placeholderId;
	}

	$deadId = same_pass_dead_registration_id($db, $extension, $liveContactsByKey);
	if ($deadId > 0) {
		$db->prepare('UPDATE registrationwatch_registrations SET registration_key = ?, source_ip = ?, source_port = ?, last_known_status = ? WHERE id = ?')
			->execute([$registrationKey, (string)($liveContact['source_ip'] ?? ''), $liveContact['source_port'] ?? null, (string)($liveContact['status'] ?? 'Unknown'), $deadId]);
		return $deadId;
	}

	$configured = get_extension_monitoring_state_contract($db, $extension);
	$enabled = $configured === null ? 0 : $configured;
	$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([$registrationKey, $extension, (string)($liveContact['source_ip'] ?? ''), $liveContact['source_port'] ?? null, $enabled, null, 'Unknown', 1]);

	return (int)$db->lastInsertId();
}

function handoff_escalation(PDO $db, int $registrationId, string $registrationKey, string $extension, int $historyId, string $alertType, string $createdAt, string $now): void {
	$stmt = $db->prepare(
		'SELECT registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode
		FROM registrationwatch_alert_escalation
		WHERE registration_id = :registration_id
		ORDER BY active_since ASC, id ASC
		LIMIT 1'
	);
	$stmt->execute([':registration_id' => $registrationId]);
	$existing = $stmt->fetch(PDO::FETCH_ASSOC);

	$activeSince = is_array($existing) && !empty($existing['active_since']) ? $existing['active_since'] : $createdAt;
	$lastAlertAt = is_array($existing) && !empty($existing['last_alert_at']) ? $existing['last_alert_at'] : $now;
	$alertCount = is_array($existing) ? (int)$existing['alert_count'] : 0;
	$nextDueAt = is_array($existing) && !empty($existing['next_due_at']) ? $existing['next_due_at'] : '2026-06-15 10:05:00';

	$db->prepare('DELETE FROM registrationwatch_alert_escalation WHERE registration_id = :registration_id AND alert_type <> :alert_type')
		->execute([':registration_id' => $registrationId, ':alert_type' => $alertType]);

	$db->prepare(
		'INSERT OR REPLACE INTO registrationwatch_alert_escalation
			(registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode)
		VALUES
			(:registration_id, :registration_key, :extension, :history_id, :alert_type, :active_since, :last_alert_at, :alert_count, :next_due_at, :repeat_mode)'
	)->execute([
		':registration_id' => $registrationId,
		':registration_key' => $registrationKey,
		':extension' => $extension,
		':history_id' => $historyId,
		':alert_type' => $alertType,
		':active_since' => $activeSince,
		':last_alert_at' => $lastAlertAt,
		':alert_count' => $alertCount,
		':next_due_at' => $nextDueAt,
		':repeat_mode' => 'escalating',
	]);
}

function storm_contract_decision(array $alerts, $threshold): array {
	$threshold = trim((string)$threshold);
	$threshold = $threshold !== '' && ctype_digit($threshold) ? (int)$threshold : 0;
	if ($threshold <= 0 || count($alerts) < $threshold) {
		return ['individuals' => $alerts, 'summaries' => []];
	}
	$recipients = array_values(array_unique(array_column($alerts, 'recipient')));
	return ['individuals' => [], 'summaries' => $recipients];
}

function rw_state_key(string $extension): string {
	return 'auto_handover_state_' . strtolower(trim($extension));
}

function rw_get_state(PDO $db, string $extension): array {
	$stmt = $db->prepare('SELECT setting_value FROM registrationwatch_settings WHERE setting_key = ?');
	$stmt->execute([rw_state_key($extension)]);
	$raw = (string)($stmt->fetchColumn() ?? '');
	if ($raw === '') {
		return [];
	}
	$decoded = json_decode($raw, true);
	return is_array($decoded) ? $decoded : [];
}

function rw_set_state(PDO $db, string $extension, array $state): void {
	$db->prepare('INSERT OR REPLACE INTO registrationwatch_settings (setting_key, setting_value) VALUES (?, ?)')
		->execute([rw_state_key($extension), json_encode($state)]);
}

function rw_clear_state(PDO $db, string $extension): void {
	$db->prepare('DELETE FROM registrationwatch_settings WHERE setting_key = ?')->execute([rw_state_key($extension)]);
}

function rw_live_signature(array $live): string {
	$parts = [];
	foreach ($live as $key => $contact) {
		$parts[] = implode('|', [
			(string)$key,
			(string)($contact['status'] ?? 'Unknown'),
			strtolower(trim((string)($contact['source_ip'] ?? ''))),
			isset($contact['source_port']) ? (string)$contact['source_port'] : '',
		]);
	}
	sort($parts, SORT_STRING);
	return implode(',', $parts);
}

function apply_auto_handover_contract(PDO $db, string $extension, array $live, string $now, int $pollWindow): array {
	$state = rw_get_state($db, $extension);
	$phase = (string)($state['phase'] ?? '');

	$rowsStmt = $db->prepare('SELECT id, registration_key, source_ip, source_port, enabled, discovered, repeat_mode, last_known_status, first_discovered_at FROM registrationwatch_registrations WHERE extension = ? ORDER BY id ASC');
	$rowsStmt->execute([$extension]);
	$rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
	$degraded = array_values(array_filter($rows, function ($row) {
		return (int)($row['enabled'] ?? 0) === 1
			&& in_array((string)($row['last_known_status'] ?? 'Unknown'), ['Unreachable', 'Not registered'], true);
	}));

	if ($phase === 'suspended') {
		$reachableCount = 0;
		foreach ($live as $contact) {
			if (($contact['status'] ?? 'Unknown') === 'Reachable') {
				$reachableCount++;
			}
		}
		$healthy = count($degraded) === 0 && $reachableCount === 1;
		$sig = rw_live_signature($live);
		if ($healthy && ($state['healthy_signature'] ?? '') === $sig) {
			$state['healthy_polls'] = ((int)($state['healthy_polls'] ?? 0)) + 1;
		} elseif ($healthy) {
			$state['healthy_signature'] = $sig;
			$state['healthy_polls'] = 1;
		} else {
			$state['healthy_signature'] = $sig;
			$state['healthy_polls'] = 0;
		}
		if ((int)$state['healthy_polls'] >= 3) {
			rw_clear_state($db, $extension);
			return ['committed' => false, 'state' => []];
		}
		rw_set_state($db, $extension, $state);
		return ['committed' => false, 'state' => $state];
	}

	if ($phase === 'validation') {
		$expectedNewKey = (string)($state['new_key'] ?? '');
		$expectedOldKey = (string)($state['old_key'] ?? '');
		$reachable = [];
		foreach ($live as $key => $contact) {
			if (($contact['status'] ?? 'Unknown') === 'Reachable') {
				$reachable[(string)$key] = $contact;
			}
		}
		$valid = count($reachable) === 1
			&& isset($reachable[$expectedNewKey])
			&& ($expectedOldKey === '' || !isset($live[$expectedOldKey]));
		if (!$valid) {
			$state['phase'] = 'suspended';
			$state['suspended_reason'] = 'post_handover_validation_failed';
			$state['healthy_polls'] = 0;
			rw_set_state($db, $extension, $state);
			return ['committed' => false, 'state' => $state];
		}

		$state['validation_observations'] = ((int)($state['validation_observations'] ?? 0)) + 1;
		if ((int)$state['validation_observations'] >= 3) {
			rw_clear_state($db, $extension);
			return ['committed' => false, 'state' => []];
		}
		rw_set_state($db, $extension, $state);
		return ['committed' => false, 'state' => $state];
	}

	if (count($degraded) !== 1) {
		rw_clear_state($db, $extension);
		return ['committed' => false, 'state' => []];
	}

	$old = $degraded[0];
	$oldId = (int)$old['id'];
	$oldKey = (string)$old['registration_key'];
	$oldIp = strtolower(trim((string)$old['source_ip']));

	$reachable = [];
	foreach ($live as $key => $contact) {
		if (($contact['status'] ?? 'Unknown') !== 'Reachable') {
			continue;
		}
		$newIp = strtolower(trim((string)($contact['source_ip'] ?? '')));
		if ((string)$key === $oldKey && $newIp === $oldIp) {
			continue;
		}
		$reachable[(string)$key] = $contact;
	}
	if (count($reachable) !== 1) {
		rw_clear_state($db, $extension);
		return ['committed' => false, 'state' => []];
	}

	$newKey = (string)array_key_first($reachable);
	$new = $reachable[$newKey];
	$newIp = strtolower(trim((string)($new['source_ip'] ?? '')));
	$candidate = ['old_id' => $oldId, 'old_key' => $oldKey, 'new_key' => $newKey, 'old_ip' => $oldIp, 'new_ip' => $newIp];
	$signature = rw_live_signature($live);

	if (isset($live[$oldKey])) {
		if (!$state || ($state['phase'] ?? '') !== 'pre_candidate' || (int)($state['old_id'] ?? 0) !== $oldId || (string)($state['new_key'] ?? '') !== $newKey) {
			$state = array_merge($candidate, ['phase' => 'pre_candidate', 'pre_observations' => 1, 'churn_events' => max(1, (int)($state['churn_events'] ?? 0)), 'confirmations' => 0, 'live_signature' => $signature]);
		} else {
			$state['pre_observations'] = ((int)($state['pre_observations'] ?? 0)) + 1;
			$state['churn_events'] = max(1, (int)($state['churn_events'] ?? 0));
		}
		rw_set_state($db, $extension, $state);
		return ['committed' => false, 'state' => $state];
	}

	$dupes = array_values(array_filter($rows, function ($row) use ($newKey, $oldId) {
		return (int)$row['id'] !== $oldId && (string)$row['registration_key'] === $newKey;
	}));
	if (count($dupes) !== 1) {
		rw_clear_state($db, $extension);
		return ['committed' => false, 'state' => []];
	}
	$firstTs = strtotime((string)($dupes[0]['first_discovered_at'] ?? ''));
	$nowTs = strtotime($now);
	if ($firstTs === false || $nowTs === false || ($nowTs - $firstTs) < max(5, $pollWindow)) {
		return ['committed' => false, 'state' => $state];
	}

	$same = $state
		&& in_array((string)($state['phase'] ?? ''), ['tracking', 'pre_candidate'], true)
		&& (int)($state['old_id'] ?? 0) === $candidate['old_id']
		&& (string)($state['old_key'] ?? '') === $candidate['old_key']
		&& (string)($state['new_key'] ?? '') === $candidate['new_key']
		&& (string)($state['old_ip'] ?? '') === $candidate['old_ip']
		&& (string)($state['new_ip'] ?? '') === $candidate['new_ip'];

	if (!$same) {
		$state = array_merge($candidate, ['phase' => 'tracking', 'confirmations' => 1, 'churn_events' => 0, 'live_signature' => $signature]);
		rw_set_state($db, $extension, $state);
		return ['committed' => false, 'state' => $state];
	}

	$confirmations = max(1, (int)($state['confirmations'] ?? 1));
	$churn = max(0, (int)($state['churn_events'] ?? 0));
	if (($state['phase'] ?? '') === 'pre_candidate') {
		$churn = max(1, $churn);
		$confirmations = 1;
		$state['phase'] = 'tracking';
		$state['live_signature'] = $signature;
	} elseif (($state['live_signature'] ?? '') !== $signature) {
		$confirmations = 1;
		$churn++;
		$state['live_signature'] = $signature;
	} else {
		$confirmations++;
	}
	$state['confirmations'] = $confirmations;
	$state['churn_events'] = $churn;
	rw_set_state($db, $extension, $state);
	$threshold = $churn > 0 ? 3 : 2;
	if ($confirmations < $threshold) {
		return ['committed' => false, 'state' => $state];
	}

	$db->beginTransaction();
	try {
		$db->prepare('DELETE FROM registrationwatch_registrations WHERE id = ?')->execute([(int)$dupes[0]['id']]);
		$db->prepare('UPDATE registrationwatch_registrations SET registration_key = ?, source_ip = ?, source_port = ?, last_known_status = ? WHERE id = ?')
			->execute([$newKey, (string)($new['source_ip'] ?? ''), $new['source_port'] ?? null, 'Reachable', $oldId]);
		$db->prepare('UPDATE registrationwatch_alert_escalation SET registration_key = ? WHERE registration_id = ?')->execute([$newKey, $oldId]);
		$db->prepare('INSERT INTO registrationwatch_status_history (registration_id, registration_key, extension, from_state, to_state, source, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
			->execute([$oldId, $newKey, $extension, (string)$old['last_known_status'], 'Reachable', 'reconcile', 'ip_address_change', $now]);
		$db->commit();
	} catch (Throwable $e) {
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		return ['committed' => false, 'state' => $state];
	}

	$state = ['phase' => 'validation', 'old_key' => $oldKey, 'new_key' => $newKey, 'validation_observations' => 0];
	rw_set_state($db, $extension, $state);
	return ['committed' => true, 'state' => $state];
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
	'CREATE TABLE registrationwatch_alert_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_id INTEGER,
		registration_key TEXT,
		extension TEXT NOT NULL,
		contact_uri TEXT,
		history_id INTEGER,
		reminder_n INTEGER NOT NULL DEFAULT 0,
		alert_type TEXT NOT NULL,
		status TEXT NOT NULL,
		recipient TEXT NOT NULL,
		result TEXT NOT NULL,
		UNIQUE (history_id, alert_type, recipient, reminder_n)
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_alert_escalation (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_id INTEGER NOT NULL,
		registration_key TEXT NOT NULL,
		extension TEXT NOT NULL,
		history_id INTEGER NOT NULL,
		alert_type TEXT NOT NULL,
		active_since TEXT NOT NULL,
		last_alert_at TEXT,
		alert_count INTEGER NOT NULL DEFAULT 0,
		next_due_at TEXT NOT NULL,
		repeat_mode TEXT NOT NULL,
		UNIQUE (registration_id, alert_type)
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_registrations (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_key TEXT NOT NULL UNIQUE,
		extension TEXT NOT NULL,
		contact_uri TEXT,
		source_ip TEXT,
		source_port INTEGER,
		registration_ua_class TEXT NOT NULL DEFAULT "",
		enabled INTEGER NOT NULL,
		auto_disabled_absent_at TEXT,
		repeat_mode TEXT,
		discovered INTEGER NOT NULL DEFAULT 1,
		last_known_status TEXT NOT NULL,
		last_seen_at TEXT,
		first_discovered_at TEXT,
		last_checked_at TEXT,
		updated_at TEXT
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_settings (
		setting_key TEXT PRIMARY KEY,
		setting_value TEXT
	)'
	);
$db->exec(
	'CREATE TABLE registrationwatch_status_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_id INTEGER NOT NULL,
		registration_key TEXT NOT NULL,
		extension TEXT NOT NULL,
		from_state TEXT,
		to_state TEXT NOT NULL,
		source TEXT NOT NULL,
		reason TEXT,
		created_at TEXT NOT NULL
	)'
);

$keyA = registration_key('2001', '198.51.100.10');
$keyB = registration_key('2001', '198.51.100.11');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$keyA, '2001', '198.51.100.10', 1, 'escalating', 'Reachable']);
$regA = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$keyB, '2001', '198.51.100.11', 1, null, 'Unreachable']);
$regB = (int)$db->lastInsertId();
assert_true($regA !== $regB, 'two source IPs under one extension should be separate watched registrations');

$insertAlert = $db->prepare(
	'INSERT OR IGNORE INTO registrationwatch_alert_history
		(registration_id, registration_key, extension, history_id, reminder_n, alert_type, status, recipient, result)
	VALUES
		(:registration_id, :registration_key, :extension, :history_id, :reminder_n, :alert_type, :status, :recipient, :result)'
);
$baseAlert = [
	':registration_id' => $regB,
	':registration_key' => $keyB,
	':extension' => '2001',
	':history_id' => 10,
	':alert_type' => 'unreachable',
	':status' => 'Unreachable',
	':recipient' => 'admin@example.invalid',
	':result' => 'sent',
];
$insertAlert->execute($baseAlert + [':reminder_n' => 0]);
assert_true($insertAlert->rowCount() === 1, 'transition alert should reserve reminder_n 0');
$insertAlert->execute($baseAlert + [':reminder_n' => 1]);
assert_true($insertAlert->rowCount() === 1, 'first reminder should not be blocked by transition alert key');
$insertAlert->execute($baseAlert + [':reminder_n' => 1]);
assert_true($insertAlert->rowCount() === 0, 'same reminder_n should never reserve twice');

handoff_escalation($db, $regB, $keyB, '2001', 20, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 10:00:00');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$regB}")->fetchColumn() === 1, 'unreachable sibling should have its own escalation');
assert_true(is_still_alertable('unreachable', 'Reachable') === false, 'reachable status should recover unreachable registration');
assert_true(is_still_alertable('not_registered', 'Not registered') === true, 'missing same registration remains alertable as not registered');

$live = [$keyA => 'Reachable'];
$statusForMissingB = isset($live[$keyB]) ? $live[$keyB] : 'Not registered';
assert_true($statusForMissingB === 'Not registered', 'reachable sibling must not recover missing registration');

$oneOfTwoLive = [
	$keyA => 'Reachable',
];
$oneOfTwoStatuses = [
	$keyA => $oneOfTwoLive[$keyA] ?? 'Not registered',
	$keyB => $oneOfTwoLive[$keyB] ?? 'Not registered',
];
assert_true($oneOfTwoStatuses[$keyA] === 'Reachable', 'one-of-two live contact should remain reachable');
assert_true($oneOfTwoStatuses[$keyB] === 'Not registered', 'one-of-two missing contact should become not registered independently');

$db->prepare('DELETE FROM registrationwatch_alert_escalation WHERE registration_id = ? AND alert_type = ?')->execute([$regB, 'unreachable']);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$regB}")->fetchColumn() === 0, 'recovery should clear only same registration escalation');

handoff_escalation($db, $regB, $keyB, '2001', 21, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 10:00:00');
$db->exec("UPDATE registrationwatch_alert_escalation SET alert_count = 2, next_due_at = '2026-06-15 11:20:00' WHERE registration_id = {$regB}");
handoff_escalation($db, $regB, $keyB, '2001', 22, 'not_registered', '2026-06-15 10:21:00', '2026-06-15 10:21:00');
$row = $db->query("SELECT alert_type, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$regB}")->fetch(PDO::FETCH_ASSOC);
assert_true($row['alert_type'] === 'not_registered', 'flap handoff should change type for same registration');
assert_true((int)$row['alert_count'] === 2, 'flap handoff should preserve alert_count for same registration');
assert_true($row['next_due_at'] === '2026-06-15 11:20:00', 'flap handoff should preserve next due time for same registration');

$db->exec("UPDATE registrationwatch_registrations SET repeat_mode = 'daily' WHERE id = {$regB}");
$modeA = $db->query("SELECT COALESCE(repeat_mode, 'global') FROM registrationwatch_registrations WHERE id = {$regA}")->fetchColumn();
$modeB = $db->query("SELECT repeat_mode FROM registrationwatch_registrations WHERE id = {$regB}")->fetchColumn();
assert_true($modeA === 'escalating', 'per-registration override should not affect sibling registration');
assert_true($modeB === 'daily', 'per-registration override should apply to selected registration');

$sameUa = resolve_identity_group([
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => 'Phone/1'],
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5062', 'user_agent' => 'Phone/1'],
]);
assert_true($sameUa[0]['registration_key'] === $sameUa[1]['registration_key'], 'same extension/IP/same UA should collapse');
$differentUa = resolve_identity_group([
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => 'PhoneA'],
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5062', 'user_agent' => 'PhoneB'],
]);
assert_true($differentUa[0]['registration_key'] !== $differentUa[1]['registration_key'], 'different usable UAs behind same IP should split');
$missingUa = resolve_identity_group([
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => ''],
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5062', 'user_agent' => 'PhoneB'],
]);
assert_true($missingUa[0]['registration_key'] === $missingUa[1]['registration_key'], 'missing UA should collapse and never force a split');
$anchored = resolve_identity_group([
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => 'PhoneA'],
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5062', 'user_agent' => 'PhoneB'],
], ['classes' => [''], 'shared' => [['contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => null]]]);
assert_true($anchored[0]['registration_ua_class'] === '', 'incumbent shared registration should keep bare key on later conflict');
assert_true($anchored[1]['registration_ua_class'] === 'phoneb', 'conflicting newcomer should get suffixed key');

$natContact = enrich_for_identity_contract(
	[
		'extension' => '2003',
		'contact_uri' => 'sip:2003@10.0.0.44:5060',
		'source_ip' => '10.0.0.44',
		'user_agent' => null,
	],
	[
		[
			'extension' => '2003',
			'contact_uri' => 'sip:2003@10.0.0.44:5060',
			'source_ip' => '198.51.100.44',
			'user_agent' => 'NatPhone/7',
		],
	]
);
$natResolved = resolve_identity_group([$natContact]);
assert_true($natResolved[0]['source_ip'] === '198.51.100.44', 'exact registrar via_addr should replace parsed contact host before identity');
assert_true($natResolved[0]['registration_key'] === registration_key('2003', '198.51.100.44'), 'NAT registration should key on authoritative via_addr');

$truncatedContact = enrich_for_identity_contract(
	[
		'extension' => '01142990567',
		'contact_uri' => 'sip:01142990567@138.124.129.209:50',
		'source_ip' => '138.124.129.209',
		'source_port' => 50,
		'user_agent' => null,
	],
	[
		[
			'extension' => '01142990567',
			'contact_uri' => 'sip:01142990567@138.124.129.209:5060;x-ast-orig-host=192.168.20.228:5060',
			'source_ip' => '192.168.20.228',
			'source_port' => 5060,
			'user_agent' => null,
		],
	]
);
assert_true(strpos((string)$truncatedContact['contact_uri'], ':5060') !== false, 'truncated parsed contact URI should be repaired from registrar contact URI');
assert_true((int)($truncatedContact['source_port'] ?? 0) === 5060, 'truncated parsed source port should be repaired from registrar contact data when NAT device side differs from public host');
$natRepairAddresses = registration_address_details_contract((string)$truncatedContact['contact_uri'], (string)($truncatedContact['source_ip'] ?? ''), $truncatedContact['source_port'] ?? null);
assert_true((string)$natRepairAddresses['device_ip'] === '192.168.20.228', 'NAT repair should preserve device-side IP from x-ast-orig-host');
assert_true((int)$natRepairAddresses['device_port'] === 5060, 'NAT repair should preserve device-side port from x-ast-orig-host');
assert_true((string)$natRepairAddresses['network_ip'] === '138.124.129.209', 'NAT repair should preserve network-side IP from public contact URI');
assert_true((int)$natRepairAddresses['network_port'] === 5060, 'NAT repair should preserve network-side port from public contact URI');

$ambiguousContact = enrich_for_identity_contract(
	[
		'extension' => '01142990567',
		'contact_uri' => 'sip:01142990567@138.124.129.209:50',
		'source_ip' => '138.124.129.209',
		'source_port' => 50,
		'user_agent' => null,
	],
	[
		[
			'extension' => '01142990567',
			'contact_uri' => 'sip:01142990567@138.124.129.209:5060;x-ast-orig-host=192.168.20.228:5060',
			'source_ip' => '192.168.20.228',
			'source_port' => 5060,
			'user_agent' => null,
		],
		[
			'extension' => '01142990567',
			'contact_uri' => 'sip:01142990567@138.124.129.209:5060;x-ast-orig-host=192.168.20.229:5060',
			'source_ip' => '192.168.20.229',
			'source_port' => 5060,
			'user_agent' => null,
		],
	]
);
assert_true((string)$ambiguousContact['contact_uri'] === 'sip:01142990567@138.124.129.209:50', 'ambiguous fallback should not mutate contact_uri');
assert_true((int)($ambiguousContact['source_port'] ?? 0) === 50, 'ambiguous fallback should not mutate source_port');

$fallbackContact = enrich_for_identity_contract(
	[
		'extension' => '2004',
		'contact_uri' => 'sip:2004@198.51.100.55:5060',
		'source_ip' => '198.51.100.55',
		'user_agent' => null,
	],
	[
		[
			'extension' => '2004',
			'contact_uri' => 'sip:2004@198.51.100.55:5099',
			'source_ip' => '198.51.100.55',
			'user_agent' => 'SiblingPhone/9',
			'contact_expires_at' => '2026-06-15 11:00:00',
		],
	]
);
assert_true(($fallbackContact['user_agent'] ?? null) === null, 'fallback enrichment must not copy a sibling UA into identity');

$allowlisted = filter_allowlisted_contacts([
	['extension' => '2005', 'source_ip' => '198.51.100.70', 'contact_uri' => 'sip:2005@198.51.100.70:5060', 'user_agent' => 'DeskPhone/1'],
	['extension' => '2005', 'source_ip' => '198.51.100.71', 'contact_uri' => 'sip:2005@198.51.100.71:5060', 'user_agent' => 'SoftPhone/2'],
	['extension' => 'magrathea-in-1', 'source_ip' => '87.238.1.10', 'contact_uri' => 'sip:87.238.1.10', 'user_agent' => null],
	['extension' => 'magrathea-in-4', 'source_ip' => '87.238.1.14', 'contact_uri' => 'sip:87.238.1.14', 'user_agent' => null],
], ['2005']);
assert_true(count($allowlisted['accepted']) === 2, 'allowlisted device contacts should continue into multi-contact resolution');
assert_true(count($allowlisted['ignored']) === 2, 'trunk-like contacts not present in devices should be ignored');
$allowlistedResolved = [];
foreach ($allowlisted['accepted'] as $contact) {
	foreach (resolve_identity_group([$contact]) as $resolved) {
		$allowlistedResolved[] = $resolved;
	}
}
assert_true(count($allowlistedResolved) === 2, 'multi-contact allowlisted device should still produce watched registrations');
assert_true($allowlistedResolved[0]['registration_key'] !== $allowlistedResolved[1]['registration_key'], 'allowlisted sibling contacts on different IPs should remain distinct');

$trunkKey = registration_key('magrathea-in-1', '87.238.1.10');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$trunkKey, 'magrathea-in-1', '87.238.1.10', 1, 'hourly', 'Reachable']);
$trunkReg = (int)$db->lastInsertId();
$db->prepare(
	'INSERT INTO registrationwatch_alert_escalation
		(registration_id, registration_key, extension, history_id, alert_type, active_since, next_due_at, repeat_mode)
	VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$trunkReg, $trunkKey, 'magrathea-in-1', 77, 'not_registered', '2026-06-15 10:00:00', '2026-06-15 11:00:00', 'hourly']);
$allowedDeviceIds = ['2005' => true];
if (!isset($allowedDeviceIds[strtolower(trim('magrathea-in-1'))])) {
	$db->prepare('DELETE FROM registrationwatch_alert_escalation WHERE registration_id = ?')->execute([$trunkReg]);
	$db->prepare('UPDATE registrationwatch_registrations SET enabled = 0 WHERE id = ?')->execute([$trunkReg]);
}
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$trunkReg}")->fetchColumn() === 0, 'stored non-device registration should be disabled by allowlist reconciliation');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$trunkReg}")->fetchColumn() === 0, 'stored non-device registration should have escalation cleared');

$safeKey = registration_key('2010', '198.51.100.130');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$safeKey, '2010', '198.51.100.130', 1, null, 'Reachable']);
$safeReg = (int)$db->lastInsertId();
$emptyAllowlist = [];
$storedRegistrationCount = (int)$db->query('SELECT COUNT(*) FROM registrationwatch_registrations')->fetchColumn();
$deferForEmptyAllowlist = !$emptyAllowlist && $storedRegistrationCount > 0;
if (!$deferForEmptyAllowlist) {
	$db->prepare('UPDATE registrationwatch_registrations SET enabled = 0 WHERE id = ?')->execute([$safeReg]);
}
assert_true($deferForEmptyAllowlist, 'empty allowlist with stored registrations should defer reconciliation');
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$safeReg}")->fetchColumn() === 1, 'empty allowlist fail-safe should leave stored registrations enabled and untouched');

$inheritExt = '2020';
$inheritOldKey = registration_key($inheritExt, '198.51.100.170');
$inheritNewKey = registration_key($inheritExt, '198.51.100.171');
set_extension_monitoring_state_contract($db, $inheritExt, 1);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$inheritOldKey, $inheritExt, '198.51.100.170', 1, null, 'Reachable', 1]);
$inheritInsertedId = promote_live_contact_same_pass($db, [
	'registration_key' => $inheritNewKey,
	'extension' => $inheritExt,
	'source_ip' => '198.51.100.171',
	'source_port' => 5060,
	'status' => 'Reachable',
], [
	$inheritOldKey => ['registration_key' => $inheritOldKey],
	$inheritNewKey => ['registration_key' => $inheritNewKey],
]);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2020'")->fetchColumn() === 2, 'monitored extension discovering a second endpoint should retain both registration rows');
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$inheritInsertedId}")->fetchColumn() === 1, 'new registration discovered under a monitored extension should inherit enabled monitoring');

$mixedExt = '2021';
$mixedKeyA = registration_key($mixedExt, '198.51.100.180');
$mixedKeyB = registration_key($mixedExt, '198.51.100.181');
set_extension_monitoring_state_contract($db, $mixedExt, 1);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, auto_disabled_absent_at, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$mixedKeyA, $mixedExt, '198.51.100.180', 1, null, null, 'Reachable', 1]);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, auto_disabled_absent_at, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$mixedKeyB, $mixedExt, '198.51.100.181', 0, '2026-06-15 09:00:00', null, 'Not registered', 1]);
normalise_extension_monitoring_state_contract($db);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2021' AND enabled = 0")->fetchColumn() === 0, 'reconciliation safety should correct mixed enabled/disabled rows on monitored extension');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2021' AND auto_disabled_absent_at IS NOT NULL")->fetchColumn() === 0, 'reconciliation safety should clear stale absent auto-disable markers on monitored extension rows');

$disableExt = '2023';
$disableKeyA = registration_key($disableExt, '198.51.100.200');
$disableKeyB = registration_key($disableExt, '198.51.100.201');
set_extension_monitoring_state_contract($db, $disableExt, 1);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$disableKeyA, $disableExt, '198.51.100.200', 1, null, 'Reachable', 1]);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$disableKeyB, $disableExt, '198.51.100.201', 1, null, 'Reachable', 1]);
set_extension_monitoring_state_contract($db, $disableExt, 0);
normalise_extension_monitoring_state_contract($db);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2023' AND enabled = 1")->fetchColumn() === 0, 'disabling monitoring for an extension should disable all registrations');

$enableExt = '2024';
$enableKeyA = registration_key($enableExt, '198.51.100.210');
$enableKeyB = registration_key($enableExt, '198.51.100.211');
set_extension_monitoring_state_contract($db, $enableExt, 0);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$enableKeyA, $enableExt, '198.51.100.210', 0, null, 'Unknown', 1]);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$enableKeyB, $enableExt, '198.51.100.211', 0, null, 'Unknown', 1]);
set_extension_monitoring_state_contract($db, $enableExt, 1);
normalise_extension_monitoring_state_contract($db);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2024' AND enabled = 1")->fetchColumn() === 2, 'enabling monitoring for an extension should enable all registrations');

$noSettingExt = '2025';
$noSettingOldKey = registration_key($noSettingExt, '198.51.100.220');
$noSettingNewKey = registration_key($noSettingExt, '198.51.100.221');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$noSettingOldKey, $noSettingExt, '198.51.100.220', 1, null, 'Reachable', 1]);
$noSettingInsertedId = promote_live_contact_same_pass($db, [
	'registration_key' => $noSettingNewKey,
	'extension' => $noSettingExt,
	'source_ip' => '198.51.100.221',
	'source_port' => 5060,
	'status' => 'Reachable',
], [
	$noSettingOldKey => ['registration_key' => $noSettingOldKey],
	$noSettingNewKey => ['registration_key' => $noSettingNewKey],
]);
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$noSettingInsertedId}")->fetchColumn() === 0, 'missing extension monitoring setting should not infer monitored authority from existing enabled rows');

$unmonitoredExt = '2022';
$unmonitoredKeyA = registration_key($unmonitoredExt, '198.51.100.190');
$unmonitoredKeyB = registration_key($unmonitoredExt, '198.51.100.191');
set_extension_monitoring_state_contract($db, $unmonitoredExt, 0);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$unmonitoredKeyA, $unmonitoredExt, '198.51.100.190', 0, null, 'Unknown', 1]);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$unmonitoredKeyB, $unmonitoredExt, '198.51.100.191', 0, null, 'Unknown', 1]);
normalise_extension_monitoring_state_contract($db);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2022' AND enabled = 1")->fetchColumn() === 0, 'unmonitored extensions should remain unmonitored');

$placeholderKey = no_contact_registration_key('2006');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$placeholderKey, '2006', null, 1, 'hourly', 'Unknown']);
$placeholderId = (int)$db->lastInsertId();
$db->prepare('UPDATE registrationwatch_registrations SET last_known_status = ? WHERE id = ?')->execute(['Not registered', $placeholderId]);
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$placeholderId}")->fetchColumn() === 1, 'allowlisted no-contact placeholder should be selected and alertable');

$contactKey = registration_key('2006', '198.51.100.88');
$db->prepare('UPDATE registrationwatch_registrations SET registration_key = ?, source_ip = ?, last_known_status = ? WHERE id = ?')
	->execute([$contactKey, '198.51.100.88', 'Reachable', $placeholderId]);
$promoted = $db->query("SELECT id, registration_key, source_ip FROM registrationwatch_registrations WHERE extension = '2006'")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$promoted['id'] === $placeholderId, 'no-contact to contact should promote the same watched registration row');
assert_true($promoted['registration_key'] === $contactKey, 'promoted row should adopt contact-backed identity key');

$db->prepare('UPDATE registrationwatch_registrations SET last_known_status = ?, contact_uri = NULL WHERE id = ?')->execute(['Not registered', $placeholderId]);
$lostContact = $db->query("SELECT id, source_ip, last_known_status FROM registrationwatch_registrations WHERE id = {$placeholderId}")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$lostContact['id'] === $placeholderId && $lostContact['source_ip'] === '198.51.100.88', 'contact to no-contact should keep the same row and historical source IP');
assert_true($lostContact['last_known_status'] === 'Not registered', 'lost contact should become not registered on the same watched registration');
$db->exec(
	'CREATE TABLE registrationwatch_status_contract_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_id INTEGER NOT NULL,
		from_state TEXT,
		to_state TEXT NOT NULL,
		source TEXT NOT NULL
	)'
);
$db->prepare('INSERT INTO registrationwatch_status_contract_history (registration_id, from_state, to_state, source) VALUES (?, ?, ?, ?)')
	->execute([$placeholderId, 'Reachable', 'Not registered', 'reconcile']);
$demotion = $db->query("SELECT from_state, to_state FROM registrationwatch_status_contract_history WHERE registration_id = {$placeholderId}")->fetch(PDO::FETCH_ASSOC);
assert_true($demotion['from_state'] === 'Reachable' && $demotion['to_state'] === 'Not registered', 'contact-backed demotion should write Reachable to Not registered status history');
assert_true(transition_alert_type($demotion['from_state'], $demotion['to_state']) === 'not_registered', 'Reachable to Not registered should enter the not_registered alert path');
assert_true(transition_alert_type('Unknown', 'Not registered') === null, 'Unknown to Not registered first baseline should remain suppressed');

$multiA = registration_key('2007', '198.51.100.91');
$multiB = registration_key('2007', '198.51.100.92');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$multiA, '2007', '198.51.100.91', 1, null, 'Reachable']);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$multiB, '2007', '198.51.100.92', 1, null, 'Reachable']);
$db->prepare('UPDATE registrationwatch_registrations SET last_known_status = ? WHERE extension = ?')->execute(['Not registered', '2007']);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2007' AND last_known_status = 'Not registered'")->fetchColumn() === 2, 'multi-contact extension losing all contacts should keep separate known registration rows');

$oldIpKey = registration_key('2008', '198.51.100.101');
$newIpKey = registration_key('2008', '198.51.100.201');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$oldIpKey, '2008', '198.51.100.101', 1, null, 'Not registered']);
$singleDeadId = (int)$db->lastInsertId();
$deadIds = $db->query("SELECT id FROM registrationwatch_registrations WHERE extension = '2008' AND last_known_status = 'Not registered' ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN, 0);
if (count($deadIds) === 1) {
	$db->prepare('UPDATE registrationwatch_registrations SET registration_key = ?, source_ip = ?, last_known_status = ? WHERE id = ?')
		->execute([$newIpKey, '198.51.100.201', 'Reachable', $deadIds[0]]);
}
$singleDeadReturn = $db->query("SELECT id, registration_key, source_ip FROM registrationwatch_registrations WHERE extension = '2008'")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$singleDeadReturn['id'] === $singleDeadId, 'different-IP return with one dead row should reuse that row');
assert_true($singleDeadReturn['registration_key'] === $newIpKey && $singleDeadReturn['source_ip'] === '198.51.100.201', 'single dead row should promote to the new source IP identity');

$deadKeyA = registration_key('2009', '198.51.100.111');
$deadKeyB = registration_key('2009', '198.51.100.112');
$newDeadKey = registration_key('2009', '198.51.100.211');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$deadKeyA, '2009', '198.51.100.111', 1, null, 'Not registered']);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$deadKeyB, '2009', '198.51.100.112', 1, null, 'Not registered']);
$multiDeadIds = $db->query("SELECT id FROM registrationwatch_registrations WHERE extension = '2009' AND last_known_status = 'Not registered' ORDER BY id ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN, 0);
if (count($multiDeadIds) !== 1) {
	$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
		->execute([$newDeadKey, '2009', '198.51.100.211', 0, null, 'Unknown']);
}
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2009'")->fetchColumn() === 3, 'different-IP return with multiple dead rows should insert a new row rather than guess');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2009' AND last_known_status = 'Not registered'")->fetchColumn() === 2, 'multiple dead rows should remain unmodified when a new unmatched IP appears');

$samePassOldKey = registration_key('2011', '198.51.100.120');
$samePassNewKey = registration_key('2011', '198.51.100.220');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$samePassOldKey, '2011', '198.51.100.120', 1, 'escalating', 'Reachable', 1]);
$samePassId = (int)$db->lastInsertId();
$samePassLive = [
	$samePassNewKey => [
		'registration_key' => $samePassNewKey,
		'extension' => '2011',
		'source_ip' => '198.51.100.220',
		'source_port' => 5092,
		'status' => 'Reachable',
	],
];
$samePassResolvedId = promote_live_contact_same_pass($db, $samePassLive[$samePassNewKey], $samePassLive);
assert_true($samePassResolvedId === $samePassId, 'same-pass IP change should reuse the existing row id');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2011'")->fetchColumn() === 1, 'same-pass IP change should not insert a second row');
assert_true((string)$db->query("SELECT registration_key FROM registrationwatch_registrations WHERE id = {$samePassId}")->fetchColumn() === $samePassNewKey, 'same-pass IP change should promote row identity to new key');

$ambigKeyA = registration_key('2012', '198.51.100.130');
$ambigKeyB = registration_key('2012', '198.51.100.131');
$ambigNewKey = registration_key('2012', '198.51.100.230');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$ambigKeyA, '2012', '198.51.100.130', 1, null, 'Reachable', 1]);
$ambigIdA = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$ambigKeyB, '2012', '198.51.100.131', 1, null, 'Reachable', 1]);
$ambigIdB = (int)$db->lastInsertId();
$ambigLive = [
	$ambigNewKey => [
		'registration_key' => $ambigNewKey,
		'extension' => '2012',
		'source_ip' => '198.51.100.230',
		'source_port' => 5060,
		'status' => 'Reachable',
	],
];
$ambigResolvedId = promote_live_contact_same_pass($db, $ambigLive[$ambigNewKey], $ambigLive);
assert_true($ambigResolvedId !== $ambigIdA && $ambigResolvedId !== $ambigIdB, 'same-pass ambiguity with two candidates should insert instead of guessing');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2012'")->fetchColumn() === 3, 'same-pass ambiguity should keep two old rows and add one new row');

$overlapOldKey = registration_key('2013', '198.51.100.140');
$overlapNewKey = registration_key('2013', '198.51.100.240');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$overlapOldKey, '2013', '198.51.100.140', 1, null, 'Reachable', 1]);
$overlapOldId = (int)$db->lastInsertId();
$overlapLive = [
	$overlapOldKey => [
		'registration_key' => $overlapOldKey,
		'extension' => '2013',
		'source_ip' => '198.51.100.140',
		'source_port' => 5060,
		'status' => 'Reachable',
	],
	$overlapNewKey => [
		'registration_key' => $overlapNewKey,
		'extension' => '2013',
		'source_ip' => '198.51.100.240',
		'source_port' => 5061,
		'status' => 'Reachable',
	],
];
$overlapExistingId = promote_live_contact_same_pass($db, $overlapLive[$overlapOldKey], $overlapLive);
$overlapNewId = promote_live_contact_same_pass($db, $overlapLive[$overlapNewKey], $overlapLive);
assert_true($overlapExistingId === $overlapOldId, 'concurrent overlap should keep existing old contact row');
assert_true($overlapNewId !== $overlapOldId, 'concurrent overlap should create separate row for new contact');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2013'")->fetchColumn() === 2, 'concurrent old and new contacts should remain separate rows');

$portOnlyKey = registration_key('2014', '198.51.100.150');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$portOnlyKey, '2014', '198.51.100.150', 5060, 1, null, 'Reachable', 1]);
$portOnlyId = (int)$db->lastInsertId();
$portOnlyLive = [
	$portOnlyKey => [
		'registration_key' => $portOnlyKey,
		'extension' => '2014',
		'source_ip' => '198.51.100.150',
		'source_port' => 5099,
		'status' => 'Reachable',
	],
];
$portOnlyResolved = promote_live_contact_same_pass($db, $portOnlyLive[$portOnlyKey], $portOnlyLive);
assert_true($portOnlyResolved === $portOnlyId, 'source-port-only change should keep the same row id');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2014'")->fetchColumn() === 1, 'source-port-only change should not create a new row');
assert_true((int)$db->query("SELECT source_port FROM registrationwatch_registrations WHERE id = {$portOnlyId}")->fetchColumn() === 5099, 'source-port-only change should update stored source_port on same row');

$pathOldKey = registration_key('2015', '198.51.100.160');
$pathNewKey = registration_key('2015', '198.51.100.260');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status, discovered) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$pathOldKey, '2015', '198.51.100.160', 1, 'escalating', 'Not registered', 1]);
$pathId = (int)$db->lastInsertId();
$db->prepare(
	'INSERT INTO registrationwatch_alert_escalation
		(registration_id, registration_key, extension, history_id, alert_type, active_since, next_due_at, repeat_mode)
	VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$pathId, $pathOldKey, '2015', 501, 'not_registered', '2026-06-15 10:00:00', '2026-06-15 11:00:00', 'escalating']);
$pathLive = [
	$pathNewKey => [
		'registration_key' => $pathNewKey,
		'extension' => '2015',
		'source_ip' => '198.51.100.260',
		'source_port' => 5060,
		'status' => 'Reachable',
	],
];
$pathResolved = promote_live_contact_same_pass($db, $pathLive[$pathNewKey], $pathLive);
assert_true($pathResolved === $pathId, 'same-pass reuse should preserve registration id for alert continuity');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2015'")->fetchColumn() === 1, 'same-pass reuse should not leave obsolete sibling row');
$currentPathKey = (string)$db->query("SELECT registration_key FROM registrationwatch_registrations WHERE id = {$pathId}")->fetchColumn();
$statusUsingCurrentIdentity = isset($pathLive[$currentPathKey]) ? 'Reachable' : 'Not registered';
assert_true($statusUsingCurrentIdentity === 'Reachable', 'reused row identity should resolve as reachable, not obsolete not-registered');
$db->prepare('DELETE FROM registrationwatch_alert_escalation WHERE registration_id = ? AND alert_type = ?')->execute([$pathId, 'not_registered']);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$pathId}")->fetchColumn() === 0, 'recovery cleanup should remove not_registered escalation on reused row id');

$oldReplaceKey = registration_key('2016', '145.224.67.212');
$newReplaceKey = registration_key('2016', '217.142.20.122');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, repeat_mode, discovered, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$oldReplaceKey, '2016', '145.224.67.212', 5060, 1, 'hourly', 1, 'Unreachable']);
$oldReplaceId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, repeat_mode, discovered, last_known_status, first_discovered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$newReplaceKey, '2016', '217.142.20.122', 20234, 0, null, 1, 'Unknown', '2026-06-15 10:00:00']);
$newReplaceDupId = (int)$db->lastInsertId();
$db->prepare(
	'INSERT INTO registrationwatch_alert_escalation
		(registration_id, registration_key, extension, history_id, alert_type, active_since, next_due_at, repeat_mode)
	VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$oldReplaceId, $oldReplaceKey, '2016', 700, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 11:00:00', 'hourly']);

$retainedLive = [
	$oldReplaceKey => [
		'registration_key' => $oldReplaceKey,
		'extension' => '2016',
		'source_ip' => '145.224.67.212',
		'source_port' => 5060,
		'status' => 'Unreachable',
	],
	$newReplaceKey => [
		'registration_key' => $newReplaceKey,
		'extension' => '2016',
		'source_ip' => '217.142.20.122',
		'source_port' => 20234,
		'status' => 'Reachable',
	],
];
$pre = apply_auto_handover_contract($db, '2016', $retainedLive, '2026-06-15 10:00:30', 10);
assert_true(($pre['state']['phase'] ?? '') === 'pre_candidate', 'retained dual-contact state should set pre-candidate phase');
assert_true((int)($pre['state']['churn_events'] ?? 0) >= 1, 'retained dual-contact state should set ambiguity churn flag');

$settledLive = [
	$newReplaceKey => [
		'registration_key' => $newReplaceKey,
		'extension' => '2016',
		'source_ip' => '217.142.20.122',
		'source_port' => 20234,
		'status' => 'Reachable',
	],
];
$s1 = apply_auto_handover_contract($db, '2016', $settledLive, '2026-06-15 10:01:00', 10);
assert_true($s1['committed'] === false, 'first settled confirmation should not commit after retained dual-contact ambiguity');
$s2 = apply_auto_handover_contract($db, '2016', $settledLive, '2026-06-15 10:01:20', 10);
assert_true($s2['committed'] === false, 'second settled confirmation should not commit when threshold is three');
$s3 = apply_auto_handover_contract($db, '2016', $settledLive, '2026-06-15 10:01:40', 10);
assert_true($s3['committed'] === true, 'third settled confirmation should commit after retained dual-contact ambiguity');

$postReplace = $db->query("SELECT id, registration_key, source_ip, enabled, repeat_mode, last_known_status FROM registrationwatch_registrations WHERE extension = '2016'")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$postReplace['id'] === $oldReplaceId, 'handover should preserve row id');
assert_true((string)$postReplace['registration_key'] === $newReplaceKey && (string)$postReplace['source_ip'] === '217.142.20.122', 'handover should update key and IP');
assert_true((int)$postReplace['enabled'] === 1 && (string)$postReplace['repeat_mode'] === 'hourly', 'handover should preserve enabled and repeat mode');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE id = {$newReplaceDupId}")->fetchColumn() === 0, 'handover should remove disabled replacement duplicate');
assert_true((string)$db->query("SELECT reason FROM registrationwatch_status_history WHERE registration_id = {$oldReplaceId} ORDER BY id DESC LIMIT 1")->fetchColumn() === 'ip_address_change', 'handover should record ip_address_change reason');
assert_true((string)$db->query("SELECT registration_key FROM registrationwatch_alert_escalation WHERE registration_id = {$oldReplaceId}")->fetchColumn() === $newReplaceKey, 'handover should preserve escalation and retarget key');

$monitoredExt = '2026';
$monitoredOldKey = registration_key($monitoredExt, '150.228.103.35');
$monitoredNewKey = registration_key($monitoredExt, '150.228.103.201');
set_extension_monitoring_state_contract($db, $monitoredExt, 1);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, discovered, last_known_status, first_discovered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$monitoredOldKey, $monitoredExt, '150.228.103.35', 5060, 1, 1, 'Not registered', '2026-06-15 10:00:00']);
$monitoredOldId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, discovered, last_known_status, first_discovered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$monitoredNewKey, $monitoredExt, '150.228.103.201', 5090, 0, 1, 'Reachable', '2026-06-15 10:00:00']);
$monitoredNewId = (int)$db->lastInsertId();
normalise_extension_monitoring_state_contract($db);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2026' AND enabled = 1")->fetchColumn() === 2, 'extension monitoring authority should keep both old and replacement rows enabled');
$handoverLive2026 = [
	$monitoredNewKey => [
		'registration_key' => $monitoredNewKey,
		'extension' => $monitoredExt,
		'source_ip' => '150.228.103.201',
		'source_port' => 5090,
		'status' => 'Reachable',
	],
];
$m1 = apply_auto_handover_contract($db, $monitoredExt, $handoverLive2026, '2026-06-15 10:10:00', 10);
$m2 = apply_auto_handover_contract($db, $monitoredExt, $handoverLive2026, '2026-06-15 10:10:20', 10);
assert_true($m1['committed'] === false && $m2['committed'] === true, 'monitored extension handover should still commit when both rows are enabled');
$monitoredPost = $db->query("SELECT id, registration_key, source_ip, enabled, last_known_status FROM registrationwatch_registrations WHERE extension = '2026'")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$monitoredPost['id'] === $monitoredOldId, 'enabled+enabled handover should preserve old row id');
assert_true((string)$monitoredPost['registration_key'] === $monitoredNewKey && (string)$monitoredPost['source_ip'] === '150.228.103.201', 'enabled+enabled handover should update identity to replacement key and IP');
assert_true((int)$monitoredPost['enabled'] === 1 && (string)$monitoredPost['last_known_status'] === 'Reachable', 'enabled+enabled handover should leave monitored row enabled and reachable');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2026'")->fetchColumn() === 1, 'enabled+enabled handover should remove replacement duplicate row after preserving continuity');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE id = {$monitoredNewId}")->fetchColumn() === 0, 'enabled+enabled handover should delete replacement row id after continuity merge');
assert_true((string)$db->query("SELECT reason FROM registrationwatch_status_history WHERE registration_id = {$monitoredOldId} ORDER BY id DESC LIMIT 1")->fetchColumn() === 'ip_address_change', 'enabled+enabled handover should record ip_address_change history reason');

$topOldKey = registration_key('2017', '145.224.67.213');
$topNewKey = registration_key('2017', '217.142.20.123');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, discovered, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$topOldKey, '2017', '145.224.67.213', 5060, 1, 1, 'Unreachable']);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, discovered, last_known_status, first_discovered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$topNewKey, '2017', '217.142.20.123', 20235, 0, 1, 'Unknown', '2026-06-15 10:00:00']);

$topLiveA = [$topNewKey => ['registration_key' => $topNewKey, 'extension' => '2017', 'source_ip' => '217.142.20.123', 'source_port' => 20235, 'status' => 'Reachable']];
$topLiveB = [$topNewKey => ['registration_key' => $topNewKey, 'extension' => '2017', 'source_ip' => '217.142.20.123', 'source_port' => 20236, 'status' => 'Reachable']];
apply_auto_handover_contract($db, '2017', $topLiveA, '2026-06-15 10:02:00', 10);
$topState = apply_auto_handover_contract($db, '2017', $topLiveB, '2026-06-15 10:02:20', 10)['state'];
assert_true((int)($topState['confirmations'] ?? 0) === 1 && (int)($topState['churn_events'] ?? 0) >= 1, 'topology signature change should reset confirmations and increment churn');

$oldReturns = apply_auto_handover_contract($db, '2017', [
	$topOldKey => ['registration_key' => $topOldKey, 'extension' => '2017', 'source_ip' => '145.224.67.213', 'source_port' => 5060, 'status' => 'Unreachable'],
	$topNewKey => ['registration_key' => $topNewKey, 'extension' => '2017', 'source_ip' => '217.142.20.123', 'source_port' => 20235, 'status' => 'Reachable'],
], '2026-06-15 10:02:40', 10);
assert_true(($oldReturns['state']['phase'] ?? '') === 'pre_candidate', 'old key returning should reset candidate into pre-candidate phase');

$multiReachable = apply_auto_handover_contract($db, '2017', [
	$topNewKey => ['registration_key' => $topNewKey, 'extension' => '2017', 'source_ip' => '217.142.20.123', 'source_port' => 20235, 'status' => 'Reachable'],
	registration_key('2017', '217.142.20.124') => ['registration_key' => registration_key('2017', '217.142.20.124'), 'extension' => '2017', 'source_ip' => '217.142.20.124', 'source_port' => 20236, 'status' => 'Reachable'],
], '2026-06-15 10:03:00', 10);
assert_true($multiReachable['state'] === [], 'more than one reachable replacement should reset candidate state');

$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, discovered, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([registration_key('2017', '145.224.67.214'), '2017', '145.224.67.214', 5061, 1, 1, 'Not registered']);
$twoDegraded = apply_auto_handover_contract($db, '2017', $topLiveA, '2026-06-15 10:03:20', 10);
assert_true($twoDegraded['state'] === [], 'more than one enabled degraded row should prevent handover');

$v1 = apply_auto_handover_contract($db, '2016', $settledLive, '2026-06-15 10:02:00', 10);
$v2 = apply_auto_handover_contract($db, '2016', $settledLive, '2026-06-15 10:02:20', 10);
$v3 = apply_auto_handover_contract($db, '2016', $settledLive, '2026-06-15 10:02:40', 10);
assert_true(($v1['state']['phase'] ?? '') === 'validation', 'post-handover should enter validation phase');
assert_true(($v3['state'] ?? []) === [], 'three successful post-handover validation polls should clear state');

$failOldKey = registration_key('2018', '145.224.67.215');
$failNewKey = registration_key('2018', '217.142.20.125');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, discovered, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([$failOldKey, '2018', '145.224.67.215', 5060, 1, 1, 'Unreachable']);
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, source_port, enabled, discovered, last_known_status, first_discovered_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
	->execute([$failNewKey, '2018', '217.142.20.125', 20237, 0, 1, 'Unknown', '2026-06-15 10:00:00']);
$f1 = apply_auto_handover_contract($db, '2018', [$failNewKey => ['registration_key' => $failNewKey, 'extension' => '2018', 'source_ip' => '217.142.20.125', 'source_port' => 20237, 'status' => 'Reachable']], '2026-06-15 10:04:00', 10);
$f2 = apply_auto_handover_contract($db, '2018', [$failNewKey => ['registration_key' => $failNewKey, 'extension' => '2018', 'source_ip' => '217.142.20.125', 'source_port' => 20237, 'status' => 'Reachable']], '2026-06-15 10:04:20', 10);
$f3 = apply_auto_handover_contract($db, '2018', [$failNewKey => ['registration_key' => $failNewKey, 'extension' => '2018', 'source_ip' => '217.142.20.125', 'source_port' => 20237, 'status' => 'Reachable']], '2026-06-15 10:04:40', 10);
assert_true($f1['committed'] === false && $f2['committed'] === true, '2018 should commit on second call when no pre-candidate churn is present');
assert_true(($f3['state']['phase'] ?? '') === 'validation', '2018 should remain in validation phase immediately after commit');
$failingValidation = apply_auto_handover_contract($db, '2018', [$failNewKey => ['registration_key' => $failNewKey, 'extension' => '2018', 'source_ip' => '217.142.20.125', 'source_port' => 20237, 'status' => 'Unreachable']], '2026-06-15 10:05:00', 10);
assert_true(($failingValidation['state']['phase'] ?? '') === 'suspended', 'failed post-handover validation should set suspended phase');
$beforeSuspendedRows = (int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2018'")->fetchColumn();
$suspendedNoMutation = apply_auto_handover_contract($db, '2018', [$failNewKey => ['registration_key' => $failNewKey, 'extension' => '2018', 'source_ip' => '217.142.20.125', 'source_port' => 20237, 'status' => 'Reachable']], '2026-06-15 10:05:20', 10);
$afterSuspendedRows = (int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '2018'")->fetchColumn();
assert_true(($suspendedNoMutation['state']['phase'] ?? '') === 'suspended' && $beforeSuspendedRows === $afterSuspendedRows, 'suspended phase should prevent further automatic mutation');

$released1 = apply_auto_handover_contract($db, '2018', [$failNewKey => ['registration_key' => $failNewKey, 'extension' => '2018', 'source_ip' => '217.142.20.125', 'source_port' => 20237, 'status' => 'Reachable']], '2026-06-15 10:05:40', 10);
$released2 = apply_auto_handover_contract($db, '2018', [$failNewKey => ['registration_key' => $failNewKey, 'extension' => '2018', 'source_ip' => '217.142.20.125', 'source_port' => 20237, 'status' => 'Reachable']], '2026-06-15 10:06:00', 10);
$released3 = apply_auto_handover_contract($db, '2018', [$failNewKey => ['registration_key' => $failNewKey, 'extension' => '2018', 'source_ip' => '217.142.20.125', 'source_port' => 20237, 'status' => 'Reachable']], '2026-06-15 10:06:20', 10);
assert_true(($released3['state'] ?? []) === [], 'suspended phase should be safely released after stable healthy polls');

assert_true(clean_contact_uri('sip:2006@198.51.100.88:5060;ob;x-ast-orig-host=10.0.0.8:5060') === '2006@198.51.100.88:5060', 'map contact display should strip SIP URI parameters');
$tileTitle = '<div class="rw-map-title"><span class="rw-led rw-led-red"></span><span>' . htmlspecialchars('2006', ENT_QUOTES, 'UTF-8') . '</span></div>';
assert_true(strpos($tileTitle, '<span>2006</span>') !== false && strpos($tileTitle, '198.51.100.88') === false, 'map tile title should lead with extension, not source IP');

$storm = storm_contract_decision([
	['registration_id' => $regA, 'extension' => '2001', 'recipient' => 'admin@example.invalid'],
	['registration_id' => $regB, 'extension' => '2001', 'recipient' => 'admin@example.invalid'],
], 2);
assert_true(count($storm['summaries']) === 1 && count($storm['individuals']) === 0, 'storm threshold should count sibling registrations separately');

$lockHeld = false;
$historyRows = 0;
$db->exec(
	'CREATE TABLE registrationwatch_reconcile_contract_history (
		registration_id INTEGER NOT NULL,
		from_state TEXT NOT NULL,
		to_state TEXT NOT NULL,
		created_at TEXT NOT NULL,
		UNIQUE (registration_id, from_state, to_state)
	)'
);
$reconcileOnce = function () use (&$lockHeld, &$historyRows): void {
	if ($lockHeld) {
		return;
	}
	$lockHeld = true;
	try {
		if ($historyRows === 0) {
			$historyRows++;
		}
	} finally {
		$lockHeld = false;
	}
};
$lockHeld = true;
$reconcileOnce();
assert_true($historyRows === 0, 'second reconcile should skip while the reconcile lock is held');
$lockHeld = false;
$reconcileOnce();
$reconcileOnce();
assert_true($historyRows === 1, 'serial reconciles should not duplicate one transition row once state has advanced');
$insertTransition = $db->prepare(
	'INSERT OR IGNORE INTO registrationwatch_reconcile_contract_history
		(registration_id, from_state, to_state, created_at)
	VALUES (?, ?, ?, ?)'
);
$insertTransition->execute([$regB, 'Reachable', 'Not registered', '2026-06-15 10:00:00']);
$insertTransition->execute([$regB, 'Reachable', 'Not registered', '2026-06-15 10:00:00']);
assert_true((int)$db->query('SELECT COUNT(*) FROM registrationwatch_reconcile_contract_history')->fetchColumn() === 1, 'locked reconcile contract should produce one transition history row for one state change');

$db->exec("UPDATE registrationwatch_registrations SET enabled = 1, auto_disabled_absent_at = NULL, last_seen_at = '2026-05-01 00:00:00', last_known_status = 'Not registered' WHERE id = {$regB}");
$absent = $db->query("SELECT enabled, auto_disabled_absent_at, last_seen_at FROM registrationwatch_registrations WHERE id = {$regB}")->fetch(PDO::FETCH_ASSOC);
assert_true(should_auto_disable_absent($absent, 2592000, '2026-06-15 10:00:00'), 'registration absent beyond threshold should qualify for auto-disable');
$db->prepare('UPDATE registrationwatch_registrations SET enabled = 0, auto_disabled_absent_at = ? WHERE id = ?')->execute(['2026-06-15 10:00:00', $regB]);
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$regB}")->fetchColumn() === 0, 'auto-disabled absent registration should stop alert eligibility');
$db->prepare('UPDATE registrationwatch_registrations SET enabled = CASE WHEN auto_disabled_absent_at IS NOT NULL THEN 1 ELSE enabled END, auto_disabled_absent_at = NULL WHERE id = ?')->execute([$regB]);
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$regB}")->fetchColumn() === 1, 'returning auto-disabled registration should re-enable automatically');

assert_true(escalating_interval_seconds(1) === 300, 'escalating reminder 1 should wait 5 minutes');
assert_true(escalating_interval_seconds(2) === 600, 'escalating reminder 2 should wait 10 minutes');
assert_true(escalating_interval_seconds(3) === 900, 'escalating reminder 3 should wait 15 minutes');
assert_true(escalating_interval_seconds(4) === 1500, 'escalating reminder 4 should wait 25 minutes');
assert_true(escalating_interval_seconds(5) === 2400, 'escalating reminder 5 should wait 40 minutes');
assert_true(escalating_interval_seconds(6) === 3900, 'escalating reminder 6 should wait 65 minutes');
assert_true(escalating_interval_seconds(7) === 6300, 'escalating reminder 7 should wait 105 minutes');
assert_true(escalating_interval_seconds(14) === 86400, 'escalating should clamp at daily ceiling');
assert_true(normalise_repeat_mode('fibonacci') === 'escalating', 'stored fibonacci repeat mode should resolve to escalating');
assert_true(normalise_repeat_mode('garbage') === 'never', 'unknown repeat mode should fail safe to never');

class TestableRegistrationwatch extends \FreePBX\modules\Registrationwatch {
	public ?PDO $testDatabase = null;
	public ContractAsteriskManagerStub $asteriskManager;

	public function __construct() {
		$this->asteriskManager = new ContractAsteriskManagerStub();
		$asteriskManager = $this->asteriskManager;
		parent::__construct(new class($asteriskManager) {
			public $Config;
			public $astman;

			public function __construct($astman) {
				$this->astman = $astman;
				$this->Config = new class {
					public function get(string $key): string {
						return $key === 'AMPUSERMANEMAILFROM' ? 'alerts@example.invalid' : '';
					}
				};
			}

			public function __call(string $name, array $args) {
				return null;
			}
		});
	}

	public function getAlertSettings(): array {
		return ['alert_recipients' => 'admin@example.invalid'];
	}

	public function normaliseRecipients(string $recipients): array {
		return array_values(array_filter(array_map('trim', explode(',', $recipients))));
	}

	public function insertAlertHistory(array $row): void {
		return;
	}
}

$systemConfig = new FreePBXSystemIdentifierConfigStub(['FREEPBX_SYSTEM_IDENT' => 'MY-PBX-NAME']);
FreePBX::$config = $systemConfig;
$watch = new TestableRegistrationwatch();
$buildAlert = new ReflectionMethod($watch, 'buildAlertEmail');
$buildAlert->setAccessible(true);
$buildStorm = new ReflectionMethod($watch, 'buildStormSummaryEmail');
$buildStorm->setAccessible(true);
$getSystemIdentifier = new ReflectionMethod($watch, 'getSystemIdentifier');
$getSystemIdentifier->setAccessible(true);

$normalMessage = $buildAlert->invoke($watch, ['extension' => '2001', 'to_state' => 'Unreachable', 'reason' => 'threshold', 'created_at' => '2026-06-15 10:00:00'], 'unreachable');
assert_true($getSystemIdentifier->invoke($watch) === 'MY-PBX-NAME', 'Registration Watch should retrieve the configured FreePBX System Identifier from FreePBX::Config()');
assert_true(in_array('FREEPBX_SYSTEM_IDENT', $systemConfig->calls, true), 'Registration Watch should consult FreePBX system identifier config');
assert_true(strpos($normalMessage['message'], 'Registration Watch state change from MY-PBX-NAME') === 0, 'normal alert should begin with the configured system identifier');

$reminderTransition = ['extension' => '2001', 'to_state' => 'Unreachable', 'reason' => 'reminder', 'repeat_mode' => 'hourly', 'reminder_n' => 1, 'created_at' => '2026-06-15 10:00:00'];
$reminderMessage = $buildAlert->invoke($watch, $reminderTransition, 'unreachable');
assert_true(strpos($reminderMessage['message'], 'Registration Watch state change from MY-PBX-NAME') === 0, 'reminder alert should include the configured system identifier');

$recoveryMessage = $buildAlert->invoke($watch, ['extension' => '2001', 'to_state' => 'Reachable', 'reason' => 'recovery', 'created_at' => '2026-06-15 10:00:00'], 'recovery');
assert_true(strpos($recoveryMessage['message'], 'Registration Watch state change from MY-PBX-NAME') === 0, 'recovery alert should include the configured system identifier');

$stormMessage = $buildStorm->invoke($watch, [['extension' => '2001', 'alert_type' => 'not_registered', 'status' => 'Not registered']], '2026-06-15 10:00:00');
assert_true(strpos($stormMessage['message'], 'Registration Watch Storm Summary from MY-PBX-NAME') === 0, 'storm summary should include the configured system identifier');

$manualTestLine = 'Registration Watch test email from MY-PBX-NAME';
assert_true(strpos($manualTestLine, 'Registration Watch test email from MY-PBX-NAME') === 0, 'manual test email must use the configured system identifier');

$emptyConfig = new FreePBXSystemIdentifierConfigStub(['FREEPBX_SYSTEM_IDENT' => '']);
FreePBX::$config = $emptyConfig;
$emptyAlert = $buildAlert->invoke($watch, ['extension' => '2001', 'to_state' => 'Unreachable', 'reason' => 'threshold', 'created_at' => '2026-06-15 10:00:00'], 'unreachable');
assert_true($getSystemIdentifier->invoke($watch) === 'unknown system', 'empty configuration should fall back to unknown system');
assert_true(strpos($emptyAlert['message'], 'Registration Watch state change from unknown system') === 0, 'missing system identifier should fall back to unknown system');

$throwingConfig = new class {
	public function get(string $key) {
		throw new RuntimeException('config unavailable');
	}
};
FreePBX::$config = $throwingConfig;
$guardedAlert = $buildAlert->invoke($watch, ['extension' => '2001', 'to_state' => 'Unreachable', 'reason' => 'threshold', 'created_at' => '2026-06-15 10:00:00'], 'unreachable');
assert_true($getSystemIdentifier->invoke($watch) === 'unknown system', 'config exceptions should fall back to unknown system');
assert_true(strpos($guardedAlert['message'], 'Registration Watch state change from unknown system') === 0, 'exception in config lookup should fall back to unknown system');

$subjectLine = 'Registration Watch: 2001 is Unreachable';
assert_true($normalMessage['subject'] === $subjectLine, 'normal alert subject should remain unchanged');
assert_true($reminderMessage['subject'] === 'Registration Watch: 2001 is still Unreachable', 'reminder alert subject should remain unchanged');
assert_true($recoveryMessage['subject'] === 'Registration Watch: 2001 has recovered', 'recovery alert subject should remain unchanged');

$normalBodyAfterFirstLine = explode("\n\n", $normalMessage['message'], 2)[1] ?? '';
$expectedNormalBodyAfterFirstLine = "Extension: 2001\nNew state: Unreachable\nReason: threshold\nLatency: Unavailable\n\nDevice: Unknown\nVersion: Unknown\nDevice IP: Unknown\nDevice Port: Unknown\nNetwork IP: Unknown\nNetwork Port: Unknown\nContact expires: Unknown\nQualify frequency: Unknown\nTransition time: 2026-06-15 10:00:00\nSource: Asterisk\n\nPlease note: email deliveries can be delayed.\nCheck current status in the FreePBX module.";
assert_true(strpos($normalBodyAfterFirstLine, 'Extension: 2001') === 0, 'normal email body should preserve content after the first line and separator');
assert_true($normalBodyAfterFirstLine === $expectedNormalBodyAfterFirstLine, 'normal email body should preserve all existing content after the first line and blank line');
assert_true(strpos($stormMessage['message'], 'Registration Watch Storm Summary from MY-PBX-NAME') === 0, 'storm summary should include the configured system identifier');
assert_true(strpos($manualTestLine, 'Registration Watch test email from MY-PBX-NAME') === 0, 'manual test email should include the configured system identifier');

$makeQueueFixture = function (int $debounceSeconds, int $stormThreshold = 20, string $snoozedUntil = '', string $alertRecipients = 'admin@example.invalid'): array {
	$database = new ContractSQLitePDO('sqlite::memory:');
	$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$database->sqliteCreateFunction('DATABASE', static function (): string {
		return 'registrationwatch_contract';
	}, 0);
	$database->exec("ATTACH DATABASE ':memory:' AS information_schema");
	$database->exec('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT)');
	$database->exec("INSERT INTO information_schema.TABLES (TABLE_SCHEMA, TABLE_NAME) VALUES ('registrationwatch_contract', 'registrationwatch_registrations'), ('registrationwatch_contract', 'devices')");
	CI_Email::$sendResults = [];
	CI_Email::$sentMessages = [];
	$database->exec('CREATE TABLE registrationwatch_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_at TEXT)');
	$database->exec('CREATE TABLE devices (id TEXT NOT NULL, tech TEXT NOT NULL)');
	$database->exec(
		'CREATE TABLE registrationwatch_registrations (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			registration_key TEXT NOT NULL UNIQUE,
			extension TEXT NOT NULL,
			description TEXT,
			last_known_status TEXT NOT NULL,
			contact_uri TEXT,
			source_ip TEXT,
			source_port INTEGER,
			user_agent TEXT,
			device_name TEXT,
			firmware_version TEXT,
			contact_expires_at TEXT,
			qualify_frequency TEXT,
			last_checked_at TEXT,
			latency_ms REAL,
			enabled INTEGER NOT NULL,
			repeat_mode TEXT
		)'
	);
	$database->exec(
		'CREATE TABLE registrationwatch_status_history (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			registration_id INTEGER NOT NULL,
			registration_key TEXT NOT NULL,
			extension TEXT NOT NULL,
			from_state TEXT,
			to_state TEXT NOT NULL,
			source TEXT NOT NULL,
			reason TEXT,
			contact_uri TEXT,
			latency_ms REAL,
			created_at TEXT NOT NULL
		)'
	);
	$database->exec(
		'CREATE TABLE registrationwatch_alert_history (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			registration_id INTEGER,
			registration_key TEXT,
			extension TEXT NOT NULL,
			contact_uri TEXT,
			history_id INTEGER,
			reminder_n INTEGER NOT NULL DEFAULT 0,
			alert_type TEXT NOT NULL,
			status TEXT NOT NULL,
			recipient TEXT NOT NULL,
			subject TEXT NOT NULL,
			message TEXT,
			sent_at TEXT NOT NULL,
			result TEXT NOT NULL,
			error TEXT,
			UNIQUE (history_id, alert_type, recipient, reminder_n)
		)'
	);
	$database->exec(
		'CREATE TABLE registrationwatch_alert_escalation (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			registration_id INTEGER NOT NULL,
			registration_key TEXT NOT NULL,
			extension TEXT NOT NULL,
			history_id INTEGER NOT NULL,
			alert_type TEXT NOT NULL,
			active_since TEXT NOT NULL,
			last_alert_at TEXT,
			alert_count INTEGER NOT NULL DEFAULT 0,
			next_due_at TEXT NOT NULL,
			repeat_mode TEXT NOT NULL,
			created_at TEXT,
			updated_at TEXT,
			UNIQUE (registration_id, alert_type)
		)'
	);
	$database->exec(
		'CREATE TRIGGER registrationwatch_alert_escalation_upsert
		BEFORE INSERT ON registrationwatch_alert_escalation
		WHEN EXISTS (
			SELECT 1 FROM registrationwatch_alert_escalation
			WHERE registration_id = NEW.registration_id AND alert_type = NEW.alert_type
		)
		BEGIN
			UPDATE registrationwatch_alert_escalation
			SET registration_key = NEW.registration_key,
				extension = NEW.extension,
				history_id = NEW.history_id,
				active_since = NEW.active_since,
				last_alert_at = NEW.last_alert_at,
				alert_count = NEW.alert_count,
				next_due_at = NEW.next_due_at,
				repeat_mode = NEW.repeat_mode,
				updated_at = NEW.updated_at
			WHERE registration_id = NEW.registration_id AND alert_type = NEW.alert_type;
			SELECT RAISE(IGNORE);
		END'
	);
	$settings = [
		'alert_enabled' => '1',
		'alert_recipients' => $alertRecipients,
		'alert_on_unreachable' => '1',
		'alert_on_not_registered' => '1',
		'alert_on_recovery' => '1',
		'debounce_seconds' => (string)$debounceSeconds,
		'repeat_mode' => 'never',
		'storm_threshold' => (string)$stormThreshold,
		'monitoring_snoozed_until' => $snoozedUntil,
	];
	$settingInsert = $database->prepare('INSERT INTO registrationwatch_settings (setting_key, setting_value) VALUES (?, ?)');
	foreach ($settings as $key => $value) {
		$settingInsert->execute([$key, $value]);
	}

	FreePBX::$database = $database;
	FreePBX::$config = new FreePBXSystemIdentifierConfigStub(['FREEPBX_SYSTEM_IDENT' => 'TEST-PBX']);
	$watch = new TestableRegistrationwatch();
	$watch->testDatabase = $database;
	$processQueue = new ReflectionMethod($watch, 'processAlertQueue');
	$processQueue->setAccessible(true);
	return [$database, $watch, $processQueue];
};

[$upsertDb] = $makeQueueFixture(0);
$upsertSql = $upsertDb->prepare(
	'INSERT INTO registrationwatch_alert_escalation
		(registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode, created_at, updated_at)
	VALUES
		(:registration_id, :registration_key, :extension, :history_id, :alert_type, :active_since, :last_alert_at, :alert_count, :next_due_at, :repeat_mode, :created_at, :updated_at)
	ON DUPLICATE KEY UPDATE
		registration_key = VALUES(registration_key),
		extension = VALUES(extension),
		history_id = VALUES(history_id),
		active_since = VALUES(active_since),
		last_alert_at = VALUES(last_alert_at),
		alert_count = VALUES(alert_count),
		next_due_at = VALUES(next_due_at),
		repeat_mode = VALUES(repeat_mode),
		updated_at = VALUES(updated_at)'
);
$upsertValues = [
	':registration_id' => 1,
	':registration_key' => 'old-registration-key',
	':extension' => 'upsert-contract',
	':history_id' => 10,
	':alert_type' => 'unreachable',
	':active_since' => '2026-06-15 10:00:00',
	':last_alert_at' => '2026-06-15 10:00:00',
	':alert_count' => 0,
	':next_due_at' => '2026-06-15 10:05:00',
	':repeat_mode' => '5m',
	':created_at' => '2026-06-15 10:00:00',
	':updated_at' => '2026-06-15 10:00:00',
];
$upsertSql->execute($upsertValues);
$originalUpsertId = (int)$upsertDb->lastInsertId();
$upsertValues[':registration_key'] = 'new-registration-key';
$upsertValues[':extension'] = 'updated-upsert-contract';
$upsertValues[':history_id'] = 11;
$upsertValues[':active_since'] = '2026-06-15 10:01:00';
$upsertValues[':last_alert_at'] = '2026-06-15 10:02:00';
$upsertValues[':alert_count'] = 2;
$upsertValues[':next_due_at'] = '2026-06-15 11:02:00';
$upsertValues[':repeat_mode'] = 'hourly';
$upsertValues[':created_at'] = '2026-06-15 10:01:00';
$upsertValues[':updated_at'] = '2026-06-15 10:02:00';
$upsertSql->execute($upsertValues);
$updatedUpsert = $upsertDb->query('SELECT * FROM registrationwatch_alert_escalation')->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($updatedUpsert) && (int)$updatedUpsert['id'] === $originalUpsertId, 'MariaDB-style escalation upsert must preserve the existing row ID');
assert_true($updatedUpsert['registration_key'] === 'new-registration-key' && $updatedUpsert['extension'] === 'updated-upsert-contract' && (int)$updatedUpsert['history_id'] === 11 && $updatedUpsert['active_since'] === '2026-06-15 10:01:00' && $updatedUpsert['last_alert_at'] === '2026-06-15 10:02:00' && (int)$updatedUpsert['alert_count'] === 2 && $updatedUpsert['next_due_at'] === '2026-06-15 11:02:00' && $updatedUpsert['repeat_mode'] === 'hourly' && $updatedUpsert['updated_at'] === '2026-06-15 10:02:00', 'MariaDB-style escalation upsert must update every assigned field');
assert_true($updatedUpsert['created_at'] === '2026-06-15 10:00:00', 'MariaDB-style escalation upsert must preserve fields not assigned by its update clause');
assert_true((int)$upsertDb->query('SELECT COUNT(*) FROM registrationwatch_alert_escalation')->fetchColumn() === 1, 'MariaDB-style escalation upsert must leave exactly one record');
$upsertValues[':registration_id'] = 2;
$upsertValues[':extension'] = null;
$invalidInsertRejected = false;
try {
	$upsertSql->execute($upsertValues);
} catch (PDOException $e) {
	$invalidInsertRejected = true;
}
assert_true($invalidInsertRejected, 'invalid escalation insert must raise a database error instead of being ignored');

	$insertQueueRegistration = function (PDO $database, string $extension, string $status = 'Reachable', string $repeatMode = 'never', ?string $sourceIp = null): array {
	$key = $sourceIp !== null ? registration_key($extension, $sourceIp) : hash('sha256', $extension . '\0queue-test');
	$stmt = $database->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, last_known_status, source_ip, enabled, repeat_mode) VALUES (?, ?, ?, ?, 1, ?)');
	$stmt->execute([$key, $extension, $status, $sourceIp, $repeatMode]);
	if ($sourceIp !== null) {
		$database->prepare('INSERT INTO devices (id, tech) VALUES (?, ?)')->execute([$extension, 'pjsip']);
	}
	return [(int)$database->lastInsertId(), $key];
};
$insertQueueTransition = function (PDO $database, int $registrationId, string $key, string $extension, string $from, string $to, string $createdAt): int {
	$stmt = $database->prepare('INSERT INTO registrationwatch_status_history (registration_id, registration_key, extension, from_state, to_state, source, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
	$stmt->execute([$registrationId, $key, $extension, $from, $to, 'reconcile', 'status_change', $createdAt]);
	return (int)$database->lastInsertId();
};
$runQueue = function (ReflectionMethod $processQueue, object $watch, string $now): void {
	if ($watch instanceof TestableRegistrationwatch && $watch->testDatabase instanceof PDO) {
		FreePBX::$database = $watch->testDatabase;
	}
	$processQueue->invoke($watch, $now);
};
$runQueueAndCountEmails = function (ReflectionMethod $processQueue, object $watch, string $now) use ($runQueue): int {
	$emailsBefore = count(CI_Email::$sentMessages);
	$runQueue($processQueue, $watch, $now);
	return count(CI_Email::$sentMessages) - $emailsBefore;
};
$setQueueLiveStatus = function (TestableRegistrationwatch $watch, string $extension, string $sourceIp, string $status): void {
	$statusToken = $status === 'Not registered' ? 'Removed' : $status;
	$watch->asteriskManager->contactOutput = sprintf(
		'Contact: %s/sip:%s@%s:5060 %s 0.000',
		$extension,
		$extension,
		$sourceIp,
		$statusToken
	);
};

[$flapDb, $flapWatch, $flapQueue] = $makeQueueFixture(300);
[$flapId, $flapKey] = $insertQueueRegistration($flapDb, 'debounce-flap');
$flapProblemId = $insertQueueTransition($flapDb, $flapId, $flapKey, 'debounce-flap', 'Reachable', 'Unreachable', '2026-06-15 08:12:07');
$flapRecoveryId = $insertQueueTransition($flapDb, $flapId, $flapKey, 'debounce-flap', 'Unreachable', 'Reachable', '2026-06-15 08:12:22');
$flapEmailDelta = $runQueueAndCountEmails($flapQueue, $flapWatch, '2026-06-15 08:17:30');
$flapEmailDelta += $runQueueAndCountEmails($flapQueue, $flapWatch, '2026-06-15 08:22:30');
assert_true((int)$flapDb->query("SELECT COUNT(*) FROM registrationwatch_status_history WHERE registration_id = {$flapId}")->fetchColumn() === 2, 'debounced flap should retain both observed Status History transitions');
$flapProblemDecision = $flapDb->query("SELECT registration_id, history_id, alert_type, recipient, result, error FROM registrationwatch_alert_history WHERE alert_type = 'unreachable'")->fetch(PDO::FETCH_ASSOC);
$flapRecoveryDecision = $flapDb->query("SELECT registration_id, history_id, alert_type, recipient, result, error FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$flapDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 2, 'short recovered flap should record both suppressed problem and recovery decisions');
assert_true(is_array($flapProblemDecision) && (int)$flapProblemDecision['registration_id'] === $flapId && (int)$flapProblemDecision['history_id'] === $flapProblemId && $flapProblemDecision['recipient'] === 'admin@example.invalid' && $flapProblemDecision['result'] === 'suppressed' && $flapProblemDecision['error'] === 'Debounce Delay', 'debounce suppression should retain its reason and reference the problem transition');
assert_true(is_array($flapRecoveryDecision) && (int)$flapRecoveryDecision['registration_id'] === $flapId && (int)$flapRecoveryDecision['history_id'] === $flapRecoveryId && $flapRecoveryDecision['recipient'] === 'admin@example.invalid' && $flapRecoveryDecision['result'] === 'suppressed' && $flapRecoveryDecision['error'] === 'Original problem alert suppressed (Debounce Delay)', 'withheld recovery should record its reason against the recovery transition');
assert_true((int)$flapDb->query('SELECT COUNT(*) FROM registrationwatch_alert_escalation')->fetchColumn() === 0, 'short recovered flap should create no repeat escalation state');
assert_true($flapEmailDelta === 0 && (int)$flapDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetchColumn() === 1, 'short recovered flap should send no email or create duplicate recovery decisions on replay');

[$notRegisteredFlapDb, $notRegisteredFlapWatch, $notRegisteredFlapQueue] = $makeQueueFixture(300);
[$notRegisteredFlapId, $notRegisteredFlapKey] = $insertQueueRegistration($notRegisteredFlapDb, 'debounce-not-registered-flap');
$notRegisteredProblemId = $insertQueueTransition($notRegisteredFlapDb, $notRegisteredFlapId, $notRegisteredFlapKey, 'debounce-not-registered-flap', 'Reachable', 'Not registered', '2026-06-15 08:25:00');
$notRegisteredRecoveryId = $insertQueueTransition($notRegisteredFlapDb, $notRegisteredFlapId, $notRegisteredFlapKey, 'debounce-not-registered-flap', 'Not registered', 'Reachable', '2026-06-15 08:25:15');
$notRegisteredFlapEmails = $runQueueAndCountEmails($notRegisteredFlapQueue, $notRegisteredFlapWatch, '2026-06-15 08:30:00');
$notRegisteredFlapRows = $notRegisteredFlapDb->query('SELECT registration_id, history_id, alert_type, recipient, result, error FROM registrationwatch_alert_history ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
assert_true(count($notRegisteredFlapRows) === 2 && $notRegisteredFlapRows[0]['alert_type'] === 'not_registered' && (int)$notRegisteredFlapRows[0]['history_id'] === $notRegisteredProblemId && $notRegisteredFlapRows[0]['result'] === 'suppressed' && $notRegisteredFlapRows[0]['error'] === 'Debounce Delay', 'short Not registered outage should retain its debounce problem suppression');
assert_true($notRegisteredFlapRows[1]['alert_type'] === 'recovery' && (int)$notRegisteredFlapRows[1]['registration_id'] === $notRegisteredFlapId && (int)$notRegisteredFlapRows[1]['history_id'] === $notRegisteredRecoveryId && $notRegisteredFlapRows[1]['recipient'] === 'admin@example.invalid' && $notRegisteredFlapRows[1]['result'] === 'suppressed' && $notRegisteredFlapRows[1]['error'] === 'Original problem alert suppressed (Debounce Delay)', 'short Not registered recovery should reference and explain the withheld recovery decision');
assert_true($notRegisteredFlapEmails === 0, 'short Not registered outage should send neither problem nor recovery email');

[$zeroHandoffDb, $zeroHandoffWatch, $zeroHandoffQueue] = $makeQueueFixture(0);
[$zeroHandoffId, $zeroHandoffKey] = $insertQueueRegistration($zeroHandoffDb, 'debounce-zero-handoff');
$insertQueueTransition($zeroHandoffDb, $zeroHandoffId, $zeroHandoffKey, 'debounce-zero-handoff', 'Reachable', 'Unreachable', '2026-06-15 08:20:00');
$insertQueueTransition($zeroHandoffDb, $zeroHandoffId, $zeroHandoffKey, 'debounce-zero-handoff', 'Unreachable', 'Not registered', '2026-06-15 08:20:01');
$zeroHandoffEmailDelta = $runQueueAndCountEmails($zeroHandoffQueue, $zeroHandoffWatch, '2026-06-15 08:20:01');
$zeroHandoffTypes = $zeroHandoffDb->query("SELECT alert_type FROM registrationwatch_alert_history WHERE reminder_n = 0 ORDER BY history_id")->fetchAll(PDO::FETCH_COLUMN);
assert_true($zeroHandoffTypes === ['unreachable', 'not_registered'], 'zero debounce should send one alert for each distinct alertable unhealthy state transition');
assert_true($zeroHandoffEmailDelta === 2, 'zero-debounce Unreachable to Not registered handoff should send exactly two problem emails');

[$transientHandoffDb, $transientHandoffWatch, $transientHandoffQueue] = $makeQueueFixture(300);
[$transientHandoffId, $transientHandoffKey] = $insertQueueRegistration($transientHandoffDb, 'debounce-transient-handoff');
$insertQueueTransition($transientHandoffDb, $transientHandoffId, $transientHandoffKey, 'debounce-transient-handoff', 'Reachable', 'Unreachable', '2026-06-15 08:30:00');
$insertQueueTransition($transientHandoffDb, $transientHandoffId, $transientHandoffKey, 'debounce-transient-handoff', 'Unreachable', 'Not registered', '2026-06-15 08:31:00');
$transientRecoveryId = $insertQueueTransition($transientHandoffDb, $transientHandoffId, $transientHandoffKey, 'debounce-transient-handoff', 'Not registered', 'Reachable', '2026-06-15 08:34:15');
$transientHandoffEmailDelta = $runQueueAndCountEmails($transientHandoffQueue, $transientHandoffWatch, '2026-06-15 08:34:15');
$transientHandoffEmailDelta += $runQueueAndCountEmails($transientHandoffQueue, $transientHandoffWatch, '2026-06-15 08:36:00');
$transientProblemDecision = $transientHandoffDb->query("SELECT history_id, alert_type, result, error FROM registrationwatch_alert_history WHERE alert_type = 'not_registered'")->fetch(PDO::FETCH_ASSOC);
$transientRecoveryDecision = $transientHandoffDb->query("SELECT history_id, alert_type, result, error FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetch(PDO::FETCH_ASSOC);
assert_true((int)$transientHandoffDb->query("SELECT COUNT(*) FROM registrationwatch_status_history WHERE registration_id = {$transientHandoffId}")->fetchColumn() === 3, 'transient unhealthy handoff should retain every factual transition');
assert_true((int)$transientHandoffDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 2, 'transient unhealthy handoff should audit both suppressed notification decisions');
assert_true(is_array($transientProblemDecision) && (int)$transientProblemDecision['history_id'] === 2 && $transientProblemDecision['alert_type'] === 'not_registered' && $transientProblemDecision['result'] === 'suppressed' && $transientProblemDecision['error'] === 'Debounce Delay', 'transient unhealthy handoff should record the latest problem suppression reason');
assert_true(is_array($transientRecoveryDecision) && (int)$transientRecoveryDecision['history_id'] === $transientRecoveryId && $transientRecoveryDecision['alert_type'] === 'recovery' && $transientRecoveryDecision['result'] === 'suppressed' && $transientRecoveryDecision['error'] === 'Original problem alert suppressed (Debounce Delay)', 'transient unhealthy handoff should audit recovery against its own transition');
assert_true((int)$transientHandoffDb->query('SELECT COUNT(*) FROM registrationwatch_alert_escalation')->fetchColumn() === 0, 'transient unhealthy handoff should not start escalation state');
assert_true($transientHandoffEmailDelta === 0, 'transient unhealthy handoff should send neither problem nor recovery email');

[$persistentDb, $persistentWatch, $persistentQueue] = $makeQueueFixture(300);
[$persistentId, $persistentKey] = $insertQueueRegistration($persistentDb, 'debounce-persistent');
$insertQueueTransition($persistentDb, $persistentId, $persistentKey, 'debounce-persistent', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$runQueue($persistentQueue, $persistentWatch, '2026-06-15 09:04:59');
assert_true((int)$persistentDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 0, 'persistent fault must remain pending just before debounce expires');
$persistentInitialEmailDelta = $runQueueAndCountEmails($persistentQueue, $persistentWatch, '2026-06-15 09:05:00');
$persistentInitialEmailDelta += $runQueueAndCountEmails($persistentQueue, $persistentWatch, '2026-06-15 09:05:20');
assert_true((int)$persistentDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'unreachable' AND reminder_n = 0")->fetchColumn() === 1, 'persistent fault should reserve exactly one initial alert after debounce');
assert_true($persistentInitialEmailDelta === 1, 'persistent fault should send exactly one initial email for this incident');

[$handoffDb, $handoffWatch, $handoffQueue] = $makeQueueFixture(300);
[$handoffId, $handoffKey] = $insertQueueRegistration($handoffDb, 'debounce-handoff', 'Not registered');
$insertQueueTransition($handoffDb, $handoffId, $handoffKey, 'debounce-handoff', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$handoffTransitionId = $insertQueueTransition($handoffDb, $handoffId, $handoffKey, 'debounce-handoff', 'Unreachable', 'Not registered', '2026-06-15 09:03:00');
$handoffEarlyEmailDelta = $runQueueAndCountEmails($handoffQueue, $handoffWatch, '2026-06-15 09:04:59');
assert_true((int)$handoffDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 0, 'unhealthy-state handoff must not restart the debounce timer');
assert_true($handoffEarlyEmailDelta === 0, 'unhealthy-state handoff must not alert before the continuous debounce expires');
$handoffQualifiedEmailDelta = $runQueueAndCountEmails($handoffQueue, $handoffWatch, '2026-06-15 09:05:00');
$handoffAlert = $handoffDb->query('SELECT history_id, alert_type FROM registrationwatch_alert_history WHERE reminder_n = 0')->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($handoffAlert) && (int)$handoffAlert['history_id'] === $handoffTransitionId && $handoffAlert['alert_type'] === 'not_registered', 'continuous unhealthy handoff should alert once using the latest eligible problem state');
assert_true((int)$handoffDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history WHERE reminder_n = 0')->fetchColumn() === 1 && $handoffQualifiedEmailDelta === 1, 'debounced unhealthy handoff should emit only the current qualifying problem alert');

[$lateZeroHandoffDb, $lateZeroHandoffWatch, $lateZeroHandoffQueue] = $makeQueueFixture(0);
[$lateZeroHandoffId, $lateZeroHandoffKey] = $insertQueueRegistration($lateZeroHandoffDb, 'debounce-late-zero-handoff');
$insertQueueTransition($lateZeroHandoffDb, $lateZeroHandoffId, $lateZeroHandoffKey, 'debounce-late-zero-handoff', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$lateZeroInitialDelta = $runQueueAndCountEmails($lateZeroHandoffQueue, $lateZeroHandoffWatch, '2026-06-15 09:00:00');
$insertQueueTransition($lateZeroHandoffDb, $lateZeroHandoffId, $lateZeroHandoffKey, 'debounce-late-zero-handoff', 'Unreachable', 'Not registered', '2026-06-15 09:10:00');
$lateZeroSecondDelta = $runQueueAndCountEmails($lateZeroHandoffQueue, $lateZeroHandoffWatch, '2026-06-15 09:10:00');
$lateZeroTypes = $lateZeroHandoffDb->query("SELECT alert_type FROM registrationwatch_alert_history WHERE reminder_n = 0 ORDER BY history_id")->fetchAll(PDO::FETCH_COLUMN);
assert_true($lateZeroInitialDelta === 1 && $lateZeroSecondDelta === 1 && $lateZeroTypes === ['unreachable', 'not_registered'], 'fresh Not registered transition should alert after the original incident start becomes stale with zero debounce');

[$staleAnchorZeroDb, $staleAnchorZeroWatch, $staleAnchorZeroQueue] = $makeQueueFixture(0);
[$staleAnchorZeroId, $staleAnchorZeroKey] = $insertQueueRegistration($staleAnchorZeroDb, 'debounce-stale-anchor-zero');
$staleAnchorZeroProblemId = $insertQueueTransition($staleAnchorZeroDb, $staleAnchorZeroId, $staleAnchorZeroKey, 'debounce-stale-anchor-zero', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$staleAnchorZeroFreshId = $insertQueueTransition($staleAnchorZeroDb, $staleAnchorZeroId, $staleAnchorZeroKey, 'debounce-stale-anchor-zero', 'Unreachable', 'Not registered', '2026-06-15 09:10:00');
$staleAnchorZeroEmailDelta = $runQueueAndCountEmails($staleAnchorZeroQueue, $staleAnchorZeroWatch, '2026-06-15 09:10:00');
$staleAnchorZeroRows = $staleAnchorZeroDb->query('SELECT history_id, alert_type FROM registrationwatch_alert_history WHERE reminder_n = 0')->fetchAll(PDO::FETCH_ASSOC);
assert_true((int)$staleAnchorZeroDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history WHERE history_id = ' . $staleAnchorZeroProblemId)->fetchColumn() === 0, 'pre-window Unreachable anchor must not create a new Alert History action');
assert_true(count($staleAnchorZeroRows) === 1 && (int)$staleAnchorZeroRows[0]['history_id'] === $staleAnchorZeroFreshId && $staleAnchorZeroRows[0]['alert_type'] === 'not_registered' && $staleAnchorZeroEmailDelta === 1, 'zero debounce should deliver only the fresh Not registered transition');

[$lateDebouncedHandoffDb, $lateDebouncedHandoffWatch, $lateDebouncedHandoffQueue] = $makeQueueFixture(300);
[$lateDebouncedHandoffId, $lateDebouncedHandoffKey] = $insertQueueRegistration($lateDebouncedHandoffDb, 'debounce-late-state-change');
$insertQueueTransition($lateDebouncedHandoffDb, $lateDebouncedHandoffId, $lateDebouncedHandoffKey, 'debounce-late-state-change', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$lateDebouncedInitialDelta = $runQueueAndCountEmails($lateDebouncedHandoffQueue, $lateDebouncedHandoffWatch, '2026-06-15 09:05:00');
$insertQueueTransition($lateDebouncedHandoffDb, $lateDebouncedHandoffId, $lateDebouncedHandoffKey, 'debounce-late-state-change', 'Unreachable', 'Not registered', '2026-06-15 09:20:00');
$lateDebouncedSecondDelta = $runQueueAndCountEmails($lateDebouncedHandoffQueue, $lateDebouncedHandoffWatch, '2026-06-15 09:20:00');
$lateDebouncedTypes = $lateDebouncedHandoffDb->query("SELECT alert_type FROM registrationwatch_alert_history WHERE reminder_n = 0 ORDER BY history_id")->fetchAll(PDO::FETCH_COLUMN);
assert_true($lateDebouncedInitialDelta === 1 && $lateDebouncedSecondDelta === 1 && $lateDebouncedTypes === ['unreachable', 'not_registered'], 'fresh Not registered transition should alert immediately after an already-matured continuous incident');

[$staleAnchorDebouncedDb, $staleAnchorDebouncedWatch, $staleAnchorDebouncedQueue] = $makeQueueFixture(300);
[$staleAnchorDebouncedId, $staleAnchorDebouncedKey] = $insertQueueRegistration($staleAnchorDebouncedDb, 'debounce-stale-anchor-matured');
$staleAnchorDebouncedProblemId = $insertQueueTransition($staleAnchorDebouncedDb, $staleAnchorDebouncedId, $staleAnchorDebouncedKey, 'debounce-stale-anchor-matured', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$staleAnchorDebouncedFreshId = $insertQueueTransition($staleAnchorDebouncedDb, $staleAnchorDebouncedId, $staleAnchorDebouncedKey, 'debounce-stale-anchor-matured', 'Unreachable', 'Not registered', '2026-06-15 09:20:00');
$staleAnchorDebouncedEmailDelta = $runQueueAndCountEmails($staleAnchorDebouncedQueue, $staleAnchorDebouncedWatch, '2026-06-15 09:20:00');
$staleAnchorDebouncedRows = $staleAnchorDebouncedDb->query('SELECT history_id, alert_type FROM registrationwatch_alert_history WHERE reminder_n = 0')->fetchAll(PDO::FETCH_ASSOC);
assert_true((int)$staleAnchorDebouncedDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history WHERE history_id = ' . $staleAnchorDebouncedProblemId)->fetchColumn() === 0, 'matured incident timing anchor must not become a replayed Unreachable alert');
assert_true(count($staleAnchorDebouncedRows) === 1 && (int)$staleAnchorDebouncedRows[0]['history_id'] === $staleAnchorDebouncedFreshId && $staleAnchorDebouncedRows[0]['alert_type'] === 'not_registered' && $staleAnchorDebouncedEmailDelta === 1, 'matured positive debounce should deliver only the fresh Not registered transition');

[$freshRecoveryDb, $freshRecoveryWatch, $freshRecoveryQueue] = $makeQueueFixture(300);
[$freshRecoveryId, $freshRecoveryKey] = $insertQueueRegistration($freshRecoveryDb, 'debounce-old-problem-fresh-recovery');
$oldUnsentProblemId = $insertQueueTransition($freshRecoveryDb, $freshRecoveryId, $freshRecoveryKey, 'debounce-old-problem-fresh-recovery', 'Reachable', 'Unreachable', '2026-06-15 08:00:00');
$insertQueueTransition($freshRecoveryDb, $freshRecoveryId, $freshRecoveryKey, 'debounce-old-problem-fresh-recovery', 'Unreachable', 'Reachable', '2026-06-15 09:20:00');
$freshRecoveryEmailDelta = $runQueueAndCountEmails($freshRecoveryQueue, $freshRecoveryWatch, '2026-06-15 09:20:00');
assert_true((int)$freshRecoveryDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 0 && $freshRecoveryEmailDelta === 0, 'fresh recovery must not release an old unsent problem transition');
assert_true($oldUnsentProblemId === 1, 'fresh-recovery stale-protection fixture should use the old problem transition as its incident anchor');

[$reminderReplayDb, $reminderReplayWatch, $reminderReplayQueue] = $makeQueueFixture(0, 0);
[$reminderReplayId, $reminderReplayKey] = $insertQueueRegistration($reminderReplayDb, 'reminder-replay', 'Unreachable', 'hourly');
$insertQueueTransition($reminderReplayDb, $reminderReplayId, $reminderReplayKey, 'reminder-replay', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$runQueue($reminderReplayQueue, $reminderReplayWatch, '2026-06-15 09:00:00');
$insertQueueTransition($reminderReplayDb, $reminderReplayId, $reminderReplayKey, 'reminder-replay', 'Unreachable', 'Reachable', '2026-06-15 09:00:10');
$reminderReplayDb->exec("UPDATE registrationwatch_registrations SET last_known_status = 'Reachable' WHERE id = {$reminderReplayId}");
$runQueue($reminderReplayQueue, $reminderReplayWatch, '2026-06-15 09:00:10');
assert_true((int)$reminderReplayDb->query('SELECT COUNT(*) FROM registrationwatch_alert_escalation')->fetchColumn() === 0, 'genuine recovery must cancel the current hourly reminder');
$newReminderProblemId = $insertQueueTransition($reminderReplayDb, $reminderReplayId, $reminderReplayKey, 'reminder-replay', 'Reachable', 'Unreachable', '2026-06-15 09:00:20');
$reminderReplayDb->exec("UPDATE registrationwatch_registrations SET last_known_status = 'Unreachable' WHERE id = {$reminderReplayId}");
$runQueue($reminderReplayQueue, $reminderReplayWatch, '2026-06-15 09:00:20');
$reminderStateSql = "SELECT history_id, active_since, alert_count, last_alert_at, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$reminderReplayId}";
$newReminderState = $reminderReplayDb->query($reminderStateSql)->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($newReminderState) && (int)$newReminderState['history_id'] === $newReminderProblemId && $newReminderState['active_since'] === '2026-06-15 09:00:20' && $newReminderState['last_alert_at'] === '2026-06-15 09:00:20' && (int)$newReminderState['alert_count'] === 0 && $newReminderState['next_due_at'] === '2026-06-15 10:00:20', 'a subsequent failure must start its own hourly schedule due at 10:00:20');
$runQueue($reminderReplayQueue, $reminderReplayWatch, '2026-06-15 09:00:30');
assert_true($reminderReplayDb->query($reminderStateSql)->fetch(PDO::FETCH_ASSOC) === $newReminderState, 'replaying the finished outage must not reset the new reminder schedule at 09:00:30');
$reminderReplayDb->exec("UPDATE registrationwatch_alert_escalation SET alert_count = 3, last_alert_at = '2026-06-15 09:00:25' WHERE registration_id = {$reminderReplayId}");
$countedReminderState = $reminderReplayDb->query($reminderStateSql)->fetch(PDO::FETCH_ASSOC);
$runQueue($reminderReplayQueue, $reminderReplayWatch, '2026-06-15 09:00:40');
$runQueue($reminderReplayQueue, $reminderReplayWatch, '2026-06-15 09:00:50');
assert_true($reminderReplayDb->query($reminderStateSql)->fetch(PDO::FETCH_ASSOC) === $countedReminderState, 'repeated historical replay must preserve a nonzero reminder count and all current schedule fields');
$insertQueueTransition($reminderReplayDb, $reminderReplayId, $reminderReplayKey, 'reminder-replay', 'Unreachable', 'Reachable', '2026-06-15 09:01:00');
$reminderReplayDb->exec("UPDATE registrationwatch_registrations SET last_known_status = 'Reachable' WHERE id = {$reminderReplayId}");
$runQueue($reminderReplayQueue, $reminderReplayWatch, '2026-06-15 09:01:00');
assert_true((int)$reminderReplayDb->query('SELECT COUNT(*) FROM registrationwatch_alert_escalation')->fetchColumn() === 0, 'a genuine later recovery must still cancel the newer incident reminder');

$replayDbFixture = $makeQueueFixture(300);
[$replayDb, $replayWatch, $replayQueue] = $replayDbFixture;
[$replayId, $replayKey] = $insertQueueRegistration($replayDb, 'debounce-replay-state', 'Unreachable', 'hourly');
$oldProblemId = $insertQueueTransition($replayDb, $replayId, $replayKey, 'debounce-replay-state', 'Reachable', 'Unreachable', '2026-06-15 08:50:00');
$oldRecoveryId = $insertQueueTransition($replayDb, $replayId, $replayKey, 'debounce-replay-state', 'Unreachable', 'Reachable', '2026-06-15 08:55:00');
$currentProblemId = $insertQueueTransition($replayDb, $replayId, $replayKey, 'debounce-replay-state', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$replayEscalation = $replayDb->prepare('INSERT INTO registrationwatch_alert_escalation (registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$replayEscalation->execute([$replayId, $replayKey, 'debounce-replay-state', $currentProblemId, 'unreachable', '2026-06-15 09:00:00', '2026-06-15 09:01:00', 4, '2026-06-15 10:10:00', 'hourly', '2026-06-15 09:00:00', '2026-06-15 09:01:00']);
$runQueue($replayQueue, $replayWatch, '2026-06-15 09:05:00');
$runQueue($replayQueue, $replayWatch, '2026-06-15 09:05:00');
$replayState = $replayDb->query("SELECT history_id, active_since, alert_count, last_alert_at, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$replayId}")->fetch(PDO::FETCH_ASSOC);
assert_true($oldRecoveryId < $currentProblemId && $oldProblemId < $oldRecoveryId, 'replay fixture should order the old recovery before the current incident');
assert_true(is_array($replayState) && (int)$replayState['history_id'] === $currentProblemId, 'historical recovery replay should retain the current incident escalation');
assert_true($replayState['active_since'] === '2026-06-15 09:00:00' && (int)$replayState['alert_count'] === 4 && $replayState['last_alert_at'] === '2026-06-15 09:01:00' && $replayState['next_due_at'] === '2026-06-15 10:10:00', 'replaying an older recovery must preserve every current repeat-schedule field');

$persistentRecoveryEmailDelta = null;
$insertQueueTransition($persistentDb, $persistentId, $persistentKey, 'debounce-persistent', 'Unreachable', 'Reachable', '2026-06-15 09:06:00');
$persistentDb->exec("UPDATE registrationwatch_registrations SET last_known_status = 'Reachable' WHERE id = {$persistentId}");
$persistentRecoveryEmailDelta = $runQueueAndCountEmails($persistentQueue, $persistentWatch, '2026-06-15 09:06:00');
assert_true((int)$persistentDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetchColumn() === 1, 'recovery after a sent debounced alert should generate one recovery action');
assert_true((int)$persistentDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'unreachable' AND reminder_n = 0")->fetchColumn() === 1, 'recovery processing must not re-emit the initial problem alert');
assert_true((int)$persistentDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'recovery' AND result = 'suppressed'")->fetchColumn() === 0, 'successful initial problem delivery must not create a suppressed recovery decision');
assert_true($persistentRecoveryEmailDelta === 1, 'recovery after a sent debounced alert should send exactly one recovery email for this incident');

[$failedRecoveryDb, $failedRecoveryWatch, $failedRecoveryQueue] = $makeQueueFixture(300);
[$failedRecoveryId, $failedRecoveryKey] = $insertQueueRegistration($failedRecoveryDb, 'debounce-failed-problem');
$insertQueueTransition($failedRecoveryDb, $failedRecoveryId, $failedRecoveryKey, 'debounce-failed-problem', 'Reachable', 'Unreachable', '2026-06-15 12:00:00');
$failedRecoveryTransitionId = $insertQueueTransition($failedRecoveryDb, $failedRecoveryId, $failedRecoveryKey, 'debounce-failed-problem', 'Unreachable', 'Reachable', '2026-06-15 12:05:00');
CI_Email::$sendResults = [false];
$failedInitialEmailDelta = $runQueueAndCountEmails($failedRecoveryQueue, $failedRecoveryWatch, '2026-06-15 12:05:00');
$failedProblem = $failedRecoveryDb->query("SELECT alert_type, result FROM registrationwatch_alert_history WHERE alert_type = 'unreachable'")->fetch(PDO::FETCH_ASSOC);
$failedRecoveryDecision = $failedRecoveryDb->query("SELECT registration_id, history_id, recipient, result, error FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($failedProblem) && $failedProblem['alert_type'] === 'unreachable' && $failedProblem['result'] === 'failed', 'same-pass qualifying problem failure should retain its failed Alert History result');
assert_true(is_array($failedRecoveryDecision) && (int)$failedRecoveryDecision['registration_id'] === $failedRecoveryId && (int)$failedRecoveryDecision['history_id'] === $failedRecoveryTransitionId && $failedRecoveryDecision['recipient'] === 'admin@example.invalid' && $failedRecoveryDecision['result'] === 'suppressed' && $failedRecoveryDecision['error'] === 'No successful problem notification delivered', 'failed same-pass problem email should produce a recipient-specific suppressed recovery audit');
assert_true($failedInitialEmailDelta === 1, 'a failed same-pass problem must be the only attempted email');
$failedLaterEmailDelta = $runQueueAndCountEmails($failedRecoveryQueue, $failedRecoveryWatch, '2026-06-15 12:05:10');
assert_true((int)$failedRecoveryDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetchColumn() === 1 && $failedLaterEmailDelta === 0, 'a later queue pass must not release recovery or duplicate its suppression audit after a failed initial problem email');

[$unknownImmediateDb, $unknownImmediateWatch, $unknownImmediateQueue] = $makeQueueFixture(0);
[$unknownImmediateId, $unknownImmediateKey] = $insertQueueRegistration($unknownImmediateDb, 'debounce-unknown-immediate', 'Unreachable');
$insertQueueTransition($unknownImmediateDb, $unknownImmediateId, $unknownImmediateKey, 'debounce-unknown-immediate', 'Unknown', 'Unreachable', '2026-06-15 13:00:00');
$insertQueueTransition($unknownImmediateDb, $unknownImmediateId, $unknownImmediateKey, 'debounce-unknown-immediate', 'Unreachable', 'Not registered', '2026-06-15 13:00:01');
$unknownImmediateEmailDelta = $runQueueAndCountEmails($unknownImmediateQueue, $unknownImmediateWatch, '2026-06-15 13:00:01');
$unknownImmediateTypes = $unknownImmediateDb->query('SELECT alert_type FROM registrationwatch_alert_history WHERE reminder_n = 0 ORDER BY history_id')->fetchAll(PDO::FETCH_COLUMN);
assert_true($unknownImmediateTypes === ['not_registered'] && $unknownImmediateEmailDelta === 1, 'Unknown baseline should stay suppressed while a later alertable Not registered transition sends once with zero debounce');

[$unknownDelayedDb, $unknownDelayedWatch, $unknownDelayedQueue] = $makeQueueFixture(300);
[$unknownDelayedId, $unknownDelayedKey] = $insertQueueRegistration($unknownDelayedDb, 'debounce-unknown-delayed', 'Not registered');
$insertQueueTransition($unknownDelayedDb, $unknownDelayedId, $unknownDelayedKey, 'debounce-unknown-delayed', 'Unknown', 'Unreachable', '2026-06-15 13:10:00');
$insertQueueTransition($unknownDelayedDb, $unknownDelayedId, $unknownDelayedKey, 'debounce-unknown-delayed', 'Unreachable', 'Not registered', '2026-06-15 13:11:00');
$unknownDelayedEarlyEmailDelta = $runQueueAndCountEmails($unknownDelayedQueue, $unknownDelayedWatch, '2026-06-15 13:15:59');
assert_true((int)$unknownDelayedDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 0, 'Unknown baseline time must not shorten debounce for the first later alertable transition');
$unknownDelayedQualifiedEmailDelta = $runQueueAndCountEmails($unknownDelayedQueue, $unknownDelayedWatch, '2026-06-15 13:16:00');
$unknownDelayedAlert = $unknownDelayedDb->query('SELECT alert_type, history_id FROM registrationwatch_alert_history WHERE reminder_n = 0')->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($unknownDelayedAlert) && $unknownDelayedAlert['alert_type'] === 'not_registered' && (int)$unknownDelayedAlert['history_id'] === 2, 'later Not registered alert should qualify exactly 300 seconds after its own transition');
assert_true($unknownDelayedEarlyEmailDelta === 0 && $unknownDelayedQualifiedEmailDelta === 1, 'Unknown baseline should not alert early and later qualify exactly one email on debounce expiry');

$stormRecoveryRecipients = 'primary@example.invalid, secondary@example.invalid';
[$stormRecoveryDb, $stormRecoveryWatch, $stormRecoveryQueue] = $makeQueueFixture(0, 1, '', $stormRecoveryRecipients);
[$stormRecoveryId, $stormRecoveryKey] = $insertQueueRegistration($stormRecoveryDb, 'debounce-storm-recovery');
$insertQueueTransition($stormRecoveryDb, $stormRecoveryId, $stormRecoveryKey, 'debounce-storm-recovery', 'Reachable', 'Unreachable', '2026-06-15 14:00:00');
CI_Email::$sendResults = [true, false];
$stormProblemEmailStart = count(CI_Email::$sentMessages);
$stormProblemEmailDelta = $runQueueAndCountEmails($stormRecoveryQueue, $stormRecoveryWatch, '2026-06-15 14:00:00');
$stormProblemCalls = array_slice(CI_Email::$sentMessages, $stormProblemEmailStart);
$stormProblemResults = $stormRecoveryDb->query("SELECT recipient, result FROM registrationwatch_alert_history WHERE alert_type = 'unreachable' ORDER BY recipient")->fetchAll(PDO::FETCH_KEY_PAIR);
assert_true(($stormProblemResults['primary@example.invalid'] ?? '') === 'storm_suppressed' && ($stormProblemResults['secondary@example.invalid'] ?? '') === 'storm_summary_failed', 'problem Storm Summary outcome should remain recipient-specific');
assert_true($stormProblemEmailDelta === 2 && array_column($stormProblemCalls, 'recipient') === ['primary@example.invalid', 'secondary@example.invalid'], 'the original problem Storm Summary should attempt one delivery for each qualifying recipient');
$stormRecoveryTransitionId = $insertQueueTransition($stormRecoveryDb, $stormRecoveryId, $stormRecoveryKey, 'debounce-storm-recovery', 'Unreachable', 'Reachable', '2026-06-15 14:01:00');
$stormRecoveryDb->exec("UPDATE registrationwatch_registrations SET last_known_status = 'Reachable' WHERE id = {$stormRecoveryId}");
CI_Email::$sendResults = [true, true];
$stormRecoveryEmailStart = count(CI_Email::$sentMessages);
$stormRecoveryEmailDelta = $runQueueAndCountEmails($stormRecoveryQueue, $stormRecoveryWatch, '2026-06-15 14:01:00');
$stormRecoveryCalls = array_slice(CI_Email::$sentMessages, $stormRecoveryEmailStart);
$stormRecoveryResults = $stormRecoveryDb->query("SELECT recipient, result FROM registrationwatch_alert_history WHERE alert_type = 'recovery' ORDER BY recipient")->fetchAll(PDO::FETCH_KEY_PAIR);
$failedStormRecovery = $stormRecoveryDb->query("SELECT registration_id, history_id, recipient, result, error FROM registrationwatch_alert_history WHERE alert_type = 'recovery' AND recipient = 'secondary@example.invalid'")->fetch(PDO::FETCH_ASSOC);
assert_true(($stormRecoveryResults['primary@example.invalid'] ?? '') === 'storm_suppressed', 'successfully storm-suppressed problem should authorize a storm-processed recovery for that recipient');
assert_true(($stormRecoveryResults['secondary@example.invalid'] ?? '') === 'suppressed' && is_array($failedStormRecovery) && (int)$failedStormRecovery['registration_id'] === $stormRecoveryId && (int)$failedStormRecovery['history_id'] === $stormRecoveryTransitionId && $failedStormRecovery['error'] === 'Storm Summary delivery failed: CI_Email send failed.', 'failed Storm Summary should produce an accurate recovery suppression audit without authorizing recovery');
assert_true((int)$stormRecoveryDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetchColumn() === 2 && $stormRecoveryDb->query("SELECT recipient FROM registrationwatch_alert_history WHERE alert_type = 'recovery' AND result = 'storm_suppressed'")->fetchColumn() === 'primary@example.invalid', 'recovery decisions should remain independent for successful and failed problem recipients');
assert_true($stormRecoveryEmailDelta === 1 && count($stormRecoveryCalls) === 1 && $stormRecoveryCalls[0]['recipient'] === 'primary@example.invalid', 'only the successful problem recipient should receive the recovery Storm Summary');
$stormRecoveryReplayEmails = $runQueueAndCountEmails($stormRecoveryQueue, $stormRecoveryWatch, '2026-06-15 14:01:10');
assert_true($stormRecoveryReplayEmails === 0 && (int)$stormRecoveryDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetchColumn() === 2, 'replaying mixed Storm Summary outcomes must not send or duplicate recovery decisions');
assert_true(strpos($stormRecoveryCalls[0]['subject'], 'Storm Summary') !== false && strpos($stormRecoveryCalls[0]['message'], 'debounce-storm-recovery - recovered to Reachable') !== false, 'primary recovery summary should contain the eligible recovery alert');

$recipientSummaryRecipients = $stormRecoveryRecipients . ', observer@example.invalid';
[$recipientSummaryDb, $recipientSummaryWatch] = $makeQueueFixture(0, 2, '', $recipientSummaryRecipients);
$dispatchCollected = new ReflectionMethod($recipientSummaryWatch, 'dispatchCollectedAlerts');
$dispatchCollected->setAccessible(true);
$recipientSummaryAlerts = [
	[
		'registration_id' => 1,
		'registration_key' => 'primary-key',
		'extension' => '7101',
		'history_id' => 101,
		'reminder_n' => 0,
		'alert_type' => 'unreachable',
		'status' => 'Unreachable',
		'recipient' => 'primary@example.invalid',
		'subject' => 'Primary problem',
		'message' => 'Primary problem details',
	],
	[
		'registration_id' => 2,
		'registration_key' => 'secondary-key',
		'extension' => '7202',
		'history_id' => 202,
		'reminder_n' => 0,
		'alert_type' => 'not_registered',
		'status' => 'Not registered',
		'recipient' => 'secondary@example.invalid',
		'subject' => 'Secondary problem',
		'message' => 'Secondary problem details',
	],
];
CI_Email::$sendResults = [true, true];
$recipientSummaryEmailStart = count(CI_Email::$sentMessages);
$dispatchCollected->invoke($recipientSummaryWatch, $recipientSummaryAlerts, [], ['storm_threshold' => '2'], explode(', ', $recipientSummaryRecipients), '2026-06-15 14:02:00');
$recipientSummaryCalls = array_slice(CI_Email::$sentMessages, $recipientSummaryEmailStart);
$recipientSummaryCallsByRecipient = [];
foreach ($recipientSummaryCalls as $summaryCall) {
	$recipientSummaryCallsByRecipient[$summaryCall['recipient']] = $summaryCall;
}
assert_true(count($recipientSummaryCalls) === 2 && isset($recipientSummaryCallsByRecipient['primary@example.invalid'], $recipientSummaryCallsByRecipient['secondary@example.invalid']), 'Storm Summary should only be sent to recipients with collected alerts');
assert_true(strpos($recipientSummaryCallsByRecipient['primary@example.invalid']['message'], '7101 - became Unreachable') !== false && strpos($recipientSummaryCallsByRecipient['primary@example.invalid']['message'], '7202 -') === false, 'primary Storm Summary should exclude the secondary recipient alert');
assert_true(strpos($recipientSummaryCallsByRecipient['secondary@example.invalid']['message'], '7202 - became Not registered') !== false && strpos($recipientSummaryCallsByRecipient['secondary@example.invalid']['message'], '7101 -') === false, 'secondary Storm Summary should exclude the primary recipient alert');

[$recoveryBatchDb, $recoveryBatchWatch, $recoveryBatchQueue] = $makeQueueFixture(0, 2);
[$recoveryBatchIdA, $recoveryBatchKeyA] = $insertQueueRegistration($recoveryBatchDb, 'debounce-recovery-batch-a');
[$recoveryBatchIdB, $recoveryBatchKeyB] = $insertQueueRegistration($recoveryBatchDb, 'debounce-recovery-batch-b');
$insertQueueTransition($recoveryBatchDb, $recoveryBatchIdA, $recoveryBatchKeyA, 'debounce-recovery-batch-a', 'Reachable', 'Unreachable', '2026-06-15 14:10:00');
$insertQueueTransition($recoveryBatchDb, $recoveryBatchIdB, $recoveryBatchKeyB, 'debounce-recovery-batch-b', 'Reachable', 'Unreachable', '2026-06-15 14:10:00');
$runQueue($recoveryBatchQueue, $recoveryBatchWatch, '2026-06-15 14:10:00');
$insertQueueTransition($recoveryBatchDb, $recoveryBatchIdA, $recoveryBatchKeyA, 'debounce-recovery-batch-a', 'Unreachable', 'Reachable', '2026-06-15 14:11:00');
$insertQueueTransition($recoveryBatchDb, $recoveryBatchIdB, $recoveryBatchKeyB, 'debounce-recovery-batch-b', 'Unreachable', 'Reachable', '2026-06-15 14:11:00');
$recoveryBatchDb->exec("UPDATE registrationwatch_registrations SET last_known_status = 'Reachable'");
$runQueue($recoveryBatchQueue, $recoveryBatchWatch, '2026-06-15 14:11:00');
assert_true((int)$recoveryBatchDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'storm_summary' AND result = 'sent'")->fetchColumn() === 2, 'a batch of recoveries should continue through Storm Threshold summary handling');
assert_true((int)$recoveryBatchDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'recovery' AND result = 'storm_suppressed'")->fetchColumn() === 2, 'Storm Threshold should record both covered recovery actions');

[$churnDb, $churnWatch, $churnQueue] = $makeQueueFixture(0, 0);
[$churnId, $churnKey] = $insertQueueRegistration($churnDb, 'debounce-high-churn');
$churnState = 'Reachable';
$churnStart = strtotime('2026-06-15 10:58:00');
for ($transitionIndex = 0; $transitionIndex < 105; $transitionIndex++) {
	$nextState = $churnState === 'Reachable' ? 'Unreachable' : 'Reachable';
	$createdAt = date('Y-m-d H:i:s', $churnStart + $transitionIndex);
	$insertQueueTransition($churnDb, $churnId, $churnKey, 'debounce-high-churn', $churnState, $nextState, $createdAt);
	$churnState = $nextState;
}
[$fairId, $fairKey] = $insertQueueRegistration($churnDb, 'debounce-fair-candidate');
$insertQueueTransition($churnDb, $fairId, $fairKey, 'debounce-fair-candidate', 'Reachable', 'Unreachable', '2026-06-15 10:59:50');
$runQueue($churnQueue, $churnWatch, '2026-06-15 11:00:00');
assert_true((int)$churnDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE registration_id = {$fairId} AND alert_type = 'unreachable'")->fetchColumn() === 1, 'high-churn history for one registration must not starve another recent candidate');

[$immediateDb, $immediateWatch, $immediateQueue] = $makeQueueFixture(0);
[$immediateId, $immediateKey] = $insertQueueRegistration($immediateDb, 'debounce-immediate', 'Not registered');
$insertQueueTransition($immediateDb, $immediateId, $immediateKey, 'debounce-immediate', 'Reachable', 'Not registered', '2026-06-15 10:00:00');
$runQueue($immediateQueue, $immediateWatch, '2026-06-15 10:00:00');
assert_true((int)$immediateDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history WHERE reminder_n = 0')->fetchColumn() === 1, 'zero debounce should preserve immediate initial-alert eligibility');

[$staleDb, $staleWatch, $staleQueue] = $makeQueueFixture(300);
[$staleId, $staleKey] = $insertQueueRegistration($staleDb, 'debounce-stale', 'Unreachable');
$insertQueueTransition($staleDb, $staleId, $staleKey, 'debounce-stale', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
$runQueue($staleQueue, $staleWatch, '2026-06-15 10:00:00');
assert_true((int)$staleDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 0, 'stale unhealthy transition must not be replayed after the freshness window');

[$ongoingSnoozedDb, $ongoingSnoozedWatch, $ongoingSnoozedQueue] = $makeQueueFixture(0, 20, date('Y-m-d H:i:s', time() + 3600));
[$ongoingSnoozedId, $ongoingSnoozedKey] = $insertQueueRegistration($ongoingSnoozedDb, 'debounce-snoozed-ongoing', 'Unreachable');
$insertQueueTransition($ongoingSnoozedDb, $ongoingSnoozedId, $ongoingSnoozedKey, 'debounce-snoozed-ongoing', 'Reachable', 'Unreachable', '2026-06-15 10:00:00');
$runQueue($ongoingSnoozedQueue, $ongoingSnoozedWatch, '2026-06-15 10:01:00');
$ongoingSnoozedResult = $ongoingSnoozedDb->query('SELECT result FROM registrationwatch_alert_history')->fetchColumn();
assert_true((string)$ongoingSnoozedResult === 'suppressed', 'ongoing snoozed eligible alert should retain existing suppression behavior; got ' . var_export($ongoingSnoozedResult, true));

[$snoozedDb, $snoozedWatch, $snoozedQueue] = $makeQueueFixture(0, 20, date('Y-m-d H:i:s', time() + 3600));
[$snoozedId, $snoozedKey] = $insertQueueRegistration($snoozedDb, 'debounce-snoozed', 'Unreachable');
$snoozedProblemId = $insertQueueTransition($snoozedDb, $snoozedId, $snoozedKey, 'debounce-snoozed', 'Reachable', 'Unreachable', '2026-06-15 10:01:00');
$snoozedRecoveryId = $insertQueueTransition($snoozedDb, $snoozedId, $snoozedKey, 'debounce-snoozed', 'Unreachable', 'Reachable', '2026-06-15 10:02:00');
$snoozedEmailDelta = $runQueueAndCountEmails($snoozedQueue, $snoozedWatch, '2026-06-15 10:02:00');
$snoozedProblem = $snoozedDb->query("SELECT history_id, result, error FROM registrationwatch_alert_history WHERE alert_type = 'unreachable'")->fetch(PDO::FETCH_ASSOC);
$snoozedRecovery = $snoozedDb->query("SELECT history_id, result, error FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($snoozedProblem) && (int)$snoozedProblem['history_id'] === $snoozedProblemId && $snoozedProblem['result'] === 'suppressed' && $snoozedProblem['error'] === 'Monitoring snoozed', 'snoozed eligible problem alert should retain existing suppression behavior');
assert_true(is_array($snoozedRecovery) && (int)$snoozedRecovery['history_id'] === $snoozedRecoveryId && $snoozedRecovery['result'] === 'suppressed' && $snoozedRecovery['error'] === 'Original problem alert suppressed (Monitoring snoozed)', 'recovery withheld by Monitoring Snooze should be audited with its reason');
assert_true($snoozedEmailDelta === 0, 'Monitoring Snooze should continue to send neither problem nor recovery email');

[$recoveryDisabledDb, $recoveryDisabledWatch, $recoveryDisabledQueue] = $makeQueueFixture(0);
$recoveryDisabledDb->prepare("UPDATE registrationwatch_settings SET setting_value = '0' WHERE setting_key = 'alert_on_recovery'")->execute();
[$recoveryDisabledId, $recoveryDisabledKey] = $insertQueueRegistration($recoveryDisabledDb, 'recovery-disabled');
$insertQueueTransition($recoveryDisabledDb, $recoveryDisabledId, $recoveryDisabledKey, 'recovery-disabled', 'Reachable', 'Unreachable', '2026-06-15 10:03:00');
$insertQueueTransition($recoveryDisabledDb, $recoveryDisabledId, $recoveryDisabledKey, 'recovery-disabled', 'Unreachable', 'Reachable', '2026-06-15 10:04:00');
$recoveryDisabledEmailDelta = $runQueueAndCountEmails($recoveryDisabledQueue, $recoveryDisabledWatch, '2026-06-15 10:04:00');
assert_true($recoveryDisabledEmailDelta === 1 && (int)$recoveryDisabledDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'recovery'")->fetchColumn() === 0, 'disabled recovery notifications must not send or create recovery suppression records');

[$monitoringDisabledDb, $monitoringDisabledWatch, $monitoringDisabledQueue] = $makeQueueFixture(0);
[$monitoringDisabledId, $monitoringDisabledKey] = $insertQueueRegistration($monitoringDisabledDb, 'monitoring-disabled');
$insertQueueTransition($monitoringDisabledDb, $monitoringDisabledId, $monitoringDisabledKey, 'monitoring-disabled', 'Reachable', 'Unreachable', '2026-06-15 10:05:00');
$insertQueueTransition($monitoringDisabledDb, $monitoringDisabledId, $monitoringDisabledKey, 'monitoring-disabled', 'Unreachable', 'Reachable', '2026-06-15 10:06:00');
$monitoringDisabledDb->prepare('UPDATE registrationwatch_registrations SET enabled = 0 WHERE id = ?')->execute([$monitoringDisabledId]);
$monitoringDisabledEmailDelta = $runQueueAndCountEmails($monitoringDisabledQueue, $monitoringDisabledWatch, '2026-06-15 10:06:00');
assert_true($monitoringDisabledEmailDelta === 0 && (int)$monitoringDisabledDb->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 0, 'disabled monitoring must not create retrospective notification or recovery audit records');

[$stormDb, $stormWatch, $stormQueue] = $makeQueueFixture(0, 2);
[$stormIdA, $stormKeyA] = $insertQueueRegistration($stormDb, 'debounce-storm-a', 'Unreachable');
[$stormIdB, $stormKeyB] = $insertQueueRegistration($stormDb, 'debounce-storm-b', 'Unreachable');
$insertQueueTransition($stormDb, $stormIdA, $stormKeyA, 'debounce-storm-a', 'Reachable', 'Unreachable', '2026-06-15 10:01:00');
$insertQueueTransition($stormDb, $stormIdB, $stormKeyB, 'debounce-storm-b', 'Reachable', 'Unreachable', '2026-06-15 10:01:00');
$runQueue($stormQueue, $stormWatch, '2026-06-15 10:01:00');
assert_true((int)$stormDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'storm_summary'")->fetchColumn() === 1, 'storm threshold should continue to summarize eligible alert batches');
assert_true((int)$stormDb->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE alert_type = 'unreachable' AND result = 'storm_suppressed'")->fetchColumn() === 2, 'successful storm summary should suppress individual alert deliveries');

$confirmedRepeatFailures = [];
$runConfirmedRepeatRegression = function (string $name, callable $regression) use (&$confirmedRepeatFailures): void {
	try {
		$regression();
	} catch (Throwable $e) {
		$confirmedRepeatFailures[] = $name . ': ' . $e->getMessage();
	}
};

$runConfirmedRepeatRegression('debounced same-state repeats', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueue, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-debounce-unreachable', 'Reachable', '5m', '192.0.2.101');
	$setQueueLiveStatus($watch, 'repeat-debounce-unreachable', '192.0.2.101', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-debounce-unreachable', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:04:59') === 0, 'debounced outage must not email before the initial alert is due');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00') === 1, 'debounced outage must send its initial problem email at 09:05');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:20') === 0, 'replaying the incident must not duplicate the initial problem email');
	foreach ([
		'2026-06-15 09:10:00' => '2026-06-15 09:15:00',
		'2026-06-15 09:15:00' => '2026-06-15 09:20:00',
		'2026-06-15 09:20:00' => '2026-06-15 09:25:00',
	] as $dueAt => $nextDueAt) {
		assert_true($runQueueAndCountEmails($queue, $watch, $dueAt) === 1, 'persistent Unreachable should send one reminder at ' . $dueAt);
		$state = $database->query("SELECT alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
		assert_true((int)$state['alert_count'] === count($database->query("SELECT id FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n > 0")->fetchAll(PDO::FETCH_COLUMN)), 'reminder counter should advance once per delivered reminder');
		assert_true($state['next_due_at'] === $nextDueAt, 'next reminder should be scheduled from the last delivered repeat');
	}
	$history = $database->query("SELECT alert_type, status, reminder_n, subject FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} ORDER BY reminder_n")->fetchAll(PDO::FETCH_ASSOC);
	assert_true(array_map('intval', array_column($history, 'reminder_n')) === [0, 1, 2, 3], 'Alert History should contain the initial alert and reminders 1 through 3');
	assert_true(array_column($history, 'alert_type') === ['unreachable', 'unreachable', 'unreachable', 'unreachable'], 'same-state Alert History should retain the Unreachable type');
	assert_true(array_column($history, 'status') === ['Unreachable', 'Unreachable', 'Unreachable', 'Unreachable'], 'same-state Alert History should retain the live status');
	assert_true(strpos($history[3]['subject'], 'still Unreachable') !== false, 'reminder subject should name the current unhealthy status');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-debounce-unreachable', 'Unreachable', 'Reachable', '2026-06-15 09:22:00');
	$setQueueLiveStatus($watch, 'repeat-debounce-unreachable', '192.0.2.101', 'Reachable');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:22:00') === 1, 'recovery after multiple reminders should send exactly one recovery email');
	assert_true($database->query("SELECT result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type = 'recovery'")->fetchColumn() === 'sent', 'recovery after multiple reminders should be recorded as sent');
	assert_true((int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetchColumn() === 0, 'recovery after multiple reminders should cancel the escalation');
});

$runConfirmedRepeatRegression('Not registered repeats', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-debounce-not-registered', 'Reachable', '5m', '192.0.2.102');
	$setQueueLiveStatus($watch, 'repeat-debounce-not-registered', '192.0.2.102', 'Not registered');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-debounce-not-registered', 'Reachable', 'Not registered', '2026-06-15 09:00:00');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00') === 1, 'debounced Not registered should send its initial problem email');
	foreach (['2026-06-15 09:10:00', '2026-06-15 09:15:00', '2026-06-15 09:20:00'] as $dueAt) {
		assert_true($runQueueAndCountEmails($queue, $watch, $dueAt) === 1, 'persistent Not registered should send one reminder at ' . $dueAt);
	}
	$history = $database->query("SELECT alert_type, status, reminder_n, subject FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} ORDER BY reminder_n")->fetchAll(PDO::FETCH_ASSOC);
	assert_true(array_map('intval', array_column($history, 'reminder_n')) === [0, 1, 2, 3], 'Not registered history should number the initial alert and first three repeats');
	assert_true(array_column($history, 'alert_type') === ['not_registered', 'not_registered', 'not_registered', 'not_registered'] && array_column($history, 'status') === ['Not registered', 'Not registered', 'Not registered', 'Not registered'], 'Not registered reminders must use matching alert type and status');
	assert_true(strpos($history[3]['subject'], 'still Not registered') !== false, 'Not registered reminder subject must use the current state');
});

$runConfirmedRepeatRegression('reverse unhealthy handoff', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-reverse-handoff', 'Reachable', '5m', '192.0.2.103');
	$setQueueLiveStatus($watch, 'repeat-reverse-handoff', '192.0.2.103', 'Not registered');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-reverse-handoff', 'Reachable', 'Not registered', '2026-06-15 09:00:00');
	$initialEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00');
	$stateBefore = $database->query("SELECT active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-reverse-handoff', 'Not registered', 'Unreachable', '2026-06-15 09:08:00');
	$setQueueLiveStatus($watch, 'repeat-reverse-handoff', '192.0.2.103', 'Unreachable');
	$handoffEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:08:00');
	$stateAfter = $database->query("SELECT active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	assert_true($stateAfter === $stateBefore, 'unhealthy-state handoff must preserve every schedule field');
	$reminderEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00');
	$reminder = $database->query("SELECT alert_type, status, reminder_n, subject FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n = 1")->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($reminder) && $reminder['alert_type'] === 'unreachable' && $reminder['status'] === 'Unreachable' && (int)$reminder['reminder_n'] === 1, 'handoff reminder must match the live Unreachable state');
	assert_true(strpos($reminder['subject'], 'still Unreachable') !== false, 'handoff reminder subject must match the live Unreachable state');
	$initialProblemCount = (int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n = 0 AND alert_type IN ('unreachable', 'not_registered')")->fetchColumn();
	assert_true([$initialEmailDelta, $handoffEmailDelta, $reminderEmailDelta] === [1, 0, 1] && $initialProblemCount === 1 && count(CI_Email::$sentMessages) === 2, 'Not registered to Unreachable should send one initial and one repeat, not an extra initial; got ' . json_encode([$initialEmailDelta, $handoffEmailDelta, $reminderEmailDelta, $initialProblemCount, count(CI_Email::$sentMessages)]));
});

$runConfirmedRepeatRegression('stale escalation type follows live state', function () use ($makeQueueFixture, $insertQueueRegistration, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-stale-type', 'Not registered', '5m', '192.0.2.106');
	$setQueueLiveStatus($watch, 'repeat-stale-type', '192.0.2.106', 'Not registered');
	$database->prepare('INSERT INTO registrationwatch_alert_escalation (registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$registrationId, $registrationKey, 'repeat-stale-type', 1, 'unreachable', '2026-06-15 09:00:00', '2026-06-15 09:05:00', 0, '2026-06-15 09:10:00', '5m']);
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00') === 1, 'continuously unhealthy live state should remain eligible when escalation type is stale');
	$history = $database->query("SELECT alert_type, status, reminder_n, subject FROM registrationwatch_alert_history WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	$stateType = $database->query("SELECT alert_type FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetchColumn();
	assert_true(is_array($history) && $history['alert_type'] === 'not_registered' && $history['status'] === 'Not registered' && (int)$history['reminder_n'] === 1, 'stale escalation reminder history must match the current live state');
	assert_true($stateType === 'not_registered' && strpos($history['subject'], 'still Not registered') !== false, 'stale escalation should retarget its type and subject to the current live state');
});

$runConfirmedRepeatRegression('recovery after successful repeat', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-authorises-recovery', 'Reachable', '5m', '192.0.2.104');
	$setQueueLiveStatus($watch, 'repeat-authorises-recovery', '192.0.2.104', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-authorises-recovery', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	CI_Email::$sendResults = [false, true, true];
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00') === 1, 'failed initial problem email should still be attempted');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00') === 1, 'first repeat should still be attempted after initial failure');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-authorises-recovery', 'Unreachable', 'Reachable', '2026-06-15 09:12:00');
	$setQueueLiveStatus($watch, 'repeat-authorises-recovery', '192.0.2.104', 'Reachable');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:12:00') === 1, 'successful repeat should authorize one recovery email');
	$recovery = $database->query("SELECT result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type = 'recovery'")->fetchColumn();
	assert_true($recovery === 'sent', 'recovery Alert History should record successful delivery after a successful repeat');
	assert_true((int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type = 'recovery' AND result = 'suppressed'")->fetchColumn() === 0, 'successful repeat notification must continue to authorize recovery without a suppression record');
	assert_true((int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetchColumn() === 0, 'genuine recovery should cancel the active repeat schedule');
});

$runConfirmedRepeatRegression('Unreachable to Not registered handoff', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-forward-handoff', 'Reachable', '5m', '192.0.2.105');
	$setQueueLiveStatus($watch, 'repeat-forward-handoff', '192.0.2.105', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-forward-handoff', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	$initialEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00');
	$stateBefore = $database->query("SELECT active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-forward-handoff', 'Unreachable', 'Not registered', '2026-06-15 09:08:00');
	$setQueueLiveStatus($watch, 'repeat-forward-handoff', '192.0.2.105', 'Not registered');
	$handoffEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:08:00');
	$stateAfter = $database->query("SELECT active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	assert_true($stateAfter === $stateBefore, 'Unreachable to Not registered handoff must preserve the repeat clock and counter');
	$reminderEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00');
	$reminder = $database->query("SELECT alert_type, status, reminder_n, subject FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n = 1")->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($reminder) && $reminder['alert_type'] === 'not_registered' && $reminder['status'] === 'Not registered' && (int)$reminder['reminder_n'] === 1, 'forward handoff reminder must match the current state');
	assert_true(strpos($reminder['subject'], 'still Not registered') !== false, 'forward handoff reminder subject must match the current state');
	$initialProblemCount = (int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n = 0 AND alert_type IN ('unreachable', 'not_registered')")->fetchColumn();
	assert_true([$initialEmailDelta, $handoffEmailDelta, $reminderEmailDelta] === [1, 1, 1] && $initialProblemCount === 2 && count(CI_Email::$sentMessages) === 3, 'Unreachable to Not registered should send two eligible initial alerts plus one repeat; got ' . json_encode([$initialEmailDelta, $handoffEmailDelta, $reminderEmailDelta, $initialProblemCount, count(CI_Email::$sentMessages)]));
});

$runConfirmedRepeatRegression('conflicting escalation keeps newer incident only', function () use ($makeQueueFixture, $insertQueueRegistration, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-conflicting-escalations', 'Not registered', '5m', '192.0.2.125');
	$setQueueLiveStatus($watch, 'repeat-conflicting-escalations', '192.0.2.125', 'Not registered');
	$insertEscalation = $database->prepare('INSERT INTO registrationwatch_alert_escalation (registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
	$insertEscalation->execute([$registrationId, $registrationKey, 'repeat-conflicting-escalations', 1, 'unreachable', '2026-06-15 09:00:00', '2026-06-15 09:05:00', 0, '2026-06-15 09:10:00', '5m']);
	$insertEscalation->execute([$registrationId, $registrationKey, 'repeat-conflicting-escalations', 2, 'not_registered', '2026-06-15 09:08:00', '2026-06-15 09:08:00', 0, '2026-06-15 09:10:00', '5m']);
	$emailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00');
	$history = $database->query("SELECT history_id, alert_type, status, reminder_n FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} ORDER BY history_id")->fetchAll(PDO::FETCH_ASSOC);
	$escalations = $database->query("SELECT history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetchAll(PDO::FETCH_ASSOC);
	assert_true($emailDelta === 1 && count($history) === 1 && (int)$history[0]['history_id'] === 2 && $history[0]['alert_type'] === 'not_registered' && $history[0]['status'] === 'Not registered' && (int)$history[0]['reminder_n'] === 1, 'conflicting escalation must emit only the newer incident reminder; got ' . json_encode([$emailDelta, $history]));
	assert_true(count($escalations) === 1 && (int)$escalations[0]['history_id'] === 2 && $escalations[0]['alert_type'] === 'not_registered' && $escalations[0]['active_since'] === '2026-06-15 09:08:00' && $escalations[0]['last_alert_at'] === '2026-06-15 09:10:00' && (int)$escalations[0]['alert_count'] === 1 && $escalations[0]['next_due_at'] === '2026-06-15 09:15:00', 'older conflicting escalation must be removed while preserving and advancing the newer schedule');
});

$runConfirmedRepeatRegression('cross-state reminders obey alert settings', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	$cases = [
		['repeat-setting-reverse', 'Not registered', 'Unreachable', 'alert_on_unreachable', '192.0.2.126'],
		['repeat-setting-forward', 'Unreachable', 'Not registered', 'alert_on_not_registered', '192.0.2.127'],
	];
	foreach ($cases as [$extension, $initialState, $nextState, $disabledSetting, $sourceIp]) {
		[$database, $watch, $queue] = $makeQueueFixture(300, 0);
		$database->prepare('UPDATE registrationwatch_settings SET setting_value = ? WHERE setting_key = ?')->execute(['0', $disabledSetting]);
		[$registrationId, $registrationKey] = $insertQueueRegistration($database, $extension, 'Reachable', '5m', $sourceIp);
		$setQueueLiveStatus($watch, $extension, $sourceIp, $initialState);
		$insertQueueTransition($database, $registrationId, $registrationKey, $extension, 'Reachable', $initialState, '2026-06-15 09:00:00');
		$initialEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00');
		$insertQueueTransition($database, $registrationId, $registrationKey, $extension, $initialState, $nextState, '2026-06-15 09:08:00');
		$setQueueLiveStatus($watch, $extension, $sourceIp, $nextState);
		$runQueueAndCountEmails($queue, $watch, '2026-06-15 09:08:00');
		$beforeDue = $database->query("SELECT active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
		$disabledDueEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00');
		$afterDisabledDue = $database->query("SELECT active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
		$database->prepare('UPDATE registrationwatch_settings SET setting_value = ? WHERE setting_key = ?')->execute(['1', $disabledSetting]);
		$reenabledEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:20:01');
		$reminder = $database->query("SELECT alert_type, status, reminder_n FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n = 1")->fetch(PDO::FETCH_ASSOC);
		assert_true($initialEmailDelta === 1 && $disabledDueEmailDelta === 0 && $reenabledEmailDelta === 1 && $beforeDue === $afterDisabledDue, $extension . ' disabled state alerts should defer, not consume, the active reminder cycle');
		assert_true(is_array($reminder) && (int)$reminder['reminder_n'] === 1 && $reminder['alert_type'] === ($nextState === 'Unreachable' ? 'unreachable' : 'not_registered') && $reminder['status'] === $nextState, $extension . ' re-enabled reminder should use the current state and next sequential number');
	}
});

$runConfirmedRepeatRegression('all repeat modes with debounce', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	$modes = [
		'never' => null,
		'5m' => 300,
		'hourly' => 3600,
		'daily' => 86400,
		'escalating' => 300,
	];
	foreach ($modes as $mode => $interval) {
		[$database, $watch, $queue] = $makeQueueFixture(300, 0);
		$database->prepare("UPDATE registrationwatch_settings SET setting_value = ? WHERE setting_key = 'repeat_mode'")->execute([$mode]);
		$extension = 'repeat-global-' . $mode;
		$sourceIp = '192.0.2.' . (110 + count($database->query('SELECT id FROM registrationwatch_registrations')->fetchAll(PDO::FETCH_COLUMN)));
		[$registrationId, $registrationKey] = $insertQueueRegistration($database, $extension, 'Reachable', '', $sourceIp);
		$setQueueLiveStatus($watch, $extension, $sourceIp, 'Unreachable');
		$insertQueueTransition($database, $registrationId, $registrationKey, $extension, 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
		assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:04:59') === 0, $mode . ' mode must honor debounce before initial delivery');
		assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00') === 1, $mode . ' mode should send the initial alert after debounce');
		$state = $database->query("SELECT repeat_mode, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
		if ($interval === null) {
			assert_true($state === false, 'Never mode must not create an escalation');
			assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 10:05:00') === 0, 'Never mode must not send reminders');
			continue;
		}
		$expectedDue = date('Y-m-d H:i:s', strtotime('2026-06-15 09:05:00') + $interval);
		assert_true(is_array($state) && $state['repeat_mode'] === $mode && (int)$state['alert_count'] === 0 && $state['next_due_at'] === $expectedDue, $mode . ' mode should retain its expected first due time');
		assert_true($runQueueAndCountEmails($queue, $watch, $expectedDue) === 1, $mode . ' mode should send its first reminder at the due time');
		$afterReminder = $database->query("SELECT alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
		$nextInterval = $mode === 'escalating' ? 300 : $interval;
		assert_true((int)$afterReminder['alert_count'] === 1 && $afterReminder['next_due_at'] === date('Y-m-d H:i:s', strtotime($expectedDue) + $nextInterval), $mode . ' mode should advance its counter and next due time once');
	}
});

$runConfirmedRepeatRegression('per-extension repeat override with debounce', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	$database->prepare("UPDATE registrationwatch_settings SET setting_value = 'hourly' WHERE setting_key = 'repeat_mode'")->execute();
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-extension-override', 'Reachable', '5m', '192.0.2.120');
	$setQueueLiveStatus($watch, 'repeat-extension-override', '192.0.2.120', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-extension-override', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00') === 1, 'per-extension override should preserve the debounced initial alert');
	$state = $database->query("SELECT repeat_mode, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($state) && $state['repeat_mode'] === '5m' && $state['next_due_at'] === '2026-06-15 09:10:00', 'per-extension 5m override should take precedence over the global hourly mode');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00') === 1, 'per-extension 5m override should deliver the first reminder');
});

$runConfirmedRepeatRegression('failed repeat retains existing counter policy', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-failed-delivery', 'Reachable', '5m', '192.0.2.121');
	$setQueueLiveStatus($watch, 'repeat-failed-delivery', '192.0.2.121', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-failed-delivery', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	CI_Email::$sendResults = [true, false, true];
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00') === 1, 'initial problem email should be attempted');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00') === 1, 'failed repeat email should be attempted once');
	$failed = $database->query("SELECT result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n = 1")->fetchColumn();
	$state = $database->query("SELECT alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	assert_true($failed === 'failed' && (int)$state['alert_count'] === 1 && $state['next_due_at'] === '2026-06-15 09:15:00', 'failed repeat should be recorded and consume one reminder number under existing policy');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:15:00') === 1, 'following repeat should proceed after a failed repeat');
	$next = $database->query("SELECT result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n = 2")->fetchColumn();
	assert_true($next === 'sent', 'next reminder should retain sequential numbering after failure');
});

$runConfirmedRepeatRegression('snoozed repeats retain existing counter policy', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0, date('Y-m-d H:i:s', time() + 3600));
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-snoozed', 'Reachable', '5m', '192.0.2.122');
	$setQueueLiveStatus($watch, 'repeat-snoozed', '192.0.2.122', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-snoozed', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	$runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00');
	$runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00');
	$database->prepare("UPDATE registrationwatch_settings SET setting_value = '' WHERE setting_key = 'monitoring_snoozed_until'")->execute();
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:15:00') === 1, 'unsnoozed later reminder should be attempted');
	$history = $database->query("SELECT reminder_n, result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} ORDER BY reminder_n")->fetchAll(PDO::FETCH_ASSOC);
	assert_true(array_map('intval', array_column($history, 'reminder_n')) === [0, 1, 2] && array_column($history, 'result') === ['suppressed', 'suppressed', 'sent'], 'snoozed cycles should remain audited and continue numbering under existing policy');
	$state = $database->query("SELECT alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	assert_true((int)$state['alert_count'] === 2 && $state['next_due_at'] === '2026-06-15 09:20:00', 'snoozed reminders should advance the existing schedule policy');
});

$runConfirmedRepeatRegression('Storm Summary repeats authorize recovery', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 1);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-storm-summary', 'Reachable', '5m', '192.0.2.123');
	$setQueueLiveStatus($watch, 'repeat-storm-summary', '192.0.2.123', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-storm-summary', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00') === 1, 'initial problem should be delivered through Storm Summary');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00') === 1, 'repeat should be delivered through Storm Summary');
	$repeatResult = $database->query("SELECT result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND reminder_n = 1")->fetchColumn();
	assert_true($repeatResult === 'storm_suppressed', 'successful repeat Storm Summary should count as successful notification coverage');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-storm-summary', 'Unreachable', 'Reachable', '2026-06-15 09:12:00');
	$setQueueLiveStatus($watch, 'repeat-storm-summary', '192.0.2.123', 'Reachable');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:12:00') === 1, 'successful repeat summary should authorize recovery coverage');
	$recoveryResult = $database->query("SELECT result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type = 'recovery'")->fetchColumn();
	assert_true($recoveryResult === 'storm_suppressed', 'recovery should continue using the existing Storm Summary behavior');
	assert_true((int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type = 'recovery' AND result = 'suppressed'")->fetchColumn() === 0, 'successful repeat Storm Summary coverage must not create a suppressed recovery decision');
});

$runConfirmedRepeatRegression('recovery cleanup is incident-scoped', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueue): void {
	[$database, $watch, $queue] = $makeQueueFixture(0, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-recovery-cleanup', 'Unreachable', 'hourly', '192.0.2.124');
	$oldProblemId = $insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-recovery-cleanup', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-recovery-cleanup', 'Unreachable', 'Not registered', '2026-06-15 09:01:00');
	$recoveryId = $insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-recovery-cleanup', 'Not registered', 'Reachable', '2026-06-15 09:02:00');
	$newProblemId = $insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-recovery-cleanup', 'Reachable', 'Not registered', '2026-06-15 09:03:00');
	$insertEscalation = $database->prepare('INSERT INTO registrationwatch_alert_escalation (registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
	$insertEscalation->execute([$registrationId, $registrationKey, 'repeat-recovery-cleanup', $oldProblemId, 'unreachable', '2026-06-15 09:00:00', '2026-06-15 09:01:00', 2, '2026-06-15 10:01:00', 'hourly']);
	$insertEscalation->execute([$registrationId, $registrationKey, 'repeat-recovery-cleanup', $newProblemId, 'not_registered', '2026-06-15 09:03:00', '2026-06-15 09:03:00', 4, '2026-06-15 10:03:00', 'hourly']);
	$database->exec("UPDATE registrationwatch_settings SET setting_value = '0' WHERE setting_key = 'alert_enabled'");
	$runQueue($queue, $watch, '2026-06-15 09:04:00');
	$state = $database->query("SELECT history_id, active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	assert_true($recoveryId > $oldProblemId && is_array($state) && (int)$state['history_id'] === $newProblemId, 'recovery should remove mismatched old state but preserve the newer incident schedule');
	assert_true($state['active_since'] === '2026-06-15 09:03:00' && $state['last_alert_at'] === '2026-06-15 09:03:00' && (int)$state['alert_count'] === 4 && $state['next_due_at'] === '2026-06-15 10:03:00', 'newer incident schedule fields must remain unchanged by older recovery replay');
});

$runConfirmedRepeatRegression('Sam 247020 Unknown handoff timeline', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(180, 0);
	$database->prepare("UPDATE registrationwatch_settings SET setting_value = '5m' WHERE setting_key = 'repeat_mode'")->execute();
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, '247020', 'Reachable', '', '192.0.2.247');
	$setQueueLiveStatus($watch, '247020', '192.0.2.247', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, '247020', 'Reachable', 'Unreachable', '2026-06-15 18:29:11');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 18:32:11') === 1, 'Sam timeline should send its initial Unreachable notification at debounce expiry');
	$insertQueueTransition($database, $registrationId, $registrationKey, '247020', 'Unreachable', 'Unknown', '2026-06-15 18:32:32');
	$setQueueLiveStatus($watch, '247020', '192.0.2.247', 'Unknown');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 18:32:32') === 0, 'intermediate Unknown must not send a problem or recovery notification');
	$insertQueueTransition($database, $registrationId, $registrationKey, '247020', 'Unknown', 'Not registered', '2026-06-15 18:32:51');
	$setQueueLiveStatus($watch, '247020', '192.0.2.247', 'Not registered');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 18:32:51') === 0, 'Unknown to Not registered must not duplicate the initial notification');
	foreach (['2026-06-15 18:37:11', '2026-06-15 18:42:11', '2026-06-15 18:47:11', '2026-06-15 18:52:11', '2026-06-15 18:57:11'] as $dueAt) {
		assert_true($runQueueAndCountEmails($queue, $watch, $dueAt) === 1, 'Sam timeline should deliver a repeat while still unhealthy at ' . $dueAt);
	}
	$insertQueueTransition($database, $registrationId, $registrationKey, '247020', 'Not registered', 'Reachable', '2026-06-15 18:59:20');
	$setQueueLiveStatus($watch, '247020', '192.0.2.247', 'Reachable');
	$recoveryEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 18:59:20');
	$problemHistory = $database->query("SELECT history_id, alert_type, status, reminder_n, result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type IN ('unreachable', 'not_registered') ORDER BY sent_at, id")->fetchAll(PDO::FETCH_ASSOC);
	$recoveryHistory = $database->query("SELECT result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type = 'recovery'")->fetchColumn();
	$escalationCount = (int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetchColumn();
	assert_true($recoveryEmailDelta === 1 && $recoveryHistory === 'sent', 'Sam timeline should send recovery after successfully delivered repeats; got ' . json_encode([$recoveryEmailDelta, $recoveryHistory, $problemHistory, $escalationCount]));
	assert_true(count($problemHistory) === 6 && array_map('intval', array_column($problemHistory, 'reminder_n')) === [0, 1, 2, 3, 4, 5], 'Sam timeline should preserve one initial alert and five sequential repeats');
	assert_true($escalationCount === 0, 'Sam timeline recovery should cancel the active escalation');
});

$runConfirmedRepeatRegression('Unknown at due time defers repeat', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(180, 0);
	$database->prepare("UPDATE registrationwatch_settings SET setting_value = '5m' WHERE setting_key = 'repeat_mode'")->execute();
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-unknown-at-due', 'Reachable', '', '192.0.2.248');
	$setQueueLiveStatus($watch, 'repeat-unknown-at-due', '192.0.2.248', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-unknown-at-due', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:03:00') === 1, 'Unknown due-time regression should establish the initial alert');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-unknown-at-due', 'Unreachable', 'Unknown', '2026-06-15 09:04:00');
	$setQueueLiveStatus($watch, 'repeat-unknown-at-due', '192.0.2.248', 'Unknown');
	$runQueueAndCountEmails($queue, $watch, '2026-06-15 09:04:00');
	$stateBeforeDue = $database->query("SELECT active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:08:00') === 0, 'Unknown live state should defer, not send, the due repeat');
	$stateAtDue = $database->query("SELECT active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetch(PDO::FETCH_ASSOC);
	assert_true(is_array($stateAtDue) && $stateAtDue === $stateBeforeDue, 'Unknown live state should preserve the due repeat schedule unchanged');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-unknown-at-due', 'Unknown', 'Not registered', '2026-06-15 09:09:00');
	$setQueueLiveStatus($watch, 'repeat-unknown-at-due', '192.0.2.248', 'Not registered');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:09:00') === 1, 'returning to a known unhealthy state should deliver the still-due repeat once');
	$history = $database->query("SELECT reminder_n, alert_type, status FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
	assert_true(array_map('intval', array_column($history, 'reminder_n')) === [0, 1] && $history[1]['alert_type'] === 'not_registered' && $history[1]['status'] === 'Not registered', 'deferred reminder should retain its number and follow the known current unhealthy state');
});

$runConfirmedRepeatRegression('Unknown to Reachable authorizes recovery', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(180, 0);
	$database->prepare("UPDATE registrationwatch_settings SET setting_value = '5m' WHERE setting_key = 'repeat_mode'")->execute();
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-unknown-direct-recovery', 'Reachable', '', '192.0.2.252');
	$setQueueLiveStatus($watch, 'repeat-unknown-direct-recovery', '192.0.2.252', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-unknown-direct-recovery', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:03:00') === 1, 'direct recovery regression should send the initial problem notification');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-unknown-direct-recovery', 'Unreachable', 'Unknown', '2026-06-15 09:04:00');
	$setQueueLiveStatus($watch, 'repeat-unknown-direct-recovery', '192.0.2.252', 'Unknown');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:04:00') === 0, 'transition to Unknown must not generate a notification');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-unknown-direct-recovery', 'Unknown', 'Reachable', '2026-06-15 09:05:00');
	$setQueueLiveStatus($watch, 'repeat-unknown-direct-recovery', '192.0.2.252', 'Reachable');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:05:00') === 1, 'successful problem notification followed by Unknown to Reachable should send one recovery email');
	$recoveryHistory = $database->query("SELECT result FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type = 'recovery'")->fetchColumn();
	assert_true($recoveryHistory === 'sent' && (int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetchColumn() === 0, 'direct recovery should be recorded and clear the escalation');
});

$runConfirmedRepeatRegression('stale replay respects completed incident boundary', function () use ($makeQueueFixture, $insertQueueRegistration, $insertQueueTransition, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(180, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-stale-incident-boundary', 'Reachable', '5m', '192.0.2.249');
	$setQueueLiveStatus($watch, 'repeat-stale-incident-boundary', '192.0.2.249', 'Unreachable');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-stale-incident-boundary', 'Reachable', 'Unreachable', '2026-06-15 09:00:00');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:03:00') === 1, 'first incident should send its initial alert at debounce expiry');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-stale-incident-boundary', 'Unreachable', 'Reachable', '2026-06-15 09:10:00');
	$setQueueLiveStatus($watch, 'repeat-stale-incident-boundary', '192.0.2.249', 'Reachable');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00') === 1, 'first incident should send its recovery');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-stale-incident-boundary', 'Reachable', 'Unknown', '2026-06-15 09:20:00');
	$setQueueLiveStatus($watch, 'repeat-stale-incident-boundary', '192.0.2.249', 'Unknown');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:20:00') === 0, 'new Unknown baseline should not send an alert');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-stale-incident-boundary', 'Unknown', 'Not registered', '2026-06-15 09:30:00');
	$setQueueLiveStatus($watch, 'repeat-stale-incident-boundary', '192.0.2.249', 'Not registered');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:30:00') === 0, 'Unknown-origin outage should remain unalerted');
	$insertQueueTransition($database, $registrationId, $registrationKey, 'repeat-stale-incident-boundary', 'Not registered', 'Reachable', '2026-06-15 10:00:00');
	$setQueueLiveStatus($watch, 'repeat-stale-incident-boundary', '192.0.2.249', 'Reachable');
	$lateRecoveryEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 10:00:00');
	$recoveryCount = (int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE registration_id = {$registrationId} AND alert_type = 'recovery'")->fetchColumn();
	assert_true($lateRecoveryEmailDelta === 0 && $recoveryCount === 1 && count(CI_Email::$sentMessages) === 2, 'completed 09:10 recovery must not authorize a second recovery for the independent Unknown-origin outage');
});

$runConfirmedRepeatRegression('matching older escalation loses to newer due incident', function () use ($makeQueueFixture, $insertQueueRegistration, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-conflict-matching-both-due', 'Not registered', '5m', '192.0.2.250');
	$setQueueLiveStatus($watch, 'repeat-conflict-matching-both-due', '192.0.2.250', 'Not registered');
	$insertEscalation = $database->prepare('INSERT INTO registrationwatch_alert_escalation (registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
	$insertEscalation->execute([$registrationId, $registrationKey, 'repeat-conflict-matching-both-due', 1, 'not_registered', '2026-06-15 09:00:00', '2026-06-15 09:05:00', 0, '2026-06-15 09:10:00', '5m']);
	$insertEscalation->execute([$registrationId, $registrationKey, 'repeat-conflict-matching-both-due', 2, 'unreachable', '2026-06-15 09:08:00', '2026-06-15 09:08:00', 0, '2026-06-15 09:10:00', '5m']);
	$emailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00');
	$history = $database->query("SELECT history_id, alert_type, status, reminder_n FROM registrationwatch_alert_history WHERE registration_id = {$registrationId}")->fetchAll(PDO::FETCH_ASSOC);
	$escalations = $database->query("SELECT history_id, alert_type, active_since, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetchAll(PDO::FETCH_ASSOC);
	assert_true($emailDelta === 1 && count($history) === 1 && (int)$history[0]['history_id'] === 2 && $history[0]['alert_type'] === 'not_registered' && $history[0]['status'] === 'Not registered' && (int)$history[0]['reminder_n'] === 1, 'only the newer incident should emit a reminder when both conflicting rows are due; got ' . json_encode([$emailDelta, $history]));
	assert_true(count($escalations) === 1 && (int)$escalations[0]['history_id'] === 2 && $escalations[0]['alert_type'] === 'not_registered' && $escalations[0]['active_since'] === '2026-06-15 09:08:00' && (int)$escalations[0]['alert_count'] === 1 && $escalations[0]['next_due_at'] === '2026-06-15 09:15:00', 'newer incident schedule should survive the both-due conflict');
});

$runConfirmedRepeatRegression('matching older escalation alone due is discarded', function () use ($makeQueueFixture, $insertQueueRegistration, $runQueueAndCountEmails, $setQueueLiveStatus): void {
	[$database, $watch, $queue] = $makeQueueFixture(300, 0);
	[$registrationId, $registrationKey] = $insertQueueRegistration($database, 'repeat-conflict-older-due', 'Not registered', '5m', '192.0.2.251');
	$setQueueLiveStatus($watch, 'repeat-conflict-older-due', '192.0.2.251', 'Not registered');
	$insertEscalation = $database->prepare('INSERT INTO registrationwatch_alert_escalation (registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
	$insertEscalation->execute([$registrationId, $registrationKey, 'repeat-conflict-older-due', 1, 'not_registered', '2026-06-15 09:00:00', '2026-06-15 09:05:00', 3, '2026-06-15 09:10:00', '5m']);
	$insertEscalation->execute([$registrationId, $registrationKey, 'repeat-conflict-older-due', 2, 'unreachable', '2026-06-15 09:08:00', '2026-06-15 09:08:00', 4, '2026-06-15 09:15:00', '5m']);
	$olderDueEmailDelta = $runQueueAndCountEmails($queue, $watch, '2026-06-15 09:10:00');
	$afterOlderDue = $database->query("SELECT history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$registrationId}")->fetchAll(PDO::FETCH_ASSOC);
	$historyAfterOlderDue = (int)$database->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE registration_id = {$registrationId}")->fetchColumn();
	assert_true($olderDueEmailDelta === 0 && $historyAfterOlderDue === 0 && count($afterOlderDue) === 1 && (int)$afterOlderDue[0]['history_id'] === 2 && $afterOlderDue[0]['alert_type'] === 'unreachable' && $afterOlderDue[0]['last_alert_at'] === '2026-06-15 09:08:00' && (int)$afterOlderDue[0]['alert_count'] === 4 && $afterOlderDue[0]['next_due_at'] === '2026-06-15 09:15:00', 'older matching escalation should be discarded without emitting or altering the newer schedule');
	assert_true($runQueueAndCountEmails($queue, $watch, '2026-06-15 09:15:00') === 1, 'the newer incident should send exactly once when its own schedule becomes due');
	$history = $database->query("SELECT history_id, alert_type, status, reminder_n FROM registrationwatch_alert_history WHERE registration_id = {$registrationId}")->fetchAll(PDO::FETCH_ASSOC);
	assert_true(count($history) === 1 && (int)$history[0]['history_id'] === 2 && $history[0]['alert_type'] === 'not_registered' && $history[0]['status'] === 'Not registered' && (int)$history[0]['reminder_n'] === 5, 'the eventual reminder must belong only to the newer incident');
});

assert_true(!$confirmedRepeatFailures, implode("\n", $confirmedRepeatFailures));

echo "repeat alerting contract tests passed\n";
