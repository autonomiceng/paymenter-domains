# NameSilo admitted transport

`Registrars/NameSilo/AdmittedTransport` is a dormant Live transport port. NS4 installs it behind
existing shared acquisition, renewal, management and read protocols. This change registers no
factory, job or route and enables no account. The standalone API client remains read-only.

Deployment authority is explicit in `domains.namesilo_live`:

- `site`: exact current HTTPS `app.url`, also matching the account's saved `live_site`.
- `catalogue`, `registration`, `transfer`, `renewal`, `management`: booleans, absent means off.
- `accounts[stable_identity]`: current `fingerprint`, `currency: USD`, `balance_funding: true`,
  positive integer `daily_call_cap` and exact decimal string `daily_spend_limit_usd`.

These are deployment controls, not provider evidence or financial admission. NS5 supplies feature
acceptance; the shared engines retain ownership, funding, balance, profile, expiry, restore and
provider-auto-renew readback checks. Do not enable a feature on configuration alone.

The constructor's credential closure must use the installed account binding factory's current
encrypted credential/digest validation, exact binding/site check and processing guard for writes.
It runs on every send and receives `bool $write`; it returns `['api_key' => ...]`. The transport
also rechecks the saved account/server/site, deployment and processing immediately before I/O.
The DeadlineTransport read port requires the caller key to equal the fresh factory key.

NS4 wiring contract:

1. Keep a single transport instance for the bound worker invocation. Inject `exchange()` only
   into the genuine engine-bound protocols; pass the DeadlineTransport port to bound read clients.
2. Wrap one operation with `reservation->run(feature, operationId, work)`. After fresh shared
   engine admission, call `reserve(command, exactParameters, authorizedCost)` outside locks and
   before the durable seal. Purchases require positive USD cost. Management mutations are free.
3. The engine still consumes its genuine grant and seals before invoking the protocol. Transport
   reservations alone never establish funding or permission to seal. Use `unconsumed(operationId)`
   only inside this scope to distinguish a reserved mutation that has not reached cURL.
4. Each scope allows one mutation with that exact command and parameter digest. Reads use their
   own durable call reservations and cannot consume the mutation. Profile creation and ancillary
   auto-renew removal each need a separately admitted management child scope; never rearm a
   consumed purchase. No unknown write is retried. Scope exit drops all in-memory send authority.

All sends use fixed HTTPS origins and commands. Writes use `/apibatch`; reads use `/api` or
`/apibatch`. Redirects, proxies, alternate credential fields, card/payment profiles, implicit
purchase auto-renew, renewal privacy changes and unproven multi-year terms are refused. The
shared NS2 ledger reserves calls and exact USD cost, retains failures/unsent attempts, and
rejects use across UTC midnight. Timeouts, redirects, oversized bodies and malformed replies
remain unresolved; diagnostics expose no key, URL, contact payload, response or chained error.

Offline native tests substitute every cURL function and exercise actual encrypted account rows
and SQL counters. They prove the transport boundary, not installed runtime or provider acceptance.
NS4 must add engine-to-transport proof of funding/seal order and safe failure disposition when
it installs these ports; NS18 owns separately authorized installed/provider evidence.
