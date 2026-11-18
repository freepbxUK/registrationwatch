<?php

// Registration Watch uninstall hook.

global $db;


try {
        if (class_exists('\FreePBX')) {
                \FreePBX::Job()->remove('registrationwatch', 'monitor');
                \FreePBX::Job()->remove('registrationwatch', 'webhooks');
        }
} catch (\Throwable $e) {
        if (class_exists('\FreePBX') && method_exists('\FreePBX', 'Log')) {
                \FreePBX::Log()->error('registrationwatch: failed to remove background job: ' . $e->getMessage());
        }
}

$db->query('DROP TABLE IF EXISTS registrationwatch_settings');
$db->query('DROP TABLE IF EXISTS registrationwatch_webhook_queue');
$db->query('DROP TABLE IF EXISTS registrationwatch_alert_escalation');
$db->query('DROP TABLE IF EXISTS registrationwatch_alert_history');
$db->query('DROP TABLE IF EXISTS registrationwatch_status_history');
$db->query('DROP TABLE IF EXISTS registrationwatch_registrations');
