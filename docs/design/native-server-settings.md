# Native registrar server settings

Registrar credentials and their verification digest stay in Paymenter's native encrypted
server settings. The Registrars page verifies the supplied account identity before saving;
rotations must match the retained registrar, environment and account fingerprint. A
successful save pauses processing until staff deliberately resume it. No migration or
second credential store is introduced.

## Accepted native access

Treat staff with server-edit permission as able to access saved credentials. With the
native server editor restored, its Livewire state exposes decrypted credentials and the
verification digest even though the visible form contains only the Domains setup note.
The native editor's exposure is the accepted native secret-key permission boundary.
Domains permissions separately control registrar setup and balances on Registrars. The owned
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
configuration no longer matches its saved digest. The Registrars page shows **On hold** when
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

Native Edit Server Delete still asks for confirmation. When a retained server is refused,
the scoped deleting observer sends a danger notice and halts that native action without
a 500 or success notice. Its native before hook still pauses account processing; the
server, verified binding and settings remain. Other writers retain the guard exception,
and unrelated native server deletion continues. Missing notification/action capabilities
are reported in Registrars; the underlying deletion guard remains in force.

Native Edit Service also handles refused identity, price and Delete writes with a
danger notification and Halt. These notices apply only to the current native service
page/record and its matching Save/Delete context; other writers retain their original
exception. Price guidance points confirmed active domains to Update renewal price
on the Domains page. Fixed field names are listed for identity refusals; deletion
guidance points to
Stop renewal or Retire unpaid order there. The native Recalculate Price hint
only edits a draft; its changed price is refused on Save. A refused service delete
leaves processing unchanged. Missing feedback capabilities are shown in Registrars
without weakening the guards.

Native Create Server, Edit Server and Edit Service use unchanged native routes. No
Secure editor, route remap, global Livewire interception or testing-only editor switch
remains. Harmless native saves continue; scoped model guards refuse unsafe writes.
The native service form still requires an expiry for Active recurring services; enter
a date to pass native validation and the managed expiry observer discards it on update.

Before deploying this removal, drain web/queue work, rebuild the native route cache
and reload open editor pages. Caches and open pages from the old Secure editors refer
to deleted classes; they cannot be retained across this deployment. New native routes
are exercised with the real route:cache command, fresh HTTP boot and Livewire hydration.
The remaining native editor/write paths stay pinned by the test-only upgrade tripwire.
Offline fixtures do not establish provider, installed-theme or InnoDB concurrency acceptance.

Refusals from the Properties and Configs tabs and from Services list bulk delete still
raise the original guard exception. Handled feedback for those paths is follow-up work.
On Active service Delete, the native terminate step is a Domains no-op; its native
audit event remains even though the service is retained and processing is unchanged.
