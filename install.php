<?php

// Registration Watch install hook.
// TODO: Add AMI ContactStatus ingestion and maintenance windows in a future phase.

global $db;

$sql = [];

$sql[] = "CREATE TABLE IF NOT EXISTS registrationwatch_registrations (
	id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	registration_key CHAR(64) NOT NULL,
	extension VARCHAR(80) NOT NULL,
	description VARCHAR(255) NULL,
	notes VARCHAR(72) NOT NULL DEFAULT '',
	notes_updated_at DATETIME NULL,
	enabled TINYINT(1) NOT NULL DEFAULT 1,
	auto_disabled_absent_at DATETIME NULL,
	repeat_mode VARCHAR(20) NULL,
	discovered TINYINT(1) NOT NULL DEFAULT 1,
	last_known_status VARCHAR(40) NOT NULL DEFAULT 'Unknown',
	contact_uri TEXT NULL,
	latency_ms DECIMAL(10,3) NULL,
	source_ip VARCHAR(45) NULL,
	registration_ua_class VARCHAR(191) NOT NULL DEFAULT '',
	transport VARCHAR(20) NULL,
	user_agent VARCHAR(255) NULL,
	device_name VARCHAR(255) NULL,
	firmware_version VARCHAR(80) NULL,
	source_port INT UNSIGNED NULL,
	contact_count INT UNSIGNED NOT NULL DEFAULT 1,
	contact_expires_at DATETIME NULL,
	qualify_frequency INT UNSIGNED NULL,
	last_heartbeat_at DATETIME NULL,
	last_seen_at DATETIME NULL,
	last_checked_at DATETIME NULL,
	first_discovered_at DATETIME NULL,
	last_discovered_at DATETIME NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY (id),
	UNIQUE KEY registrationwatch_registrations_key (registration_key),
	KEY registrationwatch_registrations_extension (extension),
	KEY registrationwatch_registrations_enabled (enabled),
	KEY registrationwatch_registrations_status (last_known_status),
	KEY registrationwatch_registrations_last_checked (last_checked_at),
	KEY registrationwatch_registrations_source_ip (source_ip),
	KEY registrationwatch_registrations_source_identity (extension, source_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS registrationwatch_status_history (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	registration_id INT UNSIGNED NOT NULL,
	registration_key CHAR(64) NOT NULL,
	extension VARCHAR(80) NOT NULL,
	from_state VARCHAR(40) NULL,
	to_state VARCHAR(40) NOT NULL,
	source VARCHAR(40) NOT NULL,
	reason VARCHAR(80) NULL,
	contact_uri TEXT NULL,
	latency_ms DECIMAL(10,3) NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	KEY registrationwatch_status_history_registration_id (registration_id),
	KEY registrationwatch_status_history_registration_key (registration_key),
	KEY registrationwatch_status_history_extension (extension),
	KEY registrationwatch_status_history_created_at (created_at),
	KEY registrationwatch_status_history_to_state (to_state),
	KEY registrationwatch_status_history_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS registrationwatch_settings (
	setting_key VARCHAR(80) NOT NULL,
	setting_value TEXT NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS registrationwatch_alert_history (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	registration_id INT UNSIGNED NULL,
	registration_key CHAR(64) NULL,
	extension VARCHAR(80) NOT NULL,
	history_id BIGINT UNSIGNED NULL,
	reminder_n INT UNSIGNED NOT NULL DEFAULT 0,
	alert_type VARCHAR(40) NOT NULL,
	status VARCHAR(40) NOT NULL,
	contact_uri TEXT NULL,
	recipient VARCHAR(255) NOT NULL,
	subject VARCHAR(255) NOT NULL,
	message TEXT NULL,
	sent_at DATETIME NOT NULL,
	result VARCHAR(40) NOT NULL,
	error TEXT NULL,
	PRIMARY KEY (id),
	KEY registrationwatch_alert_history_registration_id (registration_id),
	KEY registrationwatch_alert_history_registration_key (registration_key),
	KEY registrationwatch_alert_history_extension (extension),
	KEY registrationwatch_alert_history_history_id (history_id),
	KEY registrationwatch_alert_history_reminder_n (reminder_n),
	KEY registrationwatch_alert_history_alert_type (alert_type),
	KEY registrationwatch_alert_history_recipient (recipient),
	KEY registrationwatch_alert_history_sent_at (sent_at),
	KEY registrationwatch_alert_history_result (result),
	UNIQUE KEY registrationwatch_alert_unique_transition_recipient (history_id, alert_type, recipient(191), reminder_n)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$sql[] = "CREATE TABLE IF NOT EXISTS registrationwatch_alert_escalation (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	registration_id INT UNSIGNED NOT NULL,
	registration_key CHAR(64) NOT NULL,
	extension VARCHAR(80) NOT NULL,
	history_id BIGINT UNSIGNED NOT NULL,
	alert_type VARCHAR(40) NOT NULL,
	active_since DATETIME NOT NULL,
	last_alert_at DATETIME NULL,
	alert_count INT UNSIGNED NOT NULL DEFAULT 0,
	next_due_at DATETIME NOT NULL,
	repeat_mode VARCHAR(20) NOT NULL,
	created_at DATETIME NULL,
	updated_at DATETIME NULL,
	PRIMARY KEY (id),
	UNIQUE KEY registrationwatch_alert_escalation_registration_type (registration_id, alert_type),
	KEY registrationwatch_alert_escalation_registration_key (registration_key),
	KEY registrationwatch_alert_escalation_extension (extension),
	KEY registrationwatch_alert_escalation_history_id (history_id),
	KEY registrationwatch_alert_escalation_next_due_at (next_due_at),
	KEY registrationwatch_alert_escalation_repeat_mode (repeat_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

foreach ($sql as $statement) {
	$db->query($statement);
}

$db->query("ALTER TABLE registrationwatch_registrations MODIFY COLUMN notes VARCHAR(72) NOT NULL DEFAULT ''");

$defaultSettings = [
	'alert_enabled' => '0',
	'alert_recipients' => '',
	'repeat_mode' => 'never',
	'storm_threshold' => '20',
	'ui_show_limit' => '6',
	'alert_on_unreachable' => '1',
	'alert_on_not_registered' => '1',
	'alert_on_recovery' => '1',
	'debounce_seconds' => '0',
	'auto_disable_absent_seconds' => '2592000',
	'trusted_vpn_networks' => '',
	'topology_poll_interval_seconds' => '10',
	'status_history_prune_policy' => 'never',
	'alert_history_prune_policy' => 'never',
];

foreach ($defaultSettings as $key => $value) {
	$stmt = $db->prepare('INSERT IGNORE INTO registrationwatch_settings (setting_key, setting_value, updated_at) VALUES (:setting_key, :setting_value, :updated_at)');
	$stmt->execute([
		':setting_key' => $key,
		':setting_value' => $value,
		':updated_at' => date('Y-m-d H:i:s'),
	]);
}

// Register Registration Watch background job.
// FreePBX runs centralized jobs once per minute via fwconsole job --run.
try {
        if (class_exists('\FreePBX')) {
                \FreePBX::Job()->addClass(
                        'registrationwatch',
                        'monitor',
                        '\FreePBX\modules\Registrationwatch\Job',
                        '* * * * *',
                        30,
                        true
                );
        }
} catch (\Throwable $e) {
        if (class_exists('\FreePBX') && method_exists('\FreePBX', 'Log')) {
                \FreePBX::Log()->error('registrationwatch: failed to register background job: ' . $e->getMessage());
        }
}
