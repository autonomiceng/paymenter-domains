# Storefront policy reads

`policy-ending-reads-test.php` compares actual policy/TLD result cardinality and query counts
with 10 and 1,500 policy endings. It exercises check/order POSTs, watch GET/check/confirmed
handoff, a mixed managed/watch portal batch and native hosting summaries. Synthetic SELECTs
are replayed immediately through local PDO to count returned rows; SQLite SELECT rowCount
is not a cardinality measure. No provider transport or production database is exercised.

Current suffix reads use the existing unique `domains_sales_policies.tld` index. Longest-suffix,
normalization, unknown/subdomain refusals and disabled transfer cases retain their behavior.
Summary links deliberately retain any-enabled-suffix behavior; admission still checks the
longest ending and current authoritative offer. Display caching never supplies order authority.

Cold catalogue rendering remains intentionally proportional to the catalogue, with bounded
per-ending work and zero-query warm hits. Existing storefront/cache suites cover term growth,
price/eligibility freshness, invalidation and the thirty-second display TTL.

`owned-pages-test.php` renders proven-unsent transfer contact recovery for both registrars and submits the owner POST. Unknown, sent and recorded-null attempts hide recovery and refuse the POST without changing the operation.
