# Enom identity verification

Account-only `GetBalance` authentication was confirmed against the Enom reseller test environment, 2026-10.
The response does not echo UID. Only successful authentication of the exact submitted UID/token,
`ErrCount 0`, `Done true` and a parsable available balance establish the lowercase UID identity.
Zero balance and no domains are valid; funds, contacts, local UID equality and credential hashes
are never identity evidence. Wrong UID and wrong token have the same closed credential refusal.
Malformed, partial, HTML and timeout replies are unavailable, with no raw response or cause retained.

## Deliberate read-only composition

An installation administrator may set `domains.enom_identity.environment` to `test` or `live` in
application configuration **before extension boot**. Absence or another value leaves connection
reads off. This selects exactly one fixed HTTPS host, never a fallback. Rebuild application config
and restart workers after deliberate deployment configuration changes. No credentials belong in
this configuration. Live account reads still need the existing explicit current-site binding;
this slice adds no staff live-site binding action.

On **Domains → Registrar setup**, create a native Domains server, open **Verify identity**, choose
Enom and the configured environment, enter UID/token and a reason, and confirm. This makes at most
one ten-second `GetBalance` call and saves encrypted credentials only after positive identity proof.
Reload to see the verified lowercase UID and TEST/Live badge. Credential fields are never prefilled.
**Read balance** makes a separate single bounded read without requiring catalogue acceptance.
Open **Registrar balances** to see its cached amount and read time; rendering makes no request.
NameSilo retains an explicit unavailable identity reason.

With only the identity opt-in, the bootstrap provides identity and staff balance access.
The separate [TEST catalogue opt-in](testing-coverage-enom-catalogue.md) permits accepted
catalogue/search/cart reads; background imports and adoption readers remain closed.
Prices and seller publication require the documented staff steps. Neither opt-in installs
write ports or enables billing, processing or action features. Same-account verified
rotation retains the binding and pauses processing; another authenticated UID cannot replace it.

## Offline coverage

`enom-identity-test.php` uses namespaced cURL fakes: wrong UID/token/host/environment, strict envelope,
malformed/partial/HTML/timeout/balance refusals, empty account, uppercase UID and token rotation.
Exception arguments are enabled to check UID/token/balance redaction and discarded causes.
Native Filament submissions cover verified UID/environment display, blank secret inputs, separate
balance reads before acceptance, failed account replacement, encrypted settings/audit and processing
remaining off. Accepted catalogue plus daily balance and a runtime turn produce no registrar wire call; a retained live binding
cannot use the TEST-only staff factory. Default boot remains covered by the installed held scenario.
The same staff scenario runs from verified ZIP bytes in a fresh native process, without source autoload.

Mutation checks remove the layered ErrCount and Done conditions, and independently remove each
verifier/reader environment guard. All must be killed by the identity suite. Synthetic tests and
installed ZIP evidence establish offline behavior, not a new provider run, production acceptance,
shared database concurrency or authorization to place credentials or spend.
