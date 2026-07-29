<?php
/**
 * Registration Watch main view.
 *
 * @var string $moduleVersion
 * @var array $registrations
 * @var array $statusHistory
 * @var array $alertSettings
 * @var array $pruneSettings
 * @var array $alertHistory
 * @var string $lastRefresh
 * @var string $refreshError
 * @var array $emailStatus
 * @var array $timeDiagnostics
 * @var int $pollIntervalSeconds
 * @var string $csrfToken
 */
if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

$registrations = isset($registrations) && is_array($registrations) ? $registrations : [];
$watchedExtensions = isset($watchedExtensions) && is_array($watchedExtensions) ? $watchedExtensions : [];
$statusHistory = isset($statusHistory) && is_array($statusHistory) ? $statusHistory : [];
$alertSettings = isset($alertSettings) && is_array($alertSettings) ? $alertSettings : [];
$pruneSettings = isset($pruneSettings) && is_array($pruneSettings) ? $pruneSettings : [];
$alertHistory = isset($alertHistory) && is_array($alertHistory) ? $alertHistory : [];
$mapRegistrations = array_values(array_filter($registrations, function ($registration) {
	return isset($registration['discovered']) && (int)$registration['discovered'] === 1;
}));
$uiShowLimit = isset($alertSettings['ui_show_limit']) ? strtolower((string)$alertSettings['ui_show_limit']) : '6';
if (!in_array($uiShowLimit, ['6', '30', '60', '120', 'all'], true)) {
	$uiShowLimit = '6';
}
$uiMapViewMode = in_array($alertSettings['ui_map_view_mode'] ?? 'card', ['card', 'row'], true) ? $alertSettings['ui_map_view_mode'] : 'card';
$uiMapSortKey = (string)($alertSettings['ui_map_sort_key'] ?? 'extension');
$uiMapSortDir = in_array($alertSettings['ui_map_sort_dir'] ?? 'asc', ['asc', 'desc'], true) ? $alertSettings['ui_map_sort_dir'] : 'asc';
$uiWatchedSortKey = (string)($alertSettings['ui_watched_sort_key'] ?? 'extension');
$uiWatchedSortDir = in_array($alertSettings['ui_watched_sort_dir'] ?? 'asc', ['asc', 'desc'], true) ? $alertSettings['ui_watched_sort_dir'] : 'asc';
$uiStatusHistorySortKey = (string)($alertSettings['ui_status_history_sort_key'] ?? 'time');
$uiStatusHistorySortDir = in_array($alertSettings['ui_status_history_sort_dir'] ?? 'desc', ['asc', 'desc'], true) ? $alertSettings['ui_status_history_sort_dir'] : 'desc';
$uiAlertHistorySortKey = (string)($alertSettings['ui_alert_history_sort_key'] ?? 'time');
$uiAlertHistorySortDir = in_array($alertSettings['ui_alert_history_sort_dir'] ?? 'desc', ['asc', 'desc'], true) ? $alertSettings['ui_alert_history_sort_dir'] : 'desc';
$statusPrunePolicy = isset($pruneSettings['status_history_prune_policy']) ? strtolower((string)$pruneSettings['status_history_prune_policy']) : 'never';
$alertPrunePolicy = isset($pruneSettings['alert_history_prune_policy']) ? strtolower((string)$pruneSettings['alert_history_prune_policy']) : 'never';
if (!in_array($statusPrunePolicy, ['hourly', 'daily', 'monthly', 'yearly', 'never'], true)) {
	$statusPrunePolicy = 'never';
}
if (!in_array($alertPrunePolicy, ['hourly', 'daily', 'monthly', 'yearly', 'never'], true)) {
	$alertPrunePolicy = 'never';
}
$mapDefaultLimit = $uiShowLimit === 'all' ? count($mapRegistrations) : (int)$uiShowLimit;
$mapVisibleRegistrations = $uiShowLimit === 'all' ? $mapRegistrations : array_slice($mapRegistrations, 0, $mapDefaultLimit);
$mapRegistrationTotal = count($mapRegistrations);
$mapVisibleCount = count($mapVisibleRegistrations);
$lastRefresh = isset($lastRefresh) ? (string)$lastRefresh : '';
$refreshError = isset($refreshError) ? (string)$refreshError : '';
$emailStatus = isset($emailStatus) && is_array($emailStatus) ? $emailStatus : [
	'ci_email_available' => false,
	'alerts_enabled' => false,
	'recipients_configured' => false,
	'recipient_count' => 0,
];
$timeDiagnostics = isset($timeDiagnostics) && is_array($timeDiagnostics) ? $timeDiagnostics : [
	'module_time' => '',
	'database_time' => '',
];
$pollIntervalSeconds = isset($pollIntervalSeconds) ? (int)$pollIntervalSeconds : 10;
$registrationEmptyText = $pollIntervalSeconds > 0
	? sprintf(_('No watched extensions discovered yet. Registration Watch checks automatically every %d seconds; extensions will appear here once discovered.'), $pollIntervalSeconds)
	: _('No watched extensions discovered yet. Registration Watch will show extensions here once automatic checks run.');
$csrfToken = isset($csrfToken) ? (string)$csrfToken : '';
$monitoringState = isset($monitoringState) && is_array($monitoringState) ? $monitoringState : ['state' => 'active', 'snoozed_until' => null];
$_rwMonitoringBannerState = in_array($monitoringState['state'] ?? '', ['active', 'inactive', 'snoozed'], true) ? $monitoringState['state'] : 'active';
$repeatModeOptions = [
	'never' => _('Never'),
	'5m' => _('Every 5 minutes'),
	'hourly' => _('Hourly'),
	'daily' => _('Daily'),
	'escalating' => _('Escalating'),
];
$currentRepeatMode = isset($alertSettings['repeat_mode']) ? strtolower((string)$alertSettings['repeat_mode']) : 'never';
if ($currentRepeatMode === 'fibonacci') {
	$currentRepeatMode = 'escalating';
}
if (!array_key_exists($currentRepeatMode, $repeatModeOptions)) {
	$currentRepeatMode = 'never';
}

$_rwStatusClass = function ($status) {
	switch (strtolower(trim((string)$status))) {
		case 'reachable':
			return 'rw-led-green';
		case 'registered (no qualify)':
		case 'registered_no_qualify':
			return 'rw-led-amber';
		case 'unreachable':
		case 'not registered':
		case 'not_registered':
			return 'rw-led-red';
		case 'unknown':
		default:
			return 'rw-led-grey';
	}
};

$_rwIsRegisteredNoQualify = function ($status) {
	$status = strtolower(trim((string)$status));
	return $status === 'registered (no qualify)' || $status === 'registered_no_qualify';
};

$_rwDisplayLabel = function ($value) {
	$value = trim((string)$value);
	if ($value === '') {
		return '-';
	}

	$labels = [
		'recovery' => 'Recovery',
		'unreachable' => 'Unreachable',
		'not_registered' => 'Not registered',
		'not registered' => 'Not registered',
		'registered_no_qualify' => 'Registered (no qualify)',
		'registered (no qualify)' => 'Registered (no qualify)',
		'status_change' => 'Status changed',
		'status changed' => 'Status changed',
		'removed' => 'Contact removed',
		'contact removed' => 'Contact removed',
		'reminder' => 'Repeat alert',
		'repeat alert' => 'Repeat alert',
		'ip_address_change' => 'IP Address Change',
		'ip address change' => 'IP Address Change',
		'storm_summary' => 'Storm Summary',
		'storm summary' => 'Storm Summary',
		'storm_suppressed' => 'Storm suppressed',
		'storm_summary_failed' => 'Storm summary failed',
		'sent' => 'Sent',
		'failed' => 'Failed',
		'pending' => 'Pending',
		'suppressed' => 'Suppressed',
		'test' => 'Test',
	];

	$key = strtolower($value);
	if (isset($labels[$key])) {
		return $labels[$key];
	}

	return ucwords(str_replace('_', ' ', $value));
};

$_rwHistoryRowClass = function ($toState) {
	$s = strtolower(trim((string)$toState));
	if ($s === 'reachable' || $s === 'registered (no qualify)') {
		return 'rw-history-row-ok';
	}
	if ($s === 'unreachable' || $s === 'not registered') {
		return 'rw-history-row-bad';
	}
	return 'rw-history-row-neutral';
};

$_rwAlertHistoryRowClass = function ($alertType) {
	$s = strtolower(trim((string)$alertType));
	if ($s === 'recovery') {
		return 'rw-alert-history-row-ok';
	}
	if ($s === 'unreachable' || $s === 'not_registered' || $s === 'not registered') {
		return 'rw-alert-history-row-bad';
	}
	return 'rw-alert-history-row-neutral';
};

$_rwContactExpiryText = function ($expiresAt) {
	$expiresAt = trim((string)$expiresAt);
	if ($expiresAt === '') {
		return '-';
	}

	$expiryTimestamp = strtotime($expiresAt);
	if ($expiryTimestamp === false) {
		return '-';
	}

	$remainingSeconds = $expiryTimestamp - time();
	if ($remainingSeconds < 0) {
		return _('Expired');
	}

	return (string)$remainingSeconds . 's';
};

$_rwDisplayContact = function ($value) {
	$value = trim((string)$value);
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
};

$_rwIsActivelyAlerting = function ($registration) {
	if (empty($registration['enabled'])) {
		return false;
	}
	$status = strtolower(trim((string)($registration['last_known_status'] ?? '')));
	return $status === 'unreachable' || $status === 'not registered';
};

$_rwMapDetailRows = function ($registration) use ($_rwContactExpiryText, $_rwIsRegisteredNoQualify) {
	return [
		[_('Device IP'), ($registration['device_ip'] ?? '') !== '' ? (string)$registration['device_ip'] : '-'],
		[_('Device Port'), ($registration['device_port'] ?? '') !== '' ? (string)$registration['device_port'] : '-'],
		[_('Network IP'), ($registration['network_ip'] ?? '') !== '' ? (string)$registration['network_ip'] : '-'],
		[_('Network Port'), ($registration['network_port'] ?? '') !== '' ? (string)$registration['network_port'] : '-'],
		[_('Device'), ($registration['device_name'] ?? '') !== '' ? (string)$registration['device_name'] : '-'],
		[_('Version'), ($registration['firmware_version'] ?? '') !== '' ? (string)$registration['firmware_version'] : '-'],
		[_('Contact expires'), $_rwContactExpiryText($registration['contact_expires_at'] ?? '')],
		[_('Qualify'), ($registration['qualify_frequency'] ?? '') !== '' ? (string)$registration['qualify_frequency'] . ' ' . _('seconds') : '-'],
		[_('Latency'), ($registration['latency_ms'] ?? null) !== null && $registration['latency_ms'] !== ''
			? (string)$registration['latency_ms'] . ' ms'
			: ($_rwIsRegisteredNoQualify($registration['last_known_status'] ?? '') ? _('Unavailable; qualify is not enabled.') : '-')],
	];
};

$_rwAssetVer = max(
	@filemtime(__DIR__ . '/../assets/js/registrationwatch.js') ?: 0,
	@filemtime(__DIR__ . '/../assets/css/registrationwatch.css') ?: 0
) ?: time();
?>
<link rel="stylesheet" href="modules/registrationwatch/assets/css/registrationwatch.css?v=<?php echo $_rwAssetVer; ?>">

<div class="registrationwatch" data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" data-poll-interval="<?php echo (int)$pollIntervalSeconds; ?>">
	<input type="hidden" name="token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
	<div class="row">
		<div class="col-sm-12">
			<h1>
				<?php echo _('Registration Watch'); ?>
				<small class="text-muted" style="font-size:0.5em;">v<?php echo htmlspecialchars($moduleVersion, ENT_QUOTES, 'UTF-8'); ?></small>
			</h1>

			<div id="rw-monitoring-banner" class="rw-monitoring-banner rw-monitoring-<?php echo htmlspecialchars($_rwMonitoringBannerState, ENT_QUOTES, 'UTF-8'); ?>">
				<?php if ($_rwMonitoringBannerState === 'inactive'): ?>
					<div class="rw-monitoring-banner-body">
						<strong><?php echo _('Monitoring is inactive'); ?></strong>
						<p class="rw-monitoring-desc"><?php echo _('No watched extensions are currently monitored.'); ?></p>
					</div>
				<?php elseif ($_rwMonitoringBannerState === 'snoozed'): ?>
					<div class="rw-monitoring-banner-body">
						<strong><?php echo _('Monitoring is snoozed'); ?></strong>
						<p class="rw-monitoring-desc"><?php echo _('Alerts are paused. Status checks continue.'); ?></p>
						<p class="rw-monitoring-desc"><span id="rw-snooze-countdown"><?php echo _('Calculating...'); ?></span></p>
						<div class="rw-monitoring-actions">
							<button type="button" class="btn btn-sm btn-default rw-resume-btn"><?php echo _('Resume monitoring'); ?></button>
						</div>
					</div>
				<?php else: ?>
					<div class="rw-monitoring-banner-body">
						<strong><?php echo _('Monitoring active'); ?></strong>
						<p class="rw-monitoring-desc"><?php echo _('Status checks are running. Alerts will be sent for monitored extensions.'); ?></p>
						<div class="rw-monitoring-actions">
							<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="300"><?php echo _('Snooze 5m'); ?></button>
							<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="900"><?php echo _('Snooze 15m'); ?></button>
							<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="1800"><?php echo _('Snooze 30m'); ?></button>
							<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="3600"><?php echo _('Snooze 1h'); ?></button>
							<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="86400"><?php echo _('Snooze 1d'); ?></button>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<div id="rw-message" class="alert rw-message" style="display:none;"></div>
		</div>
	</div>

	<div class="row rw-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php echo _('Registration Status Map'); ?></h3>
				</div>
					<div class="panel-body">
						<p style="margin-bottom: 15px;">
							<strong><?php echo _('Auto Refresh Interval'); ?>:</strong>
							<?php if ($pollIntervalSeconds <= 0): ?>
								<span class="label label-default"><?php echo _('Disabled'); ?></span>
							<?php else: ?>
								<span class="label label-info"><?php echo $pollIntervalSeconds . ' ' . _('seconds'); ?></span>
							<?php endif; ?>
						</p>
						<div class="form-inline" style="margin-bottom: 15px;">
							<label for="rw-map-limit" style="margin-right: 6px;"><?php echo _('Show'); ?></label>
							<select id="rw-map-limit" class="form-control input-sm rw-shared-show-limit" data-section="map" style="width:auto;">
								<option value="6" <?php echo $uiShowLimit === '6' ? 'selected' : ''; ?>>6</option>
								<option value="30" <?php echo $uiShowLimit === '30' ? 'selected' : ''; ?>>30</option>
								<option value="60" <?php echo $uiShowLimit === '60' ? 'selected' : ''; ?>>60</option>
								<option value="120" <?php echo $uiShowLimit === '120' ? 'selected' : ''; ?>>120</option>
								<option value="all" <?php echo $uiShowLimit === 'all' ? 'selected' : ''; ?>><?php echo _('All'); ?></option>
							</select>
							<span id="rw-map-count" class="text-muted" style="margin-left: 10px;">
								<?php echo sprintf(_('Showing %d of %d EndPoints'), $mapVisibleCount, $mapRegistrationTotal); ?>
							</span>
							<div class="btn-group btn-group-xs" id="rw-map-view-toggle" style="margin-left: 16px;" role="group" aria-label="<?php echo _('Map view'); ?>">
								<button type="button" class="btn btn-default" id="rw-map-view-card" title="<?php echo _('Card view'); ?>"><i class="fa fa-th-large"></i> <?php echo _('Cards'); ?></button>
								<button type="button" class="btn btn-default" id="rw-map-view-row" title="<?php echo _('Row view'); ?>"><i class="fa fa-list"></i> <?php echo _('Rows'); ?></button>
							</div>
						</div>
					<div id="rw-topology-container" style="min-height: 200px;">
						<?php if (empty($mapRegistrations)): ?>
							<p class="text-muted"><?php echo htmlspecialchars($registrationEmptyText, ENT_QUOTES, 'UTF-8'); ?></p>
						<?php else: ?>
							<div class="rw-registration-map">
								<?php foreach ($mapVisibleRegistrations as $registration): ?>
									<div class="rw-map-tile">
										<div class="rw-map-title">
											<span class="rw-led <?php echo $_rwStatusClass($registration['last_known_status']); ?>"></span>
											<span><?php echo htmlspecialchars((string)$registration['extension'], ENT_QUOTES, 'UTF-8'); ?></span>
										</div>
										<div class="rw-map-description"><?php echo htmlspecialchars($registration['description'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="rw-map-status"><?php echo htmlspecialchars($_rwDisplayLabel($registration['last_known_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
										<?php foreach ($_rwMapDetailRows($registration) as $detailRow): ?>
											<div class="rw-map-detail"><?php echo htmlspecialchars($detailRow[0], ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars($detailRow[1], ENT_QUOTES, 'UTF-8'); ?></div>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row rw-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php echo _('Alert Settings'); ?></h3>
				</div>
				<div class="panel-body">
					<div class="row">
						<div class="col-sm-6">
							<h4><?php echo _('Alerting decision'); ?></h4>
							<div class="checkbox">
								<label>
									<input type="checkbox" id="rw-alert-enabled" <?php echo ($alertSettings['alert_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
									<?php echo _('Enable email alerts'); ?>
								</label>
							</div>
							<div class="form-group">
								<label for="rw-alert-recipients"><?php echo _('Recipients'); ?></label>
								<textarea id="rw-alert-recipients" class="form-control" rows="3" placeholder="<?php echo _('admin@example.com, support@example.com'); ?>"><?php echo htmlspecialchars($alertSettings['alert_recipients'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
							</div>
							<div class="checkbox">
								<label>
									<input type="checkbox" id="rw-alert-on-unreachable" <?php echo ($alertSettings['alert_on_unreachable'] ?? '1') === '1' ? 'checked' : ''; ?>>
									<?php echo _('Alert when a watched registration becomes unreachable'); ?>
								</label>
							</div>
							<div class="checkbox">
								<label>
									<input type="checkbox" id="rw-alert-on-not-registered" <?php echo ($alertSettings['alert_on_not_registered'] ?? '1') === '1' ? 'checked' : ''; ?>>
									<?php echo _('Alert when a watched registration becomes not registered'); ?>
								</label>
							</div>
							<div class="checkbox">
								<label>
									<input type="checkbox" id="rw-alert-on-recovery" <?php echo ($alertSettings['alert_on_recovery'] ?? '1') === '1' ? 'checked' : ''; ?>>
									<?php echo _('Alert when a watched registration recovers'); ?>
								</label>
							</div>
							<div class="form-group" style="margin-top:16px;">
								<label for="rw-debounce-seconds"><?php echo _('Debounce delay (seconds)'); ?></label>
								<input type="number" id="rw-debounce-seconds" class="form-control" min="0" max="86400" step="1" value="<?php echo htmlspecialchars($alertSettings['debounce_seconds'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
								<p class="help-block"><?php echo _('How long a problem must remain active before the first alert is sent. The default 0 seconds alerts immediately. Increase this value to reduce noise from short reloads, restarts, and transient network events. Maximum 86400 seconds, 24 hours.'); ?></p>
							</div>
						</div>
						<div class="col-sm-6">
							<h4><?php echo _('Alert tuning'); ?></h4>
							<div class="form-group">
								<label for="rw-repeat-mode"><?php echo _('Repeat alerts'); ?></label>
								<select id="rw-repeat-mode" class="form-control">
									<?php foreach ($repeatModeOptions as $modeValue => $modeLabel): ?>
										<option value="<?php echo htmlspecialchars($modeValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $currentRepeatMode === $modeValue ? 'selected' : ''; ?>>
											<?php echo htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8'); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="help-block">
									<?php echo _('Never: send only the initial state-change alert.'); ?><br>
									<?php echo _('Every 5 minutes: repeat every 5 minutes while the registration remains unavailable.'); ?><br>
									<?php echo _('Hourly: repeat once per hour while unavailable.'); ?><br>
									<?php echo _('Daily: repeat once per day while unavailable.'); ?><br>
									<?php echo _('Escalating: uses a Fibonacci-style backoff schedule, starting with shorter reminders and gradually increasing the interval up to daily.'); ?>
								</p>
							</div>
							<p class="help-block" style="margin-top:22px;margin-bottom:16px;"><strong><?php echo _('Per-extension repeat overrides can be set in the Watched Extensions table.'); ?></strong></p>
							<div class="form-group rw-storm-threshold-group">
								<label for="rw-storm-threshold"><?php echo _('Storm Threshold'); ?></label>
								<input type="number" id="rw-storm-threshold" class="form-control" min="0" max="10000" step="1" value="<?php echo htmlspecialchars($alertSettings['storm_threshold'] ?? '20', ENT_QUOTES, 'UTF-8'); ?>">
								<p class="help-block"><?php echo _('Storm Threshold limits large batches of alerts generated in the same processing pass. It reduces email floods from sudden widespread registration changes, but it is not full correlated-outage detection. The count is per registration. Use 0 to disable.'); ?></p>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-sm-12 text-right">
							<div class="rw-actions">
								<button type="button" id="rw-save-alerts" class="btn btn-primary">
									<i class="fa fa-save"></i> <?php echo _('Save'); ?>
								</button>
								<button type="button" id="rw-test-email" class="btn btn-default" disabled>
									<i class="fa fa-envelope"></i> <?php echo _('Test Email'); ?>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row rw-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php echo _('Watched Extensions'); ?></h3>
				</div>
				<div class="panel-body">
					<?php if (empty($watchedExtensions)): ?>
						<p class="rw-placeholder"><?php echo htmlspecialchars($registrationEmptyText, ENT_QUOTES, 'UTF-8'); ?></p>
					<?php else: ?>
						<div class="table-responsive rw-registrations-wrap">
							<table class="table table-striped table-condensed rw-registrations rw-watched-registrations-table">
								<colgroup>
									<col class="rw-col-selection">
									<col class="rw-col-extension">
									<col class="rw-col-description">
									<col class="rw-col-repeat">
									<col class="rw-col-notes">
								</colgroup>
								<thead>
									<tr>
										<th data-sort-key="monitored"><?php echo _('Monitored'); ?> <span class="rw-sort-arrow"></span></th>
										<th data-sort-key="extension"><?php echo _('Extension'); ?> <span class="rw-sort-arrow"></span></th>
										<th data-sort-key="description"><?php echo _('Description'); ?> <span class="rw-sort-arrow"></span></th>
										<th data-sort-key="repeat"><?php echo _('Repeat alerts'); ?> <span class="rw-sort-arrow"></span></th>
										<th data-sort-key="notes"><?php echo _('Notes'); ?> <span class="rw-sort-arrow"></span></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($watchedExtensions as $registration): ?>
										<tr data-registration-id="<?php echo (int)($registration['id'] ?? $registration['registration_id'] ?? 0); ?>" data-extension="<?php echo htmlspecialchars($registration['extension'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo !empty($registration['enabled']) ? 'class="rw-row-enabled"' : ''; ?>>
											<td data-label="<?php echo _('Monitored'); ?>">
												<?php if (!empty($registration['enabled']) && $_rwMonitoringBannerState === 'snoozed'): ?>
													<span class="rw-global-snoozed-label" title="<?php echo _('Global monitoring snooze is active'); ?>">💤 <?php echo _('Snoozed'); ?></span>
												<?php elseif ($_rwIsActivelyAlerting($registration)): ?>
													<div class="rw-alerting-cell">
														<small class="rw-alerting-indicator"><?php echo _('Actively alerting'); ?></small>
														<div class="rw-alerting-actions">
															<button type="button" class="btn btn-xs btn-warning rw-disable-alerting"
																	data-registration-id="<?php echo (int)($registration['id'] ?? $registration['registration_id'] ?? 0); ?>"
																	title="<?php echo _('Disable alerting for this extension'); ?>">
																<?php echo _('Disable alerting'); ?>
															</button>
															<button type="button" class="btn btn-xs btn-default rw-reset-from-asterisk"
																	data-registration-id="<?php echo (int)($registration['id'] ?? $registration['registration_id'] ?? 0); ?>"
																	title="<?php echo _('Refresh from Asterisk'); ?>">
																	<i class="fa fa-refresh"></i>
															</button>
														</div>
													</div>
												<?php elseif (!empty($registration['enabled'])): ?>
													<label class="rw-toggle">
														<input type="checkbox" class="rw-enabled" checked>
														<span class="rw-toggle-slider"></span>
													</label>
												<?php else: ?>
													<label class="rw-toggle">
														<input type="checkbox" class="rw-enabled">
														<span class="rw-toggle-slider"></span>
													</label>
												<?php endif; ?>
											</td>
											<td data-label="<?php echo _('Extension'); ?>">
												<?php echo htmlspecialchars($registration['extension'], ENT_QUOTES, 'UTF-8'); ?>
											</td>
											<td data-label="<?php echo _('Description'); ?>"><?php echo htmlspecialchars($registration['description'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
											<td data-label="<?php echo _('Repeat alerts'); ?>">
												<select class="form-control input-sm rw-repeat-mode" data-registration-id="<?php echo (int)($registration['id'] ?? $registration['registration_id'] ?? 0); ?>">
													<option value=""><?php echo _('Use global'); ?></option>
													<?php
													$registrationRepeatMode = isset($registration['repeat_mode']) ? (string)$registration['repeat_mode'] : '';
													if ($registrationRepeatMode === 'fibonacci') {
														$registrationRepeatMode = 'escalating';
													}
													?>
													<?php foreach ($repeatModeOptions as $modeValue => $modeLabel): ?>
														<option value="<?php echo htmlspecialchars($modeValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $registrationRepeatMode === $modeValue ? 'selected' : ''; ?>>
															<?php echo htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8'); ?>
														</option>
													<?php endforeach; ?>
												</select>
												<small class="text-muted rw-repeat-mode-status"></small>
											</td>
											<td data-label="<?php echo _('Notes'); ?>">
											<input
												type="text"
												class="form-control input-sm rw-registration-notes"
												data-registration-id="<?php echo (int)($registration['id'] ?? $registration['registration_id'] ?? 0); ?>"
												maxlength="72"
												value="<?php echo htmlspecialchars((string)($registration['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
												placeholder="<?php echo _('Add note...'); ?>"
											>
											<small class="text-muted rw-notes-status">
												<?php if (!empty($registration['notes_updated_at'])): ?>
													<?php echo _('Saved'); ?> <?php echo htmlspecialchars((string)$registration['notes_updated_at'], ENT_QUOTES, 'UTF-8'); ?>
												<?php endif; ?>
											</small>
										</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="row rw-section">
		<div class="col-sm-12">
				<div class="panel panel-default">
					<div class="panel-heading">
						<h3 class="panel-title"><?php echo _('Status History'); ?></h3>
					</div>
					<div class="panel-body">
						<div class="rw-history-control-row">
							<div class="rw-prune-control" data-history-type="status" data-active-policy="<?php echo htmlspecialchars($statusPrunePolicy, ENT_QUOTES, 'UTF-8'); ?>">
								<div class="form-inline rw-prune-inline">
									<label for="rw-status-prune-policy"><?php echo _('Prune'); ?></label>
									<select id="rw-status-prune-policy" class="form-control input-sm rw-prune-policy">
										<option value="never" <?php echo $statusPrunePolicy === 'never' ? 'selected' : ''; ?>><?php echo _('Never'); ?></option>
										<option value="hourly" <?php echo $statusPrunePolicy === 'hourly' ? 'selected' : ''; ?>><?php echo _('Hourly'); ?></option>
										<option value="daily" <?php echo $statusPrunePolicy === 'daily' ? 'selected' : ''; ?>><?php echo _('Daily'); ?></option>
										<option value="monthly" <?php echo $statusPrunePolicy === 'monthly' ? 'selected' : ''; ?>><?php echo _('Monthly'); ?></option>
										<option value="yearly" <?php echo $statusPrunePolicy === 'yearly' ? 'selected' : ''; ?>><?php echo _('Yearly'); ?></option>
									</select>
									<button type="button" class="btn btn-default btn-sm rw-apply-prune"><?php echo _('Apply'); ?></button>
								</div>
								<label class="checkbox-inline rw-prune-confirm-wrap" style="display:none;">
									<input type="checkbox" class="rw-prune-confirm">
									<?php echo _('I understand this will permanently delete older Status History rows.'); ?>
								</label>
							</div>
							<div class="rw-history-show-slot" data-show-section="status-history"></div>
						</div>
						<?php if (empty($statusHistory)): ?>
							<p class="rw-placeholder rw-history-empty"><?php echo _('No status transitions have been recorded yet.'); ?></p>
						<?php else: ?>
							<p class="rw-placeholder rw-history-empty" style="display:none;"><?php echo _('No status transitions have been recorded yet.'); ?></p>
						<?php endif; ?>
					<div class="table-responsive rw-history-wrap" style="<?php echo empty($statusHistory) ? 'display:none;' : ''; ?>">
						<table class="table table-striped table-condensed rw-history">
							<thead>
								<tr>
									<th data-sort-key="time"><?php echo _('Time'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="extension"><?php echo _('Extension'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="from"><?php echo _('From'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="to"><?php echo _('To'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="source"><?php echo _('Source'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="reason"><?php echo _('Reason'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="latency"><?php echo _('Latency'); ?> <span class="rw-sort-arrow"></span></th>
									<th><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($statusHistory as $entry): ?>
									<tr data-history-id="<?php echo (int)($entry['id'] ?? 0); ?>" class="<?php echo $_rwHistoryRowClass($entry['to_state'] ?? ''); ?>">
										<td data-label="<?php echo _('Time'); ?>"><?php echo htmlspecialchars($entry['created_at'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Extension'); ?>"><?php echo htmlspecialchars($entry['extension'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('From'); ?>"><?php echo htmlspecialchars($_rwDisplayLabel($entry['from_state'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('To'); ?>"><?php echo htmlspecialchars($_rwDisplayLabel($entry['to_state'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Source'); ?>"><?php echo htmlspecialchars($_rwDisplayLabel($entry['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Reason'); ?>"><?php echo htmlspecialchars($_rwDisplayLabel($entry['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Latency'); ?>"><?php echo $entry['latency_ms'] !== null && $entry['latency_ms'] !== '' ? htmlspecialchars((string)$entry['latency_ms'], ENT_QUOTES, 'UTF-8') . ' ms' : '-'; ?></td>
										<td data-label="<?php echo _('Actions'); ?>">
											<button type="button" class="btn btn-xs btn-danger rw-delete-status-history" data-history-id="<?php echo (int)($entry['id'] ?? 0); ?>" title="<?php echo _('Delete Status History row'); ?>">
												<i class="fa fa-trash"></i>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row rw-section">
		<div class="col-sm-12">
				<div class="panel panel-default">
					<div class="panel-heading">
						<h3 class="panel-title"><?php echo _('Alert History'); ?></h3>
					</div>
					<div class="panel-body">
						<div class="rw-history-control-row">
							<div class="rw-prune-control" data-history-type="alert" data-active-policy="<?php echo htmlspecialchars($alertPrunePolicy, ENT_QUOTES, 'UTF-8'); ?>">
								<div class="form-inline rw-prune-inline">
									<label for="rw-alert-prune-policy"><?php echo _('Prune'); ?></label>
									<select id="rw-alert-prune-policy" class="form-control input-sm rw-prune-policy">
										<option value="never" <?php echo $alertPrunePolicy === 'never' ? 'selected' : ''; ?>><?php echo _('Never'); ?></option>
										<option value="hourly" <?php echo $alertPrunePolicy === 'hourly' ? 'selected' : ''; ?>><?php echo _('Hourly'); ?></option>
										<option value="daily" <?php echo $alertPrunePolicy === 'daily' ? 'selected' : ''; ?>><?php echo _('Daily'); ?></option>
										<option value="monthly" <?php echo $alertPrunePolicy === 'monthly' ? 'selected' : ''; ?>><?php echo _('Monthly'); ?></option>
										<option value="yearly" <?php echo $alertPrunePolicy === 'yearly' ? 'selected' : ''; ?>><?php echo _('Yearly'); ?></option>
									</select>
									<button type="button" class="btn btn-default btn-sm rw-apply-prune"><?php echo _('Apply'); ?></button>
								</div>
								<label class="checkbox-inline rw-prune-confirm-wrap" style="display:none;">
									<input type="checkbox" class="rw-prune-confirm">
									<?php echo _('I understand this will permanently delete older Alert History rows.'); ?>
								</label>
							</div>
							<div class="rw-history-show-slot" data-show-section="alert-history"></div>
						</div>
						<?php if (empty($alertHistory)): ?>
							<p class="rw-placeholder rw-alert-history-empty"><?php echo _('No alert attempts have been recorded yet.'); ?></p>
						<?php else: ?>
							<p class="rw-placeholder rw-alert-history-empty" style="display:none;"><?php echo _('No alert attempts have been recorded yet.'); ?></p>
						<?php endif; ?>
					<div class="table-responsive rw-alert-history-wrap" style="<?php echo empty($alertHistory) ? 'display:none;' : ''; ?>">
						<table class="table table-striped table-condensed rw-alert-history">
							<thead>
								<tr>
									<th data-sort-key="time"><?php echo _('Time'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="extension"><?php echo _('Extension'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="type"><?php echo _('Type'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="status"><?php echo _('Status'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="recipient"><?php echo _('Recipient'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="result"><?php echo _('Result'); ?> <span class="rw-sort-arrow"></span></th>
									<th data-sort-key="error"><?php echo _('Error'); ?> <span class="rw-sort-arrow"></span></th>
									<th><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($alertHistory as $entry): ?>
									<tr data-history-id="<?php echo (int)($entry['id'] ?? 0); ?>" class="<?php echo $_rwAlertHistoryRowClass($entry['alert_type'] ?? ''); ?>">
										<td data-label="<?php echo _('Time'); ?>"><?php echo htmlspecialchars($entry['sent_at'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Extension'); ?>"><?php echo htmlspecialchars($entry['extension'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Type'); ?>"><?php echo htmlspecialchars($_rwDisplayLabel($entry['alert_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Status'); ?>"><?php echo htmlspecialchars($_rwDisplayLabel($entry['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Recipient'); ?>"><?php echo htmlspecialchars($entry['recipient'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Result'); ?>"><?php echo htmlspecialchars($_rwDisplayLabel($entry['result'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Error'); ?>"><?php echo htmlspecialchars($entry['error'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Actions'); ?>">
											<button type="button" class="btn btn-xs btn-danger rw-delete-alert-history" data-history-id="<?php echo (int)($entry['id'] ?? 0); ?>" title="<?php echo _('Delete Alert History row'); ?>">
												<i class="fa fa-trash"></i>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="rw-reset-extension-modal" tabindex="-1" role="dialog" aria-labelledby="rw-reset-extension-modal-title" aria-hidden="true">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="<?php echo _('Cancel'); ?>"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title" id="rw-reset-extension-modal-title"><?php echo _('Refresh from Asterisk?'); ?></h4>
			</div>
			<div class="modal-body">
				<p><?php echo _('Registration Watch will refresh this extension using the registration information currently reported by Asterisk.'); ?></p>
				<p><?php echo _('Monitoring settings, repeat alert mode, history and notes will be preserved where possible.'); ?></p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _('Cancel'); ?></button>
				<button type="button" class="btn btn-danger" id="rw-confirm-reset-extension"><?php echo _('Confirm'); ?></button>
			</div>
		</div>
	</div>
</div>

<p class="rw-time-footer text-muted">
	<?php echo _('Module time'); ?>:
	<span id="rw-module-time"><?php echo htmlspecialchars((string)($timeDiagnostics['module_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
	|
	<?php echo _('Database time'); ?>:
	<span id="rw-database-time"><?php echo htmlspecialchars((string)($timeDiagnostics['database_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
</p>

<script>
window.rwUiSettings = {
	watchedSortKey: <?php echo json_encode($uiWatchedSortKey); ?>,
	watchedSortDir: <?php echo json_encode($uiWatchedSortDir); ?>,
	statusHistorySortKey: <?php echo json_encode($uiStatusHistorySortKey); ?>,
	statusHistorySortDir: <?php echo json_encode($uiStatusHistorySortDir); ?>,
	alertHistorySortKey: <?php echo json_encode($uiAlertHistorySortKey); ?>,
	alertHistorySortDir: <?php echo json_encode($uiAlertHistorySortDir); ?>
};
</script>
<script src="modules/registrationwatch/assets/js/registrationwatch.js?v=<?php echo $_rwAssetVer; ?>"></script>
<script>
	// Registration map renderer. Auto-refresh uses the read-only topology AJAX path in registrationwatch.js.
	(function() {
		const textNoRegistrations = <?php echo json_encode($registrationEmptyText); ?>;
		const textDeviceIp = <?php echo json_encode(_('Device IP')); ?>;
		const textDevicePort = <?php echo json_encode(_('Device Port')); ?>;
		const textNetworkIp = <?php echo json_encode(_('Network IP')); ?>;
		const textNetworkPort = <?php echo json_encode(_('Network Port')); ?>;
		const textDevice = <?php echo json_encode(_('Device')); ?>;
		const textVersion = <?php echo json_encode(_('Version')); ?>;
		const textContactExpires = <?php echo json_encode(_('Contact expires')); ?>;
		const textQualify = <?php echo json_encode(_('Qualify')); ?>;
		const textLatency = <?php echo json_encode(_('Latency')); ?>;
		const textNoQualify = <?php echo json_encode(_('Unavailable; qualify is not enabled.')); ?>;
		const textUnknown = <?php echo json_encode(_('Unknown')); ?>;
		const textEmpty = '-';
		const textExpired = <?php echo json_encode(_('Expired')); ?>;
		const textExtension = <?php echo json_encode(_('Extension')); ?>;
		const textStatus = <?php echo json_encode(_('Status')); ?>;
		const textDescription = <?php echo json_encode(_('Description')); ?>;
		let latestMapRegistrations = <?php echo json_encode($mapRegistrations); ?>;
		let statusMapViewMode = <?php echo json_encode($uiMapViewMode); ?>;
		let rowSortKey = <?php echo json_encode($uiMapSortKey); ?>;
		let rowSortDir = <?php echo json_encode($uiMapSortDir); ?>;
		const rwInitialMonitoringState = <?php echo json_encode($monitoringState); ?>;
		if (window.RegistrationWatchUpdateMonitoringBanner) {
			window.RegistrationWatchUpdateMonitoringBanner(rwInitialMonitoringState);
		}

		function statusClass(status) {
			switch (String(status || 'Unknown').trim().toLowerCase()) {
				case 'reachable':
					return 'rw-led-green';
				case 'registered (no qualify)':
				case 'registered_no_qualify':
					return 'rw-led-amber';
				case 'unreachable':
				case 'not registered':
				case 'not_registered':
					return 'rw-led-red';
				case 'unknown':
				default:
					return 'rw-led-grey';
			}
		}

		function isRegisteredNoQualify(status) {
			status = String(status || '').trim().toLowerCase();
			return status === 'registered (no qualify)' || status === 'registered_no_qualify';
		}

		function latencyText(registration, status) {
			if (registration.latency_ms !== null && registration.latency_ms !== undefined && registration.latency_ms !== '') {
				return escapeHtml(registration.latency_ms) + ' ms';
			}
			if (isRegisteredNoQualify(status)) {
				return textNoQualify;
			}
			return '-';
		}

		function displayLabel(value) {
			const text = String(value || '').trim();
			const labels = {
				recovery: 'Recovery',
				unreachable: 'Unreachable',
				not_registered: 'Not registered',
				'not registered': 'Not registered',
				registered_no_qualify: 'Registered (no qualify)',
				'registered (no qualify)': 'Registered (no qualify)',
				status_change: 'Status changed',
				'status changed': 'Status changed',
				removed: 'Contact removed',
				'contact removed': 'Contact removed',
				sent: 'Sent',
				failed: 'Failed',
				reminder: 'Repeat alert',
				'repeat alert': 'Repeat alert',
				storm_summary: 'Storm Summary',
				'storm summary': 'Storm Summary',
				storm_suppressed: 'Storm suppressed',
				storm_summary_failed: 'Storm summary failed',
				pending: 'Pending',
				suppressed: 'Suppressed',
				test: 'Test'
			};

			if (!text) {
				return '-';
			}

			return labels[text.toLowerCase()] || text;
		}

		function contactExpiryText(expiresAt) {
			if (!expiresAt) {
				return textEmpty;
			}

			const expiryTime = Date.parse(String(expiresAt).replace(' ', 'T'));
			if (Number.isNaN(expiryTime)) {
				return textEmpty;
			}

			const remainingSeconds = Math.floor((expiryTime - Date.now()) / 1000);
			if (remainingSeconds < 0) {
				return textExpired;
			}

			return remainingSeconds + 's';
		}

		function mapDetailRows(registration, status) {
			return [
				[textDeviceIp, registration.device_ip || textEmpty],
				[textDevicePort, registration.device_port || textEmpty],
				[textNetworkIp, registration.network_ip || textEmpty],
				[textNetworkPort, registration.network_port || textEmpty],
				[textDevice, registration.device_name || textEmpty],
				[textVersion, registration.firmware_version || textEmpty],
				[textContactExpires, contactExpiryText(registration.contact_expires_at)],
				[textQualify, registration.qualify_frequency ? registration.qualify_frequency + ' seconds' : textEmpty],
				[textLatency, latencyText(registration, status)]
			];
		}

		function discoveredRegistrations(registrations) {
			if (!Array.isArray(registrations)) {
				return [];
			}

			return registrations.filter(function(registration) {
				return registration.discovered === undefined || parseInt(registration.discovered, 10) !== 0;
			});
		}

		function selectedMapLimit() {
			const select = document.getElementById('rw-map-limit');
			if (!select || select.value === 'all') {
				return 'all';
			}

			const value = parseInt(select.value, 10);
			return value > 0 ? value : 6;
		}

		function updateMapCount(shown, total) {
			const count = document.getElementById('rw-map-count');
			if (!count) {
				return;
			}

			count.textContent = 'Showing ' + shown + ' of ' + total + ' EndPoints';
		}

		function ipSortKey(ip) {
			if (!ip) { return '\xff\xff\xff\xff'; }
			const parts = String(ip).split('.');
			if (parts.length !== 4) { return String(ip).toLowerCase(); }
			return parts.map(function(p) { return String(parseInt(p, 10) || 0).padStart(3, '0'); }).join('.');
		}

		function contactExpirySortNum(expiresAt) {
			if (!expiresAt) { return Infinity; }
			const t = Date.parse(String(expiresAt).replace(' ', 'T'));
			return Number.isNaN(t) ? Infinity : Math.floor((t - Date.now()) / 1000);
		}

		function getRowSortValue(reg, key) {
			switch (key) {
				case 'extension':
					return String(reg.extension || '').trim().replace(/(\d+)/g, function(m) { return m.padStart(6, '0'); }).toLowerCase();
				case 'status': {
					const st = reg.status || reg.last_known_status || 'Unknown';
					return displayLabel(st).toLowerCase();
				}
				case 'description':
					return String(reg.description || '').toLowerCase();
				case 'device_ip':
					return ipSortKey(reg.device_ip);
				case 'network_ip':
					return ipSortKey(reg.network_ip);
				case 'device':
					return String(reg.device_name || '').toLowerCase();
				case 'contact_expires':
					return contactExpirySortNum(reg.contact_expires_at);
				case 'qualify':
					return (reg.qualify_frequency !== null && reg.qualify_frequency !== undefined && reg.qualify_frequency !== '')
						? parseFloat(reg.qualify_frequency) : Infinity;
				case 'latency':
					return (reg.latency_ms !== null && reg.latency_ms !== undefined && reg.latency_ms !== '')
						? parseFloat(reg.latency_ms) : Infinity;
				default:
					return '';
			}
		}

		function sortedVisibleRows(arr) {
			return arr.slice().sort(function(a, b) {
				const va = getRowSortValue(a, rowSortKey);
				const vb = getRowSortValue(b, rowSortKey);
				let cmp;
				if (typeof va === 'number' && typeof vb === 'number') {
					cmp = va - vb;
				} else {
					const sa = String(va);
					const sb = String(vb);
					cmp = sa < sb ? -1 : sa > sb ? 1 : 0;
				}
				return rowSortDir === 'desc' ? -cmp : cmp;
			});
		}

		function sortTh(key, label) {
			const active = rowSortKey === key;
			const arrow = active ? ' <span class="rw-sort-arrow">' + (rowSortDir === 'asc' ? '&#9650;' : '&#9660;') + '</span>' : '';
			return '<th data-sort-key="' + key + '"' + (active ? ' class="rw-sort-active"' : '') + '>' + escapeHtml(label) + arrow + '</th>';
		}

		function applyViewToggleState() {
			const btnCard = document.getElementById('rw-map-view-card');
			const btnRow = document.getElementById('rw-map-view-row');
			if (!btnCard || !btnRow) { return; }
			if (statusMapViewMode === 'row') {
				btnCard.classList.remove('active');
				btnRow.classList.add('active');
			} else {
				btnCard.classList.add('active');
				btnRow.classList.remove('active');
			}
		}

		function renderRegistrationMap(registrations) {
			const container = document.getElementById('rw-topology-container');
			if (!container) return;

			latestMapRegistrations = Array.isArray(registrations) ? registrations : [];

			const discovered = discoveredRegistrations(latestMapRegistrations);
			const total = discovered.length;
			const limit = selectedMapLimit();
			const visible = limit === 'all' ? discovered : discovered.slice(0, limit);

			updateMapCount(visible.length, total);

			if (total === 0) {
				container.innerHTML = '<p class="text-muted">' + escapeHtml(textNoRegistrations) + '</p>';
				return;
			}

			if (statusMapViewMode === 'row') {
				const rows = sortedVisibleRows(visible);
				let html = '<table class="table table-condensed rw-map-row-view">';
				html += '<thead><tr>';
				html += sortTh('extension', textExtension);
				html += sortTh('status', textStatus);
				html += sortTh('description', textDescription);
				html += sortTh('device_ip', textDeviceIp);
				html += sortTh('network_ip', textNetworkIp);
				html += sortTh('device', textDevice);
				html += sortTh('contact_expires', textContactExpires);
				html += sortTh('qualify', textQualify);
				html += sortTh('latency', textLatency);
				html += '</tr></thead><tbody>';
				for (const registration of rows) {
					const status = registration.status || registration.last_known_status || 'Unknown';
					const deviceIpPort = registration.device_ip
						? escapeHtml(registration.device_ip) + (registration.device_port ? ':' + escapeHtml(String(registration.device_port)) : '')
						: textEmpty;
					const networkIpPort = registration.network_ip
						? escapeHtml(registration.network_ip) + (registration.network_port ? ':' + escapeHtml(String(registration.network_port)) : '')
						: textEmpty;
					const deviceCell = escapeHtml(registration.device_name || textEmpty)
						+ (registration.firmware_version ? '<br><small class="text-muted">' + escapeHtml(registration.firmware_version) + '</small>' : '');
					const qualifyText = registration.qualify_frequency
						? escapeHtml(String(registration.qualify_frequency)) + ' s'
						: textEmpty;
					html += '<tr>';
					html += '<td><span class="rw-led ' + statusClass(status) + '"></span> ' + escapeHtml(registration.extension || textUnknown) + '</td>';
					html += '<td>' + escapeHtml(displayLabel(status)) + '</td>';
					html += '<td>' + escapeHtml(registration.description || '-') + '</td>';
					html += '<td>' + deviceIpPort + '</td>';
					html += '<td>' + networkIpPort + '</td>';
					html += '<td>' + deviceCell + '</td>';
					html += '<td>' + contactExpiryText(registration.contact_expires_at) + '</td>';
					html += '<td>' + qualifyText + '</td>';
					html += '<td>' + latencyText(registration, status) + '</td>';
					html += '</tr>';
				}
				html += '</tbody></table>';
				container.innerHTML = html;
			} else {
				let html = '<div class="rw-registration-map">';
				for (const registration of visible) {
					const status = registration.status || registration.last_known_status || 'Unknown';
					html += '<div class="rw-map-tile">';
					html += '<div class="rw-map-title"><span class="rw-led ' + statusClass(status) + '"></span><span>' + escapeHtml(registration.extension || textUnknown) + '</span></div>';
					html += '<div class="rw-map-description">' + escapeHtml(registration.description || '-') + '</div>';
					html += '<div class="rw-map-status">' + escapeHtml(displayLabel(status)) + '</div>';
					for (const row of mapDetailRows(registration, status)) {
						html += '<div class="rw-map-detail">' + escapeHtml(row[0]) + ': ' + escapeHtml(row[1]) + '</div>';
					}
					html += '</div>';
				}
				html += '</div>';
				container.innerHTML = html;
			}
		}
		window.RegistrationWatchRenderRegistrationMap = renderRegistrationMap;

		const mapLimitSelect = document.getElementById('rw-map-limit');
		if (mapLimitSelect) {
			mapLimitSelect.addEventListener('change', function() {
				renderRegistrationMap(latestMapRegistrations);
			});
		}

		const mapContainer = document.getElementById('rw-topology-container');
		if (mapContainer) {
			mapContainer.addEventListener('click', function(e) {
				const th = e.target.closest('th[data-sort-key]');
				if (!th) { return; }
				const key = th.getAttribute('data-sort-key');
				if (rowSortKey === key) {
					rowSortDir = rowSortDir === 'asc' ? 'desc' : 'asc';
				} else {
					rowSortKey = key;
					rowSortDir = 'asc';
				}
				if (window.rwSaveUiSetting) { window.rwSaveUiSetting('ui_map_sort_key', rowSortKey); }
				if (window.rwSaveUiSetting) { window.rwSaveUiSetting('ui_map_sort_dir', rowSortDir); }
				renderRegistrationMap(latestMapRegistrations);
			});
		}

		const btnViewCard = document.getElementById('rw-map-view-card');
		const btnViewRow = document.getElementById('rw-map-view-row');
		if (btnViewCard) {
			btnViewCard.addEventListener('click', function() {
				statusMapViewMode = 'card';
				if (window.rwSaveUiSetting) { window.rwSaveUiSetting('ui_map_view_mode', 'card'); }
				applyViewToggleState();
				renderRegistrationMap(latestMapRegistrations);
			});
		}
		if (btnViewRow) {
			btnViewRow.addEventListener('click', function() {
				statusMapViewMode = 'row';
				if (window.rwSaveUiSetting) { window.rwSaveUiSetting('ui_map_view_mode', 'row'); }
				applyViewToggleState();
				renderRegistrationMap(latestMapRegistrations);
			});
		}

		applyViewToggleState();
		renderRegistrationMap(latestMapRegistrations);

		function escapeHtml(text) {
			if (!text) return '';
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	})();
</script>
