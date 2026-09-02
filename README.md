# Registration Watch 1.4.0 for FreePBX 16 and 17

Registration Watch (`registrationwatch`) watches PJSIP registration state in
FreePBX/PBXact 16 and 17. It discovers configured FreePBX PJSIP devices and tracks
their registration and contact state, recording state changes and sending email
alerts when watched registrations become unavailable or recover.

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

Registration Watch 1.4.0 is the current release for FreePBX 16 and 17.

Use the `main` branch for stable releases. Development and release-candidate
branches may contain incomplete or test-only changes.

## Compatibility

Use with FreePBX/PBXact 16 or 17.

## Requirements

* FreePBX/PBXact 16 or 17
* PJSIP channel driver
* Existing FreePBX PJSIP extensions/devices
* Asterisk Manager access available to FreePBX
* FreePBX Job runner enabled for scheduled background checks
* FreePBX mail support configured if alert email delivery is required
* Email "From:" Address set in Advanced Settings (alerts will not send without it)

## Installing

Registration Watch is a community module and is not currently listed in the
FreePBX online module repository.

Do not use:

```sh
fwconsole ma installlocal registrationwatch
```

Use `fwconsole ma install registrationwatch` with one of the methods below.

### Option 1: Install from an unpacked module directory

Place the `registrationwatch` directory in `/var/www/html/admin/modules/`, then:

```sh
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
fwconsole chown
fwconsole reload
```

### Option 2: Install from GitHub

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
cd /var/www/html/admin/modules
git clone https://github.com/kierknoby/registrationwatch.git registrationwatch
cd registrationwatch
fwconsole ma install registrationwatch
fwconsole chown
fwconsole reload
```

### Option 3: Install from a local copy

Copy or symlink a local `registrationwatch` directory into
`/var/www/html/admin/modules/`, then:

```sh
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
fwconsole chown
fwconsole reload
```

The module appears under Reports > Registration Watch.

## Updating Registration Watch

Do not uninstall when updating. Uninstalling removes Registration Watch tables,
settings, watched registrations, status history, alert history, and
repeat-alert state.

Check version before and after updating:

```sh
fwconsole ma list | grep -i registrationwatch
grep "<version>" /var/www/html/admin/modules/registrationwatch/module.xml
```

### Option 1: Update from an unpacked module directory

Replace the module files in `/var/www/html/admin/modules/registrationwatch/`, then:

```sh
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
fwconsole chown
fwconsole reload
```

### Option 2: Update from GitHub

After the first installation, fwconsole chown may cause Git to reject the module directory because it is owned by the FreePBX web user rather than root. Add the directory to Git's safe-directory list once:

```sh
git config --global --add safe.directory /var/www/html/admin/modules/registrationwatch
```

Then update the module:

```sh
cd /var/www/html/admin/modules/registrationwatch
git fetch origin main
git reset --hard FETCH_HEAD
fwconsole ma install registrationwatch
fwconsole chown
fwconsole reload
```

### Option 3: Update from a local copy

Re-copy or re-link your local `registrationwatch` directory, then:

```sh
cd /var/www/html/admin/modules/registrationwatch
fwconsole ma install registrationwatch
fwconsole chown
fwconsole reload
```

After updating, open Reports > Registration Watch and confirm that existing watched
registrations, settings, and history are still present.

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

Registration Watch registers a FreePBX job named:

```text
registrationwatch :: monitor
```

Useful checks:

```sh
fwconsole job --list | grep -i registrationwatch
fwconsole job --run=<job_id> --force
```

Expected job output includes:

```text
Running registrationwatch :: monitor ...
Registration Watch background job completed.
```

The module does not install a daemon, systemd unit, AMI event listener, custom
probe service, webhook sender, or SMS sender.

## Data Model

The canonical Registration Watch table names are:

* `registrationwatch_registrations`
* `registrationwatch_status_history`
* `registrationwatch_settings`
* `registrationwatch_alert_history`
* `registrationwatch_alert_escalation`

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

First baseline transitions from Unknown are suppressed. Old status-history rows
are not replayed later after recipient or settings changes.

Repeat alert modes can send reminders while an alertable registration state
continues. A recovery transition resets the reminder clock.

Repeat alert modes:

* Never: send only the initial state-change alert.
* Every 5 minutes: repeat every 5 minutes while the registration remains unavailable.
* Hourly: repeat once per hour while unavailable.
* Daily: repeat once per day while unavailable.
* Escalating: uses a Fibonacci-style backoff schedule, starting with shorter reminders and gradually increasing the interval up to daily. The wait between reminders follows Fibonacci multipliers on a 5-minute base:
  * 5 min, 5 min, 10 min, 15 min, 25 min, 40 min, 65 min, 105 min, …
  * Capped at 24 hours once the interval reaches the daily ceiling.

Stored legacy `fibonacci` repeat-mode values are treated as Escalating.

The default debounce delay is 0 seconds, so first alerts are sent immediately
when an alertable problem is detected. Increase this value to reduce noise from
short reloads, restarts, and transient network events.

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
active, Registration Watch continues to record registration state changes but
does not send alert emails.

* The snooze is global and applies to all watched registrations.
* Monitoring can be resumed manually before the snooze period ends.
* Watched registrations, settings, and history are not affected by snoozing.
* There is no per-registration snooze.

## User Interface

The Registration Watch admin page is available under **Reports > Registration Watch**
and contains the following sections:

The admin page supports narrow and mobile viewports, while wide data tables retain local horizontal scrolling.

* **Monitoring banner** -- shows the current monitoring state (active, inactive, or snoozed) with Snooze/Resume controls.
* **Registration Status Map** -- shows all discovered registrations. Supports Cards and Rows views. The Row view has sortable columns.
* **Alert Settings** -- configures recipients, alert triggers, debounce, repeat alerts, storm threshold, and topology polling.
* **Watched Extensions** -- lists watched registrations with extension-level monitoring toggles, repeat-alert overrides, and admin notes. Columns are sortable.
* **Status History** -- records registration state transitions. Columns are sortable.
* **Alert History** -- records sent and suppressed alert attempts. Columns are sortable.

Cards/Rows view choice, sort columns, sort directions, and Show limit are
persisted using module settings rather than browser-only local storage, so they
are remembered across browsers for the same PBX.

## Security Model

* AJAX commands use a fixed command allowlist.
* AJAX handlers require a module-owned session CSRF token.
* Persisted UI settings are restricted to known setting keys and allowed values.
* SQL writes and history deletes use prepared statements.
* Asterisk access is read-only in this phase.
* No shell execution is used by the module.
* Alerts are not sent merely from page load.

Granular FreePBX ACL integration is still future work.

## Current Limitations

* Reconciliation is periodic, not event-driven.
* No AMI ContactStatus listener yet.
* No custom probes.
* No maintenance windows yet.
* No webhook or SMS alert delivery yet.
* Short flaps can be missed between reconciliation runs.
* Email delivery depends on the PBX mail sender and relay setup.
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

Useful local checks:

```sh
php -l Registrationwatch.class.php
php -l Job.php
php -l page.registrationwatch.php
php -l install.php
php -l uninstall.php
php -l views/main.php
php -r '$xml = simplexml_load_file("module.xml"); echo $xml ? "module.xml parsed\n" : "module.xml failed\n";'
php tests/repeat_alerting_contract.php
php tests/version_compatibility_contract.php
```

On a real FreePBX/PBXact 16 or 17 system:

```sh
fwconsole reload
fwconsole job --list | grep -i registrationwatch
fwconsole job --run=<job_id> --force
```

## Uninstalling

Uninstalling Registration Watch removes the module job and drops
`registrationwatch_*` tables. Back up first if you need Registration Watch data.

```sh
fwconsole ma uninstall registrationwatch --force
rm -rf /var/www/html/admin/modules/registrationwatch
fwconsole chown
fwconsole reload
```

## Release History

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
