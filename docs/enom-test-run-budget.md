# Enom TEST run budgets

A trusted deployment may set `domains.enom_test_run_policy` to a version 2 policy.
`EnomTestRunBudget::CONTRACT` is `2`. This policy applies only to Enom TEST requests.
LIVE call and spending limits keep their existing daily accounting.

The canonical policy contains `version`, `run_id`, `generation`, `cap`, `rolling_cap`,
`policy_hash`, `ledger_dev`, `ledger_ino`, `ledger_uid`, `ledger_gid`,
`account_fingerprint`, and `configuration_intent`. Run IDs use 1 to 48 ASCII letters,
digits, underscores or hyphens. Generations use the same alphabet, up to 64 characters.
Caps are positive integers. Ledger identity fields are nonnegative integers.
The fingerprint is a lowercase SHA-256 string, or null for initial account verification.
The configuration intent is a nonempty immutable identifier.

The hash is SHA-256 of the other canonical fields, sorted by key and encoded as compact
JSON with escaped non-ASCII characters and unescaped slashes. Pass only canonical fields to native methods.
Host envelopes and legacy counters are separate accounting evidence.

## Admission and phases

Drain all senders under the deployment's coordinator locks before changing authorization.
Call `prepare($policy, $credits)` to admit the initial credits. The result is a status array.
The phase is `preparing`; sending is refused. Repeating the same policy and initial amount
returns the same run without adding credits. Another generation is refused until the current
one is disabled, drained and closed. Run IDs and generations are never reused.

Publish and fsync matching host authorization, then call `activate($policy)`.
`allocate($policy, $credits, $allocationId)` admits additional planned work once per
allocation ID. Reusing an ID with a different amount is refused.

`disable($policy)` stops sending without releasing credits. After draining every sender,
`close($policy)` releases unused credits. Consumed attempts, permits and run identity remain.
Activation, disabling and closing are idempotent in their respective phases.
A disabled generation cannot reactivate.

All admission, reservations and consumption serialize on one deployment row. Both
`used + remaining <= cap` and `attempts in the last 24 hours + all remaining credits <= rolling_cap`
must hold. An attempt exactly 24 hours old leaves the rolling window. Unsent permits do not
age out. A new run does not reset rolling usage. Any nonzero legacy daily counter refuses
admission, including old reservation-only history. This release cannot prove its migration.

## Physical sends

`assertCapacity($required)` checks unheld credits under the configured active policy.
`reserve($fingerprint, $action, $operationId)` returns an opaque single-use permit.
It holds one admitted credit. It does not allocate or spend a second credit.
`assertPermit($permit, $fingerprint, $action, $operationId)` rechecks its identity and policy.
`consume(...)` atomically replaces one credit with a UTC-timestamped attempt immediately
before curl. Failed and uncertain attempts remain charged. There is no refund method.

Purchase, transfer, renewal and management scopes retain the original pre-seal permit,
including across midnight. Direct setup and catalogue readers use the same accounting.
The host send guard reads `pendingPermit()` and calls `assertPermit()` before writing
its correlated physical record. Native consumes after that guard and before curl.
No date check or daily count increment belongs in this version 2 host guard.

Initial verification allows one account-only `GetBalance` send before a native binding exists.
An existing mismatched account is refused. After verification succeeds and the native account
is saved, call `bindAccount($policy, $fingerprint)` before further requests. The original
null-fingerprint policy and its hash remain immutable; the verified binding is stored separately.
An unresolved verification cannot repeat. Binding cannot change to another fingerprint.
The deployment must bind the configuration intent to its local target before admission.

`status($policy = null)` returns version, run ID, generation, phase, cumulative used,
remaining credits, rolling used, run cap and rolling cap. Reading status grants no send authority.
`EnomTestCalls::usage()` adds the same run facts and retains `limit` as an alias for `cap`.
After any version 2 preparation, removing the configuration refuses TEST sends rather than
restoring the legacy daily path.

The clock fence requires Linux boot identity and one shared monotonic clock source.
It refuses backward UTC, a UTC/monotonic discontinuity over five seconds, and a changed
boot or clock source. Restarting PHP workers on the same source is safe. A machine reboot
or a different source requires separately qualified recovery; this release does not reset
clock evidence automatically. SQLite tests do not establish MariaDB locking acceptance.

## Older sealed management requests

Older sealed requests have no durable observation count. Owner and background checks refuse
these requests because earlier failed reads cannot be counted from completed read history.
Staff can use **Check status** on the original request. Each check requires its current,
one-use review ticket and records the actor, reason and before/after state before reading.
A separate `management_legacy_observation_attempts` counter permits at most 20 recovery
observations, including failed reads, and cannot reset. Completed read history still stops
at 20 entries. New TEST reads must also pass the current run and rolling budgets.
Recovery never resends the mutation, changes funding, or fabricates the earlier count.
Only the normal result checks can complete the request and clear its prior-outcome block.
The 47-send lifetime bound for newly sealed requests does not describe this unknown history.

A migrated budget sets deployment lock version 3 while its policy stays version 2.
The marker requires the retained receipt even when there are no historical attempts,
and survives closing and preparing later runs. Existing version 2 migrations gain the
marker on their first successful write with an authenticated receipt. A receipt lost
before that upgrade cannot be distinguished from a fresh version 2 run. A receipt
beside a marker other than version 2 or 3, such as a reset version 0 lock, refuses
run-budget calls and daily TEST sends.
