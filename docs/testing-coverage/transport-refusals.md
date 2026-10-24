# Transport refusal coverage

## Transport refusals before cURL setup

`tests/enom-curl-test.php` and `tests/curl-transport-test.php` count `curl_init` as well as `curl_exec`. Every pre-dispatch refusal (invalid deadline or credentials, TLS key logging, unsafe parameters, purchase mapping) asserts that no handle was created and no request was sent.

### Staff watch findings

`staff-watch-findings-test.php` renders the native staff watch list and detail over eight
synthetic watches (healthy, confirmed and unconfirmed nameserver change, expiry reached,
expiry within 30 days, out-of-date registry, not checked, paused). Each row's findings must
equal the customer `DomainSummaries` state keys, render with the customer tones, and show
"No findings" when healthy. The native attention filter must return exactly the six actionable
rows. Customer name and email appear only with `admin.users.view`; the native user link also
needs `admin.users.update`; revocation falls back on the next request. List, filter and
detail GETs leave work, observation, registry and notice rows unchanged. Query counts are
equal for 1 and 25 rows of one customer and for a 25-row page across one or five customers.
The filter evaluates the first 1,000 active names once per request in batches of at most 250;
applying it costs one more render plus one multi-owner batch per chunk. Mutations to the attention rule, permission
checks, expiry window, tones, stopped-watch handling and the filter memo each fail this test.
Offline only; no installed or live checks.

`staff-watch-findings-test.php` also pins the watch detail header: its flex bases apply only at 40rem and wider. Below that Filament stacks the header as a column, where a flex basis becomes height and left about 800 px of blank space above Findings at 375 px.

External watch transfer comparison: `watch-transfer-test.php` checks a local help-only GET,
strict annual transfer evidence, the 90–14 day window, status/premium/unknown refusals,
owner and revision revalidation, explicit authority and the real neutral native cart.
A second unknown registry read inside cart admission must refuse the handoff. Numeric
savings require independently verified, matching annual USD/tax-exclusive renewal evidence;
no such source is supplied to the runtime UI. Transfer completion exercises a failed relink
audit, atomic rollback, local repair, retained source history and definitely-unsent notice
suppression while accepted/uncertain deliveries survive. Public workers remain dormant.

Installed watch/adoption gate: repeated same-version native upload/upgrade and reinstall
retain encrypted
registry facts, adoption previews, watch source/profile/map rows, outstanding work leases,
notice episodes, uncertain deliveries and customer opt-outs byte for byte. The fixtures
prove previews remain unattached and maps/profiles, adoption and comparison activation
stay off. Repeated native upload, refused server deletion and file removal/reinstall share
the same retained snapshot. These are SQLite/offline checks; live provider acceptance,
shared-database races and actual delivery remain separate operator gates.
Cross-version watch-table retention awaits a previous build containing those tables.

Staff TLD query coverage compares 25/100 rows and repeats each size with extra price terms, retaining fresh-grant revocation checks. Settings review coverage
measures queries and encrypted-setting retrievals with 10/500 audit archives, exercises real archive rotation, exact unchanged-stamp replay refusals and
transactional key rollback. Corrupt acceptance permits narrower terms and a sales pause while still refusing widening and restoration. Recent history is bounded
independently of retained replay keys. The historical installed-package fixture adopts the unreleased review-key/archive declaration explicitly; it tests native
uploads and additive migrations from that baseline, not conversion or automatic repair of superseded missing-table schemas.

Installed default-held package acceptance also covers runtime candidate isolation for offline display rows and missing native invoice authority. It verifies
retained reconciliation/refund selection, safe actionable warnings, shared UTC daily diagnostic slots, permission revocation and secret-free
registrar account page rendering from the extracted ZIP; the old Activation status address redirects.

Staff operation diagnostics exercise the actual provider-evidence and paid-line guards and missing-invoice recheck through staff actions.
Each unexpected refusal emits exactly one warning containing only a closed reason, action and numeric operation ID; unknown failures
show a matching reference without exception text or class names. Expected staff validation stays quiet. Failed reads retain read_requested
and read_failed with a safe code, consume the review key and preserve financial/provider evidence without transport calls; successful
rechecks retain read_finished. Mutations removing the warning or recording failure as read_finished must fail these assertions.
Synthetic offline SQLite coverage only; no live provider, installed UI or shared-database acceptance is established here.
