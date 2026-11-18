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
		$statement = parent::prepare($query, $options ?? []);
		if ($statement === false) {
			throw new RuntimeException('Unable to prepare webhook queue test SQL.');
		}
		return $statement;
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
$database->exec('CREATE TABLE registrationwatch_registrations (id INTEGER PRIMARY KEY, repeat_mode TEXT, enabled INTEGER NOT NULL)');
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
$database->exec("INSERT INTO registrationwatch_registrations (id, repeat_mode, enabled) VALUES (1, 'never', 1), (2, 'never', 0)");
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
	$firstWorkerStatus === 'failed' && $secondWorkerStatus === 'pending',
	'One webhook-worker invocation must process only one queued delivery; invalid local endpoints must fail without DNS or HTTP.'
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
$firstDelivery = $database->query('SELECT status, attempts, http_status FROM registrationwatch_webhook_queue WHERE history_id = 101')->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert($firstDelivery['status'] === 'pending' && (int)$firstDelivery['attempts'] === 1 && (int)$firstDelivery['http_status'] === 503, 'A transient HTTP error should be recorded and retried.');

$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp + 61), $resolver, $unavailable);
$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp + 362), $resolver, $unavailable);
$failedDelivery = $database->query('SELECT status, attempts FROM registrationwatch_webhook_queue WHERE history_id = 101')->fetch(PDO::FETCH_ASSOC);
webhook_queue_assert($failedDelivery['status'] === 'failed' && (int)$failedDelivery['attempts'] === 3, 'Transient delivery retries should be bounded to three attempts.');
$processQueue->invoke($module, date('Y-m-d H:i:s', $nowTimestamp + 362), $resolver, $unavailable);
$recoveryDelivery = $database->query('SELECT status FROM registrationwatch_webhook_queue WHERE history_id = 102')->fetchColumn();
webhook_queue_assert($recoveryDelivery === 'suppressed', 'Recovery must be suppressed when the corresponding problem notification was never delivered.');

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

echo "Webhook queue contract passed.\n";
