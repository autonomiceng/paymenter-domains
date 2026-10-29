# NameSilo OTE enrollment

This is an explicitly weaker, sandbox-only contract. NameSilo does not confirm which account
an API key belongs to. A staff label is local free text, never provider identity. Account IDs,
contacts, orders, balances, auction users and key hashes are not accepted as identity proof.
Live NameSilo identity configuration still refuses with `namesilo_identity_unavailable`.

An installation administrator may set `domains.namesilo_sandbox_enrollment.enabled` to boolean
`true` in application configuration before extension boot. The default is off; credentials do
not belong in configuration. Rebuild application config and restart workers after changes.
This composes only enrollment and OTE balance reads (Read balance, Refresh now and the scheduled daily read). Do not mix this opt-in with an explicit
NameSilo `RegistrarFactories::install` composition; duplicate registration fails at boot. Domain reads, write ports, catalogue
acceptance, acquisition features and processing require their existing independent gates.
The OTE balance factory also requires a persisted enrollment and validates its facts on each
reader construction. A legacy synthetic NameSilo binding with a matching local credential digest
cannot use the OTE reader. Scheduled and manual NameSilo balance refreshes use the same balance-only
composition; other registrar read behavior remains unchanged. Neither path grants domain reads.

Create a fresh enabled native Domains server, then open **Domains → Registrars → Enroll
NameSilo OTE key**. Enter a staff account label, the OTE API key and a one-line reason, accept
the weaker ownership contract, and confirm. This sends at most one ten-second read-only
`getAccountBalance` request to the fixed OTE origin, with redirects and proxies disabled.
A successful authenticated reply permits local enrollment; its balance is discarded. No retry
or live fallback occurs. Reload to see the label, OTE-only status and ownership disclaimer.
The first worker tick after enrollment can make one automatic daily read through
`/apibatch/getAccountBalance` (25-second cap); OTE support and its reply contract for this route are
unconfirmed. An unsupported reply is shown as **Read failed** and does not retry before the next
daily schedule. A read-limiter deferral sends no provider request and schedules another attempt no sooner than
60 seconds later. Our safety policy limits automatic provider reads to one per day. **Read balance** and **Refresh now**
are separate interactive reads and store their amounts under Registrar balances.

The encrypted enrollment facts contain a random enrollment identifier, server ID, staff label,
key fingerprint, `provider_confirmed_account=false` and `authenticated_ote_key=true`.
An immutable local enrollment token hashes these exact facts; it is distinct from provider
identity. Existing encrypted credential settings bind the key to that token and server.
Changing facts or credentials invalidates local reads. Enrollment facts are hidden from generic
account serialization and excluded from staff audit input; only the dedicated staff card displays
the label. No provider response, diagnostic, balance or secret is retained as enrollment evidence.

Label and key fingerprint cannot change. A new key or label requires a fresh Domains server and
new enrollment with a new key; holdings, acceptances and operations remain with the old enrollment. A server
with retained Domains settings or ledger/catalogue data cannot enroll. Each key can enroll only
once, including on disabled servers; unreadable original facts hold new enrollments. Existing synthetic bindings
cannot be adopted. Copying settings cannot establish a binding, and the local identity namespace
cannot be returned by a future provider verifier. The existing `AccountVerifier` registration seam
remains the only path for future provider-confirmed identity; enrollment never implements it.
NameSilo health copy treats retained non-enrolled bindings as unconfirmed and never labels them
as a verified account.

`namesilo-enrollment-test.php` and `test_installed_namesilo_enrollment.py` share the same staff
scenario. It exercises actual Filament submission, exact OTE host/endpoint/deadline, no retries,
wrong keys, malformed replies, label/contact/order/identity substitutes, immutable model fields,
rotation, copied settings, live refusal, default-off gates, encrypted storage, argument-trace and
audit redaction, the retained synthetic-reader refusal, scheduled daily then manual balance reads,
and deployment/permission, retained-state and duplicate-key changes during the enrollment read. The
actual first `RuntimeWork::tick` sends one `/apibatch` fixture read; a second tick sends none in the
same due window. It also verifies that a corrupt encrypted identity with a retained enrollment
facts row blocks same-key reuse before another OTE request. Health and activation recovery report a
corrupt saved identity as unconfirmed. The test refuses Enom connection review
before consuming it when only NameSilo OTE is composed. The prefixed MariaDB schema scenario exercises
the fresh-server retained-table guard with a prefixed extension table. The installed scenario uses
verified ZIP runtime bytes without the source autoloader. Existing full-suite coverage retains
native upgrade/reinstall, processing and provider boundary checks.

The shared offline scenario covers enrollment success and refusal paths in native and installed ZIP runs.
Historical verification records 13 selected mutations killed, plus a Unicode-label mutation.
This does not establish exhaustive mutation coverage of every guard.
These offline tests make no real registrar calls and do not establish provider ownership, live
acceptance, shared-database race behavior or permission to purchase domains.
The installed default-account review asserts the saved ending, registration and renewal terms, and registry evidence.
