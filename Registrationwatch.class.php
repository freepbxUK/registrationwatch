<?php
/**
 * Registration Watch for FreePBX 16 and 17.
 *
 * PJSIP registration discovery and current status visibility.
 *
 * @copyright 2026 20 Telecom Ltd (trading as 20tele.com)
 * @license   GPLv3+
 */

namespace FreePBX\modules;

class Registrationwatch implements \BMO {

	/** Fallback only. Authoritative version lives in module.xml. */
	const VERSION = '1.3.1';

	const STATUS_REACHABLE = 'Reachable';
	const STATUS_UNREACHABLE = 'Unreachable';
	const STATUS_REGISTERED_NO_QUALIFY = 'Registered (no qualify)';
	const STATUS_UNKNOWN = 'Unknown';
	const STATUS_NOT_REGISTERED = 'Not registered';
	const CSRF_SESSION_KEY = 'registrationwatch_csrf_token';
	const ALERT_TIMING_MAX_SECONDS = 86400;
	const ALERT_STALE_TRANSITION_MAX_SECONDS = 300;
	const AUTO_DISABLE_ABSENT_DEFAULT_SECONDS = 2592000;
	const REPEAT_MODE_NEVER = 'never';
	const REPEAT_MODE_FIVE_MINUTES = '5m';
	const REPEAT_MODE_HOURLY = 'hourly';
	const REPEAT_MODE_DAILY = 'daily';
	const REPEAT_MODE_ESCALATING = 'escalating';
	const REPEAT_MODE_FIBONACCI = 'fibonacci';
	const REPEAT_DAILY_SECONDS = 86400;
	const REPEAT_ESCALATING_BASE_SECONDS = 300;
	const REPEAT_ESCALATING_CEILING_SECONDS = 86400;
	const REPEAT_MODES = [
		self::REPEAT_MODE_NEVER,
		self::REPEAT_MODE_FIVE_MINUTES,
		self::REPEAT_MODE_HOURLY,
		self::REPEAT_MODE_DAILY,
		self::REPEAT_MODE_ESCALATING,
	];

	private $settingsDefaults = [
		'alert_enabled' => '0',
		'alert_recipients' => '',
		'repeat_mode' => self::REPEAT_MODE_NEVER,
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
		'monitoring_snoozed_until' => '',
		'ui_map_view_mode' => 'card',
		'ui_map_sort_key' => 'extension',
		'ui_map_sort_dir' => 'asc',
		'ui_watched_sort_key' => 'extension',
		'ui_watched_sort_dir' => 'asc',
		'ui_status_history_sort_key' => 'time',
		'ui_status_history_sort_dir' => 'desc',
		'ui_alert_history_sort_key' => 'time',
		'ui_alert_history_sort_dir' => 'desc',
	];

	/** @var \FreePBX */
	private $FreePBX;

	public function __construct($freepbx = null) {
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
	}

	public function install(): void {}
	public function uninstall(): void {}

	public function backup(): array {
		$backup = [
			'settings' => [],
			'registrations' => [],
		];

		try {
			$db = $this->db();

			// Backup settings
			$stmt = $db->query('SELECT setting_key, setting_value FROM registrationwatch_settings');
			if ($stmt) {
				$backup['settings'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			}

			// Backup stored registrations
			$stmt = $db->query('SELECT * FROM registrationwatch_registrations');
			if ($stmt) {
				$backup['registrations'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			}
		} catch (\Exception $e) {
			$this->logError('Backup failed: ' . $e->getMessage());
			return [];
		}

		return $backup;
	}

	public function restore($backup): void {
		if (!is_array($backup) || empty($backup)) {
			return;
		}

		try {
			$db = $this->db();

			// Restore settings (upsert to preserve existing values)
			if (isset($backup['settings']) && is_array($backup['settings'])) {
				foreach ($backup['settings'] as $row) {
					$stmt = $db->prepare(
						'INSERT INTO registrationwatch_settings (setting_key, setting_value, updated_at)
						VALUES (:setting_key, :setting_value, :updated_at)
						ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
					);
					$stmt->execute([
						':setting_key' => $row['setting_key'],
						':setting_value' => $row['setting_value'],
						':updated_at' => $this->now(),
					]);
				}
			}

			// Restore stored registrations (preserve discovery flags)
			if (isset($backup['registrations']) && is_array($backup['registrations'])) {
				foreach ($backup['registrations'] as $row) {
					$sourceIp = $this->normaliseSourceIp($row['source_ip'] ?? '');
					$uaClass = isset($row['registration_ua_class']) ? (string)$row['registration_ua_class'] : '';
					$registrationKey = isset($row['registration_key']) && $row['registration_key'] !== ''
						? (string)$row['registration_key']
						: ($sourceIp !== '' ? $this->registrationKey((string)$row['extension'], $sourceIp, $uaClass) : '');
					if ($registrationKey === '') {
						continue;
					}
					$stmt = $db->prepare(
						'INSERT INTO registrationwatch_registrations
							(registration_key, registration_ua_class, extension, description, notes, notes_updated_at, enabled, auto_disabled_absent_at, repeat_mode, discovered, last_known_status, contact_uri,
							 source_ip, source_port, contact_count, transport, user_agent, device_name, firmware_version,
							 contact_expires_at, qualify_frequency, last_heartbeat_at, latency_ms,
							 last_seen_at, last_checked_at, first_discovered_at, last_discovered_at,
							 created_at, updated_at)
						VALUES
							(:registration_key, :registration_ua_class, :extension, :description, :notes, :notes_updated_at, :enabled, :auto_disabled_absent_at, :repeat_mode, :discovered, :last_known_status, :contact_uri,
							 :source_ip, :source_port, :contact_count, :transport, :user_agent, :device_name, :firmware_version,
							 :contact_expires_at, :qualify_frequency, :last_heartbeat_at, :latency_ms,
							 :last_seen_at, :last_checked_at, :first_discovered_at, :last_discovered_at,
							 :created_at, :updated_at)
						ON DUPLICATE KEY UPDATE
							description = VALUES(description),
							notes = VALUES(notes),
							notes_updated_at = VALUES(notes_updated_at),
							enabled = VALUES(enabled),
							auto_disabled_absent_at = VALUES(auto_disabled_absent_at),
							repeat_mode = VALUES(repeat_mode),
							last_known_status = VALUES(last_known_status),
							contact_uri = VALUES(contact_uri),
							source_ip = VALUES(source_ip),
							source_port = VALUES(source_port),
							registration_ua_class = VALUES(registration_ua_class),
							contact_count = VALUES(contact_count),
							transport = VALUES(transport),
							user_agent = VALUES(user_agent),
							device_name = VALUES(device_name),
							firmware_version = VALUES(firmware_version),
							contact_expires_at = VALUES(contact_expires_at),
							qualify_frequency = VALUES(qualify_frequency),
							last_heartbeat_at = VALUES(last_heartbeat_at),
							latency_ms = VALUES(latency_ms),
							last_seen_at = VALUES(last_seen_at),
							last_checked_at = VALUES(last_checked_at),
							last_discovered_at = VALUES(last_discovered_at),
							updated_at = VALUES(updated_at)'
					);
					$stmt->execute([
						':registration_key' => $registrationKey,
						':registration_ua_class' => $uaClass,
						':extension' => $row['extension'],
						':description' => $row['description'] ?? null,
						':notes' => isset($row['notes']) ? substr((string)$row['notes'], 0, 72) : '',
						':notes_updated_at' => $row['notes_updated_at'] ?? null,
						':enabled' => $row['enabled'] ?? 1,
						':auto_disabled_absent_at' => $row['auto_disabled_absent_at'] ?? null,
						':repeat_mode' => isset($row['repeat_mode']) && $row['repeat_mode'] !== null && $row['repeat_mode'] !== ''
							? $this->normaliseRepeatMode((string)$row['repeat_mode'])
							: null,
						':discovered' => $row['discovered'] ?? 1,
						':last_known_status' => $row['last_known_status'] ?? self::STATUS_UNKNOWN,
						':contact_uri' => $row['contact_uri'] ?? null,
						':source_ip' => $sourceIp !== '' ? $sourceIp : null,
						':source_port' => $row['source_port'] ?? null,
						':contact_count' => max(1, (int)($row['contact_count'] ?? 1)),
						':transport' => $row['transport'] ?? null,
						':user_agent' => $row['user_agent'] ?? null,
						':device_name' => $row['device_name'] ?? null,
						':firmware_version' => $row['firmware_version'] ?? null,
						':contact_expires_at' => $row['contact_expires_at'] ?? null,
						':qualify_frequency' => $row['qualify_frequency'] ?? null,
						':last_heartbeat_at' => $row['last_heartbeat_at'] ?? null,
						':latency_ms' => $row['latency_ms'] ?? null,
						':last_seen_at' => $row['last_seen_at'] ?? null,
						':last_checked_at' => $row['last_checked_at'] ?? null,
						':first_discovered_at' => $row['first_discovered_at'] ?? null,
						':last_discovered_at' => $row['last_discovered_at'] ?? null,
						':created_at' => $row['created_at'] ?? $this->now(),
						':updated_at' => $this->now(),
					]);
				}
			}
		} catch (\Exception $e) {
			$this->logError('Restore failed: ' . $e->getMessage());
		}
	}
	public function doConfigPageInit($page): void {}

	public function getVersion(): string {
		try {
			$info = \FreePBX::Modules()->getInfo('registrationwatch');
			if (isset($info['registrationwatch']['version'])) {
				return (string)$info['registrationwatch']['version'];
			}
		} catch (\Exception $e) {
			// Module metadata may be unavailable during early install.
		}

		return self::VERSION;
	}

	public function showPage(): string {
		$data = $this->getPageData(false);

		return load_view(__DIR__ . '/views/main.php', [
			'moduleVersion' => $this->getVersion(),
			'registrations' => $data['registrations'],
			'statusHistory' => $data['statusHistory'],
			'alertSettings' => $data['alertSettings'],
			'pruneSettings' => $this->getPruneSettings(),
			'alertHistory' => $data['alertHistory'],
			'lastRefresh' => $data['lastRefresh'],
			'refreshError' => $data['refreshError'],
			'emailStatus' => $data['emailStatus'],
			'timeDiagnostics' => $data['timeDiagnostics'],
			'pollIntervalSeconds' => $data['pollIntervalSeconds'],
			'csrfToken' => $this->createCsrfToken(),
		]);
	}

	public function ajaxRequest($req, &$setting): bool {
		switch ($req) {
			case 'refresh':
			case 'setenabled':
			case 'setrepeatmode':
			case 'savenotes':
			case 'saveshowlimit':
			case 'saveuisetting':
			case 'savealerts':
			case 'savetopology':
			case 'testemail':
			case 'gettopology':
			case 'saveprunepolicy':
			case 'deletestatushistoryrow':
			case 'deletealerthistoryrow':
			case 'resetextensionfromasterisk':
			case 'snoozemonitoring':
			case 'resumemonitoring':
				return true;
		}

		return false;
	}

	public function ajaxHandler(): array {
		$command = isset($_REQUEST['command']) ? (string)$_REQUEST['command'] : '';

		// CSRF protection: required for all state-modifying operations
		if (!$this->validateCsrfToken()) {
			return ['status' => false, 'message' => _('Invalid security token. Please reload the page and try again.')];
		}

		// Access control relies on FreePBX authenticated admin context.
		// See README TODO for granular ACL integration roadmap.

		switch ($command) {
			case 'refresh':
				return $this->handleRefresh();
			case 'setenabled':
				return $this->handleSetEnabled();
			case 'setrepeatmode':
				return $this->handleSetRepeatMode();
			case 'savenotes':
				return $this->handleSaveNotes();
			case 'saveshowlimit':
				return $this->handleSaveShowLimit();
			case 'saveuisetting':
				return $this->handleSaveUiSetting();
			case 'savealerts':
				return $this->handleSaveAlerts();
			case 'savetopology':
				return $this->handleSaveTopology();
			case 'testemail':
				return $this->handleTestEmail();
			case 'gettopology':
				return $this->handleGetTopology();
			case 'saveprunepolicy':
				return $this->handleSavePrunePolicy();
			case 'deletestatushistoryrow':
				return $this->handleDeleteStatusHistoryRow();
			case 'deletealerthistoryrow':
				return $this->handleDeleteAlertHistoryRow();
			case 'resetextensionfromasterisk':
				return $this->handleResetExtensionFromAsterisk();
			case 'snoozemonitoring':
				return $this->handleSnoozeMonitoring();
			case 'resumemonitoring':
				return $this->handleResumeMonitoring();
		}

		return ['status' => false, 'message' => _('Unknown command')];
	}

	private function handleRefresh(): array {
		try {
			$data = $this->getPageData(true);

			return [
				'status' => $data['refreshError'] === '',
				'message' => $data['refreshError'] === '' ? _('Registration status refreshed.') : $data['refreshError'],
				'registrations' => $data['registrations'],
				'watchedExtensions' => $data['watchedExtensions'],
				'statusHistory' => $data['statusHistory'],
				'alertHistory' => $data['alertHistory'],
				'lastRefresh' => $data['lastRefresh'],
				'timeDiagnostics' => $data['timeDiagnostics'],
				'monitoringState' => $data['monitoringState'],
			];
		} catch (\Exception $e) {
			$message = _('Failed to refresh registration status. Please check the system logs.');
			$this->logError('Refresh failed: ' . $e->getMessage());
			return ['status' => false, 'message' => $message];
		}
	}

	private function handleSetEnabled(): array {
		$registrationId = $this->positiveRequestId('registration_id');
		$enabled = !empty($_REQUEST['enabled']) ? 1 : 0;

		if ($registrationId <= 0) {
			return ['status' => false, 'message' => _('Missing watched registration.')];
		}

		$db = $this->db();
		$extStmt = $db->prepare('SELECT extension FROM registrationwatch_registrations WHERE id = :id');
		$extStmt->execute([':id' => $registrationId]);
		$extension = (string)($extStmt->fetchColumn() ?? '');

		if ($extension === '') {
			return ['status' => false, 'message' => _('Missing watched registration.')];
		}

		$stmt = $db->prepare('UPDATE registrationwatch_registrations SET enabled = :enabled, updated_at = :updated_at WHERE extension = :extension');
		$stmt->execute([
			':enabled' => $enabled,
			':updated_at' => $this->now(),
			':extension' => $extension,
		]);

		return [
			'status' => true,
			'message' => $enabled ? _('Registration selected.') : _('Registration selection cleared.'),
			'registration_id' => $registrationId,
			'enabled' => $enabled,
			'monitoringState' => $this->getMonitoringState(),
		];
	}

	private function handleResetExtensionFromAsterisk(): array {
		$registrationId = $this->positiveRequestId('registration_id');
		if ($registrationId <= 0) {
			return ['status' => false, 'message' => _('Missing watched registration.')];
		}

		$db = $this->db();
		$extStmt = $db->prepare('SELECT extension FROM registrationwatch_registrations WHERE id = :id');
		$extStmt->execute([':id' => $registrationId]);
		$extension = $this->normaliseRegistrationExtension((string)($extStmt->fetchColumn() ?? ''));
		if ($extension === '') {
			return ['status' => false, 'message' => _('Missing watched registration.')];
		}

		if (!$this->acquireReconcileLock()) {
			return ['status' => false, 'message' => _('Reset is unavailable because another registration reconcile is running. Try again in a moment.')];
		}

		try {
			$settings = $this->extensionResetSnapshot($extension);
			if (!$settings['ok']) {
				return ['status' => false, 'message' => $settings['message']];
			}

			$allowedDevices = $this->getAllowedPjsipDeviceIds();
			if (!isset($allowedDevices[$extension])) {
				return ['status' => false, 'message' => _('This extension is no longer a configured PJSIP device, so it cannot be rebuilt from Asterisk contacts.')];
			}

			$db->beginTransaction();
			try {
				$now = $this->now();
				$liveContacts = $this->getLiveContacts($allowedDevices);
				$descriptions = $this->getRegistrationDescriptions();
				$description = $descriptions[$extension] ?? '';

				$clearEscalations = $db->prepare(
					'DELETE FROM registrationwatch_alert_escalation
					WHERE registration_id IN (
						SELECT id FROM registrationwatch_registrations WHERE extension = :extension
					)'
				);
				$clearEscalations->execute([':extension' => $extension]);

				$deleteRows = $db->prepare('DELETE FROM registrationwatch_registrations WHERE extension = :extension');
				$deleteRows->execute([':extension' => $extension]);

				$extensionContacts = [];
				foreach ($liveContacts as $contact) {
					if ($this->normaliseRegistrationExtension((string)($contact['extension'] ?? '')) !== $extension) {
						continue;
					}
					$extensionContacts[] = $contact;
				}

				if ($extensionContacts) {
					$insert = $db->prepare(
						'INSERT INTO registrationwatch_registrations
							(registration_key, extension, description, notes, notes_updated_at, enabled, auto_disabled_absent_at, repeat_mode,
								discovered, last_known_status, contact_uri, source_ip, source_port, registration_ua_class,
								transport, user_agent, device_name, firmware_version, contact_count,
								contact_expires_at, qualify_frequency, latency_ms, last_seen_at, last_checked_at,
								created_at, updated_at, first_discovered_at, last_discovered_at)
						VALUES
							(:registration_key, :extension, :description, :notes, :notes_updated_at, :enabled, NULL, :repeat_mode,
								1, :last_known_status, :contact_uri, :source_ip, :source_port, :registration_ua_class,
								:transport, :user_agent, :device_name, :firmware_version, :contact_count,
								:contact_expires_at, :qualify_frequency, :latency_ms, :last_seen_at, :last_checked_at,
								:created_at, :updated_at, :first_discovered_at, :last_discovered_at)'
					);

					foreach ($extensionContacts as $contact) {
						$contactUri = (string)($contact['contact_uri'] ?? '');
						$lastSeenAt = $contactUri !== '' ? $now : null;
						$insert->execute([
							':registration_key' => (string)$contact['registration_key'],
							':extension' => $extension,
							':description' => $description,
							':notes' => $settings['notes'],
							':notes_updated_at' => $settings['notes_updated_at'],
							':enabled' => $settings['enabled'],
							':repeat_mode' => $settings['repeat_mode'],
							':last_known_status' => $this->normaliseState($contact['status'] ?? self::STATUS_UNKNOWN),
							':contact_uri' => $contactUri !== '' ? $contactUri : null,
							':source_ip' => $contact['source_ip'] ?? null,
							':source_port' => $contact['source_port'] ?? null,
							':registration_ua_class' => $contact['registration_ua_class'] ?? '',
							':transport' => $contact['transport'] ?? null,
							':user_agent' => $contact['user_agent'] ?? null,
							':device_name' => $contact['device_name'] ?? null,
							':firmware_version' => $contact['firmware_version'] ?? null,
							':contact_count' => max(1, (int)($contact['contact_count'] ?? 1)),
							':contact_expires_at' => $contact['contact_expires_at'] ?? null,
							':qualify_frequency' => $contact['qualify_frequency'] ?? null,
							':latency_ms' => $contact['latency_ms'] ?? null,
							':last_seen_at' => $lastSeenAt,
							':last_checked_at' => $now,
							':created_at' => $now,
							':updated_at' => $now,
							':first_discovered_at' => $now,
							':last_discovered_at' => $now,
						]);
					}
				} else {
					$insert = $db->prepare(
						'INSERT INTO registrationwatch_registrations
							(registration_key, extension, description, notes, notes_updated_at, enabled, auto_disabled_absent_at, repeat_mode,
								discovered, last_known_status, contact_count, last_checked_at,
								created_at, updated_at, first_discovered_at, last_discovered_at)
						VALUES
							(:registration_key, :extension, :description, :notes, :notes_updated_at, :enabled, NULL, :repeat_mode,
								1, :last_known_status, 1, :last_checked_at,
								:created_at, :updated_at, :first_discovered_at, :last_discovered_at)'
					);
					$insert->execute([
						':registration_key' => $this->noContactRegistrationKey($extension),
						':extension' => $extension,
						':description' => $description,
						':notes' => $settings['notes'],
						':notes_updated_at' => $settings['notes_updated_at'],
						':enabled' => $settings['enabled'],
						':repeat_mode' => $settings['repeat_mode'],
						':last_known_status' => self::STATUS_NOT_REGISTERED,
						':last_checked_at' => $now,
						':created_at' => $now,
						':updated_at' => $now,
						':first_discovered_at' => $now,
						':last_discovered_at' => $now,
					]);
				}

				$db->commit();
			} catch (\Throwable $e) {
				if ($db->inTransaction()) {
					$db->rollBack();
				}
				throw $e;
			}

			return [
				'status' => true,
				'message' => _('Registration state reset from Asterisk.'),
				'registrations' => $this->getRegistrationMapRows(),
				'watchedExtensions' => $this->getWatchedExtensionGroups(),
				'statusHistory' => $this->getStatusHistory(),
				'alertHistory' => $this->getAlertHistory(),
				'timeDiagnostics' => $this->getTimeDiagnostics(),
				'monitoringState' => $this->getMonitoringState(),
			];
		} catch (\Throwable $e) {
			$this->logError('Extension reset from Asterisk failed: ' . $e->getMessage());
			return ['status' => false, 'message' => _('Unable to reset from Asterisk. No changes were applied.')];
		} finally {
			$this->releaseReconcileLock();
		}
	}

	private function extensionResetSnapshot(string $extension): array {
		$stmt = $this->db()->prepare(
			'SELECT id, notes, notes_updated_at, enabled, repeat_mode
			FROM registrationwatch_registrations
			WHERE extension = :extension
			ORDER BY id ASC'
		);
		$stmt->execute([':extension' => $extension]);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		if (!$rows) {
			return [
				'ok' => false,
				'message' => _('No stored registrations were found for this extension.'),
			];
		}

		$normalise = function ($value): string {
			return trim((string)$value);
		};

		$noteValues = [];
		$preservedRow = null;
		foreach ($rows as $row) {
			$note = $normalise($row['notes'] ?? '');
			if ($note === '') {
				continue;
			}

			$noteValues[$note] = true;
			if ($preservedRow === null) {
				$preservedRow = $row;
			}
		}

		if (count($noteValues) > 1) {
			return [
				'ok' => false,
				'message' => _('Refresh cancelled because this extension has conflicting notes. Make the notes match, then try again.'),
			];
		}

		$enabled = 0;
		$repeatMode = null;
		$preservedRow = $preservedRow ?? $rows[0];
		$notes = $normalise($preservedRow['notes'] ?? '');
		$notesUpdatedAt = $normalise($preservedRow['notes_updated_at'] ?? '') !== ''
			? (string)$preservedRow['notes_updated_at']
			: null;

		foreach ($rows as $row) {
			if ((int)($row['enabled'] ?? 0) === 1) {
				$enabled = 1;
			}
			$currentRepeat = isset($row['repeat_mode']) && trim((string)$row['repeat_mode']) !== ''
				? $this->normaliseRepeatMode((string)$row['repeat_mode'])
				: null;
			if ($currentRepeat !== null) {
				$repeatMode = $currentRepeat;
			}
		}

		return [
			'ok' => true,
			'enabled' => $enabled,
			'repeat_mode' => $repeatMode,
			'notes' => $notes,
			'notes_updated_at' => $notesUpdatedAt,
		];
	}

	private function handleSetRepeatMode(): array {
		$registrationId = $this->positiveRequestId('registration_id');
		$rawMode = isset($_REQUEST['repeat_mode']) ? trim((string)$_REQUEST['repeat_mode']) : '';

		if ($registrationId <= 0) {
			return ['status' => false, 'message' => _('Missing watched registration.')];
		}

		$repeatMode = $rawMode === '' ? null : $this->normaliseRepeatMode($rawMode);
		$now = $this->now();
		$db = $this->db();
		$extStmt = $db->prepare('SELECT extension FROM registrationwatch_registrations WHERE id = :id');
		$extStmt->execute([':id' => $registrationId]);
		$extension = (string)($extStmt->fetchColumn() ?? '');

		if ($extension === '') {
			return ['status' => false, 'message' => _('Missing watched registration.')];
		}

		$stmt = $db->prepare(
			'UPDATE registrationwatch_registrations
			SET repeat_mode = :repeat_mode,
				updated_at = :updated_at
			WHERE extension = :extension'
		);
		$stmt->execute([
			':repeat_mode' => $repeatMode,
			':updated_at' => $now,
			':extension' => $extension,
		]);

		$settings = $this->getAlertSettings();
		$resolvedMode = $this->resolveRepeatMode($repeatMode, $settings);
		if ($resolvedMode === self::REPEAT_MODE_NEVER) {
			$stmt = $db->prepare(
				'DELETE FROM registrationwatch_alert_escalation
				WHERE registration_id IN (
					SELECT id FROM registrationwatch_registrations WHERE extension = :extension
				)'
			);
			$stmt->execute([':extension' => $extension]);
		}

		return [
			'status' => true,
			'message' => _('Repeat alert setting saved.'),
			'registration_id' => $registrationId,
			'repeat_mode' => $repeatMode,
			'resolved_repeat_mode' => $resolvedMode,
		];
	}

	private function handleSaveNotes(): array {
		$registrationId = $this->positiveRequestId('registration_id');
		$notes = isset($_REQUEST['notes']) ? (string)$_REQUEST['notes'] : '';

		if ($registrationId <= 0) {
			return ['status' => false, 'message' => _('Missing watched registration.')];
		}

		$notes = trim(preg_replace('/\s+/', ' ', $notes));
		if (function_exists('mb_substr')) {
			$notes = mb_substr($notes, 0, 72);
		} else {
			$notes = substr($notes, 0, 72);
		}

		$now = $this->now();
		$notesUpdatedAt = $notes === '' ? null : $now;

		$db = $this->db();
		$extStmt = $db->prepare('SELECT extension FROM registrationwatch_registrations WHERE id = :id');
		$extStmt->execute([':id' => $registrationId]);
		$extension = (string)($extStmt->fetchColumn() ?? '');

		if ($extension === '') {
			return ['status' => false, 'message' => _('Missing watched registration.')];
		}

		$stmt = $db->prepare(
			'UPDATE registrationwatch_registrations
			SET notes = :notes,
				notes_updated_at = :notes_updated_at,
				updated_at = :updated_at
			WHERE extension = :extension'
		);
		$stmt->execute([
			':notes' => $notes,
			':notes_updated_at' => $notesUpdatedAt,
			':updated_at' => $now,
			':extension' => $extension,
		]);

		return [
			'status' => true,
			'message' => $notes === '' ? _('Registration note cleared.') : _('Registration note saved.'),
			'registration_id' => $registrationId,
			'notes' => $notes,
			'notes_updated_at' => $notesUpdatedAt,
		];
	}

	private function handleSaveShowLimit(): array {
		$showLimit = isset($_REQUEST['show_limit']) ? strtolower(trim((string)$_REQUEST['show_limit'])) : '6';
		$allowed = ['6', '30', '60', '120', 'all'];

		if (!in_array($showLimit, $allowed, true)) {
			$showLimit = '6';
		}

		$now = $this->now();
		$stmt = $this->db()->prepare(
			'INSERT INTO registrationwatch_settings (setting_key, setting_value, updated_at)
			VALUES (:setting_key, :setting_value, :updated_at)
			ON DUPLICATE KEY UPDATE
				setting_value = VALUES(setting_value),
				updated_at = VALUES(updated_at)'
		);
		$stmt->execute([
			':setting_key' => 'ui_show_limit',
			':setting_value' => $showLimit,
			':updated_at' => $now,
		]);

		return [
			'status' => true,
			'message' => _('Show limit saved.'),
			'show_limit' => $showLimit,
		];
	}

	private function handleSaveUiSetting(): array {
		$dirs = ['asc', 'desc'];
		$rules = [
			'ui_map_view_mode'              => ['card', 'row'],
			'ui_map_sort_key'               => ['extension', 'status', 'description', 'device_ip', 'network_ip', 'device', 'contact_expires', 'qualify', 'latency'],
			'ui_map_sort_dir'               => $dirs,
			'ui_watched_sort_key'           => ['monitored', 'extension', 'description', 'repeat', 'notes'],
			'ui_watched_sort_dir'           => $dirs,
			'ui_status_history_sort_key'    => ['time', 'extension', 'from', 'to', 'source', 'reason', 'latency'],
			'ui_status_history_sort_dir'    => $dirs,
			'ui_alert_history_sort_key'     => ['time', 'extension', 'type', 'status', 'recipient', 'result', 'error'],
			'ui_alert_history_sort_dir'     => $dirs,
		];
		$key = isset($_REQUEST['key']) ? (string)$_REQUEST['key'] : '';
		$value = isset($_REQUEST['value']) ? strtolower(trim((string)$_REQUEST['value'])) : '';
		if (!array_key_exists($key, $rules)) {
			return ['status' => false, 'message' => _('Unknown UI setting.')];
		}
		if (!in_array($value, $rules[$key], true)) {
			return ['status' => false, 'message' => _('Invalid value for UI setting.')];
		}
		$this->setSetting($key, $value);
		return ['status' => true];
	}

	private function handleSaveAlerts(): array {
		$recipientsRaw = isset($_REQUEST['alert_recipients']) ? (string)$_REQUEST['alert_recipients'] : '';
		$recipients = $this->normaliseRecipients($recipientsRaw);
		if ($recipientsRaw !== '' && !$recipients) {
			return ['status' => false, 'message' => _('Enter at least one valid email recipient.')];
		}
		$debounceSeconds = $this->normaliseAlertTimingSeconds('debounce_seconds', $this->settingsDefaults['debounce_seconds']);
		if ($debounceSeconds === null) {
			return ['status' => false, 'message' => _('Debounce delay must be a whole number from 0 to 86400 seconds.')];
		}
		$stormThreshold = $this->normaliseStormThreshold(isset($_REQUEST['storm_threshold']) ? (string)$_REQUEST['storm_threshold'] : $this->settingsDefaults['storm_threshold']);
		if ($stormThreshold === null) {
			return ['status' => false, 'message' => _('Storm Threshold must be a whole number from 0 to 10000.')];
		}

		$settings = [
			'alert_enabled' => !empty($_REQUEST['alert_enabled']) ? '1' : '0',
			'alert_recipients' => implode(', ', $recipients),
			'repeat_mode' => $this->normaliseRepeatMode(isset($_REQUEST['repeat_mode']) ? (string)$_REQUEST['repeat_mode'] : $this->settingsDefaults['repeat_mode']),
			'storm_threshold' => (string)$stormThreshold,
			'alert_on_unreachable' => !empty($_REQUEST['alert_on_unreachable']) ? '1' : '0',
			'alert_on_not_registered' => !empty($_REQUEST['alert_on_not_registered']) ? '1' : '0',
			'alert_on_recovery' => !empty($_REQUEST['alert_on_recovery']) ? '1' : '0',
			'debounce_seconds' => (string)$debounceSeconds,
		];

		foreach ($settings as $key => $value) {
			$this->setSetting($key, $value);
		}

		return [
			'status' => true,
			'message' => _('Alert settings saved.'),
			'alertSettings' => $this->getAlertSettings(),
		];
	}

	private function handleSaveTopology(): array {
		$vpnNetworks = isset($_REQUEST['trusted_vpn_networks']) ? (string)$_REQUEST['trusted_vpn_networks'] : '';
		$pollInterval = (int)($_REQUEST['topology_poll_interval_seconds'] ?? 10);

		// Enforce minimum 5 seconds if not disabled (0 or negative)
		if ($pollInterval > 0 && $pollInterval < 5) {
			$pollInterval = 5;
		} elseif ($pollInterval < 0) {
			$pollInterval = 0;
		}

		$settings = [
			'trusted_vpn_networks' => $vpnNetworks,
			'topology_poll_interval_seconds' => (string)$pollInterval,
		];

		foreach ($settings as $key => $value) {
			$this->setSetting($key, $value);
		}

		return [
			'status' => true,
			'message' => _('Topology settings saved.'),
			'alertSettings' => $this->getAlertSettings(),
		];
	}

	private function handleTestEmail(): array {
		$settings = $this->getAlertSettings();
		$recipients = $this->normaliseRecipients($settings['alert_recipients']);
		if (!$recipients) {
			return ['status' => false, 'message' => _('No valid alert recipients are configured.')];
		}

		$now = $this->now();
		$sent = 0;
		$failed = 0;
		foreach ($recipients as $recipient) {
			$subject = _('Registration Watch: test email');
			$message = "Registration Watch test email\n\nTime: " . $now . "\nSource: manual test\n\nPlease note: Email \"From:\" Address has been configured in Advanced Settings.\n";
			$result = $this->sendEmail($recipient, $subject, $message);
			if ($result['status']) {
				$sent++;
			} else {
				$failed++;
			}
			$this->insertAlertHistory([
				'extension' => '',
				'history_id' => null,
				'alert_type' => 'test',
				'status' => 'test',
				'recipient' => $recipient,
				'subject' => $subject,
				'message' => $message,
				'sent_at' => $now,
				'result' => $result['status'] ? 'sent' : 'failed',
				'error' => $result['status'] ? null : $result['message'],
			]);
		}

		$status = $sent > 0;
		if ($sent === 0) {
			$message = sprintf(_('Test email failed for %d recipient(s).'), $failed);
		} elseif ($failed > 0) {
			$message = sprintf(_('Test email accepted by local mailer for %d recipient(s); %d failed. Delivery is not confirmed.'), $sent, $failed);
		} else {
			$message = sprintf(_('Test email accepted by local mailer for %d recipient(s). Delivery is not confirmed.'), $sent);
		}

		return [
			'status' => $status,
			'message' => $message,
			'alertHistory' => $this->getAlertHistory(),
		];
	}

	private function handleGetTopology(): array {
		$this->reconcileIfStale($this->getPollInterval());
		try {
			return [
				'status' => true,
				'registrations' => $this->getRegistrationMapRows(),
				'watchedExtensions' => $this->getWatchedExtensionGroups(),
				'statusHistory' => $this->getStatusHistory(),
				'alertHistory' => $this->getAlertHistory(),
				'timeDiagnostics' => $this->getTimeDiagnostics(),
				'timestamp' => $this->now(),
				'monitoringState' => $this->getMonitoringState(),
			];
		} catch (\Exception $e) {
			$this->logWarning('Registration map retrieval failed: ' . $e->getMessage());
			return [
				'status' => false,
				'message' => _('Unable to load registration map.'),
				'registrations' => [],
				'statusHistory' => [],
				'alertHistory' => [],
				'timeDiagnostics' => $this->getTimeDiagnostics(),
			];
		}
	}

	private function handleSavePrunePolicy(): array {
		try {
			$historyType = isset($_REQUEST['history_type']) ? strtolower(trim((string)$_REQUEST['history_type'])) : '';
			$policy = $this->normalisePrunePolicy(isset($_REQUEST['policy']) ? (string)$_REQUEST['policy'] : 'never');
			$confirmed = !empty($_REQUEST['confirmed']);

			if (!in_array($historyType, ['status', 'alert'], true)) {
				return ['status' => false, 'message' => _('Unknown history table.')];
			}

			if ($policy !== 'never' && !$confirmed) {
				return ['status' => false, 'message' => _('Confirm permanent deletion before applying pruning.')];
			}

			$settingKey = $historyType === 'status' ? 'status_history_prune_policy' : 'alert_history_prune_policy';
			$this->setSetting($settingKey, $policy);

			$deleted = 0;
			if ($policy !== 'never') {
				$result = $this->applyHistoryPruning($historyType, $policy);
				$deleted = $result[$historyType] ?? 0;
			}

			$message = $policy === 'never'
				? _('History pruning disabled. No rows were deleted.')
				: sprintf(_('History pruning policy saved. Permanently deleted %d older row(s).'), $deleted);

			$response = [
				'status' => true,
				'message' => $message,
				'history_type' => $historyType,
				'policy' => $policy,
				'deleted' => $deleted,
				'pruneSettings' => $this->getPruneSettings(),
			];

			if ($historyType === 'status') {
				$response['statusHistory'] = $this->getStatusHistory();
			} else {
				$response['alertHistory'] = $this->getAlertHistory();
			}

			return $response;
		} catch (\Exception $e) {
			$this->logError('History pruning failed: ' . $e->getMessage());
			return ['status' => false, 'message' => _('Unable to update history pruning. Please check the system logs.')];
		}
	}

	private function handleDeleteStatusHistoryRow(): array {
		try {
			$id = $this->positiveRequestId('id');
			if ($id <= 0 || empty($_REQUEST['confirmed'])) {
				return ['status' => false, 'message' => _('Confirm the selected Status History row deletion.')];
			}

			$stmt = $this->db()->prepare('DELETE FROM registrationwatch_status_history WHERE id = :id LIMIT 1');
			$stmt->execute([':id' => $id]);
			$deleted = $stmt->rowCount();

			return [
				'status' => true,
				'message' => $deleted > 0 ? _('Status History row permanently deleted.') : _('Status History row was not found.'),
				'deleted' => $deleted,
				'statusHistory' => $this->getStatusHistory(),
			];
		} catch (\Exception $e) {
			$this->logError('Status History row delete failed: ' . $e->getMessage());
			return ['status' => false, 'message' => _('Unable to delete Status History row. Please check the system logs.')];
		}
	}

	private function handleDeleteAlertHistoryRow(): array {
		try {
			$id = $this->positiveRequestId('id');
			if ($id <= 0 || empty($_REQUEST['confirmed'])) {
				return ['status' => false, 'message' => _('Confirm the selected Alert History row deletion.')];
			}

			$stmt = $this->db()->prepare('DELETE FROM registrationwatch_alert_history WHERE id = :id LIMIT 1');
			$stmt->execute([':id' => $id]);
			$deleted = $stmt->rowCount();

			return [
				'status' => true,
				'message' => $deleted > 0 ? _('Alert History row permanently deleted.') : _('Alert History row was not found.'),
				'deleted' => $deleted,
				'alertHistory' => $this->getAlertHistory(),
			];
		} catch (\Exception $e) {
			$this->logError('Alert History row delete failed: ' . $e->getMessage());
			return ['status' => false, 'message' => _('Unable to delete Alert History row. Please check the system logs.')];
		}
	}

	private function handleSnoozeMonitoring(): array {
		$allowed = [300, 900, 1800, 3600, 86400];
		$seconds = isset($_REQUEST['seconds']) ? (int)$_REQUEST['seconds'] : 0;
		if (!in_array($seconds, $allowed, true)) {
			return ['status' => false, 'message' => _('Invalid snooze duration.')];
		}
		$until = date('Y-m-d H:i:s', strtotime($this->now()) + $seconds);
		$this->setSetting('monitoring_snoozed_until', $until);
		return [
			'status' => true,
			'message' => _('Monitoring snoozed.'),
			'monitoringState' => $this->getMonitoringState(),
		];
	}

	private function handleResumeMonitoring(): array {
		$this->setSetting('monitoring_snoozed_until', '');
		return [
			'status' => true,
			'message' => _('Monitoring resumed.'),
			'monitoringState' => $this->getMonitoringState(),
		];
	}

	private function getPageData(bool $refreshStatus): array {
		$refreshError = '';
		if ($refreshStatus) {
			try {
				$this->reconcileCurrentStatus();
			} catch (\Exception $e) {
				$this->logError('Status reconciliation failed: ' . $e->getMessage());
				$refreshError = _('Unable to reconcile registration status. Please check the system logs.');
			}
		}

		return [
			'registrations' => $this->getStoredRegistrations(),
			'statusHistory' => $this->getStatusHistory(),
			'alertSettings' => $this->getAlertSettings(),
			'alertHistory' => $this->getAlertHistory(),
			'lastRefresh' => $this->getLastRefreshTime(),
			'emailStatus' => $this->getEmailStatus(),
			'timeDiagnostics' => $this->getTimeDiagnostics(),
			'pollIntervalSeconds' => $this->getPollInterval(),
			'refreshError' => $refreshError,
			'monitoringState' => $this->getMonitoringState(),
		];
	}

	private function getTimeDiagnostics(): array {
		$moduleTime = $this->now();
		$databaseTime = '';

		try {
			$stmt = $this->db()->query('SELECT NOW()');
			$databaseTime = $stmt ? (string)$stmt->fetchColumn() : '';
		} catch (\Throwable $e) {
			$this->logWarning('Database time diagnostic unavailable: ' . $e->getMessage());
		}

		return [
			'module_time' => $moduleTime,
			'database_time' => $databaseTime,
		];
	}

	private function getPollInterval(): int {
		$settings = $this->getAlertSettings();
		$interval = (int)($settings['topology_poll_interval_seconds'] ?? 10);
		// Enforce minimum 5 seconds, default 10 seconds
		if ($interval < 5 && $interval > 0) {
			return 5;
		}
		// 0 or negative means disabled
		if ($interval <= 0) {
			return 0;
		}
		return $interval;
	}
	private function syncDiscoveredRegistrations(?array $liveContacts = null, ?array $allowedDevices = null): void {
		$now = $this->now();
		$allowedDevices = $allowedDevices === null ? $this->getAllowedPjsipDeviceIds() : $allowedDevices;
		$liveContacts = $liveContacts === null ? $this->getLiveContacts($allowedDevices) : $liveContacts;
		$liveContactKeys = array_fill_keys(array_keys($liveContacts), true);
		$liveKeysByExtension = [];
		foreach ($liveContacts as $liveContact) {
			$liveExtension = $this->normaliseRegistrationExtension((string)($liveContact['extension'] ?? ''));
			if ($liveExtension === '') {
				continue;
			}
			$liveKeysByExtension[$liveExtension][] = (string)($liveContact['registration_key'] ?? '');
		}
		$descriptions = $this->getRegistrationDescriptions();
		$liveExtensions = [];
		$db = $this->db();

		foreach ($liveContacts as $registration) {
			$extension = (string)$registration['extension'];
			$liveExtensions[$this->normaliseRegistrationExtension($extension)] = true;
			$stmt = $db->prepare('SELECT id FROM registrationwatch_registrations WHERE registration_key = :registration_key');
			$stmt->execute([':registration_key' => $registration['registration_key']]);
			$id = $stmt->fetchColumn();

			if ($id) {
				$update = $db->prepare(
					'UPDATE registrationwatch_registrations
					SET description = :description,
						discovered = 1,
						enabled = CASE WHEN auto_disabled_absent_at IS NOT NULL THEN 1 ELSE enabled END,
						auto_disabled_absent_at = NULL,
						contact_uri = :contact_uri,
						source_ip = :source_ip,
						source_port = :source_port,
						registration_ua_class = :registration_ua_class,
						transport = :transport,
						user_agent = :user_agent,
						device_name = :device_name,
						firmware_version = :firmware_version,
						contact_count = :contact_count,
						contact_expires_at = :contact_expires_at,
						qualify_frequency = :qualify_frequency,
						last_discovered_at = :last_discovered_at,
						updated_at = :updated_at
					WHERE registration_key = :registration_key'
				);
				$update->execute([
					':description' => $descriptions[$extension] ?? '',
					':contact_uri' => $registration['contact_uri'] ?? null,
					':source_ip' => $registration['source_ip'] ?? null,
					':source_port' => $registration['source_port'] ?? null,
					':registration_ua_class' => $registration['registration_ua_class'] ?? '',
					':transport' => $registration['transport'] ?? null,
					':user_agent' => $registration['user_agent'] ?? null,
					':device_name' => $registration['device_name'] ?? null,
					':firmware_version' => $registration['firmware_version'] ?? null,
					':contact_count' => max(1, (int)($registration['contact_count'] ?? 1)),
					':contact_expires_at' => $registration['contact_expires_at'] ?? null,
					':qualify_frequency' => $registration['qualify_frequency'] ?? null,
					':last_discovered_at' => $now,
					':updated_at' => $now,
					':registration_key' => $registration['registration_key'],
				]);
				continue;
			}

			$placeholderId = $this->placeholderRegistrationId($extension);
			if ($placeholderId > 0) {
				$update = $db->prepare(
					'UPDATE registrationwatch_registrations
					SET registration_key = :registration_key,
						description = :description,
						discovered = 1,
						enabled = CASE WHEN auto_disabled_absent_at IS NOT NULL THEN 1 ELSE enabled END,
						auto_disabled_absent_at = NULL,
						contact_uri = :contact_uri,
						source_ip = :source_ip,
						source_port = :source_port,
						registration_ua_class = :registration_ua_class,
						transport = :transport,
						user_agent = :user_agent,
						device_name = :device_name,
						firmware_version = :firmware_version,
						contact_count = :contact_count,
						contact_expires_at = :contact_expires_at,
						qualify_frequency = :qualify_frequency,
						last_discovered_at = :last_discovered_at,
						updated_at = :updated_at
					WHERE id = :id'
				);
				$update->execute([
					':registration_key' => $registration['registration_key'],
					':description' => $descriptions[$extension] ?? '',
					':contact_uri' => $registration['contact_uri'] ?? null,
					':source_ip' => $registration['source_ip'] ?? null,
					':source_port' => $registration['source_port'] ?? null,
					':registration_ua_class' => $registration['registration_ua_class'] ?? '',
					':transport' => $registration['transport'] ?? null,
					':user_agent' => $registration['user_agent'] ?? null,
					':device_name' => $registration['device_name'] ?? null,
					':firmware_version' => $registration['firmware_version'] ?? null,
					':contact_count' => max(1, (int)($registration['contact_count'] ?? 1)),
					':contact_expires_at' => $registration['contact_expires_at'] ?? null,
					':qualify_frequency' => $registration['qualify_frequency'] ?? null,
					':last_discovered_at' => $now,
					':updated_at' => $now,
					':id' => $placeholderId,
				]);
				continue;
			}

			$deadCandidateDiagnostics = $this->singleDeadCandidateDiagnostics($extension, (string)$registration['registration_key'], $liveKeysByExtension[$this->normaliseRegistrationExtension($extension)] ?? []);
			$this->logSingleDeadCandidateDiagnostics($deadCandidateDiagnostics);

			$deadRegistrationId = $this->singleDeadRegistrationId($extension, $liveContactKeys);
			if ($deadRegistrationId <= 0) {
				$this->logInfo('Registration Watch continuity reuse rejected: ' . ($deadCandidateDiagnostics['rejection_reason'] ?? 'unknown'));
			}
			if ($deadRegistrationId > 0) {
				$update = $db->prepare(
					'UPDATE registrationwatch_registrations
					SET registration_key = :registration_key,
						description = :description,
						discovered = 1,
						enabled = CASE WHEN auto_disabled_absent_at IS NOT NULL THEN 1 ELSE enabled END,
						auto_disabled_absent_at = NULL,
						contact_uri = :contact_uri,
						source_ip = :source_ip,
						source_port = :source_port,
						registration_ua_class = :registration_ua_class,
						transport = :transport,
						user_agent = :user_agent,
						device_name = :device_name,
						firmware_version = :firmware_version,
						contact_count = :contact_count,
						contact_expires_at = :contact_expires_at,
						qualify_frequency = :qualify_frequency,
						last_discovered_at = :last_discovered_at,
						updated_at = :updated_at
					WHERE id = :id'
				);
				$update->execute([
					':registration_key' => $registration['registration_key'],
					':description' => $descriptions[$extension] ?? '',
					':contact_uri' => $registration['contact_uri'] ?? null,
					':source_ip' => $registration['source_ip'] ?? null,
					':source_port' => $registration['source_port'] ?? null,
					':registration_ua_class' => $registration['registration_ua_class'] ?? '',
					':transport' => $registration['transport'] ?? null,
					':user_agent' => $registration['user_agent'] ?? null,
					':device_name' => $registration['device_name'] ?? null,
					':firmware_version' => $registration['firmware_version'] ?? null,
					':contact_count' => max(1, (int)($registration['contact_count'] ?? 1)),
					':contact_expires_at' => $registration['contact_expires_at'] ?? null,
					':qualify_frequency' => $registration['qualify_frequency'] ?? null,
					':last_discovered_at' => $now,
					':updated_at' => $now,
					':id' => $deadRegistrationId,
				]);
				continue;
			}

			$this->logInfo('Registration Watch continuity fallback insert enabled=0 for extension ' . $this->normaliseRegistrationExtension($extension) . ', registration_key ' . (string)$registration['registration_key']);

			$insert = $db->prepare(
				'INSERT INTO registrationwatch_registrations
					(registration_key, extension, description, enabled, discovered, last_known_status, contact_uri,
					 source_ip, source_port, registration_ua_class, transport, user_agent, device_name, firmware_version,
					 contact_count, contact_expires_at, qualify_frequency, created_at, updated_at, first_discovered_at, last_discovered_at)
				VALUES
					(:registration_key, :extension, :description, 0, 1, :last_known_status, :contact_uri,
					 :source_ip, :source_port, :registration_ua_class, :transport, :user_agent, :device_name, :firmware_version,
					 :contact_count, :contact_expires_at, :qualify_frequency, :created_at, :updated_at, :first_discovered_at, :last_discovered_at)'
			);
			$insert->execute([
				':registration_key' => $registration['registration_key'],
				':extension' => $extension,
				':description' => $descriptions[$extension] ?? '',
				':last_known_status' => self::STATUS_UNKNOWN,
				':contact_uri' => $registration['contact_uri'] ?? null,
				':source_ip' => $registration['source_ip'] ?? null,
				':source_port' => $registration['source_port'] ?? null,
				':registration_ua_class' => $registration['registration_ua_class'] ?? '',
				':transport' => $registration['transport'] ?? null,
				':user_agent' => $registration['user_agent'] ?? null,
				':device_name' => $registration['device_name'] ?? null,
				':firmware_version' => $registration['firmware_version'] ?? null,
				':contact_count' => max(1, (int)($registration['contact_count'] ?? 1)),
				':contact_expires_at' => $registration['contact_expires_at'] ?? null,
				':qualify_frequency' => $registration['qualify_frequency'] ?? null,
				':created_at' => $now,
				':updated_at' => $now,
				':first_discovered_at' => $now,
				':last_discovered_at' => $now,
			]);
		}

		$this->seedMissingDeviceRegistrations($allowedDevices, $liveExtensions, $descriptions, $now);
	}

	private function seedMissingDeviceRegistrations(array $allowedDevices, array $liveExtensions, array $descriptions, string $now): void {
		$knownCounts = $this->storedRegistrationCountsByExtension();
		foreach (array_keys($allowedDevices) as $extension) {
			if (isset($liveExtensions[$extension]) || ($knownCounts[$extension] ?? 0) > 0) {
				continue;
			}

			$stmt = $this->db()->prepare(
				'INSERT IGNORE INTO registrationwatch_registrations
					(registration_key, extension, description, enabled, discovered, last_known_status,
					 contact_count, created_at, updated_at, first_discovered_at, last_discovered_at)
				VALUES
					(:registration_key, :extension, :description, 0, 1, :last_known_status,
					 1, :created_at, :updated_at, :first_discovered_at, :last_discovered_at)'
			);
			$stmt->execute([
				':registration_key' => $this->noContactRegistrationKey($extension),
				':extension' => $extension,
				':description' => $descriptions[$extension] ?? '',
				':last_known_status' => self::STATUS_UNKNOWN,
				':created_at' => $now,
				':updated_at' => $now,
				':first_discovered_at' => $now,
				':last_discovered_at' => $now,
			]);
		}
	}

	private function storedRegistrationCountsByExtension(): array {
		$stmt = $this->db()->query('SELECT extension, COUNT(*) AS total FROM registrationwatch_registrations GROUP BY extension');
		$counts = [];
		foreach ($stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [] as $row) {
			$extension = $this->normaliseRegistrationExtension((string)($row['extension'] ?? ''));
			if ($extension !== '') {
				$counts[$extension] = (int)($row['total'] ?? 0);
			}
		}

		return $counts;
	}

	private function hasStoredRegistrations(): bool {
		if (!$this->tableExists('registrationwatch_registrations')) {
			return false;
		}

		$stmt = $this->db()->query('SELECT COUNT(*) FROM registrationwatch_registrations');
		return $stmt ? (int)$stmt->fetchColumn() > 0 : false;
	}

	private function shouldDeferForEmptyAllowlist(array $allowedDevices): bool {
		return !$allowedDevices && $this->hasStoredRegistrations();
	}

	private function placeholderRegistrationId(string $extension): int {
		$stmt = $this->db()->prepare(
			'SELECT id
			FROM registrationwatch_registrations
			WHERE registration_key = :registration_key
				AND (source_ip IS NULL OR source_ip = "")
			LIMIT 1'
		);
		$stmt->execute([':registration_key' => $this->noContactRegistrationKey($extension)]);
		$id = $stmt->fetchColumn();

		return $id ? (int)$id : 0;
	}

	private function singleDeadRegistrationId(string $extension, ?array $liveContactKeys = null): int {
		$normalisedExtension = $this->normaliseRegistrationExtension($extension);
		if ($normalisedExtension === '') {
			return 0;
		}

		if ($liveContactKeys === null) {
			$stmt = $this->db()->prepare(
				'SELECT id
				FROM registrationwatch_registrations
				WHERE extension = :extension
					AND last_known_status = :status
				ORDER BY id ASC
				LIMIT 2'
			);
			$stmt->execute([
				':extension' => $normalisedExtension,
				':status' => self::STATUS_NOT_REGISTERED,
			]);
			$ids = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

			return count($ids) === 1 ? (int)$ids[0] : 0;
		}

		$placeholderKey = $this->noContactRegistrationKey($normalisedExtension);
		$params = [
			':extension' => $normalisedExtension,
			':placeholder_key' => $placeholderKey,
		];
		$sql =
			'SELECT id
			FROM registrationwatch_registrations
			WHERE extension = :extension
				AND discovered = 1
				AND source_ip IS NOT NULL AND source_ip <> ""
				AND registration_key <> :placeholder_key';

		if ($liveContactKeys) {
			$placeholders = [];
			$index = 0;
			foreach (array_keys($liveContactKeys) as $liveKey) {
				$key = ':live_key_' . $index;
				$placeholders[] = $key;
				$params[$key] = (string)$liveKey;
				$index++;
			}
			$sql .= ' AND registration_key NOT IN (' . implode(', ', $placeholders) . ')';
		}

		$sql .= ' ORDER BY id ASC LIMIT 2';
		$stmt = $this->db()->prepare($sql);
		$stmt->execute($params);
		$ids = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

		return count($ids) === 1 ? (int)$ids[0] : 0;
	}

	private function singleDeadCandidateDiagnostics(string $extension, string $newRegistrationKey, array $extensionLiveKeys): array {
		$normalisedExtension = $this->normaliseRegistrationExtension($extension);
		$placeholderKey = $this->noContactRegistrationKey($normalisedExtension);

		$stmt = $this->db()->prepare(
			'SELECT id, registration_key, source_ip, discovered, last_known_status, enabled
			FROM registrationwatch_registrations
			WHERE extension = :extension
			ORDER BY id ASC'
		);
		$stmt->execute([':extension' => $normalisedExtension]);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		$liveKeysSet = [];
		foreach ($extensionLiveKeys as $liveKey) {
			$liveKeysSet[(string)$liveKey] = true;
		}

		$candidateCount = 0;
		$predicateMisses = [
			'discovered' => 0,
			'source_ip' => 0,
			'placeholder' => 0,
			'live_key_conflict' => 0,
		];
		$rowDiagnostics = [];

		foreach ($rows as $row) {
			$rowKey = (string)($row['registration_key'] ?? '');
			$hasSourceIp = trim((string)($row['source_ip'] ?? '')) !== '';
			$isDiscovered = (int)($row['discovered'] ?? 0) === 1;
			$isPlaceholder = $rowKey === $placeholderKey;
			$isLiveKey = isset($liveKeysSet[$rowKey]);

			$predicate = [
				'discovered' => $isDiscovered,
				'has_source_ip' => $hasSourceIp,
				'not_placeholder' => !$isPlaceholder,
				'not_live_key' => !$isLiveKey,
			];

			$eligible = $predicate['discovered']
				&& $predicate['has_source_ip']
				&& $predicate['not_placeholder']
				&& $predicate['not_live_key'];

			if ($eligible) {
				$candidateCount++;
			} else {
				if (!$predicate['discovered']) {
					$predicateMisses['discovered']++;
				}
				if (!$predicate['has_source_ip']) {
					$predicateMisses['source_ip']++;
				}
				if (!$predicate['not_placeholder']) {
					$predicateMisses['placeholder']++;
				}
				if (!$predicate['not_live_key']) {
					$predicateMisses['live_key_conflict']++;
				}
			}

			$rowDiagnostics[] = [
				'id' => (int)($row['id'] ?? 0),
				'registration_key' => $rowKey,
				'source_ip' => $row['source_ip'] ?? null,
				'discovered' => (int)($row['discovered'] ?? 0),
				'is_placeholder' => $isPlaceholder,
				'last_known_status' => (string)($row['last_known_status'] ?? ''),
				'enabled' => (int)($row['enabled'] ?? 0),
				'predicates' => $predicate,
				'eligible' => $eligible,
			];
		}

		$rejectionReason = 'single dead candidate found';
		if ($candidateCount === 0) {
			$reasons = [];
			if ($predicateMisses['discovered'] > 0) {
				$reasons[] = 'rows not discovered=' . $predicateMisses['discovered'];
			}
			if ($predicateMisses['source_ip'] > 0) {
				$reasons[] = 'rows missing source_ip=' . $predicateMisses['source_ip'];
			}
			if ($predicateMisses['placeholder'] > 0) {
				$reasons[] = 'placeholder rows=' . $predicateMisses['placeholder'];
			}
			if ($predicateMisses['live_key_conflict'] > 0) {
				$reasons[] = 'rows still live in current keyset=' . $predicateMisses['live_key_conflict'];
			}
			$rejectionReason = 'no dead candidates; ' . ($reasons ? implode(', ', $reasons) : 'no rows matched predicate set');
		} elseif ($candidateCount > 1) {
			$rejectionReason = 'ambiguous dead candidates count=' . $candidateCount;
		}

		return [
			'extension' => $normalisedExtension,
			'new_registration_key' => $newRegistrationKey,
			'live_keys_for_extension' => array_values(array_unique(array_filter(array_map('strval', $extensionLiveKeys), function ($key) {
				return $key !== '';
			}))),
			'rows' => $rowDiagnostics,
			'candidate_count' => $candidateCount,
			'rejection_reason' => $rejectionReason,
		];
	}

	private function logSingleDeadCandidateDiagnostics(array $diagnostics): void {
		$this->logInfo('Registration Watch continuity candidate diagnostics: ' . json_encode($diagnostics));
	}

	private function getPjsipRegistrationTargets(): array {
		if (!$this->tableExists('pjsip')) {
			return [];
		}

		$stmt = $this->db()->query("SELECT DISTINCT id FROM pjsip WHERE keyword = 'type' AND data = 'endpoint'");
		$ids = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) : [];
		$registrations = [];

		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id !== '') {
				$registrations[$id] = true;
			}
		}

		return $registrations;
	}

	private function getAllowedPjsipDeviceIds(): array {
		if (!$this->tableExists('devices')) {
			return [];
		}

		$stmt = $this->db()->query("SELECT id FROM devices WHERE LOWER(tech) = 'pjsip' AND id <> ''");
		$ids = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) : [];
		$allowed = [];

		foreach ($ids as $id) {
			$id = $this->normaliseRegistrationExtension((string)$id);
			if ($id !== '') {
				$allowed[$id] = true;
			}
		}

		return $allowed;
	}

	private function getRegistrationDescriptions(): array {
		$db = $this->db();
		$descriptions = [];

		if ($this->tableExists('users')) {
			$stmt = $db->query("SELECT extension, name FROM users WHERE extension <> ''");
			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$descriptions[(string)$row['extension']] = (string)$row['name'];
			}
		}

		if ($this->tableExists('devices')) {
			$stmt = $db->query("SELECT id, description FROM devices WHERE LOWER(tech) = 'pjsip' AND id <> ''");
			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$id = (string)$row['id'];
				if (!isset($descriptions[$id]) || $descriptions[$id] === '') {
					$descriptions[$id] = (string)$row['description'];
				}
			}
		}

		return $descriptions;
	}

	private function reconcileIfStale(int $maxAgeSeconds): void {
		$threshold = max(5, $maxAgeSeconds);
		try {
			$stmt = $this->db()->query(
				'SELECT MAX(last_checked_at) FROM registrationwatch_registrations WHERE discovered = 1'
			);
			$latest = $stmt ? $stmt->fetchColumn() : null;
			if ($latest !== null && $latest !== false && $latest !== '') {
				$nowTs = strtotime($this->now());
				$latestTs = strtotime((string)$latest);
				if ($latestTs !== false && ($nowTs - $latestTs) < $threshold) {
					return;
				}
			}
			$this->reconcileCurrentStatus();
		} catch (\Throwable $e) {
			$this->logWarning('Registration Watch stale-guard reconciliation failed: ' . $e->getMessage());
		}
	}

	private function reconcileCurrentStatus(): void {
		if (!$this->acquireReconcileLock()) {
			$this->logWarning('Registration Watch reconcile skipped because another reconcile is already running.');
			return;
		}

		try {
			$this->reconcileCurrentStatusLocked();
		} finally {
			$this->releaseReconcileLock();
		}
	}

	private function reconcileCurrentStatusLocked(): void {
		$allowedDevices = $this->getAllowedPjsipDeviceIds();
		if ($this->shouldDeferForEmptyAllowlist($allowedDevices)) {
			$this->logWarning('Registration Watch PJSIP device allowlist is empty while watched registrations exist; reconciliation deferred to avoid disabling configured devices.');
			return;
		}

		$contacts = $this->getLiveContacts($allowedDevices);
		$this->syncDiscoveredRegistrations($contacts, $allowedDevices);
		$now = $this->now();
		$db = $this->db();
		$registrations = $this->getStoredRegistrations();
		$settings = $this->getAlertSettings();
		$autoDisableAbsentSeconds = $this->autoDisableAbsentSeconds($settings);

		foreach ($registrations as $registration) {
			if ((int)$registration['discovered'] === 0) {
				continue;
			}

			$registrationId = (int)$registration['id'];
			$extension = $this->normaliseRegistrationExtension((string)($registration['extension'] ?? ''));
			if (!isset($allowedDevices[$extension])) {
				$this->disableNonDeviceRegistration($registrationId, $now);
				continue;
			}

			$previousStatus = $this->normaliseState($registration['last_known_status'] ?? self::STATUS_UNKNOWN);
			$previousContactUri = $registration['contact_uri'] ?? null;
			$previousHadContact = $this->hadRegisteredState($previousStatus) || (string)($registration['contact_uri'] ?? '') !== '';
			$registrationKey = (string)$registration['registration_key'];
			$contact = $contacts[$registrationKey] ?? null;
			$status = self::STATUS_NOT_REGISTERED;
			$contactUri = null;
			$latency = null;
			$sourceIp = $registration['source_ip'] ?? null;
			$sourcePort = $registration['source_port'] ?? null;
			$userAgent = $registration['user_agent'] ?? null;
			$deviceName = $registration['device_name'] ?? null;
			$firmwareVersion = $registration['firmware_version'] ?? null;
			$contactExpiresAt = $registration['contact_expires_at'] ?? null;
			$qualifyFrequency = $registration['qualify_frequency'] ?? null;
			$contactCount = max(1, (int)($registration['contact_count'] ?? 1));
			$registrationUaClass = $registration['registration_ua_class'] ?? '';
			$lastSeen = $registration['last_seen_at'] ?: null;
			$enabled = (int)($registration['enabled'] ?? 0);
			$autoDisabledAbsentAt = $registration['auto_disabled_absent_at'] ?? null;

			if ($contact !== null) {
				$status = $contact['status'];
				$contactUri = $contact['contact_uri'];
				$latency = $contact['latency_ms'];
				$sourceIp = $contact['source_ip'] ?? null;
				$sourcePort = $contact['source_port'] ?? null;
				$userAgent = $contact['user_agent'] ?? $userAgent;
				$deviceName = $contact['device_name'] ?? $deviceName;
				$firmwareVersion = $contact['firmware_version'] ?? $firmwareVersion;
				$contactExpiresAt = $contact['contact_expires_at'] ?? $contactExpiresAt;
				$qualifyFrequency = $contact['qualify_frequency'] ?? $qualifyFrequency;
				$contactCount = max(1, (int)($contact['contact_count'] ?? 1));
				$registrationUaClass = $contact['registration_ua_class'] ?? $registrationUaClass;
				if ($contactUri !== '') {
					$lastSeen = $now;
				}
				if ($autoDisabledAbsentAt !== null && $autoDisabledAbsentAt !== '') {
					$enabled = 1;
					$autoDisabledAbsentAt = null;
				}
			} elseif ($this->shouldAutoDisableAbsentRegistration($registration, $autoDisableAbsentSeconds, $now)) {
				$enabled = 0;
				$autoDisabledAbsentAt = $autoDisabledAbsentAt ?: $now;
				$this->deleteEscalationsForRegistration($registrationId);
			}

			if ($previousStatus !== $status) {
				$historyContactUri = $contactUri;
				if ($status === self::STATUS_NOT_REGISTERED && trim((string)$previousContactUri) !== '') {
					$historyContactUri = $previousContactUri;
				}

				$this->insertStatusHistory(
					$registrationId,
					$registrationKey,
					(string)$registration['extension'],
					$previousStatus,
					$status,
					$this->historyReason($previousHadContact, $status),
					$historyContactUri,
					$latency,
					$now
				);
			}

			$stmt = $db->prepare(
				'UPDATE registrationwatch_registrations
				SET last_known_status = :last_known_status,
					enabled = :enabled,
					auto_disabled_absent_at = :auto_disabled_absent_at,
					contact_uri = :contact_uri,
					latency_ms = :latency_ms,
					source_ip = :source_ip,
					source_port = :source_port,
					registration_ua_class = :registration_ua_class,
					user_agent = :user_agent,
					device_name = :device_name,
					firmware_version = :firmware_version,
					contact_count = :contact_count,
					contact_expires_at = :contact_expires_at,
					qualify_frequency = :qualify_frequency,
					last_seen_at = :last_seen_at,
					last_checked_at = :last_checked_at,
					updated_at = :updated_at
				WHERE id = :id'
			);
			$stmt->execute([
				':last_known_status' => $status,
				':enabled' => $enabled,
				':auto_disabled_absent_at' => $autoDisabledAbsentAt,
				':contact_uri' => $contactUri,
				':latency_ms' => $latency,
				':source_ip' => $sourceIp,
				':source_port' => $sourcePort,
				':registration_ua_class' => $registrationUaClass,
				':user_agent' => $userAgent,
				':device_name' => $deviceName,
				':firmware_version' => $firmwareVersion,
				':contact_count' => $contactCount,
				':contact_expires_at' => $contactExpiresAt,
				':qualify_frequency' => $qualifyFrequency,
				':last_seen_at' => $lastSeen,
				':last_checked_at' => $now,
				':updated_at' => $now,
				':id' => $registrationId,
			]);
		}

		$this->processAlertQueue($now);
	}

	private function disableNonDeviceRegistration(int $registrationId, string $now): void {
		$this->deleteEscalationsForRegistration($registrationId);
		$stmt = $this->db()->prepare(
			'UPDATE registrationwatch_registrations
			SET enabled = 0,
				discovered = 0,
				updated_at = :updated_at
			WHERE id = :id
				AND (enabled <> 0 OR discovered <> 0)'
		);
		$stmt->execute([
			':updated_at' => $now,
			':id' => $registrationId,
		]);
		if ($stmt->rowCount() > 0) {
			$this->logInfo('Ignored stored registration because it is not a configured PJSIP device: id ' . $registrationId);
		}
	}

	private function acquireReconcileLock(): bool {
		try {
			$stmt = $this->db()->query("SELECT GET_LOCK('registrationwatch_reconcile', 0)");
			return $stmt ? (int)$stmt->fetchColumn() === 1 : false;
		} catch (\Throwable $e) {
			$this->logWarning('Registration Watch reconcile lock unavailable: ' . $e->getMessage());
			return false;
		}
	}

	private function releaseReconcileLock(): void {
		try {
			$this->db()->query("SELECT RELEASE_LOCK('registrationwatch_reconcile')");
		} catch (\Throwable $e) {
			$this->logWarning('Registration Watch reconcile lock release failed: ' . $e->getMessage());
		}
	}

	private function autoDisableAbsentSeconds(array $settings): int {
		$value = isset($settings['auto_disable_absent_seconds'])
			? trim((string)$settings['auto_disable_absent_seconds'])
			: (string)self::AUTO_DISABLE_ABSENT_DEFAULT_SECONDS;
		if ($value === '' || !ctype_digit($value)) {
			return self::AUTO_DISABLE_ABSENT_DEFAULT_SECONDS;
		}

		return max(0, (int)$value);
	}

	private function shouldAutoDisableAbsentRegistration(array $registration, int $thresholdSeconds, string $now): bool {
		if ($thresholdSeconds <= 0 || (int)($registration['enabled'] ?? 0) !== 1) {
			return false;
		}
		if (!empty($registration['auto_disabled_absent_at'])) {
			return false;
		}

		$lastSeen = trim((string)($registration['last_seen_at'] ?? ''));
		if ($lastSeen === '') {
			return false;
		}

		$lastSeenTs = strtotime($lastSeen);
		$nowTs = strtotime($now);
		if ($lastSeenTs === false || $nowTs === false) {
			return false;
		}

		return ($nowTs - $lastSeenTs) >= $thresholdSeconds;
	}

	private function getLiveContacts(?array $allowedDevices = null): array {
		$output = $this->runAsteriskCommand('pjsip show contacts');
		if (trim($output) === '') {
			throw new \Exception(_('Registration Watch could not query Asterisk. Confirm the FreePBX AMI user has Command privilege.'));
		}

		$contacts = [];
		$pending = [];
		$allowedDevices = $allowedDevices === null ? $this->getAllowedPjsipDeviceIds() : $allowedDevices;
		if ($this->shouldDeferForEmptyAllowlist($allowedDevices)) {
			throw new \Exception(_('Registration Watch PJSIP device allowlist is unavailable; deferring status reconciliation.'));
		}
		$registrarDetails = $this->getRegistrarContactDetails();
		$existingIdentityClasses = $this->getStoredRegistrationIdentityClasses();
		$lineCount = 0;
		$parsedCount = 0;
		$acceptedCount = 0;
		$ignoredNonDeviceCount = 0;
		$failureCount = 0;
		$firstFailedLine = null;

		foreach (preg_split('/\r\n|\r|\n/', $output) as $line) {
			if (trim($line) === '') {
				continue;
			}

			$lineCount++;
			$parsed = $this->parseContactLine($line);
			if ($parsed === null) {
				// Track first failed parse for diagnostic logging
				if (strpos($line, 'Contact:') !== false) {
					$failureCount++;
					if ($firstFailedLine === null) {
						$firstFailedLine = substr($line, 0, 100);
					}
				}
				continue;
			}

			$parsedCount++;
			if (!isset($allowedDevices[$parsed['extension']])) {
				$ignoredNonDeviceCount++;
				continue;
			}

			$parsed = $this->enrichLiveContactFromRegistrar($parsed, $registrarDetails);
			if ($this->normaliseSourceIp($parsed['source_ip'] ?? '') === '') {
				$this->logWarning('PJSIP contact skipped because Registration Watch could not resolve a source IP: ' . substr($line, 0, 100));
				continue;
			}
			$identityGroup = $this->registrationIdentityGroupKey($parsed['extension'], $parsed['source_ip']);
			$pending[$identityGroup][] = $parsed;
			$acceptedCount++;
		}

		foreach ($pending as $identityGroup => $items) {
			foreach ($this->resolveLiveContactIdentityGroup($items, $existingIdentityClasses[$identityGroup] ?? []) as $parsed) {
				$key = $parsed['registration_key'];
				$existing = $contacts[$key] ?? null;
				if ($existing === null) {
					$contacts[$key] = $parsed;
					continue;
				}

				$parsed['contact_count'] = ((int)($existing['contact_count'] ?? 1)) + 1;
				$contacts[$key] = $this->preferredLiveContact($existing, $parsed);
			}
		}

		// Log diagnostics only if there were parse failures (avoid log spam on success)
		if ($lineCount > 0 && $failureCount > 0) {
			$this->logWarning('PJSIP contact parsing: ' . $parsedCount . ' parsed, ' . $failureCount . ' failed. Example: ' . $firstFailedLine);
		}
		$this->logInfo(sprintf(
			'PJSIP live contacts seen: %d, accepted: %d, ignored as non-device: %d.',
			$parsedCount,
			$acceptedCount,
			$ignoredNonDeviceCount
		));

		return $contacts;
	}

	private function resolveLiveContactIdentityGroup(array $items, array $existingState): array {
		$usableClasses = [];
		foreach ($items as $item) {
			$uaClass = $this->normaliseUserAgentClass($item['user_agent'] ?? null);
			if ($uaClass !== '') {
				$usableClasses[$uaClass] = true;
			}
		}

		ksort($usableClasses, SORT_STRING);
		$splitClasses = [];
		$existingClasses = $existingState['classes'] ?? [];
		$existingShared = $existingState['shared'] ?? [];
		$existingNonShared = array_values(array_filter(array_map('strval', $existingClasses), function ($uaClass) {
			return $uaClass !== '';
		}));
		if (count($usableClasses) > 1) {
			$splitClasses = array_fill_keys(array_keys($usableClasses), true);
		} elseif (count($usableClasses) === 1 && $existingNonShared) {
			$splitClasses = array_fill_keys(array_unique(array_merge(array_keys($usableClasses), $existingNonShared)), true);
		}

		$sharedAnchorIndex = null;
		if ($splitClasses && $existingShared) {
			$sharedAnchorIndex = $this->sharedRegistrationAnchorIndex($items, $existingShared);
		}

		foreach ($items as $index => $item) {
			$uaClass = $this->normaliseUserAgentClass($item['user_agent'] ?? null);
			$resolvedClass = '';
			if ($sharedAnchorIndex !== $index && $uaClass !== '' && isset($splitClasses[$uaClass])) {
				$resolvedClass = $uaClass;
			}
			$items[$index]['registration_ua_class'] = $resolvedClass;
			$items[$index]['registration_key'] = $this->registrationKey($item['extension'], $item['source_ip'], $resolvedClass);
		}

		return $items;
	}

	private function sharedRegistrationAnchorIndex(array $items, array $existingShared): ?int {
		foreach ($existingShared as $existing) {
			foreach ($items as $index => $item) {
				if (!empty($existing['contact_uri']) && (string)$existing['contact_uri'] === (string)($item['contact_uri'] ?? '')) {
					return $index;
				}
			}
		}

		foreach ($existingShared as $existing) {
			$existingUaClass = $this->normaliseUserAgentClass($existing['user_agent'] ?? null);
			if ($existingUaClass === '') {
				continue;
			}
			foreach ($items as $index => $item) {
				if ($existingUaClass === $this->normaliseUserAgentClass($item['user_agent'] ?? null)) {
					return $index;
				}
			}
		}

		$ranked = [];
		foreach ($items as $index => $item) {
			$ranked[] = [
				'index' => $index,
				'ua' => $this->normaliseUserAgentClass($item['user_agent'] ?? null),
				'contact_uri' => (string)($item['contact_uri'] ?? ''),
			];
		}
		usort($ranked, function ($a, $b) {
			$uaCompare = strcmp($a['ua'], $b['ua']);
			if ($uaCompare !== 0) {
				return $uaCompare;
			}
			return strcmp($a['contact_uri'], $b['contact_uri']);
		});

		return isset($ranked[0]) ? (int)$ranked[0]['index'] : null;
	}

	private function enrichLiveContactFromRegistrar(array $contact, array $registrarDetails): array {
		$exactCandidate = null;
		$fallbackCandidates = [];
		$extension = (string)($contact['extension'] ?? '');

		foreach ($registrarDetails as $detail) {
			if (($detail['extension'] ?? '') !== $extension) {
				continue;
			}
			if (!empty($detail['contact_uri']) && $this->contactUrisMatchForEnrichment($detail['contact_uri'], $contact['contact_uri'] ?? null)) {
				$exactCandidate = $detail;
				break;
			}
			if (!empty($detail['source_ip']) && $this->normaliseSourceIp($detail['source_ip']) === (string)($contact['source_ip'] ?? '')) {
				$fallbackCandidates[] = $detail;
			}
		}

		if (is_array($exactCandidate)) {
			$detail = $exactCandidate;
			if (!empty($detail['contact_uri'])) {
				$contact['contact_uri'] = (string)$detail['contact_uri'];
			}
			if (!empty($detail['source_ip'])) {
				$contact['source_ip'] = $this->normaliseSourceIp($detail['source_ip']);
			}
			foreach (['user_agent', 'device_name', 'firmware_version', 'contact_expires_at', 'qualify_frequency'] as $field) {
				if (($contact[$field] ?? null) === null && ($detail[$field] ?? null) !== null && $detail[$field] !== '') {
					$contact[$field] = $detail[$field];
				}
			}
			if (($detail['source_port'] ?? null) !== null) {
				$contact['source_port'] = $detail['source_port'];
			}
			return $contact;
		}

		foreach ($fallbackCandidates as $detail) {
			if (!is_array($detail)) {
				continue;
			}
			foreach (['contact_expires_at', 'qualify_frequency'] as $field) {
				if (($contact[$field] ?? null) === null && ($detail[$field] ?? null) !== null && $detail[$field] !== '') {
					$contact[$field] = $detail[$field];
				}
			}
			if (($detail['source_port'] ?? null) !== null) {
				$contact['source_port'] = $detail['source_port'];
			}
		}

		return $contact;
	}

	private function contactUrisMatchForEnrichment($left, $right): bool {
		$left = trim((string)$left);
		$right = trim((string)$right);
		if ($left === '' || $right === '') {
			return false;
		}
		if ($left === $right) {
			return true;
		}

		$normalisedLeft = $this->normaliseContactUriForEnrichment($left);
		$normalisedRight = $this->normaliseContactUriForEnrichment($right);

		return $normalisedLeft !== '' && $normalisedLeft === $normalisedRight;
	}

	private function normaliseContactUriForEnrichment(string $contactUri): string {
		$contactUri = trim($contactUri, " \t\n\r\0\x0B<>");
		if ($contactUri === '') {
			return '';
		}

		$contactUri = preg_split('/[;?&#>\s]/', $contactUri, 2)[0] ?? '';
		$contactUri = trim($contactUri);
		if (stripos($contactUri, 'sip:') === 0) {
			$contactUri = substr($contactUri, 4);
		} elseif (stripos($contactUri, 'sips:') === 0) {
			$contactUri = substr($contactUri, 5);
		}

		return strtolower($contactUri);
	}

	private function getStoredRegistrationIdentityClasses(): array {
		if (!$this->tableExists('registrationwatch_registrations')) {
			return [];
		}

		try {
			$stmt = $this->db()->query(
				"SELECT extension, source_ip, registration_ua_class, contact_uri, user_agent
				FROM registrationwatch_registrations
				WHERE source_ip IS NOT NULL AND source_ip <> ''"
			);
		} catch (\Throwable $e) {
			return [];
		}

		$groups = [];
		foreach (($stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : []) as $row) {
			$sourceIp = $this->normaliseSourceIp($row['source_ip'] ?? '');
			$extension = $this->normaliseRegistrationExtension((string)($row['extension'] ?? ''));
			if ($extension === '' || $sourceIp === '') {
				continue;
			}
			$key = $this->registrationIdentityGroupKey($extension, $sourceIp);
			$uaClass = (string)($row['registration_ua_class'] ?? '');
			$groups[$key]['classes'][] = $uaClass;
			if ($uaClass === '') {
				$groups[$key]['shared'][] = [
					'contact_uri' => $row['contact_uri'] ?? null,
					'user_agent' => $row['user_agent'] ?? null,
				];
			}
		}

		return $groups;
	}

	/**
	 * Phase 2 intentionally parses the human-readable PJSIP contact listing
	 * because it is widely available through FreePBX/Asterisk manager command
	 * support. Future work should prefer structured AMI/PJSIP data where that
	 * is practical; keep this parser treated as technical debt.
	 */
	private function parseContactLine(string $line): ?array {
		if (!preg_match('/^\s*Contact:\s+(\S+)\s+(.+)$/', $line, $matches)) {
			return null;
		}

		$target = $matches[1];
		if (strpos($target, '/') === false) {
			return null;
		}

		[$extension, $contactUri] = explode('/', $target, 2);
		$extension = $this->normaliseRegistrationExtension($extension);
		$contactUri = trim($contactUri);
		if ($extension === '') {
			return null;
		}

		$tail = preg_split('/\s+/', trim($matches[2]));
		$rawStatus = '';
		$latency = null;

		foreach ($tail as $token) {
			if ($this->isAsteriskStatus($token)) {
				$rawStatus = $token;
			} elseif (is_numeric($token)) {
				$latency = (float)$token;
			}
		}

		$contactAddress = $this->parseContactUriAddress($contactUri);
		$contactHost = $contactAddress['host'];
		$sourceIp = $this->normaliseSourceIp($contactHost);

		return [
			'registration_key' => '',
			'registration_ua_class' => '',
			'extension' => $extension,
			'contact_uri' => $contactUri,
			'status' => $this->mapAsteriskStatus($rawStatus),
			'latency_ms' => $latency,
			'source_ip' => $sourceIp,
			'parsed_contact_host' => $contactHost,
			'source_port' => $contactAddress['port'],
			'contact_count' => 1,
			'transport' => null,  // Not available from "pjsip show contacts"; requires future AMI/log ingestion
			'user_agent' => null, // Enriched from registrar/contact where available.
			'device_name' => null,
			'firmware_version' => null,
			'contact_expires_at' => null,
			'qualify_frequency' => null,
		];
	}

	private function normaliseRegistrationExtension(string $extension): string {
		return strtolower(trim($extension));
	}

	private function normaliseSourceIp($sourceIp): string {
		$sourceIp = trim((string)$sourceIp, " \t\n\r\0\x0B[]");
		if ($sourceIp === '' || !filter_var($sourceIp, FILTER_VALIDATE_IP)) {
			return '';
		}

		$packed = @inet_pton($sourceIp);
		if ($packed === false) {
			return strtolower($sourceIp);
		}

		$normalised = @inet_ntop($packed);
		return $normalised === false ? strtolower($sourceIp) : strtolower($normalised);
	}

	private function registrationKey(string $extension, string $sourceIp, string $uaClass = ''): string {
		$basis = $this->normaliseRegistrationExtension($extension) . "\0" . $this->normaliseSourceIp($sourceIp);
		$uaClass = $this->normaliseUserAgentClass($uaClass);
		if ($uaClass !== '') {
			$basis .= "\0" . $uaClass;
		}

		return hash('sha256', $basis);
	}

	private function noContactRegistrationKey(string $extension): string {
		return hash('sha256', $this->normaliseRegistrationExtension($extension) . "\0no-contact");
	}

	private function registrationIdentityGroupKey(string $extension, string $sourceIp): string {
		return $this->normaliseRegistrationExtension($extension) . "\0" . $this->normaliseSourceIp($sourceIp);
	}

	private function normaliseUserAgentClass($userAgent): string {
		$userAgent = strtolower(trim((string)$userAgent));
		if ($userAgent === '') {
			return '';
		}

		$userAgent = preg_replace('/\s+/', ' ', $userAgent);
		return $userAgent === null ? '' : $userAgent;
	}

	private function preferredLiveContact(array $existing, array $candidate): array {
		$existingRank = $this->statusRank((string)($existing['status'] ?? ''));
		$candidateRank = $this->statusRank((string)($candidate['status'] ?? ''));
		if ($candidateRank > $existingRank) {
			return $candidate;
		}
		if ($candidateRank < $existingRank) {
			$existing['contact_count'] = $candidate['contact_count'] ?? $existing['contact_count'] ?? 1;
			return $existing;
		}

		$existingUri = (string)($existing['contact_uri'] ?? '');
		$candidateUri = (string)($candidate['contact_uri'] ?? '');
		if (strcmp($candidateUri, $existingUri) >= 0) {
			return $candidate;
		}

		$existing['contact_count'] = $candidate['contact_count'] ?? $existing['contact_count'] ?? 1;
		return $existing;
	}

	private function parseContactUriAddress(?string $contactUri): array {
		$result = ['host' => null, 'port' => null];
		$contactUri = trim((string)$contactUri);
		if ($contactUri === '') {
			return $result;
		}
		if (preg_match('/\s/', $contactUri)) {
			return $result;
		}

		$contactUri = trim($contactUri, '<>');
		$atPosition = strrpos($contactUri, '@');
		$hostPort = $atPosition === false ? $contactUri : substr($contactUri, $atPosition + 1);
		$hostPort = preg_split('/[;?\#>\s]/', $hostPort, 2)[0] ?? '';
		$hostPort = trim($hostPort);
		if ($hostPort === '') {
			return $result;
		}

		if (strpos($hostPort, ':') !== false && stripos($hostPort, 'sip:') === 0) {
			$hostPort = substr($hostPort, strpos($hostPort, ':') + 1);
		}

		$host = null;
		$port = null;
		if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $hostPort, $matches)) {
			$host = trim($matches[1]);
			$port = $matches[2] ?? null;
		} elseif (substr_count($hostPort, ':') === 1 && preg_match('/^([^:]+):([0-9]+)$/', $hostPort, $matches)) {
			$host = trim($matches[1]);
			$port = $matches[2];
		} elseif (substr_count($hostPort, ':') === 0) {
			$host = $hostPort;
		} else {
			$host = $hostPort;
		}

		$host = trim((string)$host, " \t\n\r\0\x0B[]");
		if ($host === '') {
			return $result;
		}
		if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
			return $result;
		}

		$result['host'] = $host;
		if ($port !== null && ctype_digit((string)$port)) {
			$portNumber = (int)$port;
			if ($portNumber > 0 && $portNumber <= 65535) {
				$result['port'] = $portNumber;
			}
		}

		return $result;
	}

	private function parseContactUriOriginalHostAddress(?string $contactUri): array {
		$result = ['host' => null, 'port' => null];
		$contactUri = trim((string)$contactUri);
		if ($contactUri === '' || preg_match('/\s/', $contactUri)) {
			return $result;
		}

		if (!preg_match('/[;?&]x-ast-orig-host=([^;?&#>\s]+)/', $contactUri, $matches)) {
			return $result;
		}

		return $this->parseAddressHostPort(rawurldecode($matches[1]));
	}

	private function registrationAddressDetails(?string $contactUri, $sourceIp = null, $sourcePort = null): array {
		$contactAddress = $this->parseContactUriAddress($contactUri);
		$originalAddress = $this->parseContactUriOriginalHostAddress($contactUri);
		$hasOriginalAddress = $originalAddress['host'] !== null || $originalAddress['port'] !== null;

		$deviceAddress = $hasOriginalAddress ? $originalAddress : $contactAddress;
		$networkAddress = $hasOriginalAddress ? $contactAddress : ['host' => null, 'port' => null];

		if ($networkAddress['host'] === null && trim((string)$sourceIp) !== '') {
			$networkAddress['host'] = $sourceIp;
		}
		if ($networkAddress['port'] === null && trim((string)$sourcePort) !== '') {
			$networkAddress['port'] = $sourcePort;
		}

		return [
			'device_ip' => $deviceAddress['host'],
			'device_port' => $deviceAddress['port'],
			'network_ip' => $networkAddress['host'],
			'network_port' => $networkAddress['port'],
		];
	}

	private function parseAddressHostPort(string $hostPort): array {
		$result = ['host' => null, 'port' => null];
		$hostPort = trim($hostPort, " \t\n\r\0\x0B<>");
		if ($hostPort === '') {
			return $result;
		}

		$host = null;
		$port = null;
		if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $hostPort, $matches)) {
			$host = trim($matches[1]);
			$port = $matches[2] ?? null;
		} elseif (substr_count($hostPort, ':') === 1 && preg_match('/^([^:]+):([0-9]+)$/', $hostPort, $matches)) {
			$host = trim($matches[1]);
			$port = $matches[2];
		} elseif (substr_count($hostPort, ':') === 0) {
			$host = $hostPort;
		} else {
			$host = $hostPort;
		}

		$host = trim((string)$host, " \t\n\r\0\x0B[]");
		if ($host === '') {
			return $result;
		}
		if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
			return $result;
		}

		$result['host'] = $host;
		if ($port !== null && ctype_digit((string)$port)) {
			$portNumber = (int)$port;
			if ($portNumber > 0 && $portNumber <= 65535) {
				$result['port'] = $portNumber;
			}
		}

		return $result;
	}

	private function isAsteriskStatus(string $value): bool {
		return in_array(strtolower($value), ['avail', 'reachable', 'unavail', 'unavailable', 'unreachable', 'unknown', 'removed', 'nonqual'], true);
	}

	private function mapAsteriskStatus(string $value): string {
		switch (strtolower($value)) {
			case 'avail':
			case 'reachable':
				return self::STATUS_REACHABLE;
			case 'unavail':
			case 'unavailable':
			case 'unreachable':
				return self::STATUS_UNREACHABLE;
			case 'removed':
				return self::STATUS_NOT_REGISTERED;
			case 'nonqual':
				return self::STATUS_REGISTERED_NO_QUALIFY;
			case 'unknown':
			default:
				return self::STATUS_UNKNOWN;
		}
	}

	private function statusRank(string $status): int {
		$status = $this->normaliseState($status);

		switch ($status) {
			case self::STATUS_REACHABLE:
				return 5;
			case self::STATUS_UNREACHABLE:
				return 4;
			case self::STATUS_REGISTERED_NO_QUALIFY:
				return 3;
			case self::STATUS_UNKNOWN:
				return 2;
			case self::STATUS_NOT_REGISTERED:
				return 1;
			default:
				return 0;
		}
	}

	private function hadRegisteredState(string $status): bool {
		return in_array($this->normaliseState($status), [
			self::STATUS_REACHABLE,
			self::STATUS_UNREACHABLE,
			self::STATUS_REGISTERED_NO_QUALIFY,
		], true);
	}

	private function historyReason(bool $previousHadContact, string $newStatus): string {
		if ($this->normaliseState($newStatus) === self::STATUS_NOT_REGISTERED && $previousHadContact) {
			return 'removed';
		}

		return 'status_change';
	}

	private function classifyNetworkGroup(?string $sourceIp): string {
		if ($sourceIp === null || $sourceIp === '') {
			return 'Unknown';
		}

		// Check against configured VPN networks
		$vpnNetworks = $this->getTrustedVpnNetworks();
		foreach ($vpnNetworks as $cidr) {
			if ($this->ipInCidr($sourceIp, $cidr)) {
				return "VPN: $cidr";
			}
		}

		// Classify as private/local vs public/WAN
		if ($this->isPrivateIp($sourceIp)) {
			return 'Local LAN';
		}

		return 'WAN';
	}

	private function getTrustedVpnNetworks(): array {
		$settings = $this->getAlertSettings();
		$vpnConfigRaw = $settings['trusted_vpn_networks'] ?? '';
		if ($vpnConfigRaw === '') {
			return [];
		}

		$networks = [];
		foreach (preg_split('/\r\n|\r|\n/', $vpnConfigRaw) as $line) {
			$cidr = trim($line);
			if ($cidr !== '') {
				$networks[] = $cidr;
			}
		}

		return $networks;
	}

	private function ipInCidr(string $ip, string $cidr): bool {
		// Parse CIDR notation (e.g., "10.8.0.0/24")
		if (strpos($cidr, '/') === false) {
			// Invalid CIDR; treat as single IP comparison
			return $ip === $cidr;
		}

		[$network, $bits] = explode('/', $cidr, 2);
		$bits = (int)$bits;

		// Handle IPv4
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$ip = ip2long($ip);
			$network = ip2long($network);
			$mask = -1 << (32 - $bits);
			$network &= $mask;
			return ($ip & $mask) === $network;
		}

		// Handle IPv6 - simplified comparison
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			// For IPv6, do a string prefix match on the network portion
			// This is a simplified check; full IPv6 CIDR support would require inet_pton
			$networkBytes = (int)($bits / 4);
			return strncmp($ip, $network, $networkBytes) === 0;
		}

		return false;
	}

	private function isPrivateIp(string $ip): bool {
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
			&& filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
	}

	public function getTopologyGroups(): array {
		$registrations = $this->getStoredRegistrations();
		$groups = [
			'VPN' => [],
			'WAN' => [],
			'Local LAN' => [],
			'Unknown' => [],
		];

		foreach ($registrations as $registration) {
			if ((int)$registration['discovered'] === 0) {
				continue;
			}

			$sourceIp = $registration['source_ip'] ?? null;
			$group = $this->classifyNetworkGroup($sourceIp);

			// Check if group is a VPN network group
			if (strpos($group, 'VPN:') === 0) {
				if (!isset($groups[$group])) {
					$groups[$group] = [];
				}
				$groups[$group][] = $registration;
			} else {
				$groups[$group][] = $registration;
			}
		}

		// Remove empty groups
		$groups = array_filter($groups, function ($items) {
			return count($items) > 0;
		});

		return $groups;
	}

	private function insertStatusHistory(int $registrationId, string $registrationKey, string $extension, ?string $fromState, string $toState, string $reason, ?string $contactUri, ?float $latency, string $createdAt): int {
		$stmt = $this->db()->prepare(
			'INSERT INTO registrationwatch_status_history
				(registration_id, registration_key, extension, from_state, to_state, source, reason, contact_uri, latency_ms, created_at)
			VALUES
				(:registration_id, :registration_key, :extension, :from_state, :to_state, :source, :reason, :contact_uri, :latency_ms, :created_at)'
		);
		$stmt->execute([
			':registration_id' => $registrationId,
			':registration_key' => $registrationKey,
			':extension' => $extension,
			':from_state' => $fromState !== '' ? $fromState : null,
			':to_state' => $toState,
			':source' => 'reconcile',
			':reason' => $reason,
			':contact_uri' => $contactUri,
			':latency_ms' => $latency,
			':created_at' => $createdAt,
		]);
		return (int)$this->db()->lastInsertId();
	}

	private function processAlertQueue(string $now): void {
		$settings = $this->getAlertSettings();
		$recipients = $this->normaliseRecipients($settings['alert_recipients']);
		$canSend = $settings['alert_enabled'] === '1' && (bool)$recipients;
		$collectedAlerts = [];
		$reminderCycles = [];

		try {
			$debounceSeconds = min(self::ALERT_TIMING_MAX_SECONDS, max(0, (int)$settings['debounce_seconds']));
			$cutoff = date('Y-m-d H:i:s', strtotime($now) - $debounceSeconds);
			// Alerts are allowed only for fresh eligible registration transitions.
			// Freshness is measured using the configured debounce delay plus
			// ALERT_STALE_TRANSITION_MAX_SECONDS. This prevents old status-history rows
			// being replayed later after recipient or settings changes, while still
			// allowing legitimate debounce-delayed alerts to fire.
			$staleCutoff = date('Y-m-d H:i:s', strtotime($now) - ($debounceSeconds + self::ALERT_STALE_TRANSITION_MAX_SECONDS));
			$stmt = $this->db()->prepare(
				'SELECT h.id, h.registration_id, h.registration_key, h.extension, h.from_state, h.to_state, h.source, h.reason,
					h.contact_uri, h.latency_ms, h.created_at, e.repeat_mode
				FROM registrationwatch_status_history h
				JOIN registrationwatch_registrations e
					ON e.id = h.registration_id
				WHERE h.source = :source
					AND h.created_at <= :cutoff
					AND h.created_at >= :stale_cutoff
					AND e.enabled = 1
				ORDER BY h.created_at ASC, h.id ASC
				LIMIT 100'
			);
			$stmt->execute([
				':source' => 'reconcile',
				':cutoff' => $cutoff,
				':stale_cutoff' => $staleCutoff,
			]);

			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $transition) {
				if ($this->isRecoveryTransition($transition)) {
					$this->resetEscalationForRecovery($transition);
				}

				$alertType = $this->alertTypeForTransition($transition, $settings);
				if ($alertType === null) {
					continue;
				}

				$repeatMode = $this->resolveRepeatMode($transition['repeat_mode'] ?? null, $settings);
				if ($this->isEscalatingAlertType($alertType)) {
					$existingEscalation = $this->getActiveEscalationForRegistration((int)$transition['registration_id']);
					$this->deleteOtherEscalations((int)$transition['registration_id'], $alertType);
					$this->upsertEscalationForTransition($transition, $alertType, $repeatMode, $now, $existingEscalation);
				}

				if (!$canSend) {
					continue;
				}

				foreach ($recipients as $recipient) {
					if ($this->hasRecordedAlertForRecipient((int)$transition['id'], $alertType, $recipient)) {
						continue;
					}

					$email = $this->buildAlertEmail($transition, $alertType);
					$reserved = $this->reserveAlertHistory([
						'registration_id' => (int)$transition['registration_id'],
						'registration_key' => $transition['registration_key'],
						'extension' => $transition['extension'],
						'history_id' => (int)$transition['id'],
						'alert_type' => $alertType,
						'status' => $transition['to_state'],
						'contact_uri' => $transition['contact_uri'] ?? null,
						'recipient' => $recipient,
						'subject' => $email['subject'],
						'message' => $email['message'],
						'sent_at' => $now,
						'result' => 'pending',
						'error' => null,
					]);
					if (!$reserved) {
						continue;
					}

					$collectedAlerts[] = [
						'registration_id' => (int)$transition['registration_id'],
						'registration_key' => (string)$transition['registration_key'],
						'extension' => (string)$transition['extension'],
						'history_id' => (int)$transition['id'],
						'reminder_n' => 0,
						'alert_type' => $alertType,
						'status' => (string)$transition['to_state'],
						'contact_uri' => $transition['contact_uri'] ?? null,
						'recipient' => $recipient,
						'subject' => $email['subject'],
						'message' => $email['message'],
						'source' => 'transition',
					];
				}
			}

			$due = $this->collectDueReminderAlerts($now, $settings, $recipients);
			$collectedAlerts = array_merge($collectedAlerts, $due['alerts']);
			$reminderCycles = $due['cycles'];

			$this->dispatchCollectedAlerts($collectedAlerts, $reminderCycles, $settings, $recipients, $now);
		} catch (\Exception $e) {
			$this->logError('Alert processing failed: ' . $e->getMessage());
		}
	}

	private function alertTypeForTransition(array $transition, array $settings): ?string {
		$from = $this->normaliseState($transition['from_state'] ?? '');
		$to = $this->normaliseState($transition['to_state'] ?? '');
		if ($from === '' || $from === self::STATUS_UNKNOWN) {
			return null;
		}

		if ($to === self::STATUS_UNREACHABLE && $settings['alert_on_unreachable'] === '1' && $this->hadRegisteredState($from)) {
			return 'unreachable';
		}

		if ($to === self::STATUS_NOT_REGISTERED && $settings['alert_on_not_registered'] === '1' && in_array($from, [
			self::STATUS_REACHABLE,
			self::STATUS_REGISTERED_NO_QUALIFY,
			self::STATUS_UNREACHABLE,
		], true)) {
			return 'not_registered';
		}

		if ($settings['alert_on_recovery'] === '1' && in_array($from, [
			self::STATUS_UNREACHABLE,
			self::STATUS_NOT_REGISTERED,
		], true) && in_array($to, [
			self::STATUS_REACHABLE,
			self::STATUS_REGISTERED_NO_QUALIFY,
		], true)) {
			return 'recovery';
		}

		return null;
	}

	private function isEscalatingAlertType(string $alertType): bool {
		return in_array($alertType, ['unreachable', 'not_registered'], true);
	}

	private function isRecoveryTransition(array $transition): bool {
		$from = $this->normaliseState($transition['from_state'] ?? '');
		$to = $this->normaliseState($transition['to_state'] ?? '');

		return in_array($from, [
			self::STATUS_UNREACHABLE,
			self::STATUS_NOT_REGISTERED,
		], true) && in_array($to, [
			self::STATUS_REACHABLE,
			self::STATUS_REGISTERED_NO_QUALIFY,
		], true);
	}

	private function recoveryEscalationTypesForTransition(array $transition): array {
		$from = $this->normaliseState($transition['from_state'] ?? '');

		if ($from === self::STATUS_UNREACHABLE) {
			return ['unreachable'];
		}
		if ($from === self::STATUS_NOT_REGISTERED) {
			return ['not_registered'];
		}

		return [];
	}

	private function resetEscalationForRecovery(array $transition): void {
		$types = $this->recoveryEscalationTypesForTransition($transition);
		if (!$types) {
			return;
		}

		$placeholders = [];
		$params = [
			':registration_id' => (int)$transition['registration_id'],
		];

		foreach ($types as $index => $type) {
			$key = ':type_' . $index;
			$placeholders[] = $key;
			$params[$key] = $type;
		}

		$stmt = $this->db()->prepare(
			'DELETE FROM registrationwatch_alert_escalation
			WHERE registration_id = :registration_id
				AND alert_type IN (' . implode(', ', $placeholders) . ')'
		);
		$stmt->execute($params);
	}

	private function resolveRepeatMode(?string $registrationRepeatMode, array $settings): string {
		$registrationRepeatMode = trim((string)$registrationRepeatMode);
		if ($registrationRepeatMode !== '') {
			return $this->normaliseRepeatMode($registrationRepeatMode);
		}

		return $this->normaliseRepeatMode($settings['repeat_mode'] ?? self::REPEAT_MODE_NEVER);
	}

	private function getActiveEscalationForRegistration(int $registrationId): array {
		$stmt = $this->db()->prepare(
			'SELECT id, registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode
			FROM registrationwatch_alert_escalation
			WHERE registration_id = :registration_id
			ORDER BY active_since ASC, id ASC
			LIMIT 1'
		);
		$stmt->execute([':registration_id' => $registrationId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);

		return is_array($row) ? $row : [];
	}

	private function upsertEscalationForTransition(array $transition, string $alertType, string $repeatMode, string $now, array $existingEscalation = []): void {
		$registrationId = (int)$transition['registration_id'];
		$registrationKey = (string)$transition['registration_key'];
		$extension = (string)$transition['extension'];
		$repeatMode = $this->normaliseRepeatMode($repeatMode);
		if ($repeatMode === self::REPEAT_MODE_NEVER) {
			$this->deleteEscalationsForRegistration($registrationId);
			return;
		}

		$alertCount = isset($existingEscalation['alert_count']) ? max(0, (int)$existingEscalation['alert_count']) : 0;
		$activeSince = !empty($existingEscalation['active_since']) ? (string)$existingEscalation['active_since'] : (string)$transition['created_at'];
		$lastAlertAt = !empty($existingEscalation['last_alert_at']) ? (string)$existingEscalation['last_alert_at'] : $now;
		$nextDueAt = !empty($existingEscalation['next_due_at'])
			? (string)$existingEscalation['next_due_at']
			: $this->nextRepeatDueAt($now, $repeatMode, $alertCount);
		if ($nextDueAt === null) {
			$this->deleteEscalationsForRegistration($registrationId);
			return;
		}

		$stmt = $this->db()->prepare(
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
		$stmt->execute([
			':registration_id' => $registrationId,
			':registration_key' => $registrationKey,
			':extension' => $extension,
			':history_id' => (int)$transition['id'],
			':alert_type' => $alertType,
			':active_since' => $activeSince,
			':last_alert_at' => $lastAlertAt,
			':alert_count' => $alertCount,
			':next_due_at' => $nextDueAt,
			':repeat_mode' => $repeatMode,
			':created_at' => $now,
			':updated_at' => $now,
		]);
	}

	private function deleteEscalationsForRegistration(int $registrationId): void {
		$stmt = $this->db()->prepare(
			'DELETE FROM registrationwatch_alert_escalation
			WHERE registration_id = :registration_id'
		);
		$stmt->execute([':registration_id' => $registrationId]);
	}

	private function deleteEscalation(int $registrationId, string $alertType): void {
		$stmt = $this->db()->prepare(
			'DELETE FROM registrationwatch_alert_escalation
			WHERE registration_id = :registration_id
				AND alert_type = :alert_type'
		);
		$stmt->execute([
			':registration_id' => $registrationId,
			':alert_type' => $alertType,
		]);
	}

	private function deleteOtherEscalations(int $registrationId, string $activeAlertType): void {
		$stmt = $this->db()->prepare(
			'DELETE FROM registrationwatch_alert_escalation
			WHERE registration_id = :registration_id
				AND alert_type <> :alert_type'
		);
		$stmt->execute([
			':registration_id' => $registrationId,
			':alert_type' => $activeAlertType,
		]);
	}

	private function collectDueReminderAlerts(string $now, array $settings, array $recipients): array {
		$result = ['alerts' => [], 'cycles' => []];
		if ($settings['alert_enabled'] !== '1' || !$recipients) {
			return $result;
		}

		try {
			$liveContacts = $this->getLiveContacts();
		} catch (\Throwable $e) {
			$this->logWarning('Reminder pass skipped because live registration status is unavailable: ' . $e->getMessage());
			return $result;
		}

		$stmt = $this->db()->prepare(
			'SELECT a.id, a.registration_id, a.registration_key, a.extension, a.history_id, a.alert_type, a.active_since, a.last_alert_at,
				a.alert_count, a.next_due_at, a.repeat_mode,
				r.last_known_status, r.contact_uri, r.latency_ms, r.enabled
			FROM registrationwatch_alert_escalation a
			JOIN registrationwatch_registrations r
				ON r.id = a.registration_id
			WHERE a.next_due_at <= :now
				AND r.enabled = 1
			ORDER BY a.next_due_at ASC, a.id ASC
			LIMIT 100'
		);
		$stmt->execute([':now' => $now]);

		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$currentStatus = $this->currentLiveStatusForReminder($row, $liveContacts);
			if (!$this->isReminderStillAlertable((string)$row['alert_type'], $currentStatus)) {
				$this->deleteEscalation((int)$row['registration_id'], (string)$row['alert_type']);
				continue;
			}

			$reminderN = ((int)$row['alert_count']) + 1;
			$transition = $this->buildReminderTransition($row, $currentStatus, $now);
			$email = $this->buildAlertEmail($transition, (string)$row['alert_type']);
			$reservedAny = false;

			foreach ($recipients as $recipient) {
				$reserved = $this->reserveAlertHistory([
					'registration_id' => (int)$row['registration_id'],
					'registration_key' => $row['registration_key'],
					'extension' => $row['extension'],
					'history_id' => (int)$row['history_id'],
					'reminder_n' => $reminderN,
					'alert_type' => $row['alert_type'],
					'status' => $currentStatus,
					'contact_uri' => $row['contact_uri'] ?? null,
					'recipient' => $recipient,
					'subject' => $email['subject'],
					'message' => $email['message'],
					'sent_at' => $now,
					'result' => 'pending',
					'error' => null,
				]);
				if (!$reserved) {
					continue;
				}

				$reservedAny = true;
				$result['alerts'][] = [
					'registration_id' => (int)$row['registration_id'],
					'registration_key' => (string)$row['registration_key'],
					'extension' => (string)$row['extension'],
					'history_id' => (int)$row['history_id'],
					'reminder_n' => $reminderN,
					'alert_type' => (string)$row['alert_type'],
					'status' => $currentStatus,
					'contact_uri' => $row['contact_uri'] ?? null,
					'recipient' => $recipient,
					'subject' => $email['subject'],
					'message' => $email['message'],
					'source' => 'reminder',
				];
			}

			if ($reservedAny) {
				$result['cycles'][(int)$row['id']] = [
					'row' => $row,
					'reminder_n' => $reminderN,
				];
			}
		}

		return $result;
	}

	private function dispatchCollectedAlerts(array $alerts, array $reminderCycles, array $settings, array $recipients, string $now): void {
		if (!$alerts) {
			return;
		}

		if ($this->isMonitoringSnoozed()) {
			foreach ($alerts as $alert) {
				$this->updateReservedAlertHistory(
					(int)$alert['history_id'],
					(string)$alert['alert_type'],
					(string)$alert['recipient'],
					'suppressed',
					'Monitoring snoozed',
					$now,
					(int)($alert['reminder_n'] ?? 0)
				);
			}
			$this->markReminderCyclesComplete($reminderCycles, $now);
			return;
		}

		$threshold = $this->stormThreshold($settings);
		if ($threshold > 0 && count($alerts) >= $threshold) {
			$summaryResults = $this->dispatchStormSummary($alerts, $recipients, $now);
			foreach ($alerts as $alert) {
				$recipient = (string)$alert['recipient'];
				$summaryResult = $summaryResults[$recipient] ?? ['status' => false, 'message' => 'Storm summary was not attempted for this recipient.'];
				$coveredBySummary = !empty($summaryResult['status']);
				$this->updateReservedAlertHistory(
					(int)$alert['history_id'],
					(string)$alert['alert_type'],
					$recipient,
					$coveredBySummary ? 'storm_suppressed' : 'storm_summary_failed',
					$coveredBySummary ? null : (string)$summaryResult['message'],
					$now,
					(int)($alert['reminder_n'] ?? 0)
				);
			}
			$this->markReminderCyclesComplete($reminderCycles, $now);
			return;
		}

		foreach ($alerts as $alert) {
			$result = $this->sendEmail((string)$alert['recipient'], (string)$alert['subject'], (string)$alert['message']);
			if (!$result['status']) {
				$this->logWarning('Alert email send failed for ' . $alert['recipient'] . ' on extension ' . $alert['extension'] . ': ' . $result['message']);
			}
			$this->updateReservedAlertHistory(
				(int)$alert['history_id'],
				(string)$alert['alert_type'],
				(string)$alert['recipient'],
				$result['status'] ? 'sent' : 'failed',
				$result['status'] ? null : $result['message'],
				$this->now(),
				(int)($alert['reminder_n'] ?? 0)
			);
		}

		$this->markReminderCyclesComplete($reminderCycles, $now);
	}

	private function stormThreshold(array $settings): int {
		$value = isset($settings['storm_threshold']) ? trim((string)$settings['storm_threshold']) : $this->settingsDefaults['storm_threshold'];
		if ($value === '' || !ctype_digit($value)) {
			return 0;
		}

		return min(10000, max(0, (int)$value));
	}

	private function dispatchStormSummary(array $alerts, array $recipients, string $now): array {
		$email = $this->buildStormSummaryEmail($alerts, $now);
		$results = [];
		foreach ($recipients as $recipient) {
			$result = $this->sendEmail($recipient, $email['subject'], $email['message']);
			$results[$recipient] = $result;
			if (!$result['status']) {
				$this->logWarning('Storm summary email send failed for ' . $recipient . ': ' . $result['message']);
			}
			$this->insertAlertHistory([
				'extension' => '',
				'history_id' => null,
				'reminder_n' => 0,
				'alert_type' => 'storm_summary',
				'status' => 'storm_summary',
				'recipient' => $recipient,
				'subject' => $email['subject'],
				'message' => $email['message'],
				'sent_at' => $now,
				'result' => $result['status'] ? 'sent' : 'failed',
				'error' => $result['status'] ? null : $result['message'],
			]);
		}

		return $results;
	}

	private function buildStormSummaryEmail(array $alerts, string $now): array {
		$total = count($alerts);
		$typeCounts = [];
		$registrations = [];

		foreach ($alerts as $alert) {
			$type = (string)$alert['alert_type'];
			$typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
			$key = (string)$alert['extension'] . '|' . $type . '|' . (string)$alert['status'];
			$registrations[$key] = [
				'extension' => (string)$alert['extension'],
				'alert_type' => $type,
				'status' => (string)$alert['status'],
			];
		}

		ksort($typeCounts);
		ksort($registrations);

		$subject = $total === 1
			? _('Registration Watch: Storm Summary (1 alert suppressed)')
			: sprintf(_('Registration Watch: Storm Summary (%d alerts suppressed)'), $total);
		$message = [
			_('Registration Watch Storm Summary'),
			'',
			$total === 1
				? _('1 alert email was suppressed in this processing pass.')
				: sprintf(_('%d alert emails were suppressed in this processing pass.'), $total),
			_('Storm Threshold limits large batches of alerts generated in the same processing pass. It reduces email floods from sudden widespread registration changes, but it is not full correlated-outage detection. The count is per registration. Use 0 to disable.'),
			'',
			'Time: ' . $now,
			'',
			_('Suppressed alert types:'),
		];

		foreach ($typeCounts as $type => $count) {
			$message[] = sprintf('%s: %d', $this->stateLabel($type), $count);
		}

		if (count($typeCounts) === 1 && isset($typeCounts['recovery'])) {
			$message[] = '';
			$message[] = sprintf(_('%d recovery alerts were suppressed in this pass.'), $total);
		}

		$message[] = '';
		$message[] = _('Watched registrations included in this pass:');
		foreach ($registrations as $registration) {
			$message[] = sprintf(
				'%s - %s',
				$registration['extension'],
				$this->alertTypePhrase($registration['alert_type'], $registration['status'])
			);
		}

		return [
			'subject' => $subject,
			'message' => implode("\n", $message),
		];
	}

	private function alertTypePhrase(string $alertType, string $status): string {
		if ($alertType === 'recovery') {
			return 'recovered to ' . $this->stateLabel($status);
		}
		return 'became ' . $this->stateLabel($status);
	}

	private function markReminderCyclesComplete(array $reminderCycles, string $now): void {
		foreach ($reminderCycles as $cycle) {
			$this->markReminderCycleComplete($cycle['row'], (int)$cycle['reminder_n'], $now);
		}
	}

	private function currentLiveStatusForReminder(array $row, array $liveContacts): string {
		$registrationKey = (string)($row['registration_key'] ?? '');
		if ($registrationKey !== '' && isset($liveContacts[$registrationKey])) {
			return $this->normaliseState($liveContacts[$registrationKey]['status'] ?? self::STATUS_UNKNOWN);
		}

		return self::STATUS_NOT_REGISTERED;
	}

	private function isReminderStillAlertable(string $alertType, string $currentStatus): bool {
		$currentStatus = $this->normaliseState($currentStatus);
		if ($alertType === 'unreachable') {
			return $currentStatus === self::STATUS_UNREACHABLE;
		}
		if ($alertType === 'not_registered') {
			return $currentStatus === self::STATUS_NOT_REGISTERED;
		}

		return false;
	}

	private function buildReminderTransition(array $row, string $currentStatus, string $now): array {
		return [
			'id' => (int)$row['history_id'],
			'registration_id' => (int)$row['registration_id'],
			'registration_key' => (string)$row['registration_key'],
			'extension' => (string)$row['extension'],
			'from_state' => null,
			'to_state' => $currentStatus,
			'source' => 'reconcile',
			'reason' => 'reminder',
			'contact_uri' => $row['contact_uri'] ?? null,
			'latency_ms' => $row['latency_ms'] ?? null,
			'created_at' => $row['active_since'] ?: $now,
			'repeat_mode' => (string)($row['repeat_mode'] ?? ''),
			'reminder_n' => ((int)($row['alert_count'] ?? 0)) + 1,
		];
	}

	private function markReminderCycleComplete(array $row, int $reminderN, string $now): void {
		$repeatMode = $this->normaliseRepeatMode($row['repeat_mode'] ?? self::REPEAT_MODE_NEVER);
		$nextDueAt = $this->nextRepeatDueAt($now, $repeatMode, $reminderN);
		if ($nextDueAt === null) {
			$this->deleteEscalation((int)$row['registration_id'], (string)$row['alert_type']);
			return;
		}

		$stmt = $this->db()->prepare(
			'UPDATE registrationwatch_alert_escalation
			SET alert_count = :alert_count,
				last_alert_at = :last_alert_at,
				next_due_at = :next_due_at,
				updated_at = :updated_at
			WHERE id = :id'
		);
		$stmt->execute([
			':alert_count' => $reminderN,
			':last_alert_at' => $now,
			':next_due_at' => $nextDueAt,
			':updated_at' => $now,
			':id' => (int)$row['id'],
		]);
	}

        private function hasRecordedAlertForRecipient(int $historyId, string $alertType, string $recipient): bool {
                $stmt = $this->db()->prepare(
                        'SELECT COUNT(*)
                        FROM registrationwatch_alert_history
                        WHERE history_id = :history_id
                                AND alert_type = :alert_type
                                AND recipient = :recipient
                                AND reminder_n = 0'
                );
                $stmt->execute([
                        ':history_id' => $historyId,
                        ':alert_type' => $alertType,
                        ':recipient' => $recipient,
                ]);

                return (int)$stmt->fetchColumn() > 0;
        }

	private function runAsteriskCommand(string $command): string {
		$astman = $this->FreePBX->astman ?? null;
		if (!$astman) {
			throw new \Exception(_('Registration Watch could not query Asterisk. Confirm the FreePBX AMI user has Command privilege.'));
		}

		try {
			if (method_exists($astman, 'Command')) {
				$result = $astman->Command($command);
				return is_array($result) ? implode("\n", $result) : (string)$result;
			}

			if (method_exists($astman, 'send_request')) {
				$result = $astman->send_request('Command', ['Command' => $command]);
				if (is_array($result)) {
					if (isset($result['data'])) {
						return is_array($result['data']) ? implode("\n", $result['data']) : (string)$result['data'];
					}
					return implode("\n", array_map('strval', $result));
				}
				return (string)$result;
			}
		} catch (\Throwable $e) {
			throw new \Exception(_('Registration Watch could not query Asterisk. Confirm the FreePBX AMI user has Command privilege.') . ' ' . $e->getMessage(), 0, $e);
		}

		throw new \Exception(_('Registration Watch could not query Asterisk. Confirm the FreePBX AMI user has Command privilege.'));
	}

	private function parseUserAgentDetails(?string $userAgent): array {
		$userAgent = trim((string)$userAgent);

		if ($userAgent === '') {
			return [
				'device_name' => null,
				'firmware_version' => null,
			];
		}

		if (preg_match('/^([^\/]+)\/(.+)$/', $userAgent, $matches)) {
			return [
				'device_name' => trim($matches[1]) ?: null,
				'firmware_version' => trim($matches[2]) ?: null,
			];
		}

		if (preg_match('/^(.+?)\s+([0-9]+(?:\.[0-9A-Za-z_-]+)+)$/', $userAgent, $matches)) {
			return [
				'device_name' => trim($matches[1]) ?: null,
				'firmware_version' => trim($matches[2]) ?: null,
			];
		}

		return [
			'device_name' => $userAgent,
			'firmware_version' => null,
		];
	}

	private function getRegistrarContactDetails(): array {
		$details = [];

		try {
			$output = $this->runAsteriskCommand('database show registrar/contact');
		} catch (\Throwable $e) {
			$this->logWarning('Registrar contact metadata unavailable: ' . $e->getMessage());
			return $details;
		}

		foreach (preg_split('/\r\n|\r|\n/', (string)$output) as $line) {
			$line = trim($line);

			if ($line === '' || strpos($line, '/registrar/contact/') !== 0) {
				continue;
			}

			$parts = explode(': ', $line, 2);
			if (count($parts) !== 2) {
				continue;
			}

			$key = $parts[0];
			$json = $parts[1];

			$payload = json_decode($json, true);
			if (!is_array($payload)) {
				continue;
			}

			$registration = (string)($payload['endpoint'] ?? '');
			if ($registration === '' && preg_match('#^/registrar/contact/([^;]+)#', $key, $matches)) {
				$registration = (string)$matches[1];
			}

			if ($registration === '') {
				continue;
			}

			$userAgent = isset($payload['user_agent']) ? (string)$payload['user_agent'] : '';
			$parsed = $this->parseUserAgentDetails($userAgent);

			$expiresAt = null;
			if (!empty($payload['expiration_time']) && ctype_digit((string)$payload['expiration_time'])) {
				$expiresAt = date('Y-m-d H:i:s', (int)$payload['expiration_time']);
			}

			$details[] = [
				'extension' => $this->normaliseRegistrationExtension($registration),
				'contact_uri' => isset($payload['uri']) && $payload['uri'] !== '' ? (string)$payload['uri'] : null,
				'source_ip' => isset($payload['via_addr']) && $payload['via_addr'] !== '' ? $this->normaliseSourceIp($payload['via_addr']) : null,
				'source_port' => isset($payload['via_port']) && is_numeric($payload['via_port']) ? (int)$payload['via_port'] : null,
				'user_agent' => $userAgent !== '' ? $userAgent : null,
				'device_name' => $parsed['device_name'],
				'firmware_version' => $parsed['firmware_version'],
				'contact_expires_at' => $expiresAt,
				'qualify_frequency' => isset($payload['qualify_frequency']) && is_numeric($payload['qualify_frequency'])
					? (int)$payload['qualify_frequency']
					: null,
			];
		}

		return $details;
	}

	private function getStoredRegistrations(): array {
		$stmt = $this->db()->query(
			'SELECT id, registration_key, registration_ua_class, extension, description, notes, notes_updated_at,
				enabled, auto_disabled_absent_at, repeat_mode, discovered, last_known_status, contact_uri,
				source_ip, source_port, contact_count, transport, user_agent, device_name, firmware_version,
				contact_expires_at, qualify_frequency, last_heartbeat_at, latency_ms, last_seen_at,
				last_checked_at, first_discovered_at, last_discovered_at
			FROM registrationwatch_registrations
			ORDER BY extension, source_ip, id'
		);

		$rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
		if (!is_array($rows)) {
			return [];
		}

		return array_map([$this, 'withRegistrationDisplayAddress'], $rows);
	}

	private function getRegistrationMapRows(): array {
		$rows = [];
		foreach ($this->getStoredRegistrations() as $registration) {
			if ((int)$registration['discovered'] === 0) {
				continue;
			}
			$rows[] = [
				'id' => (int)$registration['id'],
				'registration_id' => (int)$registration['id'],
				'registration_key' => $registration['registration_key'],
				'registration_ua_class' => $registration['registration_ua_class'] ?? '',
				'extension' => $registration['extension'],
				'description' => $registration['description'],
				'notes' => $registration['notes'],
				'notes_updated_at' => $registration['notes_updated_at'],
				'enabled' => (int)$registration['enabled'],
				'repeat_mode' => $registration['repeat_mode'] ?? null,
				'status' => $registration['last_known_status'],
				'contact_uri' => $registration['contact_uri'],
				'source_ip' => $registration['source_ip'],
				'source_port' => $registration['source_port'] ?? null,
				'contact_count' => isset($registration['contact_count']) ? (int)$registration['contact_count'] : 1,
				'device_ip' => $registration['device_ip'] ?? null,
				'device_port' => $registration['device_port'] ?? null,
				'network_ip' => $registration['network_ip'] ?? null,
				'network_port' => $registration['network_port'] ?? null,
				'seen_by_asterisk' => $registration['seen_by_asterisk'] ?? null,
				'user_agent' => $registration['user_agent'] ?? null,
				'device_name' => $registration['device_name'] ?? null,
				'firmware_version' => $registration['firmware_version'] ?? null,
				'contact_expires_at' => $registration['contact_expires_at'] ?? null,
				'qualify_frequency' => $registration['qualify_frequency'] ?? null,
				'latency_ms' => $registration['latency_ms'],
				'last_seen_at' => $registration['last_seen_at'],
				'last_checked_at' => $registration['last_checked_at'],
			];
		}

		return $rows;
	}

	private function getWatchedExtensionGroups(): array {
		$all = $this->getStoredRegistrations();
		$byExtension = [];
		foreach ($all as $row) {
			$ext = (string)($row['extension'] ?? '');
			$byExtension[$ext][] = $row;
		}

		$groups = [];
		foreach ($byExtension as $contacts) {
			usort($contacts, function ($a, $b) {
				return (int)($a['id'] ?? 0) - (int)($b['id'] ?? 0);
			});
			$primary = $contacts[0];

			// Worst status wins: any unhealthy contact makes the group unhealthy.
			$groupStatus = (string)($primary['last_known_status'] ?? self::STATUS_UNKNOWN);
			$groupRank = $this->statusRank($groupStatus);
			foreach ($contacts as $contact) {
				$s = (string)($contact['last_known_status'] ?? self::STATUS_UNKNOWN);
				$rank = $this->statusRank($s);
				if ($rank < $groupRank) {
					$groupRank = $rank;
					$groupStatus = $s;
				}
			}

			$enabled = 0;
			foreach ($contacts as $contact) {
				if ((int)($contact['enabled'] ?? 0)) {
					$enabled = 1;
					break;
				}
			}

			$discovered = 0;
			foreach ($contacts as $contact) {
				if ((int)($contact['discovered'] ?? 0)) {
					$discovered = 1;
					break;
				}
			}

			$groups[] = [
				'id' => (int)($primary['id'] ?? 0),
				'registration_id' => (int)($primary['id'] ?? 0),
				'registration_key' => (string)($primary['registration_key'] ?? ''),
				'extension' => (string)($primary['extension'] ?? ''),
				'description' => $primary['description'] ?? null,
				'notes' => (string)($primary['notes'] ?? ''),
				'notes_updated_at' => $primary['notes_updated_at'] ?? null,
				'enabled' => $enabled,
				'repeat_mode' => $primary['repeat_mode'] ?? null,
				'last_known_status' => $groupStatus,
				'status' => $groupStatus,
				'discovered' => $discovered,
			];
		}

		return $groups;
	}

	private function getLastRefreshTime(): string {
		$stmt = $this->db()->query('SELECT MAX(last_checked_at) FROM registrationwatch_registrations');
		$value = $stmt ? $stmt->fetchColumn() : '';
		return $value ? (string)$value : '';
	}

	private function getStatusHistory(): array {
		$stmt = $this->db()->query(
			'SELECT id, registration_id, registration_key, extension, from_state, to_state, source, reason, contact_uri, latency_ms, created_at
			FROM registrationwatch_status_history
			ORDER BY created_at DESC, id DESC
			LIMIT 25'
		);

		$rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
		foreach ($rows as &$row) {
			$row['source'] = $this->sourceLabel($row['source'] ?? '');
			$row['reason'] = $this->reasonLabel($row['reason'] ?? '');
		}
		unset($row);

		return $rows;
	}

	private function getAlertHistory(): array {
		$stmt = $this->db()->query(
			'SELECT id, registration_id, registration_key, extension, contact_uri, history_id, alert_type, status, recipient, subject, sent_at, result, error
			FROM registrationwatch_alert_history
			ORDER BY sent_at DESC, id DESC
			LIMIT 25'
		);

		return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
	}

	private function getPruneSettings(): array {
		$settings = $this->getAlertSettings();

		return [
			'status_history_prune_policy' => $this->normalisePrunePolicy($settings['status_history_prune_policy'] ?? 'never'),
			'alert_history_prune_policy' => $this->normalisePrunePolicy($settings['alert_history_prune_policy'] ?? 'never'),
		];
	}

	private function normalisePrunePolicy(string $policy): string {
		$policy = strtolower(trim($policy));
		return in_array($policy, ['hourly', 'daily', 'monthly', 'yearly', 'never'], true) ? $policy : 'never';
	}

	private function applyHistoryPruning(?string $historyType = null, ?string $policy = null): array {
		$deleted = [
			'status' => 0,
			'alert' => 0,
		];

		if ($historyType !== null) {
			if ($historyType === 'status') {
				$deleted['status'] = $this->pruneStatusHistory($this->normalisePrunePolicy((string)$policy));
			} elseif ($historyType === 'alert') {
				$deleted['alert'] = $this->pruneAlertHistory($this->normalisePrunePolicy((string)$policy));
			}

			return $deleted;
		}

		$settings = $this->getPruneSettings();
		$deleted['status'] = $this->pruneStatusHistory($settings['status_history_prune_policy']);
		$deleted['alert'] = $this->pruneAlertHistory($settings['alert_history_prune_policy']);

		return $deleted;
	}

	private function pruneStatusHistory(string $policy): int {
		$cutoff = $this->pruneCutoff($policy);
		if ($cutoff === null) {
			return 0;
		}

		$stmt = $this->db()->prepare('DELETE FROM registrationwatch_status_history WHERE created_at < :cutoff');
		$stmt->execute([':cutoff' => $cutoff]);
		return $stmt->rowCount();
	}

	private function pruneAlertHistory(string $policy): int {
		$cutoff = $this->pruneCutoff($policy);
		if ($cutoff === null) {
			return 0;
		}

		$stmt = $this->db()->prepare('DELETE FROM registrationwatch_alert_history WHERE sent_at < :cutoff');
		$stmt->execute([':cutoff' => $cutoff]);
		return $stmt->rowCount();
	}

	private function pruneCutoff(string $policy): ?string {
		$policy = $this->normalisePrunePolicy($policy);
		if ($policy === 'never') {
			return null;
		}

		try {
			$cutoff = new \DateTimeImmutable($this->now());
			switch ($policy) {
				case 'hourly':
					$cutoff = $cutoff->modify('-1 hour');
					break;
				case 'daily':
					$cutoff = $cutoff->modify('-1 day');
					break;
				case 'monthly':
					$cutoff = $cutoff->modify('-1 month');
					break;
				case 'yearly':
					$cutoff = $cutoff->modify('-1 year');
					break;
				default:
					return null;
			}
		} catch (\Exception $e) {
			$this->logError('Unable to calculate history pruning cutoff: ' . $e->getMessage());
			return null;
		}

		return $cutoff->format('Y-m-d H:i:s');
	}

	private function normaliseAlertTimingSeconds(string $key, string $default): ?int {
		$value = isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : trim($default);
		if ($value === '' || !ctype_digit($value)) {
			return null;
		}

		$seconds = (int)$value;
		if ($seconds < 0 || $seconds > self::ALERT_TIMING_MAX_SECONDS) {
			return null;
		}

		return $seconds;
	}

	private function normaliseRepeatMode(?string $mode): string {
		$mode = strtolower(trim((string)$mode));
		if ($mode === self::REPEAT_MODE_FIBONACCI) {
			return self::REPEAT_MODE_ESCALATING;
		}

		return in_array($mode, self::REPEAT_MODES, true) ? $mode : self::REPEAT_MODE_NEVER;
	}

	private function normaliseStormThreshold(string $value): ?int {
		$value = trim($value);
		if ($value === '') {
			return 0;
		}
		if (!ctype_digit($value)) {
			return null;
		}

		$threshold = (int)$value;
		if ($threshold < 0 || $threshold > 10000) {
			return null;
		}

		return $threshold;
	}

	private function repeatIntervalSeconds(string $repeatMode, int $alertCount): ?int {
		switch ($this->normaliseRepeatMode($repeatMode)) {
			case self::REPEAT_MODE_FIVE_MINUTES:
				return 300;
			case self::REPEAT_MODE_HOURLY:
				return 3600;
			case self::REPEAT_MODE_DAILY:
				return self::REPEAT_DAILY_SECONDS;
			case self::REPEAT_MODE_ESCALATING:
				return $this->escalatingRepeatIntervalSeconds($alertCount);
			case self::REPEAT_MODE_NEVER:
			default:
				return null;
		}
	}

	private function nextRepeatDueAt(string $lastAlertAt, string $repeatMode, int $alertCount): ?string {
		$interval = $this->repeatIntervalSeconds($repeatMode, $alertCount);
		if ($interval === null) {
			return null;
		}

		$timestamp = strtotime($lastAlertAt);
		if ($timestamp === false) {
			$timestamp = strtotime($this->now());
		}

		return date('Y-m-d H:i:s', $timestamp + $interval);
	}

	// alert_count is the number of reminders already sent. This returns the
	// wait until the next reminder using a Fibonacci-style multiplier sequence
	// (1, 1, 2, 3, 5, 8, …) on a 5-minute base, capped at the daily ceiling.
	// alertCount 0 and 1 both map to multiplier 1 (5 m), giving exactly two
	// 5-minute gaps before the sequence begins to grow.
	private function escalatingRepeatIntervalSeconds(int $alertCount): int {
		$step = max(1, $alertCount + 1);
		$previous = 0;
		$current = 1;

		for ($i = 1; $i < $step; $i++) {
			$next = $previous + $current;
			$previous = $current;
			$current = $next;

			if ($current * self::REPEAT_ESCALATING_BASE_SECONDS >= self::REPEAT_ESCALATING_CEILING_SECONDS) {
				return self::REPEAT_ESCALATING_CEILING_SECONDS;
			}
		}

		return min(
			self::REPEAT_ESCALATING_CEILING_SECONDS,
			$current * self::REPEAT_ESCALATING_BASE_SECONDS
		);
	}

	private function positiveRequestId(string $key): int {
		$value = isset($_REQUEST[$key]) ? (string)$_REQUEST[$key] : '';
		if (!ctype_digit($value)) {
			return 0;
		}

		$id = (int)$value;
		return $id > 0 ? $id : 0;
	}

	private function getAlertSettings(): array {
		$settings = $this->settingsDefaults;
		$stmt = $this->db()->query('SELECT setting_key, setting_value FROM registrationwatch_settings');
		if ($stmt) {
			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$key = (string)$row['setting_key'];
				if (array_key_exists($key, $settings)) {
					$settings[$key] = (string)$row['setting_value'];
				}
			}
		}

		return $settings;
	}

	private function setSetting(string $key, string $value): void {
		$stmt = $this->db()->prepare(
			'INSERT INTO registrationwatch_settings (setting_key, setting_value, updated_at)
			VALUES (:setting_key, :setting_value, :updated_at)
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
		);
		$stmt->execute([
			':setting_key' => $key,
			':setting_value' => $value,
			':updated_at' => $this->now(),
		]);
	}

	private function normaliseRecipients(string $raw): array {
		$parts = preg_split('/[,;\s]+/', trim($raw));
		$recipients = [];

		foreach ($parts as $part) {
			$email = trim((string)$part);
			if ($email === '') {
				continue;
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				continue;
			}
			$recipients[strtolower($email)] = $email;
		}

		return array_values($recipients);
	}

	private function getRegistrationDetailsById(int $registrationId): array {
		$stmt = $this->db()->prepare(
			'SELECT id, registration_key, extension, description, last_known_status, contact_uri, source_ip, source_port,
				user_agent, device_name, firmware_version, contact_expires_at,
				qualify_frequency, last_checked_at
			FROM registrationwatch_registrations
			WHERE id = :id
			LIMIT 1'
		);
		$stmt->execute([':id' => $registrationId]);

		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return is_array($row) ? $this->withRegistrationDisplayAddress($row) : [];
	}

	private function withRegistrationDisplayAddress(array $registration): array {
		$addressDetails = $this->registrationAddressDetails(
			$registration['contact_uri'] ?? null,
			$registration['source_ip'] ?? null,
			$registration['source_port'] ?? null
		);
		$registration['device_ip'] = $addressDetails['device_ip'];
		$registration['device_port'] = $addressDetails['device_port'];
		$registration['network_ip'] = $addressDetails['network_ip'];
		$registration['network_port'] = $addressDetails['network_port'];
		$registration['seen_by_asterisk'] = $this->formatSeenByAsterisk(
			$registration['source_ip'] ?? null,
			$registration['source_port'] ?? null
		);

		return $registration;
	}

	private function formatSeenByAsterisk($sourceIp, $sourcePort): ?string {
		$sourceIp = trim((string)$sourceIp);
		if ($sourceIp === '') {
			return null;
		}

		if ($sourcePort !== null && $sourcePort !== '' && is_numeric($sourcePort)) {
			$port = (int)$sourcePort;
			if ($port > 0 && $port <= 65535) {
				return $sourceIp . ':' . $port;
			}
		}

		return $sourceIp;
	}

	private function buildAlertEmail(array $transition, string $alertType): array {
		$extension = (string)$transition['extension'];
		$toState = $this->normaliseState($transition['to_state'] ?? '');
		$subjectStatus = $this->stateLabel($toState);
		$isReminder = ($transition['reason'] ?? '') === 'reminder';
		if ($alertType === 'recovery') {
			$subjectStatus = 'has recovered';
		}

		$still = ($isReminder && $alertType !== 'recovery') ? 'still ' : '';
		$subject = 'Registration Watch: ' . $extension . ' ' . ($alertType === 'recovery' ? $subjectStatus : 'is ' . $still . $subjectStatus);
		$latency = $transition['latency_ms'] !== null && $transition['latency_ms'] !== '' ? $transition['latency_ms'] . ' ms' : 'Unavailable';
		if ($toState === self::STATUS_REGISTERED_NO_QUALIFY) {
			$latency = 'Unavailable; qualify is not enabled.';
		}
		$registrationDetails = !empty($transition['registration_id'])
			? $this->getRegistrationDetailsById((int)$transition['registration_id'])
			: [];
		$deviceName = trim((string)($registrationDetails['device_name'] ?? ''));
		$firmwareVersion = trim((string)($registrationDetails['firmware_version'] ?? ''));
		$userAgent = trim((string)($registrationDetails['user_agent'] ?? ''));
		$historicalAddress = $toState === self::STATUS_NOT_REGISTERED;
		$contactUriForAddress = $registrationDetails['contact_uri'] ?? null;
		$sourceIpForAddress = $registrationDetails['source_ip'] ?? null;
		$sourcePortForAddress = $registrationDetails['source_port'] ?? null;
		if ($toState === self::STATUS_NOT_REGISTERED) {
			$contactUriForAddress = $transition['contact_uri'] ?? null;
		}
		$addressDetails = $this->registrationAddressDetails($contactUriForAddress, $sourceIpForAddress, $sourcePortForAddress);
		$addressPrefix = $historicalAddress ? 'Last ' : '';
		$reasonLine = ['Reason: ' . $this->reasonLabel($transition['reason'] ?? '')];
		if ($isReminder) {
			$reasonLine[] = 'Repeat mode: ' . $this->repeatModeLabel((string)($transition['repeat_mode'] ?? ''));
			$reminderN = (int)($transition['reminder_n'] ?? 0);
			if ($reminderN > 0) {
				$reasonLine[] = 'Reminder: ' . $reminderN;
			}
		}
		$message = array_merge(
			[
				'Registration Watch state change',
				'',
				'Extension: ' . $extension,
				'New state: ' . $this->stateLabel($toState),
			],
			$reasonLine,
			[
				'Latency: ' . $latency,
				'',
				'Device: ' . ($deviceName !== '' ? $deviceName : 'Unknown'),
				'Version: ' . ($firmwareVersion !== '' ? $firmwareVersion : 'Unknown'),
				$addressPrefix . 'Device IP: ' . (($addressDetails['device_ip'] ?? '') !== '' ? $addressDetails['device_ip'] : 'Unknown'),
				$addressPrefix . 'Device Port: ' . (($addressDetails['device_port'] ?? '') !== '' ? $addressDetails['device_port'] : 'Unknown'),
				$addressPrefix . 'Network IP: ' . (($addressDetails['network_ip'] ?? '') !== '' ? $addressDetails['network_ip'] : 'Unknown'),
				$addressPrefix . 'Network Port: ' . (($addressDetails['network_port'] ?? '') !== '' ? $addressDetails['network_port'] : 'Unknown'),
				'Contact expires: ' . (($registrationDetails['contact_expires_at'] ?? '') !== '' ? $registrationDetails['contact_expires_at'] : 'Unknown'),
				'Qualify frequency: ' . (($registrationDetails['qualify_frequency'] ?? '') !== '' ? $registrationDetails['qualify_frequency'] . ' seconds' : 'Unknown'),
				'Transition time: ' . $transition['created_at'],
				'Source: Asterisk',
				'',
				'Please note: email deliveries can be delayed.',
				'Check current status in the FreePBX module.',
			]
		);

		return [
			'subject' => $subject,
			'message' => implode("\n", $message),
		];
	}

	private function sendEmail(string $recipient, string $subject, string $message): array {
		// Follow the broad FreePBX missedcall CI_Email pattern. Do not fall
		// back to raw mail(); failures are returned for alert-history storage.
		try {
			if (!class_exists('\CI_Email')) {
				return ['status' => false, 'message' => 'CI_Email is not available.'];
			}

			$from = $this->getNotificationFromAddress();
			if ($from === '') {
				return ['status' => false, 'message' => 'Email "From:" Address is not configured in Advanced Settings.'];
			}
			$senderName = $this->getNotificationSenderName();

			$email = new \CI_Email();
			if ($this->emailFromSupportsReturnPath($email)) {
				$email->from($from, $senderName, $from);
			} else {
				$email->from($from, $senderName);
				if (method_exists($email, 'set_header')) {
					$email->set_header('Return-Path', $from);
				}
			}
			if (method_exists($email, 'reply_to')) {
				$email->reply_to($from, $senderName);
			}
			$email->to($recipient);
			$email->subject($subject);
			$email->set_mailtype('text');
			$email->message($message);

			if ($email->send()) {
				return ['status' => true, 'message' => 'accepted by local mailer; delivery not confirmed'];
			}

			$error = 'CI_Email send failed.';
			if (method_exists($email, 'print_debugger')) {
				$debug = trim(strip_tags((string)$email->print_debugger(['headers'])));
				if ($debug !== '') {
					$error .= ' ' . $debug;
				}
			}

			return ['status' => false, 'message' => $error];
		} catch (\Exception $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}
	}

	private function getNotificationFromAddress(): string {
		if (method_exists($this, 'fetchFromEmail')) {
			$email = $this->normaliseEmailAddress((string)$this->fetchFromEmail());
			if ($email !== '') {
				return $email;
			}
		}

		try {
			$email = $this->normaliseEmailAddress((string)\FreePBX::Config()->get('AMPUSERMANEMAILFROM'));
			if ($email !== '') {
				return $email;
			}
		} catch (\Exception $e) {
			// Fall through and fail safely rather than guessing a sender domain.
		}

		return '';
	}

	private function normaliseEmailAddress(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		if (preg_match('/<([^>]+)>/', $value, $matches)) {
			$value = trim($matches[1]);
		}

		return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
	}

	private function getNotificationSenderName(): string {
		try {
			$brand = (string)\FreePBX::Config()->get('DASHBOARD_FREEPBX_BRAND');
			if ($brand !== '') {
				return $brand;
			}
		} catch (\Exception $e) {
			// Keep Registration Watch as the sender name fallback.
		}

		return 'Registration Watch';
	}

	private function emailFromSupportsReturnPath($email): bool {
		try {
			$method = new \ReflectionMethod($email, 'from');
			return $method->getNumberOfParameters() >= 3;
		} catch (\ReflectionException $e) {
			return false;
		}
	}

	private function insertAlertHistory(array $alert): void {
		$stmt = $this->db()->prepare(
			'INSERT IGNORE INTO registrationwatch_alert_history
				(registration_id, registration_key, extension, contact_uri, history_id, reminder_n, alert_type, status, recipient, subject, message, sent_at, result, error)
			VALUES
				(:registration_id, :registration_key, :extension, :contact_uri, :history_id, :reminder_n, :alert_type, :status, :recipient, :subject, :message, :sent_at, :result, :error)'
		);
		$stmt->execute([
			':registration_id' => $alert['registration_id'] ?? null,
			':registration_key' => $alert['registration_key'] ?? null,
			':extension' => $alert['extension'],
			':contact_uri' => $alert['contact_uri'] ?? null,
			':history_id' => $alert['history_id'],
			':reminder_n' => $alert['reminder_n'] ?? 0,
			':alert_type' => $alert['alert_type'],
			':status' => $alert['status'],
			':recipient' => $alert['recipient'],
			':subject' => $alert['subject'],
			':message' => $alert['message'],
			':sent_at' => $alert['sent_at'],
			':result' => $alert['result'],
			':error' => $alert['error'],
		]);
	}

	private function reserveAlertHistory(array $alert): bool {
		$stmt = $this->db()->prepare(
			'INSERT IGNORE INTO registrationwatch_alert_history
				(registration_id, registration_key, extension, contact_uri, history_id, reminder_n, alert_type, status, recipient, subject, message, sent_at, result, error)
			VALUES
				(:registration_id, :registration_key, :extension, :contact_uri, :history_id, :reminder_n, :alert_type, :status, :recipient, :subject, :message, :sent_at, :result, :error)'
		);
		$stmt->execute([
			':registration_id' => $alert['registration_id'] ?? null,
			':registration_key' => $alert['registration_key'] ?? null,
			':extension' => $alert['extension'],
			':contact_uri' => $alert['contact_uri'] ?? null,
			':history_id' => $alert['history_id'],
			':reminder_n' => $alert['reminder_n'] ?? 0,
			':alert_type' => $alert['alert_type'],
			':status' => $alert['status'],
			':recipient' => $alert['recipient'],
			':subject' => $alert['subject'],
			':message' => $alert['message'],
			':sent_at' => $alert['sent_at'],
			':result' => $alert['result'],
			':error' => $alert['error'],
		]);

		return $stmt->rowCount() > 0;
	}

	private function updateReservedAlertHistory(int $historyId, string $alertType, string $recipient, string $result, ?string $error, string $sentAt, int $reminderN = 0): void {
		$stmt = $this->db()->prepare(
			'UPDATE registrationwatch_alert_history
			SET sent_at = :sent_at,
				result = :result,
				error = :error
			WHERE history_id = :history_id
				AND alert_type = :alert_type
				AND recipient = :recipient
				AND reminder_n = :reminder_n'
		);
		$stmt->execute([
			':sent_at' => $sentAt,
			':result' => $result,
			':error' => $error,
			':history_id' => $historyId,
			':alert_type' => $alertType,
			':recipient' => $recipient,
			':reminder_n' => $reminderN,
		]);
	}

	private function tableExists(string $table): bool {
		$stmt = $this->db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
		$stmt->execute([':table' => $table]);
		return (int)$stmt->fetchColumn() > 0;
	}

	private function createCsrfToken(): string {
		if (!$this->ensureSessionForCsrfWrite()) {
			return '';
		}

		$token = isset($_SESSION[self::CSRF_SESSION_KEY]) ? (string)$_SESSION[self::CSRF_SESSION_KEY] : '';
		if ($token !== '') {
			return $token;
		}

		try {
			$token = bin2hex(random_bytes(32));
			$_SESSION[self::CSRF_SESSION_KEY] = $token;
			return $token;
		} catch (\Throwable $e) {
			$this->logWarning('Unable to create Registration Watch CSRF token: ' . $e->getMessage());
			return '';
		}
	}

	private function ensureSessionForCsrfWrite(): bool {
		if (!function_exists('session_status')) {
			return isset($_SESSION) && is_array($_SESSION);
		}

		$status = session_status();
		if ($status === PHP_SESSION_ACTIVE) {
			return true;
		}

		if ($status === PHP_SESSION_DISABLED || headers_sent()) {
			return false;
		}

		return @session_start();
	}

	private function validateCsrfToken(): bool {
		$sessionToken = isset($_SESSION[self::CSRF_SESSION_KEY]) ? (string)$_SESSION[self::CSRF_SESSION_KEY] : '';
		if ($sessionToken === '') {
			return false;
		}

		$token = isset($_REQUEST['token']) ? (string)$_REQUEST['token'] : '';
		if ($token === '') {
			return false;
		}

		return hash_equals($sessionToken, $token);
	}

	private function logError(string $message): void {
		try {
			if (method_exists('\FreePBX', 'Log')) {
				\FreePBX::Log()->error('registrationwatch: ' . $message);
			}
		} catch (\Exception $e) {
			// Logging unavailable; silently continue
		}
	}

	private function logWarning(string $message): void {
		try {
			if (method_exists('\FreePBX', 'Log')) {
				\FreePBX::Log()->warning('registrationwatch: ' . $message);
			}
		} catch (\Exception $e) {
			// Logging unavailable; silently continue
		}
	}

	private function logInfo(string $message): void {
		try {
			if (method_exists('\FreePBX', 'Log')) {
				\FreePBX::Log()->info('registrationwatch: ' . $message);
			}
		} catch (\Exception $e) {
			// Logging unavailable; silently continue
		}
	}


	public function runBackgroundMonitor($output = null): bool {
		try {
			$settings = $this->getAlertSettings();
			$this->applyHistoryPruning();

			$interval = (int)($settings['topology_poll_interval_seconds'] ?? 10);
			if ($interval <= 0) {
				if ($output && method_exists($output, 'writeln')) {
					$output->writeln('Registration Watch background job skipped: polling disabled.');
				}
				return true;
			}

			$this->reconcileCurrentStatus();

			if ($output && method_exists($output, 'writeln')) {
				if (($settings['alert_enabled'] ?? '0') === '1') {
					$output->writeln('Registration Watch background job completed.');
				} else {
					$output->writeln('Registration Watch background job completed; alerts disabled.');
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logError('Background job failed: ' . $e->getMessage());

			if ($output && method_exists($output, 'writeln')) {
				$output->writeln('<error>Registration Watch background job failed: ' . $e->getMessage() . '</error>');
			}

			return false;
		}
	}

	private function getEmailStatus(): array {
		$settings = $this->getAlertSettings();
		$recipients = $this->normaliseRecipients($settings['alert_recipients']);

		return [
			'ci_email_available' => class_exists('\CI_Email'),
			'alerts_enabled' => $settings['alert_enabled'] === '1',
			'recipients_configured' => count($recipients) > 0,
			'recipient_count' => count($recipients),
		];
	}

	private function db() {
		return \FreePBX::Database();
	}

	private function sourceLabel(?string $source): string {
		$source = trim((string)$source);

		if ($source === 'reconcile') {
			return 'Asterisk';
		}

		return $source !== '' ? $source : '-';
	}

	private function reasonLabel(?string $reason): string {
		$reason = trim((string)$reason);

		switch ($reason) {
			case 'status_change':
				return 'Status changed';
			case 'removed':
				return 'Contact removed';
			case 'reminder':
				return 'Repeat alert';
		}

		return $reason !== '' ? $reason : '-';
	}

	private function repeatModeLabel(string $repeatMode): string {
		switch ($this->normaliseRepeatMode($repeatMode)) {
			case self::REPEAT_MODE_FIVE_MINUTES: return 'Every 5 minutes';
			case self::REPEAT_MODE_HOURLY:       return 'Hourly';
			case self::REPEAT_MODE_DAILY:        return 'Daily';
			case self::REPEAT_MODE_ESCALATING:   return 'Escalating';
			default:                             return $repeatMode !== '' ? $repeatMode : 'Never';
		}
	}

	private function stateLabel(?string $state): string {
		$state = $this->normaliseState($state);

		return $state !== '' ? $state : '-';
	}

	private function normaliseState($state): string {
		$state = trim((string)$state);

		switch (strtolower($state)) {
			case 'not_registered':
			case 'not registered':
				return self::STATUS_NOT_REGISTERED;
			case 'registered_no_qualify':
			case 'registered (no qualify)':
				return self::STATUS_REGISTERED_NO_QUALIFY;
		}

		return $state;
	}

	private function isMonitoringSnoozed(): bool {
		$settings = $this->getAlertSettings();
		$until = trim((string)($settings['monitoring_snoozed_until'] ?? ''));
		if ($until === '') {
			return false;
		}
		$ts = strtotime($until);
		return $ts !== false && $ts > strtotime($this->now());
	}

	private function hasMonitoredExtensions(): bool {
		try {
			$stmt = $this->db()->query(
				'SELECT COUNT(*) FROM registrationwatch_registrations WHERE enabled = 1 AND discovered = 1'
			);
			return $stmt ? (int)$stmt->fetchColumn() > 0 : false;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function getMonitoringState(): array {
		if (!$this->hasMonitoredExtensions()) {
			return ['state' => 'inactive', 'snoozed_until' => null];
		}
		$settings = $this->getAlertSettings();
		$until = trim((string)($settings['monitoring_snoozed_until'] ?? ''));
		if ($until !== '') {
			$ts = strtotime($until);
			if ($ts !== false && $ts > strtotime($this->now())) {
				return ['state' => 'snoozed', 'snoozed_until' => $until];
			}
		}
		return ['state' => 'active', 'snoozed_until' => null];
	}

	private function now(): string {
		return date('Y-m-d H:i:s');
	}
}
