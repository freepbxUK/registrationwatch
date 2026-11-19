<?php

declare(strict_types=1);

class MigrationContractResult {
	private $rows;
	private $index = 0;

	public function __construct(array $rows) {
		$this->rows = array_values($rows);
	}

	public function fetch() {
		return $this->rows[$this->index++] ?? false;
	}
}

class MigrationContractStatement {
	private $database;
	private $sql;

	public function __construct(MigrationContractDatabase $database, string $sql) {
		$this->database = $database;
		$this->sql = $sql;
	}

	public function execute(array $parameters = []): bool {
		$this->database->executePrepared($this->sql, $parameters);
		return true;
	}
}

class MigrationContractDatabase {
	public $settings = [];
	public $registrations = [];
	public $queries = [];
	public $inserts = [];
	public $webhookFingerprintColumn = false;
	public $webhookQueueRows = [];
	public $replacementDestinationFingerprint = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	public function query(string $sql) {
		$this->queries[] = $sql;
		if (strpos($sql, 'CREATE TABLE IF NOT EXISTS ') === 0) {
			return true;
		}
		if (strpos($sql, 'ALTER TABLE registrationwatch_webhook_queue') === 0
			&& strpos($sql, 'ADD COLUMN destination_fingerprint') !== false) {
			$this->webhookFingerprintColumn = true;
			return true;
		}
		if (strpos($sql, 'ALTER TABLE ') === 0) {
			return true;
		}
		if (strpos($sql, 'SELECT COUNT(*) AS column_count') === 0
			&& strpos($sql, 'destination_fingerprint') !== false) {
			return new MigrationContractResult([[
				'column_count' => $this->webhookFingerprintColumn ? 1 : 0,
			]]);
		}
		if (strpos($sql, 'UPDATE registrationwatch_webhook_queue') === 0) {
			foreach ($this->webhookQueueRows as &$row) {
				$fingerprint = $row['destination_fingerprint'] ?? null;
				$missingFingerprint = $fingerprint === null || (string)$fingerprint === '';
				if (!$missingFingerprint
					|| !in_array((string)($row['status'] ?? ''), ['pending', 'processing'], true)) {
					continue;
				}
				$row['status'] = 'suppressed';
				$row['last_error'] = 'Legacy notification suppressed: original destination configuration cannot be verified.';
				$row['updated_at'] = '2026-11-19 09:50:52';
			}
			unset($row);
			return true;
		}
		if (strpos($sql, 'SELECT setting_value') === 0
			&& strpos($sql, 'FROM registrationwatch_settings') !== false) {
			if (strpos($sql, 'webhook_destinations') !== false) {
				return new MigrationContractResult([]);
			}
			$key = 'extension_monitoring_state_migrated_v1';
			return new MigrationContractResult(isset($this->settings[$key]) ? [['setting_value' => $this->settings[$key]]] : []);
		}
		if (strpos($sql, 'SELECT extension,') === 0) {
			$grouped = [];
			foreach ($this->registrations as $registration) {
				$extension = (string)$registration['extension'];
				if (!isset($grouped[$extension])) {
					$grouped[$extension] = ['extension' => $extension, 'total_rows' => 0, 'enabled_rows' => 0];
				}
				$grouped[$extension]['total_rows']++;
				$grouped[$extension]['enabled_rows'] += (int)$registration['enabled'] === 1 ? 1 : 0;
			}
			return new MigrationContractResult(array_values($grouped));
		}
		throw new RuntimeException('Unexpected installer query: ' . $sql);
	}

	public function prepare(string $sql): MigrationContractStatement {
		return new MigrationContractStatement($this, $sql);
	}

	public function executePrepared(string $sql, array $parameters): void {
		if (strpos($sql, 'INSERT IGNORE INTO registrationwatch_settings') !== 0) {
			throw new RuntimeException('Unexpected installer statement: ' . $sql);
		}
		$key = (string)$parameters[':setting_key'];
		$this->inserts[] = $key;
		if (!array_key_exists($key, $this->settings)) {
			$this->settings[$key] = (string)$parameters[':setting_value'];
		}
	}
}

function migration_assert(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$db = new MigrationContractDatabase();
$db->settings = [
	'alert_enabled' => '1',
	'alert_recipients' => 'ops@example.invalid',
	'monitoring_snoozed_until' => '2026-11-18 20:00:00',
	'extension_monitoring_state_1001' => '0',
];
$db->registrations = [
	['extension' => '1001', 'enabled' => 1],
	['extension' => '1002', 'enabled' => 0],
	['extension' => '1003', 'enabled' => 1],
];
$legacyPending = [
	'id' => 1,
	'destination_id' => str_repeat('a', 32),
	'event_id' => str_repeat('1', 64),
	'registration_id' => 10,
	'history_id' => 101,
	'payload' => '{"event":"registration.unreachable"}',
	'status' => 'pending',
	'destination_fingerprint' => null,
	'attempts' => 0,
	'last_error' => null,
	'updated_at' => '2026-11-19 09:00:00',
];
$db->webhookQueueRows = [
	$legacyPending,
	array_merge($legacyPending, [
		'id' => 2,
		'event_id' => str_repeat('2', 64),
		'status' => 'processing',
		'attempts' => 1,
	]),
	array_merge($legacyPending, [
		'id' => 3,
		'event_id' => str_repeat('3', 64),
		'status' => 'delivered',
		'attempts' => 3,
		'last_error' => null,
		'updated_at' => '2026-11-19 08:00:00',
	]),
	array_merge($legacyPending, [
		'id' => 4,
		'event_id' => str_repeat('4', 64),
		'status' => 'failed',
		'attempts' => 3,
		'last_error' => 'endpoint failure',
		'updated_at' => '2026-11-19 08:01:00',
	]),
	array_merge($legacyPending, [
		'id' => 5,
		'event_id' => str_repeat('5', 64),
		'status' => 'suppressed',
		'attempts' => 2,
		'last_error' => 'already suppressed',
		'updated_at' => '2026-11-19 08:02:00',
	]),
	array_merge($legacyPending, [
		'id' => 6,
		'event_id' => str_repeat('6', 64),
		'status' => 'pending',
		'destination_fingerprint' => str_repeat('c', 64),
		'attempts' => 1,
	]),
];
$queueRowsBeforeMigration = $db->webhookQueueRows;

$installer = dirname(__DIR__) . '/install.php';
include $installer;

migration_assert($db->settings['alert_enabled'] === '1', 'Existing email notification settings must be preserved.');
migration_assert($db->settings['alert_recipients'] === 'ops@example.invalid', 'Existing notification recipients must be preserved.');
migration_assert($db->settings['monitoring_snoozed_until'] === '2026-11-18 20:00:00', 'Existing alert suppression state must be preserved.');
migration_assert($db->settings['extension_monitoring_state_1001'] === '0', 'An existing explicit unmonitored choice must not be overwritten.');
migration_assert($db->settings['extension_monitoring_state_1002'] === '0', 'Extensions with no monitored legacy rows must remain unmonitored.');
migration_assert($db->settings['extension_monitoring_state_1003'] === '1', 'Previously monitored extensions should retain their monitoring selection.');
migration_assert($db->settings['extension_monitoring_state_migrated_v1'] === '1', 'The one-time migration marker must be persisted.');
migration_assert(
	count(array_filter($db->queries, static function (string $sql): bool {
		return strpos($sql, 'CREATE TABLE IF NOT EXISTS registrationwatch_webhook_queue') === 0;
	})) === 1,
	'The idempotent webhook queue table should be created during installation.'
);
migration_assert(
	in_array('ALTER TABLE registrationwatch_settings MODIFY COLUMN setting_value MEDIUMTEXT NULL', $db->queries, true),
	'The settings value column should support large, valid webhook destination configurations.'
);
migration_assert(
	$db->webhookFingerprintColumn
		&& count(array_filter($db->queries, static function (string $sql): bool {
			return strpos($sql, 'ALTER TABLE registrationwatch_webhook_queue') === 0
				&& strpos($sql, 'ADD COLUMN destination_fingerprint') !== false;
		})) === 1,
	'The upgrade should add the destination fingerprint column exactly when it is missing: '
		. json_encode($db->queries)
);
migration_assert(
	$db->webhookQueueRows[0]['status'] === 'suppressed'
		&& $db->webhookQueueRows[1]['status'] === 'suppressed',
	'Legacy pending and processing queue rows must be suppressed during upgrade.'
);
migration_assert(
	$db->webhookQueueRows[2]['status'] === 'delivered'
		&& $db->webhookQueueRows[3]['status'] === 'failed'
		&& $db->webhookQueueRows[4]['status'] === 'suppressed'
		&& $db->webhookQueueRows[2]['last_error'] === $queueRowsBeforeMigration[2]['last_error']
		&& $db->webhookQueueRows[3]['last_error'] === $queueRowsBeforeMigration[3]['last_error']
		&& $db->webhookQueueRows[4]['last_error'] === $queueRowsBeforeMigration[4]['last_error'],
	'Historical delivered, failed and already-suppressed queue rows must preserve status and errors.'
);
migration_assert(
	$db->webhookQueueRows[5] === $queueRowsBeforeMigration[5],
	'Queue rows with an existing destination fingerprint must remain unchanged.'
);
foreach ([0, 1] as $index) {
	$before = $queueRowsBeforeMigration[$index];
	$after = $db->webhookQueueRows[$index];
	migration_assert(
		$after['id'] === $before['id']
			&& $after['destination_id'] === $before['destination_id']
			&& $after['event_id'] === $before['event_id']
			&& $after['registration_id'] === $before['registration_id']
			&& $after['history_id'] === $before['history_id']
			&& $after['payload'] === $before['payload']
			&& $after['attempts'] === $before['attempts'],
		'Suppression must preserve queue payloads, identifiers and attempts.'
	);
	migration_assert(
		$after['last_error'] === 'Legacy notification suppressed: original destination configuration cannot be verified.'
			&& $after['updated_at'] !== $before['updated_at'],
		'Suppression must record an explicit legacy-identity reason and update the row timestamp.'
	);
	migration_assert(
		$after['destination_fingerprint'] !== $db->replacementDestinationFingerprint,
		'The migration must never assign a replacement destination fingerprint to legacy notifications.'
	);
}

$firstSettings = $db->settings;
$queueRowsAfterMigration = $db->webhookQueueRows;
$db->settings['alert_enabled'] = '0';
include $installer;
migration_assert($db->settings['alert_enabled'] === '0', 'A later upgrade must not reset a changed notification setting.');
migration_assert($db->settings['extension_monitoring_state_1002'] === '0', 'A repeated upgrade must not enable an unmonitored extension.');
migration_assert(
	$db->settings === array_merge($firstSettings, ['alert_enabled' => '0']),
	'A repeated install should preserve all existing and migrated settings.'
);
migration_assert(
	$db->webhookQueueRows === $queueRowsAfterMigration,
	'A repeated migration must leave already-suppressed and historical queue rows unchanged.'
);

echo "Install migration contract passed.\n";
