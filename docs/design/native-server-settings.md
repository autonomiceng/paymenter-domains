# Native registrar server settings

Registrar credentials and their verification digest stay in Paymenter's native encrypted
server settings. Registrar setup verifies the supplied account identity before saving;
rotations must match the retained registrar, environment and account fingerprint. A
successful save pauses processing until staff deliberately resume it. No migration or
second credential store is introduced.

## Accepted native access

Treat staff with server-edit permission as able to access saved credentials. With the
native server editor restored, its Livewire state exposes decrypted credentials and the
verification digest even though the visible form contains only the Domains setup note.
The production Secure editor withholds these settings until its separate removal slice.
The native editor's exposure is the accepted native secret-key permission boundary.
Domains permissions separately control Registrar setup and Registrar balances. The owned
pages do not display saved credentials, digests or account fingerprints. Encrypted review
history retains the binding fingerprint as evidence; its staff-facing projection omits it
and neither representation records credential input or the verification digest.

On the qualified native write path, saving without changes or changing the server name
leaves credential ciphertext and processing unchanged. Native Edit Server persists only
declared configuration options; injecting an undeclared credential key into its state
does not persist it. This upstream behavior is covered by the test-only upgrade tripwire.
Native form state is still privileged data, including native audit settings hydrated with
the other server settings.

## Configuration holds and balances

A stored credential or digest edit is not identity verification. Binding checks refuse
processing and reader construction before a registrar transport is composed when the
configuration no longer matches its saved digest. Registrar setup shows **On hold** when
processing was enabled, and local health directs staff to verify and save the original
account before resuming. Disabled-server health instead directs staff to re-enable the
server. A fresh live account has no staff action to bind its site in this release, so
processing stays off. A mismatched live site requires the installation administrator to
restore the original site configuration. Positively verifying a different account
cannot repurpose the retained binding. Same-account recovery preserves that fingerprint and pauses processing.

Registrar balances validates the current configuration locally before showing a cached
amount. An unavailable configuration shows **Unknown / Configuration unavailable** and
retains the original fingerprint-keyed balance row and read time. Recovery for the same
verified account makes the retained amount available again. Restoring an older verified
credential/digest pair for that same account also matches locally; it is not a new
identity verification. Rendering these facts makes no provider request. Disabled servers also hide the cached amount; re-enable the server
before verified setup or processing recovery.

## Lifecycle and qualification boundary

Scoped model events install extension tables before native Domains server creation,
pause processing on disable, refuse retained server identity changes/deletion and block
legacy Enom processing enablement. Registrar-account identity and deletion guards remain.
These events apply independently of editor route selection. Privileged bulk, quiet and
raw database writes are outside these ordinary model-event guarantees.

`domains.tests.native_editors=true` disables only the legacy route remaps and EventBus
mount/hydrate interception when the application is running unit tests. Production ignores
the switch. The suite inspects ordinary and compiled native server-route metadata, then
exercises native server forms and owned actions on ordinary routes with interception
disabled. It does not dispatch a request through compiled routes. Native
Edit Service without SecureEditService is not qualified here; the lifecycle and paid-path
lanes and the removal integration must qualify it before removal. The Secure editors
and interception remain in production until the separate removal slice; this qualification
does not authorize their removal or establish provider, installed-theme or InnoDB concurrency
acceptance. Production health retains its stale-route-cache warning until removal.
