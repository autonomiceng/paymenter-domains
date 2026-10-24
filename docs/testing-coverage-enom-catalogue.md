# Enom TEST catalogue preview

Deployment configuration must set `domains.enom_identity.environment` to `test`
and `domains.enom_catalogue.enabled` to boolean `true` before extension boot.
Restart application workers after configuration changes. This opts in to Enom
TEST reads and catalogue/cart preview only. It supplies no writer, processing
approval, funding or live-site authority. NameSilo remains unavailable.

Staff use **Domains → Registrars** with the specific action permissions,
fresh review and a reason:

1. **Verify identity** saves encrypted UID/token after one authenticated GetBalance.
2. **Read balance** makes one independent GetBalance.
3. **Accept catalogue** records independently verified `com`, registration term 1,
   supported registry requirements and safe annual holder renewal; no reads.
   Leave transfer unaccepted for this baseline.
4. Set a pricing default, then **Import accepted ending** for `com`: GetTLDList,
   GetTLDDetails, GetExtAttributes and two PE_GetProductPrice reads (register/renew).
   It imports costs and initializes an unselected seller policy. A new TLD stays
   disabled; re-import preserves withdrawn registration, transfer and renewal support, and publication.
   Costs expire 60 minutes after the read: re-import costs five reads again and
   removes that ending’s existing TEST cart lines. Publish prices again locally.
5. Open the TLD; explicitly verify/enable annual registration and renewal, publish
   native prices, then select the account as seller. These steps make no reads.
6. Pricing/search pages show TEST and the accepted annual offer without reads.
   Explicit availability search makes one Check; adding a cart line makes another
   fresh Check. Checkout refuses with a TEST preview explanation before creating
   an order or payment. The independent processing guard still refuses admission.

Each import shares a 15-wire budget and 25-second deadline, with no retries or I/O
under locks. An additional offered accepted registration term or transfer costs
up to one additional read. Failures may spend reads but do not replace saved price
rows. Import is manual and audited: scheduler catalogue work skips Enom TEST identity-mode accounts, even while
opted out, before claiming a refresh;
unreviewed direct imports refuse; daily balance and automatic native publication skip this TEST preview.
Use **Read balance** in Registrars for an explicit balance refresh. The
Registrar balances page refresh reports configuration unavailable, points to
Registrars and retains the cached amount without a provider read. Page rendering makes no provider call.
The preview reader refuses balance, ad-hoc quotes, domain observation, renewal
assessment and reconciliation before I/O. General adoption reads remain unavailable.

Acceptance revisions invalidate all account offers and remove that account's TEST
cart lines. Unpublishing or narrowing one TLD removes only its ineligible lines;
unchanged settings preserve valid lines and unrelated endings. Seller changes
remove the previous TEST seller's lines for that ending. Re-import removes its
old cart receipts. Opt-out hides cached offers and refuses new carts; retained
cart lines still fail checkout. Staff withdrawals also clear affected TEST carts
while catalogue preview is disabled in the TEST identity deployment; re-enabling cannot revive cached offers. Price, stock and term drift are rechecked locally.

`tests/enom-catalogue-test.php` and the installed package lifecycle run the same
staff/controller/native cart scenario using synthetic, existing XML shapes and
namespaced cURL fakes. They cover missing acceptance/list entries, stale reviews,
permission revocation before/during reads, settings drift during reads, malformed
cost preservation, exact five-call import, publication/TEST labels, zero-call page
rendering and scheduler turns, search/cart reads, term/price/stock refusal, cost expiry, targeted
withdrawal, acceptance revocation, opt-out and TEST/live/NameSilo separation.

The package test uploads verified ZIP bytes, runs without source runtime autoload,
reinstalls, then verifies retained raw data and the TEST offer in a new process.
`tests/test_installed_package_previous.py` retains the previous-commit database
upgrade check. Offline fixtures establish neither actual provider behaviour nor
shared-database concurrency or permission to place credentials and run real reads.

A real Enom TEST GetTLDList (2026-10-22) listed 620 nested `<tld><tld>x</tld></tld>`
entries, 22 of them IDN (`xn--`) or numeric endings the catalogue cannot sell. One
such entry used to void the whole list, so import refused with com present.
`tests/enom-protocol-test.php` now feeds that shape with mixed unsupported entries and
asserts they are skipped; a list with no supported ending still stays unavailable.

Opted-in TEST writes have separate readers and guards. Registration composition permits
captured Purchase confirmation; renewal composition also permits renewal assessment and
sealed Extend confirmation, including explicitly accepted renewal history. See
[TEST renewal coverage](testing-coverage-enom-renewal.md). General adoption polling stays off.
Catalogue refresh coverage also checks that a later priced term restores registration.
