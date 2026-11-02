# Offer upgrade staging (B2)

This is dormant preparation. Installation adds nullable/defaulted fields; it never converts data,
publishes products, activates an account or switches a caller. No legacy store is dropped. B4 owns
the writer/projection and B6 owns the checked quiet window and activation. There is no dual writer.

At B6, stop/drain legacy catalogue writers and claims first. Keep them paused through comparison and
cutover: staging fields cannot prevent an old executable from writing its old tables. Capture
`UpgradeComparison::capture()` and retain its digest-only result with the recoverable deployment snapshot.
For each verified `AccountBinding`, call `UpgradeConversion::batch(binding, limit)` until `complete`.
The limit is 1–500 offers. No network, application bootstrap or command is invoked by the converter.

The union of retained TLDs, SellRows, accepted endings and price identities is traversed by a binary
ending cursor. Existing identities are never repointed. Newly discovered candidates are disabled and
unpublished. Each offer's staging data and cursor commit together. Retry after an interruption;
completed offers and accounts are no-ops. Source digests reject changing legacy inputs on resume,
including after completion; this is a one-time conversion, never continuing synchronization. A source
change requires a reviewed comparison/forward correction before B6, not clearing the marker blindly.

- Account defaults and nullable SellRow choice/longest/transfer overrides become B1 `Intent` data.
  Acceptance supplies missing choices only without saved defaults. Derived action/registry/renewal
  flags do not become provider facts or explicit staff stops. Hidden endings stay governed by the
  existing hidden-ending policy.
- Normalized observations live only in `domains_prices.catalogue_observation`. Exact price rows and
  annual fill cells compete by original observation time. Explicit dated disabled prices survive;
  omitted, null or malformed fill cells cannot withdraw valid costs. Equal-time disagreement stays
  unknown and is reported. Annual-to-total conversion uses integers and only an evidenced exact term.
  Unproven fill buckets and obsolete TLD cost-column units are reported, never interpolated.
- The accepted source schema has no independently dated registry/DNS/renewal facts. Such facts remain
  null, even when legacy acceptance/flags or cost timestamps exist. B5 resolves them lazily. Stale cost
  timestamps remain stale. Positive published annual USD renewal retail is retained separately even
  when the cost is missing or the account is unsold/deselected.
- Existing queue rows keep their cursor/claim/lease fields. New feed run/attempt/success/error fields
  carry the fill state; the new run starts beyond the legacy generation. This does not grant a legacy
  in-flight reader authority to write the new path. B6 must fence/drain those readers.

`UpgradeConversion::report(binding, before)` compares the original retained/native identity and row
sets, including seller revisions, settings, mappings, services, invoices, payments, credits and frozen
operation evidence. It reports completion, unchanged sources and issue counts; `activated` is always
false. Added staging candidates are explicitly excluded from the original-row digests. Any original
row difference blocks B6. Review unknown/current-offer availability separately; a compatible report
is not an activation grant or proof of installed MySQL concurrency/provider behavior. Retain the full
snapshot for recovery; digests alone are not a backup. Re-run conversion to prove idempotence.

Offline evidence is `tests/offer-conversion-test.php`: retained and fresh installs, prefix handling,
atomic interruption/rerun, conflicting/ambiguous/stale/disabled costs, preserved holder retail and
native obligations, plus 1,007 discovered offers across bounded batches. No provider or demo calls.
