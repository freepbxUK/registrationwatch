<?php

declare(strict_types=1);

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function normalise_extension(string $extension): string {
	return strtolower(trim($extension));
}

function no_contact_registration_key(string $extension): string {
	return hash('sha256', normalise_extension($extension) . "\0no-contact");
}

function extension_reset_snapshot(PDO $db, string $extension): array {
	$stmt = $db->prepare(
		'SELECT notes, notes_updated_at, enabled, repeat_mode
		FROM registrationwatch_registrations
		WHERE extension = :extension
		ORDER BY id ASC'
	);
	$stmt->execute([':extension' => $extension]);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

	if (!$rows) {
		return ['ok' => false, 'message' => 'missing extension rows'];
	}

	$noteValues = [];
	$preservedRow = null;
	foreach ($rows as $row) {
		$note = trim((string)($row['notes'] ?? ''));
		if ($note === '') {
			continue;
		}

		$noteValues[$note] = true;
		if ($preservedRow === null) {
			$preservedRow = $row;
		}
	}

	if (count($noteValues) > 1) {
		return ['ok' => false, 'message' => 'notes ambiguous'];
	}

	$enabled = 0;
	$repeatMode = null;
	$preservedRow = $preservedRow ?? $rows[0];
	foreach ($rows as $row) {
		if ((int)($row['enabled'] ?? 0) === 1) {
			$enabled = 1;
		}
		$mode = trim((string)($row['repeat_mode'] ?? ''));
		if ($mode !== '') {
			$repeatMode = $mode;
		}
	}

	return [
		'ok' => true,
		'enabled' => $enabled,
		'repeat_mode' => $repeatMode,
		'notes' => trim((string)($preservedRow['notes'] ?? '')),
		'notes_updated_at' => trim((string)($preservedRow['notes_updated_at'] ?? '')) !== '' ? (string)$preservedRow['notes_updated_at'] : null,
	];
}

function reset_extension_from_asterisk(PDO $db, int $registrationId, array $allowedDevices, array $liveContacts, string $now): array {
	$extStmt = $db->prepare('SELECT extension FROM registrationwatch_registrations WHERE id = :id');
	$extStmt->execute([':id' => $registrationId]);
	$extension = normalise_extension((string)($extStmt->fetchColumn() ?? ''));
	if ($extension === '') {
		return ['status' => false, 'message' => 'missing watched registration'];
	}
	if (!isset($allowedDevices[$extension])) {
		return ['status' => false, 'message' => 'extension not allowlisted'];
	}

	$snapshot = extension_reset_snapshot($db, $extension);
	if (!$snapshot['ok']) {
		return ['status' => false, 'message' => (string)$snapshot['message']];
	}

	$db->beginTransaction();
	try {
		$clearEscalations = $db->prepare(
			'DELETE FROM registrationwatch_alert_escalation
			WHERE registration_id IN (
				SELECT id FROM registrationwatch_registrations WHERE extension = :extension
			)'
		);
		$clearEscalations->execute([':extension' => $extension]);

		$deleteRows = $db->prepare('DELETE FROM registrationwatch_registrations WHERE extension = :extension');
		$deleteRows->execute([':extension' => $extension]);

		$contacts = [];
		foreach ($liveContacts as $contact) {
			if (normalise_extension((string)($contact['extension'] ?? '')) === $extension) {
				$contacts[] = $contact;
			}
		}

		if ($contacts) {
			$insert = $db->prepare(
				'INSERT INTO registrationwatch_registrations
					(registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, discovered, last_known_status, contact_uri, source_ip,
						source_port, registration_ua_class, contact_count, last_checked_at, created_at, updated_at, first_discovered_at, last_discovered_at)
				VALUES
					(:registration_key, :extension, :notes, :notes_updated_at, :enabled, :repeat_mode, 1, :last_known_status, :contact_uri, :source_ip,
						:source_port, :registration_ua_class, :contact_count, :last_checked_at, :created_at, :updated_at, :first_discovered_at, :last_discovered_at)'
			);
			foreach ($contacts as $contact) {
				$insert->execute([
					':registration_key' => (string)$contact['registration_key'],
					':extension' => $extension,
					':notes' => $snapshot['notes'],
					':notes_updated_at' => $snapshot['notes_updated_at'],
					':enabled' => $snapshot['enabled'],
					':repeat_mode' => $snapshot['repeat_mode'],
					':last_known_status' => (string)($contact['status'] ?? 'Unknown'),
					':contact_uri' => $contact['contact_uri'] ?? null,
					':source_ip' => $contact['source_ip'] ?? null,
					':source_port' => $contact['source_port'] ?? null,
					':registration_ua_class' => $contact['registration_ua_class'] ?? '',
					':contact_count' => max(1, (int)($contact['contact_count'] ?? 1)),
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
					(registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, discovered, last_known_status, contact_count,
						last_checked_at, created_at, updated_at, first_discovered_at, last_discovered_at)
				VALUES
					(:registration_key, :extension, :notes, :notes_updated_at, :enabled, :repeat_mode, 1, :last_known_status, 1,
						:last_checked_at, :created_at, :updated_at, :first_discovered_at, :last_discovered_at)'
			);
			$insert->execute([
				':registration_key' => no_contact_registration_key($extension),
				':extension' => $extension,
				':notes' => $snapshot['notes'],
				':notes_updated_at' => $snapshot['notes_updated_at'],
				':enabled' => $snapshot['enabled'],
				':repeat_mode' => $snapshot['repeat_mode'],
				':last_known_status' => 'Not registered',
				':last_checked_at' => $now,
				':created_at' => $now,
				':updated_at' => $now,
				':first_discovered_at' => $now,
				':last_discovered_at' => $now,
			]);
		}

		$db->commit();
		return ['status' => true, 'extension' => $extension];
	} catch (Throwable $e) {
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		throw $e;
	}
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
	'CREATE TABLE registrationwatch_registrations (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_key TEXT NOT NULL UNIQUE,
		extension TEXT NOT NULL,
		notes TEXT NOT NULL DEFAULT "",
		notes_updated_at TEXT,
		enabled INTEGER NOT NULL DEFAULT 1,
		repeat_mode TEXT,
		discovered INTEGER NOT NULL DEFAULT 1,
		last_known_status TEXT NOT NULL,
		contact_uri TEXT,
		source_ip TEXT,
		source_port INTEGER,
		registration_ua_class TEXT NOT NULL DEFAULT "",
		contact_count INTEGER NOT NULL DEFAULT 1,
		last_checked_at TEXT,
		created_at TEXT,
		updated_at TEXT,
		first_discovered_at TEXT,
		last_discovered_at TEXT
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_alert_escalation (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_id INTEGER NOT NULL,
		alert_type TEXT NOT NULL
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_status_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_id INTEGER NOT NULL,
		to_state TEXT NOT NULL
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_alert_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_id INTEGER,
		result TEXT NOT NULL
	)'
);

$now = '2026-07-29 12:00:00';
$allowed = ['3001' => true, '3002' => true];

$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'oldA'), '3001', 'Site A note', '2026-07-28 09:00:00', 1, 'daily', 'Unreachable']);
$ext1Row1 = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'oldB'), '3001', 'Site A note', '2026-07-28 09:00:00', 1, 'daily', 'Not registered']);
$ext1Row2 = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'otherA'), '3002', 'Other site', '2026-07-27 08:00:00', 0, null, 'Reachable']);
$ext2Row = (int)$db->lastInsertId();

$db->prepare('INSERT INTO registrationwatch_alert_escalation (registration_id, alert_type) VALUES (?, ?)')->execute([$ext1Row1, 'not_registered']);
$db->prepare('INSERT INTO registrationwatch_alert_escalation (registration_id, alert_type) VALUES (?, ?)')->execute([$ext2Row, 'unreachable']);
$db->prepare('INSERT INTO registrationwatch_status_history (registration_id, to_state) VALUES (?, ?)')->execute([$ext1Row1, 'Not registered']);
$db->prepare('INSERT INTO registrationwatch_status_history (registration_id, to_state) VALUES (?, ?)')->execute([$ext1Row2, 'Unreachable']);
$db->prepare('INSERT INTO registrationwatch_alert_history (registration_id, result) VALUES (?, ?)')->execute([$ext1Row1, 'sent']);

$historyStatusCountBefore = (int)$db->query('SELECT COUNT(*) FROM registrationwatch_status_history')->fetchColumn();
$historyAlertCountBefore = (int)$db->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn();

$resetResult = reset_extension_from_asterisk(
	$db,
	$ext1Row1,
	$allowed,
	[
		[
			'registration_key' => hash('sha256', 'newA'),
			'extension' => '3001',
			'status' => 'Reachable',
			'contact_uri' => 'sip:3001@198.51.100.210:5060',
			'source_ip' => '198.51.100.210',
			'source_port' => 5060,
			'registration_ua_class' => '',
			'contact_count' => 1,
		],
		[
			'registration_key' => hash('sha256', 'newB'),
			'extension' => '3001',
			'status' => 'Unreachable',
			'contact_uri' => 'sip:3001@198.51.100.211:5060',
			'source_ip' => '198.51.100.211',
			'source_port' => 5060,
			'registration_ua_class' => '',
			'contact_count' => 1,
		],
		[
			'registration_key' => hash('sha256', 'otherLive'),
			'extension' => '3002',
			'status' => 'Reachable',
		],
	],
	$now
);

assert_true($resetResult['status'] === true, 'extension reset should succeed when notes are unambiguous');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3001'")->fetchColumn() === 2, 'target extension should rebuild to live Asterisk contact count');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3002'")->fetchColumn() === 1, 'other extension current rows should be unaffected');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation e JOIN registrationwatch_registrations r ON r.id = e.registration_id WHERE r.extension = '3001'")->fetchColumn() === 0, 'target extension active escalation should be cleared');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$ext2Row}")->fetchColumn() === 1, 'other extension escalation should remain untouched');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3001' AND notes = 'Site A note'")->fetchColumn() === 2, 'extension notes should be preserved to rebuilt rows');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3001' AND notes_updated_at = '2026-07-28 09:00:00'")->fetchColumn() === 2, 'notes timestamp should be preserved to rebuilt rows');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3001' AND enabled = 1")->fetchColumn() === 2, 'enabled state should be preserved to rebuilt rows');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3001' AND repeat_mode = 'daily'")->fetchColumn() === 2, 'repeat mode should be preserved to rebuilt rows');
assert_true((int)$db->query('SELECT COUNT(*) FROM registrationwatch_status_history')->fetchColumn() === $historyStatusCountBefore, 'status history should be preserved');
assert_true((int)$db->query('SELECT COUNT(*) FROM registrationwatch_alert_history')->fetchColumn() === $historyAlertCountBefore, 'alert history should be preserved');

$ext2Reset = reset_extension_from_asterisk(
	$db,
	$ext2Row,
	$allowed,
	[],
	$now
);
assert_true($ext2Reset['status'] === true, 'extension reset should still succeed with zero live contacts');
$ext2Placeholder = $db->query("SELECT registration_key, last_known_status, notes FROM registrationwatch_registrations WHERE extension = '3002'")->fetch(PDO::FETCH_ASSOC);
assert_true($ext2Placeholder['registration_key'] === no_contact_registration_key('3002'), 'zero-contact reset should create no-contact placeholder for target extension');
assert_true($ext2Placeholder['last_known_status'] === 'Not registered', 'zero-contact reset should reflect not registered state');
assert_true($ext2Placeholder['notes'] === 'Other site', 'zero-contact reset should keep extension notes');

$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'blankA'), '3005', 'Shared note', '2026-07-28 08:00:00', 1, 'hourly', 'Reachable']);
$blank1 = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'blankB'), '3005', '', '2026-07-29 08:00:00', 0, 'daily', 'Not registered']);
$allowed['3005'] = true;
$blankStaleResult = reset_extension_from_asterisk(
	$db,
	$blank1,
	$allowed,
	[
		[
			'registration_key' => hash('sha256', 'blankLive'),
			'extension' => '3005',
			'status' => 'Reachable',
		],
	],
	$now
);
assert_true($blankStaleResult['status'] === true, 'reset should ignore blank stale note rows when a non-blank note exists');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3005' AND notes = 'Shared note'")->fetchColumn() === 1, 'preserved non-blank note should be retained once');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3005' AND notes_updated_at = '2026-07-28 08:00:00'")->fetchColumn() === 1, 'preserved note timestamp should come from the retained non-blank note');

$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'timeA'), '3006', 'Timed note', '2026-07-28 08:00:00', 1, 'daily', 'Reachable']);
$time1 = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'timeB'), '3006', 'Timed note', '2026-07-29 09:30:00', 0, 'hourly', 'Not registered']);
$allowed['3006'] = true;
$timestampMatchResult = reset_extension_from_asterisk($db, $time1, $allowed, [], $now);
assert_true($timestampMatchResult['status'] === true, 'reset should allow matching non-blank notes even when timestamps differ');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3006' AND notes = 'Timed note'")->fetchColumn() === 1, 'matching notes should still be preserved');

$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'conflictA'), '3007', 'First note', '2026-07-28 08:00:00', 1, null, 'Reachable']);
$conflict1 = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'conflictB'), '3007', 'Second note', '2026-07-28 08:00:00', 1, null, 'Reachable']);
$allowed['3007'] = true;
$conflictResult = reset_extension_from_asterisk($db, $conflict1, $allowed, [], $now);
assert_true($conflictResult['status'] === false, 'reset should fail when two genuinely different non-blank notes exist');
assert_true($conflictResult['message'] === 'notes ambiguous', 'conflict message should reflect conflicting notes');

$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'blankOnlyA'), '3008', '', null, 0, 'daily', 'Reachable']);
$blankOnly1 = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'blankOnlyB'), '3008', '', '2026-07-29 10:00:00', 1, 'hourly', 'Not registered']);
$allowed['3008'] = true;
$allBlankResult = reset_extension_from_asterisk($db, $blankOnly1, $allowed, [], $now);
assert_true($allBlankResult['status'] === true, 'reset should succeed when all notes are blank');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3008' AND notes = ''")->fetchColumn() === 1, 'all-blank notes should remain blank after reset');

$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'ambA'), '3003', 'Left note', '2026-07-28 10:00:00', 1, null, 'Reachable']);
$amb1 = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, notes, notes_updated_at, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
	->execute([hash('sha256', 'ambB'), '3003', 'Right note', '2026-07-28 10:00:00', 1, null, 'Reachable']);
$allowed['3003'] = true;

$beforeAmbiguous = (int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3003'")->fetchColumn();
$ambiguousResult = reset_extension_from_asterisk(
	$db,
	$amb1,
	$allowed,
	[
		[
			'registration_key' => hash('sha256', 'ambLive'),
			'extension' => '3003',
			'status' => 'Reachable',
		],
	],
	$now
);
assert_true($ambiguousResult['status'] === false, 'reset should fail safely when notes are ambiguous across extension rows');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_registrations WHERE extension = '3003'")->fetchColumn() === $beforeAmbiguous, 'ambiguous notes should not delete existing extension rows');

echo "reset from asterisk contract tests passed\n";
