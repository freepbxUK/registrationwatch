<?php

declare(strict_types=1);

interface BMO {}

if (!function_exists('_')) {
	function _(string $text): string {
		return $text;
	}
}

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

class FreePBX {
	public static $database;
	public static function Database() {
		return self::$database;
	}
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE registrationwatch_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TEXT)');
$database->exec('CREATE TABLE registrationwatch_status_history (id INTEGER PRIMARY KEY, registration_id INTEGER, registration_key TEXT, extension TEXT, from_state TEXT, to_state TEXT, source TEXT, reason TEXT, contact_uri TEXT, latency_ms REAL, created_at TEXT)');
$database->exec('CREATE TABLE registrationwatch_alert_history (id INTEGER PRIMARY KEY, registration_id INTEGER, registration_key TEXT, extension TEXT, contact_uri TEXT, history_id INTEGER, alert_type TEXT, status TEXT, recipient TEXT, subject TEXT, sent_at TEXT, result TEXT, error TEXT)');
FreePBX::$database = $database;

require_once __DIR__ . '/../Registrationwatch.class.php';

$statusInsert = $database->prepare('INSERT INTO registrationwatch_status_history (id, registration_id, registration_key, extension, from_state, to_state, source, reason, contact_uri, latency_ms, created_at) VALUES (:id, :id, :key, :extension, :from_state, :to_state, :source, :reason, :contact_uri, :latency_ms, :created_at)');
for ($id = 1; $id <= 30; $id++) {
	$statusInsert->execute([
		':id' => $id,
		':key' => 'status-' . $id,
		':extension' => '2000',
		':from_state' => 'Reachable',
		':to_state' => 'Unreachable',
		':source' => $id === 1 ? 'reconcile' : ($id === 2 ? ' asterisk ' : ($id === 3 ? ' aardvark_value ' : ($id === 4 ? null : ($id === 5 ? '' : 'asterisk')))),
		':reason' => $id === 1 ? 'removed' : ($id === 2 ? ' A_fallback ' : ($id === 3 ? ' removed ' : ($id === 4 ? null : ($id === 5 ? '' : 'status_change')))),
		':contact_uri' => 'sip:2000@example.invalid',
		':latency_ms' => $id,
		':created_at' => $id >= 29 ? '2026-01-01 00:29:00' : sprintf('2026-01-01 00:%02d:00', $id),
	]);
}

$alertInsert = $database->prepare('INSERT INTO registrationwatch_alert_history (id, registration_id, registration_key, extension, contact_uri, history_id, alert_type, status, recipient, subject, sent_at, result, error) VALUES (:id, :id, :key, :extension, :contact_uri, :history_id, :alert_type, :status, :recipient, :subject, :sent_at, :result, :error)');
for ($id = 1; $id <= 32; $id++) {
	$alertInsert->execute([
		':id' => $id,
		':key' => 'alert-' . $id,
		':extension' => sprintf('2%03d', 33 - $id),
		':contact_uri' => 'sip:2000@example.invalid',
		':history_id' => $id,
		':alert_type' => 'unreachable',
		':status' => 'Unreachable',
		':recipient' => 'admin@example.invalid',
		':subject' => 'Alert ' . $id,
		':sent_at' => sprintf('2026-02-01 00:%02d:00', $id),
		':result' => 'sent',
		':error' => null,
	]);
}

$watch = new FreePBX\modules\Registrationwatch(new stdClass());
$getStatusHistory = new ReflectionMethod($watch, 'getStatusHistory');
$getStatusHistory->setAccessible(true);
$getAlertHistory = new ReflectionMethod($watch, 'getAlertHistory');
$getAlertHistory->setAccessible(true);
$deleteStatusHistory = new ReflectionMethod($watch, 'handleDeleteStatusHistoryRow');
$deleteStatusHistory->setAccessible(true);
$deleteAlertHistory = new ReflectionMethod($watch, 'handleDeleteAlertHistoryRow');
$deleteAlertHistory->setAccessible(true);
$pruneStatusHistory = new ReflectionMethod($watch, 'pruneStatusHistory');
$pruneStatusHistory->setAccessible(true);
$pruneAlertHistory = new ReflectionMethod($watch, 'pruneAlertHistory');
$pruneAlertHistory->setAccessible(true);

$_REQUEST = [
	'status_history_offset' => '0',
	'status_history_sort_key' => 'time',
	'status_history_sort_dir' => 'desc',
	'alert_history_offset' => '0',
	'alert_history_sort_key' => 'time',
	'alert_history_sort_dir' => 'desc',
];
$statusPage = $getStatusHistory->invoke($watch);
$alertPage = $getAlertHistory->invoke($watch);
assert_true($statusPage['total'] === 30 && count($statusPage['rows']) === 25, 'status history total must not be truncated to the page size');
assert_true($alertPage['total'] === 32 && count($alertPage['rows']) === 25, 'alert history total must not be truncated to the page size');
assert_true((int)$statusPage['rows'][0]['id'] === 30 && (int)$statusPage['rows'][1]['id'] === 29, 'status ordering must use id as a deterministic time tie-breaker');
assert_true((int)$alertPage['rows'][0]['id'] === 32, 'alert history should use its own time ordering');

$_REQUEST['status_history_offset'] = '25';
$_REQUEST['alert_history_offset'] = '0';
$statusLastPage = $getStatusHistory->invoke($watch);
$alertFirstPage = $getAlertHistory->invoke($watch);
assert_true($statusLastPage['offset'] === 25 && count($statusLastPage['rows']) === 5 && (int)$statusLastPage['rows'][0]['id'] === 5, 'status history offset must select the retained final page');
assert_true($alertFirstPage['offset'] === 0 && count($alertFirstPage['rows']) === 25, 'status and alert histories must paginate independently');

$_REQUEST['alert_history_sort_key'] = 'extension';
$_REQUEST['alert_history_sort_dir'] = 'asc';
$alertSorted = $getAlertHistory->invoke($watch);
assert_true((int)$alertSorted['rows'][0]['id'] === 32, 'alert sorting must apply to the complete retained dataset before paging');

$_REQUEST = ['status_history_sort_key' => 'source', 'status_history_sort_dir' => 'asc', 'status_history_offset' => '0'];
$statusBySource = $getStatusHistory->invoke($watch);
assert_true(array_column(array_slice($statusBySource['rows'], 0, 5), 'id') === ['4', '5', '1', '3', '2'], 'status Source sorting must exactly use trimmed PHP-visible values for null, empty, mapped, and fallback values');
assert_true(array_column(array_slice($statusBySource['rows'], 0, 5), 'source') === ['-', '-', 'Asterisk', 'aardvark_value', 'asterisk'], 'status Source fallback values containing underscores must remain unchanged and sort by that visible value');

$_REQUEST = ['status_history_sort_key' => 'reason', 'status_history_sort_dir' => 'asc', 'status_history_offset' => '0'];
$statusByReason = $getStatusHistory->invoke($watch);
assert_true(array_column(array_slice($statusByReason['rows'], 0, 5), 'id') === ['4', '5', '2', '1', '3'], 'status Reason sorting must exactly use trimmed PHP-visible values for null, empty, mapped, and fallback values');
assert_true(array_column(array_slice($statusByReason['rows'], 0, 5), 'reason') === ['-', '-', 'A_fallback', 'Contact removed', 'Contact removed'], 'status Reason fallback values containing underscores must remain unchanged and sort by that visible value');

$_REQUEST = ['status_history_offset' => '26'];
$normalisedOffset = $getStatusHistory->invoke($watch);
assert_true($normalisedOffset['offset'] === 25, 'arbitrary status offsets must normalize to page boundaries');

for ($id = 1; $id <= 5; $id++) {
	$_REQUEST = ['id' => (string)$id, 'confirmed' => '1', 'status_history_offset' => '25'];
	$statusDelete = $deleteStatusHistory->invoke($watch);
}
assert_true($statusDelete['deleted'] === 1 && $statusDelete['statusHistory']['total'] === 25, 'status deletion must update the total');
assert_true($statusDelete['statusHistory']['offset'] === 0 && count($statusDelete['statusHistory']['rows']) === 25, 'deleting an emptied final status page must select a sensible page');

$_REQUEST = ['id' => '1', 'confirmed' => '1', 'alert_history_offset' => '25'];
$alertDelete = $deleteAlertHistory->invoke($watch);
assert_true($alertDelete['deleted'] === 1 && $alertDelete['alertHistory']['total'] === 31, 'alert deletion must update its independent total');
assert_true($alertDelete['alertHistory']['offset'] === 25 && count($alertDelete['alertHistory']['rows']) === 6, 'alert deletion must retain a valid final page when rows remain on it');

assert_true($pruneStatusHistory->invoke($watch, 'never') === 0 && (int)$database->query('SELECT COUNT(*) FROM registrationwatch_status_history')->fetchColumn() === 25, 'never status pruning must preserve retained history');
assert_true($pruneAlertHistory->invoke($watch, 'never') === 0 && (int)$database->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === 31, 'never alert pruning must preserve retained history');

assert_true($pruneStatusHistory->invoke($watch, 'hourly') === 25, 'status pruning should remove all old retained rows');
$_REQUEST = ['status_history_offset' => '25'];
$emptyStatusPage = $getStatusHistory->invoke($watch);
assert_true($emptyStatusPage['total'] === 0 && $emptyStatusPage['offset'] === 0 && $emptyStatusPage['rows'] === [], 'an empty status history must reset its offset and return no rows');

assert_true($pruneAlertHistory->invoke($watch, 'hourly') === 31, 'alert pruning should remove all old retained rows');
$_REQUEST = ['alert_history_offset' => '25'];
$emptyAlertPage = $getAlertHistory->invoke($watch);
assert_true($emptyAlertPage['total'] === 0 && $emptyAlertPage['offset'] === 0 && $emptyAlertPage['rows'] === [], 'an empty alert history must reset its offset and return no rows');

echo "history pagination contract tests passed\n";