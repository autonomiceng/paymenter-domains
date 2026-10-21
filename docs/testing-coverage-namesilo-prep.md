# NameSilo nexus and renewal preparation coverage

This is offline preparation. Synthetic fixtures do not establish provider acceptance
or enable `.us` sales, account identity, background routing or renewal activation.

## Frozen `.us` setup

`RegistryInputs` accepts exactly `usnc` / `usap` for a frozen NameSilo `.us`
registration with an empty registry schema, using `UsNexus`'s existing enumerations.
There are no defaults. Acquisition setup, registration preflight and the admitted
protocol carry those values into the purchase mapping. Privacy remains forbidden.
Ordinary operation saves cannot replace frozen registry values.

`RegistryInputs::supported` still refuses `.us` for both registrars. Customer
collection and provider acceptance with `contact_id` are unresolved; the native
fixture seeds a synthetic frozen setup after paid `.com` checkout solely to exercise
the future dispatch seam. Enom has no corresponding nexus wire mapping and remains
unchanged. Invalid registry data uses `registry_details_unsupported`; existing
contact validation retains `contact_details_required`.

## Renewal auto-renew-off proof

NameSilo support recommended explicit `auto_renew=0` on 2026-10-18. The admitted
writer now sends it with domain and years. The supplied research quotes the
[renewal reference](https://www.namesilo.com/api-reference/pages?uid=domains/renew-domain)
as listing domain/years and payment_id/coupon, without an auto-renew flag. Sending
that flag is not proof of provider behavior.

Completion requires a fresh matching owned-domain observation with auto-renew off,
alongside the existing unique charge and full-term expiry proof. On, missing,
invalid, failed and late observations retain the sealed unresolved renewal with
`provider_auto_renew_unconfirmed`. Staff are instructed to check auto-renew, turn it off if on, then choose Re-check now
or Resume reconciliation reads; the read limit is displayed when reached. No extra purchase or settings write is added. A later off
observation clears that specific reason even when history is still unresolved;
expiry and native finance remain unchanged until the complete proof succeeds.
Account auto-replenishment remains a separate operator setting.

## Regressions and installed package

- `registration-namesilo-test.php` / `Support/namesilo-nexus.php`: strict input
  shape/enumerations, frozen snapshot, existing hold vocabulary, forbidden privacy,
  exact nexus wire values, one purchase, and closed publication / Enom parity.
- `renewal-execution-namesilo-test.php` / `Support/namesilo-renewal-readback.php`:
  explicit wire flag, on/missing/invalid/outage/late holds, unchanged sealed attempt and
  native finance, rendered staff guidance, stale-guidance clearing, subsequent
  read-confirmed completion, repeated annual cycles and no replay.
- `test_installed_package_namesilo.py`: native ZIP upload/extraction/install in
  separate disposable apps; both scenarios use the installed runtime autoloader,
  with reflection checks for the changed registry/protocol/renewal classes.
- `test_installed_package_previous.py`: retained unpatched previous-runtime upgrade
  remains part of the full offline suite.

Mutation checks exercise validation, frozen-state protection, publication/privacy,
independent setup/preflight/protocol threading, renewal wire flag, completion gate,
unknown-read handling, stale-code clearing and rendered staff instructions.
Live `.us` collection/profile behavior, renewal flag semantics and accepted
charge/expiry/off readback remain evidence requests, not test claims.
