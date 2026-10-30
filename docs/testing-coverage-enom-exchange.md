# Bounded Enom exchange (offline synthetic coverage)

`AccountBindings::enomRuntimeFactory` returns a pure per-fingerprint closure. It
constructs the seven existing roles through `RuntimeComposition`, retaining exact
shared feature and acceptance objects. No registry, runtime port or acceptance is
installed automatically. Processing and action defaults remain closed.

The private exchange is reachable through the genuine registration, transfer,
renewal and management protocols. Their live one-use grants, reserved physical
limiter slots and request deadlines remain authoritative. Credentials are loaded
inside that exchange, after role/origin/parameter validation; current account,
server, encrypted configuration and live-site checks still apply. Commands outside
the management read set, including registration nameserver readback, also require
current processing. Paused management reconciliation may perform reads.
Credential-loading time reduces the received remaining timeout.

Enom TEST sends pass one per-login send limit (`EnomTestSendLimit`) at the two physical
send sites, after the deployment send guard and before the last deadline check. It counts
every attempt in any 24 hours and per minute, refuses inside an outer transaction and
refuses on any database error. `enom-test-send-limit-test.php`,
`enom-send-limit-upgrade-test.php`, `test_enom_send_limit_concurrency.py` (SQLite) and
`test_enom_send_limit_mariadb.py` cover it. One stated exception: the development-only
conversion collector (`scripts/conversion/EvidenceClient.php`) is not counted. It runs with
the app stopped and a read-only database, so it cannot record attempts; it sends one
GetDomainInfo per selected holding, at most one per second per login, with no retry.

Each role has a closed command set. Fixed TEST/live HTTPS origins, credentialed
POST, verified TLS, disabled proxies/redirects/debugging, refusal when
`SSLKEYLOGFILE` is set, bounded body streaming and total/connect timeouts apply to
every request. No retry or public command sender exists. Errors discard raw causes,
credentials and response text. A missing shared runtime limiter refuses exchanges.

`enom-exchange-test.php` checks both environments, role/parameter and malformed or
mismatched credential refusals, transaction/deadline limits, credential delay, cURL
options, partial or oversized bodies, HTTP errors, failed setup, timeout, redaction
and handle cleanup. It compares the existing read and new protocol cURL safeguards.
The fresh ZIP scenario repeats these contracts against installed classes and runs
native registration and transfer from synthetically paid invoices through the real
engines. An acknowledged send leaves registration pending and transfer uncertain,
never completed. It tests duplicate workers during a send, shared interactive and
background capacity, durable seals after factory reconstruction, processing/server/
binding drift after sealing and lost replies without replay. A refusal inside the
sealed exchange retains uncertainty even when no socket opened; existing uncertain
outcome rules govern replay and refunds. A separate credential-source test keeps a
changed binding's verification digest valid to isolate expected-fingerprint refusal.

A synthetic captured-order reconciliation, through a stubbed read transport rather
than this exchange, establishes the holding. Management loses a mutation reply and
resolves through synthetic credentialed reads with processing paused. A synthetically
paid native renewal invoice admits the renewal engine/writer; a timeout stays
uncertain and a reconstructed worker cannot send it again.

All replies and cURL functions are synthetic and sockets remain disabled. Reentrant
workers exercise overlapping engine states, not separate database connections.
Provider authentication/spend, real queue/cache deployment, InnoDB races, actual
charge/history acceptance and installed customer browser flows remain separate gates.
