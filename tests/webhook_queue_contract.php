<?php

declare(strict_types=1);

interface BMO {}

if (!function_exists('_')) {
	function _($text) {
		return $text;
	}
}

class WebhookContractConfig {
	public function get(string $key) {
		$values = [
			'AMPDBPASS' => 'contract-local-master-secret',
			'FREEPBX_SYSTEM_IDENT' => 'test-pbx',
		];
		return $values[$key] ?? '';
	}
}

class FreePBX {
	public static $database;
	public static function Database() {
		return self::$database;
	}
	public static function Config() {
		return new WebhookContractConfig();
	}
	public static function Log() {
		return new class {
			public function warning($message): void {}
			public function error($message): void {}
			public function info($message): void {}
		};
	}
}

class WebhookQueueSQLite extends PDO {
	public $failQueueSelect = false;
	public $suppressOnDestinationLock = false;
	public $suppressOnRecoveryCheck = false;
	public $suppressionDeliveryId = 0;
	public $onNextDriverAttribute;

	public function prepare($query, $options = null): PDOStatement {
		if ($this->failQueueSelect && strpos((string)$query, 'FROM registrationwatch_webhook_queue') !== false) {
			$this->failQueueSelect = false;
			throw new RuntimeException('Controlled queue selection failure.');
		}
		$query = str_replace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $query);
		$query = str_replace(
			'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)',
			'ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = excluded.updated_at',
			$query
		);
		if ($this->suppressOnRecoveryCheck
			&& strpos((string)$query, 'alert_type IN') !== false
			&& strpos((string)$query, 'SELECT status') !== false) {
			$this->suppressOnRecoveryCheck = false;
			$this->exec(
				"UPDATE registrationwatch_webhook_queue
				SET status = 'suppressed', last_error = 'Destination changed during recovery deferral'
				WHERE id = " . (int)$this->suppressionDeliveryId
			);
		}
		$statement = parent::prepare($query, $options ?? []);
		if ($statement === false) {
			throw new RuntimeException('Unable to prepare webhook queue test SQL.');
		}
		return $statement;
	}

	public function getAttribute($attribute): mixed {
		if ($attribute === PDO::ATTR_DRIVER_NAME && is_callable($this->onNextDriverAttribute)) {
			$callback = $this->onNextDriverAttribute;
			$this->onNextDriverAttribute = null;
			$callback();
		}
		if ($attribute === PDO::ATTR_DRIVER_NAME && $this->suppressOnDestinationLock) {
			$this->suppressOnDestinationLock = false;
			$this->exec(
				"UPDATE registrationwatch_webhook_queue
				SET status = 'suppressed', last_error = 'Destination changed before lock acquisition'
				WHERE id = " . (int)$this->suppressionDeliveryId
			);
			return 'mysql';
		}
		return parent::getAttribute($attribute);
	}
}

function webhook_queue_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$database = new WebhookQueueSQLite('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE registrationwatch_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT)');
$database->exec(
	'CREATE TABLE registrationwatch_registrations (
		id INTEGER PRIMARY KEY,
		registration_key TEXT,
		extension TEXT,
		repeat_mode TEXT,
		enabled INTEGER NOT NULL,
		last_known_status TEXT,
		contact_uri TEXT,
		latency_ms REAL
	)'
);
$database->exec(
	'CREATE TABLE registrationwatch_status_history (
		id INTEGER PRIMARY KEY,
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
		history_id INTEGER,
		alert_type TEXT NOT NULL,
		recipient TEXT NOT NULL,
		reminder_n INTEGER NOT NULL,
		sent_at TEXT NOT NULL,
		result TEXT NOT NULL,
		error TEXT
	)'
);
$database->exec(
	'CREATE TABLE registrationwatch_webhook_queue (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		destination_id TEXT NOT NULL,
		event_id TEXT NOT NULL,
		registration_id INTEGER NOT NULL,
		history_id INTEGER NOT NULL,
		incident_start_history_id INTEGER NOT NULL,
		destination_fingerprint TEXT,
		alert_type TEXT NOT NULL,
		event_type TEXT NOT NULL,
		payload TEXT NOT NULL,
		status TEXT NOT NULL,
		attempts INTEGER NOT NULL DEFAULT 0,
		next_attempt_at TEXT NOT NULL,
		last_attempt_at TEXT,
		http_status INTEGER,
		last_error TEXT,
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL,
		UNIQUE (event_id, destination_id)
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
		updated_at TEXT
	)'
);
FreePBX::$database = $database;

require_once dirname(__DIR__) . '/Registrationwatch.class.php';

$activeDestination = str_repeat('a', 32);
$secondActiveDestination = str_repeat('e', 32);
$disabledDestination = str_repeat('b', 32);
$testHeaderValue = 'Bearer contract-webhook-secret';
$encryptedTestHeader = \FreePBX\modules\RegistrationwatchWebhook::encryptHeaders(
	['Authorization' => $testHeaderValue],
	'contract-local-master-secret'
);
webhook_queue_assert(is_string($encryptedTestHeader), 'The contract authentication header should encrypt.');
$destinations = [
	[
		'id' => $activeDestination,
		'name' => 'Active endpoint',
		'url' => 'https://hooks.example.invalid/events',
		'method' => 'POST',
		'enabled' => 1,
		'allow_private' => 0,
		'payload_template' => '',
		'headers_encrypted' => $encryptedTestHeader,
	],
	[
		'id' => $secondActiveDestination,
		'name' => 'Second active endpoint',
		'url' => 'https://second.example.invalid/events',
		'method' => 'GET',
		'enabled' => 1,
		'allow_private' => 0,
		'payload_template' => '',
		'headers_encrypted' => '',
	],
	[
		'id' => $disabledDestination,
		'name' => 'Disabled endpoint',
		'url' => 'https://disabled.example.invalid/events',
		'method' => 'POST',
		'enabled' => 0,
		'allow_private' => 0,
		'payload_template' => '',
		'headers_encrypted' => '',
	],
];
$fingerprintDestination = static function (array $destination): string {
	return hash('sha256', json_encode([
		'url' => (string)($destination['url'] ?? ''),
		'method' => strtoupper((string)($destination['method'] ?? 'POST')),
		'allow_private' => !empty($destination['allow_private']) ? 1 : 0,
		'enabled' => !empty($destination['enabled']) ? 1 : 0,
		'headers_encrypted' => (string)($destination['headers_encrypted'] ?? ''),
	], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
};
$activeDestinationFingerprint = $fingerprintDestination($destinations[0]);
$secondDestinationFingerprint = $fingerprintDestination($destinations[1]);
$database->exec(
	"CREATE TRIGGER registrationwatch_webhook_test_fingerprint
	AFTER INSERT ON registrationwatch_webhook_queue
	WHEN NEW.destination_fingerprint IS NULL
	BEGIN
		UPDATE registrationwatch_webhook_queue
		SET destination_fingerprint = CASE NEW.destination_id
			WHEN " . $database->quote($activeDestination) . " THEN " . $database->quote($activeDestinationFingerprint) . "
			WHEN " . $database->quote($secondActiveDestination) . " THEN " . $database->quote($secondDestinationFingerprint) . "
			ELSE NULL
		END
		WHERE id = NEW.id;
	END"
);
$settings = [
	'alert_enabled' => '0',
	'alert_recipients' => '',
	'alert_on_unreachable' => '1',
	'alert_on_not_registered' => '1',
	'alert_on_recovery' => '1',
	'debounce_seconds' => '0',
	'storm_threshold' => '20',
	'monitoring_snoozed_until' => '',
	'webhook_destinations' => json_encode($destinations),
];
$settingInsert = $database->prepare('INSERT INTO registrationwatch_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at)');
foreach ($settings as $key => $value) {
	$settingInsert->execute([':key' => $key, ':value' => $value, ':updated_at' => date('Y-m-d H:i:s')]);
}

$nowTimestamp = time();
$problemAt = date('Y-m-d H:i:s', $nowTimestamp - 90);
$recoveryAt = date('Y-m-d H:i:s', $nowTimestamp - 30);
$database->exec(
	"INSERT INTO registrationwatch_registrations
		(id, registration_key, extension, repeat_mode, enabled, last_known_status)
	VALUES
		(1, '" . hash('sha256', '2001') . "', '2001', 'never', 1, 'Unreachable'),
		(2, '" . hash('sha256', '2002') . "', '2002', 'never', 0, 'Unreachable')"
);
$historyInsert = $database->prepare(
	'INSERT INTO registrationwatch_status_history
		(id, registration_id, registration_key, extension, from_state, to_state, source, reason, created_at)
	VALUES (:id, :registration_id, :registration_key, :extension, :from_state, :to_state, :source, :reason, :created_at)'
);
foreach ([
	[101, 1, '2001', 'Reachable', 'Unreachable', $problemAt],
	[102, 1, '2001', 'Unreachable', 'Reachable', $recoveryAt],
	[201, 2, '2002', 'Reachable', 'Unreachable', $problemAt],
] as $row) {
	$historyInsert->execute([
		':id' => $row[0],
		':registration_id' => $row[1],
		':registration_key' => hash('sha256', (string)$row[2]),
		':extension' => $row[2],
		':from_state' => $row[3],
		':to_state' => $row[4],
		':source' => 'reconcile',
		':reason' => 'status_change',
		':created_at' => $row[5],
	]);
}

$module = new \FreePBX\modules\Registrationwatch(new class {
	public function Config() {
		return new WebhookContractConfig();
	}
});
$localOnlyDestinations = $destinations;
$localOnlyDestinations[0]['url'] = 'https://localhost/events';
$database->prepare(
	'INSERT OR REPLACE INTO registrationwatch_settings (setting_key, setting_value, updated_at)
	VALUES (:key, :value, :updated_at)'
)->execute([
	':key' => 'webhook_destinations',
	':value' => json_encode($localOnlyDestinations),
	':updated_at' => date('Y-m-d H:i:s'),
]);
$database->prepare(
	'INSERT OR REPLACE INTO registrationwatch_settings (setting_key, setting_value, updated_at)
	VALUES (:key, :value, :updated_at)'
)->execute([
	':key' => 'topology_poll_interval_seconds',
	':value' => '0',
	':updated_at' => date('Y-m-d H:i:s'),
]);
$workerTestEvents = [
	hash('sha256', 'webhook-worker-isolation-first'),
	hash('sha256', 'webhook-worker-isolation-second'),
];
$workerTestInsert = $database->prepare(
	"INSERT INTO registrationwatch_webhook_queue
		(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
		 status, attempts, next_attempt_at, created_at, updated_at)
	VALUES (:destination_id, :event_id, 1, 900, 900, 'unreachable', 'registration.unreachable', '{}',
		'pending', 0, :now, :now, :now)"
);
foreach ($workerTestEvents as $eventId) {
	$workerTestInsert->execute([
		':destination_id' => $activeDestination,
		':event_id' => $eventId,
		':now' => date('Y-m-d H:i:s', $nowTimestamp),
	]);
}
webhook_queue_assert($module->runBackgroundMonitor(), 'The registration monitor should complete when polling is disabled.');
$workerTestStatuses = $database->prepare(
	'SELECT status FROM registrationwatch_webhook_queue WHERE event_id = :event_id'
);
$workerTestStatuses->execute([':event_id' => $workerTestEvents[0]]);
webhook_queue_assert(
	$workerTestStatuses->fetchColumn() === 'pending',
	'The registration-monitor job must not attempt queued webhook delivery, even when monitoring is disabled.'
);
$module->runWebhookDeliveryWorker();
$workerTestStatuses->execute([':event_id' => $workerTestEvents[0]]);
$firstWorkerStatus = $workerTestStatuses->fetchColumn();
$workerTestStatuses->execute([':event_id' => $workerTestEvents[1]]);
$secondWorkerStatus = $workerTestStatuses->fetchColumn();
webhook_queue_assert(
	$firstWorkerStatus === 'suppressed' && $secondWorkerStatus === 'suppressed',
	'Deliveries bound to an old destination identity must be suppressed before invalid replacement transport is attempted.'
);
$database->prepare(
	'DELETE FROM registrationwatch_webhook_queue WHERE event_id IN (:first, :second)'
)->execute([
	':first' => $workerTestEvents[0],
	':second' => $workerTestEvents[1],
]);
$database->prepare(
	'INSERT OR REPLACE INTO registrationwatch_settings (setting_key, setting_value, updated_at)
	VALUES (:key, :value, :updated_at)'
)->execute([
	':key' => 'webhook_destinations',
	':value' => json_encode($destinations),
	':updated_at' => date('Y-m-d H:i:s'),
]);
$database->prepare(
	'INSERT OR REPLACE INTO registrationwatch_settings (setting_key, setting_value, updated_at)
	VALUES (:key, :value, :updated_at)'
)->execute([
	':key' => 'topology_poll_interval_seconds',
	':value' => '10',
	':updated_at' => date('Y-m-d H:i:s'),
]);
$accessSessionBefore = $_SESSION ?? null;
$accessRequestBefore = $_REQUEST ?? null;
$csrfToken = 'webhook-access-contract-token';
$_SESSION = ['registrationwatch_csrf_token' => $csrfToken];
$makeAccessUser = static function (bool $allowed): object {
	return new class($allowed) {
		private $allowed;
		public $checkedSections = [];

		public function __construct(bool $allowed) {
			$this->allowed = $allowed;
		}

		public function checkSection(string $section): bool {
			$this->checkedSections[] = $section;
			return $section === '*' && $this->allowed;
		}
	};
};
$storedConfigurationBeforeAccessTest = $database->query(
	"SELECT setting_value FROM registrationwatch_settings WHERE setting_key = 'webhook_destinations'"
)->fetchColumn();
foreach (['savewebhook', 'deletewebhook', 'testwebhook'] as $webhookCommand) {
	$_SESSION['AMP_user'] = $makeAccessUser(false);
	$_REQUEST = [
		'command' => $webhookCommand,
		'token' => $csrfToken,
		'id' => str_repeat('f', 32),
	];
	$deniedResponse = $module->ajaxHandler();
	webhook_queue_assert(
		empty($deniedResponse['status'])
			&& strpos((string)$deniedResponse['message'], 'Full FreePBX administrator access') !== false
			&& $_SESSION['AMP_user']->checkedSections === ['*'],
		'Every webhook mutation and test AJAX command must deny non-administrators using the FreePBX full-administrator check.'
	);
}
webhook_queue_assert(
	$database->query("SELECT setting_value FROM registrationwatch_settings WHERE setting_key = 'webhook_destinations'")->fetchColumn()
		=== $storedConfigurationBeforeAccessTest,
	'Denied webhook AJAX commands must not change destination configuration.'
);
$_SESSION['AMP_user'] = new class {
	public function checkSection(string $section): bool {
		throw new RuntimeException('Controlled authorization failure.');
	}
};
$_REQUEST = ['command' => 'testwebhook', 'token' => $csrfToken, 'id' => $activeDestination];
$failedClosedResponse = $module->ajaxHandler();
webhook_queue_assert(
	empty($failedClosedResponse['status'])
		&& strpos((string)$failedClosedResponse['message'], 'Full FreePBX administrator access') !== false,
	'Webhook AJAX requests must fail closed when FreePBX cannot establish administrator access.'
);
unset($_SESSION['AMP_user']);
$_REQUEST = ['command' => 'deletewebhook', 'token' => $csrfToken];
$missingUserResponse = $module->ajaxHandler();
webhook_queue_assert(
	empty($missingUserResponse['status'])
		&& strpos((string)$missingUserResponse['message'], 'Full FreePBX administrator access') !== false,
	'Webhook AJAX requests must deny access when the FreePBX administrator session is missing.'
);
$_SESSION['AMP_user'] = $makeAccessUser(true);
$_REQUEST = [
	'command' => 'deletewebhook',
	'token' => $csrfToken,
	'destination_id' => str_repeat('f', 32),
];
$authorizedResponse = $module->ajaxHandler();
webhook_queue_assert(
	empty($authorizedResponse['status'])
		&& strpos((string)$authorizedResponse['message'], 'no longer exists') !== false
		&& $_SESSION['AMP_user']->checkedSections === ['*'],
	'Full FreePBX administrators must pass the guard and reach the destination operation.'
);
$webhookPageDataMethod = new ReflectionMethod($module, 'getWebhookPageData');
$webhookPageDataMethod->setAccessible(true);
$_SESSION['AMP_user'] = $makeAccessUser(false);
$restrictedPageData = $webhookPageDataMethod->invoke($module);
webhook_queue_assert(
	$restrictedPageData === [
		'webhookAuthorized' => false,
		'webhookDestinations' => [],
		'webhookHistory' => [],
	],
	'Non-administrative page data must not expose webhook destinations or delivery history.'
);
$_SESSION['AMP_user'] = $makeAccessUser(true);
$adminPageData = $webhookPageDataMethod->invoke($module);
webhook_queue_assert(
	$adminPageData['webhookAuthorized'] === true
		&& count($adminPageData['webhookDestinations']) === count($destinations)
		&& is_array($adminPageData['webhookHistory']),
	'Full FreePBX administrators must retain access to webhook settings and delivery history.'
);
if ($accessSessionBefore === null) {
	unset($_SESSION);
} else {
	$_SESSION = $accessSessionBefore;
}
if ($accessRequestBefore === null) {
	unset($_REQUEST);
} else {
	$_REQUEST = $accessRequestBefore;
}
$displayDestinations = new ReflectionMethod($module, 'getWebhookDestinationsForDisplay');
$displayDestinations->setAccessible(true);
$displayRows = $displayDestinations->invoke($module);
$displayJson = json_encode($displayRows);
webhook_queue_assert(
	$displayRows[0]['header_names'] === ['Authorization']
		&& !isset($displayRows[0]['headers'])
		&& !isset($displayRows[0]['headers_encrypted'])
		&& is_string($displayJson)
		&& strpos($displayJson, $testHeaderValue) === false,
	'Destination display should reveal header names but never plaintext or encrypted authentication values.'
);
$processAlerts = new ReflectionMethod($module, 'processAlertQueue');
$processAlerts->setAccessible(true);
$processAlerts->invoke($module, date('Y-m-d H:i:s', $nowTimestamp));

$queued = $database->query('SELECT destination_id, event_id, registration_id, history_id, alert_type, event_type, payload, status FROM registrationwatch_webhook_queue ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
webhook_queue_assert(count($queued) === 4, 'Each enabled destination should independently receive the monitored problem and recovery events.');
webhook_queue_assert((int)$queued[0]['history_id'] === 101 && $queued[0]['event_type'] === 'registration.unreachable', 'A monitored reachable-to-unreachable transition should queue one problem event.');
webhook_queue_assert((int)$queued[2]['history_id'] === 102 && $queued[2]['event_type'] === 'registration.registered', 'A monitored recovery should queue after the problem event.');
webhook_queue_assert($queued[0]['destination_id'] === $activeDestination && $queued[2]['destination_id'] === $activeDestination, 'The first destination should have its own ordered event pair.');
webhook_queue_assert($queued[1]['destination_id'] === $secondActiveDestination && $queued[3]['destination_id'] === $secondActiveDestination, 'A second destination should receive an independent ordered event pair.');
webhook_queue_assert($queued[0]['event_id'] === $queued[1]['event_id'] && $queued[2]['event_id'] === $queued[3]['event_id'], 'Logical event identifiers should be stable across destinations.');
webhook_queue_assert($queued[0]['status'] === 'pending' && $queued[2]['status'] === 'pending', 'New event deliveries should be queued, not sent from the monitoring path.');
$payload = json_decode($queued[0]['payload'], true);
webhook_queue_assert($payload['extension'] === '2001' && $payload['previous_status'] === 'Reachable' && $payload['current_status'] === 'Unreachable', 'The queued JSON payload should contain only the authoritative transition context.');
webhook_queue_assert(strpos($queued[0]['payload'], 'test-pbx') !== false, 'The available PBX identifier should be included as a template variable.');

$processAlerts->invoke($module, date('Y-m-d H:i:s', $nowTimestamp));
webhook_queue_assert((int)$database->query('SELECT COUNT(*) FROM registrationwatch_webhook_queue')->fetchColumn() === 4, 'Repeated monitoring passes must not enqueue duplicate logical transition events.');
$database->prepare(
	"UPDATE registrationwatch_webhook_queue SET status = 'suppressed' WHERE destination_id = :destination_id"
)->execute([':destination_id' => $secondActiveDestination]);

$reminderEventId = hash('sha256', 'registrationwatch:101:unreachable:1');
$database->prepare(
	'INSERT INTO registrationwatch_alert_history (history_id, alert_type, recipient, reminder_n, sent_at, result)
	VALUES (101, :alert_type, :recipient, 1, :sent_at, :result)'
)->execute([
	':alert_type' => 'unreachable',
	':recipient' => 'ops@example.invalid',
	':sent_at' => date('Y-m-d H:i:s', $nowTimestamp),
	':result' => 'pending',
]);
$dispatchCollectedAlerts = new ReflectionMethod($module, 'dispatchCollectedAlerts');
$dispatchCollectedAlerts->setAccessible(true);
$dispatchCollectedAlerts->invoke(
	$module,
	[[
		'history_id' => 101,
		'reminder_n' => 1,
		'alert_type' => 'unreachable',
		'status' => 'Unreachable',
		'recipient' => 'ops@example.invalid',
		'subject' => 'Registration reminder',
		'message' => 'Registration remains unreachable.',
		'source' => 'reminder',
	]],
	[],
	['storm_threshold' => '1'],
	[],
	date('Y-m-d H:i:s', $nowTimestamp)
);
$stormThresholdReminderCount = (int)$database->query(
	'SELECT COUNT(*) FROM registrationwatch_webhook_queue WHERE event_id = ' . $database->quote($reminderEventId)
)->fetchColumn();
webhook_queue_assert(
	$stormThresholdReminderCount === 2,
	'Email storm-threshold batching must not suppress reminder events for enabled webhook destinations.'
);
$database->prepare(
	"UPDATE registrationwatch_webhook_queue SET status = 'suppressed' WHERE event_id = :event_id"
)->execute([':event_id' => $reminderEventId]);

$database->prepare(
	'INSERT INTO registrationwatch_alert_history (history_id, alert_type, recipient, reminder_n, sent_at, result)
	VALUES (101, :alert_type, :recipient, 2, :sent_at, :result)'
)->execute([
	':alert_type' => 'unreachable',
	':recipient' => 'ops@example.invalid',
	':sent_at' => date('Y-m-d H:i:s', $nowTimestamp),
	':result' => 'pending',
]);
$database->exec('ALTER TABLE registrationwatch_status_history RENAME TO registrationwatch_status_history_fixture');
try {
	$dispatchCollectedAlerts->invoke(
		$module,
		[[
			'history_id' => 101,
			'reminder_n' => 2,
			'alert_type' => 'unreachable',
			'status' => 'Unreachable',
			'recipient' => 'ops@example.invalid',
			'subject' => 'Registration reminder',
			'message' => 'Registration remains unreachable.',
			'source' => 'reminder',
		]],
		[],
		['storm_threshold' => '1'],
		[],
		date('Y-m-d H:i:s', $nowTimestamp)
	);
} finally {
	$database->exec('ALTER TABLE registrationwatch_status_history_fixture RENAME TO registrationwatch_status_history');
}
$isolatedEmailDispatch = $database->query(
	"SELECT result FROM registrationwatch_alert_history WHERE history_id = 101 AND reminder_n = 2"
)->fetchColumn();
webhook_queue_assert(
	$isolatedEmailDispatch === 'storm_summary_failed',
	'A webhook reminder lookup failure must not abort the independent email notification path.'
);

$processQueue = new ReflectionMethod($module, 'processWebhookQueue');
$processQueue->setAccessible(true);
$resolver = static function (): array {
	return ['8.8.8.8'];
};
$database->failQueueSelect = true;
$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp));
webhook_queue_assert(!$database->inTransaction(), 'A queue worker error must roll back its database transaction.');
$unavailable = static function (): array {
	return ['http_status' => 503];
};
$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp), $resolver, $unavailable);
$firstDelivery = $database->query(
	'SELECT status, attempts, http_status, next_attempt_at, updated_at
	FROM registrationwatch_webhook_queue
	WHERE history_id = 101'
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$firstDelivery['status'] === 'pending'
		&& (int)$firstDelivery['attempts'] === 1
		&& (int)$firstDelivery['http_status'] === 503
		&& $firstDelivery['updated_at'] === date('Y-m-d H:i:s', $nowTimestamp)
		&& $firstDelivery['next_attempt_at'] === date('Y-m-d H:i:s', $nowTimestamp + 60),
	'A transient HTTP error should record the real update time and a 60-second retry time.'
);
$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp + 59), $resolver, $unavailable, null, 6);
$beforeFirstRetry = $database->query(
	'SELECT attempts FROM registrationwatch_webhook_queue WHERE history_id = 101'
)->fetchColumn();
webhook_queue_assert((int)$beforeFirstRetry === 1, 'A delivery must not retry before the 60-second boundary.');

$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp + 60), $resolver, $unavailable, null, 6);
$secondDelivery = $database->query(
	'SELECT status, attempts, next_attempt_at, updated_at
	FROM registrationwatch_webhook_queue WHERE history_id = 101'
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$secondDelivery['status'] === 'pending'
		&& (int)$secondDelivery['attempts'] === 2
		&& $secondDelivery['updated_at'] === date('Y-m-d H:i:s', $nowTimestamp + 60)
		&& $secondDelivery['next_attempt_at'] === date('Y-m-d H:i:s', $nowTimestamp + 360),
	'A delivery should retry at the 60-second boundary and schedule 300 more seconds.'
);
$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp + 359), $resolver, $unavailable, null, 6);
$beforeSecondRetry = $database->query(
	'SELECT attempts FROM registrationwatch_webhook_queue WHERE history_id = 101'
)->fetchColumn();
webhook_queue_assert((int)$beforeSecondRetry === 2, 'A delivery must not retry before the 300-second boundary.');
$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp + 360), $resolver, $unavailable, null, 6);
$failedDelivery = $database->query(
	'SELECT status, attempts FROM registrationwatch_webhook_queue WHERE history_id = 101'
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$failedDelivery['status'] === 'failed' && (int)$failedDelivery['attempts'] === 3,
	'Transient delivery retries should be bounded to three attempts: ' . json_encode($failedDelivery)
);
$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp + 420), $resolver, $unavailable);
$recoveryDelivery = $database->query('SELECT status FROM registrationwatch_webhook_queue WHERE history_id = 102')->fetchColumn();
webhook_queue_assert($recoveryDelivery === 'suppressed', 'Recovery must be suppressed when the corresponding problem notification was never delivered.');

$database->prepare(
	"INSERT INTO registrationwatch_alert_escalation
		(registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at,
		 alert_count, next_due_at, repeat_mode, created_at, updated_at)
	VALUES (1, :registration_key, '2001', 101, 'unreachable', :active_since, :last_alert_at,
		6, :next_due_at, '5m', :created_at, :updated_at)"
)->execute([
	':registration_key' => hash('sha256', '2001'),
	':active_since' => date('Y-m-d H:i:s', $nowTimestamp - 1800),
	':last_alert_at' => date('Y-m-d H:i:s', $nowTimestamp - 300),
	':next_due_at' => date('Y-m-d H:i:s', $nowTimestamp),
	':created_at' => date('Y-m-d H:i:s', $nowTimestamp - 1800),
	':updated_at' => date('Y-m-d H:i:s', $nowTimestamp - 300),
]);
$collectDueReminders = new ReflectionMethod($module, 'collectDueReminderAlerts');
$collectDueReminders->setAccessible(true);
$emailHistoryBeforeWebhookReminder = (int)$database->query(
	'SELECT COUNT(*) FROM registrationwatch_alert_history'
)->fetchColumn();
$independentReminder = $collectDueReminders->invoke(
	$module,
	date('Y-m-d H:i:s', $nowTimestamp),
	[
		'alert_enabled' => '0',
		'alert_on_unreachable' => '1',
		'alert_on_not_registered' => '1',
	],
	[],
	true,
	static function () use ($nowTimestamp): array {
		return [hash('sha256', '2001') => ['status' => 'Unreachable']];
	}
);
webhook_queue_assert(
	!$independentReminder['alerts']
		&& count($independentReminder['webhook_reminders']) === 1
		&& count($independentReminder['cycles']) === 1,
	'Due webhook reminders must be collected independently while email alerts are disabled.'
);
$dispatchCollectedAlerts->invoke(
	$module,
	[],
	$independentReminder['cycles'],
	['storm_threshold' => '20'],
	[],
	date('Y-m-d H:i:s', $nowTimestamp),
	$independentReminder['webhook_reminders']
);
$independentReminderEventId = hash('sha256', 'registrationwatch:101:unreachable:7');
webhook_queue_assert(
	(int)$database->query(
		'SELECT COUNT(*) FROM registrationwatch_webhook_queue WHERE event_id = '
		. $database->quote($independentReminderEventId)
	)->fetchColumn() === 2
	&& (int)$database->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn()
		=== $emailHistoryBeforeWebhookReminder,
	'Webhook-only repeat notifications must be queued for every enabled destination without generating email history.'
);
$independentReminderPayload = json_decode(
	$database->query(
		'SELECT payload FROM registrationwatch_webhook_queue WHERE event_id = '
		. $database->quote($independentReminderEventId) . ' ORDER BY id ASC LIMIT 1'
	)->fetchColumn(),
	true
);
webhook_queue_assert(
	$independentReminderPayload['previous_status'] === 'Reachable'
		&& $independentReminderPayload['current_status'] === 'Unreachable'
		&& $independentReminderPayload['event_id'] === $independentReminderEventId,
	'Webhook reminder payloads should preserve the original transition and current reminder status.'
);
$database->prepare("UPDATE registrationwatch_webhook_queue SET status = 'suppressed' WHERE event_id = :event_id")
	->execute([':event_id' => $independentReminderEventId]);

$interruptedInsert = $database->prepare(
	"INSERT INTO registrationwatch_webhook_queue
		(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
		 status, attempts, next_attempt_at, last_attempt_at, created_at, updated_at)
	VALUES
		(:destination_id, :event_id, 1, 101, 101, 'unreachable', 'registration.unreachable', '{}',
		 'processing', 3, :created_at, :created_at, :created_at, :updated_at)"
);
$interruptedInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => hash('sha256', 'interrupted-webhook-contract'),
	':created_at' => date('Y-m-d H:i:s', $nowTimestamp - 700),
	':updated_at' => date('Y-m-d H:i:s', $nowTimestamp - 700),
]);
$processQueue->invoke(
	$module,
	date('Y-m-d H:i:s', $nowTimestamp + 1000),
	$resolver,
	static function (): array {
		throw new RuntimeException('An exhausted interrupted delivery must not be sent again.');
	}
);
$interruptedDelivery = $database->query(
	"SELECT status, attempts FROM registrationwatch_webhook_queue WHERE event_id = " . $database->quote(hash('sha256', 'interrupted-webhook-contract'))
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$interruptedDelivery['status'] === 'failed' && (int)$interruptedDelivery['attempts'] === 3,
	'A delivery interrupted after its third claimed attempt must fail without an unbounded extra retry.'
);

$queueEvent = new ReflectionMethod($module, 'queueWebhookEvent');
$queueEvent->setAccessible(true);
$database->exec(
	"WITH RECURSIVE seq(x) AS (
		SELECT 1
		UNION ALL SELECT x + 1 FROM seq WHERE x < 5000
	)
	INSERT INTO registrationwatch_webhook_queue
		(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
		 status, attempts, next_attempt_at, created_at, updated_at)
	SELECT '" . str_repeat('c', 32) . "', printf('%064d', x), 1, 600, 600, 'unreachable', 'registration.unreachable', '{}',
		'pending', 0, datetime('now'), datetime('now'), datetime('now')
	FROM seq"
);
$capacityTransition = [
	'id' => 601,
	'registration_id' => 1,
	'registration_key' => hash('sha256', '2001'),
	'extension' => '2001',
	'from_state' => 'Reachable',
	'to_state' => 'Unreachable',
	'created_at' => date('Y-m-d H:i:s', $nowTimestamp),
];
$queueEvent->invoke($module, $capacityTransition, 'unreachable', 0, 601);
webhook_queue_assert(
	(int)$database->query("SELECT COUNT(*) FROM registrationwatch_webhook_queue WHERE status = 'pending'")->fetchColumn() === 5000,
	'The worker must not enqueue new notifications beyond the 5,000 pending-delivery limit.'
);
$database->exec("DELETE FROM registrationwatch_webhook_queue WHERE destination_id = '" . str_repeat('c', 32) . "'");

$currentQueueCount = (int)$database->query('SELECT COUNT(*) FROM registrationwatch_webhook_queue')->fetchColumn();
$rowsToFillQueue = max(0, 10000 - $currentQueueCount);
if ($rowsToFillQueue > 0) {
	$database->exec(
		"WITH RECURSIVE seq(x) AS (
			SELECT 1
			UNION ALL SELECT x + 1 FROM seq WHERE x < " . $rowsToFillQueue . "
		)
		INSERT INTO registrationwatch_webhook_queue
			(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
			 status, attempts, next_attempt_at, created_at, updated_at)
		SELECT '" . str_repeat('d', 32) . "', printf('%064d', x), 1, 700, 700, 'unreachable', 'registration.unreachable', '{}',
			'delivered', 1, datetime('now'), datetime('now'), datetime('now')
		FROM seq"
	);
}
$maintenanceTransition = $capacityTransition;
$maintenanceTransition['id'] = 701;
$queueEvent->invoke($module, $maintenanceTransition, 'unreachable', 0, 701);
$boundedQueueCount = (int)$database->query('SELECT COUNT(*) FROM registrationwatch_webhook_queue')->fetchColumn();
webhook_queue_assert(
	$boundedQueueCount <= 10000 && $boundedQueueCount === 9002,
	'The queue must prune completed rows before accepting new events for each enabled destination at the 10,000-row cap.'
);

$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed' WHERE status IN ('pending', 'processing')");
$preSnoozeInsert = $database->prepare(
	"INSERT INTO registrationwatch_webhook_queue
		(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
		 status, attempts, next_attempt_at, created_at, updated_at)
	VALUES
		(:destination_id, :event_id, 1, 801, 801, 'unreachable', 'registration.unreachable', '{}',
		 'pending', 0, :now, :now, :now)"
);
$preSnoozeInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => hash('sha256', 'webhook-queued-before-snooze'),
	':now' => date('Y-m-d H:i:s', $nowTimestamp),
]);

$snoozeUntil = date('Y-m-d H:i:s', $nowTimestamp + 3600);
$database->prepare("UPDATE registrationwatch_settings SET setting_value = :value WHERE setting_key = 'monitoring_snoozed_until'")
	->execute([':value' => $snoozeUntil]);
$snoozedTransition = [
	'id' => 301,
	'registration_id' => 1,
	'registration_key' => hash('sha256', '2001'),
	'extension' => '2001',
	'from_state' => 'Reachable',
	'to_state' => 'Unreachable',
	'created_at' => date('Y-m-d H:i:s', $nowTimestamp),
];
$queueEvent->invoke($module, $snoozedTransition, 'unreachable', 0, 301);
$snoozedResult = $database->query('SELECT status FROM registrationwatch_webhook_queue WHERE history_id = 301')->fetchColumn();
webhook_queue_assert($snoozedResult === 'suppressed', 'Monitoring snooze must prevent queued webhook delivery.');
$snoozedTransportCalls = 0;
$processQueue->invoke(
	$module,
	date('Y-m-d H:i:s', $nowTimestamp),
	$resolver,
	static function () use (&$snoozedTransportCalls): array {
		$snoozedTransportCalls++;
		return ['http_status' => 200];
	}
);
$preSnoozeDelivery = $database->query(
	"SELECT status, attempts FROM registrationwatch_webhook_queue WHERE event_id = " . $database->quote(hash('sha256', 'webhook-queued-before-snooze'))
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$preSnoozeDelivery['status'] === 'suppressed' && (int)$preSnoozeDelivery['attempts'] === 0 && $snoozedTransportCalls === 0,
	'Deliveries queued before a snooze must be suppressed without making a network request or consuming an attempt.'
);

$saveDestinations = new ReflectionMethod($module, 'saveWebhookDestinations');
$saveDestinations->setAccessible(true);
$maximumHeaders = [];
for ($headerIndex = 0; $headerIndex < \FreePBX\modules\RegistrationwatchWebhook::MAX_HEADERS; $headerIndex++) {
	$headerName = str_pad('X-' . $headerIndex, 128, 'A');
	$maximumHeaders[$headerName] = str_repeat(chr(92), 2048);
}
$validatedMaximumHeaders = \FreePBX\modules\RegistrationwatchWebhook::validateHeaders($maximumHeaders);
webhook_queue_assert($validatedMaximumHeaders !== null, 'Maximum per-destination headers should pass validation.');
$maximumHeaderCiphertext = \FreePBX\modules\RegistrationwatchWebhook::encryptHeaders(
	$validatedMaximumHeaders,
	'contract-local-master-secret'
);
$maximumUrlBase = 'https://hooks.example.invalid/';
$maximumUrl = $maximumUrlBase . str_repeat('p', 2048 - strlen($maximumUrlBase));
$maximumTemplate = '{"x":"' . str_repeat('x', 8184) . '"}';
webhook_queue_assert(
	is_string($maximumHeaderCiphertext)
		&& strlen($maximumUrl) === 2048
		&& \FreePBX\modules\RegistrationwatchWebhook::validateDestinationUrl($maximumUrl, false) !== null
		&& strlen($maximumTemplate) === \FreePBX\modules\RegistrationwatchWebhook::MAX_PAYLOAD_BYTES
		&& \FreePBX\modules\RegistrationwatchWebhook::renderPayload($maximumTemplate, []) !== null,
	'Maximum per-destination field values should pass their application validators.'
);
$maximumConfiguration = [];
for ($destinationIndex = 0; $destinationIndex < \FreePBX\modules\RegistrationwatchWebhook::MAX_DESTINATIONS; $destinationIndex++) {
	$maximumConfiguration[] = [
		'id' => str_pad(dechex($destinationIndex), 32, '0', STR_PAD_LEFT),
		'name' => str_repeat('N', 80),
		'url' => $maximumUrl,
		'enabled' => 1,
		'method' => 'POST',
		'allow_private' => 0,
		'payload_template' => $maximumTemplate,
		'headers_encrypted' => $maximumHeaderCiphertext,
	];
}
$maximumConfigurationError = $saveDestinations->invoke($module, $maximumConfiguration);
$storedMaximumConfiguration = $database->query(
	"SELECT setting_value FROM registrationwatch_settings WHERE setting_key = 'webhook_destinations'"
)->fetchColumn();
webhook_queue_assert(
	$maximumConfigurationError === null
		&& is_string($storedMaximumConfiguration)
		&& strlen($storedMaximumConfiguration) <= \FreePBX\modules\RegistrationwatchWebhook::MAX_DESTINATION_CONFIG_BYTES,
	'The maximum valid destination set should fit the application and database storage limits.'
);
$restoreConfigurationError = $saveDestinations->invoke($module, $destinations);
webhook_queue_assert($restoreConfigurationError === null, 'The fixture should restore its original webhook configuration.');
$storedDestinationsBeforeOversize = $database->query(
	"SELECT setting_value FROM registrationwatch_settings WHERE setting_key = 'webhook_destinations'"
)->fetchColumn();
$oversizedConfiguration = [[
	'id' => str_repeat('f', 32),
	'name' => str_repeat('x', \FreePBX\modules\RegistrationwatchWebhook::MAX_DESTINATION_CONFIG_BYTES + 1),
	'url' => 'https://hooks.example.invalid/event',
]];
$oversizeError = $saveDestinations->invoke($module, $oversizedConfiguration);
$storedDestinationsAfterOversize = $database->query(
	"SELECT setting_value FROM registrationwatch_settings WHERE setting_key = 'webhook_destinations'"
)->fetchColumn();
webhook_queue_assert(
	is_string($oversizeError) && strpos($oversizeError, 'storage limit') !== false
		&& $storedDestinationsAfterOversize === $storedDestinationsBeforeOversize,
	'Oversized serialized webhook configuration must be rejected explicitly without replacing stored settings.'
);

$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed' WHERE status IN ('pending', 'processing')");
$database->prepare("UPDATE registrationwatch_settings SET setting_value = '' WHERE setting_key = 'monitoring_snoozed_until'")
	->execute();
$slowEventIds = [
	hash('sha256', 'webhook-slow-worker-first'),
	hash('sha256', 'webhook-slow-worker-second'),
];
$slowDeliveryInsert = $database->prepare(
	"INSERT INTO registrationwatch_webhook_queue
		(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
		 status, attempts, next_attempt_at, created_at, updated_at)
	VALUES (:destination_id, :event_id, 1, 990, 990, 'unreachable', 'registration.unreachable', '{}',
		'pending', 0, :now, :now, :now)"
);
$slowDeliveryNow = date('Y-m-d H:i:s');
foreach ($slowEventIds as $eventId) {
	$slowDeliveryInsert->execute([
		':destination_id' => $activeDestination,
		':event_id' => $eventId,
		':now' => $slowDeliveryNow,
	]);
}
$slowTransportCalls = 0;
$slowStartedAt = hrtime(true);
$processQueue->invoke(
	$module,
	$slowDeliveryNow,
	$resolver,
	static function () use (&$slowTransportCalls): array {
		$slowTransportCalls++;
		usleep(150000);
		return ['http_status' => 200];
	}
);
$slowDeliveryElapsed = (hrtime(true) - $slowStartedAt) / 1000000000;
$slowStatuses = $database->prepare(
	'SELECT status FROM registrationwatch_webhook_queue WHERE event_id = :event_id'
);
$slowStatuses->execute([':event_id' => $slowEventIds[0]]);
$firstSlowStatus = $slowStatuses->fetchColumn();
$slowStatuses->execute([':event_id' => $slowEventIds[1]]);
$secondSlowStatus = $slowStatuses->fetchColumn();
webhook_queue_assert(
	$slowTransportCalls === 1 && $slowDeliveryElapsed >= 0.1
		&& $firstSlowStatus === 'delivered' && $secondSlowStatus === 'pending',
	'A slow endpoint must consume only one bounded worker attempt; backlog entries remain for later worker runs.'
);
$database->prepare('DELETE FROM registrationwatch_webhook_queue WHERE event_id IN (:first, :second)')
	->execute([':first' => $slowEventIds[0], ':second' => $slowEventIds[1]]);

$batchEventPrefix = hash('sha256', 'webhook-batch-limit');
$batchInsert = $database->prepare(
	"INSERT INTO registrationwatch_webhook_queue
		(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
		 status, attempts, next_attempt_at, created_at, updated_at)
	VALUES (:destination_id, :event_id, 1, :history_id, :history_id, 'unreachable',
		'registration.unreachable', '{}', 'pending', 0, :now, :now, :now)"
);
for ($index = 0; $index < 8; $index++) {
	$batchInsert->execute([
		':destination_id' => $activeDestination,
		':event_id' => hash('sha256', $batchEventPrefix . ':' . $index),
		':history_id' => 1001 + $index,
		':now' => $slowDeliveryNow,
	]);
}
$batchTransportCalls = 0;
$processQueue->invoke(
	$module,
	$slowDeliveryNow,
	$resolver,
	static function () use (&$batchTransportCalls): array {
		$batchTransportCalls++;
		return [
			'http_status' => $batchTransportCalls === 2 ? 503 : ($batchTransportCalls === 3 ? 400 : 200),
		];
	},
	static function (): float {
		return 0.0;
	},
	6
);
webhook_queue_assert(
	$batchTransportCalls === 6,
	'The worker must enforce its six-delivery batch limit without exceeding the configured test batch.'
);
$batchDelivered = 0;
$batchPending = 0;
$batchFailed = 0;
for ($index = 0; $index < 8; $index++) {
	$batchStatus = $database->query(
		'SELECT status FROM registrationwatch_webhook_queue WHERE event_id = '
		. $database->quote(hash('sha256', $batchEventPrefix . ':' . $index))
	)->fetchColumn();
	$batchDelivered += $batchStatus === 'delivered' ? 1 : 0;
	$batchPending += $batchStatus === 'pending' ? 1 : 0;
	$batchFailed += $batchStatus === 'failed' ? 1 : 0;
}
webhook_queue_assert(
	$batchDelivered === 4 && $batchPending === 3 && $batchFailed === 1,
	'A partial-failure batch must record each outcome, retain one retry, and continue leaving backlog for later runs.'
);
$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed'
	WHERE history_id BETWEEN 1001 AND 1008");

$budgetEvents = [];
for ($index = 0; $index < 2; $index++) {
	$eventId = hash('sha256', 'webhook-budget:' . $index);
	$budgetEvents[] = $eventId;
	$batchInsert->execute([
		':destination_id' => $activeDestination,
		':event_id' => $eventId,
		':history_id' => 1101 + $index,
		':now' => $slowDeliveryNow,
	]);
}
$budgetClockCalls = 0;
$budgetTransportCalls = 0;
$processQueue->invoke(
	$module,
	$slowDeliveryNow,
	$resolver,
	static function () use (&$budgetTransportCalls): array {
		$budgetTransportCalls++;
		return ['http_status' => 200];
	},
	static function () use (&$budgetClockCalls): float {
		$budgetClockCalls++;
		return $budgetClockCalls <= 2 ? 0.0 : 13.0;
	},
	6
);
webhook_queue_assert(
	$budgetTransportCalls === 1,
	'The worker must stop before another claim when its total execution-time budget is exhausted.'
);
$budgetStatuses = [];
foreach ($budgetEvents as $eventId) {
	$stmt = $database->prepare('SELECT status FROM registrationwatch_webhook_queue WHERE event_id = :event_id');
	$stmt->execute([':event_id' => $eventId]);
	$budgetStatuses[] = $stmt->fetchColumn();
}
webhook_queue_assert(
	$budgetStatuses === ['delivered', 'pending'],
	'Execution-budget termination must leave unprocessed deliveries pending for the next worker run.'
);

$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed'
	WHERE status IN ('pending', 'processing')");
$timingEvent = hash('sha256', 'webhook-completion-retry-time');
$timingNow = date('Y-m-d H:i:s', $nowTimestamp + 1000);
$destinationPendingInsert = $database->prepare(
	"INSERT INTO registrationwatch_webhook_queue
		(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
		 status, attempts, next_attempt_at, created_at, updated_at)
	VALUES (:destination_id, :event_id, 1, 1250, 1250, 'unreachable',
		'registration.unreachable', '{}', 'pending', 0, :now, :now, :now)"
);
$destinationPendingInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $timingEvent,
	':now' => $timingNow,
]);
$timingElapsed = 0.0;
$timingTransportCalls = 0;
$processQueue->invoke(
	$module,
	$timingNow,
	$resolver,
	static function () use (&$timingElapsed, &$timingTransportCalls): array {
		$timingTransportCalls++;
		$timingElapsed = 7.0;
		return ['http_status' => 503];
	},
	static function () use (&$timingElapsed): float {
		return $timingElapsed;
	},
	1
);
$timingState = $database->query(
	'SELECT status, attempts, updated_at, next_attempt_at FROM registrationwatch_webhook_queue
	WHERE event_id = ' . $database->quote($timingEvent)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$timingTransportCalls === 1
		&& $timingState['status'] === 'pending'
		&& (int)$timingState['attempts'] === 1
		&& $timingState['updated_at'] === date('Y-m-d H:i:s', strtotime($timingNow) + 7)
		&& $timingState['next_attempt_at'] === date('Y-m-d H:i:s', strtotime($timingNow) + 67),
	'A retry must be scheduled 60 seconds after the actual failed delivery completes.'
);
$processQueue->invoke(
	$module,
	date('Y-m-d H:i:s', strtotime($timingNow) + 66),
	$resolver,
	$unavailable,
	static function (): float {
		return 0.0;
	},
	1
);
$timingBeforeBoundary = (int)$database->query(
	'SELECT attempts FROM registrationwatch_webhook_queue WHERE event_id = '
	. $database->quote($timingEvent)
)->fetchColumn();
webhook_queue_assert(
	$timingBeforeBoundary === 1,
	'A slow failed request must not become retryable prematurely before completion plus 60 seconds.'
);

$destinationChangeEvent = hash('sha256', 'webhook-destination-name-only');
$destinationPendingInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $destinationChangeEvent,
	':now' => $slowDeliveryNow,
]);
$destinationToken = 'destination-change-contract-token';
$_SESSION = [
	'registrationwatch_csrf_token' => $destinationToken,
	'AMP_user' => new class {
		public function checkSection(string $section): bool {
			return $section === '*';
		}
	},
];
$_REQUEST = [
	'command' => 'savewebhook',
	'token' => $destinationToken,
	'destination_id' => $activeDestination,
	'name' => 'Renamed active endpoint',
	'url' => 'https://hooks.example.invalid/events',
	'method' => 'POST',
	'enabled' => '1',
	'allow_private' => '0',
	'payload_template' => '',
	'custom_headers_json' => '',
];
$renamedResponse = $module->ajaxHandler();
webhook_queue_assert(
	$renamedResponse['status'] === true
		&& $database->query(
			'SELECT status FROM registrationwatch_webhook_queue WHERE event_id = '
			. $database->quote($destinationChangeEvent)
		)->fetchColumn() === 'pending',
	'An unrelated destination-name change must preserve queued deliveries.'
);
$_REQUEST['url'] = 'https://replacement.example.invalid/events';
$reroutedResponse = $module->ajaxHandler();
$reroutedDelivery = $database->query(
	"SELECT status, last_error FROM registrationwatch_webhook_queue WHERE event_id = "
	. $database->quote($destinationChangeEvent)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$reroutedResponse['status'] === true
		&& $reroutedDelivery['status'] === 'suppressed'
		&& strpos((string)$reroutedDelivery['last_error'], 'configuration changed') !== false,
	'A material destination change must suppress pending deliveries before they can reach the new endpoint.'
);
unset($_SESSION, $_REQUEST);

$producerRaceDestinations = $destinations;
$producerRaceDestinations[0]['url'] = 'https://old-producer.example.invalid/events';
$database->prepare(
	"UPDATE registrationwatch_settings
	SET setting_value = :value
	WHERE setting_key = 'webhook_destinations'"
)->execute([':value' => json_encode($producerRaceDestinations)]);
$producerRaceTransition = [
	'id' => 1251,
	'registration_id' => 1,
	'registration_key' => hash('sha256', '2001'),
	'extension' => '2001',
	'from_state' => 'Reachable',
	'to_state' => 'Unreachable',
	'created_at' => date('Y-m-d H:i:s', $nowTimestamp),
];
$producerRaceEventId = hash('sha256', 'registrationwatch:1251:unreachable:0');
$queueEvent->invoke(
	$module,
	$producerRaceTransition,
	'unreachable',
	0,
	1251,
	static function () use ($database, $activeDestination, $producerRaceDestinations): void {
		$replacement = $producerRaceDestinations;
		$replacement[0]['url'] = 'https://replacement-producer.example.invalid/events';
		$database->prepare(
			"UPDATE registrationwatch_settings
			SET setting_value = :value
			WHERE setting_key = 'webhook_destinations'"
		)->execute([':value' => json_encode($replacement)]);
		$database->prepare(
			"UPDATE registrationwatch_webhook_queue
			SET status = 'suppressed', last_error = 'Destination changed during producer interleave'
			WHERE destination_id = :destination_id
				AND status IN ('pending', 'processing')"
		)->execute([':destination_id' => $activeDestination]);
	}
);
$producerRaceRow = $database->query(
	'SELECT id, destination_fingerprint, status FROM registrationwatch_webhook_queue WHERE destination_id = '
	. $database->quote($activeDestination)
	. ' AND event_id = ' . $database->quote($producerRaceEventId)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$producerRaceRow['status'] === 'pending'
		&& strlen((string)$producerRaceRow['destination_fingerprint']) === 64,
	'A producer must bind a queued row to the configuration it read before a deterministic edit interleave.'
);
$database->prepare(
	"UPDATE registrationwatch_webhook_queue
	SET status = 'suppressed'
	WHERE event_id <> :event_id
		AND status IN ('pending', 'processing')"
)->execute([':event_id' => $producerRaceEventId]);
$producerRaceCalls = [];
$producerProcessNow = date('Y-m-d H:i:s');
$processQueue->invoke(
	$module,
	$producerProcessNow,
	$resolver,
	static function ($url) use (&$producerRaceCalls): array {
		$producerRaceCalls[] = $url;
		return ['http_status' => 200];
	},
	null,
	6
);
$producerRaceState = $database->query(
	'SELECT status, last_error FROM registrationwatch_webhook_queue WHERE event_id = '
	. $database->quote($producerRaceEventId)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$producerRaceState['status'] === 'suppressed'
		&& strpos((string)$producerRaceState['last_error'], 'configuration changed') !== false
		&& !array_filter($producerRaceCalls, static function (string $url): bool {
			return strpos($url, 'replacement-producer.example.invalid') !== false;
		}),
	'A row queued under the old destination must be suppressed before reaching the replacement URL.'
);

$unchangedTransition = $producerRaceTransition;
$unchangedTransition['id'] = 1252;
$unchangedEventId = hash('sha256', 'registrationwatch:1252:unreachable:0');
$queueEvent->invoke($module, $unchangedTransition, 'unreachable', 0, 1252);
$unchangedCalls = 0;
$processQueue->invoke(
	$module,
	date('Y-m-d H:i:s'),
	$resolver,
	static function () use (&$unchangedCalls): array {
		$unchangedCalls++;
		return ['http_status' => 200];
	},
	null,
	6
);
webhook_queue_assert(
	$unchangedCalls >= 1
		&& $database->query(
			'SELECT status FROM registrationwatch_webhook_queue WHERE destination_id = '
			. $database->quote($activeDestination)
			. ' AND event_id = ' . $database->quote($unchangedEventId)
		)->fetchColumn() === 'delivered',
	'Normal delivery must remain available when the destination identity is unchanged.'
);

$serializedDestinations = json_decode(
	$database->query(
		"SELECT setting_value FROM registrationwatch_settings WHERE setting_key = 'webhook_destinations'"
	)->fetchColumn(),
	true
);
foreach ($serializedDestinations as &$serializedDestination) {
	if ((string)$serializedDestination['id'] === $secondActiveDestination) {
		$serializedDestination['name'] = 'Second endpoint original name';
	}
}
unset($serializedDestination);
$database->prepare(
	"UPDATE registrationwatch_settings SET setting_value = :value WHERE setting_key = 'webhook_destinations'"
)->execute([':value' => json_encode($serializedDestinations)]);
$serializedToken = 'serialized-config-contract-token';
$_SESSION = [
	'registrationwatch_csrf_token' => $serializedToken,
	'AMP_user' => new class {
		public function checkSection(string $section): bool {
			return $section === '*';
		}
	},
];
$_REQUEST = [
	'command' => 'savewebhook',
	'token' => $serializedToken,
	'destination_id' => $activeDestination,
	'name' => 'Active endpoint serialized update',
	'url' => 'https://replacement.example.invalid/events',
	'method' => 'POST',
	'enabled' => '1',
	'allow_private' => '0',
	'payload_template' => '',
	'custom_headers_json' => '',
];
$database->onNextDriverAttribute = static function () use ($database, $secondActiveDestination): void {
	$current = json_decode(
		$database->query(
			"SELECT setting_value FROM registrationwatch_settings WHERE setting_key = 'webhook_destinations'"
		)->fetchColumn(),
		true
	);
	foreach ($current as &$destination) {
		if ((string)$destination['id'] === $secondActiveDestination) {
			$destination['name'] = 'Concurrent second endpoint update';
		}
	}
	unset($destination);
	$database->prepare(
		"UPDATE registrationwatch_settings SET setting_value = :value WHERE setting_key = 'webhook_destinations'"
	)->execute([':value' => json_encode($current)]);
};
$serializedResponse = $module->ajaxHandler();
$serializedSaved = json_decode(
	$database->query(
		"SELECT setting_value FROM registrationwatch_settings WHERE setting_key = 'webhook_destinations'"
	)->fetchColumn(),
	true
);
$serializedNames = [];
foreach ($serializedSaved as $destination) {
	$serializedNames[(string)$destination['id']] = (string)$destination['name'];
}
webhook_queue_assert(
	$serializedResponse['status'] === true
		&& $serializedNames[$activeDestination] === 'Active endpoint serialized update'
		&& $serializedNames[$secondActiveDestination] === 'Concurrent second endpoint update',
	'An edit interleaved at the configuration lock must reread and preserve another destination update.'
);
unset($_SESSION, $_REQUEST);

$database->exec("UPDATE registrationwatch_webhook_queue
	SET status = 'suppressed' WHERE status IN ('pending', 'processing')");
$claimEvent = hash('sha256', 'webhook-conditional-claim');
$destinationPendingInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $claimEvent,
	':now' => $slowDeliveryNow,
]);
$claimMethod = new ReflectionMethod($module, 'claimWebhookDelivery');
$claimMethod->setAccessible(true);
$firstClaim = $claimMethod->invoke($module, $slowDeliveryNow);
$secondClaim = $claimMethod->invoke($module, $slowDeliveryNow);
webhook_queue_assert(
	is_array($firstClaim) && (int)$firstClaim['id'] > 0
		&& (string)$firstClaim['event_id'] === $claimEvent
		&& $secondClaim === null,
	'Conditional queue claiming must prevent another worker from claiming the same processing row.'
);

$processClaimed = new ReflectionMethod($module, 'processClaimedWebhookDelivery');
$processClaimed->setAccessible(true);
$raceNow = date('Y-m-d H:i:s', $nowTimestamp + 2000);
$raceInsert = $database->prepare(
	"INSERT INTO registrationwatch_webhook_queue
		(destination_id, event_id, registration_id, history_id, incident_start_history_id, alert_type, event_type, payload,
		 status, attempts, next_attempt_at, created_at, updated_at)
	VALUES (:destination_id, :event_id, 1, :history_id, :incident_start, :alert_type, :event_type, '{}',
		'pending', 0, :due_at, :created_at, :updated_at)"
);

$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed'
	WHERE status IN ('pending', 'processing')");
$lockRaceEvent = hash('sha256', 'webhook-suppressed-during-lock-failure');
$raceInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $lockRaceEvent,
	':history_id' => 1301,
	':incident_start' => 1301,
	':alert_type' => 'unreachable',
	':event_type' => 'registration.unreachable',
	':due_at' => $raceNow,
	':created_at' => $raceNow,
	':updated_at' => $raceNow,
]);
$lockRaceClaim = $claimMethod->invoke($module, $raceNow);
$database->suppressionDeliveryId = (int)$lockRaceClaim['id'];
$database->suppressOnDestinationLock = true;
$lockRaceTransportCalls = 0;
$processClaimed->invoke(
	$module,
	$lockRaceClaim,
	$raceNow,
	$resolver,
	static function () use (&$lockRaceTransportCalls): array {
		$lockRaceTransportCalls++;
		return ['http_status' => 200];
	}
);
$lockRaceState = $database->query(
	'SELECT status, last_error FROM registrationwatch_webhook_queue WHERE event_id = '
	. $database->quote($lockRaceEvent)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$lockRaceState['status'] === 'suppressed'
		&& strpos((string)$lockRaceState['last_error'], 'before lock acquisition') !== false
		&& $lockRaceTransportCalls === 0,
	'Administrative suppression during destination-lock failure must survive stale deferral and prevent HTTP.'
);

$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed'
	WHERE status IN ('pending', 'processing')");
$problemEvent = hash('sha256', 'webhook-recovery-problem-pending');
$recoveryEvent = hash('sha256', 'webhook-recovery-suppression-race');
$raceInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $problemEvent,
	':history_id' => 1401,
	':incident_start' => 1401,
	':alert_type' => 'unreachable',
	':event_type' => 'registration.unreachable',
	':due_at' => date('Y-m-d H:i:s', strtotime($raceNow) + 300),
	':created_at' => $raceNow,
	':updated_at' => $raceNow,
]);
$raceInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $recoveryEvent,
	':history_id' => 1402,
	':incident_start' => 1401,
	':alert_type' => 'recovery',
	':event_type' => 'registration.registered',
	':due_at' => $raceNow,
	':created_at' => $raceNow,
	':updated_at' => $raceNow,
]);
$recoveryClaim = $claimMethod->invoke($module, $raceNow);
$database->suppressionDeliveryId = (int)$recoveryClaim['id'];
$database->suppressOnRecoveryCheck = true;
$recoveryRaceTransportCalls = 0;
$processClaimed->invoke(
	$module,
	$recoveryClaim,
	$raceNow,
	$resolver,
	static function () use (&$recoveryRaceTransportCalls): array {
		$recoveryRaceTransportCalls++;
		return ['http_status' => 200];
	}
);
$recoveryRaceState = $database->query(
	'SELECT status, last_error FROM registrationwatch_webhook_queue WHERE event_id = '
	. $database->quote($recoveryEvent)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$recoveryRaceState['status'] === 'suppressed'
		&& strpos((string)$recoveryRaceState['last_error'], 'recovery deferral') !== false
		&& $recoveryRaceTransportCalls === 0,
	'Administrative suppression during recovery deferral must not be overwritten with pending.'
);

$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed'
	WHERE status IN ('pending', 'processing')");
$normalProblemEvent = hash('sha256', 'webhook-normal-recovery-problem-pending');
$normalRecoveryEvent = hash('sha256', 'webhook-normal-recovery-deferral');
$raceInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $normalProblemEvent,
	':history_id' => 1501,
	':incident_start' => 1501,
	':alert_type' => 'unreachable',
	':event_type' => 'registration.unreachable',
	':due_at' => date('Y-m-d H:i:s', strtotime($raceNow) + 300),
	':created_at' => $raceNow,
	':updated_at' => $raceNow,
]);
$raceInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $normalRecoveryEvent,
	':history_id' => 1502,
	':incident_start' => 1501,
	':alert_type' => 'recovery',
	':event_type' => 'registration.registered',
	':due_at' => $raceNow,
	':created_at' => $raceNow,
	':updated_at' => $raceNow,
]);
$normalRecoveryClaim = $claimMethod->invoke($module, $raceNow);
$processClaimed->invoke(
	$module,
	$normalRecoveryClaim,
	$raceNow,
	$resolver,
	static function (): array {
		throw new RuntimeException('A deferred recovery must not reach HTTP.');
	}
);
$normalRecoveryState = $database->query(
	'SELECT status, attempts, next_attempt_at FROM registrationwatch_webhook_queue WHERE event_id = '
	. $database->quote($normalRecoveryEvent)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$normalRecoveryState['status'] === 'pending'
		&& (int)$normalRecoveryState['attempts'] === 0
		&& $normalRecoveryState['next_attempt_at'] === date('Y-m-d H:i:s', strtotime($raceNow) + 60),
	'Recovery deferral must continue normally when the claim is still processing.'
);

$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed'
	WHERE status IN ('pending', 'processing')");
$originalDestinationConfiguration = json_encode($destinations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$database->prepare(
	"UPDATE registrationwatch_settings SET setting_value = :value
	WHERE setting_key = 'webhook_destinations'"
)->execute([':value' => $originalDestinationConfiguration]);
$exceptionRaceEvent = hash('sha256', 'webhook-suppressed-during-exception');
$raceInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $exceptionRaceEvent,
	':history_id' => 1601,
	':incident_start' => 1601,
	':alert_type' => 'unreachable',
	':event_type' => 'registration.unreachable',
	':due_at' => $raceNow,
	':created_at' => $raceNow,
	':updated_at' => $raceNow,
]);
$exceptionClaim = $claimMethod->invoke($module, $raceNow);
$exceptionRaceUrls = [];
$processClaimed->invoke(
	$module,
	$exceptionClaim,
	$raceNow,
	$resolver,
	static function ($url) use (&$exceptionRaceUrls, $database, $exceptionRaceEvent): array {
		$exceptionRaceUrls[] = $url;
		$stmt = $database->prepare(
			"UPDATE registrationwatch_webhook_queue
			SET status = 'suppressed', last_error = 'Destination changed during request'
			WHERE event_id = :event_id"
		);
		$stmt->execute([':event_id' => $exceptionRaceEvent]);
		throw new RuntimeException('Controlled post-request exception.');
	}
);
$exceptionRaceState = $database->query(
	'SELECT status, last_error FROM registrationwatch_webhook_queue WHERE event_id = '
	. $database->quote($exceptionRaceEvent)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$exceptionRaceState['status'] === 'suppressed'
		&& strpos((string)$exceptionRaceState['last_error'], 'during request') !== false
		&& count($exceptionRaceUrls) === 1
		&& strpos($exceptionRaceUrls[0], 'hooks.example.invalid') !== false
		&& strpos($exceptionRaceUrls[0], 'replacement.example.invalid') === false,
	'Exception recovery must not resurrect a suppressed claim or redirect it to a replacement URL.'
);

$updateDelivery = new ReflectionMethod($module, 'updateWebhookDelivery');
$updateDelivery->setAccessible(true);
$database->exec("UPDATE registrationwatch_webhook_queue SET status = 'suppressed'
	WHERE status IN ('pending', 'processing')");
$generationRaceEvent = hash('sha256', 'webhook-processing-generation-race');
$raceInsert->execute([
	':destination_id' => $activeDestination,
	':event_id' => $generationRaceEvent,
	':history_id' => 1701,
	':incident_start' => 1701,
	':alert_type' => 'unreachable',
	':event_type' => 'registration.unreachable',
	':due_at' => $raceNow,
	':created_at' => $raceNow,
	':updated_at' => $raceNow,
]);
$database->prepare(
	"UPDATE registrationwatch_webhook_queue
	SET status = 'processing', attempts = 3, updated_at = :new_claim
	WHERE event_id = :event_id"
)->execute([
	':new_claim' => date('Y-m-d H:i:s', strtotime($raceNow) + 20),
	':event_id' => $generationRaceEvent,
]);
$staleGenerationUpdated = $updateDelivery->invoke(
	$module,
	(int)$database->query(
		'SELECT id FROM registrationwatch_webhook_queue WHERE event_id = '
		. $database->quote($generationRaceEvent)
	)->fetchColumn(),
	'pending',
	503,
	'Stale generation completion.',
	date('Y-m-d H:i:s', strtotime($raceNow) + 20),
	3,
	date('Y-m-d H:i:s', strtotime($raceNow) + 320),
	3,
	$raceNow
);
$generationRaceState = $database->query(
	'SELECT status, attempts, updated_at FROM registrationwatch_webhook_queue WHERE event_id = '
	. $database->quote($generationRaceEvent)
)->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert(
	$staleGenerationUpdated === false
		&& $generationRaceState['status'] === 'processing'
		&& (int)$generationRaceState['attempts'] === 3
		&& $generationRaceState['updated_at'] === date('Y-m-d H:i:s', strtotime($raceNow) + 20),
	'A late worker from an earlier processing lease must not overwrite a newer claim with the same attempt count.'
);

echo "Webhook queue contract passed.\n";
