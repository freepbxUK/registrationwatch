# Registration Watch 1.5.0 for FreePBX 16 and 17

Registration Watch (`registrationwatch`) watches PJSIP registration state in
FreePBX/PBXact 16 and 17. It discovers configured FreePBX PJSIP devices and tracks
their registration and contact state, recording state changes and sending email
alerts when watched registrations become unavailable or recover.

**Signed Release**

Registration Watch 1.4.1 was the first signed FreePBX UK release of the module. Signed releases use the established developer GPG identity, signed by the FreePBX Module Signing v2 master key. This 1.5.0 development checkout is not signed: its checked-in `module.sig` belongs to 1.4.4 and must not be used for a 1.5.0 package.

Multiple registrations under the same extension may be tracked separately where
Registration Watch can distinguish them from Asterisk/FreePBX contact data.

Where available, Registration Watch also shows supporting details including
device IP and port, network IP and port, user-agent and device name, firmware
version, contact expiry, qualify interval, and latency. These details depend on
what Asterisk exposes for the registration and may not be available for every
device or system.

Email alerts include the configured FreePBX System Identifier so administrators
monitoring multiple PBXs can immediately identify the originating system. For
example, alert bodies begin with lines such as:

* Registration Watch state change from MY-PBX-NAME
* Registration Watch Storm Summary from MY-PBX-NAME
* Registration Watch test email from MY-PBX-NAME

If the system identifier cannot be obtained from FreePBX, Registration Watch
falls back to a sensible `unknown system` label instead of failing email delivery.

## Release Status

The latest published signed stable release is 1.4.4. This source checkout is
the unsigned 1.5.0 development candidate for the existing FreePBX/PBXact 16
and 17 module targets; it is not a production release. A future 1.5.0
production release requires a newly signed package containing the final files
and publication of its release asset and update metadata.

Use the `main` branch for stable releases. Development and release-candidate
branches may contain incomplete or test-only changes.

## Compatibility

Use with FreePBX/PBXact 16 or 17.

The module metadata does not declare a separate PHP version range. The existing
compatibility contract avoids PHP 8-only language constructs; local 1.5.0
validation runs on PHP 8.3. Confirm compatibility on the PHP version supplied by
the target FreePBX distribution before deployment.

## Requirements

* FreePBX/PBXact 16 or 17
* PJSIP channel driver
* Existing FreePBX PJSIP extensions/devices
* Asterisk Manager access available to FreePBX
* FreePBX Job runner enabled for the registration-monitor and webhook-delivery
  jobs
* FreePBX mail support configured if alert email delivery is required
* Email "From:" Address set in Advanced Settings (alerts will not send without it)
* PHP cURL extension for webhook HTTP delivery
* PHP `hrtime()` for hostname-based webhook DNS resolution; without it,
  hostname resolution fails closed

Advanced Settings → Email "From:" Address supports either a bare address such
as `asterisk@demodomain.name` or a display name and address such as
`PBX-123 <asterisk@demodomain.name>`, if supported by your FreePBX version.
An explicitly configured display name is preserved as the email sender name.
A bare address continues to use the existing sender name, falling back to
`Registration Watch` if none is configured.

## Installing

Registration Watch is a community module and is not currently listed in the
FreePBX online module repository. The preferred production installation is the
latest published, correctly signed `.tgz` uploaded through Module Admin. The
published 1.4.4 package remains the stable release; the unsigned 1.5.0
development candidate is for review and testing only. A future 1.5.0
production package may be installed this way only after it has been signed and
published.

Do not use:

```sh
fwconsole ma installlocal registrationwatch
```

Use Module Admin's upload/install action for a `.tgz`. For directory-based
installation methods, use `fwconsole ma install registrationwatch`.

### Option 1: Upload a `.tgz` in Module Admin

In **Admin > Module Admin**, use the module upload/install control and select
the latest published signed Registration Watch archive. Review the module
identity, version, and signature status shown by Module Admin, then install it
and run `fwconsole chown` and `fwconsole reload`. Use a signed
`registrationwatch-1.5.0.tgz` only after the final 1.5.0 production package is
published.

The archive must contain a `registrationwatch/` directory at its root. The
development candidate produced by the local packaging script is named
`registrationwatch-1.5.0-unsigned.tgz`; it is for structural review and test
installations only and does not contain a valid signature. Do not treat it as
a production release or upload it to production systems.

### Option 2: Install from an unpacked module directory

Place the `registrationwatch` directory in `/var/www/html/admin/modules/`, then:

```sh
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
cd
fwconsole chown
fwconsole reload
```

### Option 3: Install from GitHub

FreePBX 16 / PBXact 16 (CentOS 7)

Check whether Git is installed:

```sh
rpm -q git
```

If Git is not installed:

```sh
yum install -y git
```

FreePBX 17 / PBXact 17 (Debian 12)

Check whether Git is installed:

```sh
dpkg -l git
```

If Git is not installed:

```sh
apt update
apt install -y git
```

Then run the following commands as root:

```sh
cd
cd /var/www/html/admin/modules
git clone https://github.com/freepbxUK/registrationwatch.git registrationwatch
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
cd
fwconsole chown
fwconsole reload
```

### Option 4: Install from a local copy

Copy or symlink a local `registrationwatch` directory into
`/var/www/html/admin/modules/`, then:

```sh
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
cd
fwconsole chown
fwconsole reload
```

The module appears under Reports > Registration Watch.

## Updating Registration Watch

Do not uninstall when updating. Uninstalling removes Registration Watch tables,
settings, watched registrations, status history, alert history, and
repeat-alert state.

### Option 1: Update from a signed `.tgz`

In **Admin > Module Admin**, upload the latest published signed
Registration Watch archive (currently 1.4.4) and choose the normal module
upgrade/install action. Do not uninstall the current module first. The unsigned
1.5.0 development candidate is not a production upgrade package; use a signed
`registrationwatch-1.5.0.tgz` only after the final 1.5.0 package is published.
After Module Admin finishes, run `fwconsole chown` and `fwconsole reload`.

The install hook uses additive `CREATE TABLE IF NOT EXISTS` statements and
`INSERT IGNORE` defaults. It does not reset extension monitoring choices,
settings, registration records, history, or escalation state. The webhook
queue is added as a new table; existing registrations are not newly monitored.

### Option 2: Update from an unpacked module directory

Replace the module files in `/var/www/html/admin/modules/registrationwatch/`, then:

```sh
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
cd
fwconsole chown
fwconsole reload
```

### Option 3: Update from GitHub

After the first installation, fwconsole chown may cause Git to reject the module directory because it is owned by the FreePBX web user rather than root. Run the following commands as root:

**Warning:** `git reset --hard origin/main` discards uncommitted changes to
tracked files in the module directory. Commit, back up, or otherwise preserve
any local modifications before using this procedure.

```sh
cd

git config --global --get-all safe.directory | grep -Fxq '/var/www/html/admin/modules/registrationwatch' \
  || git config --global --add safe.directory /var/www/html/admin/modules/registrationwatch

cd /var/www/html/admin/modules/registrationwatch
git fetch origin
git reset --hard origin/main
fwconsole ma install registrationwatch
cd
fwconsole chown
fwconsole reload
```

### Option 4: Update from a local copy

Re-copy or re-link your local `registrationwatch` directory, then:

```sh
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
cd
fwconsole chown
fwconsole reload
```

After updating, open Reports > Registration Watch and confirm that existing watched
registrations, settings, and history are still present.

### Verify the update

After any update, confirm that the installed module and its files report the
expected version before investigating signature state or other update problems.

```sh
fwconsole ma list | grep -i registrationwatch
grep -m1 '<version>' /var/www/html/admin/modules/registrationwatch/module.xml
```

If either command does not report the expected version, the module has not
updated successfully. Correct the update source or module directory first, then
rerun the update before troubleshooting signing or signature verification.

### If the GitHub update did not complete

If the module.xml command still reports an older version, the source update did
not complete. An unusual dirty or local Git working-directory condition, such as
a local file named `FETCH_HEAD`, can make `git reset --hard FETCH_HEAD`
ambiguous. The documented procedure uses `git reset --hard origin/main` to avoid
that ambiguity.

Rerun the documented GitHub update and verify the version again. Do not proceed
to signature troubleshooting until module.xml reports the version you intended
to install.

## Native update discovery

Registration Watch configures the conventional module `<updateurl>` feed
format. The expected JSON includes the module version and identity, package
`location`, `md5sum`, and `packaged` timestamp. This format follows the
[FreePBX third-party module hosting guide](https://sangomakb.atlassian.net/wiki/spaces/FP/pages/10584615/FreePBX+Open+Source+-+Third+Party+Module+Hosting),
which documents `updateurl` support from Framework 13.0.191. The upstream
[FreePBX 16](https://github.com/FreePBX/framework/blob/release/16.0/amp_conf/htdocs/admin/page.modules.php)
and
[FreePBX 17](https://github.com/FreePBX/framework/blob/release/17.0/amp_conf/htdocs/admin/page.modules.php)
Module Admin sources both fetch HTTPS update URLs and decode the JSON metadata;
their
[download handlers for FreePBX 16](https://github.com/FreePBX/framework/blob/release/16.0/amp_conf/htdocs/admin/libraries/Console/Moduleadmin.class.php)
and
[FreePBX 17](https://github.com/FreePBX/framework/blob/release/17.0/amp_conf/htdocs/admin/libraries/Console/Moduleadmin.class.php)
use the feed's `location` when downloading an update.

The configured feed is
`https://raw.githubusercontent.com/freepbxUK/registrationwatch/main/version.json`.
No `version.json` is checked in for this unsigned development build, so it
currently advertises no remote release. The release process must first publish
the signed `registrationwatch-VERSION.tgz` asset at its versioned GitHub
Release URL. Then run the metadata generator; it rebuilds and verifies the
signed package, downloads the published asset over HTTPS, and confirms that
the remote bytes exactly match the locally signed package before writing
`version.json`. It rejects version regression and same-version package
replacement. Only after these checks should the generated file be placed at
the feed URL. This ordering avoids announcing an unavailable package.

FreePBX discovers and presents updates through its native Module Admin
interface and administrator-controlled update settings. This is update
discovery only: Registration Watch does not schedule or execute independent
automatic installations. The downloaded module still contains its signed
`module.sig` and is subject to FreePBX signature verification.

The `<updateurl>` declaration and JSON shape are verified against the
third-party hosting guide and the upstream FreePBX 16 and 17 Framework source.
Live Module Admin discovery and installation have not been tested on either
FreePBX release, so validate those steps on supported PBX installations before
publishing or deploying an update.

## Building release archives

The package builder stages an explicit production-file list under the required
`registrationwatch/` archive directory. It excludes Git metadata, tests,
packaging scripts, temporary files, and the checked-in 1.4.4 signature. Tar
ownership, timestamps, file ordering, and gzip timestamps are normalized so
repeated builds from identical files are byte-for-byte reproducible.

Build an unsigned review package:

```sh
php scripts/package.php --unsigned --output-dir=dist
```

This creates `dist/registrationwatch-1.5.0-unsigned.tgz`. It is not a signed
release and must not be published as production-ready. The script refuses
signed mode unless `module.sig` verifies with the existing signer fingerprint
and its signed per-file hashes match the complete production package list:

```sh
php scripts/package.php --signed --output-dir=dist
```

Before a signed build, the authorized signer must generate a new signature for
the final 1.5.0 production files using the established FreePBX Module Signing
v2 workflow and existing developer identity. Do not copy the 1.4.4 signature,
generate replacement keys, or commit private signing material. The signed
builder requires the existing public key in the local GPG keyring and fails
closed if verification or manifest checks fail. The manually dispatched
GitHub Actions workflow creates only a clearly named unsigned review artifact;
it has no release or publication step.

After publishing the signed package asset, generate the remote update metadata:

```sh
php scripts/update-metadata.php --output-dir=dist
```

The generator fails if signing credentials are missing, the published GitHub
Release asset is unavailable or differs from the signed local package, or the
new version regresses from the existing root `version.json`. It writes
`dist/version.json` only after validation. Publish the release asset first;
then review and place the generated `version.json` at the repository root so
the configured feed starts advertising that available package. This command
does not publish either artifact.

## Webhooks

The **Webhooks** section supports up to 20 independently enabled destinations.
Each destination has a name, URL, POST or GET method, optional JSON payload
template, optional custom headers, and an explicit private-network exception.
POST with JSON is the default. GET places payload fields in the query string;
use it only when the endpoint and its URL logs are suitable for those values.
Credentials in URL user-info and common credential-style query parameter names
are rejected. Because arbitrary parameter names cannot be identified as secrets,
never put credentials in any URL parameter; use encrypted custom headers.
The serialized destination list is capped at 8,000,000 bytes and stored in a
`MEDIUMTEXT` settings column; configured per-field and destination-count limits
fit below that cap.

Webhooks use Registration Watch's existing reconciliation and alert-event
pipeline. Only actively monitored registrations are eligible. Per-event alert
preferences, debounce, snooze, and recovery rules are reused; a recovery is
queued only when its corresponding problem event was queued for that
destination. Webhook delivery is independent of whether email is enabled.
Repeat reminder webhooks are generated when the existing reminder pipeline
produces a reminder. Storm Threshold continues to batch email only; it does not
collapse webhook events.

Default POST JSON payload:

```json
{
  "event": "registration.unregistered",
  "event_id": "stable-sha256-event-identifier",
  "extension": "2001",
  "technology": "PJSIP",
  "previous_status": "Reachable",
  "current_status": "Not registered",
  "timestamp": "2026-11-18T15:30:00+00:00",
  "source": "Registration Watch",
  "system_identifier": "PBX-01",
  "alert_type": "not_registered"
}
```

The available current registration states are `Reachable`, `Unreachable`,
`Registered (no qualify)`, `Unknown`, and `Not registered`. Event names used by
the notification pipeline include `registration.unreachable`,
`registration.unregistered`, and `registration.registered`. The event ID is
stable for the stored transition and alert/reminder type, and is shared across
destinations. It can be used by receivers to deduplicate delivery retries.
The payload does not include contact addresses, device IPs, user agents, or
credentials.

Custom payloads must be valid JSON objects. Use placeholders inside JSON string
values, for example:

```json
{
  "kind": "{{event}}",
  "id": "{{event_id}}",
  "extension": "{{extension}}",
  "from": "{{previous_status}}",
  "to": "{{current_status}}",
  "time": "{{timestamp}}"
}
```

Supported placeholders are `{{event}}`, `{{event_id}}`, `{{extension}}`,
`{{technology}}`, `{{previous_status}}`, `{{current_status}}`, `{{timestamp}}`,
`{{source}}`, `{{system_identifier}}`, and `{{alert_type}}`. Values are
substituted as escaped JSON string content; templates are never evaluated as
PHP or shell code. Unknown placeholders are rejected when saved, and absent
values render as empty strings.

### Authentication and destination security

Use HTTPS for public integrations. TLS certificate verification is enabled,
redirects and environment proxies are disabled, and the resolved destination
address is validated and pinned for each request. Loopback, link-local,
reserved, documentation, benchmarking, multicast, transition, and cloud
metadata addresses are blocked. Only global-unicast public addresses are
accepted by default. To use an internal service, explicitly enable the
private-network exception; it permits RFC1918 IPv4 or ULA IPv6 addresses, but
not carrier-grade NAT or other special-use ranges. HTTP is allowed only for
such an explicitly enabled private destination. TLS verification remains
enabled for internal HTTPS.

Hostname resolution sends bounded DNS queries directly to numeric nameservers
from `/etc/resolv.conf`; it does not call the system hostname resolver.
IPv4 and IPv6 records are checked, and any unsafe answer rejects the whole
destination. A monotonic two-second deadline covers DNS resolution, with at
most one second per address family. cURL is configured to connect to the
validated address; delivery fails closed if that pin cannot be configured.
Hostname destinations fail closed on PHP runtimes without `hrtime()`.

Custom header JSON may contain values such as an `Authorization` bearer token.
Header values are stored as AES-256-GCM ciphertext using a key derived from the
local FreePBX database credential, and are never returned to the GUI, logs, or
delivery history. When editing, blank headers preserve saved values; selecting
**Clear saved headers** removes them. After changing the database credential,
or restoring the encrypted configuration onto a PBX with a different
credential, re-enter custom headers. Keep FreePBX configuration and database
backups protected.

### Testing and delivery behavior

The **Test** action sends a clearly marked `registration.test` event using the
selected destination's saved settings. It does not enqueue a monitoring event
or change registration state. A test succeeds only for an HTTP 2xx response;
the interface reports the HTTP status or a sanitized connection/validation
error. The request has a two-second connect timeout and five-second total
timeout.

Monitoring notifications enter a database queue and are sent by a separate
FreePBX webhook-delivery job; the registration-monitor job only reconciles
registrations and queues events. One queued delivery is attempted per webhook
job run, with a two-second DNS resolution deadline, a five-second request
timeout, a maximum of three attempts
(initial attempt plus retries after 60 and 300 seconds), and no redirects.
DNS resolution failures, transient connection errors, HTTP 408/429, and HTTP
5xx are retried; other non-2xx responses fail. At most 5,000 deliveries can be
pending/in progress,
and the table is bounded at 10,000 rows by removing completed entries when
needed. Old completed records are pruned after 30 days when the queue is idle.
Recent status, attempts, HTTP status, and sanitized errors appear in the GUI.

Delivery is at-least-once: if an endpoint processes an event but the PBX loses
the response, a retry can deliver it again. A process interrupted during
delivery is eligible for retry after a ten-minute processing lease expires.
Stable event IDs are provided for receiver-side deduplication. A full queue
drops new webhook notifications and records a warning; it never blocks the
registration reconciliation or existing email delivery. The PHP cURL extension
is required for HTTP delivery.

#### `git fetch origin` cannot find a remote branch

If `git fetch origin` fails with `fatal: Couldn't find remote ref refs/heads/...`,
an older local checkout may have `remote.origin.fetch` pinned to an obsolete
development branch that no longer exists. This is a local Git checkout
configuration condition, not a Registration Watch runtime failure.

Inspect the configured refspecs with:

```sh
cd /var/www/html/admin/modules/registrationwatch
git config --get-all remote.origin.fetch
```

A normal Registration Watch checkout uses:

```text
+refs/heads/*:refs/remotes/origin/*
```

If the fetch configuration points to an obsolete branch, repair it with:

```sh
cd /var/www/html/admin/modules/registrationwatch

git remote set-url origin https://github.com/freepbxUK/registrationwatch.git

git config --unset-all remote.origin.fetch
git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'

git fetch --prune origin

git branch -r
```

Confirm that `origin/main` is present:

```sh
cd /var/www/html/admin/modules/registrationwatch
git branch -r
```

Then rerun the normal stable update:

```sh
cd /var/www/html/admin/modules/registrationwatch
git reset --hard origin/main
fwconsole ma install registrationwatch
cd
fwconsole chown
fwconsole reload
```

If `git fetch origin` fails, fix the Git checkout before running `fwconsole ma
install`; otherwise it will install whatever older module files are already on
disk. Then verify the installed version again:

```sh
fwconsole ma list | grep -i registrationwatch
grep -m1 '<version>' /var/www/html/admin/modules/registrationwatch/module.xml
```

### Signature warnings after updating an older unsigned release

Some existing installations upgraded from an older unsigned Registration Watch
release may initially show a signature warning after upgrading to signed
Registration Watch 1.4.4.

#### Module is Unsigned

See [Issue #15](https://github.com/freepbxUK/registrationwatch/issues/15).

First try:

```sh
fwconsole ma refreshsignatures
fwconsole chown
fwconsole reload
```

Then recheck Module Admin. If it still says unsigned, verify:

```sh
grep -m1 '<version>' /var/www/html/admin/modules/registrationwatch/module.xml
ls -l /var/www/html/admin/modules/registrationwatch/module.sig
```

If the version is not `1.4.4`, this is an update problem rather than a signature
problem.

#### Signed by unknown or untrusted key

See [Issue #14](https://github.com/freepbxUK/registrationwatch/issues/14).

On affected upgraded systems, the Registration Watch developer public key was
present in the Asterisk GPG keyring, but the signature made by the FreePBX Module
Signing v2 master key on that developer key had not been imported. Check it with:

```sh
sudo -u asterisk gpg \
  --homedir /home/asterisk/.gnupg \
  --check-sigs DD6DC4210FD028092D4981A56079D3C0D58322EE
```

If the FreePBX Module Signing v2 master-key signature is missing, use this tested
remediation:

```sh
grep -qxF 'keyserver-options no-self-sigs-only,no-import-clean' /home/asterisk/.gnupg/gpg.conf \
  || echo 'keyserver-options no-self-sigs-only,no-import-clean' >> /home/asterisk/.gnupg/gpg.conf

chown asterisk:asterisk /home/asterisk/.gnupg/gpg.conf

curl -s "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x6079D3C0D58322EE" \
  | sudo -u asterisk gpg --homedir /home/asterisk/.gnupg --import

fwconsole ma refreshsignatures
fwconsole chown
fwconsole reload
```

This is a tested remediation for affected existing installations, not a normal
installation step. Fresh signed installations tested successfully without it.

Troubleshooting order:

1. Verify the installed/module.xml version.
2. If the version is old, check whether the Git source update actually completed.
3. If `git fetch origin` fails because an obsolete remote branch is configured, repair the origin fetch configuration and rerun the update.
4. Only once the expected version is installed should signature troubleshooting begin.
5. If Module Admin says Unsigned, follow Issue #15.
6. If Module Admin reports an unknown or untrusted key, follow Issue #14.
7. Recheck Module Admin after refreshing signatures.

## Support and Feedback

Registration Watch is maintained by FreePBX UK.

For installation help, configuration questions, troubleshooting, or general support, contact FreePBX UK:

https://freepbx.uk/support/

For source code, release history, and project information:

https://github.com/freepbxUK/registrationwatch

When requesting support, include the following where relevant:

- FreePBX or PBXact version
- Registration Watch version
- Asterisk version
- whether the affected device is PJSIP
- the extension or registration behaviour being observed
- any relevant Registration Watch status or alert history
- the steps needed to reproduce the issue

Do not include passwords, SIP secrets, API credentials, private keys, or other sensitive information in support requests.

For suspected security vulnerabilities, do not post exploit details publicly. Contact FreePBX UK directly so the issue can be reviewed privately.

## Background Checks

Registration Watch registers two separate FreePBX jobs from its install hook.
The hook runs on installation and upgrade and uses stable module/job
identifiers, so repeating the install does not create a second logical job:

```text
registrationwatch :: monitor
registrationwatch :: webhooks
```

`registrationwatch :: monitor` is implemented by
`\FreePBX\modules\Registrationwatch\Job`. It reconciles registration status,
records transitions, and queues eligible email and webhook notifications. It
does not make webhook HTTP requests.

`registrationwatch :: webhooks` is implemented by
`\FreePBX\modules\Registrationwatch\WebhookJob`. It processes at most one
queued webhook delivery per run, including retry scheduling and delivery
history updates. Both jobs are scheduled once per minute by the FreePBX Job
runner.

Check that both jobs are registered:

```sh
fwconsole job --list | grep -i registrationwatch
```

The list should contain both identifiers above. To run either job manually,
use its actual job ID from that list:

```text
fwconsole job --run=<job_id> --force
```

If either job is missing, rerun the module install/upgrade through Module Admin
or `fwconsole ma install registrationwatch`, then check the FreePBX logs for a
job-registration error and list the jobs again. If the webhook job is present
but deliveries are not progressing, run that job using its listed ID, check
FreePBX logs, and inspect **Recent webhook deliveries** in Registration Watch.
The worker records delivery failures there; a successful job invocation does
not by itself mean the remote endpoint accepted a delivery.

The module does not install a daemon, systemd unit, AMI event listener, custom
probe service, or SMS sender. Uninstallation removes both
`registrationwatch :: monitor` and `registrationwatch :: webhooks`.

## Data Model

The canonical Registration Watch table names are:

* `registrationwatch_registrations`
* `registrationwatch_status_history`
* `registrationwatch_settings`
* `registrationwatch_alert_history`
* `registrationwatch_alert_escalation`
* `registrationwatch_webhook_queue`

`registrationwatch_registrations` stores watched registration entries,
including discovered contact details, watch toggles, admin notes, and the latest
status snapshot.

`registrationwatch_status_history` stores transition rows created during
reconciliation.

`registrationwatch_settings` stores simple key/value settings, including polling
interval, show limits, alert configuration, repeat-alert configuration, storm
threshold, history pruning policies, global monitoring snooze state, and
remembered UI preferences such as the Cards/Rows view choice, sort columns, and
sort directions.

Extension monitoring authority is also stored in `registrationwatch_settings`
using keys in the form `extension_monitoring_state_<extension>` (`1` or `0`).
These keys are the authoritative monitored/unmonitored state used by runtime
reconciliation and discovery for each extension.

A one-time install/upgrade migration marker
`extension_monitoring_state_migrated_v1` records that legacy row-level monitored
states were migrated to extension monitoring settings.

`registrationwatch_alert_history` stores one row per recipient and alert
decision. The unique key `registrationwatch_alert_unique_transition_recipient`
prevents repeated handling of the same transition, alert type, and recipient.
Storm-suppressed rows are also stored here so the audit trail shows which
individual messages were not sent because a Storm Summary was used.

`registrationwatch_alert_escalation` stores repeat-alert reminder state for
registrations that remain unavailable, tracking when the next reminder is due
under the configured repeat mode.

`registrationwatch_webhook_queue` stores one queued delivery per destination
and logical event. Its states are `pending`, `processing`, `delivered`,
`failed`, and `suppressed`. It records the payload, attempt count, next retry
time, last attempt, HTTP status, and bounded error text; its recent rows are
shown as webhook delivery history. Transient connection failures, HTTP 408,
429, and 5xx responses are retried up to three attempts. Only one delivery is
attempted by each webhook-job run.

The queue is limited to 5,000 pending/processing rows and 10,000 rows total.
When the total limit is reached, old terminal rows (`delivered`, `failed`, or
`suppressed`) are pruned in batches before new events are accepted; if the
pending/processing or total limit still prevents insertion, new webhook events
are not queued and a warning is logged. Terminal rows older than 30 days are
removed during an idle worker pass. An upgrade creates this table if it does
not exist and widens the settings value column for webhook configuration; it
does not reset existing settings, monitoring selections, registrations, or
notification history. Uninstallation drops the webhook queue along with the
other `registrationwatch_*` tables.

## Alerting

Alerts are generated from reconciliation-created transition rows.

Discovered registrations for configured PJSIP devices are listed in the Watched
Extensions table but not monitored by default. Enable the Monitored toggle for
any registration that should generate alerts.

Monitoring authority is extension-level. The UI Monitored toggle writes the
extension monitoring setting, and all registration rows for that extension are
kept consistent with this setting. Runtime behaviour does not infer extension
authority from `registrationwatch_registrations.enabled`.

Defaults:

* Alerts disabled
* Recipients empty
* Alert on unreachable enabled
* Alert on not registered enabled
* Alert on recovery enabled
* Debounce seconds: `0`, maximum `86400`
* Repeat alerts: `Never`
* Storm Threshold: `20`
* Auto-disable absent registrations: `2592000` seconds, 30 days

Alertable transitions:

* Reachable or Registered (no qualify) to Unreachable
* Reachable, Registered (no qualify), or Unreachable to Not registered
* Unreachable or Not registered to Reachable
* Unreachable or Not registered to Registered (no qualify)

An Unknown-origin problem transition does not start a new alertable incident.
However, Unknown does not end an existing incident that began in an alertable
unhealthy state. That incident can recover directly from Unknown if its problem
was successfully notified. Old status-history rows are not replayed later after
recipient or settings changes.

Repeat alert modes continue on the same schedule throughout a continuous
unhealthy incident, including transitions between Unreachable, Unknown and Not
registered. A change between alertable unhealthy states may still produce an
initial problem notification when that state's alert is enabled, but it does not
restart the existing repeat schedule or numbering. Reminders use the current
live unhealthy state and respect its corresponding Alert on toggle. If the live
state is Unknown, or its alert toggle is disabled, a due reminder is deferred
without consuming its reminder number. It can be sent on a later monitoring
pass when a known unhealthy state is present and the relevant alert is enabled.

Repeat alert modes:

* Never: send no repeat reminders. Eligible initial notifications for later alertable unhealthy-state changes can still be sent.
* Every 5 minutes: repeat every 5 minutes while the registration remains unavailable.
* Hourly: repeat once per hour while unavailable.
* Daily: repeat once per day while unavailable.
* Escalating: uses a Fibonacci-style backoff schedule, starting with shorter reminders and gradually increasing the interval up to daily. The wait between reminders follows Fibonacci multipliers on a 5-minute base:
  * 5 min, 5 min, 10 min, 15 min, 25 min, 40 min, 65 min, 105 min, …
  * Capped at 24 hours once the interval reaches the daily ceiling.

Stored legacy `fibonacci` repeat-mode values are treated as Escalating.

Debounce Delay applies to alerting, not Status History. Status History records
what happened; Alert History records what Registration Watch decided to do
about it. Reconciliation records an observed transition to Unreachable or Not
registered as soon as it detects the change.

During the configured delay, the initial problem notification is awaiting
eligibility; it has not been suppressed. No suppression entry therefore appears
in Alert History while the timer is running. If the registration remains
continuously unhealthy until the delay expires, the problem notification becomes
eligible for processing. Alert History then records each recipient's decision:
Sent, Failed, suppressed for another reason, or covered by a successfully
delivered Storm Summary. Moving between Unreachable and Not registered does not
restart the timer.

If the registration recovers before the delay expires, the problem notification
is suppressed. When problem and recovery alerts are enabled, Alert History
records both recipient-specific decisions: the problem as Suppressed with
reason `Debounce Delay`, and the withheld recovery as Suppressed with the same
reason `Debounce Delay`. These entries are recorded when recovery makes the
decisions final, not while the problem is still awaiting eligibility. Neither
email is sent, no repeat or escalation state is started, and nothing from the
short incident is released later.

A debounce value of `0` retains immediate alerting. A genuine recovery cancels
the active repeat schedule. Recovery alerts require a successful problem
notification, whether it was an initial alert, a repeat, or coverage by a
successful Storm Summary. Alert History also records, per recipient, when a
recovery notification is withheld because the corresponding problem
notification was deliberately suppressed or not successfully delivered. These
audit entries do not send additional emails or change recovery eligibility.

Storm Threshold limits large batches of alerts generated in the same processing
pass. It reduces email floods from sudden widespread registration changes, but
it is not full correlated-outage detection. The count is per registration. Use
0 to disable.

Watched registrations that have been continuously absent for 30 days are
auto-disabled to stop stale entries from alerting indefinitely. They remain
visible in the Watched Extensions table. If the same registration returns, it is
re-enabled automatically.

Alert delivery depends on FreePBX/PBXact mail configuration. A successful send
result means the message was accepted by the PBX mailer, not that final external
delivery is guaranteed.

## Push Mobile Softphones

Registration Watch is designed primarily for monitoring desk phones, gateways, and other endpoints that maintain a stable PJSIP registration. Push-based mobile softphones such as Bria may create temporary, duplicate, stale, or rapidly changing PJSIP contacts as the application or operating system manages background activity.

Registration Watch reports the contact state exposed by Asterisk and does not attempt to reconcile multiple or transient contacts that a push-enabled mobile application may present. Where alerting is not desirable, individual extensions can be excluded from alerting.

## Snooze Monitoring

Snooze Monitoring is a global pause control in the top monitoring banner. While
active, Registration Watch continues to reconcile and record registration
state changes, but suppresses eligible email and webhook notifications.

* The snooze is global and applies to all watched registrations.
* Monitoring can be resumed manually before the snooze period ends.
* Snoozing does not reset watched registrations or settings, or delete existing
  history. Reconciliation continues to add status transitions and notification
  suppression records to the relevant histories.
* There is no per-registration snooze.
* New alertable transitions during a snooze remain in Status History. Eligible
  email notification attempts are recorded as suppressed; webhook events
  created during the snooze are queued with status `suppressed` and are not
  sent.
* A webhook delivery that was already pending is not removed when snoozing
  starts. If the worker processes it while the snooze is active, it is marked
  suppressed without making an HTTP request or consuming an attempt. If
  monitoring is resumed first, the still-pending delivery can be processed
  afterwards.
* Recovery transitions are still recorded during a snooze. Webhook recovery
  delivery follows the existing corresponding-problem-event rule and snooze
  check; a recovery does not cause a suppressed problem event to be replayed.
* Resuming clears the snooze setting; it does not replay webhook rows already
  marked `suppressed` or resend email attempts already recorded as suppressed.
  Pending webhook rows not yet processed remain subject to the worker behavior
  described above.

## User Interface

The Registration Watch admin page is available under **Reports > Registration Watch**
and contains the following sections:

The admin page supports narrow and mobile viewports, while wide data tables retain local horizontal scrolling.

* **Monitoring banner** -- shows the current monitoring state (active, inactive, or snoozed) with Snooze/Resume controls.
* **Registration Status Map** -- shows all discovered registrations. Supports Cards and Rows views. The Row view has sortable columns.
* **Alert Settings** -- configures recipients, alert triggers, debounce, repeat alerts, storm threshold, and topology polling.
* **Webhooks** -- configures multiple destinations, custom payloads, private-network exceptions, tests, and recent delivery results.
* **Watched Extensions** -- lists watched registrations with extension-level monitoring toggles, repeat-alert overrides, and admin notes. Columns are sortable.
* **Status History** -- records registration state transitions. Retained history is paginated in bounded pages, with sorting across the complete retained history.
* **Alert History** -- records sent and suppressed alert attempts. Retained history is paginated in bounded pages, with sorting across the complete retained history.

Cards/Rows view choice, sort columns, and sort directions are persisted using
module settings rather than browser-only local storage, so they are remembered
across browsers for the same PBX. The Show limit is persisted for the
Registration Status Map and Watched Extensions.

## Security Model

* AJAX commands use a fixed command allowlist.
* AJAX handlers require a module-owned session CSRF token.
* Persisted UI settings are restricted to known setting keys and allowed values.
* SQL writes and history deletes use prepared statements.
* Asterisk access is read-only in this phase.
* The module runtime does not execute shell commands. The separate release packaging CLI invokes local tar/gzip tools.
* Alerts are not sent merely from page load.
* Webhook requests use HTTPS by default, reject unsafe resolved addresses, pin DNS results, disable redirects/proxies, and verify TLS certificates.
* Webhook authentication headers are encrypted before storage and are not exposed in GUI responses or delivery diagnostics.

Webhook destination configuration, testing, deletion, and delivery history are
available only to full FreePBX administrators. Registration Watch's other
permissions and read-only monitoring views remain governed by the existing
FreePBX module access.

## Current Limitations

* Reconciliation is periodic, not event-driven.
* No AMI ContactStatus listener yet.
* No custom probes.
* No maintenance windows yet.
* No SMS alert delivery.
* Native Module Admin update discovery is source-verified for FreePBX 16 and 17 but has not been tested on live installations. No `version.json` is published for this development checkout, so no release is currently advertised; see [Native update discovery](#native-update-discovery).
* Configurable email subject/body templates are deferred; existing email content and transport behavior are unchanged.
* Short flaps can be missed between reconciliation runs.
* Email delivery depends on the PBX mail sender and relay setup.
* Webhook hostname lookups use a bounded direct DNS exchange with a monotonic 2-second deadline and fail closed on timeout, missing `hrtime()`, or invalid responses. HTTP delivery separately has a 2-second connect timeout and a 5-second overall timeout.
* Webhook Storm Threshold batching is not implemented; the email Storm Threshold applies to email summaries only.
* Snooze Monitoring is global, not per-registration.
* Registration Watch only watches registrations for configured FreePBX PJSIP
  devices (extensions). PJSIP trunks and other non-device PJSIP objects are not
  watched. A live contact is matched to a device by its endpoint identity
  against the FreePBX devices table, so custom or unusual PJSIP objects whose
  contact target does not match a configured device ID will not appear.
* Registration Watch identifies watched entries from the FreePBX extension
  identity and registration/contact details exposed by Asterisk. Multiple
  contacts for the same extension may appear as separate watched entries where
  Registration Watch can distinguish them. Device IP, network IP, user-agent,
  firmware, contact expiry, and latency details may not be available for every
  device or system.

## Validation

### Development validation

Run these checks from the root of a complete source checkout containing the
tests and packaging scripts. They are developer checks and are not available
from an installed module archive.

Check PHP syntax, including both FreePBX job classes, and validate the module
identity and release version:

```sh
find . -path './.git' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
php -r '$module = simplexml_load_file("module.xml"); if (!$module || (string)$module->rawname !== "registrationwatch" || (string)$module->version !== "1.5.0") { fwrite(STDERR, "Invalid module metadata\n"); exit(1); }'
```

Run all PHP contract tests:

```sh
php tests/email_from_contract.php
php tests/history_pagination_contract.php
php tests/install_migration_contract.php
php tests/repeat_alerting_contract.php
php tests/reset_from_asterisk_contract.php
php tests/version_compatibility_contract.php
php tests/webhook_contract.php
php tests/webhook_queue_contract.php
php tests/packaging_contract.php
php tests/update_metadata_contract.php
```

Build and checksum the unsigned development archive:

```sh
php scripts/package.php --unsigned --output-dir=dist
sha256sum dist/registrationwatch-1.5.0-unsigned.tgz > dist/registrationwatch-1.5.0-unsigned.tgz.sha256
sha256sum --check dist/registrationwatch-1.5.0-unsigned.tgz.sha256
```

The package builder validates the archive structure. The packaging contract
builds the archive twice and compares the bytes to check reproducibility. The
webhook tests use stubbed DNS and HTTP transports, and the update-metadata
contract uses local files only; these tests make no external requests.

### Installed FreePBX verification

Perform these checks on an actual FreePBX/PBXact 16 or 17 installation after
installing an authorized signed package. Do not run development tests or
packaging scripts from the installed module directory; they are not included
in the installation archive.

Confirm the module is installed at the expected version and check its status:

```sh
fwconsole ma list | grep -i registrationwatch
grep -m1 '<version>' /var/www/html/admin/modules/registrationwatch/module.xml
```

In **Admin > Module Admin**, confirm the module identity, installed version,
and signature verification status. A development unsigned archive is not a
production installation artifact.

Reload FreePBX, verify both registered jobs, and run each using the job ID
reported by the list:

```sh
fwconsole reload
fwconsole job --list | grep -i registrationwatch
fwconsole job --run=<job_id> --force
```

Confirm that the list contains `registrationwatch :: monitor` and
`registrationwatch :: webhooks`. Run the listed ID for each job. Verify that
registration monitoring updates the Registration Watch page and Status
History. As a full FreePBX administrator, send a webhook test from the
Webhooks section and confirm its result in Recent webhook deliveries. Before
and after an upgrade, compare the existing monitoring selections, settings,
registrations, and notification history; upgrade without uninstalling.

## Uninstalling

Uninstalling Registration Watch removes the `registrationwatch :: monitor`
and `registrationwatch :: webhooks` jobs and drops all
`registrationwatch_*` tables, including `registrationwatch_webhook_queue`.
Back up first if you need Registration Watch settings, monitoring selections,
registration records, notification history, or queued webhook history.

```sh
fwconsole ma uninstall registrationwatch --force
rm -rf /var/www/html/admin/modules/registrationwatch
fwconsole chown
fwconsole reload
```

## Release History

### 1.5.0, development release

Adds multiple configurable webhook destinations with POST/JSON and opt-in GET,
safe JSON template substitution, encrypted authentication headers, and
administrator-only destination configuration, testing, and delivery history.
Delivery uses a separate FreePBX webhook job, isolated from registration
monitoring and email sending, with bounded DNS resolution, DNS/IP-based SSRF
protection, a persistent queue, bounded retries, event identifiers, and
delivery status history. Webhook events follow Registration Watch's existing
eligibility rules for watched registrations, transitions, debounce, snooze,
alert types, and recovery.
Existing monitoring selections, settings, notification history, and email
delivery behaviour are preserved during upgrade.

Adds a repeatable `.tgz` package builder with explicit production-file
inclusion, deterministic archive metadata, structure checks, and fail-closed
signed-package validation. The checked-in 1.4.4 signature is not reused; the
local 1.5.0 development archive and manually dispatched CI artifact are
explicitly unsigned and review-only. Native FreePBX 16/17 update discovery is
configured, but the development build does not publish `version.json` or
advertise a release. A production 1.5.0 release still requires live FreePBX
testing, authorized signing, and publication of the signed package and update
metadata. Email subject/body templating is deferred.

### 1.4.4, patch release, 15 October 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

Debounce Delay now suppresses transient failures that recover within the
configured delay. Status History retains actual transitions, and Alert History
records suppressed debounce decisions, including recovery notifications
withheld because the corresponding problem notification was suppressed or not
successfully delivered. These additional audit entries do not send emails or
change recovery eligibility. Problem tracking and repeat schedules continue
across Unreachable, Unknown and Not registered states. Unknown does not start a
new problem alert or end an existing incident; due reminders are deferred
without consuming their number until a known unhealthy state returns.
Transitions into another alertable unhealthy state can still produce an
initial notification when enabled, without restarting the incident's repeat
schedule.

Recovery notifications follow successful initial alerts, repeats or Storm
Summaries, including recovery directly from Unknown for an existing notified
incident. Duplicate and obsolete reminders are prevented, newer incident
schedules are preserved during recovery cleanup, and historical replay respects
completed recovery boundaries. Repeat reminders also honour their corresponding
alert-type settings. Expanded regression coverage and native PDO MySQL prepared
statement compatibility checks pass.

Cards/Rows highlighting now works consistently across FreePBX and PBXact. Changing
the shared Show selector immediately updates the Registration Status Map.

### 1.4.3, patch release, 10 September 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This patch fixes GitHub issue #17 by decoding the HTML-entity-encoded Email
"From:" Address returned by FreePBX 17 before parsing and validation, then using
one resolved From identity for test emails and every alert type. Bare addresses
retain the existing sender-name fallback, while values such as
`PBX-123 <asterisk@demodomain.name>` preserve the explicit display name.
Malformed values continue to fail safely and the extracted address must pass
normal email-address validation.

### 1.4.2, patch release, 3 September 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This patch fixes Status History and Alert History pagination. History tables now report the full retained row count and page through records in bounded 25-row server-side pages. Sorting applies across the complete retained history rather than only the currently displayed rows, with page state preserved across refresh and history maintenance actions.

Also improves test-harness portability across PHP environments, including compatibility with gettext-provided `_()` functions and PDO SQLite drivers that return integer IDs using different PHP types.

### 1.4.1, patch release, 2 September 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This is the first signed FreePBX UK release of Registration Watch. It adds FreePBX module signing and updates publisher, repository, support, and project metadata. There are no functional module changes in this release.

### 1.4.0, minor release, 20 August 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This release adds the configured FreePBX System Identifier to each Registration
Watch email body so administrators monitoring multiple PBXs can immediately tell
which system sent the alert, summary, or test email. It preserves the existing
subject lines and uses the same `unknown system` fallback as Repeat Caller when
that identifier cannot be read.

### 1.3.1, patch release, 29 July 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This patch release focuses on registration-state continuity and recovery for
extensions whose contact identity changes.

#### Registration continuity

* Preserves watched registration identity when a monitored endpoint changes network identity.
* Prevents obsolete registrations from continuing to generate repeat alerts after a successful replacement.
* Reuses the existing watched registration where continuity can be determined unambiguously.
* Preserves genuine simultaneous multi-contact registrations by refusing to guess when multiple candidates exist.

#### Automatic handover safeguards

* Uses conservative multi-poll confirmation before in-place handover: 2 matching polls by default, or 3 when candidate churn/ambiguity is detected.
* Requires exactly one enabled degraded watched row and exactly one reachable replacement candidate for a handover to proceed.
* Requires replacement-candidate age of at least one poll window before mutation, reducing same-pass flapping decisions.
* Runs post-handover validation for 3 polls; if validation fails, automatic mutation is suspended for safety.
* Suspended per-extension mutation is released only after 3 stable healthy polls.
* Detects widespread topology churn and delays/suspends automatic mutation during bulk events until stability returns.
* Manual **Reset from Asterisk** clears extension candidate/suspension state before rebuild.

#### Reset from Asterisk maintenance action

* Adds a per-extension **Reset from Asterisk** action beside Active Alerting in Watched Extensions.
* Requires confirmation before changing state and explains intended recovery-only usage.
* Acquires the existing reconcile lock before running so it does not race normal reconciliation.
* Stops active escalation state for the selected extension.
* Rebuilds current registration rows for the selected extension from live Asterisk contact data only.
* Removes stale current registration rows that Asterisk no longer reports for that extension.
* Preserves extension-level monitoring configuration, repeat alert mode, and administrator notes where they can be safely retained.
* Preserves Status History and Alert History audit tables.

#### Metadata and display polish

* Improves registrar user-agent parsing for Sangoma P-series phones so firmware tokens such as `4_27_8` are shown in Version instead of `-` when present.
* Normalises user-facing reason labels so `ip_address_change` renders as `IP address change`.
* Repairs truncated contact URI port values during registrar enrichment, so stored contact/source ports are corrected at source while preserving NAT device/network port split handling.

#### Monitoring state authority

* Treats extension monitoring state configured by the UI as the only runtime authority.
* Stores extension monitoring state in `registrationwatch_settings` keys named `extension_monitoring_state_<extension>`.
* Keeps all registration rows for an extension aligned with that configured setting during discovery and reconciliation.
* Adds a deliberate install/upgrade migration path for legacy row-level monitored data and removes runtime inference from registration rows.

#### Test harness compatibility

* Updates repeat alerting contract test SQL write handling to avoid modern SQLite UPSERT-only syntax so regression tests run consistently on older SQLite environments; production module runtime logic is unchanged.

### 1.3.0, minor release, 29 July 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This release adds FreePBX 16 support while retaining FreePBX 17 compatibility, uses shared module metadata for both supported versions, and widens registration notes from 48 to 72 characters when upgrading existing installations.

### 1.2.1, patch release, 18 July 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This focused patch fixes the Registration Watch admin page expanding to desktop content width on narrow and mobile viewports because of the FreePBX page-body table layout. Wide tables continue to scroll locally, and desktop behaviour and module runtime logic are unchanged.

### 1.2.0, minor release, 17 June 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This minor release renames EndPoint Monitor to Registration Watch, moves the module to a per-registration model, adds repeat alerting, improves alert tuning, adds Snooze Monitoring, and significantly improves the Registration Watch admin UI.

#### Module rename

* Renames EndPoint Monitor to Registration Watch.
* Renames module internals to use registration-based domain language.
* Changes the module path, page, assets, selectors, and runtime references to `registrationwatch`.
* Adds `registrationwatch_registrations` as the canonical registrations table.
* Preserves literal PJSIP/Asterisk endpoint terminology only where required by the platform.

#### Registration model

* Moves from one row per extension to a per-registration model.
* Tracks watched registrations using registration/contact identity rather than extension number alone.
* Supports multiple registrations under the same extension.
* Groups watched registrations by extension in the Watched Extensions table.
* Reduces duplicate rows and re-registration flapping by using registration/contact details.
* Keys watched registrations after registrar enrichment, so authoritative `via_addr` and exact-contact user-agent data contribute to registration identity.
* Adds per-registration history, alert, and repeat-alert state.
* Adds reconciliation locking to reduce duplicate transition handling.
* Restricts watched registrations to configured FreePBX PJSIP devices.
* Ignores trunk contacts and other non-device PJSIP objects.
* Adds visible Not registered placeholder rows for configured PJSIP devices with no live contact.
* Inserts newly discovered no-contact placeholders as discovered but unmonitored, so newly listed extensions do not generate alerts by default.
* Promotes no-contact placeholders when a matching live contact appears.
* Demotes stale live contacts back to placeholder state when the live contact disappears, preserving the configured device row.

#### Repeat alerts

* Adds opt-in repeat alerting for registrations that remain in an alertable problem state.
* Adds repeat alert modes for Never, Every 5 minutes, Hourly, Daily, and Escalating.
* Adds per-registration repeat alert overrides in the Watched Extensions table.
* Adds escalation state so reminders are state-driven rather than purely transition-driven.
* Uses Fibonacci-style intervals for Escalating reminders.
* Updates Escalating repeat timing to use 5-minute-base intervals.
* Clarifies repeat alert email wording so repeat reminders are clearly distinguished from initial state-change alerts.
* Treats stored legacy `fibonacci` repeat-mode values as Escalating.

#### Alert tuning and flood control

* Adds alert tuning controls for repeat alert scheduling, debounce behaviour, and storm protection.
* Changes the default debounce delay to immediate alerting for new installs.
* Caps alert timing values safely.
* Adds storm threshold/flood-control behaviour.
* Moves Alert Settings above Watched Extensions.
* Moves Alert Settings actions into the left column beneath alert toggles.
* Relocates Storm Threshold and diagnostics into the alerting decision area.
* Improves alert settings layout, spacing, and help text.
* Changes Recipients from a single-line input to a three-row textarea.
* Prevents duplicate alert settings save handlers.
* Prevents repeated Repeat alerts handler binding.
* Guards frontend script initialisation against duplicate loading.
* Adds live module/database time diagnostics to help confirm current server-side timing during refresh and alert testing.
* Moves diagnostics into a quiet page footer while preserving live AJAX updates.
* Replaces stale Manual Refresh empty-state wording with clearer refresh/status messaging.
* Aligns Storm Summary email wording with Alert Settings help text and fixes singular/plural suppression wording.

#### Snooze Monitoring

* Adds a global Snooze Monitoring control in the top monitoring banner.
* Shows active, inactive, and snoozed monitoring states.
* Adds quick pause buttons and a countdown while monitoring is snoozed.
* Allows monitoring to be resumed before the snooze period ends.
* Stores global snooze state in module settings.
* Removes the earlier experimental row-level Snooze implementation in favour of the global control.

#### Watched Extensions improvements

* Renames the admin list to Watched Extensions.
* Replaces the plain Monitored checkbox presentation with a clearer on/off toggle.
* Adds Monitored column sorting to the Watched Extensions table.
* Adds an active-alert disable control for watched registrations in an active problem state.
* Shows active-alert watched rows with clearer red warning styling.
* Adds compact active-alert labelling and Disable alerting action text.
* Adds 72-character Watched Extensions notes.
* Fixes notes autosave length handling so frontend, backend, and schema limits match.
* Fixes notes rendering after AJAX refresh.
* Fixes saved notes disappearing after sorting by updating the in-memory watched-registration cache after a successful notes save.
* Fixes Repeat alerts dropdown reliability in the Watched Extensions table.
* Clears active-alert row styling immediately after Disable Alerting succeeds.
* Prevents duplicate Repeat alerts handler binding.
* Prevents automatic refresh from interrupting Watched Extensions controls while they are being used.
* Keeps watched-row controls usable during automatic topology refreshes.
* Improves Watched Extensions table spacing, alignment, row separation, and saved-status text positioning.
* Improves saved-status alignment for Repeat alerts and Notes.

#### Registration Status Map

* Adds Cards and Rows views for the Registration Status Map.
* Adds registration detail columns to Registration Status Map row view.
* Adds sortable Registration Status Map row columns.
* Restores the flat card layout with cards shown side by side.
* Improves map card wording and removes unnecessary source/contact clutter.
* Normalises SIP URI parameters before contact matching, so registrar enrichment still works when Asterisk adds `;x-ast-*` metadata.
* Keeps empty card values clean instead of showing misleading placeholders.

#### Remembered UI preferences and sorting

* Remembers Registration Status Map Cards/Rows view.
* Remembers Registration Status Map row sorting.
* Adds sortable Watched Extensions columns.
* Adds sortable Status History columns.
* Adds sortable Alert History columns.
* Persists selected sort columns and sort directions using module settings.
* Stores remembered UI state in `registrationwatch_settings`, not browser-only local storage.
* Hardens persisted UI setting validation so only known view modes, sort directions, and table sort keys can be saved.

#### History and table display

* Makes Status History row colouring reflect the resulting state.
* Makes Alert History row colouring state-based.
* Applies table state colours directly to cells to work reliably under the FreePBX admin theme.
* Makes Watched Extensions row highlighting reflect monitored state.
* Improves active-alert row presentation.
* Tidies Alert Settings repeat-alert help text spacing.
* Moves module/database time diagnostics into a quiet page footer while preserving live AJAX updates.

#### Discovery and refresh behaviour

* Updates the AJAX refresh path so Watched Extensions auto-populates after discovery.
* Adds notes fields to the `gettopology` registration payload.
* Adds stale-guarded reconciliation to topology polling, so browser polling only triggers live reconciliation when stored state is older than the configured poll interval.
* Keeps automatic topology polling from rebuilding the Watched Extensions table while controls are focused or being used.

#### Documentation

* Documents the PJSIP device allowlist.
* Clarifies that Registration Watch tracks configured FreePBX PJSIP devices only.
* Clarifies that trunks and other non-device PJSIP objects are ignored.
* Documents repeat alert modes and the escalation table.
* Clarifies that discovered registrations are not monitored by default.
* Aligns README wording with Watched Extensions and registration-based terminology.

### 1.1.1, patch release, 13 June 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This patch release focuses on alert correctness, clearer endpoint status reporting, improved address visibility, and UI polish.

#### Alert fixes

* Prevents stale EndPoint alert backlog replay by only sending alerts for fresh post-debounce transitions.
* Requires EndPoint alert candidates to still be selected before an alert is sent.
* Aligns duplicate alert checks with the alert type, so different alert types are handled correctly.
* Updates alert email wording so it describes the actual transition rather than presenting old history as current status.
* Preserves last-known address details for Not registered alerts where available.

#### Endpoint address and status improvements

* Shows Device and Network address details in EndPoint alerts and displays.
* Refreshes history tables using stored endpoint data.
* Corrects EndPoint status colour mapping.
* Displays EndPoint card contact expiry as a compact countdown.
* Cleans up EndPoint display wording.

#### UI and layout improvements

* Tidies history table display labels.
* Improves mobile history table layout.
* Improves mobile EndPoint layout.
* Applies more consistent sentence-case display labels across EndPoint status and history output.

### 1.1.0, minor release, 12 June 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This minor release adds safe history pruning, improves AJAX/session protection, reduces unnecessary write behaviour during page rendering, and improves endpoint address handling.

#### New features

* Adds configurable Status History pruning.
* Adds configurable Alert History pruning.
* Supports Never, Hourly, Daily, Monthly, and Yearly pruning policies.
* Adds confirmed single-row history deletion.
* Adds module-owned session CSRF protection for AJAX requests.

#### Safety and data handling

* Makes initial page rendering read-only.
* Makes EndPoint map auto-refresh read-only.
* Keeps discovery/reconciliation out of passive page loads.
* Caps alert timing fields to 0-86400 seconds.
* Improves input handling around pruning and alert timing controls.

#### UI improvements

* Improves pruning Apply/Active UI.
* Improves responsive history controls.
* Adds friendlier history reason labels.
* Tidies alert email delivery guidance.

#### Endpoint display improvements

* Corrects EndPoint address display by deriving Device IP and Device Port from the SIP Contact URI.
* Shows Device IP separately from Asterisk source data.
* Removes misleading or noisy Asterisk source details from default EndPoint and alert output.

### 1.0.1, patch release, 11 June 2026

Released by `@kierknoby, Kieran Knowles-Byrne // FreePBX UK`.

This patch release fixes duplicate alert and duplicate UI handling issues found after the initial release, with minor wording and documentation cleanup.

#### Alert fixes

* Fixes alert send reservation to prevent duplicate normal alert emails.
* Prevents duplicate Test Email click binding.
* Removes duplicate notes autosave handling.

#### Display and wording improvements

* Maps internal source labels to Asterisk.
* Updates FreePBX/PBXact 17-only release wording.
* Cleans up minor alert email copy.
* Adds clearer 1.0.1 release headings to the README.
* Documents EndPoint Monitor update paths.

## Licence

GPLv3+. See LICENSE.

## AI-Assisted Contributions and Disclosure

This module has been developed with AI assistance for code generation, review, testing, and documentation. From 26 August 2026, generative AI assistance must be disclosed in every commit containing AI-assisted changes:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

For example: `Assisted-by: GitHub-Copilot:gpt-5.6-sol`

The human contributor remains solely responsible for the contribution. AI tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne // [FreePBX UK](https://github.com/freepbxUK)
