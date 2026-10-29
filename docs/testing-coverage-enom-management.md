# Enom TEST owner management

Before extension boot, set `domains.enom_identity.environment=test`,
`domains.enom_catalogue.enabled=true` and `domains.enom_writes.management=true`
with strict boolean flags. Restart web and queue workers. Registration and renewal
remain independent. The Registrars page requires the positively verified TEST account,
processing and an audited, encrypted fingerprint/protocol-bound management review.
Revoking that review stops new changes; retained attempts can still reconcile while
the deployment opt-in remains on.

Only nameservers, registrar lock, DNS, SRV and DS are composed. DNS/SRV/DS also
require the existing `domains_dns_tools` server setting, default false. Complete,
current initial inventory authorizes whole-list editors; dispatch independently
checks ownership, provider ID and preimage. Unknown or partial readback cannot
claim success or expose destructive forms. Other actions remain unavailable.

Every physical request counts against our safety cap `domains.enom_test_daily_call_cap`. One
write unit is reserved before the durable seal and consumed once before transport;
no unit is refunded. A proven cap/deadline refusal with an unconsumed reservation
stays held and definitely unsent. The owner can submit a fresh request when capacity remains. A consumed/lost-response write remains uncertain, with read-only
reconciliation, no resend and no automatic credit.

The installed ZIP scenario uses offline synthetic XML through existing reviewed
protocol objects. It exercises strict gates and independent review, fingerprint and
protocol drift, page silence, current ownership/ID, initial inventory, NS/lock and
DNS/SRV/DS readback, duplicate intent/worker, cap refusal, partial inventory, revoked
acceptance, lost replies, a separate PHP restart, unchanged native money and
redaction. These fixtures establish composition; actual TEST management, disposable
zone/public DNS behavior and production database concurrency require a provider run.
