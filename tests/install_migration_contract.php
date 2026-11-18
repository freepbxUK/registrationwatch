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

	public function query(string $sql) {
		$this->queries[] = $sql;
		if (strpos($sql, 'CREATE TABLE IF NOT EXISTS ') === 0 || strpos($sql, 'ALTER TABLE ') === 0) {
			return true;
		}
		if (strpos($sql, 'SELECT setting_value FROM registrationwatch_settings') === 0) {
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

$firstSettings = $db->settings;
$db->settings['alert_enabled'] = '0';
include $installer;
migration_assert($db->settings['alert_enabled'] === '0', 'A later upgrade must not reset a changed notification setting.');
migration_assert($db->settings['extension_monitoring_state_1002'] === '0', 'A repeated upgrade must not enable an unmonitored extension.');
migration_assert(
	$db->settings === array_merge($firstSettings, ['alert_enabled' => '0']),
	'A repeated install should preserve all existing and migrated settings.'
);

echo "Install migration contract passed.\n";
